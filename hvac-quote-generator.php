<?php
/**
 * Plugin Name: Instant Estimate Builder
 * Description: Build customizable multi-step instant estimate forms for local service businesses with lead capture, notifications, and lead management.
 * Version: 1.0.6
 * Author: Taggart Media Group
 * Author URI: https://taggartmediagroup.com
 * Text Domain: instant-estimate-builder
 */

if (!defined('ABSPATH')) {
    exit();
} // Prevent direct access

//Plugin update checker
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/tonjaggart/instant-estimate-builder', // GitHub repo for release metadata; legacy plugin slug is kept below.
    __FILE__,
    'hvac-quote-generator' // Legacy slug kept so existing installs can update cleanly.
);

// End plugin update checker

// Define constants only once here in main plugin file (plugin root)
define('HGM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('HGM_PLUGIN_URL', plugin_dir_url(__FILE__));

// New generic aliases. Legacy HGM constants stay in place for backward compatibility.
define('IEB_PLUGIN_PATH', HGM_PLUGIN_PATH);
define('IEB_PLUGIN_URL', HGM_PLUGIN_URL);
define('IEB_ADMIN_MENU_SLUG', 'instant-estimate-builder');
define('HGM_LEGACY_ADMIN_MENU_SLUG', 'hgm_quote_generator');


// Load core includes
require_once HGM_PLUGIN_PATH . 'includes/admin-ui.php';
require_once HGM_PLUGIN_PATH . 'includes/shortcode-render.php';
require_once HGM_PLUGIN_PATH . 'includes/email-settings.php';
require_once HGM_PLUGIN_PATH . 'includes/admin-leads.php';
require_once HGM_PLUGIN_PATH . 'includes/handle-form.php';
require_once HGM_PLUGIN_PATH . 'includes/notifications-settings.php';
require_once HGM_PLUGIN_PATH . 'includes/dashboard.php';
require_once HGM_PLUGIN_PATH . 'includes/estimate-forms-list.php';
require_once HGM_PLUGIN_PATH . 'includes/integrations.php';
require_once HGM_PLUGIN_PATH . 'includes/license-settings.php';
require_once HGM_PLUGIN_PATH . 'includes/support.php';

add_action('admin_init', function () {
    if (!is_admin() || wp_doing_ajax()) {
        return;
    }

    if (isset($_GET['page']) && $_GET['page'] === HGM_LEGACY_ADMIN_MENU_SLUG) {
        wp_safe_redirect(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG));
        exit;
    }

    if (isset($_GET['page']) && $_GET['page'] === 'instant-estimate-form') {
        wp_safe_redirect(admin_url('post-new.php?post_type=instant_quote_form'));
        exit;
    }

    if (isset($_GET['page']) && $_GET['page'] === 'hgm-email-settings') {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'customize';
        wp_safe_redirect(admin_url('admin.php?page=instant-estimate-email-settings&tab=' . $tab));
        exit;
    }

    if (isset($_GET['page']) && $_GET['page'] === 'hgm-notifications-settings') {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'email';
        wp_safe_redirect(admin_url('admin.php?page=instant-estimate-notifications&tab=' . $tab));
        exit;
    }

    if (isset($_GET['post_type']) && $_GET['post_type'] === 'instant_quote_form' && basename($_SERVER['PHP_SELF']) === 'edit.php') {
        wp_safe_redirect(admin_url('admin.php?page=instant-estimate-forms'));
        exit;
    }
});

// Register CPTs - hide default menus
function hgm_register_cpts() {
    register_post_type('instant_quote_form', [
        'labels' => [
            'name' => 'Estimate Forms',
            'singular_name' => 'Estimate Form',
            'add_new_item' => 'Add New Estimate Form',
            'edit_item' => 'Edit Estimate Form',
            'menu_name' => 'Estimate Forms',
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
        'Instant Estimate Builder',
        'Instant Estimate Builder',
        'edit_posts',
        IEB_ADMIN_MENU_SLUG,
        'hgm_render_dashboard_page',
        'dashicons-clipboard',
        25
    );

    // Dashboard submenu label for the top-level plugin page.
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,
        'Dashboard',
        'Dashboard',
        'edit_posts',
        IEB_ADMIN_MENU_SLUG,
        'hgm_render_dashboard_page'
    );

    // Custom Estimate Forms library page keeps the visible URL aligned with the new product name.
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,
        'Estimate Forms',
        'Estimate Forms',
        'edit_posts',
        'instant-estimate-forms',
        'ieb_render_estimate_forms_page'
    );

    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,
        'Add New Estimate Form',
        'Add New Estimate Form',
        'edit_posts',
        'instant-estimate-form',
        '__return_null'
    );

    // Email Settings (Standalone still)
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,
        'Email Settings',
        'Email Settings',
        'manage_options',
        'instant-estimate-email-settings',
        'hgm_render_email_settings_page'
    );

    // NEW: Notifications page (will include Email + Text tabs)
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,
        'Notifications',
        'Notifications',
        'manage_options',
        'instant-estimate-notifications',
        'hgm_render_notifications_settings_page' // This is defined in notifications-settings.php
    );

    // View Leads
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,
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

    // Hidden edit estimate form
    add_submenu_page(
        null,
        'Edit Estimate Form',
        'Edit Estimate Form',
        'edit_posts',
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
        IEB_ADMIN_MENU_SLUG,       // Parent slug
        'Integrations',              // Page title
        'Integrations',              // Menu title
        'manage_options',            // Capability
        'hgm-integrations',          // Menu slug
        'hgm_render_integrations_page' // Callback function
    );

    // License Key submenu (under main plugin menu)
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG, // Parent slug matches your main plugin menu
        'License Key',
        'License',
        'manage_options',
        'hgm-license-settings',
        'hgm_render_license_settings_page'
    );
    // Support Page submenu
    // Support (tutorials)
    add_submenu_page(
        IEB_ADMIN_MENU_SLUG,   // parent: your top-level plugin menu
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
        $current_screen->id === IEB_ADMIN_MENU_SLUG . '_page_instant-estimate-forms' ||
        $current_screen->id === IEB_ADMIN_MENU_SLUG . '_page_instant-estimate-email-settings' ||
        $current_screen->id === IEB_ADMIN_MENU_SLUG . '_page_instant-estimate-notifications' ||
        $current_screen->id === IEB_ADMIN_MENU_SLUG . '_page_hgm-view-lead' ||
        (isset($_GET['page']) && $_GET['page'] === 'hgm-view-lead')
    ) {
        $parent_file = IEB_ADMIN_MENU_SLUG;
    }

    return $parent_file;
});

add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'instant-estimate-forms') {
        return 'instant-estimate-forms';
    }

    if (isset($_GET['page']) && $_GET['page'] === 'instant-estimate-email-settings') {
        return 'instant-estimate-email-settings';
    }

    if (isset($_GET['page']) && $_GET['page'] === 'instant-estimate-notifications') {
        return 'instant-estimate-notifications';
    }

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
            $existing = get_option('hgm_email_settings', []);
            if (isset($input['sales_team_emails'])) {
                $input['sales_team_emails'] = sanitize_textarea_field($input['sales_team_emails']);
            }
            // Preserve the estimate email template settings when this notifications form saves only the sales-team field.
            return array_merge(is_array($existing) ? $existing : [], is_array($input) ? $input : []);
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
    if (strpos($hook, 'hgm-email-settings') === false && strpos($hook, 'instant-estimate-email-settings') === false) {
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

// Load admin.js + localize data for Estimate Form edit screen
add_action('admin_enqueue_scripts', function ($hook) {
    global $post;

    // Only run on the Estimate Form editor screen
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'instant_quote_form') {
            // Enqueue admin.js and dependencies
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_style(
                'hgm-dashboard-css',
                HGM_PLUGIN_URL . 'assets/dashboard.css',
                [],
                filemtime(HGM_PLUGIN_PATH . 'assets/dashboard.css')
            );
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

            // Localize estimate form data
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


// --- Instant Estimate Preview (query-string version) ---

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
    echo do_shortcode('[instant_estimate_form id="' . $form_id . '"]');
    echo '</div>';
    get_footer();
    echo ob_get_clean();

    exit; // stop WP’s normal template flow
});



/**
 * Legacy license compatibility.
 *
 * Instant Estimate Builder is now free, so these helpers return an active/free
 * status without calling the old remote license server. Function names and
 * option keys stay in place so existing installs do not fatal or lose settings.
 */
function hgm_check_license_status($email = '', $license_key = '', $product_id = '', $instance_url = '') {
    return [
        'status'  => 'active',
        'message' => 'Instant Estimate Builder is free to use. No license key is required.',
    ];
}

register_activation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('hgm_daily_license_check');
    update_option('hgm_license_status', 'active');
    update_option('hgm_license_status_cache', 'active');
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('hgm_daily_license_check');
});

function hgm_get_cached_license_status($email = '', $license_key = '', $product_id = '', $instance_url = '') {
    return [
        'status'  => 'active',
        'message' => 'Instant Estimate Builder is free to use. No license key is required.',
    ];
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
        'name'           => 'Instant Estimate Builder',
        'slug'           => 'hvac-quote-generator', // Legacy slug kept so existing installs can still see update details.
        'version'        => '1.0.6',
        'author'         => '<a href="https://taggartmediagroup.com/">Taggart Media Group</a>',
        'author_profile' => 'https://taggartmediagroup.com/',
        'homepage'       => 'https://taggartmediagroup.com/',
        'requires'       => '5.4',
        'tested'         => $tested_wp,                 // ✅ dynamic
        'requires_php'   => '7.4',                      // ✅ helpful metadata
        'download_link'  => 'https://github.com/tonjaggart/instant-estimate-builder/releases/latest',

        // NEW: show an icon in the “View details” modal
        'icons' => [
            '1x' => $icon_1x,
            '2x' => $icon_2x,
            // 'svg' => HGM_PLUGIN_URL . 'assets/plugin-icon.svg', // if you have one
        ],

        'sections' => [
            'description' => '
                <p>Instant Estimate Builder helps local service businesses turn website visitors into estimate-ready leads.</p>
                <ul>
                    <li>Multi‑step form with dynamic pricing</li>
                    <li>Automatic estimate email to the customer</li>
                    <li>Lead management + export</li>
                    <li>SMS/email alerts for your team</li>
                </ul>
            ',
            'changelog' => '
                <h4>1.0.6</h4>
                <ul><li>Rebranded user-facing plugin experience for broader local-service use.</li></ul>
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

