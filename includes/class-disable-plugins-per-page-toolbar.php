<?php
/**
 * The admin cookie and the admin bar menu.
 *
 * Rules still in testing apply only to browsers with a signed cookie, set
 * for users who can manage plugins. It's checked before plugins load, when
 * WordPress can't yet tell who's signed in. The admin bar menu shows which
 * plugins are off on the page you're looking at, and why.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin cookie and admin bar menu.
 */
final class Disable_Plugins_Per_Page_Toolbar {

	/**
	 * How long the cookie lasts. It's renewed on admin visits.
	 */
	const LIFETIME = 14 * DAY_IN_SECONDS;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_set_cookie' ) );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'on_login' ), 10, 4 );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'clear_cookie' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'menu' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'style' ) );
	}

	/**
	 * Give an admin's browser the cookie, or renew it when it's old.
	 *
	 * @return void
	 */
	public static function maybe_set_cookie() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) || headers_sent() ) {
			return;
		}

		$user = Disable_Plugins_Per_Page_Filter::admin_cookie_user();
		$name = Disable_Plugins_Per_Page_Filter::cookie_name();

		if ( get_current_user_id() === $user && isset( $_COOKIE[ $name ] ) ) {
			$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );

			if ( (int) $parts[1] > time() + self::LIFETIME / 2 ) {
				return;
			}
		}

		self::set_cookie( get_current_user_id() );
	}

	/**
	 * Set the cookie when someone who can manage plugins logs in.
	 *
	 * @param string $cookie  Logged-in cookie value.
	 * @param int    $expire  When it expires.
	 * @param int    $expiration Expiry in the cookie.
	 * @param int    $user_id User ID.
	 * @return void
	 */
	public static function on_login( $cookie, $expire, $expiration, $user_id ) {
		if ( user_can( (int) $user_id, Disable_Plugins_Per_Page_Plugin::CAP ) && ! headers_sent() ) {
			self::set_cookie( (int) $user_id );
		}
	}

	/**
	 * Write the cookie for the site's front end and admin paths.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function set_cookie( $user_id ) {
		$expires = time() + self::LIFETIME;
		$value   = Disable_Plugins_Per_Page_Filter::cookie_value( $user_id, $expires );

		if ( '' === $value ) {
			return;
		}

		foreach ( self::paths() as $path ) {
			self::send( $value, $expires, $path );
		}

		$_COOKIE[ Disable_Plugins_Per_Page_Filter::cookie_name() ] = $value;
	}

	/**
	 * Remove the cookie on logout.
	 *
	 * @return void
	 */
	public static function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}

		foreach ( self::paths() as $path ) {
			self::send( '', time() - YEAR_IN_SECONDS, $path );
		}
	}

	/**
	 * Cookie paths: the front end's and, if different, the admin's.
	 *
	 * @return array
	 */
	private static function paths() {
		$front = COOKIEPATH ? COOKIEPATH : '/';
		$site  = SITECOOKIEPATH ? SITECOOKIEPATH : '/';

		return array_unique( array( $front, $site ) );
	}

	/**
	 * Send one cookie header (HttpOnly, SameSite=Lax where PHP supports it).
	 *
	 * @param string $value   Value.
	 * @param int    $expires Expiry.
	 * @param string $path    Path.
	 * @return void
	 */
	private static function send( $value, $expires, $path ) {
		$name = Disable_Plugins_Per_Page_Filter::cookie_name();

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
				$name,
				$value,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( $name, $value, $expires, $path, COOKIE_DOMAIN, is_ssl(), true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		}
	}

	/**
	 * The admin bar menu: how many plugins are off here, which, and why.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function menu( $bar ) {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) || ! Disable_Plugins_Per_Page_Filter::is_booted() ) {
			return;
		}

		$settings = Disable_Plugins_Per_Page_Plugin::settings();

		if ( empty( $settings['toolbar'] ) ) {
			return;
		}

		$decision = Disable_Plugins_Per_Page_Filter::decision();

		if ( ! is_array( $decision ) ) {
			return;
		}

		$removed = (array) $decision['removed'];
		$testing = count( array_filter( wp_list_pluck( $removed, 'test' ) ) );
		$here    = self::current_url();

		if ( 'filtered' === $decision['status'] ) {
			/* translators: %d: number of plugins turned off on this page. */
			$label = $removed ? sprintf( _n( '%d plugin off', '%d plugins off', count( $removed ), 'disable-plugins-per-page' ), count( $removed ) ) : __( 'All plugins on', 'disable-plugins-per-page' );
		} elseif ( 'bypass' === $decision['status'] ) {
			$label = __( 'Every plugin loaded', 'disable-plugins-per-page' );
		} elseif ( 'paused' === $decision['status'] || 'safe-mode' === $decision['status'] ) {
			$label = __( 'Rules paused', 'disable-plugins-per-page' );
		} else {
			$label = __( 'All plugins on', 'disable-plugins-per-page' );
		}

		$bar->add_node(
			array(
				'id'    => 'dppp',
				'title' => Disable_Plugins_Per_Page_Icons::get( 'plug-zap', 16, 'dppp-ab-icon' ) . '<span class="dppp-ab-label">' . esc_html( $label ) . '</span>' . ( $testing ? '<span class="dppp-ab-test">' . esc_html__( 'testing', 'disable-plugins-per-page' ) . '</span>' : '' ),
				'href'  => Disable_Plugins_Per_Page_Admin::page_url(),
				'meta'  => array( 'class' => $removed ? 'dppp-ab has-off' : 'dppp-ab' ),
			)
		);

		$installed = Disable_Plugins_Per_Page_Rules::installed();
		$titles    = array();

		foreach ( (array) $settings['rules'] as $rule ) {
			if ( is_array( $rule ) && isset( $rule['id'] ) ) {
				$titles[ $rule['id'] ] = Disable_Plugins_Per_Page_Rules::title( $rule, $installed );
			}
		}

		foreach ( $removed as $file => $why ) {
			$bar->add_node(
				array(
					'parent' => 'dppp',
					'id'     => 'dppp-off-' . md5( $file ),
					'title'  => esc_html( Disable_Plugins_Per_Page_Rules::name( $file, $installed ) ) . '<span class="dppp-ab-why">' . esc_html( self::reason( $why, $titles, $installed ) ) . '</span>',
				)
			);
		}

		if ( 'skipped' === $decision['status'] && '' !== $decision['why'] ) {
			$bar->add_node(
				array(
					'parent' => 'dppp',
					'id'     => 'dppp-skipped',
					'title'  => '<span class="dppp-ab-why">' . esc_html( self::skip_text( $decision['why'] ) ) . '</span>',
				)
			);
		}

		if ( 'filtered' === $decision['status'] && $removed ) {
			$bar->add_node(
				array(
					'parent' => 'dppp',
					'id'     => 'dppp-all',
					'title'  => esc_html__( 'Load this page with every plugin', 'disable-plugins-per-page' ),
					'href'   => add_query_arg( Disable_Plugins_Per_Page_Filter::ALL_ARG, 1, $here ),
				)
			);
		} elseif ( 'bypass' === $decision['status'] ) {
			$bar->add_node(
				array(
					'parent' => 'dppp',
					'id'     => 'dppp-rules',
					'title'  => esc_html__( 'Load this page with your rules', 'disable-plugins-per-page' ),
					'href'   => remove_query_arg( Disable_Plugins_Per_Page_Filter::ALL_ARG, $here ),
				)
			);
		}

		if ( ! is_admin() && is_singular() && current_user_can( 'edit_post', get_queried_object_id() ) ) {
			$bar->add_node(
				array(
					'parent' => 'dppp',
					'id'     => 'dppp-edit-page',
					'title'  => esc_html__( 'Choose plugins for this page', 'disable-plugins-per-page' ),
					'href'   => get_edit_post_link( get_queried_object_id(), 'raw' ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'dppp',
				'id'     => 'dppp-settings',
				'title'  => esc_html__( 'Edit rules', 'disable-plugins-per-page' ),
				'href'   => Disable_Plugins_Per_Page_Admin::page_url(),
			)
		);
	}

	/**
	 * Why a plugin is off, in words.
	 *
	 * @param array $why       Reason from the filter.
	 * @param array $titles    Rule ID => title.
	 * @param array $installed From installed().
	 * @return string
	 */
	public static function reason( array $why, array $titles, array $installed ) {
		if ( 'dep' === $why['type'] ) {
			/* translators: %s: name of the plugin it needs. */
			$text = sprintf( __( 'needs %s', 'disable-plugins-per-page' ), Disable_Plugins_Per_Page_Rules::name( (string) $why['id'], $installed ) );
		} elseif ( 'page' === $why['type'] ) {
			$text = __( 'this page\'s settings', 'disable-plugins-per-page' );
		} else {
			$text = isset( $titles[ $why['id'] ] ) ? $titles[ $why['id'] ] : __( 'a rule', 'disable-plugins-per-page' );
		}

		return ! empty( $why['test'] ) ? $text . ' · ' . __( 'testing', 'disable-plugins-per-page' ) : $text;
	}

	/**
	 * Why a request loads every plugin, in words.
	 *
	 * @param string $why Reason code from the filter.
	 * @return string
	 */
	public static function skip_text( $why ) {
		$texts = array(
			'plugin-screens' => __( 'Plugin and update screens always load every plugin.', 'disable-plugins-per-page' ),
			'own-page'       => __( 'This settings page always loads every plugin.', 'disable-plugins-per-page' ),
			'editor'         => __( 'Previews and page builders always load every plugin.', 'disable-plugins-per-page' ),
			'network-admin'  => __( 'The network admin always loads every plugin.', 'disable-plugins-per-page' ),
			'no-rules'       => __( 'No rules yet.', 'disable-plugins-per-page' ),
			'long-url'       => __( 'Very long URLs always load every plugin.', 'disable-plugins-per-page' ),
			'recovery-mode'  => __( 'WordPress recovery mode loads every plugin.', 'disable-plugins-per-page' ),
		);

		return isset( $texts[ $why ] ) ? $texts[ $why ] : __( 'This request loads every plugin.', 'disable-plugins-per-page' );
	}

	/**
	 * The URL being viewed.
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw().
		$uri = preg_replace( '#^https?://[^/]+#i', '', (string) $uri );

		return set_url_scheme( 'http://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) . '/' . ltrim( $uri, '/' ) );
	}

	/**
	 * A few lines of CSS for the admin bar item.
	 *
	 * @return void
	 */
	public static function style() {
		if ( ! is_admin_bar_showing() || ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar .dppp-ab>.ab-item{display:flex!important;align-items:center;gap:6px}'
			. '#wpadminbar .dppp-ab-icon{flex:none;opacity:.75}'
			. '#wpadminbar .dppp-ab.has-off .dppp-ab-icon{opacity:1;color:#72aee6}'
			. '#wpadminbar .dppp-ab-test{padding:0 6px;border-radius:9px;background:#dba617;color:#1d2327;font-size:11px;line-height:18px}'
			. '#wpadminbar .dppp-ab-why{margin-left:8px;opacity:.7}'
			. '#wpadminbar #wp-admin-bar-dppp-skipped .dppp-ab-why{margin-left:0}'
			. '@media screen and (max-width:782px){#wpadminbar #wp-admin-bar-dppp{display:block}#wpadminbar .dppp-ab-label,#wpadminbar .dppp-ab-test{display:none}#wpadminbar .dppp-ab>.ab-item{padding:0 10px;height:46px}#wpadminbar .dppp-ab-icon{width:24px;height:24px}}'
		);
	}
}
