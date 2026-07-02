<?php
function hgm_get_license_status_secure() {
    // Instant Estimate Builder is free to use. Keep this legacy helper so older
    // shortcode/render paths do not break, but do not block rendering or call
    // the old license server.
    return 'active';
}
function render_instant_quote_form_shortcode($atts) {

    // ✅ Ensure scripts load wherever shortcode is used (ACF, templates, etc.)
    if (!wp_script_is('hgm-multi-step', 'enqueued')) {

        wp_enqueue_style(
            'hgm-frontend-css',
            HGM_PLUGIN_URL . 'assets/admin.css',
            [],
            filemtime(HGM_PLUGIN_PATH . 'assets/admin.css')
        );

        wp_enqueue_script(
            'hgm-multi-step',
            HGM_PLUGIN_URL . 'assets/multi-step-form.js',
            ['jquery'],
            filemtime(HGM_PLUGIN_PATH . 'assets/multi-step-form.js'),
            true
        );

        wp_localize_script('hgm-multi-step', 'hgm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('hgm_nonce'),
        ]);
    }
    // Normal rendering. The plugin is free, so legacy license checks do not block forms.
    $atts = shortcode_atts(['id' => 0], $atts, 'instant_quote_form');
    $form_id   = $atts['id'] ? absint($atts['id']) : get_the_ID();
    $form_data = get_post_meta($form_id, '_hgm_form_data', true);

    if (empty($form_data) || !is_array($form_data)) {
        return '<p>No estimate form data found for this ID.</p>';
    }

    ob_start();
    echo '<!-- ✅ multi-step-form.php loaded -->';
    include plugin_dir_path(__FILE__) . 'multi-step-form.php';

    return ob_get_clean();
}
add_shortcode('instant_estimate_form', 'render_instant_quote_form_shortcode');
add_shortcode('instant_quote_form', 'render_instant_quote_form_shortcode');