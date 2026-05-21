# Contributing to Woo Product Syncer

Thank you for your interest in contributing! This plugin is built for store owners who need controlled, auditable product syncing between WooCommerce stores.

## Ways to contribute

- **Report a bug** — open an issue with clear reproduction steps
- **Suggest a feature** — open an issue describing the use case
- **Fix a bug or implement a feature** — submit a pull request
- **Improve documentation** — typo fixes, clarifications, examples

## Development setup

```bash
# Prerequisites: Docker Desktop, make, Python 3.10+
make dev     # Full dev environment with two WordPress sites
make test    # Run automated end-to-end tests
make down    # Stop containers
```

The `make dev` command provisions two WordPress instances (source + receiver), installs WooCommerce, activates the plugin, and configures both sites. See the [Makefile](Makefile) for all available commands.

## Pull request guidelines

1. **One concern per PR** — keep changes focused
2. **Tests pass** — run `make test` before submitting
3. **Match the code style** — the project uses WordPress PHP coding standards (see `phpcs.xml`)
4. **Update docs** — if you change behavior, update the relevant docs in `docs/`
5. **No breaking changes to settings schema** — existing configurations should migrate seamlessly

## Code structure

| Path | Purpose |
|------|---------|
| `woo-product-syncer.php` | Plugin entry point, constants, bootstrap |
| `includes/class-wpsyncer-*.php` | Plugin logic (see docs/arch.md for dependency map) |
| `docs/` | Product requirements, spec, architecture, testing guide |
| `tests/` | Docker environment + Python test suite |

## Reporting issues

Please include:

- WordPress version
- WooCommerce version
- PHP version
- Plugin mode (source / receiver / both)
- Steps to reproduce
- Expected vs actual behavior
- Relevant sync logs (from the settings page)

## Code of conduct

Be respectful, constructive, and assume good faith. This is a small project maintained by people who care about quality.

## License

By contributing, you agree that your contributions will be licensed under the GPL v2 or later, same as the plugin itself.
