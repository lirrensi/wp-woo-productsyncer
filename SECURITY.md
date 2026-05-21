# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.3.x   | Yes       |
| < 0.3   | No        |

## Reporting a vulnerability

The Woo Product Syncer plugin handles shared secrets and product data that travels between stores. Security is a priority.

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, send an email to the maintainer. If you found this repo on GitHub, the author's email should be available from their GitHub profile.

### What to include

- A clear description of the vulnerability
- Steps to reproduce
- Affected versions
- Any potential impact

### What to expect

- You'll receive an acknowledgment within 48 hours
- A fix will be prioritized based on severity
- You'll be credited (if desired) when the fix is released

## Security features

- **HMAC-signed payloads** — every product snapshot is signed with `sha256` HMAC using a shared secret known only to the site operators
- **Timestamp freshness** — requests older than 10 minutes are rejected
- **No blind meta sync** — custom meta keys must be explicitly whitelisted
- **Conflict protection** — WordPress post locks prevent sync from overwriting in-progress edits
- **Experimental features are opt-in** — product ID sync is behind a setting toggle with safety checks

## Best practices for site operators

1. Use **HTTPS** on all sites involved in syncing
2. Generate **long random shared secrets** (32+ characters)
3. Keep the shared secret **out of version control** — set it via the admin settings page
4. Review **sync logs** regularly for unexpected activity
5. Start with **sync_images: no** and enable it after verifying core field sync
