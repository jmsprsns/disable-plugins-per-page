=== Disable Plugins Per Page ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: performance, speed, plugins, plugin manager, deactivate plugins
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn plugins off on the pages that don't need them. Rules by page, post type, URL pattern or device, admin rules, a test mode, safe recovery, and before-and-after numbers. Free, with no Pro tier.

== Description ==

Every active plugin loads on every page of your site, whether that page uses it or not. WooCommerce loads on your blog posts. Your contact form plugin loads on every product page. A slider loads everywhere because one page has a slider.

Disable Plugins Per Page stops plugins from loading where they aren't needed. It isn't a script remover: it runs before any other plugin loads and takes the unneeded ones out of the list WordPress is about to load, so their code, database queries, scripts and styles never run on that page at all.

It's completely free. Every feature is in this plugin and there's no Pro version to upgrade to.

= Rules =

Each rule picks one or more plugins and either turns them off on some pages, or loads them only on some pages and turns them off everywhere else. A rule can match:

* **Kinds of page**: the home page, the blog page, archives, search results
* **Post types**: every product, every blog post, every page
* **Chosen pages**: search by title, ID or URL
* **Pages whose content uses a shortcode or block**: for example "Contact Form 7 only where a form is placed". The list updates whenever a page is saved, and includes pages that use a synced pattern with the form in it
* **URL patterns**: `/shop/*`, `/cart/`, full URLs work too
* **Devices**: phones and tablets only, or computers only

For example: "Only load WooCommerce on /shop/, /cart/, /checkout/, /my-account/ and product pages".

= Per-page control in the editor =

Open any post or page and use the **Plugins on this page** panel in the editor sidebar. It shows what the rules do on that page, and lets you turn a plugin off just there, or keep one loading there when a rule turns it off. The classic editor gets the same controls in a box.

= Admin rules =

Heavy plugins slow down admin screens too. Admin rules keep a plugin from loading on screens that don't use it: the Dashboard, the post editor, Media, Comments, Users, Settings, or any screen by its URL. The Plugins, Updates and Site Health screens and the settings page itself always load every plugin, so you can always undo a rule.

= Test before visitors see it =

Every rule starts in testing: it only applies to administrators. Browse your site, check a few pages, then set the rule live. The admin bar shows how many plugins are off on the page you're looking at and why, with a link to load the same page with every plugin to compare.

= Safe recovery =

* **Recovery link**: a private link that pauses every rule straight away, before any plugin loads, even if you can't sign in.
* **Safe mode**: add `define( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE', true );` to wp-config.php and no rule runs.
* **Crash guard**: if a page crashes while plugins are turned off (a theme calling WooCommerce without checking it's there, say), the rules involved are paused automatically, so the next visitor gets a working page, and you're shown the page and the error.
* **Add-ons go with their parent**: turn WooCommerce off on a page and its extensions are turned off there too, so nothing calls a plugin that isn't loaded.
* **Nothing is deactivated for real**: a page that loads fewer plugins can never save a shorter active plugins list.

= Never filtered =

Some requests always load every plugin, because plugins need them wherever they're used: AJAX, the REST API, WP-Cron, WP-CLI, XML-RPC, form posts and anything else that isn't a plain page view, the login screen, previews, the Customizer, and page builders' editors (Elementor, Divi, Beaver Builder, Bricks, Oxygen and others). So adding to cart, submitting a form or saving a post always works.

= Starter rules =

Ready-made rules for common plugins, shown when they're active: WooCommerce only on shop pages, Contact Form 7, WPForms, Gravity Forms, Ninja Forms, Formidable and Fluent Forms only where a form is placed, and Slider Revolution, Smart Slider 3, MetaSlider and LayerSlider only where a slider is placed. They're added in testing.

= Before and after =

The Before & after tab loads a page from your server with every plugin, then with your rules, a few times each, and shows the difference: plugins loaded, database queries, server time, memory, and scripts and styles on the page. "Check a page" shows instantly which plugins are off on any URL, for visitors on a computer, on a phone, and for you with rules still in testing.

Not sure which plugins are slow? Plugin Speed Report, also free from Content Powered, measures each plugin on your key pages. It finds the slow plugin; this one turns it off where it isn't needed.

Find it under Content Powered > Disable Plugins Per Page in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `disable-plugins-per-page` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress. It adds a small loader file to `wp-content/mu-plugins/` so it can run before other plugins.
3. Go to Content Powered > Disable Plugins Per Page, add a rule or a starter rule, check a few pages while it's in testing, then set it live.
4. Save the recovery link from the Safety tab somewhere safe.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= How is this different from removing a plugin's scripts? =

Asset managers remove a plugin's CSS and JavaScript after the plugin has already loaded and run. This plugin stops the plugin from loading at all on that page, so its PHP code, database queries, scripts and styles all go. It works through a must-use plugin, the only kind of code WordPress runs before regular plugins.

= What if a rule breaks my site? =

Open the recovery link from the Safety tab: it pauses every rule before any plugin loads, even when you're signed out. Or add `define( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE', true );` to wp-config.php, or delete `wp-content/mu-plugins/0-disable-plugins-per-page.php`. If a page crashes because a plugin is missing, the crash guard pauses the rules involved by itself.

= Will forms, carts and checkouts still work? =

Yes. Form posts, AJAX, the REST API and cron jobs always load every plugin, wherever they come from. Adding to cart from any page, submitting a contact form and the block checkout all keep working even when the plugin is off for page views there.

= Does it work with page caching? =

Yes. Cached pages were built with the rules that applied when they were cached, so the plugin clears the page cache of popular caching plugins when the rules visitors get change (you can turn that off). Device rules need a cache that keeps separate copies for phones and computers.

= Which plugins shouldn't I turn off? =

Leave security plugins (firewalls, login protection) and your caching plugin on everywhere. Plugins that add something to every page, like an SEO plugin's meta tags or a cookie banner, should usually stay on for the front end. Test a rule before setting it live, and use "Check a page" to see its effect.

= Does it deactivate plugins? =

No. Your active plugins stay active. The rules only decide, for each page view, which of them load. Deactivate this plugin and every plugin loads everywhere again.

= Does it work on multisite? =

Yes, per site. Network-activated plugins aren't turned off.

= Is there a Pro version? =

No. Everything is free.

== Changelog ==

= 1.0.0 =
* First release: front-end and admin rules by page type, post type, chosen pages, shortcode or block, URL pattern and device; per-page control in the editor; testing for administrators; recovery link, safe mode and crash guard; add-ons turned off with their parent; starter rules; check a page; before-and-after numbers.

See https://github.com/jmsprsns/disable-plugins-per-page/releases for the full release history.
