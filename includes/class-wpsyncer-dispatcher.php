<?php
/**
 * FILE:    includes/class-wpsyncer-dispatcher.php
 * PURPOSE: Send HMAC-signed product snapshots to the receiver endpoint.
 * OWNS:    HTTP POST delivery with signature headers, response handling.
 * EXPORTS: WPSYNCER_Dispatcher::dispatch()
 * DOCS:    docs/spec.md (section 6: Source behavior — Async dispatch)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Dispatcher {
    private $settings;

    public function __construct( WPSYNCER_Settings $settings ) {
        $this->settings = $settings;
    }

    public function dispatch( array $payload ) {
        $target_url = $this->settings->get( 'target_url', '' );
        $secret = $this->settings->get( 'shared_secret', '' );

        if ( empty( $target_url ) || empty( $secret ) ) {
            WPSYNCER_Logger::log( 'error', 'Target URL or shared secret is missing.' );
            return new WP_Error( 'wpsyncer_missing_dispatch_config', 'Target URL or shared secret is missing.' );
        }

        // Since 0.3.0, target_url may be just a base URL (e.g. "http://receiver").
        // Append the REST path if not already present (handles both old full-path and new base URLs).
        $suffix = '/wp-json/wpsyncer/v1/product';
        $target_url = rtrim( $target_url, '/' );
        if ( substr( $target_url, -strlen( $suffix ) ) !== $suffix ) {
            $target_url .= $suffix;
        }

        $body = wp_json_encode( $payload );
        if ( false === $body ) {
            return new WP_Error( 'wpsyncer_json_encode_failed', 'Could not encode payload.' );
        }

        $timestamp = gmdate( 'c' );
        $signature = WPSYNCER_Security::sign_body( $body, $timestamp, $secret );

        $response = wp_remote_post(
            $target_url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type'           => 'application/json; charset=utf-8',
                    'X-WPSYNCER-Site'        => $this->settings->get( 'source_site_id', 'source-store' ),
                    'X-WPSYNCER-Timestamp'   => $timestamp,
                    'X-WPSYNCER-Signature'   => $signature,
                    'X-WPSYNCER-Plugin-Version' => WPSYNCER_VERSION,
                ),
                'body' => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            WPSYNCER_Logger::log( 'error', 'Sync request failed.', $response );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $context = array(
            'http_code' => $code,
            'event'     => $payload['event'] ?? '',
            'product'   => $payload['product']['sku'] ?? $payload['source_product_id'] ?? '',
        );

        if ( $code < 200 || $code >= 300 ) {
            $context['response'] = substr( $response_body, 0, 500 );
            WPSYNCER_Logger::log( 'error', 'Receiver rejected sync request.', $context );
            return new WP_Error( 'wpsyncer_receiver_error', 'Receiver rejected sync request.', $context );
        }

        WPSYNCER_Logger::log( 'info', 'Product sync delivered.', $context );
        return true;
    }
}
