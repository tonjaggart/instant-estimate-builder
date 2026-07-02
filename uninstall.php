<?php
// Exit if accessed directly or not truly uninstalling.
if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

/**
 * Non-destructive cleanup:
 * - Clear the daily license check schedule (in case deactivation wasn't run)
 * - Remove transient cache(s)
 * 
 * NOTE: We intentionally do NOT delete options or CPT data here.
 */

if ( function_exists('wp_clear_scheduled_hook') ) {
    wp_clear_scheduled_hook('hgm_daily_license_check');
}

// Clear license status cache transient (safe to remove).
if ( function_exists('delete_transient') ) {
    delete_transient('hgm_license_status_cache');
}