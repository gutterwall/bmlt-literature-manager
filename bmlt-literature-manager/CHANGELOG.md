# Changelog

All notable changes to BMLT Literature Manager.

## 7.0.0 — 2026-10-22

Storage moves to the WordPress database:

- All data is now in the WordPress database, the standard way for plugins: catalog, inventory, orders (with their lines and history in `order_items` and `order_notes`), the audit log and literature permissions in the plugin's own InnoDB tables (`<prefix>bmlt_literature_*`, created and upgraded with `dbDelta()`), and settings, templates, SMTP settings and the BMLT cache as `bmlt_literature_*` options. On a network each site has its own tables, and deleting a site deletes them.
- Every change is one database transaction, saved completely or rolled back: an order with its lines, history and reserved stock; a status change and its stock effect; a whole catalog or inventory import (a failure half-way used to leave the items before it); a backup restore; every settings or permissions change together with its audit entry (the entry used to be written separately). Any failed query aborts the change, nested steps are savepoints, settings written by a change that is rolled back never reach WordPress's option cache, and a transaction left open by PHP stopping is rolled back.
- Changes take a store-wide lock row and wait up to 15 seconds for each other (`bmlt_lit_lock_wait` filter), so two orders can never reserve the same stock.
- Order and item numbers come from sequences that are never reused, also after a restore.
- Upgrading from version 6 (or 2–5) moves the JSON data folder into the database automatically, in one transaction: settings and SMTP password, catalog, inventory, permissions, orders with their history, the audit log and the last BMLT answers. A damaged file is read from its `.bak` copy; if both are unreadable the move stops, administrators are told which file, and nothing changes. The folder is left untouched until it is deleted with **Tools / Status → Delete the old data folder**; until then the privacy eraser points out that it may hold the person's data. Upgrading from 1.0 moves its tables the same way.
- Backups keep the same file format, so 6.x backups restore into 7.0 and the other way round.
- Tools / Status shows the tables, their size, whether each supports transactions (InnoDB) and any database error; **Verify storage** creates missing tables and columns. The order-index rebuild and the file snapshots are gone (nothing to rebuild; a restore that fails changes nothing — download a backup before restoring if you may want the current data back).
- `BMLT_LIT_DATA_DIR` and the `bmlt_lit_data_dir` filter now only locate the version 6 folder for the upgrade; `BMLT_LIT_LOCK_METHOD` is no longer used.
- Staging and production: a database push now carries the literature data. Exclude the `bmlt_literature_*` tables and options from pushes, or copy data on purpose with backup and restore. The "created by another site" warning now refers to the database.

## 6.3.0 — 2026-10-22

Security and privacy:

- Previous versions of data files were saved as `*.json.php.bak`. Web servers that ignore `.htaccess` (nginx) serve such files as plain text, so order files with requesters' names, emails and phone numbers could be downloaded. They are now saved as `*.bak.json.php` (temporary files as `*.tmp-*.json.php`), which never show anything over the web. Existing `.bak` files are renamed automatically after the update, and by **Tools / Status → Verify storage**.
- Erasing a requester's personal data (Tools → Erase Personal Data) now also removes it from the previous-version copies and from the backup snapshots in the data folder.

Fixes:

- When a page cache served several visitors the same copy of the order form, the second visitor's order was discarded as a duplicate and that visitor was shown the first visitor's confirmation, contact details included. Duplicate submissions are now recognised by what was ordered and by whom, and pages with the order form tell caching plugins (`DONOTCACHEPAGE`) and browsers not to store them.
- Uninstalling on a multisite network deleted every site's literature data when the main site had chosen "Delete plugin data when uninstalling"; the other sites' data now follows their own setting.
- Import preview counted an item with a nested (non-simple) field as an error but not as invalid.
- Restoring a backup no longer turns a negative Area id into a real one.
- A refused Root Server URL now says why (it must be a public address on port 80, 443 or 8080) and how to allow a private one.
- README: the starter catalog has 294 items (174 plus 3 × 40 medallions), not 177.

## 6.2.0 — 2026-10-08

- Review page: quantities are editable, with an Update totals button (or Enter) that re-prices the order on the server; 0 removes an item, and a notice appears until totals are updated.
- Order form: an All tab, shown first and selected by default, lists every group together.
- Order form: the Item, Price, Special order and Quantity columns are sortable (ascending, descending, then back to the default order); names sort naturally; on narrow screens the headings become a Sort by row.

## 6.1.0 — 2026-10-07

- Area inventory JSON import/export now carries each item's special-order mark ("special": true/false), so an Area that doesn't track quantities can import its special-order list in one step; the import preview shows the mark.

## 6.0.0 — 2026-10-07

- Inventory tracking is now optional per Area (Settings → Area Configuration). Areas that don't track quantities mark items as special orders from Literature → Inventory, the worker Inventory tab, or each item's edit page (Special order by Area); their orders never change stock counts.
- Order form groups literature into tabs (one per group), with search across all groups.
- Special-order items are sorted to the bottom of the order form, the Inventory lists, the review page, confirmations, emails, printouts and order screens.
- New Review order step: the server checks the order and recalculates prices and availability before the requester submits; Edit order keeps all entries, and the review is shown again if anything changed.
- Simplified order form rows: item and description, price, Special Order flag and quantity. The show_quantities attribute is removed (ignored).
- Starter catalog: medallions are now one item per year from 1 to 40 for bronze (BRZ-01..40), laser-etched (7501-7540) and triplate (TRI-01..40), each in its own group; replaces the three general medallion items.

## 5.2.0 — 2026-10-06

- New Reference link field for literature items (admin, worker catalog, REST, JSON import/export and backups); the order form shows it as a "View on <site>" link that opens in a new tab.
- Starter NAWS catalog adds official na.org reference links for 79 items: reading pages for every booklet and informational pamphlet, plus the relevant na.org pages for service materials, daily-meditation books, group reading cards and the ASL booklet.

## 5.1.0 — 2026-10-06

- Pagination for literature lists on [bmlt_literature_order] and the Inventory and Literature Catalog tabs of [bmlt_literature_manager]: Items per page selector (20, 50, 100 or All), item count, and Previous / page / Next controls above and below the list.
- New per_page shortcode attribute (20, 50, 100 or all) on both shortcodes; each visitor's chosen page size is remembered in their browser.
- Order form keeps quantities from other pages in the totals and the submitted order, and shows a note listing chosen items on other pages.

## 5.0.0 — 2026-10-06

- Version numbering restarted at 5.0.0; every plugin file now carries its version and the download is named bmlt-literature-manager-5.0.0.zip.
- Includes all features to date: JSON file storage (staging/production safe), BMLT integration, Area-specific inventory and email routing, special orders, order management, JSON import/export, full backup/restore, SMTP, privacy tools.
- Worker Inventory tab uses labelled fields in a flexible grid with alternating row colours; fits any theme width.
- CSS/JS links change whenever files change, so browsers and caching plugins never serve stale files.
