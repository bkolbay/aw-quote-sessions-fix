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
        if (!class_exists('WooCommerce') || !is_plugin_active('woocommerce-request-a-quote-2.7.3/woocommerce-request-a-quote.php')) {
            return;
        }
        
        // Remove original AJAX handlers
        remove_action('wp_ajax_afrfq_add_to_quote_callback', array('AF_R_F_Q_Ajax_Controller', 'afrfq_add_to_quote_callback_function'));
        remove_action('wp_ajax_nopriv_afrfq_add_to_quote_callback', array('AF_R_F_Q_Ajax_Controller', 'afrfq_add_to_quote_callback_function'));
        
        // Register our replacement handlers
        add_action('wp_ajax_afrfq_add_to_quote_callback', array($this, 'fixed_add_to_quote_callback'));
        add_action('wp_ajax_nopriv_afrfq_add_to_quote_callback', array($this, 'fixed_add_to_quote_callback'));
        
        // Hook into session initialization
        add_action('woocommerce_load_cart_from_session', array($this, 'sync_quotes_to_session'), 10);
    }
    
    public function fixed_add_to_quote_callback() {
        if (!wp_verify_nonce($_POST['security'], 'af-rfq-nonce') || !isset($_POST['product_id'])) {
            wp_die('Security check failed');
        }
        
        $product_id = intval($_POST['product_id']);
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        
        // Get existing quotes from user meta (primary) or session (fallback)
        $quotes = $this->get_quotes_data();
        
        // Add new quote item
        $quote_item = array(
            'product_id' => $product_id,
            'quantity' => $quantity,
            'variation_id' => $variation_id,
            'added_on' => current_time('mysql')
        );
        
        $quotes[] = $quote_item;
        
        // Store in user meta first (primary storage)
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'addify_quote', $quotes);
        }
        
        // Also store in session (secondary/fallback)
        if (WC()->session) {
            WC()->session->set('quotes', $quotes);
        }
        
        wp_send_json_success(array(
            'message' => 'Product added to quote successfully',
            'quote_count' => count($quotes),
            'storage_method' => is_user_logged_in() ? 'user_meta_primary' : 'session_only'
        ));
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
    
    public function restore_quotes_on_login($user_login, $user) {
        if (!WC()->session) {
            return;
        }
        
        $user_quotes = get_user_meta($user->ID, 'addify_quote', true);
        if (!empty($user_quotes) && is_array($user_quotes)) {
            WC()->session->set('quotes', $user_quotes);
        }
    }
}

new AW_Quote_Sessions_Fix();