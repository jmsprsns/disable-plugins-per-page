<?php
/**
 * The part that runs on every request, started by the must-use loader before
 * any regular plugin loads. It works out which plugins this request doesn't
 * need and takes them out of the list WordPress is about to load.
 *
 * On a normal page view it reads two autoloaded options (the compiled rules
 * and a small state record) and writes nothing. It only uses what WordPress
 * has ready at that point: options, the database, $pagenow and wp_is_mobile().
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Disable_Plugins_Per_Page_Filter', false ) ) :

	/**
	 * Per-request plugin filter.
	 */
	final class Disable_Plugins_Per_Page_Filter {

		/**
		 * Compiled rules, written by Disable_Plugins_Per_Page_Rules::compile(). Autoloaded.
		 */
		const COMPILED = 'disable_plugins_per_page_compiled';

		/**
		 * Pause flag, rules paused after a crash and the crash details. Autoloaded.
		 */
		const STATE = 'disable_plugins_per_page_state';

		/**
		 * Secret that signs the admin cookie.
		 */
		const SECRET = 'disable_plugins_per_page_secret';

		/**
		 * Secret part of the recovery link.
		 */
		const RECOVERY_KEY = 'disable_plugins_per_page_recovery_key';

		/**
		 * Cookie that marks an administrator's browser (rules in testing apply).
		 */
		const COOKIE_PREFIX = 'dppp_admin_';

		/**
		 * Query args.
		 */
		const MEASURE_ARG  = 'dppp_measure';
		const ALL_ARG      = 'dppp_all';
		const RECOVERY_ARG = 'dppp_recovery';

		/**
		 * The settings page slug, which is never filtered.
		 */
		const PAGE = 'disable-plugins-per-page';

		/**
		 * Longest request path that is filtered. Longer ones load every plugin.
		 */
		const MAX_PATH = 2048;

		/**
		 * Admin screens that always load every plugin: where plugins are
		 * managed, updated and saved, so nothing there ever sees a shortened list.
		 */
		const ADMIN_SAFE = array(
			'plugins.php',
			'plugin-install.php',
			'plugin-editor.php',
			'update.php',
			'update-core.php',
			'upgrade.php',
			'site-health.php',
			'options.php',
			'admin-post.php',
			'admin-ajax.php',
			'async-upload.php',
			'media-upload.php',
		);

		/**
		 * Query args of previews and front-end editors, which load every plugin.
		 */
		const EDITOR_ARGS = array(
			'preview',
			'customize_changeset_uuid',
			'customize_theme',
			'customize_messenger_channel',
			'wp_customize',
			'elementor-preview',
			'fl_builder',
			'et_fb',
			'bricks',
			'ct_builder',
			'vc_editable',
			'vc_action',
			'brizy-edit-iframe',
			'tve',
		);

		/**
		 * This plugin's basename.
		 *
		 * @var string
		 */
		private static $self = '';

		/**
		 * Whether the filter is hooked in.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * What was decided for this request, once worked out.
		 *
		 * @var array|null
		 */
		private static $decision = null;

		/**
		 * The list as it was handed to the filter, before anything was removed.
		 *
		 * @var array
		 */
		private static $original = array();

		/**
		 * Current measuring run: hash of its token and mode.
		 *
		 * @var array|null
		 */
		private static $measure = null;

		/**
		 * Whether a crash was already recorded for this request.
		 *
		 * @var bool
		 */
		private static $crash_recorded = false;

		/**
		 * Whether this request used the recovery link.
		 *
		 * @var bool
		 */
		private static $recovered = false;

		/*
		 * The request is read while plugins load, before wp_magic_quotes()
		 * runs, so $_GET, $_COOKIE and $_SERVER aren't slashed yet here.
		 */

		/**
		 * Hook in. Called by the must-use loader.
		 *
		 * @param string $basename This plugin's basename.
		 * @return void
		 */
		public static function boot( $basename ) {
			if ( self::$booted ) {
				return;
			}

			self::$booted = true;
			self::$self   = (string) $basename;

			// Late, so the list is already final when it's shortened.
			add_filter( 'option_active_plugins', array( __CLASS__, 'filter' ), 1000 );
			add_filter( 'pre_update_option_active_plugins', array( __CLASS__, 'guard_update' ), 1000 );
		}

		/**
		 * Whether the must-use loader started the filter on this request.
		 *
		 * @return bool
		 */
		public static function is_booted() {
			return self::$booted;
		}

		/**
		 * What was decided for this request.
		 *
		 * @return array|null Null before the active plugins were first read.
		 */
		public static function decision() {
			return self::$decision;
		}

		/**
		 * Whether this request paused the rules through the recovery link.
		 *
		 * @return bool
		 */
		public static function recovered() {
			return self::$recovered;
		}

		/**
		 * Filter for option_active_plugins: drop the plugins this request doesn't need.
		 *
		 * @param mixed $plugins Active plugin basenames.
		 * @return mixed
		 */
		public static function filter( $plugins ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}

			self::$original = $plugins;

			// A must-use plugin reading the list while they're still loading:
			// $pagenow and the device check aren't set up yet.
			if ( ! did_action( 'muplugins_loaded' ) ) {
				return $plugins;
			}

			if ( null === self::$decision ) {
				self::$decision = self::is_self_active( $plugins ) ? self::decide( $plugins ) : self::blank( 'inactive' );
			}

			if ( empty( self::$decision['removed'] ) ) {
				return $plugins;
			}

			return array_values( array_diff( $plugins, array_keys( self::$decision['removed'] ) ) );
		}

		/**
		 * Filter for pre_update_option_active_plugins: a request that turned
		 * plugins off may add to the saved list but never take anything out of it.
		 *
		 * Without this, a plugin that reads the list and saves it back (or an
		 * add-on that deactivates itself because its parent "isn't active")
		 * would make a per-page change permanent. Plugins are deactivated on
		 * the Plugins screen, over AJAX/REST and in WP-CLI, which are never
		 * filtered, so real deactivations are unaffected.
		 *
		 * @param mixed $value New value.
		 * @return mixed
		 */
		public static function guard_update( $value ) {
			if ( empty( self::$decision['removed'] ) || ! is_array( $value ) ) {
				return $value;
			}

			$stored = array_values( self::stored_active_plugins() );

			// Put each missing plugin back right after the plugin it followed,
			// so the load order stays as it was.
			foreach ( $stored as $index => $plugin ) {
				if ( in_array( $plugin, $value, true ) ) {
					continue;
				}

				$at = 0;

				for ( $i = $index - 1; $i >= 0; $i-- ) {
					$before = array_search( $stored[ $i ], $value, true );

					if ( false !== $before ) {
						$at = $before + 1;
						break;
					}
				}

				array_splice( $value, $at, 0, array( $plugin ) );
			}

			return array_values( $value );
		}

		/**
		 * The active plugins as saved, bypassing every filter.
		 *
		 * @return array
		 */
		private static function stored_active_plugins() {
			$all = wp_load_alloptions();

			if ( isset( $all['active_plugins'] ) ) {
				$stored = maybe_unserialize( $all['active_plugins'] );

				if ( is_array( $stored ) ) {
					return $stored;
				}
			}

			return self::$original;
		}

		/**
		 * Whether this plugin is active on this site.
		 *
		 * @param array $plugins Active plugins.
		 * @return bool
		 */
		private static function is_self_active( array $plugins ) {
			if ( in_array( self::$self, $plugins, true ) ) {
				return true;
			}

			if ( is_multisite() ) {
				$network = get_site_option( 'active_sitewide_plugins' );

				return is_array( $network ) && isset( $network[ self::$self ] );
			}

			return false;
		}

		/**
		 * An empty decision.
		 *
		 * @param string $status Why nothing is filtered.
		 * @param string $why    Detail.
		 * @return array
		 */
		private static function blank( $status, $why = '' ) {
			return array(
				'status'  => $status,
				'why'     => $why,
				'area'    => is_admin() ? 'admin' : 'front',
				'where'   => '',
				'admin'   => false,
				'testing' => false,
				'removed' => array(),
				'loaded'  => count( self::$original ),
			);
		}

		/**
		 * Work out which plugins to take out for this request.
		 *
		 * @param array $plugins Active plugins.
		 * @return array Decision.
		 */
		private static function decide( array $plugins ) {
			if ( defined( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE ) {
				return self::blank( 'safe-mode' );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing; the recovery key and measuring token are secrets checked below.
			if ( isset( $_GET[ self::RECOVERY_ARG ] ) && self::recover( wp_unslash( $_GET[ self::RECOVERY_ARG ] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with hash_equals().
				return self::blank( 'paused', 'recovered' );
			}

			$skip = self::skip_reason();

			if ( '' !== $skip ) {
				return self::blank( 'skipped', $skip );
			}

			$compiled = get_option( self::COMPILED );

			if ( ! is_array( $compiled ) || empty( $compiled['v'] ) ) {
				return self::blank( 'skipped', 'no-rules' );
			}

			$state = self::state();

			if ( ! empty( $state['paused'] ) ) {
				return self::blank( 'paused' );
			}

			$area     = is_admin() ? 'admin' : 'front';
			$is_admin = self::admin_cookie_user() > 0;
			$measure  = self::measure_mode();

			if ( 'all' === $measure ) {
				return self::blank( 'measure-all' );
			}

			if ( $is_admin && isset( $_GET[ self::ALL_ARG ] ) ) {
				$decision          = self::blank( 'bypass' );
				$decision['admin'] = true;

				return $decision;
			}
			// phpcs:enable

			$ctx = self::request_context( $area );

			if ( null === $ctx ) {
				return self::blank( 'skipped', 'long-url' );
			}

			// Rules still in testing apply to administrators and to "with
			// rules" measuring, never to visitors.
			$testing = $is_admin || 'rules' === $measure;
			$removed = self::evaluate( $compiled, $area, $ctx, $testing, $state, $plugins );

			$decision = array(
				'status'  => 'filtered',
				'why'     => '',
				'area'    => $area,
				'where'   => 'admin' === $area ? $ctx['screen'] : $ctx['path'],
				'admin'   => $is_admin,
				'testing' => $testing,
				'removed' => $removed,
				'loaded'  => count( $plugins ) - count( $removed ),
			);

			if ( $removed && ! empty( $compiled['guard'] ) ) {
				register_shutdown_function( array( __CLASS__, 'check_crash' ) );
			}

			return $decision;
		}

		/**
		 * Why this request loads every plugin, if it does.
		 *
		 * Background requests are never filtered: their URL says nothing about
		 * the page that made them, and plugins need their own AJAX, REST,
		 * cron and form handlers wherever they're used.
		 *
		 * @return string Empty when the request can be filtered.
		 */
		private static function skip_reason() {
			global $pagenow;

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return 'cli';
			}

			if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
				return 'installing';
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only checks.
			if ( wp_doing_cron() || isset( $_GET['doing_wp_cron'] ) ) {
				return 'cron';
			}

			if ( wp_doing_ajax() ) {
				return 'ajax';
			}

			if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['rest_route'] ) || self::is_rest_path() ) {
				return 'api';
			}

			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared only; not slashed yet.

			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return 'post';
			}

			foreach ( self::EDITOR_ARGS as $arg ) {
				if ( isset( $_GET[ $arg ] ) ) {
					return 'editor';
				}
			}

			if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) {
				return 'recovery-mode';
			}

			if ( is_admin() ) {
				if ( is_network_admin() || is_user_admin() ) {
					return 'network-admin';
				}

				if ( in_array( (string) $pagenow, self::ADMIN_SAFE, true ) ) {
					return 'plugin-screens';
				}

				if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
					return 'own-page';
				}
			} elseif ( 'index.php' !== $pagenow ) {
				// wp-login.php, wp-signup.php, wp-activate.php, wp-trackback.php...
				return 'not-a-page';
			}
			// phpcs:enable

			return '';
		}

		/**
		 * Whether the request is for the REST API (REST_REQUEST isn't set yet).
		 *
		 * @return bool
		 */
		private static function is_rest_path() {
			$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
			$path   = self::request_path();

			return 0 === strpos( $path, '/' . strtolower( trim( $prefix, '/' ) ) . '/' );
		}

		/**
		 * The request path relative to the site's home, normalised.
		 *
		 * @return string
		 */
		public static function request_path() {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only compared, never output; not slashed yet.
			$path = self::normalize_path( $uri );
			$home = self::normalize_path( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );

			if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
				$path = '/' . substr( $path, strlen( $home ) );
			}

			return $path;
		}

		/**
		 * Normalise a path for comparing: no query or fragment, decoded,
		 * lower case, single slashes, a leading and a trailing slash.
		 *
		 * @param string $path Path or URL path.
		 * @return string
		 */
		public static function normalize_path( $path ) {
			$path = preg_replace( '/[?#].*$/s', '', (string) $path );
			$path = strtolower( rawurldecode( $path ) );
			$path = preg_replace( '#/+#', '/', '/' . $path );
			$path = rtrim( $path, '/' );

			return '' === $path ? '/' : $path . '/';
		}

		/**
		 * What the rules are matched against on this request.
		 *
		 * @param string $area 'front' or 'admin'.
		 * @return array|null Null when the URL is too long to bother with.
		 */
		private static function request_context( $area ) {
			global $pagenow;

			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only compared; not slashed yet.

			if ( strlen( $uri ) > self::MAX_PATH ) {
				return null;
			}

			if ( 'admin' === $area ) {
				$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only compared; not slashed yet.

				return array(
					'screen' => self::screen_string( (string) $pagenow, $query ),
				);
			}

			return array(
				'path'   => self::request_path(),
				'mobile' => wp_is_mobile(),
				'query'  => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
			);
		}

		/**
		 * An admin screen as rules see it: "edit.php?post_type=product".
		 *
		 * @param string $file  Admin file.
		 * @param string $query Query string.
		 * @return string
		 */
		public static function screen_string( $file, $query = '' ) {
			$file  = strtolower( '' === $file ? 'index.php' : $file );
			$query = strtolower( rawurldecode( $query ) );

			return '' === $query ? $file : $file . '?' . $query;
		}

		/**
		 * Which plugins the rules take out in a given context.
		 *
		 * Used for the live request and to preview a post in the editor.
		 *
		 * @param array  $compiled Compiled rules.
		 * @param string $area     'front' or 'admin'.
		 * @param array  $ctx      Front: 'path', 'mobile' (bool, or null for any
		 *                         device) and 'query', or a resolved 'page'.
		 *                         Admin: 'screen'.
		 * @param bool   $testing  Include rules that are still in testing.
		 * @param array  $state    Paused rules and pages.
		 * @param array  $active   Active plugin basenames.
		 * @return array<string,array> Basename => why it's off.
		 */
		public static function evaluate( array $compiled, $area, array $ctx, $testing, array $state, array $active ) {
			$rules         = isset( $compiled[ $area ] ) && is_array( $compiled[ $area ] ) ? $compiled[ $area ] : array();
			$paused_rules  = isset( $state['paused_rules'] ) ? (array) $state['paused_rules'] : array();
			$paused_posts  = isset( $state['paused_posts'] ) ? array_map( 'intval', (array) $state['paused_posts'] ) : array();
			$active_lookup = array_flip( $active );
			$removed       = array();
			$page          = isset( $ctx['page'] ) ? $ctx['page'] : null;
			$maps          = isset( $compiled['maps'] ) ? (array) $compiled['maps'] : array();

			// The page's post type and kind are only looked up if a rule asks.
			$resolve = function () use ( &$page, $ctx, $maps ) {
				if ( null === $page ) {
					require_once __DIR__ . '/class-disable-plugins-per-page-resolver.php';

					$page = Disable_Plugins_Per_Page_Resolver::resolve(
						isset( $ctx['path'] ) ? $ctx['path'] : '/',
						isset( $ctx['query'] ) && is_array( $ctx['query'] ) ? $ctx['query'] : array(),
						$maps
					);
				}

				return $page;
			};

			foreach ( $rules as $rule ) {
				if ( empty( $rule['plugins'] ) || in_array( $rule['id'], $paused_rules, true ) || ( ! empty( $rule['test'] ) && ! $testing ) ) {
					continue;
				}

				$matches = 'admin' === $area ? self::matches_admin( $rule, $ctx ) : self::matches_front( $rule, $ctx, $resolve );
				$off     = empty( $rule['only'] ) ? $matches : ! $matches;

				if ( ! $off ) {
					continue;
				}

				foreach ( (array) $rule['plugins'] as $plugin ) {
					if ( isset( $active_lookup[ $plugin ] ) && self::$self !== $plugin && ! isset( $removed[ $plugin ] ) ) {
						$removed[ $plugin ] = array(
							'type' => 'rule',
							'id'   => (string) $rule['id'],
							'test' => ! empty( $rule['test'] ),
						);
					}
				}
			}

			// This page's own settings from the editor beat the rules. They're
			// keyed by path, or by post ID on plain permalinks.
			if ( 'front' === $area && ( ! empty( $compiled['items'] ) || ! empty( $compiled['ids'] ) ) ) {
				$item = ! empty( $compiled['items'] ) && isset( $ctx['path'] ) ? self::find_item( (array) $compiled['items'], $ctx['path'] ) : null;

				if ( ! $item && ! empty( $compiled['ids'] ) ) {
					$found = call_user_func( $resolve );
					$item  = $found['id'] && isset( $compiled['ids'][ $found['id'] ] ) ? $compiled['ids'][ $found['id'] ] : null;
				}

				if ( $item && ! in_array( (int) $item['id'], $paused_posts, true ) && ( empty( $item['test'] ) || $testing ) ) {
					foreach ( isset( $item['on'] ) ? (array) $item['on'] : array() as $plugin ) {
						unset( $removed[ $plugin ] );
					}

					foreach ( isset( $item['off'] ) ? (array) $item['off'] : array() as $plugin ) {
						if ( isset( $active_lookup[ $plugin ] ) && self::$self !== $plugin ) {
							$removed[ $plugin ] = array(
								'type' => 'page',
								'id'   => (int) $item['id'],
								'test' => ! empty( $item['test'] ),
							);
						}
					}
				}
			}

			return self::add_dependents( $removed, $active, isset( $compiled['deps'] ) ? (array) $compiled['deps'] : array() );
		}

		/**
		 * Also take out add-ons whose parent plugin is out (a WooCommerce
		 * extension without WooCommerce), so nothing calls a missing plugin.
		 *
		 * @param array $removed Plugins out so far.
		 * @param array $active  Active plugins.
		 * @param array $deps    Add-on basename => parent basenames.
		 * @return array
		 */
		private static function add_dependents( array $removed, array $active, array $deps ) {
			if ( ! $removed || ! $deps ) {
				return $removed;
			}

			do {
				$changed = false;

				foreach ( $active as $plugin ) {
					if ( isset( $removed[ $plugin ] ) || self::$self === $plugin || empty( $deps[ $plugin ] ) ) {
						continue;
					}

					foreach ( (array) $deps[ $plugin ] as $parent ) {
						if ( isset( $removed[ $parent ] ) ) {
							$removed[ $plugin ] = array(
								'type' => 'dep',
								'id'   => (string) $parent,
								'test' => ! empty( $removed[ $parent ]['test'] ),
							);
							$changed            = true;
							break;
						}
					}
				}
			} while ( $changed );

			return $removed;
		}

		/**
		 * Whether a front-end rule's "where" matches.
		 *
		 * @param array    $rule    Compiled rule.
		 * @param array    $ctx     Request context.
		 * @param callable $resolve Returns the page context.
		 * @return bool
		 */
		private static function matches_front( array $rule, array $ctx, $resolve ) {
			if ( ! empty( $rule['device'] ) && isset( $ctx['mobile'] ) && null !== $ctx['mobile'] ) {
				if ( ( 'mobile' === $rule['device'] ) !== (bool) $ctx['mobile'] ) {
					return false;
				}
			}

			if ( ! empty( $rule['all'] ) ) {
				return true;
			}

			$path = isset( $ctx['path'] ) ? (string) $ctx['path'] : '/';

			if ( ! empty( $rule['paths'] ) && self::find_item( (array) $rule['paths'], $path ) ) {
				return true;
			}

			if ( ! empty( $rule['patterns'] ) ) {
				foreach ( (array) $rule['patterns'] as $pattern ) {
					if ( self::wildcard( (string) $pattern, $path ) ) {
						return true;
					}
				}
			}

			if ( ! empty( $rule['types'] ) || ! empty( $rule['post_types'] ) || ! empty( $rule['ids'] ) ) {
				$page = call_user_func( $resolve );

				if ( ! empty( $rule['ids'] ) && $page['id'] && in_array( (int) $page['id'], array_map( 'intval', (array) $rule['ids'] ), true ) ) {
					return true;
				}

				if ( ! empty( $rule['types'] ) && array_intersect( (array) $rule['types'], (array) $page['types'] ) ) {
					return true;
				}

				if ( ! empty( $rule['post_types'] ) && '' !== $page['post_type'] && in_array( 'singular', (array) $page['types'], true ) && in_array( $page['post_type'], (array) $rule['post_types'], true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether an admin rule's screens match.
		 *
		 * @param array $rule Compiled rule.
		 * @param array $ctx  Request context.
		 * @return bool
		 */
		private static function matches_admin( array $rule, array $ctx ) {
			if ( ! empty( $rule['all'] ) ) {
				return true;
			}

			$screen = isset( $ctx['screen'] ) ? (string) $ctx['screen'] : '';

			// Core screens picked from the list: the file itself, never a
			// plugin's own page hanging off it (options-general.php?page=...).
			if ( ! empty( $rule['files'] ) ) {
				$parts = explode( '?', $screen, 2 );

				if ( in_array( $parts[0], (array) $rule['files'], true ) && ( ! isset( $parts[1] ) || ! preg_match( '/(^|&)page=/', $parts[1] ) ) ) {
					return true;
				}
			}

			foreach ( isset( $rule['screens'] ) ? (array) $rule['screens'] : array() as $pattern ) {
				if ( self::wildcard( (string) $pattern, $screen ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Look a path up in a path-keyed map, allowing for paged copies of the
		 * same page (/page/2/, /comment-page-2/, /2/ for split posts).
		 *
		 * @param array  $map  Normalised path => value.
		 * @param string $path Normalised request path.
		 * @return mixed|null
		 */
		private static function find_item( array $map, $path ) {
			if ( isset( $map[ $path ] ) ) {
				return $map[ $path ];
			}

			if ( preg_match( '#^(.*/)(?:page/\d+|comment-page-\d+)/$#', $path, $m ) && isset( $map[ $m[1] ] ) ) {
				return $map[ $m[1] ];
			}

			// A split post's /2/, but never the front page (that's a date archive).
			if ( preg_match( '#^(/.+/)\d+/$#', $path, $m ) && isset( $map[ $m[1] ] ) ) {
				return $map[ $m[1] ];
			}

			return null;
		}

		/**
		 * Match a "*" wildcard pattern against a whole string, without regular
		 * expressions, in linear time: the fixed text before the first * and
		 * after the last * must match the ends, and each piece in between is
		 * found at its leftmost place after the previous one (which is always
		 * right when * is the only wildcard).
		 *
		 * @param string $pattern Pattern; "*" matches anything, including nothing.
		 * @param string $subject String.
		 * @return bool
		 */
		public static function wildcard( $pattern, $subject ) {
			$pattern = (string) $pattern;
			$subject = (string) $subject;

			if ( false === strpos( $pattern, '*' ) ) {
				return $pattern === $subject;
			}

			$pieces = explode( '*', $pattern );
			$prefix = array_shift( $pieces );
			$suffix = array_pop( $pieces );
			$pos    = strlen( $prefix );
			$end    = strlen( $subject ) - strlen( $suffix );

			if ( $end < $pos || 0 !== strncmp( $subject, $prefix, $pos ) || ( '' !== $suffix && substr( $subject, $end ) !== $suffix ) ) {
				return false;
			}

			foreach ( $pieces as $piece ) {
				if ( '' === $piece ) {
					continue;
				}

				$found = strpos( $subject, $piece, $pos );

				if ( false === $found || $found + strlen( $piece ) > $end ) {
					return false;
				}

				$pos = $found + strlen( $piece );
			}

			return true;
		}

		/**
		 * The state record: pause flag, paused rules and the last crash.
		 *
		 * @return array
		 */
		public static function state() {
			$state = get_option( self::STATE );

			return is_array( $state ) ? $state : array();
		}

		/**
		 * The recovery link: pause every rule, site-wide, until they're turned
		 * back on in the settings.
		 *
		 * @param mixed $key Key from the URL.
		 * @return bool Whether the key was right.
		 */
		private static function recover( $key ) {
			if ( ! is_string( $key ) || strlen( $key ) < 20 || strlen( $key ) > 128 ) {
				return false;
			}

			$stored = get_option( self::RECOVERY_KEY );

			if ( ! is_string( $stored ) || strlen( $stored ) < 20 || ! hash_equals( $stored, $key ) ) {
				return false;
			}

			$state = self::state();

			if ( empty( $state['paused'] ) ) {
				$state['paused']    = time();
				$state['paused_by'] = 'link';
				update_option( self::STATE, $state, true );
			}

			self::$recovered = true;

			return true;
		}

		/**
		 * Cookie name for the admin cookie.
		 *
		 * @return string
		 */
		public static function cookie_name() {
			return self::COOKIE_PREFIX . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( (string) get_option( 'siteurl' ) ) );
		}

		/**
		 * Signed admin cookie value: "user|expiry|signature".
		 *
		 * @param int $user_id User ID.
		 * @param int $expires Unix time it stops working.
		 * @return string Empty when there's no secret.
		 */
		public static function cookie_value( $user_id, $expires ) {
			$secret = get_option( self::SECRET );

			if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
				return '';
			}

			$data = absint( $user_id ) . '|' . absint( $expires );

			return $data . '|' . hash_hmac( 'sha256', $data, $secret );
		}

		/**
		 * The user ID in a valid admin cookie on this request.
		 *
		 * @return int 0 when there's no valid cookie.
		 */
		public static function admin_cookie_user() {
			$name = self::cookie_name();

			if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
				return 0;
			}

			$parts = explode( '|', (string) $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Checked against an HMAC; digits, | and hex only.

			if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < time() ) {
				return 0;
			}

			$expected = self::cookie_value( (int) $parts[0], (int) $parts[1] );

			return '' !== $expected && hash_equals( $expected, $parts[0] . '|' . $parts[1] . '|' . $parts[2] ) ? (int) $parts[0] : 0;
		}

		/**
		 * A before-and-after measuring request: check its single-use token.
		 *
		 * @return string|null 'all', 'rules' or null.
		 */
		private static function measure_mode() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- A single-use token checked against its stored hash.
			if ( empty( $_GET[ self::MEASURE_ARG ] ) || ! is_string( $_GET[ self::MEASURE_ARG ] ) || ! preg_match( '/^[A-Za-z0-9]{32}$/', $_GET[ self::MEASURE_ARG ] ) ) {
				return null;
			}

			$hash = hash( 'sha256', (string) $_GET[ self::MEASURE_ARG ] );
			// phpcs:enable
			$data = get_transient( 'dppp_mt_' . $hash );

			if ( ! is_array( $data ) || ! isset( $data['mode'] ) || ! in_array( $data['mode'], array( 'all', 'rules' ), true ) ) {
				return null;
			}

			delete_transient( 'dppp_mt_' . $hash );

			self::$measure = array(
				'hash' => $hash,
				'mode' => $data['mode'],
			);

			// Page caches shouldn't keep a copy of a measuring request.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			add_action( 'shutdown', array( __CLASS__, 'record_measure' ), PHP_INT_MAX );

			return $data['mode'];
		}

		/**
		 * Save a measuring request's numbers for the admin who started it.
		 *
		 * @return void
		 */
		public static function record_measure() {
			global $wpdb;

			if ( null === self::$measure ) {
				return;
			}

			$start   = isset( $GLOBALS['timestart'] ) ? (float) $GLOBALS['timestart'] : ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );
			$removed = isset( self::$decision['removed'] ) ? array_keys( self::$decision['removed'] ) : array();

			set_transient(
				'dppp_mr_' . self::$measure['hash'],
				array(
					'ms'      => round( ( microtime( true ) - $start ) * 1000, 1 ),
					'queries' => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0,
					'memory'  => memory_get_peak_usage(),
					'plugins' => max( 0, count( self::$original ) - count( $removed ) ),
					'off'     => $removed,
				),
				300
			);

			self::$measure = null;
		}

		/**
		 * Crash guard: when a page dies with a fatal error while plugins were
		 * turned off, pause the rules and page settings that turned them off,
		 * so the next visitor gets a working page. The admin sees what
		 * happened and can turn them back on.
		 *
		 * @return void
		 */
		public static function check_crash() {
			$error = error_get_last();

			if ( ! is_array( $error ) || ! isset( $error['type'] ) || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				return;
			}

			self::record_crash( $error );
		}

		/**
		 * Record a crash and pause what caused it.
		 *
		 * @param array $error From error_get_last().
		 * @return void
		 */
		private static function record_crash( array $error ) {
			if ( self::$crash_recorded || empty( self::$decision['removed'] ) ) {
				return;
			}

			self::$crash_recorded = true;
			$message              = isset( $error['message'] ) ? (string) $error['message'] : '';

			// Running out of memory or time isn't caused by having fewer plugins.
			if ( false !== stripos( $message, 'Allowed memory size' ) || false !== stripos( $message, 'Maximum execution time' ) ) {
				return;
			}

			$state = self::state();
			$rules = isset( $state['paused_rules'] ) ? (array) $state['paused_rules'] : array();
			$posts = isset( $state['paused_posts'] ) ? array_map( 'intval', (array) $state['paused_posts'] ) : array();
			$new_r = array();
			$new_p = array();

			foreach ( self::$decision['removed'] as $why ) {
				if ( 'rule' === $why['type'] && ! in_array( $why['id'], $rules, true ) ) {
					$new_r[] = (string) $why['id'];
				} elseif ( 'page' === $why['type'] && ! in_array( (int) $why['id'], $posts, true ) ) {
					$new_p[] = (int) $why['id'];
				}
			}

			$new_r = array_values( array_unique( $new_r ) );
			$new_p = array_values( array_unique( $new_p ) );

			// Already paused: nothing to write.
			if ( ! $new_r && ! $new_p ) {
				return;
			}

			// The first line says what went wrong; the stack trace isn't kept.
			$message = preg_replace( '/\s*Stack trace:.*$/s', '', $message );
			$strip   = function ( $text ) {
				return substr( str_replace( array( ABSPATH, wp_normalize_path( ABSPATH ) ), '', (string) $text ), 0, 600 );
			};

			$state['paused_rules'] = array_values( array_merge( $rules, $new_r ) );
			$state['paused_posts'] = array_values( array_merge( $posts, $new_p ) );
			$state['crash']        = array(
				'time'    => time(),
				'area'    => (string) self::$decision['area'],
				'where'   => substr( (string) self::$decision['where'], 0, 300 ),
				'message' => $strip( $message ),
				'file'    => $strip( isset( $error['file'] ) ? $error['file'] : '' ),
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
				'plugins' => array_keys( self::$decision['removed'] ),
				'rules'   => $new_r,
				'posts'   => $new_p,
			);

			update_option( self::STATE, $state, true );
		}
	}

endif;
