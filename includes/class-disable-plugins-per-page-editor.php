<?php
/**
 * Per-page control in the editor: a "Plugins on this page" panel in the
 * block editor's sidebar (and a matching box in the classic editor).
 *
 * Only users who can manage plugins see it. Each plugin can follow the
 * rules, be turned off on this page, or always load here.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor panel, classic meta box and the REST route that saves them.
 */
final class Disable_Plugins_Per_Page_Editor {

	/**
	 * REST namespace.
	 */
	const REST_NS = 'disable-plugins-per-page/v1';

	/**
	 * Nonce for the classic meta box.
	 */
	const NONCE = 'disable_plugins_per_page_post';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Whether the current user may change plugins for this post.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private static function can_edit( $post ) {
		return $post instanceof WP_Post
			&& current_user_can( Disable_Plugins_Per_Page_Plugin::CAP )
			&& current_user_can( 'edit_post', $post->ID )
			&& array_key_exists( $post->post_type, Disable_Plugins_Per_Page_Rules::post_types() );
	}

	/**
	 * POST /disable-plugins-per-page/v1/posts/{id}.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NS,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_save' ),
				'permission_callback' => function ( $request ) {
					return self::can_edit( get_post( (int) $request['id'] ) );
				},
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'test', 'live' ),
					),
					'settings' => array(
						'type' => 'object',
					),
				),
			)
		);
	}

	/**
	 * Save a post's plugin settings from the block editor panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_save( $request ) {
		$post_id = (int) $request['id'];

		self::store(
			$post_id,
			array(
				'status'  => (string) $request['status'],
				'plugins' => is_array( $request['settings'] ) ? $request['settings'] : array(),
			)
		);

		return rest_ensure_response( self::panel_data( get_post( $post_id ) ) );
	}

	/**
	 * Save (or clear) a post's settings and recompile.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    'status' and 'plugins'.
	 * @return void
	 */
	private static function store( $post_id, array $data ) {
		$clean = Disable_Plugins_Per_Page_Rules::sanitize_post_settings( $data );

		if ( $clean['plugins'] ) {
			update_post_meta( $post_id, Disable_Plugins_Per_Page_Rules::META, $clean );
		} else {
			delete_post_meta( $post_id, Disable_Plugins_Per_Page_Rules::META );
		}

		Disable_Plugins_Per_Page_Rules::compile();
	}

	/**
	 * What the panel shows for a post: each plugin, what the rules do to it
	 * here, and this page's own choice.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function panel_data( $post ) {
		$installed = Disable_Plugins_Per_Page_Rules::installed();
		$active    = Disable_Plugins_Per_Page_Rules::active();
		$settings  = Disable_Plugins_Per_Page_Rules::post_settings( $post->ID );
		$compiled  = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );
		$compiled  = is_array( $compiled ) ? $compiled : array();
		$state     = Disable_Plugins_Per_Page_Filter::state();
		$titles    = array();

		foreach ( (array) Disable_Plugins_Per_Page_Plugin::settings()['rules'] as $rule ) {
			if ( is_array( $rule ) && isset( $rule['id'] ) ) {
				$titles[ $rule['id'] ] = Disable_Plugins_Per_Page_Rules::title( $rule, $installed );
			}
		}

		// What the rules alone do here (the page's own choices left out).
		$rules_only          = $compiled;
		$rules_only['items'] = array();
		$rules_only['ids']   = array();
		$ctx                 = self::context( $post );
		$visitors            = Disable_Plugins_Per_Page_Filter::evaluate( $rules_only, 'front', $ctx, false, $state, $active );
		$testing             = Disable_Plugins_Per_Page_Filter::evaluate( $rules_only, 'front', $ctx, true, $state, $active );
		$plugins             = array();

		foreach ( Disable_Plugins_Per_Page_Rules::choosable() as $file => $name ) {
			$why = isset( $testing[ $file ] ) ? $testing[ $file ] : null;

			$plugins[] = array(
				'file'   => $file,
				'name'   => $name,
				'mode'   => isset( $settings['plugins'][ $file ] ) ? $settings['plugins'][ $file ] : '',
				'ruled'  => null !== $why,
				'live'   => isset( $visitors[ $file ] ),
				'reason' => $why ? Disable_Plugins_Per_Page_Toolbar::reason( $why, $titles, $installed ) : '',
			);
		}

		// Plugins a rule or this page affects first, then the rest by name.
		usort(
			$plugins,
			function ( $a, $b ) {
				$a_first = $a['ruled'] || '' !== $a['mode'] ? 0 : 1;
				$b_first = $b['ruled'] || '' !== $b['mode'] ? 0 : 1;

				return $a_first - $b_first ? $a_first - $b_first : strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'postId'  => (int) $post->ID,
			'status'  => $settings['status'],
			'plugins' => $plugins,
			'paused'  => in_array( (int) $post->ID, array_map( 'intval', isset( $state['paused_posts'] ) ? (array) $state['paused_posts'] : array() ), true ),
		);
	}

	/**
	 * The page context of a post, as the filter would see its URL.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function context( $post ) {
		$types = array( 'singular' );

		if ( 'page' === get_option( 'show_on_front' ) ) {
			if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
				$types[] = 'front';
			} elseif ( (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
				$types[] = 'home';
			}
		}

		return array(
			'path'   => self::post_path( $post ),
			'mobile' => null,
			'page'   => array(
				'types'     => $types,
				'post_type' => $post->post_type,
				'id'        => (int) $post->ID,
			),
		);
	}

	/**
	 * A post's path, or the path it will have once published.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function post_path( $post ) {
		if ( 'publish' === $post->post_status || 'private' === $post->post_status ) {
			return Disable_Plugins_Per_Page_Rules::post_path( $post );
		}

		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		list( $template, $name ) = get_sample_permalink( $post );

		return Disable_Plugins_Per_Page_Rules::url_path( str_replace( array( '%pagename%', '%postname%' ), $name, $template ) );
	}

	/**
	 * Load the block editor panel.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$post = get_post();

		if ( ! self::can_edit( $post ) ) {
			return;
		}

		wp_enqueue_script(
			'disable-plugins-per-page-editor',
			DISABLE_PLUGINS_PER_PAGE_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-editor', 'wp-edit-post' ),
			DISABLE_PLUGINS_PER_PAGE_VERSION,
			true
		);
		wp_enqueue_style( 'disable-plugins-per-page-editor', DISABLE_PLUGINS_PER_PAGE_URL . 'assets/editor.css', array(), DISABLE_PLUGINS_PER_PAGE_VERSION );

		wp_localize_script(
			'disable-plugins-per-page-editor',
			'disable_plugins_per_page_editor',
			array(
				'data'        => self::panel_data( $post ),
				'route'       => '/' . self::REST_NS . '/posts/' . (int) $post->ID,
				'settingsUrl' => Disable_Plugins_Per_Page_Admin::page_url(),
				'i18n'        => array(
					'title'     => __( 'Plugins on this page', 'disable-plugins-per-page' ),
					'intro'     => __( 'Turn off plugins this page doesn\'t need, or keep one loading here when a rule turns it off.', 'disable-plugins-per-page' ),
					'follow'    => __( 'Follow rules', 'disable-plugins-per-page' ),
					'off'       => __( 'Turn off here', 'disable-plugins-per-page' ),
					'on'        => __( 'Always load here', 'disable-plugins-per-page' ),
					'appliesTo' => __( 'These choices apply to', 'disable-plugins-per-page' ),
					'testing'   => __( 'Administrators only (testing)', 'disable-plugins-per-page' ),
					'live'      => __( 'Everyone', 'disable-plugins-per-page' ),
					'saving'    => __( 'Saving...', 'disable-plugins-per-page' ),
					'saved'     => __( 'Saved', 'disable-plugins-per-page' ),
					'failed'    => __( 'Couldn\'t save. Try again.', 'disable-plugins-per-page' ),
					'onNow'     => __( 'Loads here', 'disable-plugins-per-page' ),
					'offHere'   => __( 'Off on this page', 'disable-plugins-per-page' ),
					'onHere'    => __( 'Loads here (this page overrides the rule)', 'disable-plugins-per-page' ),
					/* translators: %s: why the plugin is off, e.g. a rule's name. */
					'offBy'     => __( 'Off: %s', 'disable-plugins-per-page' ),
					'filter'    => __( 'Filter plugins', 'disable-plugins-per-page' ),
					'paused'    => __( 'These settings were paused after this page crashed with plugins off. Turn them back on from the Safety tab.', 'disable-plugins-per-page' ),
					'allRules'  => __( 'All rules', 'disable-plugins-per-page' ),
					'instant'   => __( 'Changes save right away.', 'disable-plugins-per-page' ),
					'none'      => __( 'No other active plugins.', 'disable-plugins-per-page' ),
				),
			)
		);
	}

	/**
	 * The same controls as a box in the classic editor. The block editor
	 * uses its own panel instead (__back_compat_meta_box).
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public static function add_meta_box( $post_type, $post ) {
		if ( ! self::can_edit( $post ) ) {
			return;
		}

		add_meta_box(
			'disable-plugins-per-page',
			__( 'Plugins on this page', 'disable-plugins-per-page' ),
			array( __CLASS__, 'render_meta_box' ),
			$post_type,
			'side',
			'low',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Draw the classic editor box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$data = self::panel_data( $post );

		wp_nonce_field( self::NONCE, 'dppp_post_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Turn off plugins this page doesn\'t need, or keep one loading here when a rule turns it off. Saved with the post.', 'disable-plugins-per-page' ); ?></p>
		<?php if ( $data['paused'] ) : ?>
			<p class="notice notice-warning inline" style="margin:8px 0;padding:6px 8px"><?php esc_html_e( 'These settings were paused after this page crashed with plugins off. Turn them back on from the Safety tab.', 'disable-plugins-per-page' ); ?></p>
		<?php endif; ?>
		<div style="max-height:320px;overflow:auto;margin:8px 0;padding-right:4px">
			<?php foreach ( $data['plugins'] as $plugin ) : ?>
				<p style="margin:0 0 10px">
					<label for="dppp-<?php echo esc_attr( md5( $plugin['file'] ) ); ?>"><strong><?php echo esc_html( $plugin['name'] ); ?></strong></label><br>
					<span class="description"><?php echo esc_html( $plugin['ruled'] ? sprintf( /* translators: %s: reason. */ __( 'Off: %s', 'disable-plugins-per-page' ), $plugin['reason'] ) : __( 'Loads here', 'disable-plugins-per-page' ) ); ?></span><br>
					<select id="dppp-<?php echo esc_attr( md5( $plugin['file'] ) ); ?>" name="dppp_post[plugins][<?php echo esc_attr( $plugin['file'] ); ?>]" style="width:100%">
						<option value="" <?php selected( '', $plugin['mode'] ); ?>><?php esc_html_e( 'Follow rules', 'disable-plugins-per-page' ); ?></option>
						<option value="off" <?php selected( 'off', $plugin['mode'] ); ?>><?php esc_html_e( 'Turn off here', 'disable-plugins-per-page' ); ?></option>
						<option value="on" <?php selected( 'on', $plugin['mode'] ); ?>><?php esc_html_e( 'Always load here', 'disable-plugins-per-page' ); ?></option>
					</select>
				</p>
			<?php endforeach; ?>
		</div>
		<p>
			<label for="dppp-post-status"><?php esc_html_e( 'These choices apply to', 'disable-plugins-per-page' ); ?></label>
			<select id="dppp-post-status" name="dppp_post[status]" style="width:100%">
				<option value="test" <?php selected( 'test', $data['status'] ); ?>><?php esc_html_e( 'Administrators only (testing)', 'disable-plugins-per-page' ); ?></option>
				<option value="live" <?php selected( 'live', $data['status'] ); ?>><?php esc_html_e( 'Everyone', 'disable-plugins-per-page' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Save the classic editor box with the post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['dppp_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dppp_post_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! self::can_edit( $post ) ) {
			return;
		}

		$data = isset( $_POST['dppp_post'] ) && is_array( $_POST['dppp_post'] ) ? wp_unslash( $_POST['dppp_post'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by sanitize_post_settings().

		self::store(
			$post_id,
			array(
				'status'  => isset( $data['status'] ) ? (string) $data['status'] : 'test',
				'plugins' => isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array(),
			)
		);
	}
}
