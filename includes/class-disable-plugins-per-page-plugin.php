<?php
/**
 * Main plugin orchestrator: owns the settings, boots each part and keeps
 * the compiled rules current when posts, permalinks or plugins change.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Disable_Plugins_Per_Page_Plugin {

	/**
	 * Option key holding the plugin's settings array (the rules as edited).
	 */
	const OPTION = 'disable_plugins_per_page_settings';

	/**
	 * Capability needed to change which plugins load: the one that lets a
	 * user turn plugins on and off on the Plugins screen.
	 */
	const CAP = 'activate_plugins';

	/**
	 * Single shared instance.
	 *
	 * @var Disable_Plugins_Per_Page_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Disable_Plugins_Per_Page_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		if ( is_admin() ) {
			new Disable_Plugins_Per_Page_Admin( $this );
			Disable_Plugins_Per_Page_Measure::init();
		}

		Disable_Plugins_Per_Page_Toolbar::init();
		Disable_Plugins_Per_Page_Editor::init();

		add_action( 'init', array( $this, 'after_recovery' ), 1 );
		add_action( 'admin_init', array( $this, 'maintain' ) );

		// Keep the compiled list in step with what it's built from.
		add_action( 'save_post', array( $this, 'post_changed' ), 20 );
		add_action( 'trashed_post', array( $this, 'post_changed' ) );
		add_action( 'untrashed_post', array( $this, 'post_changed' ) );
		add_action( 'deleted_post', array( $this, 'post_changed' ) );
		add_action( 'activated_plugin', array( 'Disable_Plugins_Per_Page_Rules', 'queue_compile' ) );
		add_action( 'deactivated_plugin', array( 'Disable_Plugins_Per_Page_Rules', 'queue_compile' ) );

		foreach ( array( 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'home', 'widget_block', 'widget_text', 'widget_custom_html' ) as $option ) {
			add_action( 'update_option_' . $option, array( 'Disable_Plugins_Per_Page_Rules', 'queue_compile' ) );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'rules'   => array(),
			'guard'   => 1,
			'toolbar' => 1,
			'purge'   => 1,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		return self::settings();
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Create the cookie secret and the recovery key if they're missing.
	 *
	 * @return void
	 */
	public static function ensure_secrets() {
		if ( strlen( (string) get_option( Disable_Plugins_Per_Page_Filter::SECRET ) ) < 32 ) {
			update_option( Disable_Plugins_Per_Page_Filter::SECRET, wp_generate_password( 64, false, false ), false );
		}

		if ( strlen( (string) get_option( Disable_Plugins_Per_Page_Filter::RECOVERY_KEY ) ) < 20 ) {
			self::new_recovery_key();
		}
	}

	/**
	 * Replace the recovery key (the old link stops working).
	 *
	 * @return void
	 */
	public static function new_recovery_key() {
		update_option( Disable_Plugins_Per_Page_Filter::RECOVERY_KEY, wp_generate_password( 32, false, false ), false );
	}

	/**
	 * The recovery link. It goes through wp-admin, which page caches never
	 * serve, and works before anything else loads, even when signed out.
	 *
	 * @return string
	 */
	public static function recovery_url() {
		return add_query_arg( Disable_Plugins_Per_Page_Filter::RECOVERY_ARG, (string) get_option( Disable_Plugins_Per_Page_Filter::RECOVERY_KEY ), admin_url( 'index.php' ) );
	}

	/**
	 * Whether every rule is paused (recovery link or the switch).
	 *
	 * @return bool
	 */
	public static function is_paused() {
		$state = Disable_Plugins_Per_Page_Filter::state();

		return ! empty( $state['paused'] );
	}

	/**
	 * Pause or resume every rule.
	 *
	 * @param bool   $paused Pause.
	 * @param string $by     What paused them ('switch' or 'link').
	 * @return void
	 */
	public static function set_paused( $paused, $by = 'switch' ) {
		$state = Disable_Plugins_Per_Page_Filter::state();
		$was   = ! empty( $state['paused'] );

		if ( $was === (bool) $paused ) {
			return;
		}

		if ( $paused ) {
			$state['paused']    = time();
			$state['paused_by'] = $by;
		} else {
			unset( $state['paused'], $state['paused_by'] );
		}

		update_option( Disable_Plugins_Per_Page_Filter::STATE, $state, true );
	}

	/**
	 * Turn back on the rules and page settings the crash guard paused.
	 *
	 * @return void
	 */
	public static function resume_crashed() {
		$state = Disable_Plugins_Per_Page_Filter::state();

		unset( $state['paused_rules'], $state['paused_posts'], $state['crash'] );
		update_option( Disable_Plugins_Per_Page_Filter::STATE, $state, true );
	}

	/**
	 * After the recovery link paused everything, send the admin to the
	 * settings page (via the login screen if they're signed out).
	 *
	 * @return void
	 */
	public function after_recovery() {
		if ( ! Disable_Plugins_Per_Page_Filter::recovered() ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'dppp_notice', 'recovered', Disable_Plugins_Per_Page_Admin::page_url( 'safety' ) ) );
		exit;
	}

	/**
	 * On admin loads: keep the loader file current (it holds the version)
	 * and recompile when post types or the compiled format changed.
	 *
	 * @return void
	 */
	public function maintain() {
		if ( ! current_user_can( self::CAP ) || wp_doing_ajax() ) {
			return;
		}

		self::ensure_secrets();

		$status = Disable_Plugins_Per_Page_Loader::status();

		if ( 'missing' === $status || 'outdated' === $status ) {
			Disable_Plugins_Per_Page_Loader::ensure();
		}

		$compiled = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );

		if ( ! is_array( $compiled ) || ! isset( $compiled['v'] ) || Disable_Plugins_Per_Page_Rules::COMPILED_VERSION !== (int) $compiled['v']
			|| ! isset( $compiled['maps'] ) || Disable_Plugins_Per_Page_Rules::query_var_maps() !== $compiled['maps'] ) {
			Disable_Plugins_Per_Page_Rules::queue_compile();
		}
	}

	/**
	 * A post was saved, trashed or deleted: recompile if any rule or page
	 * setting depends on it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function post_changed( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( metadata_exists( 'post', $post->ID, Disable_Plugins_Per_Page_Rules::META ) ) {
			Disable_Plugins_Per_Page_Rules::queue_compile();
			return;
		}

		foreach ( (array) self::settings()['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			// "Pages that use it" rules search every page's content.
			if ( ! empty( $rule['contains'] ) || ( ! empty( $rule['items'] ) && in_array( (int) $post->ID, array_map( 'intval', (array) $rule['items'] ), true ) ) ) {
				Disable_Plugins_Per_Page_Rules::queue_compile();
				return;
			}
		}
	}

	/**
	 * Clear the page caches of popular caching plugins, so pages built with
	 * the old rules aren't served. Each is called only if it's installed.
	 *
	 * @return bool Whether any cache was cleared.
	 */
	public static function purge_page_caches() {
		$cleared = false;

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$cleared = true;
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$cleared = true;
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$cleared = true;
		}

		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
			$cleared = true;
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$cleared = true;
		}

		$actions = array(
			'litespeed_purge_all'                => 'LSCWP_V',
			'cache_enabler_clear_complete_cache' => 'CACHE_ENABLER_VERSION',
			'breeze_clear_all_cache'             => 'BREEZE_VERSION',
			'rt_nginx_helper_purge_all'          => 'NGINX_HELPER_BASENAME',
		);

		foreach ( $actions as $action => $constant ) {
			if ( defined( $constant ) ) {
				do_action( $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Other plugins' purge hooks.
				$cleared = true;
			}
		}

		return $cleared;
	}
}
