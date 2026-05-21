<?php
/**
 * FILE:    includes/class-wpsyncer-receiver.php
 * PURPOSE: REST endpoint for receiving product sync snapshots.
 * OWNS:    Route registration, request verification, payload routing.
 * EXPORTS: WPSYNCER_Receiver (init, register_routes, handle_product)
 * DOCS:    docs/spec.md (section 7: Receiver behavior)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Receiver {
    private $settings;

    public function __construct( WPSYNCER_Settings $settings ) {
        $this->settings = $settings;
    }

    public function init() {
        if ( ! in_array( $this->settings->mode(), array( 'receiver', 'both' ), true ) ) {
            return;
        }
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            'wpsyncer/v1',
            '/product',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_product' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_product( WP_REST_Request $request ) {
        $verified = WPSYNCER_Security::verify_request( $request, $this->settings );
        if ( is_wp_error( $verified ) ) {
            WPSYNCER_Logger::log( 'error', 'Rejected sync request.', $verified );
            return $verified;
        }

        $payload = json_decode( $request->get_body(), true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'wpsyncer_bad_json', 'Invalid JSON payload.', array( 'status' => 400 ) );
        }
        if ( 'wpsyncer.product_snapshot.v1' !== ( $payload['schema'] ?? '' ) ) {
            return new WP_Error( 'wpsyncer_bad_schema', 'Unsupported payload schema.', array( 'status' => 400 ) );
        }

        $updater = new WPSYNCER_Product_Updater( $this->settings );
        $result = $updater->apply_payload( $payload );
        if ( is_wp_error( $result ) ) {
            WPSYNCER_Logger::log(
                'error',
                'Failed to apply product payload: ' . $result->get_error_message(),
                array(
                    'code'  => $result->get_error_code(),
                    'data'  => $result->get_error_data(),
                    'event' => $payload['event'] ?? '',
                )
            );
            return $result;
        }

        return rest_ensure_response( array(
            'ok'        => true,
            'product_id'=> $result['product_id'] ?? null,
            'event'     => $payload['event'] ?? '',
        ) );
    }
}
