<?php
/**
 * Before and after: loads a page of this site with every plugin and then
 * with the rules, a few times each, and keeps the median plugins, queries,
 * server time, memory and scripts/styles of each.
 *
 * Each test request carries a single-use token. Only its hash is stored, for
 * a minute, and the request deletes it when it arrives. With "every plugin"
 * the rules are skipped; with "rules" the rules apply, including ones still
 * in testing. Pages are only fetched from this site's own address.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page checks and before/after measuring.
 */
final class Disable_Plugins_Per_Page_Measure {

	/**
	 * Saved results, by path. Not autoloaded.
	 */
	const REPORT = 'disable_plugins_per_page_report';

	/**
	 * AJAX nonce action.
	 */
	const NONCE = 'disable_plugins_per_page_measure';

	/**
	 * Test requests per mode (the first pair warms caches up and isn't counted).
	 */
	const ROUNDS = 4;

	/**
	 * Most pages kept in the report.
	 */
	const MAX_ROWS = 30;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_dppp_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_dppp_measure', array( __CLASS__, 'ajax_measure' ) );
		add_action( 'wp_ajax_dppp_finish', array( __CLASS__, 'ajax_finish' ) );
		add_action( 'wp_ajax_dppp_forget', array( __CLASS__, 'ajax_forget' ) );
	}

	/**
	 * Common AJAX checks: capability and nonce.
	 *
	 * @return void
	 */
	private static function verify() {
		if ( ! current_user_can( Disable_Plugins_Per_Page_Plugin::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t do that.', 'disable-plugins-per-page' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * A URL or path typed in, as a path and query on this site.
	 *
	 * @param mixed $input URL or path.
	 * @return array|null 'path' (normalised), 'query' (array) and 'url', or null for another site.
	 */
	public static function parse_target( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input || strlen( $input ) > 1000 ) {
			return null;
		}

		if ( ! preg_match( '#^https?://#i', $input ) ) {
			$input = home_url( '/' . ltrim( $input, '/' ) );

			// home_url() already includes a subfolder; a path that repeats it
			// ("/blog/about/" on a site in /blog) shouldn't double it.
			$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

			if ( '' !== $home_path ) {
				$input = preg_replace( '#/' . preg_quote( $home_path, '#' ) . '/' . preg_quote( $home_path, '#' ) . '(/|$)#', '/' . $home_path . '$1', $input, 1 );
			}
		}

		$path = Disable_Plugins_Per_Page_Rules::url_path( $input );

		if ( '' === $path ) {
			return null;
		}

		$query = array();
		$raw   = (string) wp_parse_url( $input, PHP_URL_QUERY );

		if ( '' !== $raw ) {
			parse_str( $raw, $query );
		}

		foreach ( array( Disable_Plugins_Per_Page_Filter::MEASURE_ARG, Disable_Plugins_Per_Page_Filter::ALL_ARG, Disable_Plugins_Per_Page_Filter::RECOVERY_ARG ) as $arg ) {
			unset( $query[ $arg ] );
		}

		$url = home_url( ltrim( $path, '/' ) );

		if ( '/' === $path ) {
			$url = home_url( '/' );
		}

		return array(
			'path'  => $path,
			'query' => $query,
			'url'   => $query ? add_query_arg( array_map( 'rawurlencode', array_filter( $query, 'is_scalar' ) ), $url ) : $url,
		);
	}

	/**
	 * AJAX: which plugins the rules turn off on a page, and why. Instant:
	 * nothing is loaded.
	 *
	 * @return void
	 */
	public static function ajax_check() {
		self::verify();

		$target = self::parse_target( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- verify() checked the nonce; parsed down to a path on this site.

		if ( ! $target ) {
			wp_send_json_error( array( 'message' => __( 'Enter a page on this site.', 'disable-plugins-per-page' ) ) );
		}

		wp_send_json_success( array( 'html' => self::check_html( $target ) ) );
	}

	/**
	 * The "Check a page" result table.
	 *
	 * @param array $target From parse_target().
	 * @return string
	 */
	private static function check_html( array $target ) {
		$compiled  = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );
		$compiled  = is_array( $compiled ) ? $compiled : array();
		$state     = Disable_Plugins_Per_Page_Filter::state();
		$active    = Disable_Plugins_Per_Page_Rules::active();
		$installed = Disable_Plugins_Per_Page_Rules::installed();
		$titles    = array();

		foreach ( (array) Disable_Plugins_Per_Page_Plugin::settings()['rules'] as $rule ) {
			if ( is_array( $rule ) && isset( $rule['id'] ) ) {
				$titles[ $rule['id'] ] = Disable_Plugins_Per_Page_Rules::title( $rule, $installed );
			}
		}

		$base    = array(
			'path'  => $target['path'],
			'query' => $target['query'],
		);
		$columns = array(
			'desktop' => array( __( 'Visitors on a computer', 'disable-plugins-per-page' ), Disable_Plugins_Per_Page_Filter::evaluate( $compiled, 'front', $base + array( 'mobile' => false ), false, $state, $active ) ),
			'mobile'  => array( __( 'Visitors on a phone', 'disable-plugins-per-page' ), Disable_Plugins_Per_Page_Filter::evaluate( $compiled, 'front', $base + array( 'mobile' => true ), false, $state, $active ) ),
			'testing' => array( __( 'You, with rules in testing', 'disable-plugins-per-page' ), Disable_Plugins_Per_Page_Filter::evaluate( $compiled, 'front', $base + array( 'mobile' => false ), true, $state, $active ) ),
		);

		// Show the phone column only when it differs.
		if ( $columns['mobile'][1] === $columns['desktop'][1] ) {
			unset( $columns['mobile'] );
		}

		$paused = ! empty( $state['paused'] );
		$off    = array();

		foreach ( $columns as $column ) {
			$off = array_merge( $off, array_keys( $column[1] ) );
		}

		ob_start();
		?>
		<p class="dppp-check-url">
			<?php echo Disable_Plugins_Per_Page_Icons::get( 'link', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<a href="<?php echo esc_url( $target['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $target['url'] ); ?></a>
		</p>
		<?php if ( $paused ) : ?>
			<p class="dppp-callout is-warning"><?php echo Disable_Plugins_Per_Page_Icons::get( 'circle-pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Every rule is paused right now, so this page loads every plugin. This is what it will do once they\'re back on.', 'disable-plugins-per-page' ); ?></span></p>
		<?php endif; ?>
		<?php if ( ! $off ) : ?>
			<p class="dppp-empty"><?php echo Disable_Plugins_Per_Page_Icons::get( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'No rule turns anything off on this page. Every plugin loads.', 'disable-plugins-per-page' ); ?></p>
		<?php else : ?>
			<table class="widefat striped dppp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'disable-plugins-per-page' ); ?></th>
						<?php foreach ( $columns as $column ) : ?>
							<th><?php echo esc_html( $column[0] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Disable_Plugins_Per_Page_Rules::choosable() as $file => $name ) : ?>
						<?php
						if ( ! in_array( $file, $off, true ) ) {
							continue;
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<?php foreach ( $columns as $column ) : ?>
								<td>
									<?php if ( isset( $column[1][ $file ] ) ) : ?>
										<span class="dppp-tag is-off"><?php esc_html_e( 'Off', 'disable-plugins-per-page' ); ?></span>
										<span class="dppp-muted"><?php echo esc_html( Disable_Plugins_Per_Page_Toolbar::reason( $column[1][ $file ], $titles, $installed ) ); ?></span>
									<?php else : ?>
										<span class="dppp-tag is-on"><?php esc_html_e( 'Loads', 'disable-plugins-per-page' ); ?></span>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$count = count( Disable_Plugins_Per_Page_Rules::choosable() );
			/* translators: 1: plugins turned off for visitors, 2: active plugins. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Visitors on a computer get %1$d of %2$d plugins turned off here. Every other plugin loads as normal.', 'disable-plugins-per-page' ), count( $columns['desktop'][1] ), $count ) ) . '</p>';
			?>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX: one test request, with every plugin or with the rules.
	 *
	 * @return void
	 */
	public static function ajax_measure() {
		self::verify();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() checked the nonce.
		$target = self::parse_target( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed down to a path on this site.
		$mode   = isset( $_POST['mode'] ) && 'rules' === $_POST['mode'] ? 'rules' : 'all';
		$run    = isset( $_POST['run'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST['run'] ) ) ) ) : '';
		$step   = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;
		// phpcs:enable

		if ( ! $target ) {
			wp_send_json_error( array( 'message' => __( 'Enter a page on this site.', 'disable-plugins-per-page' ) ) );
		}

		if ( strlen( $run ) < 8 || strlen( $run ) > 32 ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Reload the page and try again.', 'disable-plugins-per-page' ) ) );
		}

		$sample = self::fetch( $target, $mode );

		if ( is_wp_error( $sample ) ) {
			wp_send_json_error( array( 'message' => $sample->get_error_message() ) );
		}

		// The first pair only warms caches up.
		if ( $step >= 2 ) {
			$key                = 'dppp_run_' . get_current_user_id() . '_' . $run;
			$samples            = get_transient( $key );
			$samples            = is_array( $samples ) ? $samples : array(
				'all'   => array(),
				'rules' => array(),
			);
			$samples[ $mode ][] = $sample;

			set_transient( $key, $samples, 15 * MINUTE_IN_SECONDS );
		}

		wp_send_json_success( array( 'ms' => $sample['ms'] ) );
	}

	/**
	 * Load the page once and collect its numbers.
	 *
	 * @param array  $target From parse_target().
	 * @param string $mode   'all' or 'rules'.
	 * @return array|WP_Error
	 */
	private static function fetch( array $target, $mode ) {
		$token = wp_generate_password( 32, false, false );
		$hash  = hash( 'sha256', $token );

		set_transient( 'dppp_mt_' . $hash, array( 'mode' => $mode ), MINUTE_IN_SECONDS );

		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					Disable_Plugins_Per_Page_Filter::MEASURE_ARG => $token,
					'nocache' => wp_rand(),
				),
				$target['url']
			),
			array(
				'timeout'     => 30,
				'redirection' => 0,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'cookies'     => array(),
				'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 DisablePluginsPerPage/' . DISABLE_PLUGINS_PER_PAGE_VERSION,
			)
		);

		$result = get_transient( 'dppp_mr_' . $hash );

		delete_transient( 'dppp_mt_' . $hash );
		delete_transient( 'dppp_mr_' . $hash );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'dppp_fetch', sprintf( __( 'Couldn\'t load the page from the server: %s', 'disable-plugins-per-page' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 500 ) {
			return new WP_Error(
				'dppp_crash',
				'rules' === $mode
					/* translators: %d: HTTP status code. */
					? sprintf( __( 'The page broke with your rules (error %d). If it crashed, the crash guard has paused the rules involved; see the Safety tab.', 'disable-plugins-per-page' ), $code )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The page returned an error (%d) even with every plugin loaded.', 'disable-plugins-per-page' ), $code )
			);
		}

		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			/* translators: %s: where the page redirects to. */
			return new WP_Error( 'dppp_redirect', sprintf( __( 'That address redirects to %s. Measure that address instead.', 'disable-plugins-per-page' ), is_string( $location ) ? $location : '?' ) );
		}

		if ( ! is_array( $result ) ) {
			return new WP_Error( 'dppp_cached', __( 'The page didn\'t report its numbers back. It may be served from a cache that ignores query strings, or the must-use loader isn\'t running.', 'disable-plugins-per-page' ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return array(
			'ms'      => (float) $result['ms'],
			'queries' => (int) $result['queries'],
			'memory'  => (int) $result['memory'],
			'plugins' => (int) $result['plugins'],
			'off'     => array_values( array_filter( (array) $result['off'], 'is_string' ) ),
			'assets'  => preg_match_all( '#<script[^>]+\bsrc=|<link[^>]+rel=["\']?stylesheet#i', $body ),
			'bytes'   => strlen( $body ),
		);
	}

	/**
	 * AJAX: work out the medians for a page and save them.
	 *
	 * @return void
	 */
	public static function ajax_finish() {
		self::verify();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify() checked the nonce.
		$target = self::parse_target( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed down to a path on this site.
		$run    = isset( $_POST['run'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST['run'] ) ) ) ) : '';
		// phpcs:enable

		$key     = 'dppp_run_' . get_current_user_id() . '_' . $run;
		$samples = get_transient( $key );

		delete_transient( $key );

		if ( ! $target || ! is_array( $samples ) || empty( $samples['all'] ) || empty( $samples['rules'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Not enough results to compare. Try again.', 'disable-plugins-per-page' ) ) );
		}

		$row = array(
			'url'   => $target['url'],
			'time'  => time(),
			'all'   => self::median_sample( $samples['all'] ),
			'rules' => self::median_sample( $samples['rules'] ),
		);

		$report = self::report();
		$key    = $target['url'];

		unset( $report[ $key ] );
		$report = array( $key => $row ) + $report;
		$report = array_slice( $report, 0, self::MAX_ROWS, true );

		update_option( self::REPORT, $report, false );

		wp_send_json_success( array( 'html' => self::row_html( $row ) ) );
	}

	/**
	 * AJAX: drop a page from the report.
	 *
	 * @return void
	 */
	public static function ajax_forget() {
		self::verify();

		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify() checked the nonce.
		$report = self::report();

		unset( $report[ $url ] );
		update_option( self::REPORT, $report, false );

		wp_send_json_success();
	}

	/**
	 * The medians of a set of samples.
	 *
	 * @param array $samples Samples.
	 * @return array
	 */
	private static function median_sample( array $samples ) {
		$out = array();

		foreach ( array( 'ms', 'queries', 'memory', 'plugins', 'assets', 'bytes' ) as $field ) {
			$values = array_map( 'floatval', wp_list_pluck( $samples, $field ) );
			sort( $values );
			$count         = count( $values );
			$mid           = (int) floor( $count / 2 );
			$out[ $field ] = $count % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		}

		$last       = end( $samples );
		$out['off'] = isset( $last['off'] ) ? (array) $last['off'] : array();

		return $out;
	}

	/**
	 * Saved results, newest first.
	 *
	 * @return array
	 */
	public static function report() {
		$report = get_option( self::REPORT, array() );

		return is_array( $report ) ? $report : array();
	}

	/**
	 * Pages worth measuring: the home page and pages the rules touch.
	 *
	 * @return array Up to 8 URLs.
	 */
	public static function suggestions() {
		$urls     = array( home_url( '/' ) );
		$settings = Disable_Plugins_Per_Page_Plugin::settings();
		$compiled = get_option( Disable_Plugins_Per_Page_Filter::COMPILED );

		foreach ( (array) $settings['rules'] as $rule ) {
			if ( ! is_array( $rule ) || 'admin' === ( isset( $rule['area'] ) ? $rule['area'] : 'front' ) || 'off' === ( isset( $rule['status'] ) ? $rule['status'] : '' ) ) {
				continue;
			}

			foreach ( isset( $rule['patterns'] ) ? (array) $rule['patterns'] : array() as $pattern ) {
				$plain = rtrim( (string) $pattern, '*' );

				if ( false === strpos( $plain, '*' ) ) {
					$urls[] = home_url( ltrim( $plain, '/' ) );
				}
			}

			foreach ( array_slice( isset( $rule['items'] ) ? (array) $rule['items'] : array(), 0, 2 ) as $id ) {
				$link = get_permalink( (int) $id );

				if ( $link ) {
					$urls[] = $link;
				}
			}

			foreach ( isset( $rule['post_types'] ) ? (array) $rule['post_types'] : array() as $type ) {
				$latest = get_posts(
					array(
						'post_type'      => $type,
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);

				if ( $latest ) {
					$urls[] = get_permalink( $latest[0] );
				}
			}
		}

		// A recent blog post, where most "only on these pages" rules save the most.
		$post = get_posts(
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( $post ) {
			$urls[] = get_permalink( $post[0] );
		}

		if ( is_array( $compiled ) && ( ! empty( $compiled['items'] ) || ! empty( $compiled['ids'] ) ) ) {
			$items = array_merge( array_values( isset( $compiled['items'] ) ? (array) $compiled['items'] : array() ), array_values( isset( $compiled['ids'] ) ? (array) $compiled['ids'] : array() ) );

			foreach ( array_slice( $items, 0, 3 ) as $item ) {
				$urls[] = get_permalink( (int) $item['id'] );
			}
		}

		return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, 8 );
	}

	/**
	 * One report row.
	 *
	 * @param array $row Saved row.
	 * @return string
	 */
	public static function row_html( array $row ) {
		$all   = $row['all'];
		$rules = $row['rules'];
		$saved = $all['ms'] > 0 ? max( 0, round( ( $all['ms'] - $rules['ms'] ) / $all['ms'] * 100 ) ) : 0;
		$cells = array(
			array( 'plug', __( 'Plugins', 'disable-plugins-per-page' ), (int) $all['plugins'], (int) $rules['plugins'], '' ),
			array( 'database', __( 'Queries', 'disable-plugins-per-page' ), (int) round( $all['queries'] ), (int) round( $rules['queries'] ), '' ),
			array( 'timer', __( 'Server time', 'disable-plugins-per-page' ), (int) round( $all['ms'] ), (int) round( $rules['ms'] ), ' ms' ),
			array( 'memory-stick', __( 'Memory', 'disable-plugins-per-page' ), round( $all['memory'] / 1048576, 1 ), round( $rules['memory'] / 1048576, 1 ), ' MB' ),
			array( 'file-code', __( 'Scripts and styles', 'disable-plugins-per-page' ), (int) $all['assets'], (int) $rules['assets'], '' ),
		);
		$path  = Disable_Plugins_Per_Page_Rules::url_path( $row['url'] );

		ob_start();
		?>
		<div class="dppp-result" data-dppp-result="<?php echo esc_attr( $row['url'] ); ?>">
			<div class="dppp-result-head">
				<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" class="dppp-result-url"><?php echo esc_html( '' !== $path ? $path : $row['url'] ); ?></a>
				<?php if ( $saved >= 1 ) : ?>
					<span class="dppp-pill is-live">
						<?php
						/* translators: %d: percent less server time. */
						echo esc_html( sprintf( __( '%d%% faster', 'disable-plugins-per-page' ), $saved ) );
						?>
					</span>
				<?php endif; ?>
				<span class="dppp-muted dppp-result-time">
					<?php
					/* translators: %s: how long ago, e.g. "5 mins". */
					echo esc_html( sprintf( __( '%s ago', 'disable-plugins-per-page' ), human_time_diff( (int) $row['time'] ) ) );
					?>
				</span>
				<button type="button" class="button-link dppp-forget" data-dppp-forget="<?php echo esc_attr( $row['url'] ); ?>" aria-label="<?php esc_attr_e( 'Remove this result', 'disable-plugins-per-page' ); ?>"><?php echo Disable_Plugins_Per_Page_Icons::get( 'x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
			</div>
			<div class="dppp-result-grid">
				<?php foreach ( $cells as $cell ) : ?>
					<?php
					$diff = $cell[3] - $cell[2];
					$down = $diff < 0;
					?>
					<div class="dppp-metric">
						<span class="dppp-metric-label"><?php echo Disable_Plugins_Per_Page_Icons::get( $cell[0], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $cell[1] ); ?></span>
						<span class="dppp-metric-values">
							<span class="dppp-metric-before"><?php echo esc_html( $cell[2] . $cell[4] ); ?></span>
							<?php echo Disable_Plugins_Per_Page_Icons::get( 'arrow-right', 13, 'dppp-metric-arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<strong class="dppp-metric-after"><?php echo esc_html( $cell[3] . $cell[4] ); ?></strong>
						</span>
						<?php if ( 0 != $diff ) : // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- int or float. ?>
							<span class="dppp-metric-diff <?php echo $down ? 'is-down' : 'is-up'; ?>"><?php echo esc_html( ( $down ? '−' : '+' ) . abs( round( $diff, 1 ) ) . $cell[4] ); ?></span>
						<?php else : ?>
							<span class="dppp-metric-diff"><?php esc_html_e( 'same', 'disable-plugins-per-page' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $rules['off'] ) ) : ?>
				<?php
				$installed = Disable_Plugins_Per_Page_Rules::installed();
				$names     = array();

				foreach ( (array) $rules['off'] as $file ) {
					$names[] = Disable_Plugins_Per_Page_Rules::name( (string) $file, $installed );
				}
				?>
				<p class="dppp-result-off"><?php echo Disable_Plugins_Per_Page_Icons::get( 'power-off', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( __( 'Turned off:', 'disable-plugins-per-page' ) . ' ' . implode( ', ', $names ) ); ?></span></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
