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

        // Responsive 16:9 container (adjust padding-bottom if your videos differ)
        $iframe = sprintf(
            '<div style="position:relative;padding-bottom:56.25%%;height:0;"><iframe src="%s" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%%;height:100%%;"></iframe></div>',
            esc_url( $url )
        );
        return $iframe;
    }

    // Non-Loom: try oEmbed first
    $o = wp_oembed_get( $url );
    if ( $o ) {
        return $o;
    }

    // Fallback generic iframe
    return sprintf(
        '<div style="position:relative;padding-bottom:56.25%%;height:0;"><iframe src="%s" loading="lazy" allowfullscreen style="position:absolute;top:0;left:0;width:100%%;height:100%%;border:0;"></iframe></div>',
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
            'title'   => 'How view, export and leave internal notes for each lead',
            'video'   => 'https://www.loom.com/share/d15a682598ac4150b0cde1041dc7ae8c?sid=01ead1e1-583e-46cc-a08e-cc4c1122c394',
            'content' => '<p>Go to <strong>Instant Estimate Builder → View Leads</strong> to review, export, and add internal notes.</p>',
        ],
        [
            'id'      => 'embed-shortcode',
            'title'   => 'How to add your HVAC instant estimate form to your website',
            'video'   => 'https://www.loom.com/share/1468e892cc004c1dadd05f054c358ba2?sid=fe6ebddd-4c4e-499e-9aac-b23654749aca',
            'content' => '<p>Edit a page and paste the shortcode like <code>[instant_estimate_form id="123"]</code> where you want the form to appear.</p>',
        ],
    ];

    echo '<div id="hgm-support-top" class="wrap">';
    echo '<h1>Instant Estimate Builder — Support</h1>';
    echo '<p class="description">Quick tutorials and videos. Click a topic to jump to it.</p>';

    // Table of contents
    echo '<ol class="hgm-support-toc">';
    foreach ( $sections as $s ) {
        printf(
            '<li><a class="hgm-toc-link" href="#%1$s">%2$s</a></li>',
            esc_attr($s['id']),
            esc_html($s['title'])
        );
    }
    echo '</ol>';
    echo '<hr />';

    // Allowed tags for content snippet
    $allowed = [
        'p' => ['class'=>[],'style'=>[]],
        'strong' => [], 'em' => [], 'code' => [],
        'a' => ['href'=>[],'target'=>[],'rel'=>[],'style'=>[]],
        'ul'=>[], 'ol'=>[], 'li'=>[], 'br'=>[],
    ];

    // Sections
    foreach ( $sections as $s ) {
        $id      = $s['id'];
        $title   = $s['title'];
        $content = $s['content'];
        $video   = $s['video'];

       $embed_html = $video ? hgm_support_embed_video( $video ) : '';

        echo '<section id="' . esc_attr($id) . '" class="hgm-support-section">';
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<div class="hgm-support-body">';
        echo '  <div class="hgm-support-text">' . wp_kses($content, $allowed) . '</div>';
        if ( $embed_html ) {
            echo '  <div class="hgm-support-embed">' . $embed_html . '</div>';
        }
        echo '</div>';
        echo '<p><a href="#hgm-support-top" class="hgm-toc-link">Back to top ↑</a></p>';
        echo '<hr />';
        echo '</section>';
    }

?>
<style>
    .hgm-support-toc { margin: 1em 0 2em; padding-left: 1.25em; }
    .hgm-support-section { scroll-margin-top: 90px; }
    .hgm-support-body { display: grid; grid-template-columns: 1fr; gap: 16px; }
    @media (min-width: 900px){
        .hgm-support-body { grid-template-columns: 1.2fr 1fr; }
    }
    .hgm-support-video, .hgm-support-embed { max-width: 820px; }
    .hgm-support-text p { margin: 0.5em 0; }
</style>
<script>
    (function(){
        // Smooth scroll for this admin page only
        document.querySelectorAll('.hgm-toc-link').forEach(function(a){
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
