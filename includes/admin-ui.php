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

function hgm_render_quote_form_edit_screen()
{
    global $post;

    wp_nonce_field("hgm_save_form_data", "hgm_form_data_nonce");

    $form_data = get_post_meta($post->ID, "_hgm_form_data", true);

    if (!is_array($form_data)) {
        $decoded = json_decode($form_data, true);
        $form_data = is_array($decoded) ? $decoded : [];
    }

    $encoded_data = wp_json_encode($form_data);

    echo "<script>window.hgm_quote_questions = " . $encoded_data . ";</script>";

    echo '<div id="hgm-form-builder">';
    echo '<h3 style="margin-bottom: 10px;">Build Your Instant Estimate Form</h3>';
    echo "<p><em>Click “Add a Question” to begin building your instant estimate form.</em></p>";
    echo '<div id="hgm-steps-container"></div>';

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
        $data = json_decode(stripslashes($_POST["hgm_form_data_json"]), true);
        if (is_array($data)) {
            update_post_meta($post_id, "_hgm_form_data", $data);
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
    // ✅ Make sure this is its own statement with a semicolon
    wp_enqueue_media();

    wp_enqueue_script(
        "cropper-js",
        "https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js",
        [],
        null,
        true
    );
    wp_enqueue_style(
        "cropper-css",
        "https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css",
        [],
        null
    );

    // Correct paths to assets folder at plugin root
    wp_enqueue_script(
        "hgm-admin-js",
        plugin_dir_url(dirname(__FILE__)) . "assets/admin.js",
        ["jquery"],
        filemtime(plugin_dir_path(dirname(__FILE__)) . "assets/admin.js"),
        true
    );
    wp_enqueue_style("hgm-admin-css", plugin_dir_url(dirname(__FILE__)) . "assets/admin.css");
}
add_action("admin_enqueue_scripts", "hgm_enqueue_admin_assets");

add_action("wp_ajax_hgm_upload_cropped_image", "hgm_upload_cropped_image");
function hgm_upload_cropped_image()
{
    if (!current_user_can("upload_files") || !isset($_FILES["file"]) || !function_exists("wp_handle_sideload")) {
        wp_send_json_error(["message" => "Unauthorized or missing file."]);
    }

    $file = $_FILES["file"];

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
        $json = wp_unslash($_POST["hgm_form_data_json"]);
        update_post_meta($post_id, "hgm_quote_questions", $json);
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
        "View Lead Details", // Page title
        "View Lead Details", // Menu title (unused here)
        "edit_posts", // Capability (same as for your CPT)
        "hgm_view_lead", // Page slug
        "hgm_render_view_lead_screen" // Callback function
    );
});

function hgm_render_view_lead_screen()
{
    error_log("Plugin page: " . ($_GET["page"] ?? "none"));
    echo '<div style="margin: 20px 0 20px;">';
    echo '<a href="' .
        esc_url(admin_url("edit.php?post_type=hgm_lead")) .
        '" class="button button-primary">&larr; Back to All Leads</a>';
    echo "</div>";
    if (!current_user_can("edit_posts")) {
        wp_die(__("You are not allowed to access this page."));
    }

    $lead_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

    if (!$lead_id) {
        echo '<div class="notice notice-error"><p>Invalid lead ID.</p></div>';
        return;
    }

    $post = get_post($lead_id);
    if (!$post || $post->post_type !== "hgm_lead") {
        echo '<div class="notice notice-error"><p>Lead not found.</p></div>';
        return;
    }

    $email = get_post_meta($lead_id, "email", true);
    $phone = get_post_meta($lead_id, "phone", true);
    $zip = get_post_meta($lead_id, "zip_code", true);
    $estimate =
        get_post_meta($lead_id, "_hgm_estimate_low", true) .
        " – " .
        get_post_meta($lead_id, "_hgm_estimate_high", true);

    echo '<div class="wrap">';
    echo "<h1>Lead Details</h1>";

    echo "<h2>Summary</h2>";
    echo '<table class="widefat fixed striped">';
    echo "<thead><tr>";
    echo "<th>Name</th>";
    echo "<th>Email</th>";
    echo "<th>Phone</th>";
    echo "<th>Zip Code</th>";
    echo "<th>Estimate</th>";
    echo "</tr></thead>";
    echo "<tbody><tr>";
    echo "<td>" . esc_html($post->post_title) . "</td>";
    echo "<td>" . esc_html($email) . "</td>";
    echo "<td>" . esc_html($phone) . "</td>";
    echo "<td>" . esc_html($zip) . "</td>";
    echo "<td>" . esc_html($estimate) . "</td>";
    echo "</tr></tbody>";
    echo "</table>";

    echo '<div class="hgm-flex-row" style="display:flex; gap: 20px;">';

    $form_data_raw = get_post_meta($lead_id, "_hgm_form_data", true);

    $form_data = is_string($form_data_raw) ? json_decode($form_data_raw, true) : $form_data_raw;

    // ✅ Left column: Answers table
    echo '<div style="width:50%;">';
    echo '<h2 style="margin-bottom:0px;">Answers</h2><p style="margin-top:0px;">All of the answers the lead provided.</p>';
    echo '<table class="widefat fixed striped">';
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
    echo "</div>"; // End left column

    // ✅ Right column: Notes textarea with styles
    echo '<div style="width: 50%; float: right;">';
    echo '<h2 style="margin-bottom:0px;">Internal Notes <span id="hgm-save-status" style="margin-left: 10px; font-size: 14px; color: green;"></span></h2><p style="margin-top:0px;">⚠️ Notes save automatically in a couple of seconds after you stop typing.</p>';

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
notes: notes
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
    if (!current_user_can("edit_posts")) {
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

    $shortcode = '[instant_quote_form id="' . esc_attr($form_id) . '"]';
    $button_color = get_post_meta($post->ID, '_hgm_quote_button_color', true) ?: '#013c55'; ?>
                         <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0;">
<h3>Form Settings</h3>
<table class="form-table" role="presentation">
    <tbody>
        <tr>
            <th scope="row">
                <label for="hgm_quote_button_color">Form Primary Color:</label>
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
        <?php $subject = get_post_meta($post->ID, 'hgm_email_subject', true);?>
        <tr>
            <th scope="row">
                <label for="hgm_email_subject">Email Subject Line:</label>
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
            <th scope="row">Embed the Estimate Form:</th>
            <td>
                <code id="hgm-quote-shortcode"><?php echo esc_html($shortcode); ?></code>
                <button type="button" class="button" id="copy-hgm-shortcode" style="margin-left: 10px;">Copy Shortcode</button>
                <p class="description">Copy and paste this shortcode on any page or post to load your form.</p>
            </td>
        </tr>
        <?php
    // ======================
    // KLAVIYO SETTINGS (FORM LEVEL)
    // ======================

    // Get saved values
    $klaviyo_enabled = get_post_meta($post->ID, 'hgm_enable_klaviyo', true);
    $klaviyo_list_id = get_post_meta($post->ID, 'hgm_klaviyo_list_id', true);

    // Only show if API key exists
    $api_key = get_option('hgm_klaviyo_api_key');
        ?>

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
                <p class="description">Send this form’s leads to Klaviyo</p>
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
                <p class="description">Enter the Klaviyo List ID to send leads to</p>
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
        plugins_url('assets/color-picker-init.js', dirname(__FILE__, 2) . '/hvac-quote-generator.php'),
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