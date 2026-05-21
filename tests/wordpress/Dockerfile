FROM wordpress:6.8-php8.3

# Install WP-CLI
RUN curl -sL -o /usr/local/bin/wp \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Helper tools for test scripts
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip less jq curl mariadb-client \
    && rm -rf /var/lib/apt/lists/*

HEALTHCHECK --interval=5s --timeout=3s --start-period=25s --retries=15 \
    CMD curl -sf http://localhost/wp-admin/install.php > /dev/null 2>&1 || exit 1
