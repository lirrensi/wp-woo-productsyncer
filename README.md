# Woo Product Syncer

> A controlled, auditable, secure WooCommerce product sync system for multi-store setups.

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-latest-96588A?logo=woocommerce)](https://woocommerce.com)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![Latest Release](https://img.shields.io/github/v/release/lirrensi/wp-woo-productsyncer)](https://github.com/lirrensi/wp-woo-productsyncer/releases)
[![Build Status](https://img.shields.io/github/actions/workflow/status/lirrensi/wp-woo-productsyncer/release.yml?label=release)](https://github.com/lirrensi/wp-woo-productsyncer/actions/workflows/release.yml)

Install the same plugin on each WooCommerce store. Configure each site with a role — **Source**, **Receiver**, **Both**, or **Disabled** — and let products flow between your stores with granular control over exactly what syncs.

---

## Features

### Four modes

| Mode | What the site does |
|------|--------------------|
| **Disabled** | Plugin is inactive on this site |
| **Source** | Watches for product changes and sends snapshots to target receiver(s) |
| **Receiver** | Exposes a REST endpoint, accepts incoming snapshots, applies them to local products |
| **Both** | Acts as Source AND Receiver simultaneously — sends out and accepts in. Prevents infinite re-sync loops via a sync-skip flag. |

### Granular sync groups

Each sync group can be toggled independently:

| Group | What's included |
|-------|-----------------|
| Core product fields | name, slug, status, catalog visibility, featured, description, short description, menu order, SKU, tax status/class, sold individually, weight, dimensions, shipping class, purchase note |
| Prices | regular price, sale price, sale date range |
| Stock | manage stock flag, stock quantity, stock status, backorders |
| Categories & Tags | assigned categories and tags, matched by slug (not ID) |
| Attributes | global taxonomy attributes (`pa_color`, `pa_size`) and custom product attributes |
| Variations | variation identity, SKU, prices, stock, weight/dimensions, attributes, status, description, image, custom meta |
| Images & Gallery | featured image and gallery images; deduplicated by source attachment ID |

### More highlights

- **Custom meta whitelist** — select exactly which meta keys to sync from a dropdown of all product meta keys. No blind "sync all meta."
- **Bulk sync** — "Sync All Products" button with configurable batch size and delay.
- **Per-product sync** — meta box on product edit screens with a "Sync Now" button.
- **Settings export/import** — portable JSON for backup or cross-site configuration.
- **Conflict protection** — respects WordPress post locks; defers sync if someone is editing.
- **Deletion handling** — configurable behavior on delete events (ignore / set draft / trash).
- **Sync logs** — last 100 entries displayed in the admin, errors also written to PHP error log.
- **Experimental: Product ID sync** — opt-in feature for fully mirrored stores where numeric IDs must match.

---

## Requirements

| Dependency | Requirement |
|------------|-------------|
| WordPress | 5.6 or higher |
| WooCommerce | Any version supporting CRUD objects |
| PHP | 7.4 or higher |
| Action Scheduler | Optional (ships with WooCommerce) — for async dispatch; falls back to WP-Cron |

---

## Installation

### Via WordPress admin

1. Download the [latest release](https://github.com/lirrensi/wp-woo-productsyncer/releases) ZIP.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate.
4. Go to **Woo Product Syncer** in the admin menu to configure.

### Via Composer (if using WordPress with Composer)

```bash
composer require custom-starter/woo-product-syncer
```

### Manual upload

1. Download and extract the ZIP into `wp-content/plugins/woo-product-syncer/`.
2. Activate the plugin from the **Plugins** screen.
3. Configure under the **Woo Product Syncer** admin menu.

---

## Quick Start

### Minimum configuration

**Source site:**
| Setting | Value |
|---------|-------|
| Mode | `source` |
| Source site ID | A unique identifier (e.g., `store-a`) |
| Target receiver URL | `https://receiver.example.com` (base URL only) |
| Shared secret | A long random string — must match the receiver |

**Receiver site:**
| Setting | Value |
|---------|-------|
| Mode | `receiver` |
| Shared secret | The same long random string as the source |
| Create missing products | `yes` |

### How it works

1. Admin saves a product on the **Source** site.
2. The plugin builds a full product snapshot (all fields, variations, images, meta).
3. The snapshot is HMAC-signed and POSTed to the Receiver's REST endpoint.
4. The Receiver verifies the signature, checks for conflicts, and applies the snapshot via WooCommerce CRUD methods.

Receiver endpoint (appended automatically from base URL):
```
https://receiver.example.com/wp-json/wpsyncer/v1/product
```

---

## Documentation

| Document | Description |
|----------|-------------|
| [Product Requirements](docs/product.md) | What the plugin does and why it exists |
| [Behavior Specification](docs/spec.md) | Detailed technical spec for every feature |
| [Architecture](docs/arch.md) | Component design, data flow, and key decisions |
| [Testing Guide](docs/testing.md) | End-to-end test setup and manual walkthrough |

---

## Changelog

### 0.3.0 (2026-05-21)
- Conflict detection with WordPress post lock support
- Bidirectional sync mode (Both) with loop prevention
- Settings export/import as portable JSON
- Meta key whitelist with discovery dropdown
- WP-CLI commands (`status`, `config`, `sync`, `run`, `logs`, `configure`)
- Image sync with deduplication
- Product deletion handling (ignore / draft / trash)
- Comprehensive 9-module test suite

### 0.2.0 (2026-05-08)
- Source and Receiver modes
- HMAC-authenticated REST endpoint
- Full product snapshot payload (all field types)
- Async dispatch via Action Scheduler / WP-Cron
- Variable product support with variation sync
- Bulk sync with batch rate limiting
- Sync logging with admin viewer
- Slug-based term matching
- Experimental product ID sync

### 0.1.0 (2026-04-XX)
- Initial prototype: basic product sync between two sites

---

## Identity Model

Product IDs differ between sites. Instead of relying on numeric IDs, each product gets a stable sync UID:

- `_wpsyncer_sync_uid` — assigned on the source, stored on both sides
- `_wpsyncer_remote_sync_uid` — stored on the receiver, matches the source's UID
- `_wpsyncer_remote_source_id` — which source site sent this product
- `_wpsyncer_remote_product_id` — source's numeric product ID

**SKU** is used as a fallback match when sync UID is not present (e.g., products that existed before the plugin was installed). Variations get their own sync UIDs following the same pattern.

---

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request.

### Development environment

```bash
make dev      # Full setup: Docker + WordPress + WooCommerce + plugin
make test     # Run automated end-to-end tests
make down     # Stop containers
```

Requires Docker Desktop, `make`, and Python 3.10+.

### Creating a release

Releases are automated via GitHub Actions. To publish a new version:

```bash
# 1. Update version in woo-product-syncer.php and composer.json
# 2. Update the changelog in README.md
# 3. Commit and push
git add -A
git commit -m "Bump version to v0.x.0"

# 4. Tag and push — the CI workflow builds the ZIP and creates the release
git tag v0.x.0
git push origin v0.x.0
```

The [Release workflow](.github/workflows/release.yml) handles the rest: builds the plugin ZIP, attaches it to the release, and generates release notes from commit history.

---

## Security

If you discover a security vulnerability, please send an email rather than opening a public issue. See [SECURITY.md](SECURITY.md) for details.

---

## License

**Woo Product Syncer** is free software distributed under the terms of the GNU General Public License v2 or later.

```text
Copyright (c) 2026 lirrensi

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

See [LICENSE](LICENSE) for the full license text.

---

*Built with care for store owners who need their products in the right places.*
