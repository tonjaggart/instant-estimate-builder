<?php
if (!defined('ABSPATH')) exit;

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'toplevel_page_hgm_quote_generator') {
        wp_enqueue_style(
            'hgm-dashboard-css',
            plugin_dir_url(dirname(__FILE__)) . 'assets/dashboard.css',
            [],
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/dashboard.css')
        );
    }
});


function hgm_render_main_menu_page() {
    echo '<div class="wrap">';
    echo '<h1>HVAC Instant Quote Generator Dashboard</h1>';
    echo '</div>';
}

function hgm_render_dashboard_page() {
    echo '<div class="wrap hgm-dashboard">';

    echo '<h1 class="hgm-dashboard-title">Welcome to the HVAC Instant Quote Generator</h1>';

    echo '<div class="hgm-dashboard-grid">';

    // Column 1: Quick Stats
    echo '<div class="hgm-dashboard-col">';
    echo '<div class="hgm-dashboard-card">';
    echo '<h2 class="hgm-section-title">Quick Stats</h2>';
    $lead_counts = hgm_get_lead_counts();

    echo '<div class="hgm-stat-box">Leads Today: <strong>' . esc_html($lead_counts['today']) . '</strong></div>';
    echo '<div class="hgm-stat-box">Leads Last 7 Days: <strong>' . esc_html($lead_counts['week']) . '</strong></div>';
    echo '<div class="hgm-stat-box">Leads Last 30 Days: <strong>' . esc_html($lead_counts['month']) . '</strong></div>';
    echo '<a href="' . admin_url('edit.php?post_type=hgm_lead') . '" class="button button-secondary hgm-view-leads-btn">View All Leads</a>';
    echo '</div>';
    echo '</div>';

    // Column 2: Quick Links
    echo '<div class="hgm-dashboard-col">';
    echo '<div class="hgm-dashboard-card">';
    echo '<h2 class="hgm-section-title">Quick Links</h2>';
    echo '<ul class="hgm-quick-links">';
    echo '<li><a href="' . admin_url('edit.php?post_type=instant_quote_form') . '">Manage Quote Forms</a></li>';
    echo '<li><a href="' . admin_url('post-new.php?post_type=instant_quote_form') . '">Add New Quote Form</a></li>';
    echo '<li><a href="' . admin_url('admin.php?page=hgm-email-settings') . '">Email Settings</a></li>';
    echo '<li><a href="' . admin_url('admin.php?page=hgm-notifications-settings') . '">Notifications</a></li>';
     echo '<li><a href="' . admin_url('admin.php?page=hgm-license-settings') . '">License</a></li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';

    // Column 3: Support
    echo '<div class="hgm-dashboard-col">';
    echo '<div class="hgm-dashboard-card">';
    echo '<h2 class="hgm-section-title">Support</h2>';
    echo '<p><a href="mailto:support@hvacgrowthmachine.com">Contact Support</a></p>';
    echo '<div class="hgm-upsell-box">';
    echo '<h3>Need Help Setting Up?</h3>';
    echo '<p>Let our team build your quote form for you.</p>';
    echo '<a href="https://hvacgrowthmachine.com/checkout/?add-to-cart=280" target="_blank" class="button button-primary">Get Done-For-You Setup</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // end hgm-dashboard-grid
    echo '</div>'; // end wrap
}

function hgm_get_lead_counts() {
    $counts = [
        'today'  => 0,
        'week'   => 0,  // Last 7 days
        'month'  => 0,  // Last 30 days
    ];

    // WP local time boundaries
    $today_start  = strtotime('today midnight', current_time('timestamp'));
    $week_start   = strtotime('-7 days', current_time('timestamp'));
    $month_start  = strtotime('-30 days', current_time('timestamp'));

    // Query leads from the last 30 days (covers all counts)
    $args = [
        'post_type'      => 'hgm_lead',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'date_query'     => [
            [
                'after'     => date('Y-m-d 00:00:00', $month_start),
                'inclusive' => true,
                'column'    => 'post_date',
            ],
        ],
    ];

    $leads = get_posts($args);

    foreach ($leads as $lead_id) {
        $timestamp = get_post_time('U', false, $lead_id); // Local time

        if ($timestamp >= $today_start) {
            $counts['today']++;
        }

        if ($timestamp >= $week_start) {
            $counts['week']++;
        }

        if ($timestamp >= $month_start) {
            $counts['month']++;
        }
    }

    return $counts;
}