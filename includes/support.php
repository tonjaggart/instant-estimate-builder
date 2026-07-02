<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Instant Estimate Builder — Support Page
 * - TOC with smooth scroll
 * - Each section: brief text + embedded video (oEmbed with iframe fallback)
 */

// Responsive video embed helper (Loom-aware)
function hgm_support_embed_video( $url ) {
    $url = trim( (string) $url );

    // If it's a Loom "share" URL, convert to "embed"
    if ( strpos( $url, 'loom.com/share/' ) !== false ) {
        // Preserve query string (?sid=...) if present
        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? $parts['path'] : '';
        $qs    = isset( $parts['query'] ) ? ('?' . $parts['query']) : '';

        // /share/{id} -> /embed/{id}
        $path = str_replace( '/share/', '/embed/', $path );
        $base = 'https://www.loom.com';
        $url  = $base . $path . $qs;
    } else {
        // Non-Loom: try oEmbed first
        $o = wp_oembed_get( $url );
        if ( $o ) {
            return $o;
        }
    }

    return sprintf(
        '<div class="ieb-support-embed-frame"><iframe src="%s" loading="lazy" allowfullscreen></iframe></div>',
        esc_url( $url )
    );
}

function hgm_render_support_page() {
    // Define tutorials (edit titles or text anytime)
    $sections = [
        [
            'id'      => 'install-activate',
            'title'   => 'How to install the plugin',
            'video'   => 'https://www.loom.com/share/6e2919bed25e4d0ba530a18188b21511?sid=2765a02f-732d-46a8-b2e2-ccaced386800',
            'content' => '<p>Upload the ZIP in <strong>Plugins → Add New → Upload</strong>, then click <em>Activate</em>.</p>',
        ],
        [
            'id'      => 'create-first-form',
            'title'   => 'How to create your first form',
            'video'   => 'https://www.loom.com/share/cd1f4eed0b9e491192ed36481ad853b6?sid=722793eb-31d4-4687-b75e-2c2da0849293',
            'content' => '<p>Open <strong>Estimate Forms → Add New</strong>, configure your steps and questions, and save.</p>',
        ],
        [
            'id'      => 'customize-email',
            'title'   => 'How to customize the email estimate that gets sent to your potential customer',
            'video'   => 'https://www.loom.com/share/2507c40765c5482ab3ade8baafd7aa58?sid=edc362d9-eced-4904-b294-45e22b4a7faf',
            'content' => '<p>Adjust branding, colors, and message under <strong>Instant Estimate Builder → Email Settings</strong>.</p>',
        ],
        [
            'id'      => 'lead-email-notifications',
            'title'   => 'How to set new lead email notifications',
            'video'   => 'https://www.loom.com/share/27da3951e9b84b0094d715cca04213c1?sid=2beab232-e8da-4845-bcfe-d37f81795d41',
            'content' => '<p>Add your sales team emails under <strong>Notifications</strong> so everyone gets alerted when a lead comes in.</p>',
        ],
        [
            'id'      => 'lead-sms-notifications',
            'title'   => 'How to set new lead text message notifications',
            'video'   => 'https://www.loom.com/share/f37ebf1c8fd14b89b85234fa2f366468?sid=f6d2eaf3-0542-40d0-83b6-b1ca85a8bf4d',
            'content' => '<p>Enable SMS in <strong>Notifications</strong> and add the team phone numbers you want to notify.</p>',
        ],
        [
            'id'      => 'leads-view-export-notes',
            'title'   => 'How to view, export, and leave internal notes for each lead',
            'video'   => 'https://www.loom.com/share/d15a682598ac4150b0cde1041dc7ae8c?sid=01ead1e1-583e-46cc-a08e-cc4c1122c394',
            'content' => '<p>Go to <strong>Instant Estimate Builder → View Leads</strong> to review, export, and add internal notes.</p>',
        ],
        [
            'id'      => 'embed-shortcode',
            'title'   => 'How to add your instant estimate form to your website',
            'video'   => 'https://www.loom.com/share/1468e892cc004c1dadd05f054c358ba2?sid=fe6ebddd-4c4e-499e-9aac-b23654749aca',
            'content' => '<p>Edit a page and paste the shortcode like <code>[instant_estimate_form id="123"]</code> where you want the form to appear.</p>',
        ],
    ];

    $support_email = 'estimateplugin@taggartmediagroup.com';
    $support_email_url = 'mailto:' . $support_email;

    echo '<div id="hgm-support-top" class="wrap hgm-dashboard ieb-support-page">';

    echo '<section class="hgm-dashboard-hero ieb-support-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Support</div>';
    echo '<h1>Instant Estimate Support</h1>';
    echo '<p class="hgm-dashboard-subtitle">Find setup tutorials below. If you have questions or need help with your estimate forms, email <a href="' . esc_url($support_email_url) . '">' . esc_html($support_email) . '</a> and our team will help.</p>';
    echo '<div class="hgm-dashboard-actions">';
    echo '<a href="' . esc_url($support_email_url) . '" class="button button-primary hgm-button-primary">Email Support</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG)) . '" class="button hgm-button-secondary">Back To Dashboard</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="hgm-dashboard-card ieb-support-card ieb-support-toc-card">';
    echo '<div class="hgm-card-label">Tutorials</div>';
    echo '<h2>Quick Help Topics</h2>';
    echo '<p>Click a topic to jump to the tutorial.</p>';
    echo '<ol class="ieb-support-toc">';
    foreach ( $sections as $s ) {
        printf(
            '<li><a class="ieb-support-link" href="#%1$s">%2$s</a></li>',
            esc_attr($s['id']),
            esc_html($s['title'])
        );
    }
    echo '</ol>';
    echo '</div>';

    $allowed = [
        'p' => ['class'=>[],'style'=>[]],
        'strong' => [], 'em' => [], 'code' => [],
        'a' => ['href'=>[],'target'=>[],'rel'=>[],'style'=>[]],
        'ul'=>[], 'ol'=>[], 'li'=>[], 'br'=>[],
    ];

    echo '<div class="ieb-support-section-list">';
    foreach ( $sections as $s ) {
        $id      = $s['id'];
        $title   = $s['title'];
        $content = $s['content'];
        $video   = $s['video'];
        $embed_html = $video ? hgm_support_embed_video( $video ) : '';

        echo '<section id="' . esc_attr($id) . '" class="hgm-dashboard-card ieb-support-section">';
        echo '<div class="ieb-support-section-copy">';
        echo '<div class="hgm-card-label">Support Topic</div>';
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<div class="ieb-support-text">' . wp_kses($content, $allowed) . '</div>';
        echo '<a href="#hgm-support-top" class="ieb-support-back-link">Back To Top ↑</a>';
        echo '</div>';
        if ( $embed_html ) {
            echo '<div class="ieb-support-embed">' . $embed_html . '</div>';
        }
        echo '</section>';
    }
    echo '</div>';
?>
<script>
    (function(){
        document.querySelectorAll('.ieb-support-link, .ieb-support-back-link').forEach(function(a){
            a.addEventListener('click', function(e){
                var href = a.getAttribute('href') || '';
                if (href.startsWith('#')) {
                    e.preventDefault();
                    var el = document.querySelector(href);
                    if (el) {
                        window.scrollTo({ top: el.getBoundingClientRect().top + window.pageYOffset - 60, behavior: 'smooth' });
                        history.replaceState(null, '', href);
                    }
                }
            });
        });
    })();
</script>
<?php
    echo '</div>'; // #hgm-support-top .wrap
}
