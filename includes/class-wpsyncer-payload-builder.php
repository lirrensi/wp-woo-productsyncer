<?php
/**
 * FILE:    includes/class-wpsyncer-payload-builder.php
 * PURPOSE: Build full product snapshots for sync dispatch.
 * OWNS:    Snapshot assembly: product fields, variations, images, meta.
 * EXPORTS: WPSYNCER_Payload_Builder::build_product_snapshot()
 * DOCS:    docs/spec.md (section 5: Payload format)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Payload_Builder {
    private $settings;

    public function __construct( WPSYNCER_Settings $settings ) {
        $this->settings = $settings;
    }

    public function build_product_snapshot( $product_id, $event = 'product.updated' ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'wpsyncer_missing_product', 'Product not found.', array( 'product_id' => $product_id ) );
        }

        if ( $product->is_type( 'variation' ) ) {
            $product = wc_get_product( $product->get_parent_id() );
            if ( ! $product ) {
                return new WP_Error( 'wpsyncer_missing_parent_product', 'Variation parent product not found.' );
            }
        }

        $this->ensure_sync_uid( $product->get_id(), '_wpsyncer_sync_uid' );

        return array(
            'schema'            => 'wpsyncer.product_snapshot.v1',
            'event'             => $event,
            'source_site_id'    => $this->settings->get( 'source_site_id', 'source-store' ),
            'source_product_id' => $product->get_id(),
            'sent_at'           => gmdate( 'c' ),
            'product'           => $this->product_to_array( $product ),
        );
    }

    private function product_to_array( WC_Product $product ) {
        $data = array(
            'sync_uid'          => $this->ensure_sync_uid( $product->get_id(), '_wpsyncer_sync_uid' ),
            'source_id'         => $product->get_id(),
            'type'              => $product->get_type(),
            'sku'               => $product->get_sku(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'status'            => $product->get_status(),
            'catalog_visibility'=> $product->get_catalog_visibility(),
            'featured'          => $product->get_featured(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'regular_price'     => $this->post_meta_value( $product->get_id(), '_regular_price', $product->get_regular_price() ),
            'sale_price'        => $this->post_meta_value( $product->get_id(), '_sale_price', $product->get_sale_price() ),
            'date_on_sale_from' => $this->date_to_c( $product->get_date_on_sale_from() ),
            'date_on_sale_to'   => $this->date_to_c( $product->get_date_on_sale_to() ),
            'tax_status'        => $product->get_tax_status(),
            'tax_class'         => $product->get_tax_class(),
            'manage_stock'      => 'yes' === $this->post_meta_value( $product->get_id(), '_manage_stock', $product->get_manage_stock() ? 'yes' : 'no' ),
            'stock_quantity'    => $this->post_meta_value( $product->get_id(), '_stock', $product->get_stock_quantity() ),
            'stock_status'      => $this->post_meta_value( $product->get_id(), '_stock_status', $product->get_stock_status() ),
            'backorders'        => $this->post_meta_value( $product->get_id(), '_backorders', $product->get_backorders() ),
            'sold_individually' => $product->get_sold_individually(),
            'weight'            => $product->get_weight(),
            'dimensions'        => array(
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ),
            'shipping_class'    => $product->get_shipping_class(),
            'purchase_note'     => $product->get_purchase_note(),
            'menu_order'        => $product->get_menu_order(),
            'categories'        => $this->terms_to_array( $product->get_id(), 'product_cat' ),
            'tags'              => $this->terms_to_array( $product->get_id(), 'product_tag' ),
            'attributes'        => $this->attributes_to_array( $product ),
            'default_attributes'=> method_exists( $product, 'get_default_attributes' ) ? $product->get_default_attributes() : array(),
            'images'            => $this->images_to_array( $product ),
            'meta'              => $this->meta_to_array( $product->get_id() ),
            'variations'        => array(),
        );

        if ( $product->is_type( 'variable' ) ) {
            $data['variations'] = $this->variations_to_array( $product );
        }

        return $data;
    }

    private function terms_to_array( $product_id, $taxonomy ) {
        $terms = get_the_terms( $product_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return array();
        }

        $out = array();
        foreach ( $terms as $term ) {
            $out[] = array(
                'slug' => $term->slug,
                'name' => $term->name,
            );
        }
        return $out;
    }

    private function attributes_to_array( WC_Product $product ) {
        $out = array();
        foreach ( $product->get_attributes() as $attribute ) {
            if ( ! $attribute instanceof WC_Product_Attribute ) {
                continue;
            }

            $options = array();
            if ( $attribute->is_taxonomy() ) {
                foreach ( $attribute->get_options() as $term_id ) {
                    $term = get_term( $term_id );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $options[] = array( 'slug' => $term->slug, 'name' => $term->name );
                    }
                }
            } else {
                $options = $attribute->get_options();
            }

            $out[] = array(
                'name'      => $attribute->get_name(),
                'slug'      => sanitize_title( $attribute->get_name() ),
                'taxonomy'  => $attribute->is_taxonomy(),
                'position'  => $attribute->get_position(),
                'visible'   => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'options'   => $options,
            );
        }
        return $out;
    }

    private function variations_to_array( WC_Product $product ) {
        $out = array();
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation instanceof WC_Product_Variation ) {
                continue;
            }

            $this->ensure_sync_uid( $variation_id, '_wpsyncer_sync_variation_uid' );

            $variation_image_id = absint( get_post_meta( $variation_id, '_thumbnail_id', true ) );
            if ( ! $variation_image_id ) {
                $variation_image_id = absint( $variation->get_image_id() );
            }

            $out[] = array(
                'sync_uid'          => get_post_meta( $variation_id, '_wpsyncer_sync_variation_uid', true ),
                'source_id'         => $variation_id,
                'sku'               => $variation->get_sku(),
                'status'            => $variation->get_status(),
                'description'       => $variation->get_description(),
                'regular_price'     => $this->post_meta_value( $variation_id, '_regular_price', $variation->get_regular_price() ),
                'sale_price'        => $this->post_meta_value( $variation_id, '_sale_price', $variation->get_sale_price() ),
                'date_on_sale_from' => $this->date_to_c( $variation->get_date_on_sale_from() ),
                'date_on_sale_to'   => $this->date_to_c( $variation->get_date_on_sale_to() ),
                'manage_stock'      => 'yes' === $this->post_meta_value( $variation_id, '_manage_stock', $variation->get_manage_stock() ? 'yes' : 'no' ),
                'stock_quantity'    => $this->post_meta_value( $variation_id, '_stock', $variation->get_stock_quantity() ),
                'stock_status'      => $this->post_meta_value( $variation_id, '_stock_status', $variation->get_stock_status() ),
                'backorders'        => $this->post_meta_value( $variation_id, '_backorders', $variation->get_backorders() ),
                'weight'            => $variation->get_weight(),
                'dimensions'        => array(
                    'length' => $variation->get_length(),
                    'width'  => $variation->get_width(),
                    'height' => $variation->get_height(),
                ),
                'attributes'        => $variation->get_attributes(),
                'image'             => $this->image_to_array( $variation_image_id ),
                'meta'              => $this->meta_to_array( $variation_id ),
            );
        }
        return $out;
    }

    private function images_to_array( WC_Product $product ) {
        $gallery = array();
        $gallery_meta = (string) get_post_meta( $product->get_id(), '_product_image_gallery', true );
        $gallery_ids = array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', $gallery_meta ) ?: array() ) );
        if ( empty( $gallery_ids ) ) {
            $gallery_ids = $product->get_gallery_image_ids();
        }

        foreach ( $gallery_ids as $image_id ) {
            $gallery[] = $this->image_to_array( $image_id );
        }

        $featured_id = absint( get_post_meta( $product->get_id(), '_thumbnail_id', true ) );
        if ( ! $featured_id ) {
            $featured_id = absint( $product->get_image_id() );
        }

        return array(
            'featured' => $this->image_to_array( $featured_id ),
            'gallery'  => array_values( array_filter( $gallery ) ),
        );
    }

    private function image_to_array( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return null;
        }
        $url = $this->normalize_attachment_url( wp_get_attachment_url( $attachment_id ) );
        if ( ! $url ) {
            return null;
        }
        return array(
            'source_attachment_id' => $attachment_id,
            'url'                  => $url,
            'filename'             => basename( parse_url( $url, PHP_URL_PATH ) ),
            'alt'                  => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'title'                => get_the_title( $attachment_id ),
        );
    }

    private function meta_to_array( $object_id ) {
        $out = array();
        foreach ( $this->settings->meta_key_whitelist() as $key ) {
            $out[ $key ] = get_post_meta( $object_id, $key, true );
        }
        return $out;
    }

    private function ensure_sync_uid( $post_id, $meta_key ) {
        $uid = get_post_meta( $post_id, $meta_key, true );
        if ( ! $uid ) {
            $uid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( $post_id . microtime( true ) . wp_rand() );
            update_post_meta( $post_id, $meta_key, $uid );
        }
        return $uid;
    }

    private function date_to_c( $date ) {
        if ( ! $date instanceof WC_DateTime ) {
            return null;
        }
        return $date->date( 'c' );
    }

    private function post_meta_value( $object_id, $key, $fallback = '' ) {
        $value = get_post_meta( $object_id, $key, true );
        return '' === $value && null !== $fallback ? $fallback : $value;
    }

    private function normalize_attachment_url( $url ) {
        $url = esc_url_raw( $url );
        if ( ! $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return $url;
        }

        $host = strtolower( $parts['host'] );
        if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
            return $url;
        }

        $scheme   = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'http';
        $rewritten = $scheme . '://source';
        if ( ! empty( $parts['path'] ) ) {
            $rewritten .= $parts['path'];
        }
        if ( ! empty( $parts['query'] ) ) {
            $rewritten .= '?' . $parts['query'];
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $rewritten .= '#' . $parts['fragment'];
        }

        return $rewritten;
    }
}
