<?php
/**
 * Plugin Name:       Disable Plugins Per Page
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Turn plugins off on the pages that don't need them. Rules by page, post type, URL pattern or device, admin rules, a test mode, safe recovery, and before-and-after numbers. Free, with no Pro tier.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       disable-plugins-per-page
 * Update URI:        https://github.com/jmsprsns/disable-plugins-per-page
 *
 * @package Disable_Plugins_Per_Page
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$disable_plugins_per_page_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'DISABLE_PLUGINS_PER_PAGE_VERSION', $disable_plugins_per_page_headers['version'] );
define( 'DISABLE_PLUGINS_PER_PAGE_REPO', $disable_plugins_per_page_headers['repo'] );
define( 'DISABLE_PLUGINS_PER_PAGE_FILE', __FILE__ );
define( 'DISABLE_PLUGINS_PER_PAGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DISABLE_PLUGINS_PER_PAGE_URL', plugin_dir_url( __FILE__ ) );
define( 'DISABLE_PLUGINS_PER_PAGE_BASENAME', plugin_basename( __FILE__ ) );

unset( $disable_plugins_per_page_headers );

// The filter is normally already loaded by the must-use loader; this makes
// its helpers available when the loader is missing.
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-filter.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-icons.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-updater.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-menu.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-feedback.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-admin.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-plugin.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-loader.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-rules.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-toolbar.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-editor.php';
require_once DISABLE_PLUGINS_PER_PAGE_DIR . 'includes/class-disable-plugins-per-page-measure.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Disable_Plugins_Per_Page_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Disable_Plugins_Per_Page_Plugin
 */
function disable_plugins_per_page_plugin() {
	return Disable_Plugins_Per_Page_Plugin::instance();
}
add_action( 'plugins_loaded', 'disable_plugins_per_page_plugin' );

/**
 * On activation: create the secrets, install the must-use loader and
 * compile whatever rules were saved before.
 *
 * @return void
 */
function disable_plugins_per_page_activate() {
	Disable_Plugins_Per_Page_Plugin::ensure_secrets();
	Disable_Plugins_Per_Page_Loader::ensure();
	Disable_Plugins_Per_Page_Rules::compile();
}
register_activation_hook( DISABLE_PLUGINS_PER_PAGE_FILE, 'disable_plugins_per_page_activate' );

/**
 * On deactivation: remove the must-use loader, so no rule runs while the
 * plugin is off. Settings stay (uninstall.php removes them).
 *
 * On multisite the loader is shared by every site and does nothing where
 * the plugin isn't active, so it stays until the plugin is deleted.
 *
 * @return void
 */
function disable_plugins_per_page_deactivate() {
	if ( ! is_multisite() ) {
		Disable_Plugins_Per_Page_Loader::remove();
	}
}
register_deactivation_hook( DISABLE_PLUGINS_PER_PAGE_FILE, 'disable_plugins_per_page_deactivate' );
