<?php
/**
 * FILE:    includes/class-wpsyncer-settings.php
 * PURPOSE: Settings CRUD, admin settings page, export/import, and per-product meta box.
 * OWNS:    Settings storage, sanitization, admin UI, settings portability, product sync meta box.
 * EXPORTS: WPSYNCER_Settings (defaults, all, get, bool, mode, sanitize, meta_key_whitelist,
 *          render_page, export_json, import_json)
 * DOCS:    docs/spec.md (section 3: Settings schema, section 11: Settings export/import)
 * NOTES:   The admin page is a top-level menu with tabs (Settings, Logs, Tools). Export/import use admin-post hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSYNCER_Settings {
    public static function defaults() {
        return array(
            'mode'                    => 'disabled',
            'source_site_id'          => 'source-store',
            'target_url'              => '',
            'shared_secret'           => '',

            'create_missing_products' => 'yes',
            'create_missing_terms'    => 'yes',
            'sync_core'               => 'yes',
            'sync_prices'             => 'yes',
            'sync_stock'              => 'yes',
            'sync_taxonomies'         => 'yes',
            'sync_attributes'         => 'yes',
            'sync_variations'         => 'yes',
            'sync_images'             => 'no',
            'sync_meta_keys'          => '',
            'delete_behavior'         => 'ignore',
            'debug_logging'           => 'yes',
            'bulk_batch_size'         => 10,
            'bulk_batch_delay'        => 5,
            'sync_product_ids'        => 'no',
        );
    }

    public function init() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Export handler.
        add_action( 'admin_post_wpsyncer_export_settings', array( $this, 'handle_export' ) );
        // Import handler.
        add_action( 'admin_post_wpsyncer_import_settings', array( $this, 'handle_import' ) );

        // Bulk sync handler.
        add_action( 'admin_post_wpsyncer_bulk_sync', array( $this, 'handle_bulk_sync' ) );

        // Per-product meta box.
        add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );

        // AJAX single product sync.
        add_action( 'wp_ajax_wpsyncer_sync_single', array( $this, 'ajax_sync_single' ) );
    }

    public function admin_menu() {
        add_menu_page(
            'Woo Product Syncer',
            'Product Sync',
            'manage_woocommerce',
            'wpsyncer-settings',
            array( $this, 'render_page' ),
            'dashicons-update',
            56
        );
    }

    public function register_settings() {
        register_setting( 'wpsyncer_settings_group', WPSYNCER_SETTINGS_OPTION, array( $this, 'sanitize' ) );
    }

    public function all() {
        $saved = get_option( WPSYNCER_SETTINGS_OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public function get( $key, $default = null ) {
        $all = $this->all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    public function bool( $key ) {
        return 'yes' === $this->get( $key, 'no' );
    }

    public function mode() {
        return $this->get( 'mode', 'disabled' );
    }

    public function sanitize( $input ) {
        $defaults = self::defaults();
        $input    = is_array( $input ) ? $input : array();
        $output   = array();

        $output['mode'] = in_array( $input['mode'] ?? 'disabled', array( 'disabled', 'source', 'receiver', 'both' ), true ) ? $input['mode'] : 'disabled';
        $output['source_site_id'] = sanitize_key( $input['source_site_id'] ?? $defaults['source_site_id'] );
        $url = esc_url_raw( $input['target_url'] ?? '' );
        // Strip old full REST path from target_url (migration from pre-0.3.0)
        $suffix = '/wp-json/wpsyncer/v1/product';
        if ( substr( $url, -strlen( $suffix ) ) === $suffix ) {
            $url = substr( $url, 0, -strlen( $suffix ) );
        }
        $output['target_url'] = rtrim( $url, '/' );
        $output['shared_secret'] = sanitize_text_field( $input['shared_secret'] ?? '' );
        $output['sync_meta_keys'] = sanitize_textarea_field( $input['sync_meta_keys'] ?? '' );
        $output['delete_behavior'] = in_array( $input['delete_behavior'] ?? 'ignore', array( 'ignore', 'draft', 'trash' ), true ) ? $input['delete_behavior'] : 'ignore';
        $output['bulk_batch_size'] = max( 1, absint( $input['bulk_batch_size'] ?? 10 ) );
        $output['bulk_batch_delay'] = max( 1, absint( $input['bulk_batch_delay'] ?? 5 ) );

        $checkboxes = array(
            'create_missing_products',
            'create_missing_terms',
            'sync_core',
            'sync_prices',
            'sync_stock',
            'sync_taxonomies',
            'sync_attributes',
            'sync_variations',
            'sync_images',
            'debug_logging',
            'sync_product_ids',
        );
        foreach ( $checkboxes as $key ) {
            $output[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
        }

        return wp_parse_args( $output, $defaults );
    }

    public function meta_key_whitelist() {
        $raw = $this->get( 'sync_meta_keys', '' );
        $parts = preg_split( '/[\r\n,]+/', $raw );
        $keys = array();
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' !== $part ) {
                $keys[] = $part;
            }
        }
        return array_values( array_unique( $keys ) );
    }

    /**
     * Export current settings as JSON.
     *
     * @return string JSON-encoded settings export envelope.
     */
    public function export_json() {
        $settings = $this->all();
        $envelope = array(
            'schema'        => 'wpsyncer.settings_export.v1',
            'exported_at'   => gmdate( 'c' ),
            'source_site_id' => $settings['source_site_id'] ?? '',
            'settings'      => $settings,
        );
        return wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /**
     * Import settings from a JSON string.
     *
     * Validates envelope schema, sanitizes each setting, and saves.
     *
     * @param string $json JSON string.
     * @return true|WP_Error
     */
    public function import_json( $json ) {
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'wpsyncer_import_bad_json', 'The uploaded file is not valid JSON.' );
        }

        if ( 'wpsyncer.settings_export.v1' !== ( $decoded['schema'] ?? '' ) ) {
            return new WP_Error( 'wpsyncer_import_bad_schema', 'Unsupported settings export schema.' );
        }

        if ( ! isset( $decoded['settings'] ) || ! is_array( $decoded['settings'] ) ) {
            return new WP_Error( 'wpsyncer_import_missing_settings', 'Export payload has no settings array.' );
        }

        $sanitized = $this->sanitize( wp_parse_args( $decoded['settings'], self::defaults() ) );
        update_option( WPSYNCER_SETTINGS_OPTION, $sanitized );

        return true;
    }

    /**
     * Handle settings export download.
     */
    public function handle_export() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'wpsyncer_export' );

        $json = $this->export_json();
        $filename = 'wpsyncer-settings-' . gmdate( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Handle settings import from uploaded JSON file.
     */
    public function handle_import() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        if ( ! isset( $_FILES['wpsyncer_import_file'] ) || UPLOAD_ERR_OK !== $_FILES['wpsyncer_import_file']['error'] ) {
            wp_die( 'File upload error. Please select a valid JSON file.' );
        }

        $contents = file_get_contents( $_FILES['wpsyncer_import_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( false === $contents ) {
            wp_die( 'Could not read uploaded file.' );
        }

        $result = $this->import_json( $contents );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }

        wp_safe_redirect( add_query_arg( 'settings_imported', '1', wp_get_referer() ) );
        exit;
    }

    /**
     * Handle bulk sync trigger.
     */
    public function handle_bulk_sync() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'wpsyncer_bulk_sync' );

        $mode = $this->mode();
        if ( ! in_array( $mode, array( 'source', 'both' ), true ) ) {
            wp_die( 'Bulk sync is only available in Source or Both mode.' );
        }

        $bulk = new WPSYNCER_BulkSync( $this );
        $bulk->run();

        wp_safe_redirect( add_query_arg( 'bulk_sync_started', '1', wp_get_referer() ) );
        exit;
    }

    /**
     * Register per-product sync meta box.
     */
    public function add_product_meta_box() {
        $mode = $this->mode();
        if ( ! in_array( $mode, array( 'source', 'both' ), true ) ) {
            return;
        }

        add_meta_box(
            'wpsyncer_product_sync',
            'Product Sync',
            array( $this, 'render_product_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the per-product sync meta box content.
     *
     * @param WP_Post $post
     */
    public function render_product_meta_box( $post ) {
        $product_id = $post->ID;

        $sync_uid       = get_post_meta( $product_id, '_wpsyncer_sync_uid', true );
        $remote_sync_uid = get_post_meta( $product_id, '_wpsyncer_remote_sync_uid', true );
        $remote_source_id = get_post_meta( $product_id, '_wpsyncer_remote_source_id', true );
        $remote_product_id = get_post_meta( $product_id, '_wpsyncer_remote_product_id', true );

        echo '<div class="wpsyncer-meta-box">';
        echo '<p><strong>Sync UID:</strong> ' . esc_html( $sync_uid ?: '—' ) . '</p>';

        if ( $remote_source_id ) {
            echo '<p><strong>Remote site:</strong> ' . esc_html( $remote_source_id ) . '</p>';
        }
        if ( $remote_product_id ) {
            echo '<p><strong>Remote product ID:</strong> ' . esc_html( $remote_product_id ) . '</p>';
        }

        $nonce = wp_create_nonce( 'wpsyncer_sync_single_' . $product_id );
        $ajax_url = admin_url( 'admin-ajax.php' );
        echo '<p><button type="button" class="button button-primary" id="wpsyncer-sync-now" data-product-id="' . esc_attr( $product_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-ajax-url="' . esc_attr( $ajax_url ) . '">Sync Now</button></p>';
        echo '<div id="wpsyncer-sync-status"></div>';
        echo '</div>';
        ?>
        <script>
        (function() {
            var btn = document.getElementById('wpsyncer-sync-now');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var status = document.getElementById('wpsyncer-sync-status');
                status.innerHTML = '<em>Syncing...</em>';
                btn.disabled = true;
                var data = new FormData();
                data.append('action', 'wpsyncer_sync_single');
                data.append('product_id', btn.getAttribute('data-product-id'));
                data.append('_ajax_nonce', btn.getAttribute('data-nonce'));
                fetch(btn.getAttribute('data-ajax-url'), { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (r.success) {
                            status.innerHTML = '<span style="color:green">&#10003; Sync triggered</span>';
                        } else {
                            status.innerHTML = '<span style="color:red">Error: ' + (r.data || 'unknown') + '</span>';
                        }
                        btn.disabled = false;
                    })
                    .catch(function() {
                        status.innerHTML = '<span style="color:red">Request failed</span>';
                        btn.disabled = false;
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler for per-product sync now.
     */
    public function ajax_sync_single() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( 'Invalid product ID.' );
        }

        check_ajax_referer( 'wpsyncer_sync_single_' . $product_id );

        $mode = $this->mode();
        if ( ! in_array( $mode, array( 'source', 'both' ), true ) ) {
            wp_send_json_error( 'Source mode is not enabled.' );
        }

        $source = new WPSYNCER_Source( $this );
        $source->sync_single_product( $product_id );

        wp_send_json_success( 'Sync queued for product #' . $product_id );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
        $page_url   = menu_page_url( 'wpsyncer-settings', false );
        ?>
        <div class="wrap">
            <h1>Woo Product Syncer</h1>
            <p>Sync WooCommerce products between stores. Configure this site as a <strong>Source</strong> (sends changes), <strong>Receiver</strong> (accepts changes), or <strong>Both</strong> (bidirectional).</p>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $page_url ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs', $page_url ) ); ?>" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">Logs</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'tools', $page_url ) ); ?>" class="nav-tab <?php echo 'tools' === $active_tab ? 'nav-tab-active' : ''; ?>">Tools</a>
            </nav>

            <?php
            if ( 'logs' === $active_tab ) {
                $this->render_logs_tab();
            } elseif ( 'tools' === $active_tab ) {
                $this->render_tools_tab();
            } else {
                $this->render_settings_tab();
            }
            ?>
        </div>
        <?php
    }

    public function render_settings_tab() {
        $s = $this->all();
        $endpoint = rest_url( 'wpsyncer/v1/product' );
        ?>
        <?php if ( isset( $_GET['settings_imported'] ) ) : ?>
            <div class="notice notice-success"><p>Settings imported successfully.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['bulk_sync_started'] ) ) : ?>
            <div class="notice notice-info"><p>Bulk sync started. Check logs for progress.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php" enctype="multipart/form-data">
            <?php settings_fields( 'wpsyncer_settings_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Mode</th>
                    <td>
                        <select name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[mode]">
                            <option value="disabled" <?php selected( $s['mode'], 'disabled' ); ?>>Disabled</option>
                            <option value="source" <?php selected( $s['mode'], 'source' ); ?>>Source</option>
                            <option value="receiver" <?php selected( $s['mode'], 'receiver' ); ?>>Receiver</option>
                            <option value="both" <?php selected( $s['mode'], 'both' ); ?>>Both (bidirectional)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Source site ID</th>
                    <td><input type="text" class="regular-text" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[source_site_id]" value="<?php echo esc_attr( $s['source_site_id'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Target receiver URL</th>
                    <td>
                        <input type="url" class="large-text" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[target_url]" value="<?php echo esc_attr( $s['target_url'] ); ?>" placeholder="https://shop.example.com">
                        <p class="description">Base URL of the receiver site only. The plugin appends the REST path (<code>/wp-json/wpsyncer/v1/product</code>) automatically.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Shared secret</th>
                    <td><input type="password" class="regular-text" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[shared_secret]" value="<?php echo esc_attr( $s['shared_secret'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row">Creation behavior</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[create_missing_products]" value="yes" <?php checked( $s['create_missing_products'], 'yes' ); ?>> Create missing products</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[create_missing_terms]" value="yes" <?php checked( $s['create_missing_terms'], 'yes' ); ?>> Create missing categories, tags, attributes and terms</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sync groups</th>
                    <td>
                        <?php foreach ( array(
                            'sync_core'       => 'Core product fields',
                            'sync_prices'     => 'Prices',
                            'sync_stock'      => 'Stock',
                            'sync_taxonomies' => 'Categories and tags',
                            'sync_attributes' => 'Attributes',
                            'sync_variations' => 'Variations',
                            'sync_images'     => 'Images and gallery',
                        ) as $key => $label ) : ?>
                            <label><input type="checkbox" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( $s[ $key ], 'yes' ); ?>> <?php echo esc_html( $label ); ?></label><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Custom meta whitelist</th>
                    <td>
                        <?php
                        $selected_keys = $this->meta_key_whitelist();
                        $available_keys = WPSYNCER_MetaFieldList::get_available_keys();
                        ?>
                        <select multiple="multiple" class="large-text code" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[sync_meta_keys]" style="height:150px;" id="wpsyncer-meta-select">
                            <?php foreach ( $available_keys as $key ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php echo in_array( $key, $selected_keys, true ) ? 'selected' : ''; ?>><?php echo esc_html( $key ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea rows="5" class="large-text code" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[sync_meta_keys]" id="wpsyncer-meta-textarea" style="display:none;"><?php echo esc_textarea( $s['sync_meta_keys'] ); ?></textarea>
                        <p class="description">Select custom meta keys to sync. Only keys found on products in this site are shown. This is a whitelist — do not sync all meta blindly.</p>
                        <script>
                        (function() {
                            var select = document.getElementById('wpsyncer-meta-select');
                            var textarea = document.getElementById('wpsyncer-meta-textarea');
                            if (select && textarea) {
                                function syncToTextarea() {
                                    var selected = [];
                                    for (var i = 0; i < select.options.length; i++) {
                                        if (select.options[i].selected) {
                                            selected.push(select.options[i].value);
                                        }
                                    }
                                    textarea.value = selected.join('\n');
                                }
                                select.addEventListener('change', syncToTextarea);
                                var form = select.closest('form');
                                if (form) {
                                    form.addEventListener('submit', syncToTextarea);
                                }
                            }
                        })();
                        </script>
                    </td>
                </tr>
                <tr>
                    <th scope="row">When source product/variation disappears</th>
                    <td>
                        <select name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[delete_behavior]">
                            <option value="ignore" <?php selected( $s['delete_behavior'], 'ignore' ); ?>>Ignore</option>
                            <option value="draft" <?php selected( $s['delete_behavior'], 'draft' ); ?>>Set draft</option>
                            <option value="trash" <?php selected( $s['delete_behavior'], 'trash' ); ?>>Trash</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Bulk sync batch size</th>
                    <td>
                        <input type="number" class="small-text" min="1" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[bulk_batch_size]" value="<?php echo esc_attr( $s['bulk_batch_size'] ); ?>">
                        <p class="description">Number of products to sync per batch. Default: 10.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Bulk sync batch delay (seconds)</th>
                    <td>
                        <input type="number" class="small-text" min="1" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[bulk_batch_delay]" value="<?php echo esc_attr( $s['bulk_batch_delay'] ); ?>">
                        <p class="description">Delay in seconds between batches. Default: 5.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Experimental: Sync product IDs</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[sync_product_ids]" value="yes" <?php checked( $s['sync_product_ids'], 'yes' ); ?>> Use source product IDs when creating products on the receiver</label>
                        <p class="description" style="color:#856404;background:#fff3cd;padding:6px 10px;border-radius:3px;">&#9888; Use source product IDs when creating products on the receiver. Use only on fresh sites with no existing products.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Debug logging</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WPSYNCER_SETTINGS_OPTION ); ?>[debug_logging]" value="yes" <?php checked( $s['debug_logging'], 'yes' ); ?>> Keep last 100 sync log entries</label></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    public function render_logs_tab() {
        $logs = WPSYNCER_Logger::get_logs();
        ?>
        <h2>Recent sync logs</h2>
        <table class="widefat striped">
            <thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead>
            <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="4">No logs yet.</td></tr>
            <?php else : ?>
                <?php foreach ( array_reverse( $logs ) as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                        <td><?php echo esc_html( strtoupper( $entry['level'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
                        <td><code><?php echo esc_html( wp_json_encode( $entry['context'] ?? array() ) ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function render_tools_tab() {
        $mode = $this->mode();
        ?>
        <h2>Tools</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Export settings</th>
                <td>
                    <input type="button" class="button" value="Export Settings" onclick="window.location.href='<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=wpsyncer_export_settings' ), 'wpsyncer_export' ); ?>'">
                    <p class="description">Download settings as JSON. Includes shared secret — handle file securely.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Import settings</th>
                <td>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="wpsyncer_import_settings">
                        <input type="file" name="wpsyncer_import_file" accept=".json">
                        <input type="submit" class="button" name="wpsyncer_import_settings" value="Import Settings">
                    </form>
                </td>
            </tr>
            <?php if ( in_array( $mode, array( 'source', 'both' ), true ) ) : ?>
            <tr>
                <th scope="row">Sync all products</th>
                <td>
                    <input type="button" class="button button-primary" value="Sync All Products" onclick="if(confirm('Sync all published products now?'))window.location.href='<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=wpsyncer_bulk_sync' ), 'wpsyncer_bulk_sync' ); ?>'">
                    <p class="description">Queue all published products for sync. Use with caution on large catalogs.</p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }
}
