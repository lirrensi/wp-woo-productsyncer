<?php
/**
 * FILE:    includes/class-wpsyncer-product-factory.php
 * PURPOSE: Create WC_Product instances with optional ID sync (import_id).
 * OWNS:    Product creation logic respecting sync_product_ids experimental feature.
 * EXPORTS: WPSYNCER_ProductFactory::create_product()
 * DOCS:    docs/spec.md (section 7: Product creation with ID sync)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_ProductFactory {
    /**
     * Create a product from incoming data.
     *
     * When $sync_ids is true, attempt to use the source's product ID on the receiver
     * via wp_insert_post() with import_id. Includes safety checks to avoid overwriting
     * existing non-synced posts.
     *
     * @param array             $data     Product snapshot data (must contain 'type', 'source_id', 'name', 'sync_uid').
     * @param bool              $sync_ids Whether to attempt ID sync.
     * @param WPSYNCER_Settings $settings Settings instance for logging.
     * @return WC_Product|WP_Error
     */
    public static function create_product( array $data, $sync_ids, WPSYNCER_Settings $settings ) {
        $type = sanitize_key( $data['type'] ?? 'simple' );
        $sync_ids = (bool) $sync_ids;

        if ( $sync_ids && ! empty( $data['source_id'] ) ) {
            $source_product_id = absint( $data['source_id'] );
            $existing_post     = get_post( $source_product_id );

            if ( $existing_post ) {
                $existing_uid = get_post_meta( $existing_post->ID, '_wpsyncer_remote_sync_uid', true );
                if ( empty( $existing_uid ) ) {
                    return new WP_Error(
                        'wpsyncer_id_conflict',
                        sprintf(
                            'Cannot use import_id %d — post exists but was not created by sync. Skipping ID sync.',
                            $source_product_id
                        ),
                        array( 'status' => 409 )
                    );
                }

                $product = wc_get_product( $existing_post->ID );
                if ( $product ) {
                    return $product;
                }
            }

            $post_id = wp_insert_post(
                array(
                    'import_id'  => $source_product_id,
                    'post_type'  => 'product',
                    'post_title' => $data['name'] ?? '',
                    'post_status' => 'draft',
                ),
                true
            );

            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            $product = wc_get_product( $post_id );
            if ( ! $product ) {
                return new WP_Error( 'wpsyncer_product_creation_failed', 'Failed to load product after creation with import_id.', array( 'status' => 500 ) );
            }

            return $product;
        }

        switch ( $type ) {
            case 'variable':
                $product = new WC_Product_Variable();
                break;
            case 'external':
                $product = new WC_Product_External();
                break;
            case 'grouped':
                $product = new WC_Product_Grouped();
                break;
            case 'simple':
            default:
                $product = new WC_Product_Simple();
                break;
        }

        return $product;
    }
}
