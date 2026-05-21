<?php
/**
 * FILE:    includes/class-wpsyncer-plugin.php
 * PURPOSE: Singleton orchestrator that initializes all plugin components.
 * OWNS:    Plugin bootstrap, dependency coordination, activation hook.
 * EXPORTS: WPSYNCER_Plugin::instance(), WPSYNCER_Plugin::activate()
 * DOCS:    docs/arch.md (High-level shape), docs/spec.md (Plugin identity)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Plugin {
    private static $instance = null;

    public $settings;
    public $logger;
    public $source;
    public $receiver;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        if ( ! get_option( WPSYNCER_SETTINGS_OPTION ) ) {
            add_option( WPSYNCER_SETTINGS_OPTION, WPSYNCER_Settings::defaults() );
        }
        if ( ! get_option( WPSYNCER_LOG_OPTION ) ) {
            add_option( WPSYNCER_LOG_OPTION, array() );
        }
    }

    private function __construct() {
        $this->settings = new WPSYNCER_Settings();
        $this->logger   = new WPSYNCER_Logger();

        add_action( 'admin_notices', array( $this, 'maybe_show_woocommerce_notice' ) );

        if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
            return;
        }

        $this->settings->init();

        $this->source = new WPSYNCER_Source( $this->settings );
        $this->source->init();

        $this->receiver = new WPSYNCER_Receiver( $this->settings );
        $this->receiver->init();
    }

    public function maybe_show_woocommerce_notice() {
        if ( current_user_can( 'activate_plugins' ) && ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
            echo '<div class="notice notice-warning"><p><strong>Woo Product Syncer</strong> requires WooCommerce to be active.</p></div>';
        }
    }
}
