<?php
/**
 * FILE:    includes/class-wpsyncer-logger.php
 * PURPOSE: Structured sync logging with 100-entry circular buffer and PHP error log fallback.
 * OWNS:    Log storage, pruning, and retrieval.
 * EXPORTS: WPSYNCER_Logger::log(), WPSYNCER_Logger::get_logs()
 * DOCS:    docs/spec.md (section 12: Logging)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Logger {
    public static function log( $level, $message, $context = array() ) {
        $settings = get_option( WPSYNCER_SETTINGS_OPTION, array() );
        if ( isset( $settings['debug_logging'] ) && 'yes' !== $settings['debug_logging'] ) {
            return;
        }

        $logs = get_option( WPSYNCER_LOG_OPTION, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        $logs[] = array(
            'time'    => gmdate( 'c' ),
            'level'   => sanitize_key( $level ),
            'message' => (string) $message,
            'context' => self::sanitize_context( $context ),
        );

        if ( count( $logs ) > 100 ) {
            $logs = array_slice( $logs, -100 );
        }

        update_option( WPSYNCER_LOG_OPTION, $logs, false );

        if ( 'error' === $level ) {
            error_log( '[WPSYNCER] ' . $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public static function get_logs() {
        $logs = get_option( WPSYNCER_LOG_OPTION, array() );
        return is_array( $logs ) ? $logs : array();
    }

    private static function sanitize_context( $context ) {
        if ( is_wp_error( $context ) ) {
            return array(
                'code'    => $context->get_error_code(),
                'message' => $context->get_error_message(),
            );
        }
        if ( is_array( $context ) ) {
            return $context;
        }
        return array( 'value' => (string) $context );
    }
}
