<?php
/**
 * FILE:    includes/class-wpsyncer-source.php
 * PURPOSE: Source-side hooks for detecting product saves/deletes and enqueuing sync.
 * OWNS:    Save/delete detection, debounce, async enqueue, sync-skip flag check.
 * EXPORTS: WPSYNCER_Source (init, on_product_saved, sync_product, sync_single_product)
 * DOCS:    docs/spec.md (section 6: Source behavior)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Source {
    private $settings;

    public function __construct( WPSYNCER_Settings $settings ) {
        $this->settings = $settings;
    }

    public function init() {
        if ( ! in_array( $this->settings->mode(), array( 'source', 'both' ), true ) ) {
            return;
        }

        add_action( 'woocommerce_after_product_object_save', array( $this, 'on_product_saved' ), 20, 2 );
        add_action( 'save_post_product_variation', array( $this, 'on_variation_saved' ), 20, 3 );
        add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 1 );
        add_action( WPSYNCER_ASYNC_HOOK, array( $this, 'sync_product' ), 10, 2 );
    }

    public function on_product_saved( $product, $data_store ) {
        if ( defined( 'WPSYNCER_APPLYING_SYNC' ) && WPSYNCER_APPLYING_SYNC ) {
            return;
        }

        if ( ! $product instanceof WC_Product ) {
            return;
        }
        $product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $this->enqueue_product_sync( $product_id, 'product.updated' );
    }

    public function on_variation_saved( $post_id, $post, $update ) {
        if ( defined( 'WPSYNCER_APPLYING_SYNC' ) && WPSYNCER_APPLYING_SYNC ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        $parent_id = wp_get_post_parent_id( $post_id );
        if ( $parent_id ) {
            $this->enqueue_product_sync( $parent_id, 'product.updated' );
        }
    }

    public function on_before_delete_post( $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
            return;
        }

        $product_id = 'product_variation' === $post_type ? wp_get_post_parent_id( $post_id ) : $post_id;
        if ( ! $product_id ) {
            return;
        }

        $payload = array(
            'schema'           => 'wpsyncer.product_snapshot.v1',
            'event'            => 'product.deleted',
            'source_site_id'   => $this->settings->get( 'source_site_id', 'source-store' ),
            'source_product_id'=> (int) $product_id,
            'sent_at'          => gmdate( 'c' ),
            'product'          => array(
                'sync_uid' => get_post_meta( $product_id, '_wpsyncer_sync_uid', true ),
                'sku'      => get_post_meta( $product_id, '_sku', true ),
            ),
        );

        $dispatcher = new WPSYNCER_Dispatcher( $this->settings );
        $dispatcher->dispatch( $payload );
    }

    private function enqueue_product_sync( $product_id, $event ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return;
        }

        $transient_key = 'wpsyncer_queue_' . $product_id;
        if ( get_transient( $transient_key ) ) {
            return;
        }
        set_transient( $transient_key, 1, 30 );

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( WPSYNCER_ASYNC_HOOK, array( 'product_id' => $product_id, 'event' => $event ), 'wpsyncer' );
            WPSYNCER_Logger::log( 'info', 'Queued product sync via Action Scheduler.', array( 'product_id' => $product_id, 'event' => $event ) );
        } else {
            wp_schedule_single_event( time() + 5, WPSYNCER_ASYNC_HOOK, array( $product_id, $event ) );
            WPSYNCER_Logger::log( 'info', 'Queued product sync via WP-Cron fallback.', array( 'product_id' => $product_id, 'event' => $event ) );
        }
    }

    public function sync_product( $product_id, $event = 'product.updated' ) {
        $builder = new WPSYNCER_Payload_Builder( $this->settings );
        $payload = $builder->build_product_snapshot( absint( $product_id ), $event );

        if ( is_wp_error( $payload ) ) {
            WPSYNCER_Logger::log( 'error', 'Could not build sync payload.', $payload );
            return;
        }

        $dispatcher = new WPSYNCER_Dispatcher( $this->settings );
        $dispatcher->dispatch( $payload );
    }

    /**
     * Sync a single product immediately, bypassing debounce.
     *
     * Used by the per-product "Sync Now" button and bulk sync.
     *
     * @param int $product_id
     */
    public function sync_single_product( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return;
        }

        $builder = new WPSYNCER_Payload_Builder( $this->settings );
        $payload = $builder->build_product_snapshot( $product_id, 'product.updated' );

        if ( is_wp_error( $payload ) ) {
            WPSYNCER_Logger::log( 'error', 'Could not build sync payload for single product.', $payload );
            return;
        }

        $dispatcher = new WPSYNCER_Dispatcher( $this->settings );
        $dispatcher->dispatch( $payload );
    }
}
