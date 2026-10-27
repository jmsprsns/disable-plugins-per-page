# Disable Plugins Per Page

Turn plugins off on the pages that don't need them: rules by page type, post type, chosen pages, shortcode or block, URL pattern or device, admin rules, per-page control in the editor, a test mode, safe recovery, and before-and-after numbers. Free, with no Pro tier.

It's a free replacement for Freesoul Deactivate Plugins, which moved features behind its Pro version. It pairs with [Plugin Speed Report](https://github.com/jmsprsns/plugin-speed-report): the report finds the slow plugin, this one turns it off where it isn't needed.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## How it works

Activation writes a short must-use plugin, `wp-content/mu-plugins/0-disable-plugins-per-page.php`. WordPress runs must-use plugins before regular ones, so the loader can hook `option_active_plugins` before `wp_get_active_and_valid_plugins()` reads the list. That's the only way to stop a plugin from running at all, rather than removing its scripts after it has already loaded.

- **The filter** (`includes/class-disable-plugins-per-page-filter.php`) works out the decision once per request, on the first read of the list after `muplugins_loaded`. On a normal page view it reads two autoloaded options (`disable_plugins_per_page_compiled` and `disable_plugins_per_page_state`) and writes nothing.
- **Compiled rules.** The settings page edits `disable_plugins_per_page_settings` (not autoloaded). Every save, and every change the rules depend on (a post saved, a slug or permalink change, a plugin activated), recompiles it into the small autoloaded list the filter reads: chosen pages and "content uses" rules become exact paths, screen groups become admin file names, and each page's own editor settings are keyed by its path. Compiles triggered by posts run once at `shutdown`, so bulk edits do one.
- **What a URL is.** Rules by post type or page type need to know what a URL is before WordPress parses the request. `includes/class-disable-plugins-per-page-resolver.php` matches the path against the saved `rewrite_rules` the way `WP::parse_request()` does (with the verbose page check), then finds the post with `get_page_by_path()`. Custom post type and taxonomy query vars are captured when compiling, because those types aren't registered yet when the filter runs. The resolver only loads when a rule needs it.
- **URL patterns** use `*` as the only wildcard, matched in linear time without regular expressions. Paths are compared decoded, lower case, relative to the site's home, with a trailing slash; paged copies (`/page/2/`, `/comment-page-2/`, `/2/`) count as the page.
- **Add-ons go with their parent.** When compiling, each active plugin's `Requires Plugins` header, WooCommerce and Elementor extension headers (`WC requires at least`, `Woo`, `Elementor tested up to`) and names like "Stripe for WooCommerce" or "WooCommerce Subscriptions" give a map of add-on to parent. A plugin whose parent is off is turned off too.
- **Testing.** Rules and page settings in testing apply only to browsers with the admin cookie: `user|expiry|HMAC-SHA256` signed with `disable_plugins_per_page_secret`, set (HttpOnly, SameSite=Lax) for users who can `activate_plugins` on login and admin visits, cleared on logout. It's needed because pluggable functions, and so the login check, aren't loaded when the filter runs. The same cookie lets an admin load any page with every plugin (`?dppp_all=1`, from the admin bar).

### Never filtered

AJAX, the REST API (`/wp-json/` and `?rest_route=`), WP-Cron (including `?doing_wp_cron`), WP-CLI, XML-RPC, installs, any request that isn't GET or HEAD, previews, the Customizer and front-end page builders, WordPress recovery mode, front-end requests not routed through `index.php` (login, sign-up, activation, trackbacks), the network admin, URLs over 2,048 characters, and these admin screens: `plugins.php`, `plugin-install.php`, `plugin-editor.php`, `update.php`, `update-core.php`, `upgrade.php`, `site-health.php`, `options.php`, `admin-post.php`, `async-upload.php`, `media-upload.php` and this plugin's own page.

### Safety

- **Saving the list.** On a request that turned plugins off, `pre_update_option_active_plugins` puts back any plugin missing from the new value (in its original place), so a plugin that saves the list it read, or an add-on that deactivates itself because its parent "isn't active", can't make a per-page change permanent. Real deactivations happen on screens and requests that are never filtered.
- **Recovery link**: `/wp-admin/index.php?dppp_recovery=<32 random characters>`. It's checked with `hash_equals()` before anything else, sets the pause flag in the state option, then sends the admin to the Safety tab. wp-admin is never page-cached, and the check runs before the login redirect.
- **Safe mode**: `define( 'DISABLE_PLUGINS_PER_PAGE_SAFE_MODE', true );` in wp-config.php. Deleting the loader file has the same effect.
- **Crash guard**: a request that turned plugins off registers a shutdown function. If the request ends in a fatal error (other than running out of memory or time), the rules and page settings that turned plugins off are added to the paused lists in the state option, with the page and the error's first line. Each rule is written once, so repeated crashes cause no repeated writes.
- **Who can change it**: everything needs `activate_plugins`, including `options.php` (through `option_page_capability_disable-plugins-per-page`), the AJAX handlers, the admin-post links, and the editor's REST route (which also needs `edit_post`).

### Before and after

"Check a page" runs the same evaluation as the filter for a URL, without loading it. Measuring makes loopback requests (`wp_safe_remote_get`, this site's own host only, no redirects) with `?dppp_measure=<token>`: 32 random characters, of which only the SHA-256 hash is stored, for 60 seconds, and deleted by the request that uses it. That request skips the rules ("every plugin") or applies them including rules in testing ("rules"), sets `DONOTCACHEPAGE`, and at shutdown stores its server time (from `$timestart`), query count, peak memory and plugin count for the admin's AJAX call to read. Four requests per mode alternate; the first pair warms caches and the median of the rest is kept, with scripts and styles counted from the returned HTML.

## Automatic updates

Updates come from https://github.com/jmsprsns/disable-plugins-per-page through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `disable-plugins-per-page.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `disable-plugins-per-page.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'DISABLE_PLUGINS_PER_PAGE_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'DISABLE_PLUGINS_PER_PAGE_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
disable-plugins-per-page.php                       Plugin header, constants, boot, activation/deactivation
uninstall.php                                      Deletes options, page settings, transients and the loader
readme.txt                                         WordPress readme; feeds the "View details" popup
includes/
  class-disable-plugins-per-page-filter.php        Runs from the must-use loader: decides and filters each request
  class-disable-plugins-per-page-resolver.php      URL to post type / page type, before WordPress parses the request
  class-disable-plugins-per-page-rules.php         Cleaning, compiling, add-on detection, summaries, starter rules
  class-disable-plugins-per-page-loader.php        Writes, checks and removes the must-use loader
  class-disable-plugins-per-page-plugin.php        Main singleton, settings, secrets, recompile triggers, cache purge
  class-disable-plugins-per-page-admin.php         Content Powered > Disable Plugins Per Page page and Plugins-screen link
  class-disable-plugins-per-page-editor.php        "Plugins on this page" panel, classic meta box, REST route
  class-disable-plugins-per-page-measure.php       Check a page, before-and-after measuring
  class-disable-plugins-per-page-toolbar.php       Admin cookie and admin bar menu
  class-disable-plugins-per-page-menu.php          Shared top-level "Content Powered" menu
  class-disable-plugins-per-page-feedback.php      Shared Submit Feedback and Report a Bug pages
  class-disable-plugins-per-page-updater.php       GitHub update checks (PUC wiring)
  class-disable-plugins-per-page-icons.php         Inline Lucide icon helper
assets/
  admin.css, admin.js                              Loaded only on the settings page
  editor.js, editor.css                            The block editor panel (no build step)
  icon.svg                                         Icon on the update screens
  content-powered.svg                              Sumo logo for the Content Powered menu
icons/                                             The Lucide icons it uses (plus LICENSE)
plugin-update-checker/                             Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-disable-plugins-per-page-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-disable-plugins-per-page-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icon set in `icons/`. Print one inline with:

```php
echo Disable_Plugins_Per_Page_Icons::get( 'package', 20 );           // name, size in px
echo Disable_Plugins_Per_Page_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
