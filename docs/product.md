# Woo Product Syncer — Product Requirements Document

## What it is

Woo Product Syncer (WPSyncer) is a WordPress plugin for WooCommerce that syncs products between two (or more) WooCommerce stores. It installs the same plugin on every site and each site is configured with a role — Source, Receiver, or Both. When a product changes on one site, the plugin builds a full product snapshot and delivers it to the other site(s), which validate, map, filter, and apply the allowed data.

It is not a generic "copy everything" tool. It gives you granular control over what fields sync, what custom meta travels, what happens when products or variations disappear, and how images are handled.

## Why it exists

Existing sync plugins are either paid, too rigid, or fail once you need custom meta, special variation handling, image deduplication, and field-level control. This plugin is built for store owners and developers who need a controlled, auditable, secure product replication system they own entirely.

## Who it serves

- Store owners running multiple WooCommerce sites that need shared product catalogs
- Developers who need product sync with custom field support and whitelist control
- Site operators who want visibility into what synced, when, and what failed
- Anyone who needs to migrate or keep products in sync with settings that travel as portable JSON

## Core concepts

### Modes

| Mode | What the site does |
|---|---|
| **Disabled** | Plugin is inactive on this site |
| **Source** | Watches for product changes and sends snapshots to target receiver(s) |
| **Receiver** | Exposes a REST endpoint, accepts incoming snapshots, applies them to local products |
| **Both** | Acts as Source AND Receiver simultaneously — sends out and accepts in |

When a site is in **Both** mode, it prevents infinite re-sync loops by flagging saves that originated from an incoming sync payload and suppressing their re-dispatch.

### Identity model

Product IDs differ between sites. Instead of relying on numeric IDs, each product gets a stable sync UID:

- `_wpsyncer_sync_uid` — assigned on the source, stored on both sides
- `_wpsyncer_remote_sync_uid` — stored on the receiver, matches the source's UID
- **SKU** is used as a fallback match when sync UID is not present (e.g., products created before the plugin was installed)

Variations get their own sync UIDs following the same pattern.

### Snapshot model

Every sync sends a **full product snapshot** — the complete canonical state of the product at that moment. This is idempotent: if a previous sync failed, the next one self-heals. No fragile diff logic.

## Features

### Sync groups (checkbox toggles)

Each group can be enabled or disabled independently on the receiver:

| Group | What's included |
|---|---|
| Core product fields | name, slug, status, catalog visibility, featured, short/long description, menu order, SKU, tax status/class, sold individually, weight, dimensions, shipping class, purchase note |
| Prices | regular price, sale price, sale date range |
| Stock | manage stock flag, stock quantity, stock status, backorders |
| Categories & Tags | assigned product categories and tags, matched by slug |
| Attributes | global taxonomy attributes (pa_color, pa_size) and custom product attributes |
| Variations | variation identity, SKU, prices, stock, weight/dimensions, attributes, status, description, image, custom meta |
| Images & Gallery | featured image and gallery images; deduplicated by source attachment ID |

### Custom meta

Custom meta keys are synced on a **whitelist-only** basis. The admin selects which meta keys to sync from a **dropdown list** of all meta keys currently present on site products. No blind "sync all meta" — that breaks things. The dropdown shows all post meta keys found across WooCommerce products so the admin can pick precisely which custom fields to include.

### Sync all products (bulk)

A button on the settings page triggers a bulk sync: every published product is queued for snapshot dispatch. Rate-limited to prevent overwhelming the receiver — products are processed in configurable batches with delays between batches.

### Per-product sync

A meta box on each product edit screen shows:
- Last sync time and result
- A **"Sync this product now"** button
- The remote product ID and site

### Product deletion handling

When a product is trashed or deleted on the source, a delete event is sent to the receiver. The receiver's behavior is configurable:

| Behavior | What happens |
|---|---|
| Ignore | Do nothing; product remains on receiver |
| Set draft | Product status changed to draft |
| Trash | Product moved to trash |

Same behavior applies to variations that disappear from an incoming snapshot.

### Conflict protection

The receiver checks WordPress's built-in post locking (`wp_check_post_lock()`) before applying a sync payload. If an admin is currently editing the product on the receiver side, the sync is **deferred** (queued for retry later) rather than silently overwriting their work.

### Settings export / import

All plugin settings (mode, URLs, secrets, sync group toggles, meta key selections, delete behavior, etc.) can be exported as a **JSON file** for backup, transfer between sites, or quick configuration. The same JSON can be imported to restore or replicate settings.

### Sync logs

An admin log viewer shows recent sync activity: time, level, message, and context. Last 100 entries retained. Errors are also written to the PHP error log.

## Main flows

### Flow 1: Product updated on Source → Receiver gets it

```
Source site:
  Admin saves a product
  → Source hooks detect the save
  → Debounce: skip if already queued (30s transient)
  → Enqueue async job (Action Scheduler or WP-Cron)
  → Admin gets normal response immediately

Async job runs:
  → Build full product snapshot (all fields, variations, images, meta)
  → HMAC-sign the payload
  → POST to receiver's REST endpoint

Receiver site:
  → Verify HMAC signature and timestamp
  → Check allowed source IDs
  → Parse payload, validate schema
  → Find product by sync UID (then SKU fallback)
  → Check post lock — defer if someone is editing
  → Apply allowed fields via WooCommerce CRUD
  → Match/create categories, tags, attributes
  → Handle images: deduplicate, sideload if new
  → Apply custom meta (whitelist only)
  → Update/create/cleanup variations
  → Log result
```

### Flow 2: Bulk initial sync

```
Admin clicks "Sync all products" on Source site settings
  → All published products are enumerated
  → Batched: N products per batch, M seconds between batches
  → Each product follows Flow 1
  → Progress shown in logs / admin UI
```

### Flow 3: Bidirectional sync (Both mode)

```
Site A and Site B are both in "Both" mode.

Site A: Admin edits a product
  → Snapshot sent to Site B (same as Flow 1)
  → Site B receives, applies changes
  → During application, flag is set: "this save came from a sync"
  → Site B's Source hooks see the flag → suppress re-dispatch
  → No infinite loop

Same in reverse when Site B's admin edits a product.
```

### Flow 4: Settings export/import

```
Export:
  Admin clicks "Export settings"
  → All wpsyncer settings collected into JSON
  → JSON file downloaded via browser

Import:
  Admin clicks "Import settings", selects JSON file
  → JSON validated for structure
  → Settings applied (mode, URLs, secrets, toggles, meta keys)
  → Confirmation message shown
```

### Flow 5: Product ID sync (experimental)

```
Receiver receives a snapshot for a new product
  → sync_product_ids is enabled
  → Check: does a post with source_product_id already exist on receiver?
      → Exists AND has _wpsyncer_remote_sync_uid → it's ours, safe to reuse
      → Exists WITHOUT _wpsyncer_remote_sync_uid → conflict, skip with error log
      → Does not exist → safe
  → Create product using wp_insert_post() with import_id = source_product_id
  → Load WC_Product from that ID, apply sync fields normally
```

### Experimental: Product ID sync

For fully mirrored stores where product IDs must match across sites, the receiver can be configured to use the source's numeric product ID when creating products. This uses WordPress's `import_id` mechanism.

**This is experimental and carries risk.** Use only on fresh receiver sites with no existing products. Safety checks prevent overwriting existing non-synced products with conflicting IDs, but the feature bypasses normal WordPress auto-increment behavior. You are responsible for the outcome.

Requires `sync_product_ids` to be explicitly set to `'yes'` (defaults to `'no'`). The settings UI marks it clearly with a warning.

### Future: Advanced Custom Fields (ACF) detection

ACF stores field values using structured keys (`_field_key`, `field_*` patterns). The custom meta dropdown could auto-detect ACF field groups and present them as labeled, selectable groups rather than raw meta keys. Not in scope for the initial build but the architecture accommodates it as a helper layer on top of the existing meta whitelist system.

## What this plugin does NOT do

- Real-time order or customer sync
- Multi-site inventory management
- Multi-currency or price-per-site rules
- REST API product creation (only sync between configured sites)
- Blind meta sync (must be whitelisted)
- Migrate product IDs onto sites with existing products (use experimental ID sync only on fresh sites)
