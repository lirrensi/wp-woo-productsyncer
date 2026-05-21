# ────────────────────────────────────────────────────────────
# Woo Product Syncer — Dev Environment Makefile
# ────────────────────────────────────────────────────────────
# One-command automation for local development & testing.
#
# Quick start:
#   make dev       — full provisioning, keep running
#   make test      — run automated end-to-end tests
#   make down      — stop containers
#   make clean     — full teardown (removes volumes)
#   make info      — show URLs & credentials
#
# Prerequisites: Docker, docker compose, bash, Python 3.10+
# ────────────────────────────────────────────────────────────

.DEFAULT_GOAL := help

# ── Project Configuration ──────────────────────────────────
PROJECT_NAME    ?= wpsyncer-test
COMPOSE_FILE    := tests/docker-compose.yml
COMPOSE_OPTS    := -f $(COMPOSE_FILE) -p $(PROJECT_NAME)

SOURCE_CONT     := $(PROJECT_NAME)-source-1
RECEIVER_CONT   := $(PROJECT_NAME)-receiver-1
SOURCE_URL      := http://localhost:8080
RECEIVER_URL    := http://localhost:8081
RECEIVER_INT    := http://receiver
SHARED_SECRET   := test-shared-secret-2026
SOURCE_ID       := test-source

# ── Colors (for pretty output) ──────────────────────────────
BOLD := $(shell tput bold 2>/dev/null || echo "")
GREEN := $(shell tput setaf 2 2>/dev/null || echo "")
YELLOW := $(shell tput setaf 3 2>/dev/null || echo "")
CYAN := $(shell tput setaf 6 2>/dev/null || echo "")
RED := $(shell tput setaf 1 2>/dev/null || echo "")
RESET := $(shell tput sgr0 2>/dev/null || echo "")

# ═══════════════════════════════════════════════════════════
# T A R G E T S
# ═══════════════════════════════════════════════════════════

.PHONY: help up dev test test-all test-basic test-edge test-receiver test-images test-cli test-both test-settings test-conflict test-logging test-legacy down clean info doctor logs shell-source shell-receiver status rebuild

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "$(CYAN)%-20s$(RESET) %s\n", $$1, $$2}'

# ── Container Lifecycle ──────────────────────────────────────

up: ## Start Docker environment (build + daemon)
	@echo "$(YELLOW)Building and starting containers...$(RESET)"
	docker compose $(COMPOSE_OPTS) build --quiet
	docker compose $(COMPOSE_OPTS) up -d
	@echo "$(GREEN)Containers started.$(RESET)"
	@$(MAKE) status

dev: ## Full dev setup: start + provision both WordPress sites (keeps running)
	@$(MAKE) up
	@$(MAKE) _wait-healthy
	@$(MAKE) _install-wordpress
	@$(MAKE) _install-woocommerce
	@$(MAKE) _configure-plugin
	@$(MAKE) info
	@echo ""
	@echo "$(GREEN)$(BOLD)✓ Dev environment is ready!$(RESET)"
	@echo "$(GREEN)  Open the URLs above and log in with admin / admin$(RESET)"
	@echo "$(GREEN)  Run 'make test' to run automated tests$(RESET)"
	@echo "$(GREEN)  Run 'make down' to stop$(RESET)"

test: test-all ## Run ALL comprehensive test modules (requires `make dev` first)
test-all: ## Run ALL comprehensive test modules (cross-platform Python runner)
	@echo "$(YELLOW)Running full test suite...$(RESET)"
	python tests/scripts/run-tests.py

test-legacy: ## Run original end-to-end tests (legacy PowerShell scripts)
	@echo "$(YELLOW)Running legacy tests via PowerShell...$(RESET)"
	pwsh -NoProfile -ExecutionPolicy Bypass -File tests/scripts/run-tests.ps1

# ── Individual Test Modules ──────────────────────────────────

test-basic: ## Module 01: Basic sync (simple, variable, grouped, external products)
	python tests/scripts/run-tests.py --module 01

test-edge: ## Module 02: Edge cases (UTF-8, wrong secret, SKU conflicts, bulk)
	python tests/scripts/run-tests.py --module 02

test-receiver: ## Module 03: Receiver toggles & delete behaviors
	python tests/scripts/run-tests.py --module 03

test-images: ## Module 04: Image sync & deduplication
	python tests/scripts/run-tests.py --module 04

test-cli: ## Module 05: WP-CLI commands
	python tests/scripts/run-tests.py --module 05

test-both: ## Module 06: Both mode & bidirectional loop prevention
	python tests/scripts/run-tests.py --module 06

test-settings: ## Module 07: Settings export/import & meta keys
	python tests/scripts/run-tests.py --module 07

test-conflict: ## Module 08: Conflict detection & post locks
	python tests/scripts/run-tests.py --module 08

test-logging: ## Module 09: Logging behavior
	python tests/scripts/run-tests.py --module 09

down: ## Stop containers (keeps volumes for fast restart)
	@echo "$(YELLOW)Stopping containers...$(RESET)"
	docker compose $(COMPOSE_OPTS) down
	@echo "$(GREEN)Containers stopped. Use 'make up' to restart.$(RESET)"

clean: ## Full teardown: stop + remove volumes (fresh start)
	@echo "$(RED)Removing containers and volumes...$(RESET)"
	docker compose $(COMPOSE_OPTS) down -v
	@echo "$(GREEN)Clean teardown complete. Use 'make dev' for fresh setup.$(RESET)"

rebuild: ## Force rebuild and restart
	@echo "$(YELLOW)Rebuilding containers...$(RESET)"
	docker compose $(COMPOSE_OPTS) build --no-cache
	docker compose $(COMPOSE_OPTS) up -d
	@echo "$(GREEN)Rebuild complete.$(RESET)"
	@$(MAKE) status

# ── Info & Diagnostics ──────────────────────────────────────

info: ## Show URLs, credentials, and useful commands
	@echo ""
	@echo "$(BOLD)╔══════════════════════════════════════════════╗$(RESET)"
	@echo "$(BOLD)║   Woo Product Syncer — Dev Environment      ║$(RESET)"
	@echo "$(BOLD)╚══════════════════════════════════════════════╝$(RESET)"
	@echo ""
	@echo "$(CYAN)Source:$(RESET)"
	@echo "  URL:       $(SOURCE_URL)"
	@echo "  Admin:     $(SOURCE_URL)/wp-admin"
	@echo "  Creds:     admin / admin"
	@echo ""
	@echo "$(CYAN)Receiver:$(RESET)"
	@echo "  URL:       $(RECEIVER_URL)"
	@echo "  Admin:     $(RECEIVER_URL)/wp-admin"
	@echo "  Creds:     admin / admin"
	@echo ""
	@echo "$(CYAN)Endpoint:$(RESET)"
	@echo "  Internal (base):      $(RECEIVER_INT)"
	@echo "  Internal (full REST): $(RECEIVER_INT)/wp-json/wpsyncer/v1/product"
	@echo "  External (base):      $(RECEIVER_URL)"
	@echo "  External (full REST): $(RECEIVER_URL)/wp-json/wpsyncer/v1/product"
	@echo ""
	@echo "$(CYAN)Containers:$(RESET)"
	@echo "  Source:    $(SOURCE_CONT)"
	@echo "  Receiver:  $(RECEIVER_CONT)"
	@echo "  DB Source: $(PROJECT_NAME)-db_source-1"
	@echo "  DB Recv:   $(PROJECT_NAME)-db_receiver-1"
	@echo ""
	@echo "$(CYAN)Quick commands:$(RESET)"
	@echo "  Shell into source:   make shell-source"
	@echo "  Shell into receiver: make shell-receiver"
	@echo "  Follow logs:         make logs"
	@echo "  Run tests:           make test"
	@echo "  Stop everything:     make down"

doctor: ## Check Docker, port availability, container health
	@echo "$(BOLD)🔍 Doctor check...$(RESET)"
	@echo ""
	@echo "$(YELLOW)── Docker ──$(RESET)"
	@docker info --format '{{.ServerVersion}}' 2>/dev/null \
		&& echo "$(GREEN)  Docker OK$(RESET)" \
		|| (echo "$(RED)  Docker is not running!$(RESET)"; exit 1)
	@echo ""
	@echo "$(YELLOW)── Ports ──$(RESET)"
	@(echo >/dev/tcp/localhost/8080) 2>/dev/null \
		&& echo "$(RED)  Port 8080 is already in use$(RESET)" \
		|| echo "$(GREEN)  Port 8080 is free$(RESET)"
	@(echo >/dev/tcp/localhost/8081) 2>/dev/null \
		&& echo "$(RED)  Port 8081 is already in use$(RESET)" \
		|| echo "$(GREEN)  Port 8081 is free$(RESET)"
	@echo ""
	@echo "$(YELLOW)── Containers ──$(RESET)"
	@$(MAKE) status

status: ## Show container status
	@docker ps --filter "name=$(PROJECT_NAME)" \
		--format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null \
		|| echo "$(YELLOW)  No running containers for this project.$(RESET)"

logs: ## Follow logs from all containers
	docker compose $(COMPOSE_OPTS) logs -f

shell-source: ## Open a bash shell in the source container
	docker exec -it $(SOURCE_CONT) bash

shell-receiver: ## Open a bash shell in the receiver container
	docker exec -it $(RECEIVER_CONT) bash

# ═══════════════════════════════════════════════════════════
# I N T E R N A L   T A R G E T S  (not for direct use)
# ═══════════════════════════════════════════════════════════

.PHONY: _wait-healthy _install-wordpress _install-woocommerce _configure-plugin _wp _wc

# ── Wait for containers to be healthy ────────────────────────
_wait-healthy:
	@echo "$(YELLOW)Waiting for source container to be healthy...$(RESET)"
	@for i in $$(seq 1 40); do \
		status=$$(docker inspect $(SOURCE_CONT) --format '{{.State.Health.Status}}' 2>/dev/null); \
		if [ "$$status" = "healthy" ]; then echo "$(GREEN)  Source OK$(RESET)"; break; fi; \
		if [ $$i -eq 40 ]; then echo "$(RED)Timeout waiting for source$(RESET)"; exit 1; fi; \
		sleep 3; \
	done
	@echo "$(YELLOW)Waiting for receiver container to be healthy...$(RESET)"
	@for i in $$(seq 1 40); do \
		status=$$(docker inspect $(RECEIVER_CONT) --format '{{.State.Health.Status}}' 2>/dev/null); \
		if [ "$$status" = "healthy" ]; then echo "$(GREEN)  Receiver OK$(RESET)"; break; fi; \
		if [ $$i -eq 40 ]; then echo "$(RED)Timeout waiting for receiver$(RESET)"; exit 1; fi; \
		sleep 3; \
	done

# ── WP-CLI helper ────────────────────────────────────────────
_wp:
	@docker exec $(1) wp $(2) --allow-root

_wc:
	@docker exec $(1) wp wc --user=admin $(2) --allow-root

# ── Install WordPress on both sites ──────────────────────────
_install-wordpress:
	@echo "$(YELLOW)Installing WordPress on source...$(RESET)"
	docker exec $(SOURCE_CONT) wp core install \
		--url='$(SOURCE_URL)' --title='WPSyncer Source' \
		--admin_user=admin --admin_password=admin \
		--admin_email=admin@example.com --skip-email --allow-root
	docker exec $(SOURCE_CONT) wp rewrite structure '/%postname%/' --allow-root
	@echo "$(GREEN)  WordPress installed on source$(RESET)"
	@echo "$(YELLOW)Installing WordPress on receiver...$(RESET)"
	docker exec $(RECEIVER_CONT) wp core install \
		--url='$(RECEIVER_URL)' --title='WPSyncer Receiver' \
		--admin_user=admin --admin_password=admin \
		--admin_email=admin@example.com --skip-email --allow-root
	docker exec $(RECEIVER_CONT) wp rewrite structure '/%postname%/' --allow-root
	@echo "$(GREEN)  WordPress installed on receiver$(RESET)"

# ── Install WooCommerce (direct zip, bypasses version checks) ─
_install-woocommerce:
	@echo "$(YELLOW)Installing WooCommerce on both sites...$(RESET)"
	docker exec $(SOURCE_CONT) wp plugin install \
		https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip \
		--activate --allow-root
	docker exec $(RECEIVER_CONT) wp plugin install \
		https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip \
		--activate --allow-root
	@echo "$(GREEN)  WooCommerce installed on both sites$(RESET)"
	@echo "$(YELLOW)Activating plugin...$(RESET)"
	docker exec $(SOURCE_CONT) wp plugin activate woo-product-syncer --allow-root
	docker exec $(RECEIVER_CONT) wp plugin activate woo-product-syncer --allow-root
	@echo "$(GREEN)  Plugin activated on both sites$(RESET)"

# ── Plugin configuration ─────────────────────────────────────
_configure-plugin:
	@echo "$(YELLOW)Configuring source mode...$(RESET)"
	docker exec $(SOURCE_CONT) wp option update wpsyncer_settings \
		'{"mode":"source","source_site_id":"$(SOURCE_ID)","target_url":"$(RECEIVER_INT)","shared_secret":"$(SHARED_SECRET)","create_missing_products":"yes","create_missing_terms":"yes","sync_core":"yes","sync_prices":"yes","sync_stock":"yes","sync_taxonomies":"yes","sync_attributes":"yes","sync_variations":"yes","sync_images":"no","sync_meta_keys":"_test_meta_field","delete_behavior":"draft","debug_logging":"yes","sync_product_ids":"no","bulk_batch_size":10,"bulk_batch_delay":5}' \
		--format=json --allow-root
	@echo "$(GREEN)  Source mode configured$(RESET)"
	@echo "$(YELLOW)Configuring receiver mode...$(RESET)"
	docker exec $(RECEIVER_CONT) wp option update wpsyncer_settings \
		'{"mode":"receiver","source_site_id":"test-receiver","target_url":"","shared_secret":"$(SHARED_SECRET)","create_missing_products":"yes","create_missing_terms":"yes","sync_core":"yes","sync_prices":"yes","sync_stock":"yes","sync_taxonomies":"yes","sync_attributes":"yes","sync_variations":"yes","sync_images":"yes","sync_meta_keys":"_test_meta_field","delete_behavior":"draft","debug_logging":"yes","sync_product_ids":"no","bulk_batch_size":10,"bulk_batch_delay":5}' \
		--format=json --allow-root
	@echo "$(GREEN)  Receiver mode configured$(RESET)"
