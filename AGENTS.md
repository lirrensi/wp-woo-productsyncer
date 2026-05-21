# Woo Product Syncer — Agent Guide

This file tells any AI agent (including future-me) how to work with this repo.

---

## What This Is

A WordPress plugin that syncs WooCommerce products between stores. It runs in four modes:

| Mode | Role |
|------|------|
| **Source** | Sends product snapshots to a receiver |
| **Receiver** | Accepts incoming snapshots and applies them |
| **Both** | Sends AND receives (with loop prevention) |
| **Disabled** | Plugin inactive |

---

## Quick Start for Agents

### Prerequisites

- Docker Desktop (must be running)
- `make` (available via Git Bash / WSL on Windows, or natively on macOS/Linux)
- `python` 3.10+ — for the test runner

### One-Command Dev Environment

```bash
make dev
```

This single command **does all of the following**:

1. Builds Docker images for two WordPress instances
2. Starts both containers + their MySQL databases
3. Waits for them to be healthy
4. Installs WordPress on **source** (port 8080) and **receiver** (port 8081)
5. Installs WooCommerce (latest stable) on both
6. Activates the **woo-product-syncer** plugin on both
7. Configures the source with shared secret, target URL, and sync groups
8. Configures the receiver with matching settings
9. Prints URLs, credentials, and next steps
10. **Leaves the environment running** for manual testing

**First run:** ~5-10 minutes (pulls Docker images + WooCommerce zip).
**Subsequent runs:** ~30 seconds (all cached).

### Run Automated Tests

Choose from these test targets:

```bash
make test            # Full comprehensive suite via Python
make test-all        # Alias for the full suite
make test-basic      # Module 01: Basic sync (simple, variable, grouped, external)
make test-edge       # Module 02: Edge cases (UTF-8, wrong secret, conflicts)
make test-receiver   # Module 03: Receiver toggles & delete behaviors
make test-images     # Module 04: Image sync & deduplication
make test-cli        # Module 05: WP-CLI commands
make test-both       # Module 06: Both mode & bidirectional loop prevention
make test-settings   # Module 07: Settings export/import & meta keys
make test-conflict   # Module 08: Conflict detection & post locks
make test-logging    # Module 09: Logging behavior
```

Direct runner:

```bash
python tests/scripts/run-tests.py
python tests/scripts/run-tests.py --module 05
python tests/scripts/run-tests.py --keep-running
```

### Test Suite Architecture

The comprehensive test suite is modular, split into 9 focused modules plus a shared framework:

| Module | File | What It Tests |
|--------|------|---------------|
| **Common** | `tests/scripts/run-tests.py` | Shared cross-platform runner, helpers, assertions |
| **Module 01** | `tests/scripts/run-tests.py` | Simple, variable (color+size), grouped, external products — field-level verification, idempotency |
| **Module 02** | `tests/scripts/run-tests.py` | UTF-8/emoji names, wrong shared secret (401), unauthorized source (403), empty target_url, no-SKU products, 10K+ descriptions, 5+ categories, duplicate SKU, bulk sync |
| **Module 03** | `tests/scripts/run-tests.py` | `create_missing_products=no`, `create_missing_terms=no`, `delete_behavior` (ignore/trash/draft), individual sync toggles (`sync_core`, `sync_prices`, `sync_stock`, `sync_taxonomies`), `sync_product_ids=yes` |
| **Module 04** | `tests/scripts/run-tests.py` | Featured image sync, gallery sync, variation-specific images, image deduplication (re-sync same image), `sync_images=no` suppression |
| **Module 05** | `tests/scripts/run-tests.py` | `wp wpsyncer status`, `config get/set`, `sync` (async + --wait), `run` (bulk), `logs` (filtered), `configure --yes`, invalid values |
| **Module 06** | `tests/scripts/run-tests.py` | Both mode on both sites, create on source → arrives on receiver, create on receiver → arrives on source, verify NO infinite loop, update propagation without echo |
| **Module 07** | `tests/scripts/run-tests.py` | Export JSON format, import validation, missing field defaults, bad schema rejection, meta key whitelist (multiple keys, non-whitelisted filtering), bulk batch size override |
| **Module 08** | `tests/scripts/run-tests.py` | Post lock simulation via `_edit_lock`, verify 409 rejection, verify conflict logged, verify sync succeeds after lock cleared |
| **Module 09** | `tests/scripts/run-tests.py` | Log entry format (ISO 8601, level, message), error_log propagation, log rotation (max 100), `debug_logging=no` suppression, re-enable after suppression |

### Key Test Files

| File | Lines | Purpose |
|------|-------|---------|
| `tests/scripts/run-tests.py` | 1600+ | Cross-platform orchestrator, helpers, module implementations |
| `tests/scripts/run-tests.ps1` | 348 | Legacy single-script test (kept for backward compat) |
| `tests/scripts/run-all-tests.ps1` / `run-tests-*.ps1` | legacy | Legacy PowerShell suite retained for reference |

---

## Architecture

```
┌──────────────────────┐         ┌──────────────────────┐
│   Source             │  HTTP   │   Receiver            │
│   localhost:8080     │ ──────► │   localhost:8081      │
│                      │  POST   │                       │
│  MySQL: db_source    │         │  MySQL: db_receiver   │
│  WordPress 6.8       │         │  WordPress 6.8        │
│  WooCommerce latest  │         │  WooCommerce latest   │
│  Plugin: source mode │         │  Plugin: receiver mode│
└──────────────────────┘         └──────────────────────┘
```

Internal (cross-container) base URL: `http://receiver` (plugin appends REST path)

---

## Key Commands

| Command | What it does |
|---------|-------------|
| `make dev` | Full setup: start containers + provision everything |
| `make up` | Just start containers (skip provisioning) |
| `make test` | Run automated end-to-end tests |
| `make down` | Stop containers (keeps volumes) |
| `make clean` | Full teardown (removes volumes, fresh start) |
| `make info` | Show URLs, credentials, container names |
| `make doctor` | Check Docker, port availability, container health |
| `make status` | Show container status table |
| `make logs` | Follow all container logs |
| `make shell-source` | Bash into the source container |
| `make shell-receiver` | Bash into the receiver container |
| `make rebuild` | Force rebuild images from scratch |

---

## Credentials

| Site | URL | Login |
|------|-----|-------|
| Source | http://localhost:8080/wp-admin | `admin` / `admin` |
| Receiver | http://localhost:8081/wp-admin | `admin` / `admin` |

---

## Container Names

| Container | Purpose |
|-----------|---------|
| `wpsyncer-test-source-1` | Source WordPress |
| `wpsyncer-test-receiver-1` | Receiver WordPress |
| `wpsyncer-test-db_source-1` | Source MySQL |
| `wpsyncer-test-db_receiver-1` | Receiver MySQL |

---

## Key Files

| File | Purpose |
|------|---------|
| `woo-product-syncer.php` | Plugin entry point (constants + loader) |
| `includes/class-wpsyncer-plugin.php` | Singleton orchestrator |
| `includes/class-wpsyncer-settings.php` | Settings CRUD, admin page, meta box |
| `includes/class-wpsyncer-source.php` | Source-side hooks + async dispatch |
| `includes/class-wpsyncer-receiver.php` | REST endpoint for incoming snapshots |
| `includes/class-wpsyncer-dispatcher.php` | HMAC-signed HTTP POST delivery |
| `includes/class-wpsyncer-security.php` | HMAC signing and verification |
| `includes/class-wpsyncer-payload-builder.php` | Full product snapshot assembly |
| `includes/class-wpsyncer-product-updater.php` | Apply incoming snapshots |
| `includes/class-wpsyncer-cli.php` | WP-CLI commands |
| `Makefile` | Dev environment automation |
| `tests/docker-compose.yml` | Multi-container test environment |
| `tests/scripts/run-tests.ps1` | Automated end-to-end test runner |
| `tests/wordpress/Dockerfile` | Custom WordPress image with WP-CLI |

---

## Shell Commands (for debugging)

Once the environment is running, you can inspect anything:

```bash
# List products on source
docker exec wpsyncer-test-source-1 wp post list --post_type=product --allow-root

# Check a product's sync meta on receiver
docker exec wpsyncer-test-receiver-1 wp post meta get <id> _wpsyncer_remote_sync_uid --allow-root

# View sync logs
docker exec wpsyncer-test-receiver-1 wp option get wpsyncer_logs --format=json --allow-root

# Manually trigger a sync for a specific product
docker exec wpsyncer-test-source-1 wp eval '
  require_once "/var/www/html/wp-content/plugins/woo-product-syncer/woo-product-syncer.php";
  WPSYNCER_Plugin::instance()->source->sync_product(<product_id>, "product.updated");
' --allow-root
```

---

## Plugin Configuration (for reference)

The `wpsyncer_settings` option holds all config as JSON:

```json
{
  "mode": "source",
  "source_site_id": "test-source",
  "target_url": "http://receiver",
  "shared_secret": "test-shared-secret-2026",
  "create_missing_products": "yes",
  "create_missing_terms": "yes",
  "sync_core": "yes",
  "sync_prices": "yes",
  "sync_stock": "yes",
  "sync_taxonomies": "yes",
  "sync_attributes": "yes",
  "sync_variations": "yes",
  "sync_images": "no",
  "sync_meta_keys": "_test_meta_field",
  "delete_behavior": "draft",
  "debug_logging": "yes",
  "sync_product_ids": "no",
  "bulk_batch_size": 10,
  "bulk_batch_delay": 5
}
```

---

## Ignored Directories

These paths are in `.gitignore` and should never be touched:

- `agent_chat/` — conversation logs
- `private/` — local credentials, notes
- `.env` / `.env.local` — environment overrides

---

## Files That Must Never Be Modified

- **Code logic** in `includes/*.php` — that is the plugin's domain, not for a curator
- **Canonical docs** in `docs/` — product, spec, and architecture belong to Thoth
- **Existing tests** — new tests belong to Osiris

---

## Workflow

1. `make dev` → environment is ready
2. `make test` → validate end-to-end
3. `make shell-source` / `make shell-receiver` → debug
4. `make down` → stop when done
