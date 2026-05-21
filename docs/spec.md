# Woo Product Syncer — Behavior Specification

## 1. Plugin identity

- **Name:** Woo Product Syncer
- **Slug:** `woo-product-syncer`
- **Text domain:** `woo-product-syncer`
- **PHP constant prefix:** `WPSYNCER_`
- **Class prefix:** `WPSYNCER_`
- **Meta key prefix:** `_wpsyncer_`
- **REST API namespace:** `wpsyncer/v1`
- **Settings option key:** `wpsyncer_settings`
- **Log option key:** `wpsyncer_logs`
- **Async hook name:** `wpsyncer_sync_product_async`
- **Minimum PHP:** 7.4
- **Required plugin:** WooCommerce (any version that supports CRUD objects)

## 2. Modes

The plugin has exactly four modes stored as the string value of `mode` in settings:

| Value | Behavior |
|---|---|
| `disabled` | Plugin does nothing. No hooks registered. |
| `source` | Registers product save/delete hooks. Dispatches snapshots. Does NOT register REST endpoint. |
| `receiver` | Registers REST endpoint. Processes incoming payloads. Does NOT register source hooks. |
| `both` | Registers source hooks AND REST endpoint. Uses sync-skip flag to prevent echo loops. |

The default mode is `disabled`.

## 3. Settings schema

All settings are stored in a single WordPress option array under key `wpsyncer_settings`. Default values:

```php
[
    'mode'                    => 'disabled',
    'source_site_id'          => '',       // unique identifier for this site
    'target_url'              => '',       // receiver base URL (REST path appended automatically)
    'shared_secret'           => '',       // HMAC shared secret
    'create_missing_products' => 'yes',
    'create_missing_terms'    => 'yes',
    'sync_core'               => 'yes',
    'sync_prices'             => 'yes',
    'sync_stock'              => 'yes',
    'sync_taxonomies'         => 'yes',
    'sync_attributes'         => 'yes',
    'sync_variations'         => 'yes',
    'sync_images'             => 'no',
    'sync_meta_keys'          => '',       // selected meta keys (newline/comma-separated)
    'delete_behavior'         => 'ignore', // 'ignore' | 'draft' | 'trash'
    'debug_logging'           => 'yes',
    'bulk_batch_size'         => 10,       // products per batch during bulk sync
    'bulk_batch_delay'        => 5,        // seconds between batches
    'sync_product_ids'        => 'no',     // experimental: use source product IDs on receiver
]
```

All toggle settings (`create_missing_products`, `sync_core`, etc.) use `'yes'` / `'no'` string values.

## 4. Product identity & matching

### On the source

When a product is first synced, a sync UID is generated and stored:
- Parent product: `_wpsyncer_sync_uid` — a UUID v4 string
- Variation: `_wpsyncer_sync_variation_uid` — a UUID v4 string

If `wp_generate_uuid4()` is available (WordPress 6.5+), use it. Otherwise, use `md5($post_id . microtime(true) . wp_rand())`.

### On the receiver

The receiver stores mapping metadata:
- Parent product: `_wpsyncer_remote_sync_uid` — matches source's `sync_uid`
- Variation: `_wpsyncer_remote_variation_uid` — matches source's `sync_uid`
- Parent product: `_wpsyncer_remote_source_id` — source site ID string
- Parent product: `_wpsyncer_remote_product_id` — source's numeric product ID

### Matching algorithm (receiver)

To find the local product for an incoming payload:

1. Look up by `_wpsyncer_remote_sync_uid` matching the incoming `sync_uid`
2. If not found, look up by SKU via `wc_get_product_id_by_sku()`
3. If still not found:
   - If `create_missing_products` is `'yes'`: create a new product of the matching type
   - If `create_missing_products` is `'no'`: return an error, do not create

### Variation matching

1. Look up by `_wpsyncer_remote_variation_uid` matching the incoming `sync_uid` (scoped to the parent product)
2. If not found, look up by SKU via `wc_get_product_id_by_sku()`
3. If still not found, create a new `WC_Product_Variation` under the parent

### Variation cleanup

After processing all incoming variations:
- Enumerate existing variations on the receiver that have `_wpsyncer_remote_variation_uid` set
- Any variation with a `_wpsyncer_remote_variation_uid` that was NOT in the incoming payload is considered "removed from source"
- Apply `delete_behavior` to these orphaned variations: ignore / set draft / trash

## 5. Payload format

### Envelope

```json
{
    "schema": "wpsyncer.product_snapshot.v1",
    "event": "product.updated | product.deleted",
    "source_site_id": "store-a",
    "source_product_id": 123,
    "sent_at": "2026-05-08T10:30:00+00:00",
    "product": { ... }
}
```

### Product snapshot object

```json
{
    "sync_uid": "8b7b8e0a-...",
    "source_id": 123,
    "type": "simple|variable|external|grouped",
    "sku": "ABC-123",
    "name": "Product Name",
    "slug": "product-name",
    "status": "publish|draft|pending|private",
    "catalog_visibility": "visible|catalog|search|hidden",
    "featured": true,
    "description": "<p>Full HTML description</p>",
    "short_description": "<p>Short excerpt</p>",
    "regular_price": "29.99",
    "sale_price": "24.99",
    "date_on_sale_from": "2026-05-01T00:00:00+00:00",
    "date_on_sale_to": "2026-05-31T23:59:59+00:00",
    "tax_status": "taxable|shipping|none",
    "tax_class": "",
    "manage_stock": true,
    "stock_quantity": 50,
    "stock_status": "instock|outofstock|onbackorder",
    "backorders": "no|notify|yes",
    "sold_individually": false,
    "weight": "1.5",
    "dimensions": {
        "length": "10",
        "width": "20",
        "height": "5"
    },
    "shipping_class": "standard-shipping",
    "purchase_note": "Thank you",
    "menu_order": 0,
    "categories": [
        { "slug": "hoodies", "name": "Hoodies" }
    ],
    "tags": [
        { "slug": "cotton", "name": "Cotton" }
    ],
    "attributes": [
        {
            "name": "Color",
            "slug": "color",
            "taxonomy": true,
            "position": 0,
            "visible": true,
            "variation": true,
            "options": [
                { "slug": "blue", "name": "Blue" },
                { "slug": "red", "name": "Red" }
            ]
        }
    ],
    "default_attributes": { "pa_color": "blue" },
    "images": {
        "featured": {
            "source_attachment_id": 456,
            "url": "https://source.example.com/wp-content/uploads/2026/05/hoodie.jpg",
            "filename": "hoodie.jpg",
            "alt": "Blue hoodie",
            "title": "Hoodie"
        },
        "gallery": [
            {
                "source_attachment_id": 457,
                "url": "https://...",
                "filename": "hoodie-back.jpg",
                "alt": "",
                "title": ""
            }
        ]
    },
    "meta": {
        "_custom_size_chart": "...",
        "_supplier_code": "SUP-001"
    },
    "variations": [ ... ]
}
```

### Variation object (inside `variations[]`)

```json
{
    "sync_uid": "abc-def-...",
    "source_id": 789,
    "sku": "ABC-123-BLUE-L",
    "status": "publish",
    "description": "",
    "regular_price": "29.99",
    "sale_price": "",
    "date_on_sale_from": null,
    "date_on_sale_to": null,
    "manage_stock": true,
    "stock_quantity": 10,
    "stock_status": "instock",
    "backorders": "no",
    "weight": "1.5",
    "dimensions": { "length": "", "width": "", "height": "" },
    "attributes": { "pa_color": "blue", "pa_size": "large" },
    "image": {
        "source_attachment_id": 458,
        "url": "https://...",
        "filename": "hoodie-blue.jpg",
        "alt": "",
        "title": ""
    },
    "meta": {
        "_custom_field": "value"
    }
}
```

### Delete payload

When a product is trashed/deleted on the source:

```json
{
    "schema": "wpsyncer.product_snapshot.v1",
    "event": "product.deleted",
    "source_site_id": "store-a",
    "source_product_id": 123,
    "sent_at": "2026-05-08T10:30:00+00:00",
    "product": {
        "sync_uid": "8b7b8e0a-...",
        "sku": "ABC-123"
    }
}
```

## 6. Source behavior

### Product save detection

Hooks registered in `source` or `both` mode:
- `woocommerce_after_product_object_save` — fires after any product save via CRUD
- `save_post_product_variation` — fires when a variation post is saved

Both hooks enqueue an async sync job for the **parent product** (not individual variations).

### Debounce

Before enqueuing, check a transient: `wpsyncer_queue_{product_id}`. If it exists (30-second TTL), skip — the product is already queued. This prevents duplicate dispatches from rapid consecutive saves.

### Async dispatch

1. Try Action Scheduler first: `as_enqueue_async_action('wpsyncer_sync_product_async', [...], 'wpsyncer')`
2. Fallback: `wp_schedule_single_event(time() + 5, 'wpsyncer_sync_product_async', [...])`

### Delete detection

Hook: `before_delete_post`. Check if post type is `product` or `product_variation`. For variations, resolve to parent product. Dispatch a delete payload synchronously (not async), since the data will be gone after deletion.

### Sync-skip flag (bidirectional loop prevention)

When the receiver applies an incoming sync and saves a product, the subsequent save hooks would normally re-trigger a new outgoing sync — causing an infinite echo loop.

To prevent this, the receiver sets a flag before saving:

```php
define('WPSYNCER_APPLYING_SYNC', true);
// ... apply changes and save ...
// (flag cleared after save completes)
```

The source hook checks this flag:

```php
if (defined('WPSYNCER_APPLYING_SYNC') && WPSYNCER_APPLYING_SYNC) {
    return; // skip — this save came from a sync, don't send it back
}
```

## 7. Receiver behavior

### REST endpoint

- **Route:** `POST /wp-json/wpsyncer/v1/product`
- **Permission callback:** `__return_true` (auth is via HMAC, not WordPress auth)
- **Registered only in `receiver` or `both` mode**

### Request verification pipeline

1. **Shared secret check:** reject with 401 if not configured
2. **Timestamp freshness:** extract `X-WPSYNCER-Timestamp`, parse with `strtotime()`. Reject 401 if stale (>10 minutes) or unparseable.
3. **HMAC signature:** extract `X-WPSYNCER-Signature` header. Recompute `sha256=` + `hash_hmac('sha256', $timestamp . "\n" . $body, $secret)`. Compare with `hash_equals()`. Reject 401 if mismatch.

### Payload processing

1. JSON decode body. Reject 400 if invalid.
2. Validate `schema` is `wpsyncer.product_snapshot.v1`. Reject 400 if not.
3. If `event` is `product.deleted`, route to delete handler.
4. Otherwise, route to snapshot handler.

### Product creation with ID sync (experimental)

When `sync_product_ids` is `'yes'` and a product needs to be created on the receiver:

1. Check if `get_post($source_product_id)` already exists on the receiver
2. If exists:
   - Check for `_wpsyncer_remote_sync_uid` meta
   - If present: the post was created by a previous sync — safe to reuse the ID. Proceed.
   - If absent: the post belongs to something else — log an error, return `WP_Error`, do NOT overwrite.
3. If does not exist: create the product using `wp_insert_post()` with `'import_id' => $source_product_id`. This forces WordPress to use the specified ID instead of auto-incrementing.
4. Load the product as a `WC_Product` and continue normal field application.

This feature is **off by default** (`'no'`). When `'no'`, products are created with normal WordPress auto-increment IDs.

### Snapshot application pipeline

1. **Find or create product** using identity matching (section 4). If `sync_product_ids` is enabled, use the product ID sync logic above.
2. **Post-lock check:** call `wp_check_post_lock($product_id)`. If a lock exists (someone is editing), log the conflict and return a 409 Conflict response with a `retry-after` suggestion. Do NOT overwrite.
3. **Store identity metadata:** write `_wpsyncer_remote_source_id`, `_wpsyncer_remote_product_id`, `_wpsyncer_remote_sync_uid`
4. **Apply core fields** if `sync_core` is enabled
5. **Apply price fields** if `sync_prices` is enabled
6. **Apply stock fields** if `sync_stock` is enabled
7. **Apply shipping class** — always applied, by slug lookup
8. **Apply taxonomies** (categories, tags) if `sync_taxonomies` is enabled
9. **Apply attributes** if `sync_attributes` is enabled
10. **Apply images** (featured + gallery) if `sync_images` is enabled
11. **Apply custom meta** — apply the authenticated payload's meta values (the source already filtered them through its whitelist)
12. **Apply variations** if `sync_variations` is enabled AND product type is `variable`
13. **Set the sync-skip flag**, call `$product->save()`, clear the flag

All field application uses WooCommerce CRUD setter methods (`$product->set_name()`, etc.), not direct post meta writes.

### Taxonomy matching

Terms (categories, tags, attribute terms) are matched by **slug**, never by ID. If a slug does not exist on the receiver:
- If `create_missing_terms` is `'yes'`: create the term
- If `create_missing_terms` is `'no'`: skip that term assignment

### Attribute handling

Global taxonomy attributes (e.g., `pa_color`) are identified by the `taxonomy: true` flag in the payload. The receiver:
1. Normalizes attribute name to taxonomy format (`pa_` prefix)
2. Checks if the taxonomy exists
3. If not and `create_missing_terms` is enabled: calls `wc_create_attribute()` to register it
4. For each option, matches term by slug, creates if missing and allowed

Custom product attributes (`taxonomy: false`) are set directly as text options.

### Image handling

Images are matched by `{source_site_id}:{source_attachment_id}` key stored in `_wpsyncer_source_image_key` attachment meta. This prevents duplicate downloads. If not found:
1. `media_sideload_image()` downloads the image
2. Source key, URL, alt text, and title are stored in attachment meta
3. The attachment ID is assigned to the product as featured image or gallery

If the source attachment URL resolves to `localhost` or `127.0.0.1`, the payload builder rewrites it to the Docker service hostname so the receiver can fetch it across containers.

## 8. Bulk sync

### Trigger

A "Sync All Products" button on the admin settings page. Requires `manage_woocommerce` capability. Only functional in `source` or `both` mode.

### Behavior

1. Query all published products (all types) on the source site
2. Process in batches of `bulk_batch_size` (default 10)
3. Between batches, sleep for `bulk_batch_delay` seconds (default 5)
4. Each product follows the standard snapshot → dispatch flow
5. A transient `wpsyncer_bulk_sync_running` is set during the bulk sync to prevent overlapping bulk runs
6. Progress and completion are logged

### Resume safety

If a bulk sync is interrupted (timeout, server restart), running it again will re-process all published products. Since snapshots are idempotent, this is safe — it just re-sends current state.

## 9. Per-product sync button

A WordPress meta box on the product edit screen (for source/both mode) shows:
- Last sync timestamp (from log)
- Last sync result (success/error)
- Remote product ID
- **"Sync Now"** button

Clicking triggers the standard async sync for that single product. Uses admin-ajax or a small REST endpoint.

## 10. Custom meta field selector

The settings page presents a **multi-select dropdown** listing all post meta keys found on any WooCommerce product on this site. The admin selects which keys to sync.

### Meta key discovery

Query all product IDs, then collect all meta keys using `get_post_custom_keys()` or a direct SQL query for performance. Cache the result for 1 hour. Keys excluded from the list:
- Internal WordPress keys (`_edit_lock`, `_edit_last`, `_wp_old_slug`, etc.)
- WooCommerce internal keys managed by CRUD (`_price`, `_regular_price`, `_sale_price`, `_stock`, `_stock_status`, `_sku`, etc.)
- Plugin sync identity keys (`_wpsyncer_*`)

The selected keys are saved as a comma-separated or newline-separated string in `sync_meta_keys`.

## 11. Settings export / import

### Export

1. Read current `wpsyncer_settings` option
2. Wrap in a JSON envelope with version marker:

```json
{
    "schema": "wpsyncer.settings_export.v1",
    "exported_at": "2026-05-08T10:30:00+00:00",
    "source_site_id": "store-a",
    "settings": { ... full settings array ... }
}
```

3. Send as a downloadable JSON file via browser with `Content-Disposition: attachment`

The shared secret is included in the export. A warning is shown: "This export includes your shared secret. Handle the file securely."

### Import

1. Accept a JSON file upload
2. Validate `schema` is `wpsyncer.settings_export.v1`
3. Validate `settings` is an array with valid keys
4. Sanitize each setting value per the existing sanitization rules
5. Merge with defaults to fill any missing keys
6. Save to `wpsyncer_settings` option
7. Show confirmation with a diff-like summary of what changed

## 12. Logging

### Storage

Logs are stored in the `wpsyncer_logs` WordPress option as an array of log entry objects. Maximum 100 entries (oldest pruned).

### Entry format

```json
{
    "time": "2026-05-08T10:30:00+00:00",
    "level": "info|error|warn",
    "message": "Human-readable description",
    "context": {
        "product_id": 123,
        "sku": "ABC-123",
        "http_code": 200
    }
}
```

### When to log

- **info:** successful sync dispatched, successful sync received, product created
- **warn:** post-lock conflict, SKU conflict, duplicate skipped
- **error:** dispatch failure, receiver rejection, payload build failure, image import failure

Errors are additionally written to the PHP error log via `error_log()`.

### Admin display

The settings page shows the most recent log entries in reverse chronological order as a table.

## 13. Uninstall behavior

The uninstall script (`uninstall.php`) runs when the plugin is deleted via WordPress admin:

- **Delete:** `wpsyncer_settings` option and `wpsyncer_logs` option
- **Keep:** all product meta keys (`_wpsyncer_*`) — deliberately preserved so reinstallation does not break identity mappings
