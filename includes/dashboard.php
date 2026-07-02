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
    echo '<h1>Instant Estimate Builder</h1>';
    echo '</div>';
}

function hgm_render_dashboard_page() {
    $lead_counts = hgm_get_lead_counts();

    echo '<div class="wrap hgm-dashboard">';

    echo '<section class="hgm-dashboard-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Taggart Media Group</div>';
    echo '<h1>Instant Estimate Builder</h1>';
    echo '<p class="hgm-dashboard-subtitle">Create multi-step instant estimate forms for local service businesses, capture better leads, and follow up faster.</p>';
    echo '<div class="hgm-dashboard-actions">';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=instant_quote_form')) . '" class="button button-primary hgm-button-primary">Create Estimate Form</a>';
    echo '<a href="' . esc_url(admin_url('edit.php?post_type=hgm_lead')) . '" class="button hgm-button-secondary">View Leads</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="hgm-dashboard-grid">';

    echo '<div class="hgm-dashboard-card hgm-dashboard-card-stats">';
    echo '<div class="hgm-card-label">Lead Snapshot</div>';
    echo '<h2>Recent performance</h2>';
    echo '<div class="hgm-stat-grid">';
    echo '<div class="hgm-stat-box"><span>Today</span><strong>' . esc_html($lead_counts['today']) . '</strong></div>';
    echo '<div class="hgm-stat-box"><span>Last 7 days</span><strong>' . esc_html($lead_counts['week']) . '</strong></div>';
    echo '<div class="hgm-stat-box"><span>Last 30 days</span><strong>' . esc_html($lead_counts['month']) . '</strong></div>';
    echo '</div>';
    echo '<a href="' . esc_url(admin_url('edit.php?post_type=hgm_lead')) . '" class="hgm-text-link">Open lead inbox →</a>';
    echo '</div>';

    echo '<div class="hgm-dashboard-card">';
    echo '<div class="hgm-card-label">Builder</div>';
    echo '<h2>Manage estimate forms</h2>';
    echo '<p>Build forms for windows, roofing, HVAC, plumbing, landscaping, pest control, and other local-service offers.</p>';
    echo '<div class="hgm-link-list">';
    echo '<a href="' . esc_url(admin_url('edit.php?post_type=instant_quote_form')) . '">Manage estimate forms</a>';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=instant_quote_form')) . '">Add new estimate form</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=hgm-notifications-settings')) . '">Notification settings</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=hgm-email-settings')) . '">Email settings</a>';
    echo '</div>';
    echo '</div>';

    echo '<div class="hgm-dashboard-card hgm-dashboard-card-cta">';
    echo '<div class="hgm-card-label">Done-for-you setup</div>';
    echo '<h2>Want us to set this up?</h2>';
    echo '<p>Taggart Media Group can configure your services, estimate ranges, notifications, styling, and test the full form.</p>';
    echo '<div class="hgm-price-pill">One-time setup: $299</div>';
    echo '<a href="https://taggartmediagroup.com/" target="_blank" rel="noopener" class="button button-primary hgm-button-primary">Get Setup Help</a>';
    echo '<p class="hgm-dashboard-note">Plugin stays free. Setup help is optional.</p>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

function hgm_get_lead_counts() {
    $counts = [
        'today'  => 0,
        'week'   => 0,
        'month'  => 0,
    ];

    $today_start  = strtotime('today midnight', current_time('timestamp'));
    $week_start   = strtotime('-7 days', current_time('timestamp'));
    $month_start  = strtotime('-30 days', current_time('timestamp'));

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
        $timestamp = get_post_time('U', false, $lead_id);

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
