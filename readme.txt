=== Kreativ Free Fonts ===
Contributors: kreativ
Tags: fonts, google fonts, seo, importer, zip
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports open-source Google Fonts locally, bundles mandatory OFL licensing, generates ZIP packages, and publishes SEO-ready WordPress posts.

== Description ==

Kreativ Free Fonts is built for font content websites that need a repeatable, compliant import workflow. It fetches font families from the Google Fonts Developer API, downloads local font assets, stores the mandatory OFL license file, creates ZIP packages, and publishes search-optimized posts automatically.

Key features:

* Google Fonts API integration with transient caching
* Local storage in `wp-content/uploads/kreativ-fonts/`
* Mandatory `OFL.txt` creation in every font package
* `metadata.json` generation for downstream integrations
* ZIP package generation for direct download links
* SEO-oriented WordPress post publishing with richer landing-page sections
* Live front-end font preview with editable sample text
* JSON-LD schema and social meta output for imported font posts
* Automatic branded featured image generation for imported posts
* Duplicate prevention via a dedicated database table
* WP-Cron support every 6 hours
* Admin logs viewer and manual import trigger
* Secure settings handling, nonces, sanitization, and capability checks

== Installation ==

1. Upload the `kreativ-font-ingestor` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `Free Fonts` in the WordPress admin menu.
4. Add a valid Google Fonts Developer API key.
5. Configure the import limit and cron preference.
6. Run a manual import or allow WP-Cron to ingest fonts automatically.

== Deployment ==

The repository can be deployed directly to a WordPress server with the bundled GitHub Actions workflow in `.github/workflows/deploy-wordpress.yml`.

Required GitHub Actions secrets:

* `WP_SSH_HOST` - SFTP/SSH hostname for the WordPress server
* `WP_SSH_PORT` - SFTP port, usually `22`
* `WP_SSH_USER` - SFTP username used for deployment
* `WP_SSH_PASSWORD` - SFTP password for the deployment user
* `WP_REMOTE_PATH` - absolute path to the live plugin directory, for example `/var/www/html/wp-content/plugins/kreativ-font-ingestor`

The workflow runs on every push to `main`, lints PHP files, builds a clean deploy package, uploads it as an artifact, and then mirrors the plugin into the configured WordPress plugin directory over SFTP.

== Frequently Asked Questions ==

= Which fonts are imported? =

The plugin imports font families returned by the Google Fonts Developer API. It keeps original family names and file distributions intact.

= Does the plugin include license files? =

Yes. Every downloaded family folder and ZIP package includes `OFL.txt`, along with license and attribution information in the generated post content.

= Where are files stored? =

All files are stored in `wp-content/uploads/kreativ-fonts/`. ZIP packages are stored in the `packages/` subdirectory, and logs are written to `logs.txt`.

= How are duplicates prevented? =

Imported families are recorded in a dedicated WordPress database table with a unique slug constraint. Existing families are skipped on subsequent runs.

= Can I monetize the generated posts? =

Yes. The plugin includes an affiliate placeholder block in the post template and an admin setting for optional affiliate HTML.

== Changelog ==

= 1.0.5 =

* Improved generated font landing-page layout with a stronger hero section
* Added denser specimen preview modes and applied showcased fonts across specimen surfaces
* Removed empty placeholder sections from rendered font pages

= 1.0.4 =

* Switched font ingestion batches from alphabetical order to randomized eligible selection
* Added configurable hierarchical category parent names for imported font classification

= 1.0.3 =

* Fixed live preview font format handling for generated font pages
* Added inline featured image rendering at the top of generated post content

= 1.0.2 =

* Added branded featured image generation for imported font posts
* Added live front-end preview with editable sample text
* Expanded generated landing-page content and package metadata
* Added schema/meta output for imported font posts
* Hardened licensing verification and import flow behavior

= 1.0.0 =

* Initial release
* Google Fonts API ingestion with local downloads
* OFL license bundling and metadata generation
* ZIP packaging and SEO post creation
* Admin UI, logging, cron imports, and duplicate prevention
