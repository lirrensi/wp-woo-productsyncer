<?php
/**
 * FILE:    includes/class-wpsyncer-bulk-sync.php
 * PURPOSE: Bulk sync all published products from source to configured receiver(s).
 * OWNS:    Batch enumeration, pacing, progress logging of bulk sync operations.
 * EXPORTS: WPSYNCER_BulkSync (constructor takes WPSYNCER_Settings, run() method)
 * DOCS:    docs/spec.md (section 8: Bulk sync)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_BulkSync {
    /**
     * @var WPSYNCER_Settings
     */
    private $settings;

    /**
     * @param WPSYNCER_Settings $settings
     */
    public function __construct( WPSYNCER_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Run the bulk sync process.
     *
     * Enumerates all published products and processes them in batches
     * with a configurable delay between batches.
     *
     * @return array Result summary with total_processed and errors.
     */
    public function run() {
        $running = get_transient( 'wpsyncer_bulk_sync_running' );
        if ( $running ) {
            WPSYNCER_Logger::log( 'warn', 'Bulk sync already running. Skipping duplicate run.' );
            return array(
                'total_processed' => 0,
                'errors'          => array(),
                'skipped'         => true,
            );
        }

        set_transient( 'wpsyncer_bulk_sync_running', 1, 5 * MINUTE_IN_SECONDS );

        $product_ids = wc_get_products(
            array(
                'status' => 'publish',
                'limit'  => -1,
                'return' => 'ids',
            )
        );

        $total   = count( $product_ids );
        $batch   = max( 1, absint( $this->settings->get( 'bulk_batch_size', 10 ) ) );
        $delay   = max( 1, absint( $this->settings->get( 'bulk_batch_delay', 5 ) ) );
        $errors  = array();
        $batches = array_chunk( $product_ids, $batch );

        WPSYNCER_Logger::log( 'info', 'Bulk sync started.', array( 'total_products' => $total, 'batch_size' => $batch, 'batch_count' => count( $batches ) ) );

        foreach ( $batches as $batch_index => $batch_ids ) {
            foreach ( $batch_ids as $product_id ) {
                $source = new WPSYNCER_Source( $this->settings );
                $source->sync_single_product( $product_id );
            }

            $processed = ( $batch_index + 1 ) * $batch;
            if ( $processed > $total ) {
                $processed = $total;
            }

            WPSYNCER_Logger::log( 'info', 'Bulk sync batch progress.', array( 'batch' => $batch_index + 1, 'processed' => $processed, 'total' => $total ) );

            if ( $batch_index < count( $batches ) - 1 ) {
                sleep( $delay );
            }
        }

        delete_transient( 'wpsyncer_bulk_sync_running' );

        WPSYNCER_Logger::log( 'info', 'Bulk sync completed.', array( 'total_processed' => $total ) );

        return array(
            'total_processed' => $total,
            'errors'          => $errors,
            'skipped'         => false,
        );
    }
}
