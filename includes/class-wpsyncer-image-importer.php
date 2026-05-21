<?php
/**
 * FILE:    includes/class-wpsyncer-image-importer.php
 * PURPOSE: Sideload product and variation images from remote URLs with deduplication.
 * OWNS:    Image download, dedup by source key, metadata preservation.
 * EXPORTS: WPSYNCER_Image_Importer::apply_product_images(), WPSYNCER_Image_Importer::apply_variation_image()
 * DOCS:    docs/spec.md (section 7: Image handling)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Image_Importer {
    public static function apply_product_images( WC_Product $product, array $images, $source_id ) {
        if ( ! empty( $images['featured']['url'] ) ) {
            $image_id = self::import_image( $images['featured'], $product->get_id(), $source_id );
            if ( $image_id ) {
                $product->set_image_id( $image_id );
            }
        }

        $gallery_ids = array();
        foreach ( $images['gallery'] ?? array() as $image ) {
            if ( empty( $image['url'] ) ) {
                continue;
            }
            $image_id = self::import_image( $image, $product->get_id(), $source_id );
            if ( $image_id ) {
                $gallery_ids[] = $image_id;
            }
        }
        if ( $gallery_ids ) {
            $product->set_gallery_image_ids( $gallery_ids );
        }
    }

    public static function apply_variation_image( WC_Product_Variation $variation, array $image, $source_id ) {
        if ( empty( $image['url'] ) ) {
            return;
        }
        $image_id = self::import_image( $image, $variation->get_parent_id(), $source_id );
        if ( $image_id ) {
            $variation->set_image_id( $image_id );
        }
    }

    private static function import_image( array $image, $parent_id, $source_id ) {
        $url = esc_url_raw( $image['url'] ?? '' );
        if ( ! $url ) {
            return 0;
        }

        $source_attachment_id = absint( $image['source_attachment_id'] ?? 0 );
        $key = sanitize_key( $source_id ) . ':' . $source_attachment_id;
        if ( $source_attachment_id ) {
            $existing = self::find_attachment_by_source_key( $key );
            if ( $existing ) {
                return $existing;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            WPSYNCER_Logger::log( 'error', 'Image download failed.', array( 'url' => $url, 'error' => $response->get_error_message() ) );
            return 0;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            WPSYNCER_Logger::log( 'error', 'Image download failed.', array( 'url' => $url, 'http_code' => $code ) );
            return 0;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            WPSYNCER_Logger::log( 'error', 'Image download failed.', array( 'url' => $url, 'error' => 'Empty response body.' ) );
            return 0;
        }

        $filename = ! empty( $image['filename'] ) ? sanitize_file_name( $image['filename'] ) : basename( wp_parse_url( $url, PHP_URL_PATH ) );
        if ( ! $filename ) {
            $filename = 'wpsyncer-image-' . time() . '.jpg';
        }

        $tmp_path = tempnam( sys_get_temp_dir(), 'wpsyncer-img-' );
        if ( ! $tmp_path ) {
            WPSYNCER_Logger::log( 'error', 'Image sideload failed.', array( 'url' => $url, 'error' => 'Could not create temp file.' ) );
            return 0;
        }

        if ( false === file_put_contents( $tmp_path, $body ) ) {
            @unlink( $tmp_path );
            WPSYNCER_Logger::log( 'error', 'Image sideload failed.', array( 'url' => $url, 'error' => 'Could not write temp file.' ) );
            return 0;
        }

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp_path,
        );

        $attachment_id = media_handle_sideload( $file_array, $parent_id, null, array( 'test_form' => false ) );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp_path );
            WPSYNCER_Logger::log( 'error', 'Image sideload failed.', array( 'url' => $url, 'error' => $attachment_id->get_error_message() ) );
            return 0;
        }

        @unlink( $tmp_path );

        if ( $source_attachment_id ) {
            update_post_meta( $attachment_id, '_wpsyncer_source_image_key', $key );
        }
        update_post_meta( $attachment_id, '_wpsyncer_source_image_url', $url );
        if ( ! empty( $image['alt'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt'] ) );
        }
        if ( ! empty( $image['title'] ) ) {
            wp_update_post( array( 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $image['title'] ) ) );
        }

        return absint( $attachment_id );
    }

    private static function find_attachment_by_source_key( $key ) {
        $ids = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_key'       => '_wpsyncer_source_image_key',
            'meta_value'     => $key,
        ) );
        return ! empty( $ids ) ? absint( $ids[0] ) : 0;
    }
}
