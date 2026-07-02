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
            $url = admin_url('admin.php?page=hgm_view_lead&id=' . $post_id);
            echo '<a class="button button-primary" href="' . esc_url($url) . '">🔎 View Lead Details</a>';
            break;
    }
}, 10, 2);


// Add Export CSV button above leads list
add_action('manage_posts_extra_tablenav', function($which) {
    global $typenow;

    if ($typenow === 'hgm_lead' && $which === 'top') {
        $export_url = admin_url('admin-post.php?action=hgm_export_leads_csv');
        echo '<div class="alignleft actions">';
        echo '<a href="' . esc_url($export_url) . '" class="button button-primary">Export Leads CSV</a>';
        echo '</div>';
    }
});

add_action('admin_post_hgm_export_leads_csv', 'hgm_export_leads_csv_callback');

function hgm_export_leads_csv_callback() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="hvac_leads.csv"');
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

        fputcsv($output, [$id, $name, $email, $phone, $zip, $low, $high, $date]);
    }

    fclose($output);
    exit;
}

