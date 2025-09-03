# AW Quote Sessions Fix

A WordPress plugin to fix WooCommerce Request a Quote compatibility with WooCommerce 10.1+ session storage changes.

## Problem

WooCommerce 10.1.0 removed persistent cart storage from user meta in favor of session-only storage. This broke the "WooCommerce Request a Quote" plugin's session-dependent storage for logged-in users, causing quote items to disappear.

## Solution

This plugin replaces the original quote plugin's AJAX handlers with fixed versions that:

- Use **user meta as primary storage** for logged-in users
- Fall back to session storage when user meta is unavailable
- Maintain session synchronization for backwards compatibility
- Restore quotes from user meta on user login

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through WordPress admin
3. Ensure "WooCommerce Request a Quote" plugin is also active

## Technical Details

### Modified Functions:
- `afrfq_add_to_quote_callback` - Fixed AJAX handler for adding items to quotes

### Storage Strategy:
- **Logged-in users**: User meta (primary) → Session (secondary)
- **Guest users**: Session only (unchanged)

### Hooks Used:
- `wp_ajax_afrfq_add_to_quote_callback` - Replace original handler
- `woocommerce_load_cart_from_session` - Sync quotes to session
- `wp_login` - Restore quotes on login

## Compatibility

- WordPress: 6.5+
- WooCommerce: 10.1.0+
- PHP: 7.4+
- Requires: WooCommerce Request a Quote plugin

## Author

ArachnidWorks, Inc.