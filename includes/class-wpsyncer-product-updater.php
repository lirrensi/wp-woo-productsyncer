<?php
/**
 * FILE:    includes/class-wpsyncer-product-updater.php
 * PURPOSE: Apply incoming product snapshots to local WooCommerce products.
 * OWNS:    Snapshot field application, conflict detection, ID-mapped creation, variation handling.
 * EXPORTS: WPSYNCER_Product_Updater::apply_payload()
 * DOCS:    docs/spec.md (section 7: Snapshot application pipeline)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Product_Updater {
    private $settings;

    public function __construct( WPSYNCER_Settings $settings ) {
        $this->settings = $settings;
    }

    public function apply_payload( array $payload ) {
        $event = $payload['event'] ?? '';
        if ( 'product.deleted' === $event ) {
            return $this->apply_delete( $payload );
        }
        return $this->apply_snapshot( $payload );
    }

    private function apply_snapshot( array $payload ) {
        $source_id = sanitize_key( $payload['source_site_id'] ?? '' );
        $data = $payload['product'] ?? array();
        if ( empty( $data['sync_uid'] ) && empty( $data['sku'] ) ) {
            return new WP_Error( 'wpsyncer_missing_identity', 'Payload has no sync UID or SKU.', array( 'status' => 422 ) );
        }

        $product = $this->find_or_create_product( $data );
        if ( is_wp_error( $product ) ) {
            return $product;
        }

        $lock_check = WPSYNCER_Conflict::check_lock( $product->get_id() );
        if ( is_wp_error( $lock_check ) ) {
            return $lock_check;
        }

        update_post_meta( $product->get_id(), '_wpsyncer_remote_source_id', $source_id );
        update_post_meta( $product->get_id(), '_wpsyncer_remote_product_id', absint( $data['source_id'] ?? 0 ) );
        if ( ! empty( $data['sync_uid'] ) ) {
            update_post_meta( $product->get_id(), '_wpsyncer_remote_sync_uid', sanitize_text_field( $data['sync_uid'] ) );
        }

        if ( $this->settings->bool( 'sync_core' ) ) {
            $this->apply_core_fields( $product, $data );
        }
        if ( $this->settings->bool( 'sync_prices' ) ) {
            $this->apply_price_fields( $product, $data );
        }
        if ( $this->settings->bool( 'sync_stock' ) ) {
            $this->apply_stock_fields( $product, $data );
        }

        if ( ! empty( $data['shipping_class'] ) && taxonomy_exists( 'product_shipping_class' ) ) {
            $term = get_term_by( 'slug', sanitize_title( $data['shipping_class'] ), 'product_shipping_class' );
            if ( $term ) {
                $product->set_shipping_class_id( $term->term_id );
            }
        }

        if ( ! defined( 'WPSYNCER_APPLYING_SYNC' ) ) {
            define( 'WPSYNCER_APPLYING_SYNC', true );
        }
        $product->save();

        if ( $this->settings->bool( 'sync_taxonomies' ) ) {
            $this->apply_terms( $product->get_id(), 'product_cat', $data['categories'] ?? array() );
            $this->apply_terms( $product->get_id(), 'product_tag', $data['tags'] ?? array() );
        }

        if ( $this->settings->bool( 'sync_attributes' ) && ! empty( $data['attributes'] ) ) {
            $this->apply_attributes( $product, $data['attributes'], $data['default_attributes'] ?? array() );
            $product->save();
        }

        if ( $this->settings->bool( 'sync_images' ) && ! empty( $data['images'] ) ) {
            WPSYNCER_Image_Importer::apply_product_images( $product, $data['images'], $source_id );
            $product->save();
        }

        $this->apply_custom_meta( $product->get_id(), $data['meta'] ?? array() );

        if ( $product->is_type( 'variable' ) && $this->settings->bool( 'sync_variations' ) ) {
            $this->apply_variations( $product, $data['variations'] ?? array(), $source_id );
        }

        WPSYNCER_Logger::log( 'info', 'Applied product snapshot.', array( 'product_id' => $product->get_id(), 'sku' => $product->get_sku() ) );
        return array( 'product_id' => $product->get_id() );
    }

    private function find_or_create_product( array $data ) {
        $product_id = 0;
        if ( ! empty( $data['sync_uid'] ) ) {
            $product_id = $this->find_product_by_meta( '_wpsyncer_remote_sync_uid', sanitize_text_field( $data['sync_uid'] ), 'product' );
        }
        if ( ! $product_id && ! empty( $data['sku'] ) ) {
            $product_id = wc_get_product_id_by_sku( wc_clean( $data['sku'] ) );
        }

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                return $product;
            }
        }

        if ( ! $this->settings->bool( 'create_missing_products' ) ) {
            return new WP_Error( 'wpsyncer_product_not_found', 'Product not found and create_missing_products is disabled.', array( 'status' => 404 ) );
        }

        $sync_ids = $this->settings->bool( 'sync_product_ids' );
        if ( $sync_ids ) {
            return WPSYNCER_ProductFactory::create_product( $data, true, $this->settings );
        }

        $type = sanitize_key( $data['type'] ?? 'simple' );
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

    private function apply_core_fields( WC_Product $product, array $data ) {
        if ( isset( $data['name'] ) ) {
            $product->set_name( wp_kses_post( $data['name'] ) );
        }
        if ( isset( $data['slug'] ) && '' !== $data['slug'] ) {
            $product->set_slug( sanitize_title( $data['slug'] ) );
        }
        if ( isset( $data['status'] ) ) {
            $product->set_status( sanitize_key( $data['status'] ) );
        }
        if ( isset( $data['catalog_visibility'] ) ) {
            $product->set_catalog_visibility( sanitize_key( $data['catalog_visibility'] ) );
        }
        if ( isset( $data['featured'] ) ) {
            $product->set_featured( (bool) $data['featured'] );
        }
        if ( isset( $data['description'] ) ) {
            $product->set_description( wp_kses_post( $data['description'] ) );
        }
        if ( isset( $data['short_description'] ) ) {
            $product->set_short_description( wp_kses_post( $data['short_description'] ) );
        }
        if ( isset( $data['tax_status'] ) ) {
            $product->set_tax_status( sanitize_key( $data['tax_status'] ) );
        }
        if ( isset( $data['tax_class'] ) ) {
            $product->set_tax_class( sanitize_title( $data['tax_class'] ) );
        }
        if ( isset( $data['sold_individually'] ) ) {
            $product->set_sold_individually( (bool) $data['sold_individually'] );
        }
        if ( isset( $data['weight'] ) ) {
            $product->set_weight( wc_clean( $data['weight'] ) );
        }
        if ( isset( $data['dimensions'] ) && is_array( $data['dimensions'] ) ) {
            $product->set_length( wc_clean( $data['dimensions']['length'] ?? '' ) );
            $product->set_width( wc_clean( $data['dimensions']['width'] ?? '' ) );
            $product->set_height( wc_clean( $data['dimensions']['height'] ?? '' ) );
        }
        if ( isset( $data['purchase_note'] ) ) {
            $product->set_purchase_note( wp_kses_post( $data['purchase_note'] ) );
        }
        if ( isset( $data['menu_order'] ) ) {
            $product->set_menu_order( absint( $data['menu_order'] ) );
        }
        if ( isset( $data['sku'] ) && '' !== $data['sku'] && $product->get_sku() !== $data['sku'] ) {
            try {
                $product->set_sku( wc_clean( $data['sku'] ) );
            } catch ( Exception $e ) {
                WPSYNCER_Logger::log( 'error', 'Could not set product SKU.', array( 'sku' => $data['sku'], 'error' => $e->getMessage() ) );
            }
        }
    }

    private function apply_price_fields( WC_Product $product, array $data ) {
        if ( isset( $data['regular_price'] ) ) {
            $product->set_regular_price( wc_format_decimal( $data['regular_price'] ) );
        }
        if ( array_key_exists( 'sale_price', $data ) ) {
            $product->set_sale_price( '' === $data['sale_price'] || null === $data['sale_price'] ? '' : wc_format_decimal( $data['sale_price'] ) );
        }
        $product->set_date_on_sale_from( $this->parse_date( $data['date_on_sale_from'] ?? null ) );
        $product->set_date_on_sale_to( $this->parse_date( $data['date_on_sale_to'] ?? null ) );
    }

    private function apply_stock_fields( WC_Product $product, array $data ) {
        if ( isset( $data['manage_stock'] ) ) {
            $product->set_manage_stock( (bool) $data['manage_stock'] );
        }
        if ( array_key_exists( 'stock_quantity', $data ) ) {
            $product->set_stock_quantity( null === $data['stock_quantity'] ? null : wc_stock_amount( $data['stock_quantity'] ) );
        }
        if ( isset( $data['stock_status'] ) ) {
            $product->set_stock_status( sanitize_key( $data['stock_status'] ) );
        }
        if ( isset( $data['backorders'] ) ) {
            $product->set_backorders( sanitize_key( $data['backorders'] ) );
        }
    }

    private function apply_terms( $product_id, $taxonomy, array $incoming_terms ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return;
        }

        $term_ids = array();
        foreach ( $incoming_terms as $incoming ) {
            $slug = sanitize_title( $incoming['slug'] ?? $incoming['name'] ?? '' );
            $name = sanitize_text_field( $incoming['name'] ?? $slug );
            if ( ! $slug ) {
                continue;
            }
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term && $this->settings->bool( 'create_missing_terms' ) ) {
                $created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
                if ( ! is_wp_error( $created ) ) {
                    $term_ids[] = absint( $created['term_id'] );
                }
            } elseif ( $term ) {
                $term_ids[] = $term->term_id;
            }
        }
        wp_set_object_terms( $product_id, $term_ids, $taxonomy );
    }

    private function apply_attributes( WC_Product $product, array $incoming_attributes, array $default_attributes ) {
        $attributes = array();
        foreach ( $incoming_attributes as $incoming ) {
            $attr = new WC_Product_Attribute();
            $is_taxonomy = ! empty( $incoming['taxonomy'] );
            $name = $incoming['name'] ?? '';
            $taxonomy = $is_taxonomy ? $this->normalize_attribute_taxonomy( $name ) : wc_clean( $name );

            if ( ! $taxonomy ) {
                continue;
            }

            if ( $is_taxonomy ) {
                $this->maybe_create_attribute_taxonomy( $taxonomy, $name );
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    WPSYNCER_Logger::log( 'error', 'Attribute taxonomy does not exist. Sync will skip it until taxonomy is available.', array( 'taxonomy' => $taxonomy ) );
                    continue;
                }

                $term_ids = array();
                $term_slugs = array();
                foreach ( $incoming['options'] ?? array() as $option ) {
                    $slug = sanitize_title( is_array( $option ) ? ( $option['slug'] ?? $option['name'] ?? '' ) : $option );
                    $label = sanitize_text_field( is_array( $option ) ? ( $option['name'] ?? $slug ) : $option );
                    if ( ! $slug ) {
                        continue;
                    }
                    $term = get_term_by( 'slug', $slug, $taxonomy );
                    if ( ! $term && $this->settings->bool( 'create_missing_terms' ) ) {
                        $created = wp_insert_term( $label, $taxonomy, array( 'slug' => $slug ) );
                        if ( ! is_wp_error( $created ) ) {
                            $term_ids[] = absint( $created['term_id'] );
                            $term_slugs[] = $slug;
                        }
                    } elseif ( $term ) {
                        $term_ids[] = $term->term_id;
                        $term_slugs[] = $term->slug;
                    }
                }
                wp_set_object_terms( $product->get_id(), $term_slugs, $taxonomy );
                $attr->set_id( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0 );
                $attr->set_name( $taxonomy );
                $attr->set_options( $term_ids );
            } else {
                $attr->set_id( 0 );
                $attr->set_name( $taxonomy );
                $attr->set_options( array_map( 'wc_clean', (array) ( $incoming['options'] ?? array() ) ) );
            }

            $attr->set_position( absint( $incoming['position'] ?? 0 ) );
            $attr->set_visible( ! empty( $incoming['visible'] ) );
            $attr->set_variation( ! empty( $incoming['variation'] ) );
            $attributes[] = $attr;
        }

        $product->set_attributes( $attributes );
        if ( method_exists( $product, 'set_default_attributes' ) ) {
            $product->set_default_attributes( $default_attributes );
        }
    }

    private function apply_variations( WC_Product $product, array $incoming_variations, $source_id ) {
        $seen_ids = array();
        foreach ( $incoming_variations as $incoming ) {
            $variation = $this->find_or_create_variation( $product, $incoming );
            if ( is_wp_error( $variation ) ) {
                WPSYNCER_Logger::log( 'error', 'Could not create or load variation.', $variation );
                continue;
            }

            $variation->set_parent_id( $product->get_id() );
            $variation->set_status( sanitize_key( $incoming['status'] ?? 'publish' ) );
            if ( isset( $incoming['description'] ) ) {
                $variation->set_description( wp_kses_post( $incoming['description'] ) );
            }
            if ( isset( $incoming['sku'] ) && '' !== $incoming['sku'] && $variation->get_sku() !== $incoming['sku'] ) {
                try {
                    $variation->set_sku( wc_clean( $incoming['sku'] ) );
                } catch ( Exception $e ) {
                    WPSYNCER_Logger::log( 'error', 'Could not set variation SKU.', array( 'sku' => $incoming['sku'], 'error' => $e->getMessage() ) );
                }
            }
            if ( $this->settings->bool( 'sync_prices' ) ) {
                $variation->set_regular_price( wc_format_decimal( $incoming['regular_price'] ?? '' ) );
                $variation->set_sale_price( '' === ( $incoming['sale_price'] ?? '' ) || null === ( $incoming['sale_price'] ?? null ) ? '' : wc_format_decimal( $incoming['sale_price'] ) );
                $variation->set_date_on_sale_from( $this->parse_date( $incoming['date_on_sale_from'] ?? null ) );
                $variation->set_date_on_sale_to( $this->parse_date( $incoming['date_on_sale_to'] ?? null ) );
            }
            if ( $this->settings->bool( 'sync_stock' ) ) {
                $variation->set_manage_stock( ! empty( $incoming['manage_stock'] ) );
                $variation->set_stock_quantity( array_key_exists( 'stock_quantity', $incoming ) && null !== $incoming['stock_quantity'] ? wc_stock_amount( $incoming['stock_quantity'] ) : null );
                $variation->set_stock_status( sanitize_key( $incoming['stock_status'] ?? 'instock' ) );
                $variation->set_backorders( sanitize_key( $incoming['backorders'] ?? 'no' ) );
            }
            if ( isset( $incoming['weight'] ) ) {
                $variation->set_weight( wc_clean( $incoming['weight'] ) );
            }
            if ( isset( $incoming['dimensions'] ) && is_array( $incoming['dimensions'] ) ) {
                $variation->set_length( wc_clean( $incoming['dimensions']['length'] ?? '' ) );
                $variation->set_width( wc_clean( $incoming['dimensions']['width'] ?? '' ) );
                $variation->set_height( wc_clean( $incoming['dimensions']['height'] ?? '' ) );
            }
            if ( isset( $incoming['attributes'] ) && is_array( $incoming['attributes'] ) ) {
                $variation->set_attributes( $this->sanitize_variation_attributes( $incoming['attributes'] ) );
            }

            $variation->save();
            $seen_ids[] = $variation->get_id();

            update_post_meta( $variation->get_id(), '_wpsyncer_remote_source_id', sanitize_key( $source_id ) );
            update_post_meta( $variation->get_id(), '_wpsyncer_remote_variation_id', absint( $incoming['source_id'] ?? 0 ) );
            if ( ! empty( $incoming['sync_uid'] ) ) {
                update_post_meta( $variation->get_id(), '_wpsyncer_remote_variation_uid', sanitize_text_field( $incoming['sync_uid'] ) );
            }
            $this->apply_custom_meta( $variation->get_id(), $incoming['meta'] ?? array() );

            if ( $this->settings->bool( 'sync_images' ) && ! empty( $incoming['image'] ) ) {
                WPSYNCER_Image_Importer::apply_variation_image( $variation, $incoming['image'], $source_id );
                $variation->save();
            }
        }

        $this->handle_missing_variations( $product, $seen_ids );
    }

    private function find_or_create_variation( WC_Product $product, array $incoming ) {
        $variation_id = 0;
        if ( ! empty( $incoming['sync_uid'] ) ) {
            $variation_id = $this->find_product_by_meta( '_wpsyncer_remote_variation_uid', sanitize_text_field( $incoming['sync_uid'] ), 'product_variation', $product->get_id() );
        }
        if ( ! $variation_id && ! empty( $incoming['sku'] ) ) {
            $variation_id = wc_get_product_id_by_sku( wc_clean( $incoming['sku'] ) );
        }

        if ( $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( $variation && $variation instanceof WC_Product_Variation ) {
                return $variation;
            }
        }

        $variation = new WC_Product_Variation();
        $variation->set_parent_id( $product->get_id() );
        return $variation;
    }

    private function handle_missing_variations( WC_Product $product, array $seen_ids ) {
        $behavior = $this->settings->get( 'delete_behavior', 'ignore' );
        if ( 'ignore' === $behavior ) {
            return;
        }

        foreach ( $product->get_children() as $child_id ) {
            if ( in_array( $child_id, $seen_ids, true ) ) {
                continue;
            }
            if ( ! get_post_meta( $child_id, '_wpsyncer_remote_variation_uid', true ) ) {
                continue;
            }
            if ( 'trash' === $behavior ) {
                wp_trash_post( $child_id );
            } elseif ( 'draft' === $behavior ) {
                $variation = wc_get_product( $child_id );
                if ( $variation ) {
                    $variation->set_status( 'draft' );
                    $variation->save();
                }
            }
        }
    }

    private function apply_delete( array $payload ) {
        $data = $payload['product'] ?? array();
        $product_id = 0;
        if ( ! empty( $data['sync_uid'] ) ) {
            $product_id = $this->find_product_by_meta( '_wpsyncer_remote_sync_uid', sanitize_text_field( $data['sync_uid'] ), 'product' );
        }
        if ( ! $product_id && ! empty( $data['sku'] ) ) {
            $product_id = wc_get_product_id_by_sku( wc_clean( $data['sku'] ) );
        }
        if ( ! $product_id ) {
            return array( 'product_id' => null );
        }

        $behavior = $this->settings->get( 'delete_behavior', 'ignore' );
        if ( ! defined( 'WPSYNCER_APPLYING_SYNC' ) ) {
            define( 'WPSYNCER_APPLYING_SYNC', true );
        }
        if ( 'trash' === $behavior ) {
            wp_trash_post( $product_id );
        } elseif ( 'draft' === $behavior ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $product->set_status( 'draft' );
                $product->save();
            }
        }
        return array( 'product_id' => $product_id );
    }

    private function apply_custom_meta( $object_id, array $incoming_meta ) {
        $incoming_keys = array();
        foreach ( $incoming_meta as $key => $value ) {
            $key = sanitize_key( $key );
            if ( '' === $key ) {
                continue;
            }
            $incoming_keys[] = $key;
            update_post_meta( $object_id, $key, $value );
        }

        $protected_exact = array(
            '_thumbnail_id',
            '_product_image_gallery',
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
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

        $existing_meta = get_post_meta( $object_id );
        foreach ( array_keys( is_array( $existing_meta ) ? $existing_meta : array() ) as $key ) {
            if ( ! is_string( $key ) || '' === $key || '_' !== substr( $key, 0, 1 ) ) {
                continue;
            }
            if ( in_array( $key, $incoming_keys, true ) || in_array( $key, $protected_exact, true ) || 0 === strpos( $key, '_wpsyncer_' ) || 0 === strpos( $key, '_wc_' ) || 0 === strpos( $key, '_wp_' ) || 0 === strpos( $key, '_product_' ) ) {
                continue;
            }
            delete_post_meta( $object_id, $key );
        }
    }

    private function find_product_by_meta( $key, $value, $post_type = 'product', $parent_id = 0 ) {
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => $key,
                    'value' => $value,
                ),
            ),
        );
        if ( $parent_id ) {
            $args['post_parent'] = absint( $parent_id );
        }
        $ids = get_posts( $args );
        return ! empty( $ids ) ? absint( $ids[0] ) : 0;
    }

    private function parse_date( $date ) {
        if ( empty( $date ) ) {
            return null;
        }
        try {
            return new WC_DateTime( $date );
        } catch ( Exception $e ) {
            return null;
        }
    }

    private function normalize_attribute_taxonomy( $name ) {
        $name = wc_attribute_taxonomy_name( str_replace( 'pa_', '', sanitize_title( $name ) ) );
        return $name;
    }

    private function maybe_create_attribute_taxonomy( $taxonomy, $label ) {
        if ( taxonomy_exists( $taxonomy ) || ! $this->settings->bool( 'create_missing_terms' ) || ! function_exists( 'wc_create_attribute' ) ) {
            return;
        }
        $slug = str_replace( 'pa_', '', $taxonomy );
        $result = wc_create_attribute( array(
            'name'         => sanitize_text_field( $label ?: $slug ),
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ) );
        if ( is_wp_error( $result ) ) {
            WPSYNCER_Logger::log( 'error', 'Could not create attribute taxonomy.', $result );
        } else {
            delete_transient( 'wc_attribute_taxonomies' );
            WPSYNCER_Logger::log( 'info', 'Created attribute taxonomy. It may become fully available on next request.', array( 'taxonomy' => $taxonomy ) );
        }
    }

    private function sanitize_variation_attributes( array $attributes ) {
        $out = array();
        foreach ( $attributes as $key => $value ) {
            $key = sanitize_title( $key );
            $out[ $key ] = is_string( $value ) ? sanitize_title( $value ) : wc_clean( $value );
        }
        return $out;
    }
}
