<?php
/**
 * Rules: cleaning what the settings page sends, compiling it into the small
 * list the filter reads on every request, spotting add-ons that need their
 * parent plugin, and the starter rules.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rule storage and compiling.
 */
final class Disable_Plugins_Per_Page_Rules {

	/**
	 * Post meta holding a post's own plugin settings from the editor.
	 */
	const META = '_disable_plugins_per_page';

	/**
	 * Notes from the last compile (pages found per rule, warnings). Not autoloaded.
	 */
	const INFO = 'disable_plugins_per_page_info';

	/**
	 * Bump when the compiled format changes.
	 */
	const COMPILED_VERSION = 1;

	/**
	 * Limits, so the autoloaded list stays small.
	 */
	const MAX_RULES    = 100;
	const MAX_PATTERNS = 200;
	const MAX_ITEMS    = 300;
	const MAX_FOUND    = 500;
	const MAX_PAGES    = 1000;

	/**
	 * Page types a front-end rule can pick.
	 *
	 * @return array<string,string>
	 */
	public static function page_types() {
		return array(
			'front'   => __( 'Home page', 'disable-plugins-per-page' ),
			'home'    => __( 'Blog page (latest posts)', 'disable-plugins-per-page' ),
			'archive' => __( 'Archives (categories, tags, dates, authors)', 'disable-plugins-per-page' ),
			'search'  => __( 'Search results', 'disable-plugins-per-page' ),
		);
	}

	/**
	 * Groups of core admin screens an admin rule can pick: label, icon and files.
	 *
	 * @return array<string,array>
	 */
	public static function screens() {
		return array(
			'dashboard'  => array( __( 'Dashboard', 'disable-plugins-per-page' ), 'layout-dashboard', array( 'index.php' ) ),
			'editor'     => array( __( 'Post editor', 'disable-plugins-per-page' ), 'square-pen', array( 'post.php', 'post-new.php' ) ),
			'lists'      => array( __( 'Post and page lists', 'disable-plugins-per-page' ), 'list', array( 'edit.php', 'edit-tags.php', 'term.php' ) ),
			'media'      => array( __( 'Media', 'disable-plugins-per-page' ), 'image', array( 'upload.php', 'media-new.php' ) ),
			'comments'   => array( __( 'Comments', 'disable-plugins-per-page' ), 'message-square', array( 'edit-comments.php', 'comment.php' ) ),
			'appearance' => array( __( 'Appearance', 'disable-plugins-per-page' ), 'paintbrush', array( 'themes.php', 'nav-menus.php', 'widgets.php', 'site-editor.php', 'theme-editor.php' ) ),
			'users'      => array( __( 'Users and profile', 'disable-plugins-per-page' ), 'users', array( 'users.php', 'user-new.php', 'user-edit.php', 'profile.php' ) ),
			'tools'      => array( __( 'Tools', 'disable-plugins-per-page' ), 'wrench', array( 'tools.php', 'import.php', 'export.php', 'export-personal-data.php', 'erase-personal-data.php' ) ),
			'settings'   => array( __( 'Settings', 'disable-plugins-per-page' ), 'settings', array( 'options-general.php', 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php' ) ),
		);
	}

	/**
	 * A blank rule with every key.
	 *
	 * @param string $area 'front' or 'admin'.
	 * @return array
	 */
	public static function blank( $area = 'front' ) {
		return array(
			'id'             => self::new_id(),
			'area'           => 'admin' === $area ? 'admin' : 'front',
			'label'          => '',
			'status'         => 'test',
			'action'         => 'off',
			'plugins'        => array(),
			'everywhere'     => 0,
			'types'          => array(),
			'post_types'     => array(),
			'items'          => array(),
			'contains'       => array(),
			'patterns'       => array(),
			'device'         => '',
			'screens'        => array(),
			'admin_patterns' => array(),
			'starter'        => '',
		);
	}

	/**
	 * A new random rule ID.
	 *
	 * @return string
	 */
	public static function new_id() {
		return 'r' . strtolower( wp_generate_password( 9, false, false ) );
	}

	// Plugins -------------------------------------------------------------

	/**
	 * Every installed plugin, as get_plugins() returns them.
	 *
	 * @return array<string,array>
	 */
	public static function installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * Active plugin basenames, as saved (never this request's shortened list).
	 *
	 * @return array
	 */
	public static function active() {
		$all    = wp_load_alloptions();
		$stored = isset( $all['active_plugins'] ) ? maybe_unserialize( $all['active_plugins'] ) : get_option( 'active_plugins', array() );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Active plugins a rule can turn off: basename => name, by name.
	 *
	 * @return array<string,string>
	 */
	public static function choosable() {
		$installed = self::installed();
		$list      = array();

		foreach ( self::active() as $file ) {
			if ( DISABLE_PLUGINS_PER_PAGE_BASENAME !== $file && isset( $installed[ $file ] ) ) {
				$list[ $file ] = '' !== trim( $installed[ $file ]['Name'] ) ? $installed[ $file ]['Name'] : $file;
			}
		}

		natcasesort( $list );

		return $list;
	}

	/**
	 * A plugin's name.
	 *
	 * @param string $file      Basename.
	 * @param array  $installed From installed().
	 * @return string
	 */
	public static function name( $file, array $installed ) {
		return isset( $installed[ $file ]['Name'] ) && '' !== trim( $installed[ $file ]['Name'] ) ? $installed[ $file ]['Name'] : $file;
	}

	/**
	 * The slug WordPress uses for a plugin in "Requires Plugins": its folder,
	 * or its file name for a single-file plugin.
	 *
	 * @param string $file Basename.
	 * @return string
	 */
	private static function slug_of( $file ) {
		return false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
	}

	/**
	 * Add-ons that need another active plugin: add-on => parents.
	 *
	 * From the "Requires Plugins" header (WordPress 6.5+), WooCommerce and
	 * Elementor's extension headers, and names like "Stripe for WooCommerce"
	 * or "WooCommerce Subscriptions".
	 *
	 * @param array $installed From installed().
	 * @param array $active    Active basenames.
	 * @return array<string,array>
	 */
	public static function dependencies( array $installed, array $active ) {
		$by_slug = array();

		foreach ( $active as $file ) {
			$by_slug[ strtolower( self::slug_of( $file ) ) ] = $file;
		}

		$deps = array();

		foreach ( $active as $file ) {
			if ( DISABLE_PLUGINS_PER_PAGE_BASENAME === $file || ! isset( $installed[ $file ] ) || ! is_readable( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue;
			}

			$headers = get_file_data(
				WP_PLUGIN_DIR . '/' . $file,
				array(
					'requires' => 'Requires Plugins',
					'wc'       => 'WC requires at least',
					'wc_test'  => 'WC tested up to',
					'woo'      => 'Woo',
					'el'       => 'Elementor tested up to',
					'el_pro'   => 'Elementor Pro tested up to',
				)
			);
			$parents = array();

			foreach ( explode( ',', (string) $headers['requires'] ) as $slug ) {
				$slug = strtolower( trim( $slug ) );

				if ( '' !== $slug && isset( $by_slug[ $slug ] ) ) {
					$parents[] = $by_slug[ $slug ];
				}
			}

			if ( ( '' !== $headers['wc'] || '' !== $headers['wc_test'] || '' !== $headers['woo'] ) && isset( $by_slug['woocommerce'] ) ) {
				$parents[] = $by_slug['woocommerce'];
			}

			if ( '' !== $headers['el'] && isset( $by_slug['elementor'] ) ) {
				$parents[] = $by_slug['elementor'];
			}

			if ( '' !== $headers['el_pro'] && isset( $by_slug['elementor-pro'] ) ) {
				$parents[] = $by_slug['elementor-pro'];
			}

			$name = self::name( $file, $installed );

			foreach ( $active as $parent ) {
				$parent_name = self::name( $parent, $installed );

				if ( $parent === $file || DISABLE_PLUGINS_PER_PAGE_BASENAME === $parent || strlen( $parent_name ) < 4 ) {
					continue;
				}

				if ( 0 === stripos( $name, $parent_name . ' ' ) || false !== stripos( $name, ' for ' . $parent_name ) ) {
					$parents[] = $parent;
				}
			}

			$parents = array_values( array_unique( array_diff( $parents, array( $file ) ) ) );

			if ( $parents ) {
				$deps[ $file ] = $parents;
			}
		}

		return $deps;
	}

	// Cleaning ---------------------------------------------------------------

	/**
	 * Clean every submitted rule.
	 *
	 * @param mixed $input Submitted rules.
	 * @return array
	 */
	public static function sanitize_rules( $input ) {
		$installed = self::installed();
		$rules     = array();
		$seen      = array();

		foreach ( is_array( $input ) ? $input : array() as $raw ) {
			if ( ! is_array( $raw ) || count( $rules ) >= self::MAX_RULES ) {
				continue;
			}

			$rule = self::sanitize_rule( $raw, $installed );

			// Two rules can't share an ID (the crash guard pauses rules by ID).
			if ( isset( $seen[ $rule['id'] ] ) ) {
				$rule['id'] = self::new_id();
			}

			$seen[ $rule['id'] ] = true;
			$rules[]             = $rule;
		}

		return $rules;
	}

	/**
	 * Clean one rule.
	 *
	 * @param array $in        Submitted values.
	 * @param array $installed From installed().
	 * @return array
	 */
	public static function sanitize_rule( array $in, array $installed ) {
		$rule = self::blank( isset( $in['area'] ) ? (string) $in['area'] : 'front' );
		$pick = function ( $key, array $allowed, $default ) use ( $in ) {
			return isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ? $in[ $key ] : $default;
		};
		$list = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? array_filter( $in[ $key ], 'is_scalar' ) : array();
		};

		if ( isset( $in['id'] ) && is_string( $in['id'] ) && preg_match( '/^[a-z0-9]{4,20}$/', $in['id'] ) ) {
			$rule['id'] = $in['id'];
		}

		$rule['label']   = isset( $in['label'] ) && is_string( $in['label'] ) ? substr( sanitize_text_field( $in['label'] ), 0, 100 ) : '';
		$rule['status']  = $pick( 'status', array( 'live', 'test', 'off' ), 'test' );
		$rule['action']  = $pick( 'action', array( 'off', 'only' ), 'off' );
		$rule['starter'] = isset( $in['starter'] ) && is_string( $in['starter'] ) ? sanitize_key( $in['starter'] ) : '';

		$plugins = array();

		foreach ( $list( 'plugins' ) as $file ) {
			$file = (string) $file;

			if ( isset( $installed[ $file ] ) && DISABLE_PLUGINS_PER_PAGE_BASENAME !== $file ) {
				$plugins[] = $file;
			}
		}

		$rule['plugins']    = array_slice( array_values( array_unique( $plugins ) ), 0, 100 );
		$rule['everywhere'] = empty( $in['everywhere'] ) ? 0 : 1;

		if ( 'admin' === $rule['area'] ) {
			$rule['screens']        = array_values( array_intersect( array_map( 'strval', $list( 'screens' ) ), array_keys( self::screens() ) ) );
			$rule['admin_patterns'] = self::clean_lines( isset( $in['admin_patterns'] ) ? $in['admin_patterns'] : '', array( __CLASS__, 'clean_admin_pattern' ), self::MAX_PATTERNS );

			return $rule;
		}

		$rule['types']      = array_values( array_intersect( array_map( 'strval', $list( 'types' ) ), array_keys( self::page_types() ) ) );
		$rule['post_types'] = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', $list( 'post_types' ) ) ) ) ), 0, 50 );
		$rule['items']      = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $list( 'items' ) ) ) ) ), 0, self::MAX_ITEMS );
		$rule['contains']   = self::clean_lines( isset( $in['contains'] ) ? $in['contains'] : '', array( __CLASS__, 'clean_needle' ), 20 );
		$rule['patterns']   = self::clean_lines( isset( $in['patterns'] ) ? $in['patterns'] : '', array( __CLASS__, 'clean_pattern' ), self::MAX_PATTERNS );
		$rule['device']     = $pick( 'device', array( '', 'mobile', 'desktop' ), '' );

		return $rule;
	}

	/**
	 * Clean a textarea (or list) of lines.
	 *
	 * @param mixed    $value   Text or array.
	 * @param callable $cleaner Cleans one line; returns '' to drop it.
	 * @param int      $max     Most lines kept.
	 * @return array
	 */
	private static function clean_lines( $value, $cleaner, $max ) {
		$lines = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		$out   = array();

		foreach ( $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}

			$line = call_user_func( $cleaner, (string) $line );

			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}

			if ( count( $out ) >= $max ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Clean a front-end URL pattern into a normalised path. Full URLs are cut
	 * down to their path; "*" is the only wildcard.
	 *
	 * @param string $line Pattern.
	 * @return string
	 */
	public static function clean_pattern( $line ) {
		$line = trim( preg_replace( '/[\x00-\x20\x7f]+/', '', (string) $line ) );

		if ( '' === $line ) {
			return '';
		}

		if ( preg_match( '#^(?:https?:)?//#i', $line ) ) {
			$line = (string) wp_parse_url( $line, PHP_URL_PATH );
		}

		$home = Disable_Plugins_Per_Page_Filter::normalize_path( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$line = strtolower( rawurldecode( preg_replace( '/[?#].*$/s', '', $line ) ) );
		$line = preg_replace( '#/+#', '/', '/' . $line );

		if ( '/' !== $home && 0 === strpos( rtrim( $line, '*' ) . '/', $home ) ) {
			$line = '/' . substr( $line, strlen( $home ) );
		}

		$line = preg_replace( '/\*+/', '*', $line );

		if ( '*' !== substr( $line, -1 ) ) {
			$line = rtrim( $line, '/' ) . '/';
		}

		return substr( $line, 0, 300 );
	}

	/**
	 * Clean an admin screen pattern: "edit.php?post_type=product*".
	 *
	 * @param string $line Pattern.
	 * @return string
	 */
	public static function clean_admin_pattern( $line ) {
		$line = trim( preg_replace( '/[\x00-\x20\x7f]+/', '', (string) $line ) );
		$pos  = stripos( $line, 'wp-admin/' );

		if ( false !== $pos ) {
			$line = substr( $line, $pos + 9 );
		}

		$line = ltrim( strtolower( rawurldecode( preg_replace( '/#.*$/s', '', $line ) ) ), '/' );
		$line = preg_replace( '/\*+/', '*', $line );

		return '' === $line || '*' === $line ? '' : substr( $line, 0, 300 );
	}

	/**
	 * Clean a "content contains" line: a shortcode or block name.
	 *
	 * @param string $line Text.
	 * @return string
	 */
	public static function clean_needle( $line ) {
		$line = trim( str_replace( array( '<!--', '-->' ), '', (string) $line ) );
		$line = trim( sanitize_text_field( $line ) );

		return strlen( $line ) >= 3 ? substr( $line, 0, 100 ) : '';
	}

	// Compiling --------------------------------------------------------------

	/**
	 * Whether a request has asked for a compile at shutdown.
	 *
	 * @var bool
	 */
	private static $queued = false;

	/**
	 * Compile once at the end of this request (bulk edits save many posts).
	 *
	 * @return void
	 */
	public static function queue_compile() {
		if ( ! self::$queued ) {
			self::$queued = true;
			add_action( 'shutdown', array( __CLASS__, 'compile' ) );
		}
	}

	/**
	 * Build the compiled list the filter reads on every request.
	 *
	 * @return array The compiled rules.
	 */
	public static function compile() {
		$settings  = Disable_Plugins_Per_Page_Plugin::settings();
		$installed = self::installed();
		$active    = self::active();
		$front     = array();
		$admin     = array();
		$info      = array();

		foreach ( (array) $settings['rules'] as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['id'] ) ) {
				continue;
			}

			$rule = wp_parse_args( $rule, self::blank( isset( $rule['area'] ) ? $rule['area'] : 'front' ) );

			if ( 'admin' === $rule['area'] ) {
				$compiled = self::compile_admin( $rule );
			} else {
				$found               = self::content_pages( $rule['contains'] );
				$info[ $rule['id'] ] = array(
					'found'  => count( $found['paths'] ),
					'global' => $found['global'],
				);
				$compiled            = self::compile_front( $rule, $found['paths'] );
			}

			if ( 'off' === $rule['status'] || ! $rule['plugins'] || ! $compiled ) {
				continue;
			}

			if ( 'admin' === $rule['area'] ) {
				$admin[] = $compiled;
			} else {
				$front[] = $compiled;
			}
		}

		$compiled = array(
			'v'     => self::COMPILED_VERSION,
			'front' => $front,
			'admin' => $admin,
			'items' => self::compile_items(),
			'deps'  => self::dependencies( $installed, $active ),
			'maps'  => self::query_var_maps(),
			'guard' => empty( $settings['guard'] ) ? 0 : 1,
		);

		update_option( Disable_Plugins_Per_Page_Filter::COMPILED, $compiled, true );
		update_option( self::INFO, $info, false );

		return $compiled;
	}

	/**
	 * The parts of a front-end rule the filter needs.
	 *
	 * @param array $rule  Rule.
	 * @param array $found Paths of pages whose content matched.
	 * @return array|null Null when the rule doesn't say where.
	 */
	private static function compile_front( array $rule, array $found ) {
		$paths = array();

		foreach ( array_merge( self::item_paths( $rule['items'] ), $found ) as $path ) {
			$paths[ $path ] = 1;
		}

		$compiled = array(
			'id'         => (string) $rule['id'],
			'test'       => 'test' === $rule['status'],
			'only'       => 'only' === $rule['action'],
			'plugins'    => array_values( (array) $rule['plugins'] ),
			'all'        => ! empty( $rule['everywhere'] ),
			'types'      => array_values( (array) $rule['types'] ),
			'post_types' => array_values( (array) $rule['post_types'] ),
			'paths'      => $paths,
			'patterns'   => array_values( (array) $rule['patterns'] ),
			'device'     => (string) $rule['device'],
		);

		// "Only where its shortcode is used" with nothing found yet still
		// means "off everywhere else", so it's kept; a plain rule with no
		// "where" at all isn't.
		if ( ! $compiled['all'] && ! $compiled['types'] && ! $compiled['post_types'] && ! $paths && ! $compiled['patterns'] && ! $rule['contains'] && ! $rule['items'] ) {
			return null;
		}

		return array_filter(
			$compiled,
			function ( $value ) {
				return false !== $value && '' !== $value && array() !== $value;
			}
		) + array( 'id' => (string) $rule['id'] );
	}

	/**
	 * The parts of an admin rule the filter needs.
	 *
	 * @param array $rule Rule.
	 * @return array|null
	 */
	private static function compile_admin( array $rule ) {
		$screens = self::screens();
		$files   = array();

		foreach ( (array) $rule['screens'] as $key ) {
			if ( isset( $screens[ $key ] ) ) {
				$files = array_merge( $files, $screens[ $key ][2] );
			}
		}

		if ( empty( $rule['everywhere'] ) && ! $files && ! $rule['admin_patterns'] ) {
			return null;
		}

		return array_filter(
			array(
				'id'      => (string) $rule['id'],
				'test'    => 'test' === $rule['status'],
				'only'    => 'only' === $rule['action'],
				'plugins' => array_values( (array) $rule['plugins'] ),
				'all'     => ! empty( $rule['everywhere'] ),
				'files'   => array_values( array_unique( $files ) ),
				'screens' => array_values( (array) $rule['admin_patterns'] ),
			),
			function ( $value ) {
				return false !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Normalised path of each published post in a list.
	 *
	 * @param array $ids Post IDs.
	 * @return array
	 */
	public static function item_paths( array $ids ) {
		$paths = array();

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );

			if ( $post && in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
				$path = self::url_path( get_permalink( $post ) );

				if ( '' !== $path ) {
					$paths[] = $path;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * A URL on this site as a normalised path relative to home.
	 *
	 * @param string|false $url URL.
	 * @return string Empty for another site's URL.
	 */
	public static function url_path( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) $host ) ) {
			return '';
		}

		$path = Disable_Plugins_Per_Page_Filter::normalize_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$home = Disable_Plugins_Per_Page_Filter::normalize_path( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}

		return $path;
	}

	/**
	 * Public post types a rule or the editor panel can work with.
	 *
	 * @return array<string,string> Name => label.
	 */
	public static function post_types() {
		$list = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$list[ $type->name ] = $type->labels->name;
			}
		}

		return $list;
	}

	/**
	 * Published pages whose content contains any of the given strings, plus
	 * pages that use a synced pattern containing them.
	 *
	 * @param array $needles Shortcode or block names.
	 * @return array 'paths' and 'global' (also found in a template, template
	 *               part or widget, which can show on every page).
	 */
	public static function content_pages( array $needles ) {
		global $wpdb;

		$result = array(
			'paths'  => array(),
			'global' => false,
		);

		if ( ! $needles ) {
			return $result;
		}

		$types = array_keys( self::post_types() );

		if ( ! $types ) {
			return $result;
		}

		$like = array();

		foreach ( $needles as $needle ) {
			$like[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $needle ) . '%' );
		}

		$like_sql  = '(' . implode( ' OR ', $like ) . ')';
		$type_list = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every value is prepared above or here; this runs on save, not on page views.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($type_list) AND $like_sql LIMIT %d", array_merge( $types, array( self::MAX_FOUND ) ) ) );

		// Synced patterns: find the pages that include them.
		$blocks = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wp_block' AND post_status = 'publish' AND $like_sql LIMIT 50" );

		foreach ( $blocks as $block_id ) {
			$more = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($type_list) AND post_content LIKE %s LIMIT %d", array_merge( $types, array( '%' . $wpdb->esc_like( '"ref":' . (int) $block_id ) . '%', self::MAX_FOUND ) ) ) );
			$ids  = array_merge( $ids, $more );
		}

		$templates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wp_template','wp_template_part') AND post_status = 'publish' AND $like_sql" );
		// phpcs:enable

		$widgets = false;

		foreach ( array( 'widget_text', 'widget_block', 'widget_custom_html' ) as $option ) {
			$value = maybe_serialize( get_option( $option ) );

			foreach ( $needles as $needle ) {
				if ( is_string( $value ) && false !== stripos( $value, $needle ) ) {
					$widgets = true;
				}
			}
		}

		$result['paths']  = self::item_paths( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, self::MAX_FOUND ) );
		$result['global'] = $templates > 0 || $widgets;

		return $result;
	}

	/**
	 * Each post's own settings from the editor, keyed by its path.
	 *
	 * @return array
	 */
	private static function compile_items() {
		$ids   = get_posts(
			array(
				'post_type'        => array_keys( self::post_types() ),
				'post_status'      => array( 'publish', 'private' ),
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs on save only.
				'posts_per_page'   => self::MAX_PAGES,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$items = array();

		foreach ( $ids as $id ) {
			$data = self::post_settings( $id );

			if ( ! $data['plugins'] ) {
				continue;
			}

			$path = self::url_path( get_permalink( $id ) );

			if ( '' === $path ) {
				continue;
			}

			$item = array(
				'id'  => (int) $id,
				'off' => array_keys( $data['plugins'], 'off', true ),
				'on'  => array_keys( $data['plugins'], 'on', true ),
			);

			if ( 'live' !== $data['status'] ) {
				$item['test'] = true;
			}

			$items[ $path ] = $item;
		}

		return $items;
	}

	/**
	 * A post's own plugin settings.
	 *
	 * @param int $post_id Post ID.
	 * @return array 'status' ('test' or 'live') and 'plugins' (basename => 'off'|'on').
	 */
	public static function post_settings( $post_id ) {
		$data = get_post_meta( (int) $post_id, self::META, true );
		$data = is_array( $data ) ? $data : array();

		return array(
			'status'  => isset( $data['status'] ) && 'live' === $data['status'] ? 'live' : 'test',
			'plugins' => isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array(),
		);
	}

	/**
	 * Clean a post's plugin settings before saving.
	 *
	 * @param mixed $data Submitted settings.
	 * @return array
	 */
	public static function sanitize_post_settings( $data ) {
		$data      = is_array( $data ) ? $data : array();
		$installed = self::installed();
		$plugins   = array();

		foreach ( isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array() as $file => $mode ) {
			if ( isset( $installed[ $file ] ) && DISABLE_PLUGINS_PER_PAGE_BASENAME !== $file && in_array( $mode, array( 'off', 'on' ), true ) ) {
				$plugins[ $file ] = $mode;
			}
		}

		return array(
			'status'  => isset( $data['status'] ) && 'live' === $data['status'] ? 'live' : 'test',
			'plugins' => array_slice( $plugins, 0, 100, true ),
		);
	}

	/**
	 * Post type and taxonomy query vars, so the filter can tell what a URL
	 * is before those types are registered on the request.
	 *
	 * @return array
	 */
	public static function query_var_maps() {
		$qv  = array();
		$tax = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( ! empty( $type->query_var ) && ! in_array( $type->name, array( 'post', 'page', 'attachment' ), true ) ) {
				$qv[ (string) $type->query_var ] = $type->name;
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->query_var ) && ! in_array( $taxonomy->name, array( 'category', 'post_tag' ), true ) ) {
				$tax[] = (string) $taxonomy->query_var;
			}
		}

		ksort( $qv );
		sort( $tax );

		return array(
			'qv'  => $qv,
			'tax' => $tax,
		);
	}

	// Describing ---------------------------------------------------------------

	/**
	 * One line saying what a rule does, for its card header.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	public static function summary( array $rule ) {
		$rule  = wp_parse_args( $rule, self::blank() );
		$parts = array();

		if ( ! empty( $rule['everywhere'] ) ) {
			$parts[] = 'admin' === $rule['area'] ? __( 'every admin screen', 'disable-plugins-per-page' ) : __( 'every page', 'disable-plugins-per-page' );
		}

		if ( 'admin' === $rule['area'] ) {
			$screens = self::screens();

			foreach ( (array) $rule['screens'] as $key ) {
				if ( isset( $screens[ $key ] ) ) {
					$parts[] = $screens[ $key ][0];
				}
			}

			if ( $rule['admin_patterns'] ) {
				/* translators: %d: number of admin URL patterns. */
				$parts[] = sprintf( _n( '%d screen pattern', '%d screen patterns', count( $rule['admin_patterns'] ), 'disable-plugins-per-page' ), count( $rule['admin_patterns'] ) );
			}
		} else {
			$types = self::page_types();

			foreach ( (array) $rule['types'] as $key ) {
				if ( isset( $types[ $key ] ) ) {
					$parts[] = $types[ $key ];
				}
			}

			$post_types = self::post_types();

			foreach ( (array) $rule['post_types'] as $key ) {
				$parts[] = isset( $post_types[ $key ] ) ? $post_types[ $key ] : $key;
			}

			if ( $rule['items'] ) {
				/* translators: %d: number of chosen pages. */
				$parts[] = sprintf( _n( '%d chosen page', '%d chosen pages', count( $rule['items'] ), 'disable-plugins-per-page' ), count( $rule['items'] ) );
			}

			if ( $rule['contains'] ) {
				$parts[] = __( 'pages that use it', 'disable-plugins-per-page' );
			}

			if ( $rule['patterns'] ) {
				$parts[] = 1 === count( $rule['patterns'] ) ? $rule['patterns'][0] : sprintf(
					/* translators: %d: number of URL patterns. */
					_n( '%d URL pattern', '%d URL patterns', count( $rule['patterns'] ), 'disable-plugins-per-page' ),
					count( $rule['patterns'] )
				);
			}
		}

		if ( ! $parts ) {
			return __( 'Choose where this rule applies', 'disable-plugins-per-page' );
		}

		$where = implode( ', ', $parts );

		if ( 'only' === $rule['action'] ) {
			/* translators: %s: list of places. */
			$text = sprintf( __( 'Only loads on %s', 'disable-plugins-per-page' ), $where );
		} else {
			/* translators: %s: list of places. */
			$text = sprintf( __( 'Off on %s', 'disable-plugins-per-page' ), $where );
		}

		if ( 'mobile' === $rule['device'] ) {
			$text .= ' · ' . __( 'phones and tablets', 'disable-plugins-per-page' );
		} elseif ( 'desktop' === $rule['device'] ) {
			$text .= ' · ' . __( 'desktops', 'disable-plugins-per-page' );
		}

		return $text;
	}

	/**
	 * A rule's display name: its label, or its plugins' names.
	 *
	 * @param array $rule      Rule.
	 * @param array $installed From installed().
	 * @return string
	 */
	public static function title( array $rule, array $installed ) {
		if ( ! empty( $rule['label'] ) ) {
			return (string) $rule['label'];
		}

		$names = array();

		foreach ( isset( $rule['plugins'] ) ? (array) $rule['plugins'] : array() as $file ) {
			$names[] = self::name( $file, $installed );
		}

		if ( ! $names ) {
			return __( 'New rule', 'disable-plugins-per-page' );
		}

		if ( count( $names ) > 2 ) {
			/* translators: 1: first plugin name, 2: number of other plugins. */
			return sprintf( __( '%1$s and %2$d more', 'disable-plugins-per-page' ), $names[0], count( $names ) - 1 );
		}

		return implode( ' + ', $names );
	}

	// Starter rules ----------------------------------------------------------

	/**
	 * Ready-made rules for common plugins that are active here.
	 *
	 * @return array<string,array> Key => 'title', 'text', 'icon', 'rule'.
	 */
	public static function starters() {
		$active   = self::active();
		$has      = function ( array $files ) use ( $active ) {
			foreach ( $files as $file ) {
				if ( in_array( $file, $active, true ) ) {
					return $file;
				}
			}

			return '';
		};
		$starters = array();

		$woo = $has( array( 'woocommerce/woocommerce.php' ) );

		if ( $woo ) {
			$patterns = array();

			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
				$id = (int) get_option( 'woocommerce_' . $page . '_page_id' );

				if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
					$path = self::url_path( get_permalink( $id ) );

					// Endpoints (order-received, orders...) live under these pages.
					if ( '' !== $path && '/' !== $path ) {
						$patterns[] = $path . '*';
					}
				}
			}

			$bases = get_option( 'woocommerce_permalinks' );
			$bases = is_array( $bases ) ? $bases : array();

			foreach ( array(
				'category_base' => 'product-category',
				'tag_base'      => 'product-tag',
			) as $key => $default ) {
				$base       = ! empty( $bases[ $key ] ) ? trim( (string) $bases[ $key ], '/' ) : $default;
				$patterns[] = self::clean_pattern( '/' . $base . '/*' );
			}

			$starters['woocommerce'] = array(
				'title' => __( 'WooCommerce only on shop pages', 'disable-plugins-per-page' ),
				'text'  => __( 'Loads WooCommerce on products, the shop, cart, checkout and account pages, and turns it off on your blog posts and other pages. Its add-ons go with it.', 'disable-plugins-per-page' ),
				'icon'  => 'shopping-cart',
				'rule'  => array(
					'label'      => __( 'WooCommerce only on shop pages', 'disable-plugins-per-page' ),
					'action'     => 'only',
					'plugins'    => array( $woo ),
					'post_types' => post_type_exists( 'product' ) ? array( 'product' ) : array(),
					'patterns'   => array_values( array_unique( array_filter( $patterns ) ) ),
				),
			);

			$starters['woocommerce-admin'] = array(
				'title' => __( 'WooCommerce off on Media and Comments', 'disable-plugins-per-page' ),
				'text'  => __( 'An admin rule: the Media library and Comments screens load without WooCommerce.', 'disable-plugins-per-page' ),
				'icon'  => 'layout-dashboard',
				'rule'  => array(
					'area'    => 'admin',
					'label'   => __( 'WooCommerce off on Media and Comments', 'disable-plugins-per-page' ),
					'action'  => 'off',
					'plugins' => array( $woo ),
					'screens' => array( 'media', 'comments' ),
				),
			);
		}

		$content = array(
			'contact-form-7' => array( array( 'contact-form-7/wp-contact-form-7.php' ), 'Contact Form 7', array( '[contact-form-7', 'wp:contact-form-7/' ), 'mail' ),
			'wpforms'        => array( array( 'wpforms-lite/wpforms.php', 'wpforms/wpforms.php' ), 'WPForms', array( '[wpforms', 'wp:wpforms/' ), 'mail' ),
			'gravityforms'   => array( array( 'gravityforms/gravityforms.php' ), 'Gravity Forms', array( '[gravityform', 'wp:gravityforms/' ), 'mail' ),
			'ninja-forms'    => array( array( 'ninja-forms/ninja-forms.php' ), 'Ninja Forms', array( '[ninja_form', 'wp:ninja-forms/' ), 'mail' ),
			'formidable'     => array( array( 'formidable/formidable.php' ), 'Formidable Forms', array( '[formidable', 'wp:formidable/' ), 'mail' ),
			'fluentform'     => array( array( 'fluentform/fluentform.php' ), 'Fluent Forms', array( '[fluentform' ), 'mail' ),
			'revslider'      => array( array( 'revslider/revslider.php' ), 'Slider Revolution', array( '[rev_slider', 'wp:themepunch/revslider' ), 'gallery-horizontal' ),
			'smart-slider-3' => array( array( 'smart-slider-3/smart-slider-3.php', 'nextend-smart-slider3-pro/nextend-smart-slider3-pro.php' ), 'Smart Slider 3', array( '[smartslider3', 'wp:nextend/smartslider3' ), 'gallery-horizontal' ),
			'ml-slider'      => array( array( 'ml-slider/ml-slider.php' ), 'MetaSlider', array( '[metaslider', 'wp:metaslider/' ), 'gallery-horizontal' ),
			'layerslider'    => array( array( 'LayerSlider/layerslider.php' ), 'LayerSlider', array( '[layerslider', 'wp:kreatura/layerslider' ), 'gallery-horizontal' ),
		);

		foreach ( $content as $key => $def ) {
			$file = $has( $def[0] );

			if ( ! $file ) {
				continue;
			}

			$starters[ $key ] = array(
				/* translators: %s: plugin name, e.g. Contact Form 7. */
				'title' => sprintf( __( '%s only where it\'s used', 'disable-plugins-per-page' ), $def[1] ),
				/* translators: %s: plugin name. */
				'text'  => sprintf( __( 'Finds the pages that contain one of its shortcodes or blocks and turns %s off everywhere else. The list updates when you save a page.', 'disable-plugins-per-page' ), $def[1] ),
				'icon'  => $def[3],
				'rule'  => array(
					/* translators: %s: plugin name. */
					'label'    => sprintf( __( '%s only where it\'s used', 'disable-plugins-per-page' ), $def[1] ),
					'action'   => 'only',
					'plugins'  => array( $file ),
					'contains' => $def[2],
				),
			);
		}

		$cf7 = $has( array( 'contact-form-7/wp-contact-form-7.php' ) );

		if ( $cf7 ) {
			$starters['contact-form-7-admin'] = array(
				'title' => __( 'Contact Form 7 only on its own screens', 'disable-plugins-per-page' ),
				'text'  => __( 'An admin rule: Contact Form 7 loads on its own screens and in the post editor, and nowhere else in the admin.', 'disable-plugins-per-page' ),
				'icon'  => 'layout-dashboard',
				'rule'  => array(
					'area'           => 'admin',
					'label'          => __( 'Contact Form 7 only on its own screens', 'disable-plugins-per-page' ),
					'action'         => 'only',
					'plugins'        => array( $cf7 ),
					'screens'        => array( 'editor' ),
					'admin_patterns' => array( 'admin.php?page=wpcf7*' ),
				),
			);
		}

		return $starters;
	}
}
