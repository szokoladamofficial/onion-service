<?php
/**
 * Plugin Name:       Onion Service by Adam Szokol
 * Description:       Enables Tor Onion Service support and Onion-Location headers for any WordPress site.
 * Version:           1.0.0
 * Author:            Adam Szokol Public Benefit Corporation
 * Author URI:        https://adamszokol.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adamszokol-onion-service
 * Requires at least: 5.8
 * Tested up to:      6.8
 * Requires PHP:      7.4
 */
 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly.

final class Onion_Service_Plugin {

    private static $_instance = null;
    private $config_file_path;
    private $sunrise_file_path;
    private $wp_config_path;
    private $admin_page_hook = '';

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        $this->sunrise_file_path = WP_CONTENT_DIR . '/sunrise.php';
        $this->wp_config_path    = ABSPATH . 'wp-config.php';
        $this->config_file_path  = WP_CONTENT_DIR . '/uploads/wp-onion-service-config.php';
        
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function activate() {
        $this->manage_sunrise();
    }
    
    public function init() {
        // REMOVED load_plugin_textdomain(): WordPress automatically handles loading translations for plugins hosted on WordPress.org.
        $this->add_plugin_hooks();
        $this->manage_sunrise();
    }

    private function add_plugin_hooks() {
        $menu_hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
        add_action( $menu_hook, [ $this, 'add_admin_page' ] );

        // Enqueue scripts/styles instead of outputting directly in HTML
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_post_ts_save', [ $this, 'handle_form_save' ] );
        add_action( 'admin_post_ts_delete', [ $this, 'handle_form_delete' ] );
        add_action( 'wp_ajax_ts_search_sites', [ $this, 'ajax_search_sites' ] );
        add_action( 'template_redirect', [ $this, 'send_onion_location_header' ] );
    }
    
    public function add_admin_page() {
        // Updated menu titles
        $page_title = __( 'Onion Service Settings', 'adamszokol-onion-service' );
        $menu_title = __( 'Onion Service', 'adamszokol-onion-service' );
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        $menu_slug  = 'adamszokol-onion-service-settings';
        $function   = [ $this, 'render_settings_page' ];
        $icon       = 'dashicons-shield-alt';

        $this->admin_page_hook = add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon, 82 );
    }

    /**
     * Enqueue admin scripts and styles for the settings page (addresses review point).
     */
    public function enqueue_admin_assets( $hook_suffix ) {
        if ( $hook_suffix !== $this->admin_page_hook ) {
            return;
        }

        // Register and enqueue inline styles
        wp_register_style( 'adsz-onion-admin-style', false, [], '1.0.3' );
        wp_enqueue_style( 'adsz-onion-admin-style' );
        $css = '#ts_search_results div:hover { background: #007cba; color: #fff; }';
        wp_add_inline_style( 'adsz-onion-admin-style', $css );

        // Register and enqueue inline script
        wp_register_script( 'adsz-onion-admin-js', false, [ 'jquery' ], '1.0.3', true );
        wp_enqueue_script( 'adsz-onion-admin-js' );

        // Localize script for AJAX data and i18n strings (replaces direct PHP output in script tag)
        wp_localize_script( 'adsz-onion-admin-js', 'adszOnionData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ts_search_nonce' ),
            'i18n'    => [
                'searching' => esc_html__( 'Searching...', 'adamszokol-onion-service' ),
                'noSites'   => esc_html__( 'No sites found.', 'adamszokol-onion-service' ),
                'reqFailed' => esc_html__( 'Request failed.', 'adamszokol-onion-service' ),
            ],
        ]);

        // Add the inline script (replaces direct <script> tag)
        $js = "
        document.addEventListener('DOMContentLoaded', function() {
            const searchBox = document.getElementById('ts_blog_search'), 
                  resultsDiv = document.getElementById('ts_search_results'), 
                  hiddenIdField = document.getElementById('ts_blog_id');
            let searchTimeout;

            async function performSearch() {
                const searchTerm = searchBox.value;
                if (searchTerm.length < 2) { resultsDiv.innerHTML = ''; return; }
                
                resultsDiv.innerHTML = '<div>' + adszOnionData.i18n.searching + '</div>';
                
                const data = new URLSearchParams({ 
                    action: 'ts_search_sites', 
                    nonce: adszOnionData.nonce, 
                    search: searchTerm 
                });

                try {
                    const response = await fetch(adszOnionData.ajaxUrl, { method: 'POST', body: data });
                    const result = await response.json();
                    resultsDiv.innerHTML = '';

                    if (result.success && result.data.length > 0) {
                        result.data.forEach(function(site) {
                            const siteDiv = document.createElement('div');
                            siteDiv.textContent = site.name;
                            siteDiv.style.padding = '8px'; 
                            siteDiv.style.cursor = 'pointer';
                            siteDiv.addEventListener('click', function() {
                                hiddenIdField.value = site.id;  
                                searchBox.value = this.textContent;     
                                resultsDiv.innerHTML = '';  
                            });
                            resultsDiv.appendChild(siteDiv);
                        });
                    } else { 
                        resultsDiv.innerHTML = '<div>' + adszOnionData.i18n.noSites + '</div>'; 
                    }
                } catch (error) { 
                    resultsDiv.innerHTML = '<div>' + adszOnionData.i18n.reqFailed + '</div>'; 
                }
            }

            searchBox.addEventListener('keyup', function() { 
                clearTimeout(searchTimeout); 
                searchTimeout = setTimeout(performSearch, 300); 
            });

            document.addEventListener('click', function(e) { 
                if (!searchBox.contains(e.target) && !resultsDiv.contains(e.target)) {
                    resultsDiv.innerHTML = ''; 
                }
            });
        });
        ";
        wp_add_inline_script( 'adsz-onion-admin-js', $js );
    }

    private function get_setting( $key, $default = false ) {
        $option_name = 'ts_' . $key;
        // Updated text domain in get/update settings
        return is_multisite() ? get_site_option( $option_name, $default ) : get_option( $option_name, $default );
    }
    
    private function update_setting( $key, $value ) {
        $option_name = 'ts_' . $key;
        return is_multisite() ? update_site_option( $option_name, $value ) : update_option( $option_name, $value );
    }

    public function render_settings_page() {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $capability ) ) return;

        settings_errors( 'ts_messages' );
        $this->show_config_warnings();

        $onion_map        = $this->get_setting( 'map', [] );
        $is_disabled      = $this->get_setting( 'service_disabled', 0 );
        $disabled_message = $this->get_setting( 'disabled_message', 'This Onion Service is temporarily disabled.' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Onion Service Settings', 'adamszokol-onion-service' ); ?></h1>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ts_save">
                <?php wp_nonce_field( 'ts_save_action' ); ?>

                <h2><?php esc_html_e( 'Service Status', 'adamszokol-onion-service' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable Service', 'adamszokol-onion-service' ); ?></th>
                        <td>
                            <label><input name="service_disabled" type="checkbox" value="1" <?php checked( $is_disabled, 1 ); ?>> <?php esc_html_e( 'Disable all .onion URL mappings.', 'adamszokol-onion-service' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Visitors to .onion URLs will see a maintenance message.', 'adamszokol-onion-service' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="disabled_message"><?php esc_html_e( 'Disabled Message', 'adamszokol-onion-service' ); ?></label></th>
                        <td><textarea name="disabled_message" id="disabled_message" class="large-text" rows="3"><?php echo esc_textarea( $disabled_message ); ?></textarea></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Add or Update Mapping', 'adamszokol-onion-service' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ts_blog_search"><?php esc_html_e( 'Select Site', 'adamszokol-onion-service' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_blog_search" class="regular-text" placeholder="<?php esc_attr_e( 'Start typing site name or domain...', 'adamszokol-onion-service' ); ?>" autocomplete="off">
                            <div id="ts_search_results" style="border:1px solid #ccd0d4; max-height:150px; overflow-y:auto; background:#fff; position:absolute; z-index:100; width: 25em;"></div>
                            <input type="hidden" name="blog_id" id="ts_blog_id">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ts_onion_url"><?php esc_html_e( '.onion Domain', 'adamszokol-onion-service' ); ?></label></th>
                        <td><input name="onion_url" type="text" id="ts_onion_url" class="regular-text" placeholder="yourlongv3address.onion" required></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings & Mappings', 'adamszokol-onion-service' ); ?></button></p>
            </form>

            <h2><?php esc_html_e( 'Current Mappings', 'adamszokol-onion-service' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th><?php esc_html_e( 'Site ID', 'adamszokol-onion-service' ); ?></th><th><?php esc_html_e( 'Mapped .onion Domain', 'adamszokol-onion-service' ); ?></th><th style="width:100px;"><?php esc_html_e( 'Actions', 'adamszokol-onion-service' ); ?></th></tr></thead>
                <tbody>
                    <?php if ( empty( $onion_map ) ) : ?>
                        <tr><td colspan="3"><?php esc_html_e( 'No .onion mappings have been saved yet.', 'adamszokol-onion-service' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $onion_map as $blog_id => $onion_url ) : ?>
                            <tr>
                                <td><?php echo esc_html( $blog_id ); ?></td>
                                <td><?php echo esc_html( $onion_url ); ?></td>
                                <td>
                                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="ts_delete">
                                        <input type="hidden" name="blog_id_to_delete" value="<?php echo esc_attr( $blog_id ); ?>">
                                        <?php wp_nonce_field( 'ts_delete_action_' . $blog_id ); ?>
                                        <button type="submit" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'adamszokol-onion-service' ); ?>')"><?php esc_html_e( 'Delete', 'adamszokol-onion-service' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function handle_form_save() {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        $nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! current_user_can( $capability ) || ! wp_verify_nonce( $nonce, 'ts_save_action' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'adamszokol-onion-service' ) );
        }
    
        $this->update_setting( 'service_disabled', isset( $_POST['service_disabled'] ) ? 1 : 0 );
        $this->update_setting( 'disabled_message', isset( $_POST['disabled_message'] ) ? wp_kses_post( wp_unslash( $_POST['disabled_message'] ) ) : '' );
        
        $blog_id   = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : 0;
        $onion_url = isset( $_POST['onion_url'] ) ? sanitize_text_field( wp_unslash( $_POST['onion_url'] ) ) : '';
    
        if ( $blog_id > 0 && ! empty( $onion_url ) && strpos( $onion_url, '.onion' ) !== false ) {
            $onion_map = $this->get_setting( 'map', [] );
            $onion_map[ $blog_id ] = $onion_url;
            $this->update_setting( 'map', $onion_map );
        }
    
        $this->write_static_config_file();
        add_settings_error( 'ts_messages', 'settings_saved', __( 'Settings saved.', 'adamszokol-onion-service' ), 'updated' );
        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    public function handle_form_delete() {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        $blog_id_to_delete = isset( $_POST['blog_id_to_delete'] ) ? absint( $_POST['blog_id_to_delete'] ) : 0;
        $nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    
        if ( ! current_user_can( $capability ) || $blog_id_to_delete === 0 || ! wp_verify_nonce( $nonce, 'ts_delete_action_' . $blog_id_to_delete ) ) {
            wp_die( esc_html__( 'Permission denied.', 'adamszokol-onion-service' ) );
        }
        
        $onion_map = $this->get_setting( 'map', [] );
        if ( isset( $onion_map[ $blog_id_to_delete ] ) ) {
            unset( $onion_map[ $blog_id_to_delete ] );
            $this->update_setting( 'map', $onion_map );
            $this->write_static_config_file();
            add_settings_error( 'ts_messages', 'setting_deleted', __( 'Mapping deleted.', 'adamszokol-onion-service' ), 'updated' );
        }
        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    public function ajax_search_sites() {
        check_ajax_referer( 'ts_search_nonce', 'nonce' );
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $capability ) ) wp_send_json_error( 'Permission denied.' );
    
        $search_term = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $sites = [];
        
        if ( is_multisite() ) {
            // Note: get_sites() is already correctly used here for multisite search
            $results = get_sites([ 'search' => $search_term, 'number' => 10 ]);
            foreach ( $results as $site ) {
                $details = get_blog_details($site->blog_id);
                $sites[] = [ 'id' => $site->blog_id, 'name' => $details->blogname . " ({$site->domain}{$site->path})" ];
            }
        } else {
             // Single-site fallback for search
             if ( stristr( get_bloginfo( 'name' ), $search_term ) || stristr( get_bloginfo( 'url' ), $search_term ) ) {
                $sites[] = [ 'id' => 1, 'name' => get_bloginfo( 'name' ) ];
            }
        }
        wp_send_json_success( $sites );
    }

    public function send_onion_location_header() {
        if ( is_admin() || headers_sent() || ( defined('DOING_AJAX') && DOING_AJAX ) ) return;
        
        $http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        if ( strpos( $http_host, '.onion' ) !== false ) return;

        $current_blog_id = get_current_blog_id();
        $onion_map = $this->get_setting( 'map', [] );
        
        if ( isset( $onion_map[ $current_blog_id ] ) && ! $this->get_setting( 'service_disabled', 0 ) ) {
            // Updated text domain for translations here
            header( 'Onion-Location: http://' . sanitize_text_field( $onion_map[ $current_blog_id ] ) );
        }
    }

    private function write_static_config_file() {
        // Retrieve settings
        $onion_map = $this->get_setting( 'map', [] );
        $is_disabled = (int) $this->get_setting( 'service_disabled', 0 );
        $disabled_message = addslashes( $this->get_setting( 'disabled_message', '' ) );

        // Manually construct the PHP array string to avoid the var_export() debug function warning.
        $domains_array_php = '';
        foreach( array_flip( $onion_map ) as $onion_domain => $blog_id ) {
            $domains_array_php .= "        '" . addslashes($onion_domain) . "' => " . (int)$blog_id . ",\n";
        }

        $php_code = "<?php\n// Auto-generated by Onion Service by Adam Szokol plugin. Do not edit.\n\$GLOBALS['ts_sunrise_data'] = [\n";
        $php_code .= "    'extra_domains' => [\n" . $domains_array_php . "    ],\n";
        $php_code .= "    'is_disabled' => $is_disabled,\n";
        $php_code .= "    'disabled_message' => '$disabled_message',\n";
        $php_code .= "];\n";
        
        if ( ! is_dir( dirname( $this->config_file_path ) ) ) wp_mkdir_p( dirname( $this->config_file_path ) );
        file_put_contents( $this->config_file_path, $php_code );
    }

    private function manage_sunrise() {
        WP_Filesystem();
        global $wp_filesystem;
        
        $sunrise_defined = defined( 'SUNRISE' ) && SUNRISE;
        // Updated content for sunrise.php
        $sunrise_content = "<?php\n/**\n * Sunrise handler for Onion Service by Adam Szokol plugin.\n * This file was automatically generated.\n * @version 1.0\n */\nif(file_exists(WP_CONTENT_DIR . '/uploads/wp-onion-service-config.php')){\ninclude_once(WP_CONTENT_DIR . '/uploads/wp-onion-service-config.php');\nif(isset(\$_SERVER['HTTP_HOST'],\$GLOBALS['ts_sunrise_data']['extra_domains'][\$_SERVER['HTTP_HOST']])){\nif(\$GLOBALS['ts_sunrise_data']['is_disabled']){wp_die(\$GLOBALS['ts_sunrise_data']['disabled_message'],'Service Disabled',['response'=>503]);}\n\$blog_id=\$GLOBALS['ts_sunrise_data']['extra_domains'][\$_SERVER['HTTP_HOST']];\n\$GLOBALS['blog_id']=\$blog_id;}}?>";

        if ( ! $wp_filesystem->exists( $this->sunrise_file_path ) ) {
            $wp_filesystem->put_contents( $this->sunrise_file_path, $sunrise_content, FS_CHMOD_FILE );
        }

        if ( ! $sunrise_defined && $wp_filesystem->is_writable( $this->wp_config_path ) ) {
            $config_content = $wp_filesystem->get_contents( $this->wp_config_path );
            if ( strpos( $config_content, 'SUNRISE' ) === false ) {
                $insert_line = "define( 'SUNRISE', true );";
                $config_content = preg_replace( "/(<\?php)/", "$1\n{$insert_line}\n", $config_content, 1 );
                $wp_filesystem->put_contents( $this->wp_config_path, $config_content );
            }
        }
    }

    private function show_config_warnings() {
        WP_Filesystem();
        global $wp_filesystem;

        // Updated text domain for translations in warnings
        if ( ! defined( 'SUNRISE' ) || ! SUNRISE ) {
            // translators: %s is a line of code.
            $message = sprintf( __( 'The <code>SUNRISE</code> constant is not defined or is false in your <code>wp-config.php</code> file. The plugin attempted to add it, but failed. Please add the following line to your <code>wp-config.php</code> manually: %s', 'adamszokol-onion-service' ), '<br><code>define( \'SUNRISE\', true );</code>' );
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Action Required:', 'adamszokol-onion-service' ) . '</strong> ' . wp_kses_post( $message ) . '</p></div>';
        }
        if ( ! $wp_filesystem->is_writable( $this->sunrise_file_path ) ) {
            // translators: %s is a file path.
            $message = sprintf( __( 'The <code>sunrise.php</code> file at <code>%s</code> is not writable. Please check file permissions.', 'adamszokol-onion-service' ), esc_html( $this->sunrise_file_path ) );
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Warning:', 'adamszokol-onion-service' ) . '</strong> ' . wp_kses_post( $message ) . '</p></div>';
        }
        $uploads_dir = dirname( $this->config_file_path );
        if ( ! $wp_filesystem->is_writable( $uploads_dir ) ) {
            // translators: %s is a directory path.
            $message = sprintf( __( 'The uploads directory (<code>%s</code>) is not writable. The plugin cannot save its configuration file.', 'adamszokol-onion-service' ), esc_html( $uploads_dir ) );
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Warning:', 'adamszokol-onion-service' ) . '</strong> ' . wp_kses_post( $message ) . '</p></div>';
        }
    }
}

Onion_Service_Plugin::instance();
