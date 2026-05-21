# Woo Product Syncer — Architecture

## High-level shape

```
wpsyncer/
├── woo-product-syncer.php          # Plugin entry, constants, bootstrap
├── readme.md
├── uninstall.php
├── includes/
│   ├── class-wpsyncer-plugin.php          # Singleton orchestrator
│   ├── class-wpsyncer-settings.php        # Settings CRUD, admin page, export/import
│   ├── class-wpsyncer-source.php          # Save/delete hooks, async enqueue
│   ├── class-wpsyncer-dispatcher.php      # HTTP POST + HMAC delivery
│   ├── class-wpsyncer-security.php        # HMAC sign/verify (static)
│   ├── class-wpsyncer-payload-builder.php # Full product snapshot assembly
│   ├── class-wpsyncer-receiver.php        # REST endpoint registration + request routing
│   ├── class-wpsyncer-product-updater.php # Snapshot → WC_Product application
│   ├── class-wpsyncer-image-importer.php  # Sideload + deduplicate images (static)
│   ├── class-wpsyncer-logger.php          # Log write + read (static)
│   ├── class-wpsyncer-bulk-sync.php       # Bulk sync job enumeration + batching
│   ├── class-wpsyncer-meta-field-list.php # Discover available custom meta keys
│   ├── class-wpsyncer-conflict.php        # Post lock check + deferral
│   └── class-wpsyncer-product-factory.php # Product creation with optional ID sync
├── docs/
│   ├── product.md
│   ├── spec.md
│   └── arch.md
└── agent_chat/
    └── (plan files, brief files)
```

## Component boundaries

```
┌──────────────────────────────────────────────────┐
│                WPSYNCER_Plugin                    │
│  (Singleton orchestrator, loads everything)      │
├──────────────────────────────────────────────────┤
│                                                   │
│  ┌─────────────────────┐  ┌─────────────────────┐│
│  │  WPSYNCER_Source    │  │  WPSYNCER_Receiver  ││
│  │  ───────────────    │  │  ─────────────────  ││
│  │  Hooks: save/delete │  │  REST endpoint      ││
│  │  ↓                  │  │  ↓                  ││
│  │  PayloadBuilder     │  │  ProductUpdater     ││
│  │  ↓                  │  │  ↓                  ││
│  │  Dispatcher         │  │  ImageImporter      ││
│  │                     │  │  ↓                  ││
│  │                     │  │  Conflict           ││
│  └─────────┬───────────┘  └─────────┬───────────┘│
│            │                        │             │
│  ┌─────────┴────────────────────────┴───────────┐│
│  │            Shared services                    ││
│  │  Security (HMAC)        │  Logger             ││
│  │  Settings               │  BulkSync           ││
│  │  MetaFieldList                                    ││
│  └──────────────────────────────────────────────┘│
└──────────────────────────────────────────────────┘
```

## Data flow: source → dispatcher → receiver → updater

```
Product saved (WP hook)
        │
        ▼
WPSYNCER_Source.on_product_saved()
        │ checks: sync-skip flag? mode=source|both?
        │ debounce: transient check (30s)
        ▼
WPSYNCER_Source.enqueue_product_sync()
        │ Action Scheduler → wpsyncer_sync_product_async hook
        │ (WP-Cron fallback)
        ▼
WPSYNCER_Source.sync_product()
        │
        ├─► WPSYNCER_PayloadBuilder.build_product_snapshot($product_id)
        │       │ loads WC_Product
        │       │ ensures sync UIDs exist
        │       │ normalizes local attachment URLs for container reachability
        │       │ builds full snapshot array
        │       ▼
        │   returns: envelope + product array
        │
        ├─► WPSYNCER_Dispatcher.dispatch($payload)
                │ reads target_url, shared_secret from settings
                │ json_encode payload
                │ WPSYNCER_Security::sign_body()
                │ wp_remote_post() with HMAC headers
                │ logs result
                ▼
          HTTP POST → Receiver site
```

```
HTTP POST arrives at Receiver
        │
        ▼
WPSYNCER_Receiver.handle_product(WP_REST_Request)
        │
        ├─► WPSYNCER_Security::verify_request()
        │       checks: shared_secret configured?
        │       checks: timestamp not stale?
        │       checks: HMAC signature matches?
        │       ▼
        │   returns: true or WP_Error
        │
        ├─► Schema validation (wpsyncer.product_snapshot.v1)
        │
        ├─► WPSYNCER_ProductUpdater.apply_payload($payload)
        │       │
        │       ├─► event=deleted? → apply_delete()
        │       │
        │       ├─► find_or_create_product()
        │       │       by _wpsyncer_remote_sync_uid
        │       │       by SKU fallback
        │       │       or create new WC_Product
        │       │
        │       ├─► WPSYNCER_Conflict::check_lock($product_id)
        │       │       wp_check_post_lock()
        │       │       if locked → 409 defer
        │       │
        │       ├─► Store identity meta (_wpsyncer_remote_*)
        │       │
        │       ├─► apply_core_fields()  (if sync_core)
        │       ├─► apply_price_fields() (if sync_prices)
        │       ├─► apply_stock_fields() (if sync_stock)
        │       ├─► apply_terms()        (if sync_taxonomies)
        │       ├─► apply_attributes()   (if sync_attributes)
        │       │
        │       ├─► $product->save()
        │       │
        │       ├─► WPSYNCER_ImageImporter::apply_product_images()
        │       │       (if sync_images)
        │       │
        │       ├─► apply_custom_meta()  (applies meta already filtered by source whitelist)
        │       │
        │       ├─► apply_variations()   (if sync_variations + variable)
        │       │       for each incoming variation:
        │       │         find_or_create_variation()
        │       │         apply fields
        │       │         $variation->save()
        │       │         store identity meta
        │       │       handle_missing_variations()
        │       │
        │       └─► Log success
        │
        └─► return REST response { ok: true, product_id }
```

## Sync-skip flag (bidirectional loop prevention)

```
Receiver applying sync:
  define('WPSYNCER_APPLYING_SYNC', true);
  $product->save();  // ← triggers woocommerce_after_product_object_save
  // source hooks check this constant and bail out

Source hook:
  if (defined('WPSYNCER_APPLYING_SYNC') && WPSYNCER_APPLYING_SYNC) {
      return; // don't dispatch — this save came from a sync
  }
```

## Key architectural decisions

| Decision | Rationale |
|---|---|
| Full snapshots, not diffs | Idempotent, self-healing, no fragile change detection |
| Sync UIDs, not product IDs | Product IDs are site-specific and meaningless across sites |
| Slug-based term matching | IDs differ between sites; slugs are the stable identity |
| WooCommerce CRUD, not raw DB | Woo strongly recommends CRUD; it fires proper hooks and validates data |
| Action Scheduler first, WP-Cron fallback | AS is scalable and traceable; WP-Cron is the universal fallback |
| HMAC over HTTPS | Defense in depth; HTTPS can be terminated at load balancers |
| Settings in a single option | Simple, portable, easy to export — and this is a plugin, not a large-scale app |
| Whitelist-only custom meta | Blind meta sync copies internal/edit-lock/transient keys and breaks sites |
| Product ID sync is opt-in experimental | Forcing IDs risks collisions; guarded by safety checks and clear warnings |
| Static methods for Security, Logger, ImageImporter | These are pure utilities with no instance state — simpler call sites |

## WordPress integration points

| Integration | Hook / API | Purpose |
|---|---|---|
| Product save | `woocommerce_after_product_object_save` | Detect product changes on source |
| Variation save | `save_post_product_variation` | Detect variation changes |
| Post delete | `before_delete_post` | Detect product/variation deletion |
| REST route | `register_rest_route()` on `rest_api_init` | Receiver endpoint |
| Admin menu | `add_menu_page()` with `dashicons-update` | Top-level settings page with tabs (Settings, Logs, Tools) |
| Admin meta box | `add_meta_box()` | Per-product sync button |
| Action Scheduler | `as_enqueue_async_action()` | Async dispatch (preferred) |
| WP-Cron | `wp_schedule_single_event()` | Async dispatch (fallback) |
| Post lock | `wp_check_post_lock()` | Conflict detection on receiver |
| Media sideload | `media_sideload_image()` | Image import on receiver |
| Term management | `wp_insert_term()`, `wp_set_object_terms()` | Create/map categories, tags |
| Attribute creation | `wc_create_attribute()` | Create missing attribute taxonomies |
| Options API | `get_option()`, `update_option()`, `delete_option()` | Settings and log storage |
| Transients | `get_transient()`, `set_transient()` | Debounce and bulk sync lock |
