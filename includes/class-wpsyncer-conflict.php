<?php
/**
 * FILE:    includes/class-wpsyncer-conflict.php
 * PURPOSE: Post lock conflict detection for sync operations.
 * OWNS:    Prevents sync from overwriting products being edited.
 * EXPORTS: WPSYNCER_Conflict::check_lock()
 * DOCS:    docs/spec.md (section 7: Snapshot application pipeline, step 2)
 *
 * NOTE: Uses raw _edit_lock meta instead of wp_check_post_lock()
 * because that function is only loaded in wp-admin, not on REST API requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Conflict {
    /**
     * Check if a product is currently locked by another user editing it.
     *
     * WordPress stores _edit_lock as "timestamp:user_id".
     * Locks expire after 150 seconds (heartbeat interval).
     *
     * @param int $product_id
     * @return true|WP_Error
     */
    public static function check_lock( $product_id ) {
        $post = get_post( $product_id );
        if ( ! $post ) {
            return true; // No post = nothing to lock, proceed
        }

        $lock = get_post_meta( $product_id, '_edit_lock', true );
        if ( ! $lock ) {
            return true; // No lock = free
        }

        $parts = explode( ':', $lock );
        $lock_time = isset( $parts[0] ) ? (int) $parts[0] : 0;
        $lock_user = isset( $parts[1] ) ? (int) $parts[1] : 0;

        // Locks expire after 150 seconds
        if ( $lock_time && $lock_time > time() - 150 ) {
            return new WP_Error(
                'wpsyncer_post_locked',
                'Product is currently being edited.',
                array(
                    'status'         => 409,
                    'locked_by_user' => $lock_user,
                )
            );
        }

        return true;
    }
}
