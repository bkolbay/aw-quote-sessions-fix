<?php
/**
 * Plugin Name: AW Quote Sessions Fix
 * Plugin URI: https://arachnidworks.com
 * Description: Fixes WooCommerce Request a Quote plugin compatibility with WooCommerce 10.1+ session storage changes.
 * Version: 1.0.0
 * Author: ArachnidWorks, Inc.
 * Author URI: https://arachnidworks.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 10.1.0
 * WC tested up to: 10.1.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class AW_Quote_Sessions_Fix {
    
    public function __construct() {
        add_action('init', array($this, 'init'), 25);
        add_action('wp_login', array($this, 'restore_quotes_on_login'), 10, 2);
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Register our replacement handlers with higher priority to override
        add_action('wp_ajax_add_to_quote_single_vari', array($this, 'fixed_add_to_quote_callback'), 5);
        add_action('wp_ajax_nopriv_add_to_quote_single_vari', array($this, 'fixed_add_to_quote_callback'), 5);
        add_action('wp_ajax_add_to_quote_single', array($this, 'fixed_add_to_quote_callback'), 5);
        add_action('wp_ajax_nopriv_add_to_quote_single', array($this, 'fixed_add_to_quote_callback'), 5);
        add_action('wp_ajax_add_to_quote', array($this, 'fixed_add_to_quote_callback'), 5);
        add_action('wp_ajax_nopriv_add_to_quote', array($this, 'fixed_add_to_quote_callback'), 5);
        
        // Hook to remove original handlers after they're registered
        add_action('init', array($this, 'remove_original_handlers'), 30);
        
        // Hook into session initialization
        add_action('woocommerce_load_cart_from_session', array($this, 'sync_quotes_to_session'), 10);
        
        // Hook before quote submission to ensure quotes are in session
        add_action('wp_loaded', array($this, 'ensure_quotes_before_submission'), 5);
        
        // Hook after quote creation to ensure proper clearing
        add_action('addify_quote_created', array($this, 'ensure_quote_cleared'), 10);
    }
    
    public function remove_original_handlers() {
        global $wp_filter;
        
        // Remove original handlers by finding them in the hook array
        $actions_to_clean = array(
            'wp_ajax_add_to_quote_single_vari',
            'wp_ajax_nopriv_add_to_quote_single_vari', 
            'wp_ajax_add_to_quote_single',
            'wp_ajax_nopriv_add_to_quote_single',
            'wp_ajax_add_to_quote',
            'wp_ajax_nopriv_add_to_quote'
        );
        
        foreach ($actions_to_clean as $action) {
            if (isset($wp_filter[$action])) {
                foreach ($wp_filter[$action]->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback_key => $callback) {
                        if (is_array($callback['function']) && 
                            isset($callback['function'][0]) && 
                            is_object($callback['function'][0]) && 
                            get_class($callback['function'][0]) === 'AF_R_F_Q_Ajax_Controller') {
                            unset($wp_filter[$action]->callbacks[$priority][$callback_key]);
                            error_log('AW Quote Fix: Removed original handler for ' . $action);
                        }
                    }
                }
            }
        }
    }
    
    public function fixed_add_to_quote_callback() {
        error_log('AW Quote Fix: AJAX handler called - Action: ' . $_POST['action']);
        
        // Handle different AJAX actions with their specific parameter formats
        if ($_POST['action'] === 'add_to_quote_single_vari' && isset($_POST['form_data'])) {
            // Parse form data for variation products
            parse_str(sanitize_meta('', wp_unslash($_POST['form_data']), ''), $form_data);
            $product_id = isset($form_data['add-to-cart']) ? intval($form_data['add-to-cart']) : 0;
            $quantity = isset($form_data['quantity']) ? intval($form_data['quantity']) : 1;
            $variation_id = isset($form_data['variation_id']) ? intval($form_data['variation_id']) : 0;
            $variation = array();
            foreach ($form_data as $key => $value) {
                if (!in_array($key, array('add-to-cart', 'quantity', 'variation_id', 'product_id'), true)) {
                    $variation[$key] = $value;
                }
            }
        } else {
            // Handle simple product additions
            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
            $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
            $variation = array();
            $form_data = $_POST;
        }
        
        if (!$product_id) {
            wp_send_json_error('Product ID missing');
            return;
        }
        
        // Restore quotes from user meta to session for logged-in users BEFORE processing
        $this->ensure_quotes_in_session();
        
        // Use original plugin's add_to_quote method but with fixed session handling
        $ajax_add_to_quote = new AF_R_F_Q_Quote();
        $result = $ajax_add_to_quote->add_to_quote($form_data, $product_id, $quantity, $variation_id, $variation);
        
        // Force save to user meta immediately after successful addition
        if ($result && is_user_logged_in()) {
            $session_quotes = WC()->session->get('quotes');
            if (!empty($session_quotes)) {
                update_user_meta(get_current_user_id(), 'addify_quote', $session_quotes);
                error_log('AW Quote Fix: Saved ' . count($session_quotes) . ' items to user meta');
            }
        }
        
        if ($result !== false) {
            // Get the updated quote contents and product info
            $quote_contents = WC()->session->get('quotes');
            $product = '';
            $product_name = 'Product';
            
            if (isset($quote_contents[$result])) {
                $product = $quote_contents[$result]['data'];
            }
            
            if (is_object($product)) {
                $product_name = $product->get_name();
            }
            
            // Return same response format as original plugin
            if ('yes' === get_option('enable_ajax_shop') && false !== $result) {
                ob_start();
                wc_get_template(
                    'quote/mini-quote.php',
                    array(),
                    '/woocommerce/addify/rfq/',
                    WP_PLUGIN_DIR . '/woocommerce-request-a-quote-2.7.3/templates/'
                );
                $mini_quote = ob_get_clean();
                
                ob_start();
                ?>
                <a href="<?php echo esc_url(get_page_link(get_option('addify_atq_page_id'))); ?>" class="added_to_cart added_to_quote wc-forward" title="View Quote"><?php echo esc_html(get_option('afrfq_view_button_message')); ?></a>
                <?php
                $view_quote_btn = ob_get_clean();
                
                wp_send_json(array(
                    'mini-quote' => $mini_quote,
                    'view_button' => $view_quote_btn,
                ));
            } else {
                $button = '<a href="' . esc_url(get_page_link(get_option('addify_atq_page_id'))) . '" class="button wc-forward">' . __('View quote', 'addify_rfq') . '</a>';
                wc_add_notice(sprintf(__('"%1$s" has been added to your quote. %2$s', 'addify_rfq'), $product_name, wp_kses_post($button)), 'success');
                echo 'success';
            }
        } else {
            wc_add_notice(sprintf(__('"%s" has not been added to your quote.', 'addify_rfq'), $product_name), 'error');
            echo 'failed';
        }
        
        die();
    }
    
    private function ensure_quotes_in_session() {
        if (!is_user_logged_in() || !WC()->session) {
            return;
        }
        
        // Get quotes from user meta
        $user_quotes = get_user_meta(get_current_user_id(), 'addify_quote', true);
        if (!empty($user_quotes) && is_array($user_quotes)) {
            // Set in session so original plugin can work with it
            WC()->session->set('quotes', $user_quotes);
            error_log('AW Quote Fix: Restored ' . count($user_quotes) . ' items from user meta to session');
        }
    }
    
    private function get_quotes_data() {
        $quotes = array();
        
        if (is_user_logged_in()) {
            // For logged-in users, check user meta first
            $user_quotes = get_user_meta(get_current_user_id(), 'addify_quote', true);
            if (!empty($user_quotes) && is_array($user_quotes)) {
                $quotes = $user_quotes;
            } else {
                // Fallback to session if user meta empty
                if (WC()->session) {
                    $session_quotes = WC()->session->get('quotes');
                    if (!empty($session_quotes) && is_array($session_quotes)) {
                        $quotes = $session_quotes;
                        // Sync to user meta
                        update_user_meta(get_current_user_id(), 'addify_quote', $quotes);
                    }
                }
            }
        } else {
            // For guests, use session only
            if (WC()->session) {
                $session_quotes = WC()->session->get('quotes');
                if (!empty($session_quotes) && is_array($session_quotes)) {
                    $quotes = $session_quotes;
                }
            }
        }
        
        return is_array($quotes) ? $quotes : array();
    }
    
    public function sync_quotes_to_session() {
        if (!is_user_logged_in() || !WC()->session) {
            return;
        }
        
        $user_quotes = get_user_meta(get_current_user_id(), 'addify_quote', true);
        if (!empty($user_quotes) && is_array($user_quotes)) {
            WC()->session->set('quotes', $user_quotes);
        }
    }
    
    public function ensure_quotes_before_submission() {
        // Check if this is a quote submission
        if (isset($_POST['afrfq_action']) && is_user_logged_in()) {
            error_log('AW Quote Fix: Quote submission detected, ensuring quotes in session');
            $this->ensure_quotes_in_session();
            
            // Double-check that quotes are now in session
            $session_quotes = WC()->session ? WC()->session->get('quotes') : null;
            if (empty($session_quotes)) {
                error_log('AW Quote Fix: WARNING - No quotes in session for submission');
            } else {
                error_log('AW Quote Fix: ' . count($session_quotes) . ' quotes restored to session for submission');
            }
        }
    }
    
    public function restore_quotes_on_login($user_login, $user) {
        if (!WC()->session) {
            return;
        }
        
        $user_quotes = get_user_meta($user->ID, 'addify_quote', true);
        if (!empty($user_quotes) && is_array($user_quotes)) {
            WC()->session->set('quotes', $user_quotes);
        }
    }
    
    public function ensure_quote_cleared($quote_id) {
        // Ensure quotes are properly cleared from both session and user meta after successful submission
        if (is_user_logged_in()) {
            delete_user_meta(get_current_user_id(), 'addify_quote');
            error_log('AW Quote Fix: Cleared quote data from user meta after submission');
        }
        
        if (WC()->session) {
            WC()->session->set('quotes', null);
            WC()->session->set('quote_fields_data', null);
            error_log('AW Quote Fix: Cleared quote data from session after submission');
        }
    }
}

new AW_Quote_Sessions_Fix();