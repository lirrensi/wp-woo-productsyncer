<?php
/**
 * FILE:    includes/class-wpsyncer-meta-field-list.php
 * PURPOSE: Discover available custom post meta keys across all WooCommerce products.
 * OWNS:    Meta key discovery and filtering for the settings whitelist dropdown.
 * EXPORTS: WPSYNCER_MetaFieldList::get_available_keys()
 * DOCS:    docs/spec.md (section 10: Custom meta field selector)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_MetaFieldList {
    /**
     * Get all meta keys found on WooCommerce products, excluding internal keys.
     *
     * Results are cached in transient 'wpsyncer_meta_keys_cache' for 1 hour.
     *
     * @return array List of meta key strings.
     */
    public static function get_available_keys() {
        $cached = get_transient( 'wpsyncer_meta_keys_cache' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( 'publish', 'draft', 'pending', 'private' )",
                'product'
            )
        );

        if ( empty( $product_ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        $meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ( {$placeholders} ) ORDER BY meta_key",
                $product_ids
            )
        );

        if ( empty( $meta_keys ) ) {
            return array();
        }

        $excluded_prefixes = array(
            '_wpsyncer_',
            '_edit_',
            '_wp_old_',
            '_wc_',
            '_product_',
        );

        $excluded_exact = array(
            '_price',
            '_regular_price',
            '_sale_price',
            '_stock',
            '_stock_status',
            '_sku',
            '_manage_stock',
            '_sold_individually',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_tax_status',
            '_tax_class',
            '_backorders',
            '_featured',
            '_virtual',
            '_downloadable',
            '_purchase_note',
        );

        $filtered = array();
        foreach ( $meta_keys as $key ) {
            if ( in_array( $key, $excluded_exact, true ) ) {
                continue;
            }
            $skip = false;
            foreach ( $excluded_prefixes as $prefix ) {
                if ( strpos( $key, $prefix ) === 0 ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }
            $filtered[] = $key;
        }

        sort( $filtered );
        set_transient( 'wpsyncer_meta_keys_cache', $filtered, HOUR_IN_SECONDS );

        return $filtered;
    }
}
