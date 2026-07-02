<?php
/**
 * Template Name: Instant Estimate Preview
 */

if (!defined('ABSPATH')) {
    exit;
}

$post_id = intval($_GET['form_id'] ?? 0);

if (!$post_id) {
    wp_die('Invalid form ID');
}

// Replace this with your actual shortcode or form render function
$form_shortcode = '[instant_quote_form id="' . $post_id . '"]';

get_header();
?>

<div class="instant-quote-preview-container" style="max-width: 900px; margin: 30px auto;">
    <?php echo do_shortcode($form_shortcode); ?>
</div>

<?php get_footer(); ?>