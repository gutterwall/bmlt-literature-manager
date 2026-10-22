# Changelog

All notable changes to BMLT Literature Manager.

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
