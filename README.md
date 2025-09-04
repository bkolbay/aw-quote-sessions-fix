# AW Quote Sessions Fix

A WordPress plugin to fix WooCommerce Request a Quote compatibility with WooCommerce 10.1+ session storage changes.

## Problem

WooCommerce 10.1.0 introduced a breaking change that removed persistent cart storage from user meta in favor of session-only storage. This architectural change broke the "WooCommerce Request a Quote" plugin's session-dependent storage for logged-in users, causing quote items to:

- Appear to be added successfully via AJAX
- Disappear when pages are refreshed or navigated
- Prevent quote submissions due to empty session data
- Work correctly for admin users but fail for customers

The root cause is that WooCommerce 10.1+ no longer synchronizes cart/session data to `wp_usermeta` table for logged-in users, making session data volatile and unreliable for persistent storage needs.

## Our Solution

This plugin provides a compatibility layer that:

### Core Functionality
- **Replaces AJAX handlers**: Intercepts all quote-related AJAX calls with fixed versions
- **Dual storage approach**: Uses user meta as primary storage with session as fallback
- **Session restoration**: Automatically restores quotes to session before operations
- **Maintains compatibility**: Preserves original response formats and user experience

### Key Features
- ✅ **Quote persistence**: Items remain in cart across page refreshes
- ✅ **Multiple item support**: Can add multiple products to quote cart  
- ✅ **Proper AJAX responses**: Returns original plugin's JSON format for success messages
- ✅ **Quote submission**: Successfully submits quotes with all items
- ✅ **Automatic cart clearing**: Empties cart after successful submission
- ✅ **Role compatibility**: Works for both customer and admin users
- ✅ **Session synchronization**: Maintains backwards compatibility

## Installation

1. Upload the `aw-quote-sessions-fix` folder to `/wp-content/plugins/`
2. Activate through WordPress admin → Plugins
3. Ensure "WooCommerce Request a Quote" plugin remains active
4. No configuration needed - works automatically

## Technical Details

### AJAX Handlers Replaced
- `add_to_quote_single_vari` - Variable product quotes
- `add_to_quote_single` - Simple product quotes  
- `add_to_quote` - General quote additions

### Storage Strategy
- **Logged-in users**: User meta (`addify_quote`) as primary, session as secondary
- **Guest users**: Session only (unchanged from original)
- **Data synchronization**: Automatic restoration between storage methods

### WordPress Hooks Used
- `wp_ajax_*` / `wp_ajax_nopriv_*` - AJAX handler replacement
- `woocommerce_load_cart_from_session` - Session synchronization
- `wp_loaded` - Quote submission preprocessing
- `wp_login` - Quote restoration on login
- `addify_quote_created` - Post-submission cart clearing

### WooCommerce Compatibility
This fix addresses the breaking change introduced in:
- **WooCommerce 10.1.0**: Removed `_woocommerce_persistent_cart_*` from user meta
- **Impact**: Session-only storage with 7-day expiration vs previous permanent storage
- **Solution**: Restores reliable storage for quote functionality specifically

## For Plugin Authors

This temporary fix can serve as a reference for updating the core "WooCommerce Request a Quote" plugin. The key changes needed in your codebase:

1. **Primary storage switch**: Change from session-first to user meta-first for logged-in users
2. **Session restoration**: Add hooks to restore data before operations
3. **Compatibility layer**: Maintain session synchronization for backwards compatibility

## Testing Verified

- ✅ Single item addition and persistence
- ✅ Multiple item additions 
- ✅ Quote cart persistence across page refreshes
- ✅ Quote submission with all items
- ✅ Success messages and "View Quote" links
- ✅ Cart clearing after submission
- ✅ Admin vs customer account compatibility

## Compatibility

- **WordPress**: 6.5+
- **WooCommerce**: 10.1.0+ (specifically addresses 10.1+ session changes)
- **PHP**: 7.4+
- **Dependencies**: WooCommerce Request a Quote v2.7.3+

## License

GPL v2 or later

## Author

ArachnidWorks, Inc.