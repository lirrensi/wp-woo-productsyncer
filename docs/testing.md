# Woo Product Syncer — End-to-End Testing Guide

## Prerequisites

- Docker Desktop (running)
- Python 3.10+
- The plugin code mounted at `tests/../` (repo root)

## Quick Start

```bash
python tests/scripts/run-tests.py
```

This runner is cross-platform and replaces the old PowerShell-only suite.

**First run is slow** (~5-10 min): Docker pulls the WordPress 6.8 image and WooCommerce zip. Subsequent runs are instant (cached).

---

## Architecture

```
┌──────────────────────┐         ┌──────────────────────┐
│   Source             │  HTTP   │   Receiver            │
│   localhost:8080     │ ──────► │   localhost:8081      │
│                      │  POST   │                       │
│  MySQL: db_source    │         │  MySQL: db_receiver   │
│  WordPress 6.8       │         │  WordPress 6.8        │
│  WooCommerce 10.7    │         │  WooCommerce 10.7     │
│  Plugin: source mode │         │  Plugin: receiver mode│
└──────────────────────┘         └──────────────────────┘
```

Docker Compose project name: `wpsyncer-test`
Internal cross-container base URL: `http://receiver` (plugin appends the REST path)

---

## Manual Test Walkthrough

If the automated script fails or you need to debug step by step, here is the exact procedure that works.

### 1. Start Environment

```powershell
cd tests
docker compose -p wpsyncer-test up -d
```

Wait for both containers to become healthy:

```powershell
docker ps --filter "name=wpsyncer" --format "table {{.Names}}\t{{.Status}}"
```

### 2. Install WordPress on Both Sites

```powershell
docker exec wpsyncer-test-source-1 wp core install --url='http://localhost:8080' --title='WPSyncer Source' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --allow-root
docker exec wpsyncer-test-source-1 wp rewrite structure '/%postname%/' --allow-root

docker exec wpsyncer-test-receiver-1 wp core install --url='http://localhost:8081' --title='WPSyncer Receiver' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --allow-root
docker exec wpsyncer-test-receiver-1 wp rewrite structure '/%postname%/' --allow-root
```

### 3. Install WooCommerce (direct zip, bypasses version checks)

```powershell
docker exec wpsyncer-test-source-1 wp plugin install https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip --activate --allow-root
docker exec wpsyncer-test-receiver-1 wp plugin install https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip --activate --allow-root
```

**Important:** On WordPress 6.7 and earlier, the `wp plugin install woocommerce --activate` command fails because WooCommerce requires WP 6.8+. Always use the direct zip URL to bypass this check.

### 4. Activate the Plugin

```powershell
docker exec wpsyncer-test-source-1 wp plugin activate woo-product-syncer --allow-root
docker exec wpsyncer-test-receiver-1 wp plugin activate woo-product-syncer --allow-root
```

### 5. Configure Plugin Settings

Source mode:

```powershell
$src = @{
    mode = "source"
    source_site_id = "test-source"
    target_url = "http://receiver"
    shared_secret = "test-shared-secret-2026"
    create_missing_products = "yes"
    create_missing_terms = "yes"
    sync_core = "yes"
    sync_prices = "yes"
    sync_stock = "yes"
    sync_taxonomies = "yes"
    sync_attributes = "yes"
    sync_variations = "yes"
    sync_images = "no"
    sync_meta_keys = "_test_meta_field"
    delete_behavior = "draft"
    debug_logging = "yes"
    sync_product_ids = "no"
    bulk_batch_size = 10
    bulk_batch_delay = 5
} | ConvertTo-Json -Compress
docker exec wpsyncer-test-source-1 wp option update wpsyncer_settings "$src" --format=json --allow-root
```

Receiver mode:

```powershell
$rcv = @{
    mode = "receiver"
    source_site_id = "test-receiver"
    target_url = ""
    shared_secret = "test-shared-secret-2026"
    create_missing_products = "yes"
    create_missing_terms = "yes"
    sync_core = "yes"
    sync_prices = "yes"
    sync_stock = "yes"
    sync_taxonomies = "yes"
    sync_attributes = "yes"
    sync_variations = "yes"
    sync_images = "yes"
    sync_meta_keys = "_test_meta_field"
    delete_behavior = "draft"
    debug_logging = "yes"
    sync_product_ids = "no"
    bulk_batch_size = 10
    bulk_batch_delay = 5
} | ConvertTo-Json -Compress
docker exec wpsyncer-test-receiver-1 wp option update wpsyncer_settings "$rcv" --format=json --allow-root
```

**Heads up:** When serializing settings in PowerShell, `ConvertTo-Json` can escape newlines in `sync_meta_keys`. If the whitelist contains multiple keys, set them as a JSON array element or use the textarea format directly.

### 6. Create Test Products on Source

Create a simple product:

```powershell
docker exec wpsyncer-test-source-1 wp wc product create --user=admin --allow-root --name='Synced Simple Product' --type=simple --regular_price=29.99 --sale_price=24.99 --sku='SYNC-SIMPLE-001' --manage_stock=true --stock_quantity=100 --weight=1.5
```

Create categories and terms:

```powershell
docker exec wpsyncer-test-source-1 wp term create product_cat 'Test Category' --slug=test-cat --allow-root
docker exec wpsyncer-test-source-1 wp term create product_tag 'Test Tag' --slug=test-tag --allow-root
```

Assign them (get the product ID first via `wp post list`):

```powershell
$simpleId = docker exec wpsyncer-test-source-1 wp post list --post_type=product --posts_per_page=1 --meta_key=_sku --meta_value=SYNC-SIMPLE-001 --allow-root --format=ids
docker exec wpsyncer-test-source-1 wp post term set $simpleId product_cat test-cat --allow-root
docker exec wpsyncer-test-source-1 wp post meta update $simpleId _test_meta_field 'test-meta-value-001' --allow-root
```

Create a variable product:

```powershell
docker exec wpsyncer-test-source-1 wp wc product create --user=admin --allow-root --name='Synced Variable Product' --type=variable --sku='SYNC-VAR-001'
```

Create attribute terms, then assign the attribute to the variable product and create a variation:

```powershell
docker exec wpsyncer-test-source-1 wp term create pa_color 'Color' --slug=color --allow-root
docker exec wpsyncer-test-source-1 wp term create pa_color 'Red' --slug=red --allow-root
$varId = docker exec wpsyncer-test-source-1 wp post list --post_type=product --posts_per_page=1 --meta_key=_sku --meta_value=SYNC-VAR-001 --allow-root --format=ids
docker exec wpsyncer-test-source-1 wp post meta update $varId _product_attributes 'a:1:{s:8:"pa_color";a:6:{s:4:"name";s:8:"pa_color";s:5:"value";s:0:"";s:8:"position";i:0;s:10:"is_visible";i:1;s:12:"is_variation";i:1;s:11:"is_taxonomy";i:1;}}' --allow-root
$varPostId = docker exec wpsyncer-test-source-1 wp post create --post_type=product_variation --post_parent=$varId --post_title='Variation #1' --post_status=publish --allow-root --porcelain
docker exec wpsyncer-test-source-1 wp post meta set $varPostId _sku "SYNC-VAR-RED" --allow-root
docker exec wpsyncer-test-source-1 wp post meta set $varPostId _regular_price 34.99 --allow-root
docker exec wpsyncer-test-source-1 wp post meta set $varPostId _stock 15 --allow-root
docker exec wpsyncer-test-source-1 wp post meta set $varPostId attribute_pa_color red --allow-root
```

### 7. Trigger Sync

The WooCommerce CRUD hook `woocommerce_after_product_object_save` fires when a product is saved via `WC_Product::save()`. Use `wp wc product update` (not `wp post update`) to trigger it:

```powershell
docker exec wpsyncer-test-source-1 wp wc product update $simpleId --name="Synced Simple Product" --user=admin --allow-root
docker exec wpsyncer-test-source-1 wp wc product update $varId --name="Synced Variable Product" --user=admin --allow-root
```

Alternatively, call the sync directly:

```powershell
docker exec wpsyncer-test-source-1 wp eval 'require_once "/var/www/html/wp-content/plugins/woo-product-syncer/woo-product-syncer.php"; WPSYNCER_Plugin::instance()->source->sync_product($productId, "product.updated");' --allow-root
```

### 8. Verify on Receiver

Check products arrived:

```powershell
docker exec wpsyncer-test-receiver-1 wp post list --post_type=product --posts_per_page=20 --allow-root --format=table
docker exec wpsyncer-test-receiver-1 wp post list --post_type=product_variation --posts_per_page=20 --allow-root --format=table
```

Verify individual fields:

```powershell
docker exec wpsyncer-test-receiver-1 wp post get <id> --field=post_title --allow-root
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _sku --allow-root
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _regular_price --allow-root
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _test_meta_field --allow-root
docker exec wpsyncer-test-receiver-1 wp post term list <id> product_cat --field=slug --allow-root
```

Check sync identity mapping:

```powershell
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _wpsyncer_remote_sync_uid --allow-root
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _wpsyncer_remote_source_id --allow-root
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _wpsyncer_remote_product_id --allow-root
```

Check sync logs:

```powershell
docker exec wpsyncer-test-receiver-1 wp option get wpsyncer_logs --format=json --allow-root | ConvertFrom-Json
docker exec wpsyncer-test-source-1 wp option get wpsyncer_logs --format=json --allow-root | ConvertFrom-Json
```

---

## Known Gotchas

| Problem | Cause | Fix |
|---|---|---|
| `wp plugin install woocommerce` fails | WordPress version check rejects plugin | Use direct zip URL: `https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip` |
| `wp_post_lock()` fatal error on REST API | Function is admin-only | Our `WPSYNCER_Conflict` reads `_edit_lock` meta directly (fixed) |
| Sync not triggering on `wp post update` | WordPress post update bypasses WooCommerce CRUD | Use `wp wc product update` or direct `sync_product()` call |
| Product IDs not captured in automation scripts | JSON regex mismatch from `wp wc` CLI | Use `wp post list --meta_key=_sku --meta_value=... --format=ids` instead |
| Settings import messes up `sync_meta_keys` | PowerShell escaping of newlines in JSON | Set value directly via `wp option update` |

---

## During Development: Quick Rebuild

When you change PHP files on the host (bind-mounted at `wp-content/plugins/woo-product-syncer`), changes are reflected immediately in both containers. No rebuild needed. Just re-run the sync.

---

## Teardown

```powershell
cd tests
docker compose -p wpsyncer-test down -v    # removes containers + volumes
docker compose -p wpsyncer-test down        # keeps volumes (faster restart)
```
