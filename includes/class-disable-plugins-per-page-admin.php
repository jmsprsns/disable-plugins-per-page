<?php
/**
 * Admin side: the Content Powered > Disable Plugins Per Page page and the
 * Plugins-screen link.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Disable_Plugins_Per_Page_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'disable-plugins-per-page';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'plug-zap';

	/**
	 * Nonce action for the page's AJAX calls and links.
	 */
	const NONCE = 'disable_plugins_per_page_admin';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'rules'   => 'globe',
		'admin'   => 'layout-dashboard',
		'pages'   => 'file-text',
		'results' => 'chart-column',
		'safety'  => 'life-buoy',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Disable_Plugins_Per_Page_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Message to show after saving ('purged' when page caches were cleared).
	 *
	 * @var string
	 */
	private static $notice = '';

	/**
	 * Constructor.
	 *
	 * @param Disable_Plugins_Per_Page_Plugin $plugin Plugin instance.
	 */
	public function __construct( Disable_Plugins_Per_Page_Plugin $plugin ) {
		$this->plugin = $plugin;

		Disable_Plugins_Per_Page_Menu::init();
		Disable_Plugins_Per_Page_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_notices', array( $this, 'global_notices' ) );
		add_action( 'add_option_' . Disable_Plugins_Per_Page_Plugin::OPTION, array( $this, 'after_save' ), 10, 0 );
		add_action( 'update_option_' . Disable_Plugins_Per_Page_Plugin::OPTION, array( $this, 'after_save' ), 10, 0 );
		add_action( 'admin_post_dppp_starter', array( $this, 'add_starter' ) );
		add_action( 'admin_post_dppp_resume', array( $this, 'resume' ) );
		add_action( 'admin_post_dppp_new_key', array( $this, 'new_key' ) );
		add_action( 'wp_ajax_dppp_search', array( $this, 'ajax_search' ) );

		// options.php asks for manage_options by default; changing which
		// plugins load needs the right to turn plugins on and off.
		add_filter( 'option_page_capability_' . self::PAGE, array( $this, 'capability' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( DISABLE_PLUGINS_PER_PAGE_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Capability needed to save the settings form.
	 *
	 * @return string
	 */
	public function capability() {
		return Disable_Plugins_Per_Page_Plugin::CAP;
	}

	/**
	 * URL of the settings page, optionally on a tab.
	 *
	 * @param string $tab Tab ID.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		return admin_url( 'admin.php?page=' . self::PAGE ) . ( '' !== $tab ? '#' . $tab : '' );
	}

	/**
	 * A nonced admin-post.php link.
	 *
	 * @param string $action Action.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	private static function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::page_url() ),
			esc_html__( 'Settings', 'disable-plugins-per-page' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Disable_Plugins_Per_Page_Icons::get( self::ICON, 17, 'disable-plugins-per-page-menu-icon' );
		$menu_title = $icon . '<span class="disable-plugins-per-page-menu-label">' . esc_html__( 'Disable Plugins Per Page', 'disable-plugins-per-page' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Disable_Plugins_Per_Page_Menu::SLUG,
			__( 'Disable Plugins Per Page', 'disable-plugins-per-page' ),
			$menu_title,
			Disable_Plugins_Per_Page_Plugin::CAP,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' dppp-settings-page' : $classes;
	}

	/**
	 * Register the setting. One form saves every tab.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Disable_Plugins_Per_Page_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Disable_Plugins_Per_Page_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * The "Rules on" switch is saved to the state record, not the settings,
	 * because the recovery link changes it too.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in = is_array( $input ) ? $input : array();

		if ( isset( $in['form'] ) && current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			$was = Disable_Plugins_Per_Page_Plugin::is_paused();

			Disable_Plugins_Per_Page_Plugin::set_paused( empty( $in['rules_on'] ) );

			if ( Disable_Plugins_Per_Page_Plugin::is_paused() !== $was && ! empty( $in['purge'] ) && Disable_Plugins_Per_Page_Plugin::purge_page_caches() ) {
				self::$notice = 'purged';
			}
		}

		return array(
			'rules'   => Disable_Plugins_Per_Page_Rules::sanitize_rules( isset( $in['rules'] ) ? $in['rules'] : array() ),
			'guard'   => empty( $in['guard'] ) ? 0 : 1,
			'toolbar' => empty( $in['toolbar'] ) ? 0 : 1,
			'purge'   => empty( $in['purge'] ) ? 0 : 1,
		);
	}

	/**
	 * After the settings are saved: recompile, and clear page caches when
	 * the rules visitors get have changed.
	 *
	 * @return void
	 */
	public function after_save() {
		$before = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );
		$after  = Disable_Plugins_Per_Page_Rules::compile();

		if ( self::live_signature( $before ) !== self::live_signature( $after ) && $this->plugin->get_setting( 'purge' ) && Disable_Plugins_Per_Page_Plugin::purge_page_caches() ) {
			self::$notice = 'purged';
		}

		if ( 'purged' === self::$notice && function_exists( 'add_settings_error' ) ) {
			add_settings_error( Disable_Plugins_Per_Page_Plugin::OPTION, 'dppp_saved', __( 'Settings saved. Your page cache was cleared, so visitors get the new rules.', 'disable-plugins-per-page' ), 'success' );
		}
	}

	/**
	 * A fingerprint of what visitors get (rules in testing left out).
	 *
	 * @param mixed $compiled Compiled rules.
	 * @return string
	 */
	private static function live_signature( $compiled ) {
		if ( ! is_array( $compiled ) ) {
			return '';
		}

		$live = function ( $list ) {
			return array_values(
				array_filter(
					(array) $list,
					function ( $rule ) {
						return empty( $rule['test'] );
					}
				)
			);
		};

		return md5( wp_json_encode( array( $live( isset( $compiled['front'] ) ? $compiled['front'] : array() ), $live( isset( $compiled['items'] ) ? $compiled['items'] : array() ) ) ) );
	}

	/**
	 * Add a starter rule (as a rule in testing) and open it.
	 *
	 * @return void
	 */
	public function add_starter() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			wp_die( esc_html__( 'You can\'t do that.', 'disable-plugins-per-page' ), 403 );
		}

		check_admin_referer( 'dppp_starter' );

		$key      = isset( $_GET['starter'] ) ? sanitize_key( wp_unslash( $_GET['starter'] ) ) : '';
		$starters = Disable_Plugins_Per_Page_Rules::starters();

		if ( ! isset( $starters[ $key ] ) ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$rule     = array_merge( Disable_Plugins_Per_Page_Rules::blank( isset( $starters[ $key ]['rule']['area'] ) ? $starters[ $key ]['rule']['area'] : 'front' ), $starters[ $key ]['rule'], array( 'starter' => $key ) );
		$settings = Disable_Plugins_Per_Page_Plugin::settings();

		$settings['rules'][] = $rule;

		update_option( Disable_Plugins_Per_Page_Plugin::OPTION, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'dppp_notice' => 'starter',
					'dppp_open'   => $rule['id'],
				),
				self::page_url( 'admin' === $rule['area'] ? 'admin' : 'rules' )
			)
		);
		exit;
	}

	/**
	 * Turn back on what the crash guard paused.
	 *
	 * @return void
	 */
	public function resume() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			wp_die( esc_html__( 'You can\'t do that.', 'disable-plugins-per-page' ), 403 );
		}

		check_admin_referer( 'dppp_resume' );
		Disable_Plugins_Per_Page_Plugin::resume_crashed();

		wp_safe_redirect( add_query_arg( 'dppp_notice', 'resumed', self::page_url( 'safety' ) ) );
		exit;
	}

	/**
	 * Make a new recovery link (the old one stops working).
	 *
	 * @return void
	 */
	public function new_key() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			wp_die( esc_html__( 'You can\'t do that.', 'disable-plugins-per-page' ), 403 );
		}

		check_admin_referer( 'dppp_new_key' );
		Disable_Plugins_Per_Page_Plugin::new_recovery_key();

		wp_safe_redirect( add_query_arg( 'dppp_notice', 'newkey', self::page_url( 'safety' ) ) );
		exit;
	}

	/**
	 * AJAX: find posts for the "Chosen pages" picker.
	 *
	 * @return void
	 */
	public function ajax_search() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			wp_send_json_error( null, 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$q     = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
		$types = Disable_Plugins_Per_Page_Rules::post_types();
		$args  = array(
			'post_type'        => array_keys( $types ),
			'post_status'      => array( 'publish', 'private', 'future', 'draft' ),
			'posts_per_page'   => 10,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);

		if ( preg_match( '#^https?://#i', $q ) ) {
			$id = url_to_postid( $q );

			$args['post__in'] = array( $id ? $id : -1 );
		} elseif ( ctype_digit( $q ) ) {
			$args['post__in'] = array( (int) $q );
		} elseif ( '' !== $q ) {
			$args['s'] = $q;
		} else {
			$args['orderby'] = 'modified';
		}

		$results = array();

		foreach ( get_posts( $args ) as $post ) {
			$results[] = array(
				'id'    => (int) $post->ID,
				'title' => self::post_label( $post ),
				'type'  => self::type_label( $post->post_type ),
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * A post type's singular name ("Page", "Product").
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	private static function type_label( $type ) {
		$object = get_post_type_object( $type );

		return $object ? $object->labels->singular_name : $type;
	}

	/**
	 * A post's title for lists.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function post_label( $post ) {
		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );

		if ( '' === $title ) {
			/* translators: %d: post ID. */
			$title = sprintf( __( '(no title) #%d', 'disable-plugins-per-page' ), $post->ID );
		}

		return html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="disable-plugins-per-page-menu-icon-style">'
			. '#adminmenu a:has(>.disable-plugins-per-page-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .disable-plugins-per-page-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .disable-plugins-per-page-menu-icon,#adminmenu a:hover .disable-plugins-per-page-menu-icon,#adminmenu a:focus .disable-plugins-per-page-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'disable-plugins-per-page-admin', DISABLE_PLUGINS_PER_PAGE_URL . 'assets/admin.css', array(), DISABLE_PLUGINS_PER_PAGE_VERSION );
		wp_enqueue_script( 'disable-plugins-per-page-admin', DISABLE_PLUGINS_PER_PAGE_URL . 'assets/admin.js', array(), DISABLE_PLUGINS_PER_PAGE_VERSION, true );

		$installed = Disable_Plugins_Per_Page_Rules::installed();
		$names     = array();

		foreach ( $installed as $file => $data ) {
			$names[ $file ] = Disable_Plugins_Per_Page_Rules::name( $file, $installed );
		}

		$compiled = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );

		wp_localize_script(
			'disable-plugins-per-page-admin',
			'disable_plugins_per_page_admin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'measureNonce' => wp_create_nonce( Disable_Plugins_Per_Page_Measure::NONCE ),
				'rounds'       => Disable_Plugins_Per_Page_Measure::ROUNDS,
				'names'        => $names,
				'deps'         => is_array( $compiled ) && isset( $compiled['deps'] ) ? $compiled['deps'] : array(),
				'iconX'        => self::icon( 'x', 13 ),
				'i18n'         => array(
					'newRule'    => __( 'New rule', 'disable-plugins-per-page' ),
					/* translators: 1: first plugin name, 2: number of other plugins. */
					'more'       => __( '%1$s and %2$d more', 'disable-plugins-per-page' ),
					'confirmDel' => __( 'Delete this rule? It\'s gone once you save.', 'disable-plugins-per-page' ),
					'unsaved'    => __( 'You have unsaved changes.', 'disable-plugins-per-page' ),
					'withIt'     => __( 'Also turned off with it, because they need it:', 'disable-plugins-per-page' ),
					'searching'  => __( 'Searching...', 'disable-plugins-per-page' ),
					'noResults'  => __( 'Nothing found.', 'disable-plugins-per-page' ),
					'remove'     => __( 'Remove', 'disable-plugins-per-page' ),
					'checking'   => __( 'Checking...', 'disable-plugins-per-page' ),
					/* translators: 1: page path, 2: current test request, 3: total test requests. */
					'measuring'  => __( 'Measuring %1$s (%2$d of %3$d)...', 'disable-plugins-per-page' ),
					'done'       => __( 'Done.', 'disable-plugins-per-page' ),
					'failed'     => __( 'Something went wrong. Try again.', 'disable-plugins-per-page' ),
					'copied'     => __( 'Copied', 'disable-plugins-per-page' ),
					'newKey'     => __( 'Make a new recovery link? The current one stops working.', 'disable-plugins-per-page' ),
					'status'     => array(
						'test' => __( 'Testing', 'disable-plugins-per-page' ),
						'live' => __( 'Live', 'disable-plugins-per-page' ),
						'off'  => __( 'Off', 'disable-plugins-per-page' ),
					),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name  Lucide icon.
	 * @param int    $size  Size in px.
	 * @param string $class Extra class.
	 * @return string
	 */
	private static function icon( $name, $size = 18, $class = '' ) {
		return Disable_Plugins_Per_Page_Icons::get( $name, $size, $class );
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'rules'   => __( 'Front-end rules', 'disable-plugins-per-page' ),
			'admin'   => __( 'Admin rules', 'disable-plugins-per-page' ),
			'pages'   => __( 'Pages', 'disable-plugins-per-page' ),
			'results' => __( 'Before & after', 'disable-plugins-per-page' ),
			'safety'  => __( 'Safety', 'disable-plugins-per-page' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Notices on other admin screens: a missing loader, paused rules, a crash.
	 *
	 * @return void
	 */
	public function global_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) || $screen->id === $this->hook_suffix ) {
			return;
		}

		$state = Disable_Plugins_Per_Page_Filter::state();

		if ( ! empty( $state['crash'] ) && in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Disable Plugins Per Page:', 'disable-plugins-per-page' ),
				esc_html__( 'a page crashed while plugins were turned off, so the rules involved were paused.', 'disable-plugins-per-page' ),
				esc_url( self::page_url( 'safety' ) ),
				esc_html__( 'See what happened', 'disable-plugins-per-page' )
			);
		}

		if ( 'plugins' !== $screen->id ) {
			return;
		}

		$status = Disable_Plugins_Per_Page_Loader::status();

		if ( 'installed' !== $status && 'outdated' !== $status && ! ( defined( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Disable Plugins Per Page:', 'disable-plugins-per-page' ),
				esc_html__( 'its must-use loader isn\'t installed, so no rule runs yet.', 'disable-plugins-per-page' ),
				esc_url( self::page_url( 'safety' ) ),
				esc_html__( 'Fix it', 'disable-plugins-per-page' )
			);
		}
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			return;
		}

		$settings  = $this->plugin->get_settings();
		$installed = Disable_Plugins_Per_Page_Rules::installed();
		$choosable = Disable_Plugins_Per_Page_Rules::choosable();
		$info      = get_option( Disable_Plugins_Per_Page_Rules::INFO, array() );
		$state     = Disable_Plugins_Per_Page_Filter::state();
		$context   = array(
			'installed' => $installed,
			'choosable' => $choosable,
			'info'      => is_array( $info ) ? $info : array(),
			'paused'    => isset( $state['paused_rules'] ) ? (array) $state['paused_rules'] : array(),
		);
		?>
		<div class="dppp-header">
			<div class="dppp-header-title">
				<span class="dppp-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="dppp-version"><?php echo esc_html( 'v' . DISABLE_PLUGINS_PER_PAGE_VERSION ); ?></span>
			</div>
			<nav class="dppp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'disable-plugins-per-page' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="dppp-tab" role="tab" data-dppp-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap dppp-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			$this->render_notices( $state );
			?>

			<form action="options.php" method="post" class="dppp-form">
				<?php settings_fields( self::PAGE ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::name( 'form' ) ); ?>" value="1">

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							$this->box( 'rules', 'globe', __( 'Front-end rules', 'disable-plugins-per-page' ), array( $this, 'render_front_rules' ), array( $settings, $context ) );
							$this->box( 'rules', 'sparkles', __( 'Starter rules', 'disable-plugins-per-page' ), array( $this, 'render_starters' ), array( $settings ) );
							$this->box( 'admin', 'layout-dashboard', __( 'Admin rules', 'disable-plugins-per-page' ), array( $this, 'render_admin_rules' ), array( $settings, $context ) );
							$this->box( 'pages', 'file-text', __( 'Pages with their own settings', 'disable-plugins-per-page' ), array( $this, 'render_pages' ), array( $installed, $state ) );
							$this->box( 'results', 'scan-search', __( 'Check a page', 'disable-plugins-per-page' ), array( $this, 'render_check' ), array() );
							$this->box( 'results', 'chart-column', __( 'Before and after', 'disable-plugins-per-page' ), array( $this, 'render_results' ), array() );
							$this->box( 'safety', 'life-buoy', __( 'Recovery link', 'disable-plugins-per-page' ), array( $this, 'render_recovery' ), array() );
							$this->box( 'safety', 'shield-alert', __( 'Crash guard', 'disable-plugins-per-page' ), array( $this, 'render_guard' ), array( $settings, $state, $installed ) );
							$this->box( 'safety', 'file-code', __( 'Safe mode in wp-config.php', 'disable-plugins-per-page' ), array( $this, 'render_safe_mode' ), array() );
							$this->box( 'safety', 'shield-check', __( 'Always loads every plugin', 'disable-plugins-per-page' ), array( $this, 'render_never' ), array() );
							$this->box( 'safety', 'puzzle', __( 'Must-use loader', 'disable-plugins-per-page' ), array( $this, 'render_loader' ), array() );
							$this->box( 'safety', 'sliders-horizontal', __( 'Options', 'disable-plugins-per-page' ), array( $this, 'render_options' ), array( $settings ) );
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="dppp-sidebar">
								<?php $this->render_sidebar( $settings, $state ); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>

				<template id="dppp-template-front"><?php $this->render_rule( array( 'id' => '__ID__' ) + Disable_Plugins_Per_Page_Rules::blank( 'front' ), $context, true ); ?></template>
				<template id="dppp-template-admin"><?php $this->render_rule( array( 'id' => '__ID__' ) + Disable_Plugins_Per_Page_Rules::blank( 'admin' ), $context, true ); ?></template>
			</form>
		</div>
		<?php
	}

	/**
	 * One box (postbox) on a tab.
	 *
	 * @param string   $tab    Tab it's on.
	 * @param string   $icon   Icon.
	 * @param string   $title  Title.
	 * @param callable $render Draws the inside.
	 * @param array    $args   Arguments for $render.
	 * @return void
	 */
	private function box( $tab, $icon, $title, $render, array $args ) {
		?>
		<div class="postbox dppp-box" data-dppp-panel="<?php echo esc_attr( $tab ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php call_user_func_array( $render, $args ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key, or a path like "rules][r1][label".
	 * @return string
	 */
	private static function name( $key ) {
		return Disable_Plugins_Per_Page_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Notices at the top of the page.
	 *
	 * @param array $state State record.
	 * @return void
	 */
	private function render_notices( array $state ) {
		$notice  = isset( $_GET['dppp_notice'] ) ? sanitize_key( wp_unslash( $_GET['dppp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notices = array(
			'recovered' => array( 'warning', __( 'The recovery link paused every rule, so every plugin loads everywhere. Fix or delete the rule that caused the problem, then switch the rules back on in the Save box.', 'disable-plugins-per-page' ) ),
			'starter'   => array( 'success', __( 'Starter rule added in testing: it only applies to administrators. Check a few pages, then set it live.', 'disable-plugins-per-page' ) ),
			'resumed'   => array( 'success', __( 'The paused rules are back on.', 'disable-plugins-per-page' ) ),
			'newkey'    => array( 'success', __( 'New recovery link made. The old one no longer works.', 'disable-plugins-per-page' ) ),
		);

		if ( isset( $notices[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
		}

		if ( defined( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Safe mode is on in wp-config.php (DISABLE_PLUGINS_PER_PAGE_SAFE_MODE), so no rule runs. Every plugin loads everywhere until you remove that line.', 'disable-plugins-per-page' ) . '</p></div>';
		} elseif ( ! empty( $state['paused'] ) ) {
			$by = isset( $state['paused_by'] ) && 'link' === $state['paused_by'] ? __( 'by the recovery link', 'disable-plugins-per-page' ) : __( 'with the switch in the Save box', 'disable-plugins-per-page' );
			/* translators: 1: how they were paused, 2: how long ago. */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Every rule is paused (%1$s, %2$s ago). Every plugin loads everywhere until you switch them back on in the Save box.', 'disable-plugins-per-page' ), $by, human_time_diff( (int) $state['paused'] ) ) ) . '</p></div>';
		}

		if ( ! empty( $state['crash'] ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="#safety">%3$s</a></p></div>',
				esc_html__( 'A page crashed while plugins were turned off.', 'disable-plugins-per-page' ),
				esc_html__( 'The crash guard paused the rules involved, so visitors get a working page.', 'disable-plugins-per-page' ),
				esc_html__( 'See what happened', 'disable-plugins-per-page' )
			);
		}

		$status = Disable_Plugins_Per_Page_Loader::status();

		if ( 'installed' !== $status && 'outdated' !== $status && ! ( defined( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The must-use loader isn\'t installed, so no rule runs yet. See the Safety tab.', 'disable-plugins-per-page' ) . '</p></div>';
		}
	}

	// Rules -----------------------------------------------------------------

	/**
	 * The front-end rules box.
	 *
	 * @param array $settings Settings.
	 * @param array $context  Shared render data.
	 * @return void
	 */
	public function render_front_rules( array $settings, array $context ) {
		?>
		<p class="dppp-intro"><?php esc_html_e( 'Each rule stops one or more plugins from loading on parts of your site that don\'t need them, so those pages run less code and fewer database queries. New rules start in testing: they only apply to administrators until you set them live.', 'disable-plugins-per-page' ); ?></p>
		<?php
		$this->render_rule_list( $settings, $context, 'front' );
	}

	/**
	 * The admin rules box.
	 *
	 * @param array $settings Settings.
	 * @param array $context  Shared render data.
	 * @return void
	 */
	public function render_admin_rules( array $settings, array $context ) {
		?>
		<p class="dppp-intro"><?php esc_html_e( 'Keep heavy plugins from loading on admin screens that don\'t use them. The Plugins and Updates screens and this settings page always load every plugin, so you can always undo a rule.', 'disable-plugins-per-page' ); ?></p>
		<?php
		$this->render_rule_list( $settings, $context, 'admin' );
	}

	/**
	 * A list of rule cards with an "Add a rule" button.
	 *
	 * @param array  $settings Settings.
	 * @param array  $context  Shared render data.
	 * @param string $area     'front' or 'admin'.
	 * @return void
	 */
	private function render_rule_list( array $settings, array $context, $area ) {
		$open  = isset( $_GET['dppp_open'] ) ? sanitize_key( wp_unslash( $_GET['dppp_open'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$count = 0;
		?>
		<div class="dppp-rules" data-dppp-rules="<?php echo esc_attr( $area ); ?>">
			<?php
			foreach ( (array) $settings['rules'] as $rule ) {
				if ( ! is_array( $rule ) || ( isset( $rule['area'] ) ? $rule['area'] : 'front' ) !== $area ) {
					continue;
				}

				++$count;
				$this->render_rule( wp_parse_args( $rule, Disable_Plugins_Per_Page_Rules::blank( $area ) ), $context, $open === $rule['id'] );
			}
			?>
		</div>
		<p class="dppp-empty dppp-rules-empty" <?php echo $count ? 'hidden' : ''; ?>>
			<?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<?php echo 'admin' === $area ? esc_html__( 'No admin rules yet. Every plugin loads on every admin screen.', 'disable-plugins-per-page' ) : esc_html__( 'No rules yet. Every plugin loads on every page. Add a rule, or start from a starter rule below.', 'disable-plugins-per-page' ); ?>
		</p>
		<p class="dppp-add">
			<button type="button" class="button" data-dppp-add="<?php echo esc_attr( $area ); ?>">
				<?php echo self::icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php esc_html_e( 'Add a rule', 'disable-plugins-per-page' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * One rule card.
	 *
	 * @param array $rule    Rule.
	 * @param array $context Shared render data.
	 * @param bool  $open    Start expanded.
	 * @return void
	 */
	private function render_rule( array $rule, array $context, $open = false ) {
		$id      = $rule['id'];
		$is_tpl  = false !== strpos( $id, '__' );
		$base    = 'rules][' . $id . '][';
		$admin   = 'admin' === $rule['area'];
		$paused  = in_array( $id, $context['paused'], true );
		$title   = Disable_Plugins_Per_Page_Rules::title( $rule, $context['installed'] );
		$summary = Disable_Plugins_Per_Page_Rules::summary( $rule );
		$status  = array(
			'test' => array( __( 'Testing', 'disable-plugins-per-page' ), __( 'Administrators only', 'disable-plugins-per-page' ), 'flask-conical' ),
			'live' => array( __( 'Live', 'disable-plugins-per-page' ), __( 'Everyone', 'disable-plugins-per-page' ), 'radio' ),
			'off'  => array( __( 'Off', 'disable-plugins-per-page' ), __( 'Nobody', 'disable-plugins-per-page' ), 'circle-pause' ),
		);
		$uid     = 'dppp-' . $id;
		?>
		<div class="dppp-rule<?php echo $open ? ' is-open' : ''; ?>" data-dppp-rule="<?php echo esc_attr( $id ); ?>" data-status="<?php echo esc_attr( $rule['status'] ); ?>">
			<button type="button" class="dppp-rule-head" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $uid ); ?>-body">
				<span class="dppp-rule-icon"><?php echo self::icon( $admin ? 'layout-dashboard' : 'plug', 17 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<span class="dppp-rule-text">
					<span class="dppp-rule-title" data-dppp-title><?php echo esc_html( $title ); ?></span>
					<span class="dppp-rule-summary"><?php echo esc_html( $summary ); ?></span>
				</span>
				<?php if ( $paused ) : ?>
					<span class="dppp-pill is-paused"><?php esc_html_e( 'Paused after a crash', 'disable-plugins-per-page' ); ?></span>
				<?php endif; ?>
				<span class="dppp-pill is-<?php echo esc_attr( $rule['status'] ); ?>" data-dppp-pill><?php echo esc_html( $status[ $rule['status'] ][0] ); ?></span>
				<?php echo self::icon( 'chevron-down', 18, 'dppp-rule-chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			</button>

			<div class="dppp-rule-body" id="<?php echo esc_attr( $uid ); ?>-body" <?php echo $open ? '' : 'hidden'; ?>>
				<input type="hidden" name="<?php echo esc_attr( self::name( $base . 'id' ) ); ?>" value="<?php echo esc_attr( $id ); ?>">
				<input type="hidden" name="<?php echo esc_attr( self::name( $base . 'area' ) ); ?>" value="<?php echo esc_attr( $rule['area'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( self::name( $base . 'starter' ) ); ?>" value="<?php echo esc_attr( $rule['starter'] ); ?>">

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'disable-plugins-per-page' ); ?></th>
							<td>
								<div class="dppp-segmented" role="radiogroup">
									<?php foreach ( $status as $key => $label ) : ?>
										<label class="dppp-segment is-<?php echo esc_attr( $key ); ?>">
											<input type="radio" name="<?php echo esc_attr( self::name( $base . 'status' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $rule['status'] ); ?> data-dppp-status>
											<span><?php echo self::icon( $label[2], 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><strong><?php echo esc_html( $label[0] ); ?></strong><em><?php echo esc_html( $label[1] ); ?></em></span>
										</label>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plugins', 'disable-plugins-per-page' ); ?></th>
							<td>
								<?php $this->render_plugin_picker( $rule, $base, $context ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'What to do', 'disable-plugins-per-page' ); ?></th>
							<td>
								<div class="dppp-radios">
									<label><input type="radio" name="<?php echo esc_attr( self::name( $base . 'action' ) ); ?>" value="off" <?php checked( 'off', $rule['action'] ); ?>> <?php echo $admin ? esc_html__( 'Turn them off on the screens below', 'disable-plugins-per-page' ) : esc_html__( 'Turn them off on the pages below', 'disable-plugins-per-page' ); ?></label>
									<label><input type="radio" name="<?php echo esc_attr( self::name( $base . 'action' ) ); ?>" value="only" <?php checked( 'only', $rule['action'] ); ?>> <?php echo $admin ? esc_html__( 'Only load them on the screens below', 'disable-plugins-per-page' ) : esc_html__( 'Only load them on the pages below (off everywhere else)', 'disable-plugins-per-page' ); ?></label>
								</div>
							</td>
						</tr>
						<?php
						if ( $admin ) {
							$this->render_admin_where( $rule, $base );
						} else {
							$this->render_front_where( $rule, $base, $context );
						}
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-label"><?php esc_html_e( 'Name', 'disable-plugins-per-page' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="<?php echo esc_attr( $uid ); ?>-label" name="<?php echo esc_attr( self::name( $base . 'label' ) ); ?>" value="<?php echo esc_attr( $rule['label'] ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'Optional, e.g. "Shop plugins only on shop pages"', 'disable-plugins-per-page' ); ?>" data-dppp-label>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="dppp-rule-foot">
					<button type="button" class="button-link button-link-delete" data-dppp-delete>
						<?php echo self::icon( 'trash-2', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php esc_html_e( 'Delete rule', 'disable-plugins-per-page' ); ?>
					</button>
					<?php if ( ! $is_tpl ) : ?>
						<span class="dppp-muted dppp-rule-id"><?php echo esc_html( $id ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The plugin checkboxes in a rule.
	 *
	 * @param array  $rule    Rule.
	 * @param string $base    Name prefix.
	 * @param array  $context Shared render data.
	 * @return void
	 */
	private function render_plugin_picker( array $rule, $base, array $context ) {
		$list = $context['choosable'];

		// Plugins chosen earlier that are no longer active stay listed.
		foreach ( (array) $rule['plugins'] as $file ) {
			if ( ! isset( $list[ $file ] ) ) {
				/* translators: %s: plugin name. */
				$list[ $file ] = sprintf( __( '%s (inactive)', 'disable-plugins-per-page' ), Disable_Plugins_Per_Page_Rules::name( $file, $context['installed'] ) );
			}
		}

		if ( ! $list ) {
			echo '<p class="dppp-muted">' . esc_html__( 'There are no other active plugins.', 'disable-plugins-per-page' ) . '</p>';
			return;
		}
		?>
		<div class="dppp-picker" data-dppp-picker>
			<?php if ( count( $list ) > 8 ) : ?>
				<input type="search" class="dppp-picker-filter" placeholder="<?php esc_attr_e( 'Filter plugins', 'disable-plugins-per-page' ); ?>" aria-label="<?php esc_attr_e( 'Filter plugins', 'disable-plugins-per-page' ); ?>" data-dppp-filter>
			<?php endif; ?>
			<div class="dppp-picker-list">
				<?php foreach ( $list as $file => $name ) : ?>
					<label class="dppp-picker-item">
						<input type="checkbox" name="<?php echo esc_attr( self::name( $base . 'plugins][' ) ); ?>" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, (array) $rule['plugins'], true ) ); ?> data-dppp-plugin>
						<span><?php echo esc_html( $name ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="dppp-deps" data-dppp-deps hidden></p>
		<?php
	}

	/**
	 * Where a front-end rule applies.
	 *
	 * @param array  $rule    Rule.
	 * @param string $base    Name prefix.
	 * @param array  $context Shared render data.
	 * @return void
	 */
	private function render_front_where( array $rule, $base, array $context ) {
		$uid   = 'dppp-' . $rule['id'];
		$info  = isset( $context['info'][ $rule['id'] ] ) ? $context['info'][ $rule['id'] ] : null;
		$types = Disable_Plugins_Per_Page_Rules::post_types();

		// Post types chosen earlier that aren't registered any more stay listed.
		foreach ( (array) $rule['post_types'] as $type ) {
			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = $type;
			}
		}
		?>
		<tr class="dppp-where">
			<th scope="row"><?php esc_html_e( 'Pages', 'disable-plugins-per-page' ); ?></th>
			<td>
				<label class="dppp-check dppp-everywhere">
					<input type="checkbox" name="<?php echo esc_attr( self::name( $base . 'everywhere' ) ); ?>" value="1" <?php checked( 1, (int) $rule['everywhere'] ); ?> data-dppp-everywhere>
					<span><?php esc_html_e( 'Every page on the site', 'disable-plugins-per-page' ); ?></span>
				</label>

				<div class="dppp-where-fields" data-dppp-where-fields>
					<fieldset class="dppp-field">
						<legend><?php esc_html_e( 'Kinds of page', 'disable-plugins-per-page' ); ?></legend>
						<div class="dppp-chips">
							<?php foreach ( Disable_Plugins_Per_Page_Rules::page_types() as $key => $label ) : ?>
								<label class="dppp-chip-check"><input type="checkbox" name="<?php echo esc_attr( self::name( $base . 'types][' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $rule['types'], true ) ); ?>> <span><?php echo esc_html( $label ); ?></span></label>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<fieldset class="dppp-field">
						<legend><?php esc_html_e( 'Every single item of these post types', 'disable-plugins-per-page' ); ?></legend>
						<div class="dppp-chips">
							<?php foreach ( $types as $key => $label ) : ?>
								<label class="dppp-chip-check"><input type="checkbox" name="<?php echo esc_attr( self::name( $base . 'post_types][' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $rule['post_types'], true ) ); ?>> <span><?php echo esc_html( $label ); ?></span></label>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<div class="dppp-field">
						<label class="dppp-field-label" for="<?php echo esc_attr( $uid ); ?>-search"><?php esc_html_e( 'Chosen pages', 'disable-plugins-per-page' ); ?></label>
						<div class="dppp-items" data-dppp-items data-name="<?php echo esc_attr( self::name( $base . 'items][' ) ); ?>">
							<div class="dppp-item-list" data-dppp-item-list>
								<?php foreach ( (array) $rule['items'] as $item_id ) : ?>
									<?php $post = get_post( (int) $item_id ); ?>
									<span class="dppp-item">
										<span><?php echo esc_html( $post ? self::post_label( $post ) : '#' . (int) $item_id ); ?></span>
										<button type="button" class="dppp-item-remove" aria-label="<?php esc_attr_e( 'Remove', 'disable-plugins-per-page' ); ?>" data-dppp-item-remove><?php echo self::icon( 'x', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
										<input type="hidden" name="<?php echo esc_attr( self::name( $base . 'items][' ) ); ?>" value="<?php echo esc_attr( (int) $item_id ); ?>">
									</span>
								<?php endforeach; ?>
							</div>
							<div class="dppp-search">
								<?php echo self::icon( 'search', 15, 'dppp-search-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
								<input type="search" id="<?php echo esc_attr( $uid ); ?>-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by title, ID or URL', 'disable-plugins-per-page' ); ?>" autocomplete="off" data-dppp-search>
								<ul class="dppp-search-results" data-dppp-results hidden></ul>
							</div>
						</div>
					</div>

					<div class="dppp-field">
						<label class="dppp-field-label" for="<?php echo esc_attr( $uid ); ?>-contains"><?php esc_html_e( 'Pages whose content uses', 'disable-plugins-per-page' ); ?></label>
						<textarea id="<?php echo esc_attr( $uid ); ?>-contains" class="large-text code" rows="2" name="<?php echo esc_attr( self::name( $base . 'contains' ) ); ?>" placeholder="[contact-form-7&#10;wp:contact-form-7/"><?php echo esc_textarea( implode( "\n", (array) $rule['contains'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One shortcode or block name per line. The pages are found again whenever a page is saved.', 'disable-plugins-per-page' ); ?></p>
						<?php if ( $info && $rule['contains'] ) : ?>
							<p class="dppp-found">
								<?php echo self::icon( 'file-search', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
								<?php
								/* translators: %d: number of pages. */
								echo esc_html( sprintf( _n( 'Found on %d page.', 'Found on %d pages.', (int) $info['found'], 'disable-plugins-per-page' ), (int) $info['found'] ) );
								?>
							</p>
							<?php if ( ! empty( $info['global'] ) ) : ?>
								<p class="dppp-callout is-warning"><?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'It\'s also used in a widget or a theme template, which can show on every page. Those pages aren\'t on the list, so check them before setting this rule live.', 'disable-plugins-per-page' ); ?></span></p>
							<?php endif; ?>
						<?php endif; ?>
					</div>

					<div class="dppp-field">
						<label class="dppp-field-label" for="<?php echo esc_attr( $uid ); ?>-patterns"><?php esc_html_e( 'URL patterns', 'disable-plugins-per-page' ); ?></label>
						<textarea id="<?php echo esc_attr( $uid ); ?>-patterns" class="large-text code" rows="3" name="<?php echo esc_attr( self::name( $base . 'patterns' ) ); ?>" placeholder="/shop/*&#10;/cart/&#10;/checkout/*"><?php echo esc_textarea( implode( "\n", (array) $rule['patterns'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. Use * as a wildcard: /shop/* matches /shop/ and everything under it. Full URLs work too.', 'disable-plugins-per-page' ); ?></p>
					</div>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-device"><?php esc_html_e( 'Devices', 'disable-plugins-per-page' ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $uid ); ?>-device" name="<?php echo esc_attr( self::name( $base . 'device' ) ); ?>">
					<option value="" <?php selected( '', $rule['device'] ); ?>><?php esc_html_e( 'All devices', 'disable-plugins-per-page' ); ?></option>
					<option value="mobile" <?php selected( 'mobile', $rule['device'] ); ?>><?php esc_html_e( 'Phones and tablets only', 'disable-plugins-per-page' ); ?></option>
					<option value="desktop" <?php selected( 'desktop', $rule['device'] ); ?>><?php esc_html_e( 'Computers only', 'disable-plugins-per-page' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'With a page cache, device rules need separate mobile and desktop copies (for example WP Rocket\'s "Separate cache files for mobile devices").', 'disable-plugins-per-page' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Where an admin rule applies.
	 *
	 * @param array  $rule Rule.
	 * @param string $base Name prefix.
	 * @return void
	 */
	private function render_admin_where( array $rule, $base ) {
		$uid = 'dppp-' . $rule['id'];
		?>
		<tr class="dppp-where">
			<th scope="row"><?php esc_html_e( 'Screens', 'disable-plugins-per-page' ); ?></th>
			<td>
				<label class="dppp-check dppp-everywhere">
					<input type="checkbox" name="<?php echo esc_attr( self::name( $base . 'everywhere' ) ); ?>" value="1" <?php checked( 1, (int) $rule['everywhere'] ); ?> data-dppp-everywhere>
					<span><?php esc_html_e( 'Every admin screen (except the plugin and update screens)', 'disable-plugins-per-page' ); ?></span>
				</label>

				<div class="dppp-where-fields" data-dppp-where-fields>
					<fieldset class="dppp-field">
						<legend><?php esc_html_e( 'WordPress screens', 'disable-plugins-per-page' ); ?></legend>
						<div class="dppp-screens">
							<?php foreach ( Disable_Plugins_Per_Page_Rules::screens() as $key => $screen ) : ?>
								<label class="dppp-screen">
									<input type="checkbox" name="<?php echo esc_attr( self::name( $base . 'screens][' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $rule['screens'], true ) ); ?>>
									<?php echo self::icon( $screen[1], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
									<span><?php echo esc_html( $screen[0] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Plugins\' own pages under these menus (Settings > Some Plugin) aren\'t included. A plugin that\'s off in the post editor can\'t offer its blocks there.', 'disable-plugins-per-page' ); ?></p>
					</fieldset>

					<div class="dppp-field">
						<label class="dppp-field-label" for="<?php echo esc_attr( $uid ); ?>-admin-patterns"><?php esc_html_e( 'Screen URL patterns', 'disable-plugins-per-page' ); ?></label>
						<textarea id="<?php echo esc_attr( $uid ); ?>-admin-patterns" class="large-text code" rows="3" name="<?php echo esc_attr( self::name( $base . 'admin_patterns' ) ); ?>" placeholder="admin.php?page=wpcf7*&#10;edit.php?post_type=product*"><?php echo esc_textarea( implode( "\n", (array) $rule['admin_patterns'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, the part of the address after /wp-admin/. Use * as a wildcard.', 'disable-plugins-per-page' ); ?></p>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * The starter rules box.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public function render_starters( array $settings ) {
		$starters = Disable_Plugins_Per_Page_Rules::starters();
		$added    = array();

		foreach ( (array) $settings['rules'] as $rule ) {
			if ( is_array( $rule ) && ! empty( $rule['starter'] ) ) {
				$added[ $rule['starter'] ] = true;
			}
		}
		?>
		<p class="dppp-intro"><?php esc_html_e( 'Ready-made rules for common plugins that are active on this site. Each one is added in testing, so only administrators see the change until you set it live.', 'disable-plugins-per-page' ); ?></p>
		<?php if ( ! $starters ) : ?>
			<p class="dppp-empty"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'None of the plugins with starter rules (WooCommerce, Contact Form 7 and other form plugins, Slider Revolution, Smart Slider, MetaSlider, LayerSlider) is active here.', 'disable-plugins-per-page' ); ?></p>
			<?php
			return;
		endif;
		?>
		<div class="dppp-starters">
			<?php foreach ( $starters as $key => $starter ) : ?>
				<div class="dppp-starter">
					<span class="dppp-starter-icon"><?php echo self::icon( $starter['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<div class="dppp-starter-text">
						<strong><?php echo esc_html( $starter['title'] ); ?></strong>
						<span><?php echo esc_html( $starter['text'] ); ?></span>
					</div>
					<?php if ( isset( $added[ $key ] ) ) : ?>
						<span class="dppp-starter-added"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Added', 'disable-plugins-per-page' ); ?></span>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( self::action_url( 'dppp_starter', array( 'starter' => $key ) ) ); ?>" data-dppp-leave><?php esc_html_e( 'Add rule', 'disable-plugins-per-page' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// Pages -------------------------------------------------------------------

	/**
	 * Posts and pages with their own plugin settings.
	 *
	 * @param array $installed From installed().
	 * @param array $state     State record.
	 * @return void
	 */
	public function render_pages( array $installed, array $state ) {
		$ids    = get_posts(
			array(
				'post_type'        => array_keys( Disable_Plugins_Per_Page_Rules::post_types() ),
				'post_status'      => 'any',
				'meta_key'         => Disable_Plugins_Per_Page_Rules::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin page only.
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$paused = isset( $state['paused_posts'] ) ? array_map( 'intval', (array) $state['paused_posts'] ) : array();
		?>
		<p class="dppp-intro"><?php esc_html_e( 'Open any post or page in the editor and use the "Plugins on this page" panel in the sidebar to turn plugins off just there, or to keep one loading there when a rule turns it off. Pages with their own settings are listed here.', 'disable-plugins-per-page' ); ?></p>
		<?php if ( ! $ids ) : ?>
			<p class="dppp-empty"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'No page has its own settings yet.', 'disable-plugins-per-page' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped dppp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'disable-plugins-per-page' ); ?></th>
					<th><?php esc_html_e( 'Turned off', 'disable-plugins-per-page' ); ?></th>
					<th><?php esc_html_e( 'Always loaded', 'disable-plugins-per-page' ); ?></th>
					<th><?php esc_html_e( 'Applies to', 'disable-plugins-per-page' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ids as $id ) : ?>
					<?php
					$post = get_post( $id );
					$data = Disable_Plugins_Per_Page_Rules::post_settings( $id );
					$off  = array();
					$on   = array();

					foreach ( $data['plugins'] as $file => $mode ) {
						if ( 'off' === $mode ) {
							$off[] = Disable_Plugins_Per_Page_Rules::name( $file, $installed );
						} else {
							$on[] = Disable_Plugins_Per_Page_Rules::name( $file, $installed );
						}
					}
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( self::post_label( $post ) ); ?></a></strong>
							<span class="dppp-muted"><?php echo esc_html( self::type_label( $post->post_type ) . ( 'publish' !== $post->post_status ? ' · ' . $post->post_status : '' ) ); ?></span>
						</td>
						<td><?php echo $off ? esc_html( implode( ', ', $off ) ) : '<span class="dppp-muted">-</span>'; ?></td>
						<td><?php echo $on ? esc_html( implode( ', ', $on ) ) : '<span class="dppp-muted">-</span>'; ?></td>
						<td>
							<?php if ( in_array( (int) $id, $paused, true ) ) : ?>
								<span class="dppp-pill is-paused"><?php esc_html_e( 'Paused after a crash', 'disable-plugins-per-page' ); ?></span>
							<?php elseif ( 'live' === $data['status'] ) : ?>
								<span class="dppp-pill is-live"><?php esc_html_e( 'Everyone', 'disable-plugins-per-page' ); ?></span>
							<?php else : ?>
								<span class="dppp-pill is-test"><?php esc_html_e( 'Testing', 'disable-plugins-per-page' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// Before and after ------------------------------------------------------------

	/**
	 * The "Check a page" box.
	 *
	 * @return void
	 */
	public function render_check() {
		?>
		<p class="dppp-intro"><?php esc_html_e( 'See which plugins your rules turn off on a page, and which rule does it. Nothing is loaded, so it\'s instant.', 'disable-plugins-per-page' ); ?></p>
		<div class="dppp-inline">
			<input type="text" class="regular-text dppp-url" value="<?php echo esc_attr( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Page address', 'disable-plugins-per-page' ); ?>" data-dppp-check-url>
			<button type="button" class="button" data-dppp-check><?php echo self::icon( 'scan-search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check', 'disable-plugins-per-page' ); ?></button>
		</div>
		<div class="dppp-check-result" data-dppp-check-result aria-live="polite"></div>
		<?php
	}

	/**
	 * The "Before and after" box.
	 *
	 * @return void
	 */
	public function render_results() {
		$report = Disable_Plugins_Per_Page_Measure::report();
		?>
		<p class="dppp-intro"><?php esc_html_e( 'Loads a page from your server with every plugin, then with your rules (including ones still in testing), a few times each, and compares the middle results: plugins loaded, database queries, server time, memory, and scripts and styles on the page.', 'disable-plugins-per-page' ); ?></p>
		<div class="dppp-inline">
			<input type="text" class="regular-text dppp-url" value="<?php echo esc_attr( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Page address', 'disable-plugins-per-page' ); ?>" data-dppp-measure-url>
			<button type="button" class="button button-primary" data-dppp-measure><?php echo self::icon( 'gauge', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Measure', 'disable-plugins-per-page' ); ?></button>
		</div>
		<div class="dppp-suggest">
			<span class="dppp-muted"><?php esc_html_e( 'Suggested:', 'disable-plugins-per-page' ); ?></span>
			<?php foreach ( Disable_Plugins_Per_Page_Measure::suggestions() as $url ) : ?>
				<?php $path = Disable_Plugins_Per_Page_Rules::url_path( $url ); ?>
				<button type="button" class="dppp-suggestion" data-dppp-suggest="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( '' !== $path ? $path : $url ); ?></button>
			<?php endforeach; ?>
			<button type="button" class="button-link" data-dppp-measure-all><?php esc_html_e( 'Measure all of these', 'disable-plugins-per-page' ); ?></button>
		</div>
		<p class="dppp-progress" data-dppp-progress aria-live="polite" hidden></p>
		<div class="dppp-results" data-dppp-results-list>
			<?php
			foreach ( $report as $row ) {
				echo Disable_Plugins_Per_Page_Measure::row_html( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
			}
			?>
		</div>
		<p class="dppp-empty dppp-results-empty" <?php echo $report ? 'hidden' : ''; ?>><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'No pages measured yet.', 'disable-plugins-per-page' ); ?></p>
		<p class="description"><?php esc_html_e( 'Server time is how long WordPress takes to build the page, without the network or the browser. Numbers vary a little between runs; differences under 10% are mostly noise.', 'disable-plugins-per-page' ); ?></p>
		<?php
	}

	// Safety ----------------------------------------------------------------------

	/**
	 * The recovery link box.
	 *
	 * @return void
	 */
	public function render_recovery() {
		?>
		<p class="dppp-intro"><?php esc_html_e( 'If a rule ever breaks your site, open this link. It pauses every rule straight away, before any plugin loads, even if you can\'t sign in, then takes you here to fix it. Save it in your password manager or bookmarks.', 'disable-plugins-per-page' ); ?></p>
		<div class="dppp-inline">
			<input type="text" class="large-text code dppp-copy-field" readonly value="<?php echo esc_attr( Disable_Plugins_Per_Page_Plugin::recovery_url() ); ?>" aria-label="<?php esc_attr_e( 'Recovery link', 'disable-plugins-per-page' ); ?>" data-dppp-copy-source>
			<button type="button" class="button" data-dppp-copy><?php echo self::icon( 'copy', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy', 'disable-plugins-per-page' ); ?></span></button>
		</div>
		<p class="description">
			<?php esc_html_e( 'Keep it private: anyone with the link can pause your rules (nothing else). If it gets out, make a new one.', 'disable-plugins-per-page' ); ?>
			<a href="<?php echo esc_url( self::action_url( 'dppp_new_key' ) ); ?>" data-dppp-new-key data-dppp-leave><?php esc_html_e( 'Make a new link', 'disable-plugins-per-page' ); ?></a>
		</p>
		<?php
	}

	/**
	 * The crash guard box.
	 *
	 * @param array $settings  Settings.
	 * @param array $state     State record.
	 * @param array $installed From installed().
	 * @return void
	 */
	public function render_guard( array $settings, array $state, array $installed ) {
		$crash = isset( $state['crash'] ) && is_array( $state['crash'] ) ? $state['crash'] : null;
		?>
		<p class="dppp-intro"><?php esc_html_e( 'Some themes and plugins call another plugin without checking it\'s there. If a page crashes while plugins are turned off, the crash guard pauses the rules (and page settings) that turned them off, so the next visitor gets a working page, and shows you what happened.', 'disable-plugins-per-page' ); ?></p>
		<label class="dppp-check">
			<input type="checkbox" name="<?php echo esc_attr( self::name( 'guard' ) ); ?>" value="1" <?php checked( 1, (int) $settings['guard'] ); ?>>
			<span><?php esc_html_e( 'Pause the rules involved when a page crashes', 'disable-plugins-per-page' ); ?></span>
		</label>
		<?php if ( $crash ) : ?>
			<?php
			$titles = array();

			foreach ( (array) $settings['rules'] as $rule ) {
				if ( is_array( $rule ) && isset( $rule['id'] ) && in_array( $rule['id'], (array) $crash['rules'], true ) ) {
					$titles[] = Disable_Plugins_Per_Page_Rules::title( $rule, $installed );
				}
			}

			foreach ( (array) $crash['posts'] as $post_id ) {
				/* translators: %s: page title. */
				$titles[] = sprintf( __( 'settings of "%s"', 'disable-plugins-per-page' ), get_the_title( (int) $post_id ) );
			}

			$off = array();

			foreach ( (array) $crash['plugins'] as $file ) {
				$off[] = Disable_Plugins_Per_Page_Rules::name( (string) $file, $installed );
			}
			?>
			<div class="dppp-crash">
				<h3><?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Last crash', 'disable-plugins-per-page' ); ?></h3>
				<table class="dppp-table-kv">
					<tr><th><?php esc_html_e( 'When', 'disable-plugins-per-page' ); ?></th><td><?php echo esc_html( sprintf( /* translators: %s: time ago. */ __( '%s ago', 'disable-plugins-per-page' ), human_time_diff( (int) $crash['time'] ) ) ); ?></td></tr>
					<tr><th><?php echo 'admin' === $crash['area'] ? esc_html__( 'Admin screen', 'disable-plugins-per-page' ) : esc_html__( 'Page', 'disable-plugins-per-page' ); ?></th><td><code><?php echo esc_html( $crash['where'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Plugins that were off', 'disable-plugins-per-page' ); ?></th><td><?php echo esc_html( implode( ', ', $off ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Paused', 'disable-plugins-per-page' ); ?></th><td><?php echo esc_html( implode( ', ', $titles ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Error', 'disable-plugins-per-page' ); ?></th><td><code class="dppp-error"><?php echo esc_html( $crash['message'] ); ?></code><br><span class="dppp-muted"><?php echo esc_html( $crash['file'] . ':' . (int) $crash['line'] ); ?></span></td></tr>
				</table>
				<p><?php esc_html_e( 'Usually the error names the plugin that\'s missing. Keep it loading on that page (or change the rule), then turn the paused rules back on.', 'disable-plugins-per-page' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( self::action_url( 'dppp_resume' ) ); ?>" data-dppp-leave><?php echo self::icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Turn the paused rules back on', 'disable-plugins-per-page' ); ?></a></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The wp-config.php safe mode box.
	 *
	 * @return void
	 */
	public function render_safe_mode() {
		$on = defined( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE;
		?>
		<p class="dppp-intro"><?php esc_html_e( 'If you can\'t open the recovery link, add this line to wp-config.php (above "That\'s all, stop editing!"). No rule runs while it\'s there. Deleting wp-content/mu-plugins/0-disable-plugins-per-page.php does the same.', 'disable-plugins-per-page' ); ?></p>
		<div class="dppp-inline">
			<input type="text" class="large-text code dppp-copy-field" readonly value="define( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE', true );" aria-label="<?php esc_attr_e( 'Line for wp-config.php', 'disable-plugins-per-page' ); ?>" data-dppp-copy-source>
			<button type="button" class="button" data-dppp-copy><?php echo self::icon( 'copy', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy', 'disable-plugins-per-page' ); ?></span></button>
		</div>
		<?php if ( $on ) : ?>
			<p class="dppp-callout is-warning"><?php echo self::icon( 'circle-pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Safe mode is on right now.', 'disable-plugins-per-page' ); ?></span></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * What's never filtered.
	 *
	 * @return void
	 */
	public function render_never() {
		$items = array(
			array( 'refresh-cw', __( 'Background requests: AJAX, the REST API, WP-Cron, WP-CLI and XML-RPC. Plugins need their handlers wherever they\'re used.', 'disable-plugins-per-page' ) ),
			array( 'send', __( 'Form posts and anything else that isn\'t a GET request, so submitting a form, a login or a checkout always works.', 'disable-plugins-per-page' ) ),
			array( 'puzzle', __( 'The Plugins, Updates and Site Health screens and this page, so you can always see and undo everything.', 'disable-plugins-per-page' ) ),
			array( 'eye', __( 'Previews, the Customizer and front-end page builders (Elementor, Divi, Beaver Builder, Bricks, Oxygen and others).', 'disable-plugins-per-page' ) ),
			array( 'log-in', __( 'The login, sign-up and activation screens.', 'disable-plugins-per-page' ) ),
			array( 'link-2', __( 'Add-ons whose parent plugin is off on a page (a WooCommerce extension without WooCommerce) are turned off with it.', 'disable-plugins-per-page' ) ),
			array( 'database', __( 'A request that turned plugins off can never save a shorter active plugins list, so nothing is deactivated for real.', 'disable-plugins-per-page' ) ),
		);
		?>
		<ul class="dppp-checklist">
			<?php foreach ( $items as $item ) : ?>
				<li class="is-ok"><?php echo self::icon( $item[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The must-use loader box.
	 *
	 * @return void
	 */
	public function render_loader() {
		$status = Disable_Plugins_Per_Page_Loader::status();
		$texts  = array(
			'installed' => array( 'is-ok', 'circle-check', __( 'Installed and running. It starts the rules before any other plugin loads.', 'disable-plugins-per-page' ) ),
			'outdated'  => array( 'is-ok', 'circle-check', __( 'Installed. It updates itself on your next admin page load.', 'disable-plugins-per-page' ) ),
			'missing'   => array( 'is-off', 'circle-x', __( 'Not installed, and the folder can\'t be written to. Copy the file below into wp-content/mu-plugins/.', 'disable-plugins-per-page' ) ),
			'blocked'   => array( 'is-off', 'circle-x', __( 'Not installed, because file changes are turned off on this site (DISALLOW_FILE_MODS). Copy the file below into wp-content/mu-plugins/.', 'disable-plugins-per-page' ) ),
			'foreign'   => array( 'is-off', 'circle-x', __( 'Another file has the loader\'s name, so it wasn\'t replaced. Rename it, then reload this page.', 'disable-plugins-per-page' ) ),
		);
		$text   = $texts[ $status ];
		?>
		<ul class="dppp-checklist">
			<li class="<?php echo esc_attr( $text[0] ); ?>"><?php echo self::icon( $text[1], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $text[2] ); ?></span></li>
		</ul>
		<p class="dppp-muted"><code><?php echo esc_html( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( Disable_Plugins_Per_Page_Loader::path() ) ) ); ?></code></p>
		<?php if ( 'missing' === $status || 'blocked' === $status ) : ?>
			<textarea class="large-text code" rows="10" readonly aria-label="<?php esc_attr_e( 'Loader file contents', 'disable-plugins-per-page' ); ?>"><?php echo esc_textarea( Disable_Plugins_Per_Page_Loader::contents() ); ?></textarea>
		<?php endif; ?>
		<?php
	}

	/**
	 * The options box.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public function render_options( array $settings ) {
		?>
		<div class="dppp-options">
			<label class="dppp-check">
				<input type="checkbox" name="<?php echo esc_attr( self::name( 'toolbar' ) ); ?>" value="1" <?php checked( 1, (int) $settings['toolbar'] ); ?>>
				<span><?php esc_html_e( 'Show which plugins are off on each page in the admin bar (administrators only)', 'disable-plugins-per-page' ); ?></span>
			</label>
			<label class="dppp-check">
				<input type="checkbox" name="<?php echo esc_attr( self::name( 'purge' ) ); ?>" value="1" <?php checked( 1, (int) $settings['purge'] ); ?>>
				<span><?php esc_html_e( 'Clear the page cache when the rules visitors get change (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Cache Enabler, Nginx Helper)', 'disable-plugins-per-page' ); ?></span>
			</label>
		</div>
		<?php
	}

	// Sidebar ---------------------------------------------------------------------

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @param array $settings Settings.
	 * @param array $state    State record.
	 * @return void
	 */
	private function render_sidebar( array $settings, array $state ) {
		$live  = 0;
		$test  = 0;
		$pages = 0;

		foreach ( (array) $settings['rules'] as $rule ) {
			if ( is_array( $rule ) && isset( $rule['status'] ) ) {
				$live += 'live' === $rule['status'] ? 1 : 0;
				$test += 'test' === $rule['status'] ? 1 : 0;
			}
		}

		$compiled = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );
		$pages    = is_array( $compiled ) ? count( isset( $compiled['items'] ) ? (array) $compiled['items'] : array() ) + count( isset( $compiled['ids'] ) ? (array) $compiled['ids'] : array() ) : 0;
		$loader   = in_array( Disable_Plugins_Per_Page_Loader::status(), array( 'installed', 'outdated' ), true );
		$paused   = ! empty( $state['paused'] );
		$safe     = defined( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE;
		$status   = array(
			array( $loader, $loader ? __( 'Loader installed', 'disable-plugins-per-page' ) : __( 'Loader not installed', 'disable-plugins-per-page' ) ),
			/* translators: %d: number of live rules. */
			array( $live > 0, sprintf( _n( '%d live rule', '%d live rules', $live, 'disable-plugins-per-page' ), $live ) ),
			/* translators: %d: number of rules in testing. */
			array( $test > 0, sprintf( _n( '%d rule in testing', '%d rules in testing', $test, 'disable-plugins-per-page' ), $test ) ),
			/* translators: %d: number of pages with their own settings. */
			array( $pages > 0, sprintf( _n( '%d page with its own settings', '%d pages with their own settings', $pages, 'disable-plugins-per-page' ), $pages ) ),
			array( ! empty( $settings['guard'] ), ! empty( $settings['guard'] ) ? __( 'Crash guard on', 'disable-plugins-per-page' ) : __( 'Crash guard off', 'disable-plugins-per-page' ) ),
			array( true, __( 'Recovery link ready', 'disable-plugins-per-page' ) ),
		);
		?>
		<div class="postbox dppp-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'disable-plugins-per-page' ); ?>
				</h2>
			</div>
			<div class="inside">
				<label class="dppp-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'rules_on' ) ); ?>" value="1" <?php checked( ! $paused ); ?>>
					<span><?php esc_html_e( 'Rules on', 'disable-plugins-per-page' ); ?></span>
				</label>
				<?php if ( $safe ) : ?>
					<p class="dppp-callout is-warning"><?php esc_html_e( 'Switched off by DISABLE_PLUGINS_PER_PAGE_SAFE_MODE in wp-config.php.', 'disable-plugins-per-page' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Untick to pause every rule at once. Settings on every tab are saved together.', 'disable-plugins-per-page' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'disable-plugins-per-page' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'disable-plugins-per-page' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="dppp-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( DISABLE_PLUGINS_PER_PAGE_REPO ) );
		$check_url  = Disable_Plugins_Per_Page_Updater::check_now_url();
		?>
		<div class="postbox dppp-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'disable-plugins-per-page' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'disable-plugins-per-page' ), '<strong>' . esc_html( DISABLE_PLUGINS_PER_PAGE_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Disable_Plugins_Per_Page_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'disable-plugins-per-page' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( DISABLE_PLUGINS_PER_PAGE_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Disable_Plugins_Per_Page_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'disable-plugins-per-page' ),
							'<a href="' . esc_url( DISABLE_PLUGINS_PER_PAGE_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>DISABLE_PLUGINS_PER_PAGE_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'disable-plugins-per-page' ),
							'<a href="' . esc_url( DISABLE_PLUGINS_PER_PAGE_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'disable-plugins-per-page' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
