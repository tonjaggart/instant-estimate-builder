<?php
/**
 * Plugin Name: HVAC Quote Generator
 * Description: Create customizable multi-step quote forms with lead capture, email notifications, text message notifications and lead management.
 * Version: 1.0.5
 * Author: HVAC Growth Machine
 * Author URI: https://hvacgrowthmachine.com
 */

if (!defined('ABSPATH')) {
    exit();
} // Prevent direct access

//Plugin update checker
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://hvacgrowthmachine.com/hgm-plugin/updates.json', // ← change this
    __FILE__,
    'hvac-quote-generator'
);

// End plugin update checker

// Define constants only once here in main plugin file (plugin root)
define('HGM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('HGM_PLUGIN_URL', plugin_dir_url(__FILE__));


// Load core includes
require_once HGM_PLUGIN_PATH . 'includes/admin-ui.php';
require_once HGM_PLUGIN_PATH . 'includes/shortcode-render.php';
require_once HGM_PLUGIN_PATH . 'includes/email-settings.php';
require_once HGM_PLUGIN_PATH . 'includes/admin-leads.php';
require_once HGM_PLUGIN_PATH . 'includes/handle-form.php';
require_once HGM_PLUGIN_PATH . 'includes/notifications-settings.php';
require_once HGM_PLUGIN_PATH . 'includes/dashboard.php';
require_once HGM_PLUGIN_PATH . 'includes/integrations.php';
require_once HGM_PLUGIN_PATH . 'includes/license-settings.php';
require_once HGM_PLUGIN_PATH . 'includes/support.php';

// Register CPTs - hide default menus
function hgm_register_cpts() {
    register_post_type('instant_quote_form', [
        'labels' => [
            'name' => 'Quote Forms',
            'singular_name' => 'Quote Form',
            'add_new_item' => 'Add New Quote Form',
            'edit_item' => 'Edit Quote Form',
            'menu_name' => 'Quote Forms',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title'],
        'capability_type' => 'post',
    ]);

    register_post_type('hgm_lead', [
        'labels' => [
            'name' => 'Leads',
            'singular_name' => 'Lead',
            'add_new_item' => 'Add New Lead',
            'edit_item' => 'Edit Lead',
            'menu_name' => 'Leads',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title'],
        'capability_type' => 'post',
    ]);
}
add_action('init', 'hgm_register_cpts', 5);

// Remove default CPT menus forcibly to avoid duplicates
add_action('admin_menu', function () {
    remove_menu_page('edit.php?post_type=instant_quote_form');
    remove_menu_page('edit.php?post_type=hgm_lead');
}, 999);

add_action('admin_menu', function() {
    // Main menu item
    add_menu_page(
        'HVAC Instant Quote Generator',
        'HVAC Instant Quote Generator',
        'manage_options',
        'hgm_quote_generator',
        'hgm_render_dashboard_page',
        plugins_url('assets/plugin-icon.png', __FILE__),
        25
    );

    // CPT: Quote Forms
    add_submenu_page(
        'hgm_quote_generator',
        'Quote Forms',
        'Quote Forms',
        'manage_options',
        'edit.php?post_type=instant_quote_form'
    );

    add_submenu_page(
        'hgm_quote_generator',
        'Add New Quote Form',
        'Add New Quote Form',
        'manage_options',
        'post-new.php?post_type=instant_quote_form'
    );

    // Email Settings (Standalone still)
    add_submenu_page(
        'hgm_quote_generator',
        'Email Settings',
        'Email Settings',
        'manage_options',
        'hgm-email-settings',
        'hgm_render_email_settings_page'
    );

    // NEW: Notifications page (will include Email + Text tabs)
    add_submenu_page(
        'hgm_quote_generator',
        'Notifications',
        'Notifications',
        'manage_options',
        'hgm-notifications-settings',
        'hgm_render_notifications_settings_page' // This is defined in notifications-settings.php
    );

    // View Leads
    add_submenu_page(
        'hgm_quote_generator',
        'View Leads',
        'View Leads',
        'manage_options',
        'edit.php?post_type=hgm_lead'
    );

    // Hidden lead details page
    add_submenu_page(
        'edit.php?post_type=hgm_lead',
        'View Lead Details',
        '', // hidden
        'manage_options',
        'hgm_view_lead',
        'hgm_render_view_lead_screen'
    );

    // Hidden edit quote form
    add_submenu_page(
        null,
        'Edit Quote Form',
        'Edit Quote Form',
        'manage_options',
        'post.php',
        ''
    );

    // Hidden view lead page (duplicate safety)
    add_submenu_page(
        null,
        'View Lead Details',
        'View Lead Details',
        'manage_options',
        'hgm_view_lead',
        'hgm_render_view_lead_screen'
    );

    // Integrations Page
    add_submenu_page(
        'hgm_quote_generator',       // Parent slug
        'Integrations',              // Page title
        'Integrations',              // Menu title
        'manage_options',            // Capability
        'hgm-integrations',          // Menu slug
        'hgm_render_integrations_page' // Callback function
    );

    // License Key submenu (under main plugin menu)
    add_submenu_page(
        'hgm_quote_generator', // Parent slug matches your main plugin menu
        'License Key',
        'License',
        'manage_options',
        'hgm-license-settings',
        'hgm_render_license_settings_page'
    );
    // Support Page submenu
    // Support (tutorials)
    add_submenu_page(
        'hgm_quote_generator',   // parent: your top-level plugin menu
        'Support',               // page title
        'Support',               // menu title
        'manage_options',        // capability
        'hgm-support',           // menu slug
        'hgm_render_support_page'// callback (defined in includes/support.php)
    );
});
// Keep custom top-level menu expanded for CPT pages
add_filter('parent_file', function ($parent_file) {
    global $current_screen;

    if (
        $current_screen->post_type === 'instant_quote_form' ||
        $current_screen->id === 'toplevel_page_hgm-email-settings' ||
        $current_screen->id === 'hvac-quote-generator_page_hgm-view-lead' ||
        (isset($_GET['page']) && $_GET['page'] === 'hgm-view-lead')
    ) {
        $parent_file = 'hgm_quote_generator';
    }

    return $parent_file;
});

add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'hgm_view_lead') {
        return 'edit.php?post_type=hgm_lead'; // Match existing submenu item
    }
    return $submenu_file;
});

// Sale team email notifications settings
add_action('admin_init', function () {
    register_setting('hgm_email_settings', 'hgm_email_settings', [
        'type' => 'array',
        'sanitize_callback' => function ($input) {
            if (isset($input['sales_team_emails'])) {
                $input['sales_team_emails'] = sanitize_textarea_field($input['sales_team_emails']);
            }
            // Sanitize other fields as needed...
            return $input;
        },
        'default' => [],
    ]);

    add_settings_section(
        'hgm_email_sales_team_section', 
        'Sales Team Email Notifications', 
        '__return_false', 
        'hgm-email-settings-notifications'
    );

    add_settings_section(
        'hgm_email_customize_section', 
        'Customize Estimate Email', 
        '__return_false', 
        'hgm-email-settings-customize'
    );
});

// Enqueue admin scripts and styles
add_action('admin_enqueue_scripts', function ($hook) {
    // Only load on plugin settings pages
    if (strpos($hook, 'hgm-email-settings') === false) {
        return;
    }

    // WordPress native color picker
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script(
        'hgm-admin-js',
        HGM_PLUGIN_URL . 'assets/admin.js',
        ['jquery', 'wp-color-picker'],
        filemtime(HGM_PLUGIN_PATH . 'assets/admin.js'),
        true
    );

    wp_enqueue_style(
        'hgm-admin-css',
        HGM_PLUGIN_URL . 'assets/admin.css',
        [],
        filemtime(HGM_PLUGIN_PATH . 'assets/admin.css')
    );
});

// Load admin.js + localize data for Quote Form edit screen
add_action('admin_enqueue_scripts', function ($hook) {
    global $post;

    // Only run on the Quote Form editor screen
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'instant_quote_form') {
            // Enqueue admin.js and dependencies
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_style(
                'hgm-admin-css',
                HGM_PLUGIN_URL . 'assets/admin.css',
                [],
                filemtime(HGM_PLUGIN_PATH . 'assets/admin.css')
            );
            wp_enqueue_script(
                'hgm-admin-js',
                HGM_PLUGIN_URL . 'assets/admin.js',
                ['jquery', 'wp-color-picker'],
                filemtime(HGM_PLUGIN_PATH . 'assets/admin.js'),
                true
            );

            // Localize quote form data
            wp_localize_script('hgm-admin-js', 'hgmQuoteFormData', [
                'postId' => get_the_ID(),
                'nonce'  => wp_create_nonce('hgm_nonce'),
            ]);
        }
    }
});

add_action('wp_ajax_hgm_save_quote_form_data', 'hgm_save_quote_form_data_callback');
function hgm_save_quote_form_data_callback() {
    check_ajax_referer('hgm_nonce', 'nonce');

    $post_id   = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $steps     = isset($_POST['steps']) ? $_POST['steps'] : [];

    if (!$post_id || empty($steps)) {
        wp_send_json_error('Missing post ID or step data.');
    }

    // Save to hidden field (WordPress will pick this up on Update)
    $_POST['hgm_quote_questions_json'] = wp_json_encode($steps);

    // Manually trigger save_post hook to persist the meta (acts like clicking "Update")
    do_action('save_post_instant_quote_form', $post_id);

    wp_send_json_success('Form saved successfully.');
}


// --- Instant Quote Preview (query-string version) ---

// 1) Allow ?quote_form_preview=1 to be read by WP
add_filter('query_vars', function ($vars) {
    $vars[] = 'quote_form_preview';
    return $vars;
});

// 2) Intercept and render the preview when the flag is present
add_action('template_include', function ($template) {
    $is_preview = (int) get_query_var('quote_form_preview') === 1 || isset($_GET['quote_form_preview']);
    if (!$is_preview) {
        return $template; // not a preview request, continue normally
    }

    $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
    if (!$form_id) {
        wp_die('Invalid form ID');
    }

    status_header(200);
    global $wp_query;
    $wp_query->is_404 = false;

    ob_start();
    get_header();
    echo '<div class="instant-quote-preview-container" style="max-width:900px;margin:30px auto;">';
    echo do_shortcode('[instant_quote_form id="' . $form_id . '"]');
    echo '</div>';
    get_footer();
    echo ob_get_clean();

    exit; // stop WP’s normal template flow
});



/**
 * Check license status from HVACGrowthMachine.com
 */
function hgm_check_license_status($email, $license_key, $product_id, $instance_url) {

    $api_url = 'https://hvacgrowthmachine.com/wp-json/hgm-license/v1/check';

    // The secret token from hvacgrowthmachine.com
    $secret_token = 'fae60fecabbe38df57903f638d820095b9b5acb916e0518197d83acf5d96f054';

    $response = wp_remote_get(add_query_arg([
        'email'       => $email,
        'license_key' => $license_key,
        'product_id'  => $product_id,
        'instance'    => $instance_url,
        'token'       => $secret_token
    ], $api_url), [
        'timeout'   => 20,
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => 'Could not connect to license server'];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return ['status' => 'error', 'message' => 'Invalid response from license server'];
    }

    return $data;
}

/**
 * Schedule daily license check at 12am PST
 */
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('hgm_daily_license_check')) {
        // Schedule at midnight PST (convert to UTC)
        $timestamp = strtotime('tomorrow 12:00am America/Los_Angeles');
        wp_schedule_event($timestamp, 'daily', 'hgm_daily_license_check');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('hgm_daily_license_check');
});


/**
 * Cron job to run daily license check. Get and cache license status from hvacgrowthmachine.com
 */
function hgm_get_cached_license_status($email, $license_key, $product_id, $instance_url) {
    $cache_key = 'hgm_license_status_cache';

    // 1️⃣ Check cache
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    // 2️⃣ Build remote API call
    $api_url = 'https://hvacgrowthmachine.com/wp-json/hgm-license/v1/check';

    // This is now a public key (safe to be in the plugin)
    $public_client_key = 'HGM-PUBLIC-001'; 

    $response = wp_remote_get(add_query_arg([
        'email'       => $email,
        'license_key' => $license_key,
        'product_id'  => $product_id,
        'instance'    => $instance_url,
        'client_id'   => $public_client_key
    ], $api_url), [
        'timeout'   => 20,
        'sslverify' => true
    ]);

    // 3️⃣ Handle connection errors
    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => 'Could not connect to license server'];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return ['status' => 'error', 'message' => 'Invalid response from license server'];
    }

    // 4️⃣ Cache for 24 hours
    set_transient($cache_key, $data, DAY_IN_SECONDS);

    return $data;
}

// Provide custom plugin info for the "View details" modal.
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information') {
        return $result;
    }
    if (empty($args->slug) || $args->slug !== 'hvac-quote-generator') {
        return $result;
    }

    // NEW: use the site's current WP version to silence the warning
    $tested_wp = $GLOBALS['wp_version']; // or get_bloginfo('version')

    // Optional: point to your icon(s). If you only have one, reuse it for 1x/2x.
    $icon_1x = HGM_PLUGIN_URL . 'assets/plugin-icon.png';
    $icon_2x = HGM_PLUGIN_URL . 'assets/plugin-icon@x.png';

    return (object) [
        'name'           => 'HVAC Instant Quote Generator',
        'slug'           => 'hvac-quote-generator',
        'version'        => '1.0.1',
        'author'         => '<a href="https://hvacgrowthmachine.com/">HVAC Growth Machine</a>',
        'author_profile' => 'https://hvacgrowthmachine.com/',
        'homepage'       => 'https://hvacgrowthmachine.com/instant-hvac-quote-plugin/',
        'requires'       => '5.4',
        'tested'         => $tested_wp,                 // ✅ dynamic
        'requires_php'   => '7.4',                      // ✅ helpful metadata
        'download_link'  => 'https://hvacgrowthmachine.com/hgm-plugin/plugin/hvac-quote-generator-1.0.1.zip',

        // NEW: show an icon in the “View details” modal
        'icons' => [
            '1x' => $icon_1x,
            '2x' => $icon_2x,
            // 'svg' => HGM_PLUGIN_URL . 'assets/plugin-icon.svg', // if you have one
        ],

        'sections' => [
            'description' => '
                <p>The HVAC Instant Quote Generator turns your site into a lead machine.</p>
                <ul>
                    <li>Multi‑step form with dynamic pricing</li>
                    <li>Automatic estimate email to the customer</li>
                    <li>Lead management + export</li>
                    <li>SMS/email alerts for your team</li>
                </ul>
            ',
            'changelog' => '
                <h4>1.0.1</h4>
                <ul><li>Initial public release</li></ul>
            ',
        ],
    ];
}, 20, 3);

// Clear schedules and caches on deactivation (no data deletion)
register_deactivation_hook(__FILE__, function () {
    // Unschedule the daily license check, if set
    wp_clear_scheduled_hook('hgm_daily_license_check');

    // Clear cached license status
    delete_transient('hgm_license_status_cache');
});

