<?php
/**
 * FILE:    includes/class-wpsyncer-cli.php
 * PURPOSE: WP-CLI commands for installing, configuring, and managing the syncer.
 * OWNS:    CLI entry points for setup, config, status, sync trigger, and logs.
 * EXPORTS: WPSYNCER_CLI (install, status, configure, config, sync, run, logs)
 * DOCS:    docs/cli.md
 *
 * USAGE:
 *   wp wpsyncer install          — Verify deps, create default settings
 *   wp wpsyncer status           — Show mode, config, recent logs
 *   wp wpsyncer configure        — Interactive guided setup
 *   wp wpsyncer config get [key] — Show current setting(s)
 *   wp wpsyncer config set <key> <value> — Set a single setting
 *   wp wpsyncer sync <id>        — Sync one product by ID
 *   wp wpsyncer run              — Run bulk sync (source/both mode)
 *   wp wpsyncer logs [n]         — Show last N log entries (default 20)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI command class for Woo Product Syncer.
 *
 * ## EXAMPLES
 *
 *     # Quickstart: install and configure as source
 *     wp wpsyncer install
 *     wp wpsyncer configure
 *
 *     # Check what's running
 *     wp wpsyncer status
 *
 *     # Trigger a specific product sync
 *     wp wpsyncer sync 42
 */
class WPSYNCER_CLI {

    /**
     * Verify installation and create default settings if missing.
     *
     * Checks that WooCommerce is active, creates default options,
     * and validates the environment is ready.
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer install
     */
    public function install() {
        WP_CLI::line( 'Checking Woo Product Syncer installation...' );

        // 1. Check WooCommerce.
        if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
            WP_CLI::error( 'WooCommerce is not active. Please install and activate WooCommerce first.' );
        }
        WP_CLI::success( 'WooCommerce is active (v' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ').' );

        // 2. Ensure default options exist.
        $settings_opt = get_option( WPSYNCER_SETTINGS_OPTION );
        if ( false === $settings_opt ) {
            add_option( WPSYNCER_SETTINGS_OPTION, WPSYNCER_Settings::defaults() );
            WP_CLI::success( 'Created default settings.' );
        } else {
            $count = count( WPSYNCER_Settings::defaults() );
            WP_CLI::line( 'Settings option exists (' . $count . ' fields).' );
        }

        $logs_opt = get_option( WPSYNCER_LOG_OPTION );
        if ( false === $logs_opt ) {
            add_option( WPSYNCER_LOG_OPTION, array() );
            WP_CLI::success( 'Created log storage.' );
        } else {
            WP_CLI::line( 'Log storage exists.' );
        }

        // 3. Check WP-Cron or Action Scheduler.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            WP_CLI::success( 'Action Scheduler detected — async syncs will use it.' );
        } else {
            WP_CLI::warning( 'Action Scheduler not found. Falling back to WP-Cron for async syncs.' );
        }

        // 4. Check REST API availability.
        $rest_url = rest_url( 'wpsyncer/v1/product' );
        WP_CLI::line( 'Receiver REST endpoint: ' . $rest_url );

        WP_CLI::success( 'Installation verified. Run `wp wpsyncer configure` to set up.' );
    }

    /**
     * Show current configuration, mode, and recent logs.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. table, json, or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer status
     *     wp wpsyncer status --format=json
     */
    public function status( $args, $assoc_args ) {
        $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        $settings = new WPSYNCER_Settings();
        $all      = $settings->all();

        // Build sync groups string.
        $groups = array();
        foreach ( array( 'sync_core', 'sync_prices', 'sync_stock', 'sync_taxonomies', 'sync_attributes', 'sync_variations', 'sync_images' ) as $g ) {
            if ( 'yes' === $all[ $g ] ) {
                $groups[] = str_replace( 'sync_', '', $g );
            }
        }
        $meta_keys = $settings->meta_key_whitelist();

        if ( 'json' === $format ) {
            WP_CLI::line( json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        } elseif ( 'yaml' === $format ) {
            WP_CLI::error( 'YAML format requires the WP-CLI yaml command. Use table or json.' );
            return;
        }

        // Table output — use associative arrays for format_items.
        $rows = array();
        $rows[] = array( 'Setting' => 'Mode', 'Value' => $all['mode'] );
        $rows[] = array( 'Setting' => 'Source Site ID', 'Value' => $all['source_site_id'] );
        $rows[] = array( 'Setting' => 'Target URL', 'Value' => $all['target_url'] ?: '(not set)' );
        $rows[] = array( 'Setting' => 'Shared Secret', 'Value' => $all['shared_secret'] ? '******** (' . strlen( $all['shared_secret'] ) . ' chars)' : '(not set)' );
        $rows[] = array( 'Setting' => 'Sync Groups', 'Value' => implode( ', ', $groups ) ?: '(none)' );
        $rows[] = array( 'Setting' => 'Custom Meta Whitelist', 'Value' => ! empty( $meta_keys ) ? implode( ', ', $meta_keys ) : '(none)' );
        $rows[] = array( 'Setting' => 'Delete Behavior', 'Value' => $all['delete_behavior'] );
        $rows[] = array( 'Setting' => 'Sync Product IDs (exp)', 'Value' => $all['sync_product_ids'] );
        $rows[] = array( 'Setting' => 'Debug Logging', 'Value' => $all['debug_logging'] );
        $rows[] = array( 'Setting' => 'Bulk Batch Size', 'Value' => $all['bulk_batch_size'] );
        $rows[] = array( 'Setting' => 'Bulk Batch Delay (s)', 'Value' => $all['bulk_batch_delay'] );

        WP_CLI\Utils\format_items( 'table', $rows, array( 'Setting', 'Value' ) );

        // Show latest logs at the bottom.
        $logs = WPSYNCER_Logger::get_logs();
        if ( ! empty( $logs ) ) {
            $recent = array_slice( array_reverse( $logs ), 0, 5 );
            WP_CLI::line( '' );
            WP_CLI::line( 'Recent logs (last 5):' );
            foreach ( $recent as $entry ) {
                $level = strtoupper( $entry['level'] ?? '?' );
                $msg   = $entry['message'] ?? '';
                $time  = $entry['time'] ?? '';
                WP_CLI::line( "  [{$level}] {$time} — {$msg}" );
            }
        }

        // Connection test if in source/both mode and target URL is set.
        if ( in_array( $all['mode'], array( 'source', 'both' ), true ) && ! empty( $all['target_url'] ) ) {
            WP_CLI::line( '' );
            WP_CLI::line( 'Testing connection to receiver...' );
            $response = wp_remote_get( $all['target_url'], array( 'timeout' => 10 ) );
            if ( is_wp_error( $response ) ) {
                WP_CLI::warning( 'Connection failed: ' . $response->get_error_message() );
            } elseif ( 200 === wp_remote_retrieve_response_code( $response ) ) {
                WP_CLI::success( 'Receiver endpoint is reachable.' );
            } else {
                WP_CLI::warning( 'Receiver returned HTTP ' . wp_remote_retrieve_response_code( $response ) );
            }
        }
    }

    /**
     * Interactive guided configuration wizard.
     *
     * Walks through each setting with prompts and sensible defaults.
     * Saves all settings at the end.
     *
     * ## OPTIONS
     *
     * [--mode=<mode>]
     * : Skip the mode prompt. One of: disabled, source, receiver, both.
     *
     * [--yes]
     * : Skip all confirmations and use defaults for non-required prompts.
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer configure
     *     wp wpsyncer configure --mode=source --yes
     *     wp wpsyncer configure --mode=receiver
     */
    public function configure( $args, $assoc_args ) {
        $settings = new WPSYNCER_Settings();
        $current  = $settings->all();
        $defaults = WPSYNCER_Settings::defaults();
        $non_interactive = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
        $mode_override   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'mode', '' );

        WP_CLI::line( 'Woo Product Syncer Configuration Wizard' );
        WP_CLI::line( '─────────────────────────────────────────' );
        WP_CLI::line( 'Press Enter to accept the value shown in brackets.' );
        WP_CLI::line( '' );

        // --- Mode ---
        if ( ! empty( $mode_override ) ) {
            $mode = $mode_override;
            if ( ! in_array( $mode, array( 'disabled', 'source', 'receiver', 'both' ), true ) ) {
                WP_CLI::error( "Invalid mode '{$mode}'. Must be: disabled, source, receiver, both." );
            }
            WP_CLI::line( "Mode: {$mode}" );
        } else {
            $mode = $this->prompt_choice(
                'Mode',
                array(
                    'disabled' => 'Disabled — plugin does nothing',
                    'source'   => 'Source — send product changes to a receiver',
                    'receiver' => 'Receiver — accept product changes from sources',
                    'both'     => 'Both — send AND accept (bidirectional)',
                ),
                $current['mode'],
                $non_interactive
            );
        }

        // --- Source Site ID ---
        $source_site_id = $this->prompt(
            'Source Site ID (unique identifier for this site)',
            $current['source_site_id'],
            $non_interactive
        );

        // --- Shared Secret ---
        $shared_secret = $this->prompt(
            'Shared Secret (used to sign sync payloads; must match on source and receiver)',
            $current['shared_secret'] ?: $this->generate_secret(),
            $non_interactive
        );

        // --- Source-specific ---
        $target_url       = '';
        $create_products  = $current['create_missing_products'];
        $create_terms     = $current['create_missing_terms'];
        $sync_groups      = array();
        $meta_keys        = $current['sync_meta_keys'];
        $delete_behavior  = $current['delete_behavior'];
        $sync_product_ids = $current['sync_product_ids'];
        $batch_size       = $current['bulk_batch_size'];
        $batch_delay      = $current['bulk_batch_delay'];
        $debug            = $current['debug_logging'];

        if ( in_array( $mode, array( 'source', 'both' ), true ) ) {
            WP_CLI::line( '' );
            WP_CLI::line( '── Source Configuration ──' );

            $target_url = $this->prompt(
                'Target Receiver URL (base URL only, e.g., https://shop.example.com)',
                $current['target_url'],
                $non_interactive
            );

            $sync_groups = $this->get_sync_groups( $current, $non_interactive );

            $meta_keys = $this->prompt(
                'Custom meta keys to sync (comma-separated; e.g., _test_meta_field,_custom_price)',
                $current['sync_meta_keys'],
                $non_interactive
            );

            $delete_behavior = $this->prompt_choice(
                'When source product is deleted',
                array(
                    'ignore' => 'Ignore — do nothing on the receiver',
                    'draft'  => 'Draft — set the receiver product to draft',
                    'trash'  => 'Trash — move the receiver product to trash',
                ),
                $current['delete_behavior'],
                $non_interactive
            );

            $batch_size = (int) $this->prompt(
                'Bulk sync batch size',
                $current['bulk_batch_size'],
                $non_interactive
            );

            $batch_delay = (int) $this->prompt(
                'Bulk sync batch delay (seconds between batches)',
                $current['bulk_batch_delay'],
                $non_interactive
            );

            $sync_product_ids = $this->prompt_yes_no(
                'Experimental: Sync product IDs? (use source IDs on receiver; only for fresh sites)',
                $current['sync_product_ids'],
                $non_interactive
            );

            $debug = $this->prompt_yes_no(
                'Enable debug logging?',
                $current['debug_logging'],
                $non_interactive
            );
        }

        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            WP_CLI::line( '' );
            WP_CLI::line( '── Receiver Configuration ──' );

            $create_products = $this->prompt_yes_no(
                'Create missing products on receiver?',
                $current['create_missing_products'],
                $non_interactive
            );

            $create_terms = $this->prompt_yes_no(
                'Create missing categories, tags, and attributes?',
                $current['create_missing_terms'],
                $non_interactive
            );
        }

        // --- Summary ---
        WP_CLI::line( '' );
        WP_CLI::line( 'Configuration Summary:' );
        WP_CLI::line( '  Mode:                        ' . $mode );
        WP_CLI::line( '  Source Site ID:               ' . $source_site_id );
        if ( in_array( $mode, array( 'source', 'both' ), true ) ) {
            WP_CLI::line( '  Target URL:                   ' . ( $target_url ?: '(not set)' ) );
        }
        WP_CLI::line( '  Shared Secret:                ' . ( $shared_secret ? 'set (' . strlen( $shared_secret ) . ' chars)' : '(not set)' ) );

        if ( ! $non_interactive ) {
            $confirm = \cli\prompt( 'Save these settings? [Y/n]' );
            if ( ! in_array( strtolower( $confirm ), array( '', 'y', 'yes' ), true ) ) {
                WP_CLI::error( 'Configuration cancelled.' );
            }
        }

        // --- Build and save ---
        $new_settings = array(
            'mode'                    => $mode,
            'source_site_id'          => $source_site_id,
            'target_url'              => $target_url,
            'shared_secret'           => $shared_secret,
            'create_missing_products' => $create_products,
            'create_missing_terms'    => $create_terms,
            'sync_core'               => in_array( 'core', $sync_groups, true ) ? 'yes' : $current['sync_core'],
            'sync_prices'             => in_array( 'prices', $sync_groups, true ) ? 'yes' : $current['sync_prices'],
            'sync_stock'              => in_array( 'stock', $sync_groups, true ) ? 'yes' : $current['sync_stock'],
            'sync_taxonomies'         => in_array( 'taxonomies', $sync_groups, true ) ? 'yes' : $current['sync_taxonomies'],
            'sync_attributes'         => in_array( 'attributes', $sync_groups, true ) ? 'yes' : $current['sync_attributes'],
            'sync_variations'         => in_array( 'variations', $sync_groups, true ) ? 'yes' : $current['sync_variations'],
            'sync_images'             => in_array( 'images', $sync_groups, true ) ? 'yes' : $current['sync_images'],
            'sync_meta_keys'          => $meta_keys,
            'delete_behavior'         => $delete_behavior,
            'sync_product_ids'        => $sync_product_ids,
            'bulk_batch_size'         => absint( $batch_size ) ?: 10,
            'bulk_batch_delay'        => absint( $batch_delay ) ?: 5,
            'debug_logging'           => $debug,
        );

        // IMPORTANT: Bypass $settings->sanitize(). The sanitize method uses
        // !empty() for checkbox checks, which incorrectly converts the string
        // 'no' to 'yes' (since 'no' is non-empty in PHP). Our values are already
        // validated individually, so direct save is safe and correct.
        update_option( WPSYNCER_SETTINGS_OPTION, $new_settings );

        WP_CLI::success( 'Configuration saved.' );

        // Show REST endpoint info.
        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            WP_CLI::line( 'Receiver REST endpoint: ' . rest_url( 'wpsyncer/v1/product' ) );
        }
    }

    /**
     * Get or set individual configuration values.
     *
     * ## OPTIONS
     *
     * <command>
     * : Sub-command: get or set.
     *
     * [<key>]
     * : Setting key. For "get", omit to show all. For "set", this is required.
     *
     * [<value>]
     * : Setting value (only for "set").
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer config get
     *     wp wpsyncer config get target_url
     *     wp wpsyncer config set mode source
     *     wp wpsyncer config set target_url https://shop.example.com
     *     wp wpsyncer config set shared_secret my-secret-key
     */
    public function config( $args, $assoc_args ) {
        $subcommand = $args[0] ?? '';
        $key        = $args[1] ?? '';
        $value      = $args[2] ?? '';

        $settings = new WPSYNCER_Settings();

        if ( 'get' === $subcommand ) {
            if ( empty( $key ) ) {
                // Show all.
                $all = $settings->all();
                $rows = array();
                foreach ( $all as $k => $v ) {
                    if ( 'shared_secret' === $k && ! empty( $v ) ) {
                        $v = '********';
                    }
                    $rows[] = array( 'key' => $k, 'value' => is_string( $v ) ? $v : json_encode( $v ) );
                }
                WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
            } else {
                $val = $settings->get( $key );
                if ( null === $val ) {
                    WP_CLI::error( "Unknown setting: {$key}" );
                }
                if ( 'shared_secret' === $key && ! empty( $val ) ) {
                    WP_CLI::line( '********' );
                } else {
                    WP_CLI::line( is_string( $val ) ? $val : json_encode( $val ) );
                }
            }
        } elseif ( 'set' === $subcommand ) {
            if ( empty( $key ) ) {
                WP_CLI::error( 'Usage: wp wpsyncer config set <key> <value>' );
            }

            if ( empty( $value ) && 0 === count( $assoc_args ) ) {
                WP_CLI::error( 'Usage: wp wpsyncer config set <key> <value>' );
            }

            $all          = $settings->all();
            $defaults     = WPSYNCER_Settings::defaults();

            if ( ! array_key_exists( $key, $defaults ) ) {
                WP_CLI::error( "Unknown setting: {$key}. Valid keys: " . implode( ', ', array_keys( $defaults ) ) );
            }

            // Sanitize the individual value based on its type.
            $checkbox_keys = array(
                'create_missing_products', 'create_missing_terms',
                'sync_core', 'sync_prices', 'sync_stock', 'sync_taxonomies',
                'sync_attributes', 'sync_variations', 'sync_images',
                'debug_logging', 'sync_product_ids',
            );
            $choice_keys = array( 'mode', 'delete_behavior' );

            if ( in_array( $key, $checkbox_keys, true ) ) {
                $value = in_array( strtolower( $value ), array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';
            } elseif ( 'mode' === $key ) {
                $valid_modes = array( 'disabled', 'source', 'receiver', 'both' );
                if ( ! in_array( $value, $valid_modes, true ) ) {
                    WP_CLI::error( "Invalid mode '{$value}'. Must be: " . implode( ', ', $valid_modes ) );
                }
            } elseif ( 'delete_behavior' === $key ) {
                $valid_behaviors = array( 'ignore', 'draft', 'trash' );
                if ( ! in_array( $value, $valid_behaviors, true ) ) {
                    WP_CLI::error( "Invalid delete_behavior '{$value}'. Must be: " . implode( ', ', $valid_behaviors ) );
                }
            } elseif ( 'bulk_batch_size' === $key || 'bulk_batch_delay' === $key ) {
                $value = max( 1, absint( $value ) );
            } elseif ( 'target_url' === $key ) {
                $value = esc_url_raw( $value );
                // Strip old full REST path if provided (migration from pre-0.3.0).
                $suffix = '/wp-json/wpsyncer/v1/product';
                if ( substr( $value, -strlen( $suffix ) ) === $suffix ) {
                    $value = substr( $value, 0, -strlen( $suffix ) );
                }
                $value = rtrim( $value, '/' );
            } elseif ( 'source_site_id' === $key ) {
                $value = sanitize_key( $value );
            } elseif ( 'shared_secret' === $key ) {
                $value = sanitize_text_field( $value );
            } elseif ( 'sync_meta_keys' === $key ) {
                $value = sanitize_textarea_field( $value );
            }

            $all[ $key ] = $value;
            update_option( WPSYNCER_SETTINGS_OPTION, $all );
            WP_CLI::success( "{$key} set to '{$value}'." );
        } else {
            WP_CLI::error( "Unknown sub-command: {$subcommand}. Use 'get' or 'set'." );
        }
    }

    /**
     * Sync a single product by ID.
     *
     * Builds a snapshot of the product and dispatches it to the receiver.
     * Only works in source or both mode.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : The WordPress post ID of the product to sync.
     *
     * [--wait]
     * : Wait for the sync to complete (bypass async).
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer sync 42
     *     wp wpsyncer sync 42 --wait
     */
    public function sync( $args, $assoc_args ) {
        $product_id = absint( $args[0] ?? 0 );
        if ( ! $product_id ) {
            WP_CLI::error( 'Usage: wp wpsyncer sync <product-id>' );
        }

        $settings = new WPSYNCER_Settings();
        $mode     = $settings->mode();

        if ( ! in_array( $mode, array( 'source', 'both' ), true ) ) {
            WP_CLI::error( 'Sync is only available in source or both mode. Current mode: ' . $mode );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            WP_CLI::error( "Product #{$product_id} not found." );
        }

        $product_name = $product->get_name();
        $sku          = $product->get_sku() ?: '(no SKU)';

        WP_CLI::line( "Syncing product #{$product_id} — {$product_name} ({$sku})..." );

        $wait = \WP_CLI\Utils\get_flag_value( $assoc_args, 'wait', false );

        if ( $wait ) {
            // Sync immediately.
            $source = new WPSYNCER_Source( $settings );
            $source->sync_product( $product_id, 'product.updated' );
            WP_CLI::success( "Product #{$product_id} synced immediately." );
        } else {
            // Enqueue async.
            $source = new WPSYNCER_Source( $settings );
            $source->sync_single_product( $product_id );
            WP_CLI::success( "Product #{$product_id} queued for sync." );
        }
    }

    /**
     * Run bulk sync for all published products.
     *
     * Dispatches sync for all published simple, variable, and grouped products.
     * Only works in source or both mode.
     *
     * ## OPTIONS
     *
     * [--batch-size=<n>]
     * : Override the configured batch size for this run.
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer run
     *     wp wpsyncer run --batch-size=50
     */
    public function run( $args, $assoc_args ) {
        $settings = new WPSYNCER_Settings();
        $mode     = $settings->mode();

        if ( ! in_array( $mode, array( 'source', 'both' ), true ) ) {
            WP_CLI::error( 'Bulk sync is only available in source or both mode. Current mode: ' . $mode );
        }

        if ( empty( $settings->get( 'target_url' ) ) ) {
            WP_CLI::error( 'Target URL is not configured. Set it first with `wp wpsyncer config set target_url <url>`.' );
        }

        $batch_size = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', $settings->get( 'bulk_batch_size', 10 ) ) );

        WP_CLI::line( 'Starting bulk sync...' );
        WP_CLI::line( 'Batch size: ' . $batch_size );

        $bulk = new WPSYNCER_BulkSync( $settings );
        $bulk->run();

        WP_CLI::success( 'Bulk sync triggered. Check `wp wpsyncer logs` for progress.' );
    }

    /**
     * Show recent sync log entries.
     *
     * ## OPTIONS
     *
     * [<count>]
     * : Number of log entries to show. Default: 20.
     *
     * [--level=<level>]
     * : Filter by level: info, warning, error.
     *
     * [--follow]
     * : Tail logs and wait for new entries (press Ctrl+C to stop).
     *
     * ## EXAMPLES
     *
     *     wp wpsyncer logs
     *     wp wpsyncer logs 50
     *     wp wpsyncer logs --level=error
     */
    public function logs( $args, $assoc_args ) {
        $count = isset( $args[0] ) ? absint( $args[0] ) : 20;
        $level_filter = \WP_CLI\Utils\get_flag_value( $assoc_args, 'level', '' );
        $follow = \WP_CLI\Utils\get_flag_value( $assoc_args, 'follow', false );

        if ( $follow ) {
            WP_CLI::line( 'Tailing logs... (Ctrl+C to stop)' );
            $last_count = count( WPSYNCER_Logger::get_logs() );
            while ( true ) {
                $logs = WPSYNCER_Logger::get_logs();
                $total = count( $logs );
                if ( $total > $last_count ) {
                    $new = array_slice( $logs, $last_count );
                    foreach ( $new as $entry ) {
                        $level = strtoupper( $entry['level'] ?? '?' );
                        $msg   = $entry['message'] ?? '';
                        $time  = $entry['time'] ?? '';
                        WP_CLI::line( "  [{$level}] {$time} — {$msg}" );
                    }
                    $last_count = $total;
                }
                sleep( 2 );
            }
        }

        $logs = WPSYNCER_Logger::get_logs();

        if ( empty( $logs ) ) {
            WP_CLI::line( 'No log entries.' );
            return;
        }

        // Apply level filter.
        if ( ! empty( $level_filter ) ) {
            $logs = array_filter( $logs, function( $entry ) use ( $level_filter ) {
                return ( $entry['level'] ?? '' ) === $level_filter;
            } );
        }

        $entries = array_slice( array_reverse( $logs ), 0, $count );

        $rows = array();
        foreach ( $entries as $entry ) {
            $context = ! empty( $entry['context'] ) ? json_encode( $entry['context'] ) : '';
            $rows[] = array(
                'time'    => $entry['time'] ?? '',
                'level'   => strtoupper( $entry['level'] ?? '' ),
                'message' => $entry['message'] ?? '',
                'context' => $context,
            );
        }

        WP_CLI\Utils\format_items( 'table', $rows, array( 'time', 'level', 'message', 'context' ) );

        $total = count( $logs );
        $shown = count( $entries );
        $filtered = ! empty( $level_filter ) ? ' (filtered by level: ' . $level_filter . ')' : '';
        WP_CLI::line( "Showing {$shown} of {$total} total entries{$filtered}." );
    }

    // ─── Private Helpers ─────────────────────────────────────────

    /**
     * Prompt for a text value with a default.
     */
    private function prompt( $label, $default, $non_interactive ) {
        if ( $non_interactive ) {
            return $default;
        }
        $result = \cli\prompt( $label, false, $default );
        return is_string( $result ) ? trim( $result ) : $default;
    }

    /**
     * Prompt for a choice from a list.
     */
    private function prompt_choice( $label, $choices, $default, $non_interactive ) {
        if ( $non_interactive ) {
            return $default;
        }

        WP_CLI::line( '' );
        WP_CLI::line( "{$label}:" );
        $keys = array_keys( $choices );
        foreach ( $choices as $key => $desc ) {
            $marker = ( $key === $default ) ? ' [default]' : '';
            WP_CLI::line( "  {$key} — {$desc}{$marker}" );
        }

        $result = \cli\prompt( 'Enter choice', false, $default );
        $result = trim( $result );
        if ( empty( $result ) ) {
            return $default;
        }
        if ( ! in_array( $result, $keys, true ) ) {
            WP_CLI::warning( "Invalid choice '{$result}'. Using default '{$default}'." );
            return $default;
        }
        return $result;
    }

    /**
     * Prompt for a yes/no value.
     */
    private function prompt_yes_no( $label, $default, $non_interactive ) {
        if ( $non_interactive ) {
            return $default;
        }
        $default_str = 'yes' === $default ? 'Y/n' : 'y/N';
        $result = \cli\prompt( "{$label} [{$default_str}]", false, $default );
        $result = strtolower( trim( $result ) );
        if ( in_array( $result, array( '', 'y', 'yes' ), true ) ) {
            return 'yes';
        }
        return 'no';
    }

    /**
     * Interactive sync group selector.
     */
    private function get_sync_groups( $current, $non_interactive ) {
        if ( $non_interactive ) {
            $groups = array();
            foreach ( array( 'sync_core', 'sync_prices', 'sync_stock', 'sync_taxonomies', 'sync_attributes', 'sync_variations', 'sync_images' ) as $g ) {
                if ( 'yes' === ( $current[ $g ] ?? 'yes' ) ) {
                    $groups[] = str_replace( 'sync_', '', $g );
                }
            }
            return $groups;
        }

        $groups = array();
        $options = array(
            'core'       => 'Core product fields (name, description, short description, SKU)',
            'prices'     => 'Prices (regular, sale, currency)',
            'stock'      => 'Stock (quantity, status, manage stock)',
            'taxonomies' => 'Categories and tags',
            'attributes' => 'Product attributes',
            'variations' => 'Variations',
            'images'     => 'Images and gallery',
        );

        WP_CLI::line( '' );
        WP_CLI::line( 'Select sync groups (comma-separated list, e.g.: core,prices,stock):' );
        foreach ( $options as $key => $desc ) {
            $checkbox = 'yes' === ( $current[ 'sync_' . $key ] ?? 'yes' ) ? '[x]' : '[ ]';
            WP_CLI::line( "  {$checkbox} {$key} — {$desc}" );
        }

        $result = \cli\prompt( 'Sync groups', false, 'core,prices,stock,taxonomies,attributes,variations' );
        $parts  = array_map( 'trim', explode( ',', $result ) );
        foreach ( $parts as $p ) {
            if ( array_key_exists( $p, $options ) ) {
                $groups[] = $p;
            }
        }
        return array_unique( $groups );
    }

    /**
     * Generate a random shared secret.
     */
    private function generate_secret() {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            return uniqid( 'wpsyncer_', true ) . '_' . bin2hex( pack( 'N', time() ) );
        }
    }
}

// Register the command.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'wpsyncer', 'WPSYNCER_CLI' );
}
