=== BMLT Literature Manager ===
Contributors: bmltliteraturemanager
Tags: narcotics anonymous, bmlt, literature, inventory, orders
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 7.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Literature ordering, Area-specific inventory, pricing, special orders, order management and email notifications for Narcotics Anonymous service bodies, integrated with the BMLT.

== Description ==

BMLT Literature Manager lets an Area (or several Areas) take literature orders from groups on a WordPress site.

* Group members choose their meeting from your BMLT Root Server; the plugin works out the Area automatically.
* Each Area keeps its own inventory. Stock indicators, special-order warnings and totals update as items are chosen.
* Prices, stock and totals are always recalculated on the server. Browser values are never trusted.
* Items that are out of stock are clearly marked as special orders everywhere: on the form, the confirmation, the worker screens, the print view and every email.
* Orders are emailed to the literature workers configured for that order's Area only.
* Literature workers manage orders, inventory and the catalog from protected pages or from the WordPress dashboard.
* JSON import/export with validation and preview, a built-in SMTP option, an audit log, privacy exporter/eraser and a status page.

All data (catalog, inventory, orders, history, settings) lives in the WordPress database, in the plugin's own tables and options. Every change is one database transaction: it is saved completely or not at all.

== Installation ==

1. Upload the `bmlt-literature-manager` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate the plugin. Its database tables and roles are created automatically.
3. Go to Literature → BMLT / Areas, enter your Root Server URL (for example `https://bmlt.example.org/main_server`), save, and click Test BMLT Connection.
4. Tick the Areas this site serves and click Save selected Areas.
5. Go to Literature → Settings → Area Configuration and enter literature-worker email addresses for each Area.
6. Add literature under Literature → Items (or import JSON under Literature → Import / Export).
7. Enter starting quantities under Literature → Inventory for each Area.
8. Configure Literature → SMTP if your host's mail is unreliable, then send a test email.
9. Create a public page containing `[bmlt_literature_order]`.
10. Create a private page for workers containing `[bmlt_literature_manager]` and optionally another with `[bmlt_literature_orders]`.

See `docs/ADMIN-GUIDE.md` for the full administrator guide.

== Data storage ==

Since version 7.0 all plugin data is stored in the WordPress database, the standard way for WordPress plugins:

* Catalog, inventory, orders (with their lines and history), the audit log and literature permissions are in the plugin's own tables, `<prefix>bmlt_literature_*` (InnoDB), created and upgraded with WordPress's dbDelta(). Settings, Area email routing, SMTP settings, email templates and the BMLT cache are options named `bmlt_literature_*`.
* Every change runs in a database transaction: an order, its lines, its history entry and the stock it reserves are saved together or not at all, and so are status changes, imports, restores and settings with their audit entry. When anything fails (a refused value, a database error, PHP stopping half-way), everything the change wrote is rolled back.
* Changes wait for each other (up to 15 seconds), so two orders can never reserve the same stock.
* Tools / Status shows the tables, checks that they support transactions (InnoDB), and Verify storage creates anything missing.
* Tools / Status provides full JSON backup download and validated restore (useful for deliberately copying a catalog from staging to production). A restore is one transaction too.
* Staging and production: pushing a staging database to production replaces production's literature data with staging's. Exclude the `bmlt_literature_*` tables and options from database pushes, or copy data on purpose with backup and restore. When the data was created under another site URL, administrators see a warning.
* On a network, each site has its own tables; deleting a site deletes its tables.

Upgrading from version 6 (JSON data folder) or 1.0 (database tables) moves the data into the new tables automatically, in one transaction, the first time WordPress loads after the update. The old data is left untouched: delete the version 6 data folder (it holds requesters' contact details) or the version 1.0 tables from Tools / Status once you have checked the data. A damaged file in the version 6 folder stops the move with an explanation, and nothing is changed.

== Shortcodes ==

= [bmlt_literature_order] =
Public order form. Attributes:

* `area` — a single BMLT service body ID to limit the form to (must be one of the selected Areas).
* `areas` — comma-separated service body IDs. If neither `area` nor `areas` is given, all selected Areas are used.
* `show_prices` — `yes` (default) or `no`. Hides prices and totals on the form and confirmation. Prices are still recorded.
* `show_inventory` — `yes` (default) or `no`. Shows the Special Order flag on items that are special orders for the requester's Area.
* `show_quantities` — removed in 6.0 (ignored).

The form groups literature into tabs, lists special-order items last, and includes a server-checked Review step before the order is submitted.
* `show_descriptions` — `yes` (default) or `no`.
* `title` — optional heading above the form.
* `per_page` — `20` (default), `50`, `100` or `all`: literature items per page. Visitors can change it with the Items per page selector.

Example: `[bmlt_literature_order areas="12,15" show_prices="yes" title="Order literature"]`

= [bmlt_literature_manager] =
Protected literature-worker workspace with Orders, Inventory and Catalog tabs (each shown only to users with the matching capability). Requires login. Attributes:

* `default_tab` — `orders` (default), `inventory` or `catalog`.
* `area` — preselect an Area ID.
* `per_page` — `20` (default), `50`, `100` or `all`: items per page on the Inventory and Literature Catalog tabs.

= [bmlt_literature_orders] =
Protected order history. Shows the last 45 days and a "Load Previous 45 Days" button that loads one older period at a time. Filters for Area, status, special orders, order number / name / email / phone, meeting, date range, and sorting. Requires the `bmlt_lit_view_orders` capability. Attributes:

* `area` — preselect an Area ID.

== Capabilities ==

* `bmlt_lit_view_orders` — view orders and order history.
* `bmlt_lit_manage_orders` — change status, add notes, resend notifications, email requesters.
* `bmlt_lit_manage_inventory` — adjust Area inventory.
* `bmlt_lit_manage_catalog` — add/edit/deactivate literature items, import/export.
* `bmlt_lit_manage_settings` — BMLT, Areas, SMTP, settings and tools.

Administrators always have every capability. Two roles are created: Literature Worker (orders + inventory) and Literature Chair (orders + inventory + catalog). Users can also be restricted to specific Areas under Literature → Settings → Permissions.

== Frequently Asked Questions ==

= What happens if the BMLT server is down? =
The last successfully cached Areas and meetings continue to be used, so ordering keeps working. Administrators see a notice that cached data is in use.

= Does deactivating the plugin delete orders? =
No. Data is only removed on uninstall, and only if "Delete plugin data when uninstalling" was turned on under Settings → Advanced.

= Will pushing staging to production erase orders? =
A database push copies the plugin's tables and options like any other WordPress data. Exclude the `bmlt_literature_*` tables and options from the push (most hosts let you choose tables), or push nothing and copy a catalog or configuration with Tools / Status → backup and restore.

= Can I copy my catalog from staging to production on purpose? =
Yes: use Literature → Import / Export (catalog JSON) or Tools / Status → full backup and restore.

= Can I keep the SMTP password out of the database? =
Yes. Add `define( 'BMLT_LIT_SMTP_PASSWORD', 'your-password' );` to wp-config.php. When defined it is used instead of any stored value. A stored password is encrypted with the site's security keys, so it must be re-entered if the database is moved to a server with different keys.

= Can I add a CAPTCHA? =
Yes. Render your widget with the `bmlt_lit_order_form_captcha` action and validate it with the `bmlt_lit_validate_captcha` filter (return true, or a WP_Error to block).

= Does page caching affect the order form? =
Exclude the order page from full-page caching so that each visitor gets a fresh security token. If a token expires the form is re-shown with the visitor's entries preserved.

== Developer hooks ==

* `bmlt_lit_order_created` (action, $order_id)
* `bmlt_lit_order_status_changed` (action, $order_id, $old_status, $new_status)
* `bmlt_lit_worker_recipients` (filter, $recipients, $order)
* `bmlt_lit_client_ip` (filter, $ip) — supply the real client IP behind a trusted proxy.
* `bmlt_lit_data_dir` (filter, $dir) — where to look for the version 6 data folder when upgrading.
* `bmlt_lit_lock_wait` (filter, $seconds) — how long a change waits for another one (default 15).
* `bmlt_lit_order_form_captcha` (action) and `bmlt_lit_validate_captcha` (filter, $valid, $post).

== Changelog ==

= 7.0.0 =
* Storage: all data moved from JSON files to the WordPress database — the plugin's own InnoDB tables (created with dbDelta) and options. Upgrading moves version 6 data automatically, in one transaction; the old folder is kept until you delete it from Tools / Status.
* Every change is one database transaction, saved completely or rolled back: orders with their lines, history and stock; status changes; catalog and inventory imports (a failure half-way no longer leaves part of an import); backup restores; settings and permissions together with their audit entries. Any failed query aborts the change.
* Network sites: deleting a site deletes its literature tables.
* BMLT_LIT_DATA_DIR is now only used to find the version 6 data folder when upgrading; BMLT_LIT_LOCK_METHOD is no longer used. File snapshots and the order-index rebuild tool are gone (no longer needed): download a backup before restoring if you may want the current data back.

= 6.3.0 =
* Security: previous versions of data files are now saved as *.bak.json.php instead of *.json.php.bak, which nginx served as plain text (order details). Existing files are renamed automatically.
* Privacy: erasing a requester's data also removes it from previous-version copies and backup snapshots.
* Fix: with a page cache, a second visitor's order could be discarded and the first visitor's confirmation shown. Duplicates are now recognised by content, and order-form pages are marked not to be cached.
* Fix: uninstalling on a multisite network no longer deletes other sites' data when the main site opted in.
* Fix: import preview counts items with nested fields as invalid; backup restore drops negative Area ids; clearer message for a refused Root Server URL.

= 6.2.0 =
* Review page: quantities are editable, with an Update totals button (or Enter) that re-prices the order on the server; 0 removes an item, and a notice appears until totals are updated.
* Order form: an All tab, shown first and selected by default, lists every group together.
* Order form: the Item, Price, Special order and Quantity columns are sortable (ascending, descending, then back to the default order); names sort naturally; on narrow screens the headings become a Sort by row.

= 6.1.0 =
* Area inventory JSON import/export now carries each item's special-order mark ("special": true/false), so an Area that doesn't track quantities can import its special-order list in one step; the import preview shows the mark.

= 6.0.0 =
* Inventory tracking is now optional per Area (Settings → Area Configuration). Areas that don't track quantities mark items as special orders from Literature → Inventory, the worker Inventory tab, or each item's edit page (Special order by Area); their orders never change stock counts.
* Order form groups literature into tabs (one per group), with search across all groups.
* Special-order items are sorted to the bottom of the order form, the Inventory lists, the review page, confirmations, emails, printouts and order screens.
* New Review order step: the server checks the order and recalculates prices and availability before the requester submits; Edit order keeps all entries, and the review is shown again if anything changed.
* Simplified order form rows: item and description, price, Special Order flag and quantity. The show_quantities attribute is removed (ignored).
* Starter catalog: medallions are now one item per year from 1 to 40 for bronze (BRZ-01..40), laser-etched (7501-7540) and triplate (TRI-01..40), each in its own group; replaces the three general medallion items.

= 5.2.0 =
* New Reference link field for literature items (admin, worker catalog, REST, JSON import/export and backups); the order form shows it as a "View on <site>" link that opens in a new tab.
* Starter NAWS catalog adds official na.org reference links for 79 items: reading pages for every booklet and informational pamphlet, plus the relevant na.org pages for service materials, daily-meditation books, group reading cards and the ASL booklet.

= 5.1.0 =
* Pagination for literature lists on [bmlt_literature_order] and the Inventory and Literature Catalog tabs of [bmlt_literature_manager]: Items per page selector (20, 50, 100 or All), item count, and Previous / page / Next controls above and below the list.
* New per_page shortcode attribute (20, 50, 100 or all) on both shortcodes; each visitor's chosen page size is remembered in their browser.
* Order form keeps quantities from other pages in the totals and the submitted order, and shows a note listing chosen items on other pages.


= 5.0.0 =
* Version numbering restarted at 5.0.0; every plugin file now carries its version and the download is named bmlt-literature-manager-5.0.0.zip.
* Includes all features to date: JSON file storage (staging/production safe), BMLT integration, Area-specific inventory and email routing, special orders, order management, JSON import/export, full backup/restore, SMTP, privacy tools.
* Worker Inventory tab uses labelled fields in a flexible grid with alternating row colours; fits any theme width.
* CSS/JS links change whenever files change, so browsers and caching plugins never serve stale files.

= 2.0.1 =
* Worker Inventory tab rebuilt as labelled form fields in a flexible grid (no table) with alternating row colours; fits any theme width.
* Filter bar can no longer force horizontal page scrolling on narrow themes.
* CSS/JS URLs now change whenever the files change, so browsers and caching plugins never serve stale styles after an update.

= 2.0.0 =
* All data moved from database tables/options to locked, atomic JSON files in a protected data directory (configurable with BMLT_LIT_DATA_DIR).
* Literature permissions stored in the data directory and applied through a capability filter.
* Automatic migration from 1.0 database storage; legacy tables kept until explicitly removed.
* Full backup download and validated restore; automatic snapshots; order-index rebuild tool.
* Warning when the data directory was created by a different site URL (e.g. copied from staging).
* Separate data directory per site on multisite.

= 1.0.0 =
* Initial release.
