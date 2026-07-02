<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register Custom Post Type for Estimate Forms
function hgm_register_quote_form_cpt() {
    register_post_type( 'instant_quote_form', array(
        'labels' => array(
            'name' => 'Estimate Forms',
            'singular_name' => 'Estimate Form',
            'add_new_item' => 'Add New Estimate Form',
            'edit_item' => 'Edit Estimate Form',
            'menu_name' => 'Estimate Forms',
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // hide default menu
        'menu_position' => 25,
        'menu_icon' => 'dashicons-list-view',
        'supports' => array( 'title' ),
        'capability_type' => 'post',
    ));
}
add_action( 'init', 'hgm_register_quote_form_cpt' );

// Rename Publish/Save Buttons
add_filter('gettext', 'hgm_rename_publish_buttons', 10, 3);
function hgm_rename_publish_buttons($translation, $text, $domain) {
    global $post;

    if (is_admin() && $post && get_post_type($post) === 'instant_quote_form') {
        switch ($text) {
            case 'Publish':
                return 'Make Live';
            case 'Save Draft':
                return 'Save for Later';
            case 'Update':
                return 'Save Changes';
        }
    }

    return $translation;
}

