<?php
/**
 * FILE:    includes/class-wpsyncer-security.php
 * PURPOSE: HMAC signing and verification for sync request authentication.
 * OWNS:    Request signature creation and verification pipeline.
 * EXPORTS: WPSYNCER_Security::sign_body(), WPSYNCER_Security::verify_request()
 * DOCS:    docs/spec.md (section 6: Source behavior, section 7: Receiver behavior)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Security {
    public static function sign_body( $body, $timestamp, $secret ) {
        return 'sha256=' . hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret );
    }

    public static function verify_request( WP_REST_Request $request, WPSYNCER_Settings $settings ) {
        $secret = $settings->get( 'shared_secret', '' );
        if ( '' === $secret ) {
            return new WP_Error( 'wpsyncer_missing_secret', 'Shared secret is not configured.', array( 'status' => 401 ) );
        }

        $timestamp = $request->get_header( 'x-wpsyncer-timestamp' );
        $signature = $request->get_header( 'x-wpsyncer-signature' );

        if ( empty( $timestamp ) || empty( $signature ) ) {
            return new WP_Error( 'wpsyncer_missing_signature_headers', 'Missing signature headers.', array( 'status' => 401 ) );
        }

        $ts = strtotime( $timestamp );
        if ( ! $ts || abs( time() - $ts ) > 10 * MINUTE_IN_SECONDS ) {
            return new WP_Error( 'wpsyncer_stale_request', 'Request timestamp is stale or invalid.', array( 'status' => 401 ) );
        }

        $expected = self::sign_body( $request->get_body(), $timestamp, $secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'wpsyncer_bad_signature', 'Invalid request signature.', array( 'status' => 401 ) );
        }

        return true;
    }
}
