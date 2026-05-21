<?php
/**
 * Uninstall handler for Woo Product Syncer.
 *
 * Removes plugin options. Deliberately preserves product mapping meta
 * (_wpsyncer_*) so reinstalling does not break identity mappings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Deliberately keep product mapping meta by default so reinstalling does not break identities.
delete_option( 'wpsyncer_settings' );
delete_option( 'wpsyncer_logs' );
