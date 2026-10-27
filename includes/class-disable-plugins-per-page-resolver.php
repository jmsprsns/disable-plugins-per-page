<?php
/**
 * Works out what a URL is (a product, the front page, a category archive...)
 * before WordPress does, so rules by post type and page type can run before
 * plugins load.
 *
 * It matches the path against the saved rewrite rules the way WP::parse_request()
 * does, then looks the post up by its slug: one indexed query. It's only
 * loaded when a rule needs it; URL patterns and single pages don't.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Disable_Plugins_Per_Page_Resolver', false ) ) :

	/**
	 * URL to page context.
	 */
	final class Disable_Plugins_Per_Page_Resolver {

		/**
		 * Query vars that mean an archive.
		 */
		const ARCHIVE_VARS = array( 'cat', 'category_name', 'tag', 'tag_id', 'author', 'author_name', 'm', 'year', 'monthnum', 'day', 'taxonomy', 'term' );

		/**
		 * Query vars read from the query string, like WordPress does.
		 */
		const PUBLIC_VARS = array( 'p', 'page_id', 'attachment_id', 'pagename', 'name', 'attachment', 'post_type', 's', 'feed', 'cat', 'category_name', 'tag', 'tag_id', 'author', 'author_name', 'm', 'year', 'monthnum', 'day', 'taxonomy', 'term' );

		/**
		 * What a path is.
		 *
		 * @param string $path  Normalised path relative to home ("/about/team/").
		 * @param array  $query The query string's values.
		 * @param array  $maps  'qv' => post type query var => post type,
		 *                      'tax' => taxonomy query vars (from compiling).
		 * @return array 'types' (front, home, singular, archive, search, feed),
		 *               'post_type' and 'id'.
		 */
		public static function resolve( $path, array $query, array $maps ) {
			$qv   = isset( $maps['qv'] ) && is_array( $maps['qv'] ) ? $maps['qv'] : array();
			$tax  = isset( $maps['tax'] ) && is_array( $maps['tax'] ) ? $maps['tax'] : array();
			$vars = self::query_vars( $path, $query, array_merge( array_keys( $qv ), $tax ) );

			// A path that no rule knows is a 404, not the home page.
			if ( ! $vars && '/' !== $path ) {
				return array(
					'types'     => array(),
					'post_type' => '',
					'id'        => 0,
				);
			}

			return self::context( $vars, $qv, $tax );
		}

		/**
		 * The query vars WordPress would get for this request.
		 *
		 * @param string $path   Normalised path.
		 * @param array  $query  Query string values.
		 * @param array  $extra  Extra public query vars (post types, taxonomies).
		 * @return array
		 */
		private static function query_vars( $path, array $query, array $extra ) {
			$request = trim( (string) $path, '/' );
			$vars    = array();

			if ( '' !== $request ) {
				$rules = get_option( 'rewrite_rules' );

				if ( is_array( $rules ) && $rules ) {
					if ( 0 === strpos( $request, 'index.php/' ) ) {
						$request = substr( $request, 10 );
					}

					$vars = self::match_rules( $rules, $request );
				}
			}

			// Query string values win, as in WP::parse_request().
			foreach ( array_merge( self::PUBLIC_VARS, $extra ) as $var ) {
				if ( isset( $query[ $var ] ) && is_scalar( $query[ $var ] ) && '' !== (string) $query[ $var ] ) {
					$vars[ $var ] = (string) $query[ $var ];
				}
			}

			return $vars;
		}

		/**
		 * Find the first rewrite rule that matches, as WordPress does.
		 *
		 * @param array  $rules   Regex => query.
		 * @param string $request Path without slashes at either end.
		 * @return array Query vars.
		 */
		private static function match_rules( array $rules, $request ) {
			$decoded = urldecode( $request );

			foreach ( $rules as $match => $target ) {
				$match = (string) $match;

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken rule from another plugin mustn't stop the request.
				if ( ! @preg_match( '#^' . $match . '#', $request, $matches ) && ! @preg_match( '#^' . $match . '#', $decoded, $matches ) ) {
					continue;
				}

				// A "page" rule only counts if the page exists (verbose page rules).
				if ( preg_match( '/pagename=\$matches\[([0-9]+)\]/', (string) $target, $var_match ) ) {
					$page_path = isset( $matches[ $var_match[1] ] ) ? $matches[ $var_match[1] ] : '';

					if ( '' === $page_path || ! get_page_by_path( $page_path ) ) {
						continue;
					}
				}

				$target = preg_replace( '!^.+\?!', '', (string) $target );
				$target = preg_replace_callback(
					'/\$matches\[(\d+)\]/',
					function ( $m ) use ( $matches ) {
						return isset( $matches[ $m[1] ] ) ? rawurlencode( $matches[ $m[1] ] ) : '';
					},
					$target
				);

				parse_str( $target, $vars );

				return is_array( $vars ) ? $vars : array();
			}

			return array();
		}

		/**
		 * Turn query vars into a page context.
		 *
		 * @param array $vars Query vars.
		 * @param array $qv   Post type query var => post type.
		 * @param array $tax  Taxonomy query vars.
		 * @return array
		 */
		private static function context( array $vars, array $qv, array $tax ) {
			$ctx = array(
				'types'     => array(),
				'post_type' => '',
				'id'        => 0,
			);

			$text = function ( $key ) use ( $vars ) {
				return isset( $vars[ $key ] ) && is_scalar( $vars[ $key ] ) ? trim( (string) $vars[ $key ] ) : '';
			};

			if ( '' !== $text( 's' ) ) {
				$ctx['types'] = array( 'search' );

				return $ctx;
			}

			if ( '' !== $text( 'feed' ) ) {
				$ctx['types'] = array( 'feed' );

				return $ctx;
			}

			$post     = null;
			$singular = true;

			if ( '' !== $text( 'p' ) ) {
				$post = get_post( absint( $text( 'p' ) ) );
			} elseif ( '' !== $text( 'page_id' ) ) {
				$post = get_post( absint( $text( 'page_id' ) ) );
			} elseif ( '' !== $text( 'attachment_id' ) ) {
				$post = get_post( absint( $text( 'attachment_id' ) ) );
			} elseif ( '' !== $text( 'pagename' ) ) {
				$post = get_page_by_path( $text( 'pagename' ) );
			} elseif ( '' !== $text( 'attachment' ) ) {
				$post = get_page_by_path( $text( 'attachment' ), OBJECT, 'attachment' );
			} elseif ( '' !== $text( 'name' ) ) {
				$type = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $text( 'post_type' ) ) );
				$post = get_page_by_path( $text( 'name' ), OBJECT, '' !== $type ? $type : 'post' );
			} else {
				$singular = false;

				foreach ( $qv as $var => $type ) {
					if ( '' !== $text( $var ) ) {
						$post     = get_page_by_path( $text( $var ), OBJECT, (string) $type );
						$singular = true;
						break;
					}
				}
			}

			if ( $singular ) {
				if ( $post instanceof WP_Post ) {
					$ctx['types']     = array( 'singular' );
					$ctx['post_type'] = $post->post_type;
					$ctx['id']        = (int) $post->ID;

					if ( 'page' === get_option( 'show_on_front' ) ) {
						if ( (int) get_option( 'page_on_front' ) === $ctx['id'] ) {
							$ctx['types'][] = 'front';
						} elseif ( (int) get_option( 'page_for_posts' ) === $ctx['id'] ) {
							$ctx['types'][] = 'home';
						}
					}
				}

				return $ctx;
			}

			if ( '' !== $text( 'post_type' ) ) {
				$ctx['types']     = array( 'archive' );
				$ctx['post_type'] = $text( 'post_type' );

				return $ctx;
			}

			foreach ( array_merge( self::ARCHIVE_VARS, $tax ) as $var ) {
				if ( '' !== $text( $var ) ) {
					$ctx['types'] = array( 'archive' );

					return $ctx;
				}
			}

			// Nothing else: the home page (or a paged copy of it).
			$front = (int) get_option( 'page_on_front' );

			if ( 'page' === get_option( 'show_on_front' ) && $front ) {
				$ctx['types']     = array( 'front', 'singular' );
				$ctx['post_type'] = 'page';
				$ctx['id']        = $front;
			} else {
				$ctx['types'] = array( 'front', 'home' );
			}

			return $ctx;
		}
	}

endif;
