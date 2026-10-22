# BMLT Literature Manager

A WordPress plugin for Narcotics Anonymous service bodies to take literature orders from groups, track inventory separately for each Area, flag special orders, and route orders to each Area's literature workers. Meetings and Areas come from your BMLT Root Server.

- **Version:** 6.3.0
- **Requires:** WordPress 5.9+, PHP 7.4+
- **License:** GPL-2.0-or-later

---

## Contents

1. [What it does](#what-it-does)
2. [Installation](#installation)
3. [Where data is stored (staging and production)](#where-data-is-stored-staging-and-production)
4. [Setup, step by step](#setup-step-by-step)
5. [Shortcodes](#shortcodes)
6. [Processing orders](#processing-orders)
7. [Inventory and special orders](#inventory-and-special-orders)
8. [Literature catalog](#literature-catalog)
9. [Import and export](#import-and-export)
10. [Email and SMTP](#email-and-smtp)
11. [Users and permissions](#users-and-permissions)
12. [Admin menu reference](#admin-menu-reference)
13. [Backups, restore and maintenance](#backups-restore-and-maintenance)
14. [wp-config.php constants](#wp-configphp-constants)
15. [Developer reference](#developer-reference)
16. [Security and privacy](#security-and-privacy)
17. [Troubleshooting](#troubleshooting)
18. [Uninstalling](#uninstalling)

---

## What it does

**For group members:** a public order form where they pick their meeting from BMLT. The plugin works out their Area, shows that Area's stock for each item, and calculates totals as they choose quantities. Items that aren't in stock are clearly marked as special orders.

**For literature workers:** a protected page (and the WordPress dashboard) for working through orders, updating inventory and maintaining the catalog, usable from a phone at the Area Service meeting.

**For administrators:** BMLT connection, Area selection, per-Area email routing, email templates, built-in SMTP, JSON import/export, backups and a status page.

Key behaviours:

- **Inventory is always per Area.** The same item can be in stock in one Area and a special order in another.
- **Prices and stock are always recalculated on the server** when an order is submitted. Browser values are never trusted.
- **Special orders are flagged everywhere:** on the form, the confirmation, the worker and admin screens, the print view and every email.
- **Each order goes only to its own Area's literature workers.**
- **Orders keep a snapshot** of item names and prices, so history stays accurate when the catalog changes.

---

## Installation

1. In WordPress go to **Plugins → Add New → Upload Plugin**, choose `bmlt-literature-manager.zip`, and click **Install Now**.
2. **Before activating on a live site**, decide where the data folder will live (see the next section). On each server, add the location to `wp-config.php`.
3. Click **Activate**. The plugin creates its data folder and two user roles. Administrators automatically get every literature permission.

Upgrading from 1.0? Upload the new version over the old one. All existing database data is copied into JSON storage automatically the first time 2.0 runs, and the old tables are left untouched.

---

## Where data is stored (staging and production)

All plugin data is stored in **JSON files**, not the WordPress database. That covers the catalog, inventory, orders and their history, the audit log, settings, Area email routing, templates, SMTP settings and literature permissions. Pushing a staging **database** to production therefore cannot overwrite production orders.

Many hosts' "push to production" also copies **files**. To keep each environment's data safe, put the data folder **outside the WordPress directory on each server**. Add this line to `wp-config.php` above "That's all, stop editing":

```php
define( 'BMLT_LIT_DATA_DIR', '/home/youraccount/bmlt-literature-data' );
```

- Use a path the web server can write to.
- If data already exists in the default folder (`wp-content/bmlt-literature-data`), move that folder to the new path *before* adding the line.
- **Never point staging and production at the same folder.**
- The data remembers which site URL created it. If production ever opens staging's data, administrators see a warning until it's fixed or confirmed on **Literature → Tools / Status**.

Protection built in:

- **Not readable from the web:** files are named `*.json.php` and start with `<?php exit; ?>`, so they show nothing if requested in a browser. `.htaccess`, `web.config` and `index.php` guards are added too.
- **Safe concurrent saves:** every save takes an exclusive lock and replaces files in one step, so two simultaneous orders can't both take the last copy.
- **Recovery:** each file keeps its previous version (as `<name>.bak.json.php`, so it is just as unreadable from the web), and snapshots are saved automatically before restores, migrations and repairs.

---

## Setup, step by step

### 1. Connect to BMLT

**Literature → BMLT / Areas**

1. Enter your **Root Server URL**, for example `https://bmlt.example.org/main_server`. Don't include `/client_interface/...`; the plugin adds the standard JSON path itself. The address must be a server on the internet on port 80, 443 or 8080 (WordPress's protection against requests to internal addresses). A Root Server on a private network or another port can be allowed with WordPress's `http_request_host_is_external` and `http_allowed_safe_ports` filters.
2. Adjust **timeout**, **cache duration** and **automatic refresh** if needed. The defaults are 20 seconds, 6 hours and 12 hours.
3. Click **Save BMLT settings**, then **Test BMLT Connection**. You'll see the server version and how many service bodies and meetings were found.

If BMLT is ever unreachable, the last good copy of Areas and meetings keeps being used, so ordering keeps working.

### 2. Choose your Areas

On the same page, tick each Area this site serves and click **Save selected Areas**. Meetings for those Areas load immediately. Meetings belonging to sub-service-bodies are counted under their parent Area.

### 3. Set up each Area

**Literature → Settings → Area Configuration**

| Field | Purpose |
|---|---|
| Display name override | How the Area appears to requesters |
| Literature worker email(s) | Comma-separated. These people receive every order for this Area |
| Literature chair email | Optional extra recipient |
| Special-order recipient(s) | Notified only when an order contains special-order items |
| Area website / phone | Reference information |
| Inventory | **Track quantities on hand** (default), or **don't track** and simply mark which items are special orders for this Area |
| Delivery instructions | Added to the delivery message |
| Area Service meeting details | For example "2nd Sunday, 2 PM, Community Center" |
| Next Area Service meeting date | Optional. Shown only while the date is in the future |

If an Area has no worker email, its orders go to the global literature chair (Settings → Email) or, failing that, the site administrator, so no order is lost.

### 4. Add literature

Either import the starter catalog (see [Import and export](#import-and-export)) or add items under **Literature → Items → Add New Item**.

### 5. Enter starting inventory

**Literature → Inventory**: choose an Area and type each counted quantity in **On hand**. Repeat for each Area. For an Area that doesn't track quantities, tick the items that are special orders there instead.

### 6. Set up email

Set the sender name and address under **Settings → Email**. If your host's mail is unreliable, configure **Literature → SMTP**. Then send a test email.

### 7. Create your pages

| Page | Shortcode | Visibility |
|---|---|---|
| Order Literature | `[bmlt_literature_order]` | Public |
| Literature Workers | `[bmlt_literature_manager]` | Login required |
| Order History (optional) | `[bmlt_literature_orders]` | Login required |

The plugin tells caching plugins (`DONOTCACHEPAGE`) and browsers not to cache pages with the order form, so each visitor gets a fresh security token. If your host caches pages at the server regardless, exclude the order page there.

---

## Shortcodes

### `[bmlt_literature_order]`: public order form

Group members choose their meeting, enter contact details, pick literature and submit. They then see a confirmation page with their order number.

| Attribute | Default | Description |
|---|---|---|
| `area` | *(all selected Areas)* | A single BMLT service body ID. Only meetings from this Area are offered |
| `areas` | *(all selected Areas)* | Comma-separated service body IDs, e.g. `areas="12,15"` |
| `show_prices` | `yes` | `no` hides prices and totals on the form and confirmation (prices are still recorded) |
| `show_inventory` | `yes` | Shows the **Special Order** flag on items that are special orders for the requester's Area |
| `show_quantities` | — | *Removed in 6.0.* The simplified form no longer shows quantities on hand; the attribute is ignored |
| `show_descriptions` | `yes` | Shows short descriptions and an expandable long description |
| `title` | *(none)* | Optional heading above the form |
| `per_page` | `20` | Literature items per page: `20`, `50`, `100` or `all` |

Only Areas selected on the BMLT / Areas page can be used. IDs that aren't selected there are ignored.

Examples:

```
[bmlt_literature_order]
[bmlt_literature_order area="12" title="Order literature for Central Area"]
[bmlt_literature_order areas="12,15"]
[bmlt_literature_order show_prices="no"]
[bmlt_literature_order per_page="all"]
```

**Pagination.** Long lists on `[bmlt_literature_order]` and on the Inventory and Literature Catalog tabs of `[bmlt_literature_manager]` are split into pages, with an **Items per page** selector (20, 50, 100 or All), a "Showing 1–20 of 160" count, and Previous / page number / Next controls above and below the list.

- `per_page` sets the starting page size. Each visitor's own choice is then remembered in their browser.
- Searching or filtering returns to page 1.
- On the order form, quantities entered on one page are kept when moving to another, included in the totals and submitted with the order. A note lists any chosen items on other pages.
- Without JavaScript, all items are shown on one page.

What the requester sees:

1. **Choose literature.**
   - Pick a meeting (with a search box when there are more than 15). The plugin works out the Area and shows its details.
   - Literature is grouped into **tabs**: **All** (shown first, and selected by default) plus one per group (Books, Booklets, Pamphlets, Keytags, Medallions, and so on). The search box searches **all** groups; clicking a tab clears the search.
   - **Sortable columns.** Click **Item**, **Price**, **Special order** or **Quantity** to sort by that column. Clicking the same heading again reverses the order, and a third click returns to the default order (catalog order with special orders last). Item names sort naturally, so "2 Year" comes before "10 Year". On narrow screens the headings appear as a **Sort by** row.
   - Each row shows just the **item and its description, the price, a Special Order flag** (when it applies to the requester's Area) and a quantity control. If a quantity is larger than what's in stock, the flag shows how many will be special ordered (for example "3 of 5").
   - **Special-order items are listed at the bottom** of each group for the selected Area.
   - A running item count and total, and a **THIS ORDER CONTAINS SPECIAL ORDER ITEMS** warning when relevant.
2. **Review order.** "Review order" sends the form to the server, which checks everything and recalculates prices and availability. The requester sees the meeting, contact details, every item with price, line total and special-order status (special orders last), the total and the delivery message.
   - **Quantities can be changed right on the review page.** Change a number (0 removes the item) and select **Update totals** — or press Enter — to have the server re-price the order. A notice appears until the totals are updated.
   - **Edit order** goes back to the full form with everything kept; **Submit order** places the order.
   - If quantities, prices or availability changed since the review was shown (for example, a quantity was edited without updating), the review is shown again with the new totals instead of submitting.
3. **Confirmation.** Order number, items (special orders last), totals and the delivery message. The confirmation link expires after 30 days.

### `[bmlt_literature_manager]`: literature-worker workspace

A protected page with up to three tabs. Each tab appears only for users with the matching permission.

| Tab | Requires | What it does |
|---|---|---|
| Orders | View orders | The full order history and order management (same as `[bmlt_literature_orders]`) |
| Inventory | Manage inventory | Choose an Area. For each item: on hand, reserved, available, low-stock threshold and status. +/− buttons, a reason field and a Save button per row. Filter to low and out-of-stock items |
| Literature Catalog | Manage catalog | Add, edit, activate/deactivate and delete (only never-ordered) items. Filter by catalog and search |

| Attribute | Default | Description |
|---|---|---|
| `default_tab` | `orders` | Tab shown first: `orders`, `inventory` or `catalog` |
| `area` | *(none)* | Area ID to preselect in the Area filters |
| `per_page` | `20` | Items per page on the Inventory and Literature Catalog tabs: `20`, `50`, `100` or `all` |

Examples:

```
[bmlt_literature_manager]
[bmlt_literature_manager default_tab="inventory" area="12"]
[bmlt_literature_manager default_tab="catalog" per_page="50"]
```

Visitors who aren't logged in see a **Log in** link. Logged-in users without literature permissions see a "no permission" message. Workers restricted to certain Areas see only those Areas.

### `[bmlt_literature_orders]`: order history

A protected order history for anyone with the **View orders** permission.

| Attribute | Default | Description |
|---|---|---|
| `area` | *(none)* | Area ID to preselect in the Area filter |

```
[bmlt_literature_orders]
[bmlt_literature_orders area="15"]
```

How it works:

- **Loading in periods:** the last **45 days** load first. **Load Previous 45 Days** fetches the next older 45-day period each time it's clicked, so thousands of records never load at once.
- **Filters:** Area, status (including "Open"), special orders, order number / name / email / phone, meeting, and from/to dates. Setting a date range switches to page-by-page loading for that range.
- **Sorting:** newest, oldest, meeting, Area, status, or highest total.
- **Order details:** clicking an order number opens its details, with items, stock and special-order status, contact information, totals and history.

---

## Processing orders

Open an order from the order history shortcode, the worker workspace, the dashboard, or **Literature → Orders**. The admin list shows the 20 most recent orders first; choose 20, 50 or 100 per page.

### Statuses

| Status | Meaning |
|---|---|
| New | Just submitted |
| Reviewed | Seen by a literature worker |
| Waiting for Special Order | Special-order items have been ordered from NAWS |
| Ready | Ready for pickup or delivery |
| Partially Fulfilled | Some items delivered |
| Fulfilled | Complete. **Final** |
| Cancelled | Cancelled. **Final** |

### Actions

- **Change status:** Mark Reviewed, Waiting for Special Order, Mark Ready, Partially Fulfilled, Mark Fulfilled or Cancel Order. You can add an optional note.
- **Email the requester:** tick the box when marking **Ready** or **Waiting for Special Order** to send the matching template.
- **Resend Notification:** sends the order to the Area's literature workers again.
- **Email Requester:** writes a free-form message to the requester, with the order details included.
- **Add Note:** an internal note, recorded in the order's history.
- **Print Order:** a clean printable page with a tick-box column for picking.

Every status change, note and email is recorded in the order's history. Orders can't be deleted from the interface.

---

## Inventory and special orders

### Areas that don't track quantities

Under **Settings → Area Configuration**, choose **Don't track quantities** for an Area that doesn't keep a stock count. For that Area:

- Each item is simply **marked as a special order or not**. Change it from **Literature → Inventory** (tick the items), from the worker page's **Inventory** tab ("Special order for this Area"), or on an item's edit page under **Special order by Area**.
- Unmarked items are treated as available. Marked items are flagged **Special Order** on the order form and sorted to the bottom.
- Orders never change stock counts, and the inventory reservation setting doesn't apply.
- Low-stock alerts on the dashboard are skipped for that Area.

Switching an Area back to tracking uses its stored quantities again; the special-order marks are kept but ignored while tracking is on.

### Stock states

Shown with an icon and a word, so colour isn't the only cue.

| Indicator | Meaning |
|---|---|
| ✓ In Stock | Available quantity is above the low-stock threshold |
| ⚠ Low Stock | Available quantity is at or below the threshold |
| ! Special Order | Nothing available for this Area |
| ✕ Inactive | Item is not currently offered |

**Available = On hand − Reserved.** Reserved stock belongs to open orders.

### When an order asks for more than is in stock

Set under **Settings → General → When quantity exceeds stock**:

- **Split** (default): what's available comes from stock, and only the excess is special ordered.
- **Whole line:** the entire line becomes a special order.

### Inventory when an order is placed

Set under **Settings → General → Inventory when an order is placed**:

| Option | Ordered | Fulfilled | Cancelled |
|---|---|---|---|
| **Reserve, then deduct** (recommended) | Reserved | Deducted from on hand | Reservation released |
| Deduct immediately | Deducted | No change | Restored |
| Leave unchanged | No change | Deducted | No change |

Each order remembers which option it was placed under. Special-order quantities never reduce inventory. When special-order literature arrives, record it under Inventory if you want it counted.

### Adjusting inventory

- **Literature → Inventory:**
  - Set **On hand** to a counted quantity, or use **Adjust by** for receipts (+12) or losses (−2).
  - Set low-stock thresholds and reorder quantities.
  - Enter an **Adjustment reason**, then save.
- **Worker workspace → Inventory tab:** saves one row at a time, which is convenient on a phone.

Every change is recorded with who made it, when, the old and new values, and the reason.

### A typical special order

1. The order arrives flagged **THIS ORDER CONTAINS SPECIAL ORDER ITEMS**, with each special item listed in the email.
2. Mark it **Waiting for Special Order**, optionally emailing the requester.
3. Order the items from NAWS.
4. When they arrive, record them under Inventory.
5. Mark the order **Ready**, then **Fulfilled** when it's handed over.

---

## Literature catalog

**Literature → Items** or the worker workspace's Catalog tab.

Each item has an item number (SKU), title, category, price, short and long description, sort order, optional image URL, optional **reference link** (for example the item's official reading page on na.org, shown on the order form as a "View on na.org" link that opens in a new tab), optional NAWS product ID, and active/inactive status.

**Catalogs:**

- **Shared (all Areas):** offered on every Area's order form. This is the usual choice.
- **A specific Area:** offered only for that Area's meetings. Use this when an Area keeps its own catalog or pricing.

**Rules:**

- Item numbers must be unique within a catalog.
- Price changes apply to new orders only.
- Items that appear on any order can't be deleted. **Deactivate** them instead.
- Bulk activate, deactivate and delete are available from the Items list.

---

## Import and export

**Literature → Import / Export**

### Starter catalog

The plugin includes `sample-data/naws-catalog-2026.json`: 294 English items from the NA World Services 2026 price sheet (174 items plus 3 × 40 medallions), at single-copy US dollar NAWS prices.

- 17 items whose prices couldn't be confirmed are imported **inactive**. Check their prices and activate them.
- Medallions are one item per year from 1 to 40 years for each type, in three groups (tabs): **Bronze Medallions** (`BRZ-01`…`BRZ-40`), **Laser-etched Medallions** (NAWS numbers `7501`…`7540`) and **Triplate Medallions** (`TRI-01`…`TRI-40`, color stated in the order notes). Bronze and triplate use local item numbers because NAWS's numbering for them couldn't be confirmed.
- Upgrading a site that imported the earlier catalog: re-import with "Update existing items" ticked, and tick "Deactivate items missing from the file" to retire the three old general medallion items.
- 79 items include a reference link to an official NA World Services page: the reading page for each booklet and informational pamphlet, and the relevant na.org section for service materials and daily-meditation books. No images are included. NAWS's cover art and product photos are its intellectual property, so add images only if your Area has the right to use them.
- Adjust prices if your Area adds a markup to cover shipping.

### Importing

1. Choose **Literature catalog** (or **Area inventory**).
2. Choose the target catalog (or Area).
3. Choose whether to **update existing items with the same SKU** and whether to **deactivate items missing from the file**.
4. Upload a `.json` file or paste JSON into the editor. **Validate JSON** checks the syntax.
5. Click **Preview import** and review exactly what will be created, updated, skipped or deactivated.
6. Tick the confirmation box and click **Apply import**. Nothing changes before this step.

### Exporting

Download the catalog (everything, shared only, or one Area) or one Area's inventory, or load it into the editor and copy it.

### File format

```json
{
  "format": "bmlt-literature-manager",
  "version": 1,
  "exported_at": "2026-10-05T12:00:00+00:00",
  "items": [
    {
      "sku": "1101",
      "name": "Basic Text (Hardcover)",
      "short_description": "Narcotics Anonymous, Sixth Edition.",
      "long_description": "",
      "category": "Books",
      "price": "15.65",
      "active": true,
      "sort_order": 101,
      "image_url": "",
      "reference_url": "",
      "naws_id": "1101"
    }
  ]
}
```

- **SKUs** may contain letters, numbers, spaces and `. _ - / #`.
- **Prices** need at most two decimals.
- **Inventory files** use `"format": "bmlt-literature-manager-inventory"`, with items containing `sku` and any of `quantity_on_hand`, `low_stock_threshold`, `reorder_quantity` and `special` (`true`/`false`: whether the item is a special order for that Area, used by Areas that don't track quantities). Fields left out are not changed. Choose the Area to import into on the Import / Export page.
- **Safety:** only plain values are accepted, and nothing in a file is ever executed.

---

## Email and SMTP

### Who receives what

| Email | Recipients |
|---|---|
| Literature-worker notification | The order's Area worker emails + chair email, plus special-order recipients when relevant, plus the global chair if "copy every order" is on |
| Administrator copy | The admin copy address, if enabled |
| Requester confirmation | The requester, if enabled |
| Ready / special-order notice | The requester, when a worker ticks "email the requester" |
| Message to requester | The requester, from the order screen |

Worker emails put a **SPECIAL ORDER NOTICE** at the top whenever any item is a special order, and list exactly which items and quantities.

### Templates

**Settings → Email Templates.** You can edit the subject and HTML body of each email. Tick **Reset** to restore a template's default. Available placeholders:

`{order_number}` `{order_date}` `{order_status}` `{area_name}` `{meeting_name}` `{meeting_details}` `{contact_name}` `{contact_phone}` `{contact_email}` `{notes}` `{order_items}` `{item_count}` `{subtotal}` `{total}` `{special_order_notice}` `{special_order_flag}` `{delivery_message}` `{worker_message}` `{order_link}` `{organization_name}` `{site_name}`

Values typed by requesters are always escaped.

### SMTP

**Literature → SMTP**

- **WordPress default mail:** the plugin doesn't touch WordPress email at all.
- **Custom SMTP:** host, port, encryption (None, STARTTLS/TLS, SSL), authentication, username, password, From address and name, **Force From**, and a default reply-to. This applies to all WordPress email.

The password is encrypted with the site's security keys and is never shown again; leave the field blank to keep it. For the strongest protection, keep it out of storage entirely:

```php
define( 'BMLT_LIT_SMTP_PASSWORD', 'your-smtp-password' );
```

Use **Send Test Email** to check delivery. Errors are shown with credentials removed.

---

## Users and permissions

### Capabilities

| Capability | Allows |
|---|---|
| `bmlt_lit_view_orders` | View orders and order history |
| `bmlt_lit_manage_orders` | Change status, add notes, resend notifications, email requesters |
| `bmlt_lit_manage_inventory` | Adjust Area inventory |
| `bmlt_lit_manage_catalog` | Add, edit and deactivate items; import and export |
| `bmlt_lit_manage_settings` | BMLT, Areas, SMTP, settings and tools |

Administrators always have every capability.

### Roles

| Role | Capabilities |
|---|---|
| Literature Worker | View orders, manage orders, manage inventory |
| Literature Chair | Literature Worker + manage catalog |

### Assigning workers

- **Option 1:** give the user a role under **Users → Edit user → Role**.
- **Option 2:** under **Settings → Permissions**, enter a username or email in **Add a literature worker**, then tick individual capabilities.

**Restrict to Areas:** tick one or more Areas to limit a user to those Areas' orders, inventory and catalogs. Leave everything unticked to allow every Area. Restricted users can't edit the shared catalog.

Permissions set on the Permissions tab are stored in the data folder, so database pushes don't change them. Roles are WordPress user data and travel with the database. Only site administrators can change permissions.

---

## Admin menu reference

| Menu | Purpose |
|---|---|
| **Dashboard** | New orders, open special orders, orders waiting for fulfillment, orders in the last 7/30/45 days, low and out-of-stock items, recent orders and inventory changes. Filter by Area |
| **Orders** | All orders, newest first, with filters and search. Click an order to manage it |
| **Items** | The literature catalog |
| **Inventory** | Per-Area quantities, adjustments and inventory history |
| **Import / Export** | JSON catalog and inventory exchange |
| **BMLT / Areas** | Root Server connection, connection test, refresh, Area selection |
| **SMTP** | Mail delivery settings and test email |
| **Settings** | Tabs: General, BMLT, Area Configuration, Email, Email Templates, Permissions, Advanced |
| **Tools / Status** | Storage health, environment checks, backup and restore, cache and repair tools |

**Settings → General** also covers:

- organization name and currency format
- delivery and special-order wording and the approximate special-order turnaround
- order-number prefix (orders look like `LIT-2026-000123`)
- which contact fields are required
- default low-stock threshold and maximum quantity per item

**Settings → Advanced** covers spam protection (orders per connection, minimum fill-in time) and the uninstall data option.

---

## Backups, restore and maintenance

**Literature → Tools / Status**

- **Download full backup:** one JSON file with the catalog, inventory, orders, history, settings and permissions. The SMTP password is never included.
- **Restore from a full backup** (administrators only):
  - The file is validated and summarized first, and you must type **RESTORE** to confirm.
  - Restoring replaces all current literature data. A snapshot of the current data is saved first, and this site's SMTP password is kept.
  - Use this, or the catalog export, when you want to copy something from staging to production on purpose.
- **Refresh BMLT Data:** fetches fresh Areas and meetings now.
- **Clear and rebuild BMLT cache.**
- **Verify storage and rebuild order index:** recreates folders and protection files and rebuilds the order index. Never removes data.
- **Re-run migration from version 1.0 / Delete old version 1.0 database tables:** shown only after an upgrade from 1.0.

**Updating the plugin:** upload the new version over the old one. Data in the JSON folder is never touched by updates. Download a full backup before major updates.

---

## wp-config.php constants

| Constant | Purpose |
|---|---|
| `BMLT_LIT_DATA_DIR` | Absolute path of the data folder. Strongly recommended, and different on each server |
| `BMLT_LIT_SMTP_PASSWORD` | SMTP password; used instead of any stored value |
| `BMLT_LIT_LOCK_METHOD` | Set to `'mkdir'` on network filesystems that don't support file locking |

On multisite, each site in the network automatically gets its own subfolder.

---

## Developer reference

### Hooks

| Hook | Type | Arguments | Use |
|---|---|---|---|
| `bmlt_lit_order_created` | action | `$order_id` | Runs after an order is saved |
| `bmlt_lit_order_status_changed` | action | `$order_id, $old_status, $new_status` | Runs after a status change |
| `bmlt_lit_worker_recipients` | filter | `$recipients, $order` | Change who receives worker notifications |
| `bmlt_lit_order_form_captcha` | action | — | Render a CAPTCHA widget in the order form |
| `bmlt_lit_validate_captcha` | filter | `$valid, $post` | Return `true`, or a `WP_Error` to block the order |
| `bmlt_lit_client_ip` | filter | `$ip` | Supply the real client IP behind a trusted proxy (used for rate limiting) |
| `bmlt_lit_data_dir` | filter | `$dir` | Change the data folder |

CAPTCHA example:

```php
add_action( 'bmlt_lit_order_form_captcha', function () {
	echo '<div class="my-captcha" data-sitekey="..."></div>';
} );

add_filter( 'bmlt_lit_validate_captcha', function ( $valid, $post ) {
	return my_captcha_check( $post['my-captcha-response'] ?? '' )
		? true
		: new WP_Error( 'captcha', 'Please complete the verification.' );
}, 10, 2 );
```

### REST API

Namespace: `/wp-json/bmlt-lit/v1/`. All endpoints except `public/stock` require a logged-in user, the `X-WP-Nonce` header and the listed capability. Orders and items are also checked against the user's permitted Areas.

| Method | Route | Capability |
|---|---|---|
| GET | `orders` (`before`, `page`, `area`, `status`, `special`, `search`, `meeting`, `date_from`, `date_to`, `sort`) | view orders |
| GET | `orders/{id}` | view orders |
| POST | `orders/{id}/status` (`status`, `note`, `notify`) | manage orders |
| POST | `orders/{id}/notes` (`note`) | manage orders |
| POST | `orders/{id}/resend` | manage orders |
| POST | `orders/{id}/email` (`message`) | manage orders |
| GET / POST | `inventory` (`area`, `item_id`, `set`, `delta`, `low_stock_threshold`, `reorder_quantity`, `reason`) | manage inventory |
| GET / POST | `items` | manage catalog |
| POST / DELETE | `items/{id}` | manage catalog |
| POST | `items/{id}/active` (`active`) | manage catalog |
| POST | `admin/bmlt-test` | manage settings |
| GET | `public/stock?area={id}` | public: stock indicators only, no personal data |

### File layout

```
bmlt-literature-manager/
├── bmlt-literature-manager.php   Bootstrap and autoloader (namespace BMLT_Lit)
├── uninstall.php
├── includes/                     Store, Schema, Settings, Catalog, Inventory, Orders,
│                                 Email, Smtp, Bmlt_Client, Permissions, Audit, Rest,
│                                 Shortcodes, Privacy, Import_Export, Migrator, Helpers
├── admin/                        Admin controller, list tables, views, CSS, JS
├── public/                       Front controller, views, CSS, JS
├── sample-data/                  naws-catalog-2026.json
├── languages/                    bmlt-literature-manager.pot
└── docs/ADMIN-GUIDE.md
```

The plugin is fully translatable (text domain `bmlt-literature-manager`).

---

## Security and privacy

- **Requests:**
  - Every form and endpoint checks a nonce and a capability.
  - Every order and item is checked against the user's permitted Areas.
  - All output is escaped.
- **Public order form:**
  - security token, hidden honeypot field, minimum fill-in time and rate limit per connection
  - optional CAPTCHA through the hooks above
- **Data:** stored in files that can't be read over the web. The IP address is kept only as a one-way hash.
- **Privacy tools:** registered with **Tools → Export Personal Data** and **Erase Personal Data**. Erasing removes contact details but keeps item and total information for literature accounting.

---

## Troubleshooting

| Problem | What to check |
|---|---|
| "Literature ordering is not available yet" | Select at least one Area on **BMLT / Areas** |
| "The meeting list is temporarily unavailable" | BMLT has never been reached successfully. Check the Root Server URL and run **Test BMLT Connection** |
| Test connection fails | The URL should be the Root Server base (often ending in `/main_server`). Try the endpoint override if your server is non-standard |
| Every item shows Special Order | No inventory has been entered for that Area yet (**Literature → Inventory**) |
| "Your session expired" on the order form | The page is being cached. Exclude it from page caching |
| Workers can't see a page or Area | Check their capabilities and Area restrictions under **Settings → Permissions** |
| No emails arrive | Check the Area's worker emails, then **SMTP → Send Test Email**. The order's history shows whether each email was sent |
| "Data directory is not writable" | Create the folder and give the web server write access, or set `BMLT_LIT_DATA_DIR` |
| "Literature data belongs to another site" | Staging and production may be sharing or have copied the data folder. Give each its own folder, or confirm on **Tools / Status** |
| "The literature data is busy" | Another save was in progress; try again. If it keeps happening on a network filesystem, set `BMLT_LIT_LOCK_METHOD` to `'mkdir'` |
| SMTP password stopped working after a move | Stored passwords depend on each site's security keys. Re-enter it, or use `BMLT_LIT_SMTP_PASSWORD` |

---

## Uninstalling

- **Deactivating** never deletes data.
- **Deleting** the plugin removes data (the JSON data folder and any leftover 1.0 tables) **only if** **Settings → Advanced → Delete plugin data when uninstalling** was turned on. It's off by default.
