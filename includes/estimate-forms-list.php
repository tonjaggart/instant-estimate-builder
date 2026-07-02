<?php
if (!defined('ABSPATH')) exit;

function ieb_render_estimate_forms_page() {
    $forms = get_posts([
        'post_type'      => 'instant_quote_form',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    $total_forms = count($forms);
    $published_forms = 0;

    foreach ($forms as $form) {
        if ($form->post_status === 'publish') {
            $published_forms++;
        }
    }

    echo '<div class="wrap hgm-dashboard ieb-forms-page">';

    echo '<section class="hgm-dashboard-hero ieb-forms-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Estimate Forms</div>';
    echo '<h1>Instant Estimate Forms</h1>';
    echo '<p class="hgm-dashboard-subtitle">Manage the instant estimate forms you use to capture local-service leads.</p>';
    echo '<div class="hgm-dashboard-actions">';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=instant_quote_form')) . '" class="button button-primary hgm-button-primary">Add New Estimate Form</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG)) . '" class="button hgm-button-secondary">Back To Dashboard</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="ieb-forms-summary-grid">';
    echo '<div class="hgm-dashboard-card ieb-forms-summary-card"><span>Total Forms</span><strong>' . esc_html($total_forms) . '</strong></div>';
    echo '<div class="hgm-dashboard-card ieb-forms-summary-card"><span>Published Forms</span><strong>' . esc_html($published_forms) . '</strong></div>';
    echo '</div>';

    echo '<div class="hgm-dashboard-card ieb-forms-table-card">';
    echo '<div class="hgm-card-label">Form Library</div>';
    echo '<h2>Manage Estimate Forms</h2>';

    if (empty($forms)) {
        echo '<p>No estimate forms found yet.</p>';
        echo '<a href="' . esc_url(admin_url('post-new.php?post_type=instant_quote_form')) . '" class="button button-primary hgm-button-primary">Create Your First Estimate Form</a>';
        echo '</div></div>';
        return;
    }

    echo '<table class="widefat fixed striped ieb-forms-table">';
    echo '<thead><tr>';
    echo '<th>Form Name</th>';
    echo '<th>Status</th>';
    echo '<th>Last Updated</th>';
    echo '<th class="ieb-forms-actions-column">Actions</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($forms as $form) {
        $edit_url = get_edit_post_link($form->ID, 'raw');
        $trash_url = get_delete_post_link($form->ID);
        $status = get_post_status_object($form->post_status);
        $status_label = $status ? $status->label : ucfirst($form->post_status);

        echo '<tr>';
        echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html(get_the_title($form) ?: 'Untitled Estimate Form') . '</a></strong></td>';
        echo '<td><span class="ieb-status-pill">' . esc_html($status_label) . '</span></td>';
        echo '<td>' . esc_html(get_the_modified_date('M j, Y', $form)) . '</td>';
        echo '<td class="ieb-forms-actions">';
        echo '<a href="' . esc_url($edit_url) . '" class="button hgm-button-secondary ieb-table-button">Edit</a>';
        echo '<a href="' . esc_url($trash_url) . '" class="button hgm-button-secondary ieb-table-button ieb-table-button-danger">Trash</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}
