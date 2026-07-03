<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit();

// Add custom columns to hgm_lead CPT list
add_filter('manage_edit-hgm_lead_columns', function($columns) {
    return [
        'cb'        => '<input type="checkbox" />',
        'title'     => 'Name',
        'email'     => 'Email',
        'phone'     => 'Phone',
        'zip_code'  => 'Zip Code',
        'estimate'  => 'Estimate',
        'view'      => 'View',
        'date'      => 'Date'
    ];
});

// Populate custom column data
add_action('manage_hgm_lead_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'email':
            echo esc_html(get_post_meta($post_id, 'email', true));
            break;
        case 'phone':
            echo esc_html(get_post_meta($post_id, 'phone', true));
            break;
        case 'zip_code':
            echo esc_html(get_post_meta($post_id, 'zip_code', true));
            break;
        case 'estimate':
            $low  = get_post_meta($post_id, '_hgm_estimate_low', true);
            $high = get_post_meta($post_id, '_hgm_estimate_high', true);
            echo ($low && $high) ? esc_html("$low – $high") : '—';
            break;
        case 'view':
            $url = admin_url('admin.php?page=instant-estimate-lead&id=' . $post_id);
            echo '<a class="button button-primary" href="' . esc_url($url) . '">🔎 View Lead Details</a>';
            break;
    }
}, 10, 2);


// Add Export CSV button above leads list
add_action('manage_posts_extra_tablenav', function($which) {
    global $typenow;

    if ($typenow === 'hgm_lead' && $which === 'top') {
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=hgm_export_leads_csv'), 'hgm_export_leads_csv');
        echo '<div class="alignleft actions">';
        echo '<a href="' . esc_url($export_url) . '" class="button button-primary">Export Leads CSV</a>';
        echo '</div>';
    }
});

add_action('admin_post_hgm_export_leads_csv', 'hgm_export_leads_csv_callback');

function hgm_sanitize_csv_cell($value) {
    $value = (string) $value;
    return preg_match('/^[=+\-@]/', ltrim($value)) ? "'" . $value : $value;
}

function hgm_export_leads_csv_callback() {
    if (!current_user_can(defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('hgm_export_leads_csv');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="instant-estimate-leads.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Output CSV headers
    fputcsv($output, ['Lead ID', 'Name', 'Email', 'Phone', 'Zip Code', 'Estimate Low', 'Estimate High', 'Date']);

    $args = [
        'post_type'      => 'hgm_lead',
        'post_status'    => 'publish',
        'posts_per_page' => -1
    ];
    $leads = get_posts($args);

    foreach ($leads as $lead) {
        $id     = $lead->ID;
        $name   = get_the_title($id);
        $email  = get_post_meta($id, 'email', true);
        $phone  = get_post_meta($id, 'phone', true);
        $zip    = get_post_meta($id, 'zip_code', true);
        $low    = get_post_meta($id, '_hgm_estimate_low', true);
        $high   = get_post_meta($id, '_hgm_estimate_high', true);
        $date   = get_the_date('Y-m-d H:i:s', $id);

        fputcsv($output, array_map('hgm_sanitize_csv_cell', [$id, $name, $email, $phone, $zip, $low, $high, $date]));
    }

    fclose($output);
    exit;
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'instant-estimate-leads') !== false || strpos($hook, 'instant-estimate-lead') !== false) {
        wp_enqueue_style('hgm-dashboard-css', plugin_dir_url(__FILE__) . '../assets/dashboard.css', [], filemtime(plugin_dir_path(__FILE__) . '../assets/dashboard.css'));
    }
});

function ieb_get_lead_search_ids($search) {
    global $wpdb;

    $search = trim((string) $search);
    if ($search === '') {
        return [];
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    $digits = preg_replace('/\D+/', '', $search);
    $phone_digits_sql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(pm.meta_value, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', '')";

    $where_parts = [
        $wpdb->prepare('p.post_title LIKE %s', $like),
        $wpdb->prepare("(pm.meta_key IN ('first_name', 'email', 'zip_code') AND pm.meta_value LIKE %s)", $like),
        $wpdb->prepare("(pm.meta_key = 'phone' AND pm.meta_value LIKE %s)", $like),
    ];

    if ($digits !== '') {
        $digits_like = '%' . $wpdb->esc_like($digits) . '%';
        $where_parts[] = $wpdb->prepare("(pm.meta_key = 'phone' AND {$phone_digits_sql} LIKE %s)", $digits_like);
        $where_parts[] = $wpdb->prepare("(pm.meta_key = 'zip_code' AND pm.meta_value LIKE %s)", $digits_like);
    }

    $where_sql = implode(' OR ', $where_parts);

    $ids = $wpdb->get_col(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
            ON p.ID = pm.post_id
            AND pm.meta_key IN ('first_name', 'email', 'phone', 'zip_code')
         WHERE p.post_type = 'hgm_lead'
            AND p.post_status = 'publish'
            AND ({$where_sql})"
    );

    return array_map('intval', $ids ?: []);
}

function ieb_render_leads_page() {
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $per_page = 20;

    $query_args = [
        'post_type'      => 'hgm_lead',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ($search !== '') {
        $matching_ids = ieb_get_lead_search_ids($search);
        $query_args['post__in'] = !empty($matching_ids) ? $matching_ids : [0];
    }

    $leads_query = new WP_Query($query_args);
    $published_count = (int) wp_count_posts('hgm_lead')->publish;
    $seven_days_ago = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
    $recent_count = (new WP_Query([
        'post_type'      => 'hgm_lead',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'date_query'     => [
            [
                'after'     => $seven_days_ago,
                'inclusive' => true,
            ],
        ],
    ]))->found_posts;

    echo '<div class="wrap hgm-dashboard ieb-leads-page">';

    echo '<section class="hgm-dashboard-hero ieb-leads-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Lead Inbox</div>';
    echo '<h1>Instant Estimate Leads</h1>';
    echo '<p class="hgm-dashboard-subtitle">Review new instant estimate submissions, contact details, ZIP codes, and estimate ranges in one place.</p>';
    echo '<div class="hgm-dashboard-actions">';
    $export_url = wp_nonce_url(admin_url('admin-post.php?action=hgm_export_leads_csv'), 'hgm_export_leads_csv');
    echo '<a href="' . esc_url($export_url) . '" class="button button-primary hgm-button-primary">Export Leads CSV</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG)) . '" class="button hgm-button-secondary">Back To Dashboard</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="ieb-forms-summary-grid ieb-leads-summary-grid">';
    echo '<div class="hgm-dashboard-card ieb-forms-summary-card"><span>Total Leads</span><strong>' . esc_html($published_count) . '</strong></div>';
    echo '<div class="hgm-dashboard-card ieb-forms-summary-card"><span>Last 7 Days</span><strong>' . esc_html($recent_count) . '</strong></div>';
    echo '</div>';

    echo '<div class="hgm-dashboard-card ieb-leads-table-card">';
    echo '<div class="hgm-card-label">Lead Inbox</div>';
    echo '<div class="ieb-leads-card-header">';
    echo '<div>';
    echo '<h2>View Leads</h2>';
    echo '<p>Use this list to open the full lead detail screen for notes and follow-up.</p>';
    echo '</div>';
    echo '<form method="get" class="ieb-leads-search">';
    echo '<input type="hidden" name="page" value="instant-estimate-leads" />';
    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search name, email, phone, or ZIP" />';
    echo '<button type="submit" class="button hgm-button-secondary">Search</button>';
    if ($search !== '') {
        echo '<a href="' . esc_url(admin_url('admin.php?page=instant-estimate-leads')) . '" class="button hgm-button-secondary">Clear</a>';
    }
    echo '</form>';
    echo '</div>';

    if (!$leads_query->have_posts()) {
        echo '<div class="ieb-leads-empty">No leads found yet.</div>';
        echo '</div></div>';
        wp_reset_postdata();
        return;
    }

    echo '<div class="ieb-leads-table-wrap">';
    echo '<table class="widefat fixed striped ieb-leads-table">';
    echo '<thead><tr>';
    echo '<th>Name</th>';
    echo '<th>Email</th>';
    echo '<th>Phone</th>';
    echo '<th>ZIP Code</th>';
    echo '<th>Estimate</th>';
    echo '<th>Date</th>';
    echo '<th class="ieb-leads-actions-column">Action</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    while ($leads_query->have_posts()) {
        $leads_query->the_post();
        $post_id = get_the_ID();
        $email = get_post_meta($post_id, 'email', true);
        $phone = get_post_meta($post_id, 'phone', true);
        $zip = get_post_meta($post_id, 'zip_code', true);
        $low = get_post_meta($post_id, '_hgm_estimate_low', true);
        $high = get_post_meta($post_id, '_hgm_estimate_high', true);
        $estimate = ($low && $high) ? "$low – $high" : '—';
        $detail_url = admin_url('admin.php?page=instant-estimate-lead&id=' . $post_id);

        echo '<tr>';
        echo '<td><strong>' . esc_html(get_the_title() ?: 'Untitled Lead') . '</strong></td>';
        echo '<td>' . esc_html($email ?: '—') . '</td>';
        echo '<td>' . esc_html($phone ?: '—') . '</td>';
        echo '<td>' . esc_html($zip ?: '—') . '</td>';
        echo '<td>' . esc_html($estimate) . '</td>';
        echo '<td>' . esc_html(get_the_date('M j, Y g:i a')) . '</td>';
        echo '<td class="ieb-leads-actions"><a href="' . esc_url($detail_url) . '" class="button button-primary hgm-button-primary ieb-lead-detail-button">View Lead Details</a></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    $total_pages = (int) $leads_query->max_num_pages;
    if ($total_pages > 1) {
        echo '<div class="ieb-leads-pagination">';
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%', admin_url('admin.php?page=instant-estimate-leads' . ($search !== '' ? '&s=' . rawurlencode($search) : ''))),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => '&lsaquo; Previous',
            'next_text' => 'Next &rsaquo;',
        ]);
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    wp_reset_postdata();
}
