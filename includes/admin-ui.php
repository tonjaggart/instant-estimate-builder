<?php
if (!defined("ABSPATH")) {
    exit();
}

function hgm_add_quote_form_metabox()
{
    add_meta_box(
        "hgm_quote_form_config",
        "Instant Estimate Builder",
        "hgm_render_quote_form_edit_screen", // ✅ this is correct
        "instant_quote_form",
        "normal",
        "high"
    );
}

add_action("add_meta_boxes", "hgm_add_quote_form_metabox");

add_action('edit_form_top', 'hgm_render_estimate_form_editor_header');
function hgm_render_estimate_form_editor_header($post)
{
    if (!$post || $post->post_type !== 'instant_quote_form') {
        return;
    }

    $form_id = absint($post->ID);
    $is_new_form = $post->post_status === 'auto-draft' || basename($_SERVER['PHP_SELF']) === 'post-new.php';
    $title = get_the_title($form_id) ?: 'Untitled';
    $header_title = preg_match('/estimate\s+form$/i', $title) ? $title : $title . ' Estimate Form';
    $forms_url = admin_url('admin.php?page=instant-estimate-forms');
    $new_url = admin_url('admin.php?page=instant-estimate-form');
    $preview_url = add_query_arg(
        [
            'quote_form_preview' => 1,
            'form_id'            => $form_id,
        ],
        home_url('/')
    );
    ?>
    <section class="hgm-dashboard-hero ieb-edit-hero">
        <div class="hgm-dashboard-eyebrow">Estimate Form Editor</div>
        <?php if ($is_new_form) : ?>
            <h1>Create a New Instant Estimate Form</h1>
        <?php else : ?>
            <h1>Edit <?php echo esc_html($header_title); ?></h1>
        <?php endif; ?>
        <p class="hgm-dashboard-subtitle">Customize the form name, brand color, email subject line, shortcode embed, lead questions, and Klaviyo integration settings.</p>
        <div class="hgm-dashboard-actions">
            <a href="<?php echo esc_url($forms_url); ?>" class="button hgm-button-secondary">Back To Estimate Forms</a>
            <?php if (!$is_new_form) : ?>
                <a href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener" class="button hgm-button-secondary">Preview Form</a>
                <a href="<?php echo esc_url($new_url); ?>" class="button button-primary hgm-button-primary">Add New Estimate Form</a>
            <?php endif; ?>
        </div>
    </section>
    <?php if ($is_new_form) : ?>
        <script>
            window.history.replaceState(null, '', '<?php echo esc_js(admin_url('admin.php?page=instant-estimate-form')); ?>');
        </script>
    <?php endif; ?>
    <?php
}

function hgm_render_quote_form_edit_screen()
{
    global $post;

    wp_nonce_field("hgm_save_form_data", "hgm_form_data_nonce");

    $form_data = get_post_meta($post->ID, "_hgm_form_data", true);

    if (!is_array($form_data)) {
        $decoded = json_decode($form_data, true);
        $form_data = is_array($decoded) ? $decoded : [];
    }

    $encoded_data = wp_json_encode($form_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    echo "<script>window.hgm_quote_questions = " . $encoded_data . ";</script>";

    echo '<div id="hgm-form-builder">';
    echo '<h3 style="margin-bottom: 10px;">Build Your Instant Estimate Form</h3>';
    echo "<p><em>Click “Add a Question” to begin building your instant estimate form.</em></p>";
    echo '<div id="hgm-steps-container"></div>';
    echo '<input type="hidden" name="hgm_quote_questions_json" id="hgm_quote_questions_json" value="" />';

    $form_preview_url = add_query_arg(
        array(
            'quote_form_preview' => 1,
            'form_id'            => absint( $post->ID ),
        ),
        home_url( '/' )
    );
    echo '<button type="button" class="button button-primary" id="hgm-add-step">+ Add a Question</button>
          <button type="button" class="button button-secondary" id="hgm-save-questions" style="margin-left: 10px;">💾 Save Questions</button>
          <a href="' .
        esc_url($form_preview_url) .
        '" target="_blank" class="button" style="margin-left: 10px;">👁 Preview Form</a>';

    echo "</div>";
}
function ieb_sanitize_form_builder_data($steps)
{
    $steps = is_array($steps) ? $steps : [];

    return array_values(array_map(function ($step) {
        $step = is_array($step) ? $step : [];
        $options = is_array($step["options"] ?? null) ? $step["options"] : [];

        return [
            "title" => sanitize_text_field($step["title"] ?? ""),
            "subtitle" => sanitize_text_field($step["subtitle"] ?? ""),
            "boldLabel" => sanitize_text_field($step["boldLabel"] ?? ""),
            "type" => in_array(($step["type"] ?? "radio"), ["radio", "dropdown", "text"], true) ? $step["type"] : "radio",
            "tooltip" => sanitize_text_field($step["tooltip"] ?? ""),
            "image" => esc_url_raw($step["image"] ?? ""),
            "options" => array_values(array_map(function ($option) {
                $option = is_array($option) ? $option : [];
                return [
                    "label" => sanitize_text_field($option["label"] ?? ""),
                    "low" => isset($option["low"]) && is_numeric($option["low"]) ? floatval($option["low"]) : 0,
                    "high" => isset($option["high"]) && is_numeric($option["high"]) ? floatval($option["high"]) : 0,
                ];
            }, $options)),
        ];
    }, $steps));
}

function hgm_save_quote_form_metabox($post_id)
{
    if (
        !isset($_POST["hgm_form_data_nonce"]) ||
        !wp_verify_nonce($_POST["hgm_form_data_nonce"], "hgm_save_form_data")
    ) {
        return;
    }

    if (defined("DOING_AUTOSAVE") && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can("edit_post", $post_id)) {
        return;
    }

    if (isset($_POST["hgm_form_data_json"])) {
        $data = json_decode(wp_unslash($_POST["hgm_form_data_json"]), true);
        if (is_array($data)) {
            update_post_meta($post_id, "_hgm_form_data", ieb_sanitize_form_builder_data($data));
        }
    }
}
add_action("save_post", "hgm_save_quote_form_metabox");

// In admin-ui.php or similar
function hgm_enqueue_admin_assets($hook)
{
    if ($hook !== "post.php" && $hook !== "post-new.php") {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'instant_quote_form') {
        return;
    }

    wp_enqueue_media();

    wp_enqueue_script(
        "cropper-js",
        plugin_dir_url(dirname(__FILE__)) . "assets/vendor/cropperjs/cropper.min.js",
        [],
        "1.5.13",
        true
    );
    wp_enqueue_style(
        "cropper-css",
        plugin_dir_url(dirname(__FILE__)) . "assets/vendor/cropperjs/cropper.min.css",
        [],
        "1.5.13"
    );

    // Correct paths to assets folder at plugin root
    wp_enqueue_script(
        "hgm-admin-js",
        plugin_dir_url(dirname(__FILE__)) . "assets/admin.js",
        ["jquery"],
        filemtime(plugin_dir_path(dirname(__FILE__)) . "assets/admin.js"),
        true
    );
    wp_localize_script("hgm-admin-js", "hgmAdminSecurity", [
        "uploadNonce" => wp_create_nonce("hgm_upload_cropped_image"),
    ]);
    wp_enqueue_style(
        "hgm-dashboard-css",
        plugin_dir_url(dirname(__FILE__)) . "assets/dashboard.css",
        [],
        filemtime(plugin_dir_path(dirname(__FILE__)) . "assets/dashboard.css")
    );
    wp_enqueue_style(
        "hgm-admin-css",
        plugin_dir_url(dirname(__FILE__)) . "assets/admin.css",
        [],
        filemtime(plugin_dir_path(dirname(__FILE__)) . "assets/admin.css")
    );
}
add_action("admin_enqueue_scripts", "hgm_enqueue_admin_assets");

add_action("wp_ajax_hgm_upload_cropped_image", "hgm_upload_cropped_image");
function hgm_upload_cropped_image()
{
    check_ajax_referer("hgm_upload_cropped_image", "nonce");

    if (!current_user_can("upload_files") || !isset($_FILES["file"]) || !function_exists("wp_handle_sideload")) {
        wp_send_json_error(["message" => "Unauthorized or missing file."]);
    }

    $file = $_FILES["file"];
    $file_type = wp_check_filetype_and_ext($file["tmp_name"], $file["name"]);
    if (empty($file_type["type"]) || strpos($file_type["type"], "image/") !== 0) {
        wp_send_json_error(["message" => "Please upload a valid image file."]);
    }

    // Give it a unique name
    $file["name"] = wp_unique_filename(wp_upload_dir()["path"], $file["name"]);

    // Let WordPress handle the upload
    $upload = wp_handle_sideload($file, ["test_form" => false]);

    if (isset($upload["error"])) {
        wp_send_json_error(["message" => $upload["error"]]);
    }

    // Now insert into Media Library
    $attachment = [
        "post_mime_type" => $upload["type"],
        "post_title" => sanitize_file_name($file["name"]),
        "post_content" => "",
        "post_status" => "inherit",
    ];

    $attach_id = wp_insert_attachment($attachment, $upload["file"]);

    require_once ABSPATH . "wp-admin/includes/image.php";
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload["file"]);
    wp_update_attachment_metadata($attach_id, $attach_data);

    $image_url = wp_get_attachment_url($attach_id);

    wp_send_json_success(["url" => $image_url]);
}

function hgm_save_quote_form_meta($post_id)
{
    // Bail early if doing autosave, ajax, or wrong post type
    if (defined("DOING_AUTOSAVE") && DOING_AUTOSAVE) {
        return;
    }
    if (defined("DOING_AJAX") && DOING_AJAX) {
        return;
    }
    if (get_post_type($post_id) !== "instant_quote_form") {
        return;
    }
    if (!current_user_can("edit_post", $post_id)) {
        return;
    }

    if (!isset($_POST["hgm_quote_questions_json"])) {
        return;
    }

    // Decode, sanitize, and re-encode JSON
    $raw_json = wp_unslash($_POST["hgm_quote_questions_json"]);
    $decoded = json_decode($raw_json, true);

    if (!is_array($decoded)) {
        return;
    }

    $sanitized = array_map("hgm_sanitize_question", $decoded);
    $re_encoded = wp_json_encode($sanitized);

    update_post_meta($post_id, "hgm_quote_questions", $re_encoded);

    if (isset($_POST['hgm_button_color'])) {
        $color = sanitize_hex_color($_POST['hgm_button_color']);
        if ($color) {
            update_post_meta($post_id, '_hgm_button_color', $color);
        }
    }
}

function hgm_sanitize_question($question)
{
    return [
        "title" => sanitize_text_field($question["title"] ?? ""),
        "type" => sanitize_text_field($question["type"] ?? ""),
        "tooltip" => sanitize_text_field($question["tooltip"] ?? ""),
        "image" => esc_url_raw($question["image"] ?? ""),
        "answers" => array_map(function ($answer) {
            return [
                "label" => sanitize_text_field($answer["label"] ?? ""),
                "low" => floatval($answer["low"] ?? 0),
                "high" => floatval($answer["high"] ?? 0),
            ];
        }, $question["answers"] ?? []),
    ];
}
add_action("save_post_instant_quote_form", "hgm_save_quote_form_data");
function hgm_save_quote_form_data($post_id)
{
    if (defined("DOING_AUTOSAVE") && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can("edit_post", $post_id)) {
        return;
    }

    if (isset($_POST["hgm_form_data_json"])) {
        $decoded = json_decode(wp_unslash($_POST["hgm_form_data_json"]), true);
        if (is_array($decoded)) {
            update_post_meta($post_id, "hgm_quote_questions", wp_json_encode(ieb_sanitize_form_builder_data($decoded)));
        }
    }
}
add_action("wp_enqueue_scripts", function () {
    if (is_singular("instant_quote_form")) {
        wp_enqueue_style(
            "hgm-admin-css",
            plugin_dir_url(dirname(__FILE__)) . "assets/admin.css",
            [],
            filemtime(plugin_dir_path(dirname(__FILE__)) . "assets/admin.css")
        );

        wp_enqueue_script(
            "hgm-admin-js",
            plugin_dir_url(dirname(__FILE__)) . "assets/admin.js",
            ["jquery"],
            filemtime(plugin_dir_path(dirname(__FILE__)) . "assets/admin.js"),
            true
        );
    }
});
// Enqueue admin styles for custom leads page
function hgm_enqueue_leads_page_styles($hook)
{
    if ($hook === "instant_quote_form_page_hgm_leads") {
        wp_enqueue_style(
            "hgm-admin-leads-css",
            plugin_dir_url(__FILE__) . "../assets/admin.css",
            [],
            filemtime(plugin_dir_path(__FILE__) . "../assets/admin.css")
        );
    }
}
add_action("admin_enqueue_scripts", "hgm_enqueue_leads_page_styles");

// Add a read-only metabox to view lead details
add_action("add_meta_boxes", function () {
    add_meta_box("hgm_lead_details", "Lead Details", "hgm_render_lead_details_box", "hgm_lead", "normal", "high");
});

function hgm_render_lead_details_box($post)
{
    $fields = [
        "First Name" => $post->post_title,
        "Email" => get_post_meta($post->ID, "email", true),
        "Phone" => get_post_meta($post->ID, "phone", true),
        "Zip Code" => get_post_meta($post->ID, "zip_code", true),
    ];

    echo '<table class="form-table">';
    foreach ($fields as $label => $value) {
        echo "<tr>";
        echo '<th scope="row"><label>' . esc_html($label) . "</label></th>";
        echo "<td><strong>" . esc_html($value ?: "—") . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
}
// Replace title column completely to remove link
add_filter("manage_edit-hgm_lead_columns", function ($columns) {
    unset($columns["title"]); // remove default title column

    // Define custom column order: checkbox first, then name, then others
    return array_merge(["cb" => '<input type="checkbox" />', "lead_name" => "Name"], $columns);
});

// Display plain text name instead of link
add_action(
    "manage_hgm_lead_posts_custom_column",
    function ($column, $post_id) {
        if ($column === "lead_name") {
            echo esc_html(get_the_title($post_id)); // plain title
        }
    },
    10,
    2
);

add_action("admin_menu", function () {
    add_submenu_page(
        null, // Hidden from the menu
        "Instant Estimate Lead Details", // Page title
        "Instant Estimate Lead Details", // Menu title (unused here)
        defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options', // Sensitive lead details
        "instant-estimate-lead", // Page slug
        "hgm_render_view_lead_screen" // Callback function
    );
});

function hgm_render_view_lead_screen()
{
    if (!current_user_can(defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options')) {
        wp_die(__("You are not allowed to access this page."));
    }

    $lead_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

    if (!$lead_id) {
        echo '<div class="wrap hgm-dashboard ieb-lead-detail-page"><div class="notice notice-error"><p>Invalid lead ID.</p></div></div>';
        return;
    }

    $post = get_post($lead_id);
    if (!$post || $post->post_type !== "hgm_lead") {
        echo '<div class="wrap hgm-dashboard ieb-lead-detail-page"><div class="notice notice-error"><p>Lead not found.</p></div></div>';
        return;
    }

    $email = get_post_meta($lead_id, "email", true);
    $phone = get_post_meta($lead_id, "phone", true);
    $zip = get_post_meta($lead_id, "zip_code", true);
    $estimate_low = get_post_meta($lead_id, "_hgm_estimate_low", true);
    $estimate_high = get_post_meta($lead_id, "_hgm_estimate_high", true);
    $estimate = ($estimate_low || $estimate_high) ? trim($estimate_low . " – " . $estimate_high, " –") : "—";
    $submitted_at = get_the_date("M j, Y g:i a", $lead_id);

    echo '<div class="wrap hgm-dashboard ieb-lead-detail-page">';
    echo '<section class="hgm-dashboard-hero ieb-lead-detail-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Lead Record</div>';
    echo '<h1>Instant Estimate Lead Details</h1>';
    echo '<p class="hgm-dashboard-subtitle">Review this lead\'s contact information, submitted estimate answers, and internal follow-up notes.</p>';
    echo '<div class="hgm-dashboard-actions">';
    echo '<a href="' . esc_url(admin_url("admin.php?page=instant-estimate-leads")) . '" class="button button-primary hgm-button-primary">&larr; Back To Leads</a>';
    echo '<a href="' . esc_url(admin_url("admin.php?page=" . IEB_ADMIN_MENU_SLUG)) . '" class="button hgm-button-secondary">Back To Dashboard</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="hgm-dashboard-card ieb-lead-detail-summary-card">';
    echo '<div class="hgm-card-label">Summary</div>';
    echo '<h2>Lead Summary</h2>';
    echo '<div class="ieb-leads-table-wrap ieb-lead-detail-summary-wrap">';
    echo '<table class="widefat fixed striped ieb-leads-table ieb-lead-detail-summary-table">';
    echo "<thead><tr>";
    echo "<th>Name</th>";
    echo "<th>Email</th>";
    echo "<th>Phone</th>";
    echo "<th>ZIP Code</th>";
    echo "<th>Estimate</th>";
    echo "<th>Submitted</th>";
    echo "</tr></thead>";
    echo "<tbody><tr>";
    echo "<td><strong>" . esc_html($post->post_title ?: "Untitled Lead") . "</strong></td>";
    echo "<td>" . esc_html($email ?: "—") . "</td>";
    echo "<td>" . esc_html($phone ?: "—") . "</td>";
    echo "<td>" . esc_html($zip ?: "—") . "</td>";
    echo "<td>" . esc_html($estimate) . "</td>";
    echo "<td>" . esc_html($submitted_at ?: "—") . "</td>";
    echo "</tr></tbody>";
    echo "</table>";
    echo "</div>";
    echo "</div>";

    echo '<div class="ieb-lead-detail-grid">';

    $form_data_raw = get_post_meta($lead_id, "_hgm_form_data", true);

    $form_data = is_string($form_data_raw) ? json_decode($form_data_raw, true) : $form_data_raw;

    // Left column: Answers table
    echo '<div class="hgm-dashboard-card ieb-lead-detail-card ieb-lead-answers-card">';
    echo '<div class="hgm-card-label">Submitted Answers</div>';
    echo '<h2>Answers</h2><p>All of the answers the lead provided.</p>';
    echo '<div class="ieb-leads-table-wrap">';
    echo '<table class="widefat fixed striped ieb-leads-table ieb-lead-answers-table">';
    echo "<thead><tr>";
    echo '<th style="width: 50%;">Question</th>';
    echo "<th>Answer</th>";
    echo "</tr></thead>";
    echo "<tbody>";

    // Get raw _hgm_form_data meta from lead
    $form_data_raw = get_post_meta($lead_id, "_hgm_form_data", true);

    // Decode if it is JSON string, else assume array
    $form_data = is_string($form_data_raw) ? json_decode($form_data_raw, true) : $form_data_raw;

    // Get raw _hgm_form_data meta from the lead post
    $form_data_raw = get_post_meta($lead_id, "_hgm_form_data", true);

    // Decode if it is JSON string, else assume array
    $form_data = is_string($form_data_raw) ? json_decode($form_data_raw, true) : $form_data_raw;

    if (!empty($form_data) && is_array($form_data)) {
        // Get the form post ID associated with this lead
        $form_post_id = get_post_meta($lead_id, "_hgm_form_id", true);

        // Retrieve form steps meta from estimate form post
        $form_steps_raw = get_post_meta($form_post_id, "_hgm_form_data", true);

        if (is_string($form_steps_raw)) {
            $form_steps = maybe_unserialize($form_steps_raw);
        } elseif (is_array($form_steps_raw)) {
            $form_steps = $form_steps_raw;
        } else {
            $form_steps = [];
        }

        $index = 0;
        foreach ($form_data as $step_key => $answer_data) {
            $question_title =
                isset($form_steps[$index]["title"]) && !empty($form_steps[$index]["title"])
                ? $form_steps[$index]["title"]
                : ucfirst(str_replace("_", " ", $step_key));

            $answer_label = $answer_data["label"] ?? "—";

            echo "<tr>";
            echo "<td>" . esc_html($question_title) . "</td>";
            echo "<td>" . esc_html($answer_label) . "</td>";
            echo "</tr>";

            $index++;
        }
    } else {
        echo '<tr><td colspan="2"><em>No answers found.</em></td></tr>';
    }

    echo "</tbody></table>";
    echo "</div>"; // End answers table wrap
    echo "</div>"; // End left column

    // Right column: Notes editor
    echo '<div class="hgm-dashboard-card ieb-lead-detail-card ieb-lead-notes-card">';
    echo '<div class="hgm-card-label">Follow Up</div>';
    echo '<h2>Internal Notes <span id="hgm-save-status"></span></h2><p class="ieb-lead-notes-help">Notes save automatically a couple of seconds after you stop typing.</p>';

    $notes = get_post_meta($lead_id, "_hgm_lead_notes", true);
    wp_editor($notes, "hgm_lead_notes_editor", [
        "textarea_name" => "hgm_lead_notes",
        "textarea_rows" => 16,
        "teeny" => true,
        "media_buttons" => false,
        "tinymce" => [
            "toolbar1" => "bold italic underline bullist numlist link",
            "toolbar2" => "",
            "menubar" => false,
            "statusbar" => false,
            "wp_autoresize_on" => true,
        ],
        "quicktags" => false,
    ]);

    //echo '<div style="margin-top: 12px; text-align: right;">';
    //echo '<button id="hgm-save-notes" class="button button-primary" data-lead-id="' . esc_attr($lead_id) . '">💾 Save Notes</button>';
    //echo '</div>';

    echo "</div>";

    echo "</div>"; // End flex row
    echo "</div>";

    echo "<script>";
    echo 'window.ajaxurl = "' . esc_url(admin_url("admin-ajax.php")) . '";';
?>
(function($) {
function initAutosaveNotes() {
let timeout;
const $status = $('#hgm-save-status');
const leadId = <?php echo intval($lead_id); ?>;
const notesNonce = '<?php echo esc_js(wp_create_nonce("hgm_save_lead_notes")); ?>';

function saveNotes() {
let notes = '';
const editor = window.tinyMCE?.get('hgm_lead_notes_editor');

if (editor && !editor.isHidden()) {
notes = editor.getContent();
} else {
notes = $('#hgm_lead_notes_editor').val();
}

$.ajax({
method: 'POST',
url: window.ajaxurl,
data: {
action: 'hgm_save_lead_notes',
lead_id: leadId,
notes: notes,
nonce: notesNonce
},
success: function(res) {
console.log('AJAX success:', res);
if (res.success) {
$status.text('Notes autosaved ✔️').fadeIn().delay(1500).fadeOut();
} else {
$status.text('Save failed: ' + (res.data?.message || 'Unknown')).fadeIn().delay(1500).fadeOut();
}
},
error: function(xhr, status, error) {
console.error('AJAX error:', status, error, xhr.responseText);
$status.text('Autosave failed ❌').fadeIn().delay(1500).fadeOut();
}
});
}

// Trigger autosave when typing in TinyMCE
setTimeout(() => {
const editor = window.tinyMCE?.get('hgm_lead_notes_editor');
const iframe = document.getElementById('hgm_lead_notes_editor_ifr');

if (editor && iframe) {
const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

if (iframeDoc && iframeDoc.body) {
iframeDoc.body.addEventListener('input', () => {
clearTimeout(timeout);
timeout = setTimeout(saveNotes, 2000);
});
} else {
console.error('TinyMCE iframe body not found');
}
} else {
console.error('TinyMCE editor or iframe not found');
}
}, 1000);
}

$(document).ready(function() {
if (typeof window.tinyMCE !== 'undefined') {
initAutosaveNotes();
} else {
let retries = 10;
const waitForTinyMCE = setInterval(() => {
if (window.tinyMCE) {
clearInterval(waitForTinyMCE);
initAutosaveNotes();
} else if (--retries <= 0) {
                         clearInterval(waitForTinyMCE);
                         console.error('TinyMCE never loaded');
                         }
                         }, 300);
                         }
                         });
                         })(jQuery);
                         <?php echo "</script>";
}

add_action("wp_ajax_hgm_save_lead_notes", function () {
    check_ajax_referer("hgm_save_lead_notes", "nonce");

    if (!current_user_can(defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options')) {
        wp_send_json_error(["message" => "Unauthorized"]);
    }

    $lead_id = intval($_POST["lead_id"] ?? 0);
    $notes = wp_kses_post($_POST["notes"] ?? "");

    if (!$lead_id || get_post_type($lead_id) !== "hgm_lead") {
        wp_send_json_error(["message" => "Invalid lead ID"]);
    }

    update_post_meta($lead_id, "_hgm_lead_notes", $notes);

    wp_send_json_success(["message" => "Notes saved"]);
});

add_action("edit_form_after_editor", "hgm_render_form_shortcode_box");
function hgm_render_form_shortcode_box($post)
{
    if ($post->post_type !== "instant_quote_form") {
        return;
    }

    $form_id = intval($post->ID);
    if (!$form_id) {
        return;
    }

    $shortcode = '[instant_estimate_form id="' . esc_attr($form_id) . '"]';
    $button_color = get_post_meta($post->ID, '_hgm_quote_button_color', true) ?: '#013c55';
    $subject = get_post_meta($post->ID, 'hgm_email_subject', true);

    // ======================
    // KLAVIYO SETTINGS (FORM LEVEL)
    // ======================
    $klaviyo_enabled = get_post_meta($post->ID, 'hgm_enable_klaviyo', true);
    $klaviyo_list_id = get_post_meta($post->ID, 'hgm_klaviyo_list_id', true);
    $api_key = get_option('hgm_klaviyo_api_key');
    ?>
<div class="ieb-edit-card ieb-form-settings-card">
    <div class="hgm-card-label">Form Settings</div>
    <h2>Form Settings</h2>
    <table class="form-table ieb-settings-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">
                    <label for="hgm_quote_button_color">Form Primary Color</label>
                </th>
                <td>
                    <input type="text"
                           id="hgm_quote_button_color"
                           name="hgm_quote_button_color"
                           value="<?php echo esc_attr($button_color); ?>"
                           class="hgm-quote-form-color"
                           data-default-color="#E84232" />
                    <p class="description">Choose a color for the form button.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="hgm_email_subject">Email Subject Line</label>
                </th>
                <td>
                    <input type="text"
                           id="hgm_email_subject"
                           name="hgm_email_subject"
                           value="<?php echo esc_attr($subject); ?>"
                           class="regular-text"
                           placeholder="Your HVAC Estimate is Ready!" />
                    <p class="description">
                        Customize the subject line. The customer’s first name will always be added automatically. e.g. Jon - Your Custom Subject Line Here
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Embed The Estimate Form</th>
                <td>
                    <div class="ieb-shortcode-row">
                        <code id="hgm-quote-shortcode" class="ieb-shortcode-code"><?php echo esc_html($shortcode); ?></code>
                        <button type="button" class="button hgm-button-secondary" id="copy-hgm-shortcode">Copy Shortcode</button>
                    </div>
                    <p class="description">Copy and paste this shortcode on any page or post to load your form.</p>
                </td>
            </tr>
            <?php if (!empty($api_key)) : ?>
                <tr>
                    <th scope="row">
                        <label for="hgm_enable_klaviyo">Enable Klaviyo</label>
                    </th>
                    <td>
                        <input type="checkbox"
                               id="hgm_enable_klaviyo"
                               name="hgm_enable_klaviyo"
                               value="1"
                               <?php checked($klaviyo_enabled, 1); ?> />
                        <p class="description">Send this form’s leads to Klaviyo.</p>
                    </td>
                </tr>
                <tr class="hgm-klaviyo-list-row" style="<?php echo $klaviyo_enabled ? '' : 'display:none;'; ?>">
                    <th scope="row">
                        <label for="hgm_klaviyo_list_id">Klaviyo List ID</label>
                    </th>
                    <td>
                        <input type="text"
                               id="hgm_klaviyo_list_id"
                               name="hgm_klaviyo_list_id"
                               value="<?php echo esc_attr($klaviyo_list_id); ?>"
                               class="regular-text"
                               placeholder="e.g. XhDyw7" />
                        <p class="description">Enter the Klaviyo List ID to send leads to.</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const btn = document.getElementById("copy-hgm-shortcode");
        const code = document.getElementById("hgm-quote-shortcode");

        if (btn && code) {
            btn.addEventListener("click", function () {
                const text = code.innerText;
                navigator.clipboard.writeText(text).then(function () {
                    btn.innerText = "Copied!";
                    setTimeout(() => {
                        btn.innerText = "Copy Shortcode";
                    }, 1500);
                });
            });
        }
    });
</script>
<?php
}

add_action('admin_enqueue_scripts', 'hgm_enqueue_color_picker');
function hgm_enqueue_color_picker($hook_suffix) {
    // Only load on post.php (edit) or post-new.php (add new)
    if ($hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php') {
        return;
    }

    // Get post type safely
    $screen = get_current_screen();
    if (! $screen || $screen->post_type !== 'instant_quote_form') {
        return;
    }

    // Enqueue WP color picker assets
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script(
        'hgm-color-picker-init',
        plugin_dir_url(dirname(__FILE__)) . 'assets/color-picker-init.js',
        ['wp-color-picker'],
        false,
        true
    );
}

add_action('save_post_instant_quote_form', 'hgm_save_quote_form_button_color');
function hgm_save_quote_form_button_color($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['hgm_quote_button_color'])) {
        update_post_meta(
            $post_id,
            '_hgm_quote_button_color',
            sanitize_hex_color($_POST['hgm_quote_button_color'])
        );
    }

    if (isset($_POST['hgm_email_subject'])) {
        update_post_meta(
            $post_id,
            'hgm_email_subject',
            sanitize_text_field($_POST['hgm_email_subject'])
        );
    }
    // ======================
    // SAVE KLAVIYO SETTINGS
    // ======================

    // Save enable toggle
    $enabled = isset($_POST['hgm_enable_klaviyo']) ? 1 : 0;
    update_post_meta($post_id, 'hgm_enable_klaviyo', $enabled);

    // Save list ID
    if (isset($_POST['hgm_klaviyo_list_id'])) {
        update_post_meta(
            $post_id,
            'hgm_klaviyo_list_id',
            sanitize_text_field($_POST['hgm_klaviyo_list_id'])
        );
    }
}