<?php
/**
 * The must-use loader: a short file in wp-content/mu-plugins that starts the
 * filter before any regular plugin loads. That's the only point where a
 * plugin can be stopped from running at all, rather than having its scripts
 * hidden after it has already loaded.
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installs, checks and removes the loader file.
 */
final class Disable_Plugins_Per_Page_Loader {

	/**
	 * Loader file name. The leading 0 sorts it first among must-use plugins.
	 */
	const FILE = '0-disable-plugins-per-page.php';

	/**
	 * Line that marks the file as ours. Nothing without it is ever deleted.
	 */
	const MARKER = '@disable-plugins-per-page-loader';

	/**
	 * Full path of the loader file.
	 *
	 * @return string
	 */
	public static function path() {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	/**
	 * What the loader file should contain. Fixed text: the only variable
	 * parts are this plugin's own folder and version, written with var_export().
	 *
	 * @return string
	 */
	public static function contents() {
		$folder = basename( DISABLE_PLUGINS_PER_PAGE_DIR );

		return '<?php
/**
 * Plugin Name: Disable Plugins Per Page (loader)
 * Description: Lets Disable Plugins Per Page turn plugins off before they load. Added and removed by Disable Plugins Per Page. To stop every rule at once, delete this file or add define( \'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE\', true ); to wp-config.php.
 * Author:      Content Powered
 * Author URI:  https://www.contentpowered.com/wordpress-plugins/
 * Version:     ' . DISABLE_PLUGINS_PER_PAGE_VERSION . '
 *
 * ' . self::MARKER . '
 */

defined( \'ABSPATH\' ) || exit;

if ( ! ( defined( \'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE\' ) && DISABLE_PLUGINS_PER_PAGE_SAFE_MODE ) ) {
	$disable_plugins_per_page_filter = WP_PLUGIN_DIR . \'/\' . ' . var_export( $folder, true ) . ' . \'/includes/class-disable-plugins-per-page-filter.php\'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

	if ( is_readable( $disable_plugins_per_page_filter ) ) {
		require_once $disable_plugins_per_page_filter;
		Disable_Plugins_Per_Page_Filter::boot( ' . var_export( $folder . '/disable-plugins-per-page.php', true ) . ' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}

	unset( $disable_plugins_per_page_filter );
}
';
	}

	/**
	 * Whether the site lets plugins write files.
	 *
	 * @return bool
	 */
	public static function can_write() {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	/**
	 * Current state: installed, outdated, missing, foreign (a file of the
	 * same name that isn't ours) or blocked (file changes turned off).
	 *
	 * @return string
	 */
	public static function status() {
		$path = self::path();

		if ( ! file_exists( $path ) ) {
			return self::can_write() ? 'missing' : 'blocked';
		}

		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		if ( false === strpos( $current, self::MARKER ) ) {
			return 'foreign';
		}

		return self::contents() === $current ? 'installed' : 'outdated';
	}

	/**
	 * Install or refresh the loader when it's missing or out of date.
	 *
	 * @return bool True when the current loader is in place afterwards.
	 */
	public static function ensure() {
		$status = self::status();

		if ( 'installed' === $status ) {
			return true;
		}

		if ( 'missing' !== $status && 'outdated' !== $status ) {
			return false;
		}

		if ( ! self::can_write() || ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! is_writable( WPMU_PLUGIN_DIR ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return false;
		}

		$written = file_put_contents( self::path(), self::contents(), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixed content, our own file.

		if ( false === $written ) {
			return false;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( self::path(), true );
		}

		return 'installed' === self::status();
	}

	/**
	 * Remove the loader, but only if the file is ours.
	 *
	 * @return void
	 */
	public static function remove() {
		$path = self::path();

		if ( ! file_exists( $path ) ) {
			return;
		}

		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		if ( false !== strpos( $current, self::MARKER ) ) {
			wp_delete_file( $path );

			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $path, true );
			}
		}
	}
}
