# BMLT Literature Manager — Administrator Guide

_Version 6.2.0_

This guide walks through setting up and running the plugin. Menu locations refer to the **Literature** menu in the WordPress dashboard.

## 1. Installation and activation

Upload the plugin zip under **Plugins → Add New → Upload Plugin**, then click **Activate**. Activation creates the JSON data directory (see 1a), two roles (Literature Worker, Literature Chair), and administrators automatically have every literature capability. Nothing is ever deleted on deactivation.

## 1a. Where data is stored (read this before going live)

Version 2.0 stores **all** literature data in JSON files instead of the WordPress database: catalog, inventory, orders and their history, the audit log, settings, Area email routing, email templates, SMTP settings and literature permissions. This means pushing a staging **database** to production cannot overwrite production orders.

Many hosts' "push staging to production" also copies **files**. To be safe, keep the data directory outside anything that gets pushed. On **each** server, add this to `wp-config.php` above the "That's all, stop editing" line, using a path outside the WordPress folder that the web server can write to:

```php
define( 'BMLT_LIT_DATA_DIR', '/home/youraccount/bmlt-literature-data' );
```

If you already have data in the default folder (`wp-content/bmlt-literature-data`), move that folder to the new path first, then add the line. Staging and production must each have their **own** directory — never share one.

Safety features:

- Data files end in `.json.php` and start with `<?php exit; ?>`, so they show nothing if someone requests them in a browser. `.htaccess`, `web.config` and `index.php` guards are added as well.
- Every save takes an exclusive lock and replaces files atomically, so simultaneous orders cannot corrupt data or oversell stock. If your host uses a network filesystem that does not support file locks, add `define( 'BMLT_LIT_LOCK_METHOD', 'mkdir' );`.
- Each file keeps its previous version as `.bak`; damaged files are never silently overwritten. Snapshots are saved in `backups/` before restores, migrations and repairs.
- The data remembers which site URL created it. If production opens data created by staging (or the other way round), administrators see a warning on every Literature screen until it is fixed or confirmed on **Tools / Status**.

To copy something from staging to production **on purpose**, export the catalog JSON (Import / Export) or download a full backup (Tools / Status) on staging, then import/restore it on production. Restores show a summary and require typing RESTORE; production's own SMTP password is kept.

Upgrading from 1.0: all database data is copied into JSON automatically the first time 2.0 runs. The old tables are left untouched; once you have checked everything, you can delete them from **Tools / Status**.

## 2. Connecting to BMLT

Open **Literature → BMLT / Areas**.

1. Enter your **Root Server URL**, for example `https://bmlt.example.org/main_server`. Do not include `/client_interface/...`; the plugin adds the standard JSON path itself. Use the **API endpoint override** only if your server publishes the JSON interface somewhere unusual.
2. Leave the timeout at 20 seconds unless your server is slow.
3. **Cache duration** controls how long meeting data is reused before BMLT is asked again. **Automatic refresh** runs in the background through WordPress cron.
4. Click **Save BMLT settings**, then **Test BMLT Connection**. The result shows the server version, the number of service bodies, the number of meetings, and a plain-language error if something is wrong.

If BMLT is ever unreachable, the last good copy of the Areas and meetings keeps being used and administrators see a notice.

## 3. Selecting Areas

On the same page, tick each Area this site manages and click **Save selected Areas**. The plugin immediately loads the meetings that belong to those Areas (including meetings of child service bodies, which are attributed to the selected parent Area). Each selected Area gets its own inventory, its own email routing and, optionally, its own catalog.

## 4. Area configuration and email routing

Open **Literature → Settings → Area Configuration**. For every Area set:

- **Display name override** — how the Area appears to requesters.
- **Literature worker email(s)** — comma-separated; these people receive every order for this Area.
- **Literature chair email** — optional additional recipient.
- **Special-order recipient(s)** — added only when an order contains special-order items.
- **Website / phone** — reference information.
- **Delivery instructions** and **Area Service meeting details** — added to the delivery message.
- **Next Area Service meeting date** — optional; shown only while the date is in the future. Leave blank if you do not want to promise a date.

An order for Area A is sent only to Area A's addresses. If an Area has no address, the order goes to the global literature chair (Settings → Email) or, failing that, the site administrator, so no order is lost. The Status page lists Areas missing a worker email.

## 5. Assigning literature workers

Either give users the **Literature Worker** or **Literature Chair** role under **Users** (roles are WordPress user data and travel with database pushes), or open **Settings → Permissions** (stored in the literature data directory, so database pushes do not change them), type a username or email in **Add a literature worker**, and tick individual capabilities. To limit someone to particular Areas, tick those Areas in **Restrict to Areas**; leave all unticked to allow every Area. Restricted users cannot edit the shared catalog. Only site administrators can change permissions.

## 6. Adding literature

Open **Literature → Items → Add New Item**. Choose the **Catalog**:

- **Shared (all Areas)** — the item appears on every Area's order form (most common).
- **A specific Area** — the item appears only for that Area's meetings, which lets an Area keep its own catalog or pricing.

Enter SKU, name, category, price, descriptions and sort order. Price changes affect new orders only; every order stores the name and price at the time it was placed. Items that appear on any order cannot be deleted — deactivate them instead. Bulk activate/deactivate is available from the list.

## 7. Importing and exporting JSON

Open **Literature → Import / Export**.

- **Export** downloads the catalog (all, shared, or one Area) or one Area's inventory as JSON. **Copy … to editor** loads the JSON into the editor box so you can copy it.
- **Import**: upload a `.json` file or paste JSON, choose the target catalog (or Area for inventory), choose whether to update existing SKUs and whether to deactivate items missing from the file, then click **Preview import**. Nothing changes until you review the preview, tick the confirmation box and click **Apply import**.

Catalog file format:

```json
{
  "format": "bmlt-literature-manager",
  "version": 1,
  "exported_at": "2026-10-04T12:00:00+00:00",
  "items": [
    {
      "sku": "1101",
      "name": "Basic Text, Sixth Edition",
      "short_description": "Hardcover",
      "long_description": "",
      "category": "Books",
      "price": "13.50",
      "active": true,
      "sort_order": 10,
      "image_url": "",
      "naws_id": ""
    }
  ]
}
```

Inventory files use `"format": "bmlt-literature-manager-inventory"` with items containing `sku`, `quantity_on_hand`, `low_stock_threshold` and `reorder_quantity`. Every field is validated; only plain values are accepted and nothing in the file is ever executed.

## 8. Setting starting inventory

Open **Literature → Inventory**, choose an Area, and type each counted quantity in **On hand**. Use **Adjust by** for receipts (+12) or losses (−2), set a **Low-stock threshold**, enter an **Adjustment reason**, and click **Save inventory changes**. Every change is recorded in the inventory history with who, when, old value, new value and reason. Inventory is always per Area: the same item can be in stock in one Area and a special order in another.

**Reserved** shows quantities held for open orders; **Available** = On hand − Reserved.

## 9. Inventory reservation behavior

Under **Settings → General → Inventory when an order is placed**:

- **Reserve, then deduct when fulfilled** (recommended): available stock drops immediately, on-hand drops when the order is marked Fulfilled, and cancelling releases the reservation.
- **Deduct immediately**: on-hand drops when ordered; cancelling restores it.
- **Leave unchanged until fulfilled**: nothing changes until Fulfilled.

Each order remembers which method it was placed under. Orders are saved under an exclusive data lock, so two simultaneous orders cannot both take the last copy.

**When quantity exceeds stock** chooses whether only the excess is special ordered (default) or the whole line.

## 10. Configuring SMTP and testing email

Open **Literature → SMTP**. Choose **Use WordPress default mail** (the plugin then does not touch WordPress email at all) or **Use custom SMTP** and enter host, port, encryption (None, STARTTLS/TLS, SSL), authentication, username and password. The password is encrypted with your site's security keys and is never displayed again; leave the field blank to keep it. The encrypted password lives in the literature data directory and depends on the site's security keys, so re-enter it if you move the data to a server with different keys. For the strongest protection add this to `wp-config.php`:

```php
define( 'BMLT_LIT_SMTP_PASSWORD', 'your-smtp-password' );
```

Set the sender under **Settings → Email** and use **Send Test Email**. Errors are shown with credentials removed.

## 11. Email templates

**Settings → Email Templates** lets you edit the subject and HTML body for the literature-worker notification, administrator copy, requester confirmation, order-ready notice, special-order notice, and free-form messages to requesters. Placeholders such as `{order_number}`, `{area_name}`, `{meeting_name}`, `{contact_name}`, `{contact_phone}`, `{contact_email}`, `{order_items}`, `{subtotal}`, `{total}`, `{special_order_notice}` and `{delivery_message}` are listed on that screen. Values typed by requesters are always escaped. Tick **Reset** to restore a default.

## 12. Inserting shortcodes

- Public page: `[bmlt_literature_order]` (attributes: `area`, `areas`, `show_prices`, `show_inventory`, `show_quantities`, `show_descriptions`, `title`).
- Worker page: `[bmlt_literature_manager]` (attributes: `default_tab`, `area`).
- History page: `[bmlt_literature_orders]` (attribute: `area`).

Worker and history pages require login and the right capability, so it is safe if someone learns the URL. Exclude the public order page from full-page caching.

## 13. Processing orders

New orders appear on the dashboard, under **Literature → Orders** (newest first, 20 per page; choose 20/50/100), and in the worker shortcodes. Open an order to:

- **Mark Reviewed**, **Waiting for Special Order**, **Mark Ready**, **Partially Fulfilled**, **Mark Fulfilled** or **Cancel Order**. Fulfilled and Cancelled are final.
- Tick **Email the requester** to send the ready or special-order template.
- **Resend Notification**, **Email Requester**, **Add note**, or **Print Order** (a clean printable page with a pick-list checkbox column).

Every status change, note and email is recorded in the order's history. Orders cannot be deleted from the interface.

## 14. Handling special orders

When an item's available quantity for the requester's Area is zero (or lower than the quantity ordered), the line is marked special order. Such orders show **THIS ORDER CONTAINS SPECIAL ORDER ITEMS** on the form, confirmation, worker and admin screens, print view, and at the top of every email, listing exactly which items and quantities are special. A typical flow: mark **Waiting for Special Order** (optionally emailing the requester), order from NAWS, record the received stock under Inventory, then mark **Ready** and finally **Fulfilled** at the Area Service meeting.

## 15. Adjusting inventory

Use **Literature → Inventory** or the Inventory tab in `[bmlt_literature_manager]` (convenient on a phone at the Area Service meeting). The worker screen saves one row at a time with +/− buttons and a reason field.

## 16. Updating the plugin safely

Upload the new version over the old one. Data in the JSON directory is never touched by plugin updates. **Tools / Status → Verify storage and rebuild order index** can be run at any time and never removes data. Before major updates, download a full backup from Tools / Status.

## 17. Tools / Status

Shows the data directory and whether it is writable, file counts and sizes, which site created the data, BMLT server and cache state, configured Areas, item/inventory/order counts and email configuration (never passwords). Tools: **Download full backup**, **Restore from a full backup**, **Refresh BMLT Data**, **Clear and rebuild BMLT cache**, **Verify storage and rebuild order index**, and (after upgrading from 1.0) **Re-run migration** and **Delete old version 1.0 database tables**.

## 18. Privacy

Only the meeting, contact name, phone, email, notes and items are stored (in the protected data directory), plus a one-way hash of the IP address for spam protection. The plugin registers with **Tools → Export Personal Data** and **Erase Personal Data**; erasing anonymizes contact details while keeping item and total information for literature accounting. Suggested privacy-policy text is added under **Settings → Privacy**.

## 19. Uninstalling

Deactivation never deletes data. Deleting the plugin removes data (the JSON data directory and any leftover 1.0 tables) only if **Settings → Advanced → Delete plugin data when uninstalling** was turned on.
