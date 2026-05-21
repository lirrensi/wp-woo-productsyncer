<?php
/**
 * Plugin Name: Woo Product Syncer
 * Description: Sync WooCommerce products between stores with source, receiver, and bidirectional modes.
 * Version: 0.3.0
 * Author: lirrensi + DeepSeekusV4
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Text Domain: woo-product-syncer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPSYNCER_VERSION', '0.3.0');
define('WPSYNCER_FILE', __FILE__);
define('WPSYNCER_PATH', plugin_dir_path(__FILE__));
define('WPSYNCER_URL', plugin_dir_url(__FILE__));
define('WPSYNCER_SETTINGS_OPTION', 'wpsyncer_settings');
define('WPSYNCER_LOG_OPTION', 'wpsyncer_logs');
define('WPSYNCER_ASYNC_HOOK', 'wpsyncer_sync_product_async');

require_once WPSYNCER_PATH . 'includes/class-wpsyncer-plugin.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-settings.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-logger.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-security.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-payload-builder.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-dispatcher.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-source.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-receiver.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-product-updater.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-image-importer.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-conflict.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-meta-field-list.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-bulk-sync.php';
require_once WPSYNCER_PATH . 'includes/class-wpsyncer-product-factory.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once WPSYNCER_PATH . 'includes/class-wpsyncer-cli.php';
}

register_activation_hook(__FILE__, array('WPSYNCER_Plugin', 'activate'));

add_action('plugins_loaded', array('WPSYNCER_Plugin', 'instance'));
