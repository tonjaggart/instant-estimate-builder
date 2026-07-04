<?php
// Make sure this file is being included properly
if (!defined('ABSPATH')) exit;

// These variables should already be defined in the shortcode handler
// But we include fallback just in case this file is used directly
if (!isset($form_data)) {
    $form_id = isset($atts['id']) ? intval($atts['id']) : get_the_ID();
    $form_data = get_post_meta($form_id, '_hgm_form_data', true);
}

// Bail if no form data
if (empty($form_data) || !is_array($form_data)) {
    echo '<p>No estimate form data found for this form.</p>';
    return;
}

// Load saved color and log it
$button_color = get_post_meta($form_id, '_hgm_quote_button_color', true) ?: '#E84232';
?>
<style>
    :root {
        --bg-color: #F2EDEB;
        --primary: <?php echo esc_attr($button_color); ?>;
        --secondary: #f5f5f8;
        --white-color: #ffffff;
        --text-color: #16171A;
        --black-color: #000000;
    }

    body {
        /* background-color: var(--bg-color); */
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        letter-spacing: -0.01em;
        color:var(--text-color);
        overflow-x: hidden;
    }
    main.multi-section-form-holder .container {
        max-width: 1000px;
        width: 100%;
    }
    .custom-container-1 {
        max-width: 1700px;
    }
    .btn {
        background-color: var(--primary);
        margin-top: 20px;
        font-size: 18px;
        line-height: 30px;
        padding: 14px 48px;
        color: #ffffff;
        font-weight: 700;
        border-radius: 0;
        float: right;
        position: relative;
        border: 0;
        overflow: hidden;
        text-transform: uppercase;
        color: var(--white-color) !important;
        letter-spacing: -0.04em;
    }
    .btn-outline-primary:hover {
        background-color: transparent !important;
        border-color: transparent !important;
    }

    .btn-primary:focus {
        box-shadow: rgb(232, 66, 50) 0px 0px 0px 0.25rem;
        background-color: var(--primary);
        margin-top: 20px;
        font-size: 18px;
        line-height: 30px;
        padding: 14px 48px;
        color: #ffffff;
        font-weight: 700;
        border-radius: 0;
        float: right;
        position: relative;
        border: 0;
        overflow: hidden;
        text-transform: uppercase;
        color: var(--white-color) !important;
        letter-spacing: -0.04em;
    }
    /* BUTTON HOVER
    .btn::before {
    content: "";
    width: 100%;
    position: absolute;
    height: 100%;
    background-color: var(--black-color);
    left: -100%;
    top: 0;
    z-index: 0;
    transition: all 500ms ease-in-out
    }

    .btn:hover::before {
    left: 100%;
    }

    .btn span {
    position: relative;
    }
    */
    button.prev-btn,
    button.prev-btn span svg path{
        transition: 0.3s ease-in-out;
        letter-spacing: -0.04em;
    }

    button.prev-btn:hover {
        color: var(--primary);
    }

    button.prev-btn:hover span svg path {
        stroke: var(--primary);
    }

    /* header */

    .tp-header-transparent {
        position: relative;
    }

    .tp-header-top-left-box ul {
        margin: 0;
        padding: 0;
    }

    header .tp-header-area .row > [class*='col-']:nth-child(2) {
        height: 82px;
    }

    footer .pt-160{
        padding-top: 60px;
    }

    .tp-copyright-left p{
        margin-top: 0 !important;s
    }

    /* Multi Step Form */
    #multiStepForm {
        margin-left: 10px;
    }

    .multi-section-form-holder {
        justify-content: center;
        align-items: center;
        height: 100%;
        display: flex;
        flex-wrap: wrap;
    }

    .multi-step-form {
        width: 100%;
        max-width: 1031px;
        margin: auto;
        padding: 40px 33px;
        background-color: var(--white-color);
        box-shadow: 0 0 100px #0000000f;
    }

    .step-title {
        font-size: 45px;
        font-weight: 800;
        line-height: 54px;
        white-space: nowrap;
        letter-spacing: -0.02em;
        color: var(--black-color);
    }

    .step-title sup {
        background-color: var(--primary);
        color: var(--white-color);
        font-size: 10px;
        line-height: 17px;
        width: 16px;
        height: 16px;
        display: inline-block;
        border-radius: 50%;
        text-align: center;
        position: relative;
        top: -34px;
        left: -14px;
        transition: 0.3s ease-in-out;
    }

    .step-title sup span {
        font-size: 0;
        display: none;
        white-space: normal;
    }

    .step-title sup:hover span {
        position: absolute;
        font-size: 14px;
        letter-spacing: 0px;
        line-height: 22px;
        font-weight: normal;
        text-align: center;
        padding: 15px;
        bottom: 38px;
        width: 290px;
        background-color: var(--white-color);
        box-shadow: 0px 18px 48px 0px #16171A14;
        margin: auto;
        transform: translate(-47%, 0);
        color: var(--black-color);
        display: block;
        z-index: 9;
    }

    .step-title sup span::after {
        content: "";
        position: absolute;
        border-width: 5px;
        border-color: var(--white-color) var(--white-color) transparent transparent;
        border-style: solid;
        transform: rotate(135deg);
        bottom: -5px;
        left: 0;
        right: 0;
        width: fit-content;
        margin: auto;
    }

    .option-title {
        font-size: 24px;
        line-height: 34px;
        font-weight: 800;
        margin-top: 15px;
        margin-bottom: 18px;
        color: var(--color-black);
    }

    form label {
        font-weight: 800;
    }

    .card-option {
        margin-block: 34px;
        display: flex;
        flex-wrap: wrap;
        gap: 42px;
    }

    .card-option .item {
        width: 171px;
        padding: 34px 8px;
        transition: 0.3s ease-in-out;
        position: relative;
    }

    .card-option .item:hover,
    .card-option .item:has(input:checked) {
        box-shadow: 0px 18px 48px 0px #16171A14;
    }

    .card-option .item input {
        opacity: 0;
        position: absolute;
        z-index: 1;
        left: 0;
        right: 0;
        top: 0;
        bottom: 0;
        width: 100%;
        cursor: pointer;
    }

    .card-option .item input~span {
        width: 20px;
        height: 20px;
        border: 2px solid var(--black-color);
        display: block;
        border-radius: 50%;
        margin: auto;
        position: relative;
    }
    .btn-group.card-option .item input~span {
        margin: unset;
    }
    .btn-group.card-option .item {
        justify-content: flex-start;
        width: fit-content;
        padding: 0;
    }
    .card-option .item input:checked~span {
        border-color: var(--primary);
        border-width: 6px;
    }

    .card-option .item:has(input:checked) svg path {
        fill: var(--primary);
    }

    .card-option .item .card-img-holder,
    .card-option .item .card-img-holder svg {
        position: relative;
    }

    .card-option .item .card-img-holder::before {
        content: "";
        width: 54px;
        height: 54px;
        position: absolute;
        background-color: var(--secondary);
        border-radius: 50%;
        bottom: 3px;
        left: 20px;
        right: 0;
        margin: auto;
    }

    .form-step p {
        font-size: 18px;
        line-height: 28px;
        margin-bottom: 20px;
        margin-top: 20px;
        letter-spacing: -0.01em;
        font-weight: 400;
        color: #6b7280;
    }
    .form-step {
        display: none;
    }

    .form-step.active {
        display: block;
    }

    .required {
        color: var(--primary);
    }

    .form-step label {
        font-size: 16px;
        line-height: 24px;
        margin-top: 14px;
        margin-bottom: 18px;
        color: var(--text-color);
        letter-spacing: -0.01em;
        width: 100%;
    }

    .form-step input.bg-style {
        font-size: 15px;
        line-height: 54px;
        border: 0;
        background-color: var(--secondary);
        border-radius: 0;
        padding: 0 25px;
    }

    .form-step input:focus {
        box-shadow: unset;
        background-color: var(--secondary);
    }

    .form-step input::placeholder {
        color: #727272;
    }

    .prev-btn {
        font-size: 18px;
        line-height: 30px;
        font-weight: 700;
        text-transform: uppercase;
        border: 0;
        background-color: unset;
        margin-top: 34px;
        letter-spacing: -0.04em;
        color: var(--text-color);
    }

    .prev-btn span {
        margin-right: 12.89px;
        text-transform: uppercase;
    }

    span.select2-selection.select2-selection--single {
        background-color: var(--secondary);
        border: 0;
        padding-inline: 25px;
        font-size: 15px;
        line-height: 54px;
        border-radius: 0;
        display: block;
        height: 59px;
    }
    span.select2-selection.select2-selection--single span{
        padding: 0 !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered{
        padding-inline: 25px;
        font-size: 15px;
        line-height: 59px;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        right: 20px;
        top: -10px;
        bottom: 0;
        margin-block: auto;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow b {
        width: 10px;
        height: 10px;
        border: 1px solid #727272;
        transform: rotate(45deg);
        border-left: 0;
        border-top: 0;
    }

    .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
        width: 10px;
        height: 10px;
        border: 1px solid #727272;
        transform: rotate(-135deg);
        border-left: 0;
        border-top: 0;
        top:18px;
    }
    .select2-container {
        width: 100%;
        display: block;
    }
    .select2-search--dropdown {
        display: none;
    }

    .form-step select:focus {
        box-shadow: unset;
    }
    .btn-group {
        margin-bottom: 20px;
    }
    .btn-group .item {
        width: unset;
        padding: unset;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: unset;
        box-shadow: unset;
    }
    .btn-group .item label {
        padding: 0px 0px 0px 35px;
        margin: 0px 0px;
        width: auto;
        text-align: left;
        position: relative;
        right: 20px;
        cursor: pointer;
        color: var(--black-color);
    }
    .form-step .mb-3 {
        margin-bottom: 34px !important;
    }
    .select2-dropdown {
        box-shadow: 0px 8px 34px 0px #16171A0A;
        padding: 8px;
        border: 0.75px solid #D7D7D7 !important;
        border-radius: 4px !important;
        margin-top:4px;
    }
    .select2-container--default .select2-results>.select2-results__options {
        max-height: unset;
    }
    .select2-results__option--selectable {
        color: #727272;
        font-size: 15px;
        line-height: 25px;
        letter-spacing: -0.01em;
        padding: 8px 14px;
    }
    .select2-container--default .select2-results__option--selected,
    .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
        background-color: unset;
        color: var(--text-color);
        font-weight: 600;
    }
    .nice-select::after{
        display: none;
    }
    .nice-select.open .list{
        width: 100%;
        border-radius: 0px !important;
    }

    .nice-select .option:hover,
    .nice-select .option.selected {
        /*background-color: #16171A; */
        color: #16171A;
        font-weight: 700;
    }

    .form-select:focus{
        box-shadow: unset;
    }
    select.error,
    input.error {
        border: 2px solid #dc3545 !important;
    }

    @media screen and (max-width: 1399.98px) {
        .tp-header-area .tp-header-logo img {
            max-width: 220px;
        }
    }

    @media (max-width: 991.98px){
        .multi-section-form-holder {
            padding: 64px 24px;
        }
        #multiStepForm {
            margin-left: 20px;
        }
        .card-option {
            gap: 22px;
        }
        .card-option .item {
            width: 150px;
            padding: 26px 8px;
        }
        .option-title {
            font-size: 20px;
            letter-spacing: -0.04em;
        }
        .form-step p {
            font-size: 16px;
            line-height: 24px;
        }
        .step-title {
            font-size: 34px;
            line-height: 42px;
        }
        .step-title sup {
            top: -13px;
            left: 0px;
        }
        .form-step .mb-3 {
            margin-bottom: 30px !important;
        }
    }

    @media (max-width: 799.98px){

        #step10 .prev-btn {
            margin-inline: auto;
            display: block;
            margin-bottom: 15px;
            margin-top: 30px;
        }

        .multi-step-form button.btn.btn-success {
            margin-inline: auto;
            float: unset;
            display: block;
        }


    }

    @media (max-width: 767.98px){
        .multi-section-form-holder {
            height: auto;
        }
        .multi-step-form .img-holder {
            height: 197px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .multi-step-form .img-holder img.img-fluid {
            width: 100%;
        }
        .multi-section-form-holder .container{
            padding: 0;
        }
        .multi-step-form {
            padding: 30px;
            display: flex;
            justify-content: center;
        }
        .multi-step-form [class*="col-"] {
            padding: 0;
        }
        #multiStepForm {
            margin-left: 0;
        }
        .card-option {
            margin-block: 30px;
            gap: unset;
            justify-content: space-between;
        }
        .card-option .item {
            width: 45.49%;
            padding: 34px 8px;
        }

        .btn-group.card-option {
            margin-bottom: 16px;
            justify-content: flex-start;
        }
        .btn {
            padding: 14px 48px;
        }
        .btn.btn-success {
            padding: 14px 30px;
        }
        .form-step .row {
            margin: 0;
        }
        .step-title sup span,
        .step-title sup:hover span{
            text-align: left;
        }
        button.prev-btn{
            padding:0;
        }
        .prev-btn span{
            margin-right: 7.5px;
        }
    }
    @media(max-width: 479.98px){
        .step-title {
            font-size: 24px;
            line-height: 30px;
        }
        .multi-section-form-holder {
            padding: 32px 12px;
        }
        .multi-step-form {
            padding: 20px;
        }
        .option-title {
            font-size: 16px;
            margin-block: 10px;
        }
        .card-option .item {
            padding: 30px 8px 20px;
        }
        .btn {
            margin-top: 10px;
            font-size: 16px;
            padding: 8px 32px;
        }
        .prev-btn {
            font-size: 16px;
            margin-top: 20px;
        }
        .prev-btn span{
            margin-right: 4px;
        }
        .prev-btn span svg {
            width: 16px;
        }
        .btn-success {
            margin-inline: auto;
            float: unset;
            display: block;
        }

        .step-title sup:hover span {
            transform: translate(-56%, 0);
        }
        .step-title sup span::after {
            left: 20%;
        }
    }

</style>

<main class="multi-section-form-holder">
    <div class="container">
        <div class="multi-step-form">
            <div class="row w-100">
                <div class="col-md-5">
                    <div class="img-holder">
                        <img
                             class="img-fluid step-image"
                             src=""
                             alt=""
                             >
                    </div>
                </div>
                <div class="col-md-7">
                    <form id="multiStepForm" method="post" action="" novalidate>
                        <?php $has_steps = !empty($form_data) && is_array($form_data); ?>
                        <?php $total_steps = $has_steps ? count($form_data) + 1 : 1; ?>

                        <?php if ($has_steps): ?>
                        <?php foreach ($form_data as $index => $step): ?>
                        <?php
                        $step_id = $index + 1;
                        $type = $step['type'] ?? '';
                        $title = $step['title'] ?? '';
                        $subtitle = $step['subtitle'] ?? '';
                        $tooltip = $step['tooltip'] ?? '';
                        $options = $step['options'] ?? [];
                        $image = $step['image'] ?? '';
                        $is_first = $index === 0;
                        ?>
                        <div class="form-step<?php echo $is_first ? ' active' : ''; ?>"
                             id="step<?php echo esc_attr($step_id); ?>"
                             data-step-image="<?php echo esc_url($image); ?>"
                             data-step-title="<?php echo esc_attr($title); ?>">
                            <div class="row">
                                <div class="form-inner">
                                    <h2 class="step-title">
                                        <?php echo esc_html($step_id); ?>. <?php echo esc_html($title); ?>
                                        <?php if (!empty($tooltip)): ?>
                                        <sup>?<span><?php echo esc_html($tooltip); ?></span></sup>
                                        <?php endif; ?>
                                    </h2>

                                    <?php if (!empty($subtitle)): ?>
                                    <p class="step-subtitle"><?php echo esc_html($subtitle); ?></p>
                                    <?php endif; ?>

                                    <div class="form-group">
                                        <?php if (!empty($step['boldLabel'])): ?>
                                        <label><?php echo wp_kses_post($step['boldLabel']); ?></label>
                                        <?php endif; ?>

                                        <?php if ($type === 'dropdown'): ?>
                                        <select class="quiz-select-dropdown form-select" aria-label="Default select example" name="step_<?php echo esc_attr($step_id); ?>">
                                            <option value="">Select an option</option>
                                            <?php foreach ($options as $opt): ?>
                                            <option
                                                    value="<?php echo esc_attr($opt['label']); ?>"
                                                    data-min="<?php echo esc_attr($opt['low'] ?? 0); ?>"
                                                    data-max="<?php echo esc_attr($opt['high'] ?? 0); ?>">
                                                <?php echo esc_html($opt['label']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <?php elseif ($type === 'radio'): ?>
                                        <div class="btn-group card-option" role="group">
                                            <?php foreach ($options as $i => $opt):
                                            $opt_id = 'step' . $step_id . '_option' . $i;
                                            ?>
                                            <div class="item">
                                                <input
                                                       type="radio"
                                                       class="btn-check"
                                                       name="step_<?php echo esc_attr($step_id); ?>"
                                                       id="<?php echo esc_attr($opt_id); ?>"
                                                       autocomplete="off"
                                                       value="<?php echo esc_attr($opt['label']); ?>"
                                                       data-min="<?php echo esc_attr($opt['low'] ?? 0); ?>"
                                                       data-max="<?php echo esc_attr($opt['high'] ?? 0); ?>">
                                                <span for="<?php echo esc_attr($opt_id); ?>"></span>
                                                <label class="btn-outline-primary" for="<?php echo esc_attr($opt_id); ?>">
                                                    <?php echo esc_html($opt['label']); ?>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-nav">
                                        <?php if ($index > 0): ?>
                                        <button class="prev-btn">
                                            <span>
                                                <svg width="24" height="13" viewBox="0 0 24 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M7.40332 12.5L1.40332 6.5L7.40332 0.5M2.05243 6.5H23.1051"
                                                          stroke="black" stroke-miterlimit="10" />
                                                </svg>
                                            </span>
                                            Previous
                                        </button>
                                        <?php endif; ?>

                                        <?php if ($index < count($form_data)): ?>
                                        <button type="button" class="btn btn-primary hgm-next-btn">Next</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="form-step" id="step<?php echo esc_attr($total_steps); ?>">
                            <h2 class="step-title"><?php echo esc_html($total_steps); ?>. Send Estimate
                                <sup>?
                                    <span>
                                        This will help us get you the information you requested. Please watch your spam folders as sometimes the quotes are intercepted.
                                    </span>
                                </sup>
                            </h2>
                            <p>Where should we send your estimate?</p>
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="first_name">First Name<span class="required">*</span></label>
                                        <input type="text" class="form-control bg-style" id="first_name" name="first_name" placeholder="First Name">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="email">Email<span class="required">*</span></label>
                                        <input type="email" class="form-control bg-style" id="email" name="email" placeholder="Email">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="phone">Phone<span class="required">*</span></label>
                                        <input type="text" class="form-control bg-style" id="phone" name="phone" placeholder="Phone">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="zip_code">Zip Code<span class="required">*</span></label>
                                        <input type="text" class="form-control bg-style" id="zip_code" name="zip_code" placeholder="Zip Code">
                                    </div>
                                </div>
                            </div>

                            <?php wp_nonce_field('hgm_quote_nonce'); ?>

                            <button class="prev-btn">
                                <span>
                                    <svg width="24" height="13" viewBox="0 0 24 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M7.40332 12.5L1.40332 6.5L7.40332 0.5M2.05243 6.5H23.1051" stroke="black" stroke-miterlimit="10" />
                                    </svg>
                                </span>
                                Previous
                            </button>
                            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
                            <button type="submit" name="hgm_quote_submit" class="btn">Send My Estimate</button>
                        </div>
                        <input type="hidden" name="_hgm_form_data" id="hgmFormData" />
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    jQuery(document).ready(function($) {
        var currentStep = 0;

        // Show the first step
        var steps = $('.form-step');
        steps.hide().eq(currentStep).addClass('active').show();

        // Handle Next button
        $(document).on('click', '.hgm-next-btn', function() {
            steps = $('.form-step'); // Re-select in case new steps were added
            steps.eq(currentStep).removeClass('active').hide();
            currentStep++;
            steps.eq(currentStep).addClass('active').show();
        });

        // Handle Previous button
        $(document).on('click', '.prev-btn', function() {
            steps = $('.form-step');
            steps.eq(currentStep).removeClass('active').hide();
            currentStep--;
            steps.eq(currentStep).addClass('active').show();
        });

    });
    document.addEventListener('DOMContentLoaded', function () {
        const steps = document.querySelectorAll('.form-step');
        const stepImage = document.querySelector('.step-image');

        function updateStepImage(stepElement) {
            const newImage = stepElement.getAttribute('data-step-image');
            const newAlt = stepElement.getAttribute('data-step-title');
            if (newImage && stepImage) {
                stepImage.src = newImage;
                stepImage.alt = newAlt || '';
            }
        }

        // Initial image
        const firstVisibleStep = document.querySelector('.form-step.active') || steps[0];
        if (firstVisibleStep) updateStepImage(firstVisibleStep);

        // Handle next/prev button clicks
        document.querySelectorAll('.hgm-next-btn, .prev-btn').forEach(button => {
            button.addEventListener('click', function () {
                setTimeout(() => {
                    const activeStep = document.querySelector('.form-step.active');
                    if (activeStep) updateStepImage(activeStep);
                }, 50); // Small delay to allow DOM update
            });
        });
    });

</script>
<script>
    /*jQuery(document).ready(function($) {

            function handleOtherOption(selectBox) {
                let newOption = prompt("Please enter the new option:");
                if (newOption) {
                    // Add the new option to the original select box
                    $(selectBox).append(new Option(newOption, newOption.toLowerCase()));
                    // Refresh Nice Select to recognize the new option
                    $(selectBox).niceSelect('update');
                    // Select the new option
                    $(selectBox).val(newOption.toLowerCase()).niceSelect('update');
                } else {
                    // Revert to the default option if no input was provided
                    $(selectBox).val('').niceSelect('update');
                }
            }

            // Attach event handler to the Nice Select custom HTML
            $(document).on('click', '.nice-select ul li', function() {
                var $originalSelect = $(this).closest('.nice-select').prev('select');
                if ($(this).data('value') === 'other') {
                    handleOtherOption($originalSelect);
                }
            });
        });*/

    jQuery(document).ready(function($) {
        //$('select').niceSelect();

        function handleOtherOption(selectBox, listItem) {
            var otherInput = $('<input type="text" class="other-input" placeholder="Enter new option">');
            listItem.replaceWith(otherInput);
            otherInput.focus();


            otherInput.on('blur keydown', function(e) {
                if (e.type === 'blur' || (e.type === 'keydown' && e.key === 'Enter')) {
                    var newOption = otherInput.val();
                    if (newOption) {
                        // Add the new option to the original select box
                        $(selectBox).append(new Option(newOption, newOption.toLowerCase()));
                        // Refresh Nice Select to recognize the new option
                        $(selectBox).niceSelect('update');
                        // Select the new option
                        $(selectBox).val(newOption.toLowerCase()).niceSelect('update');
                        listItem.closest('.nice-select').removeClass('open');
                    } else {
                        // Revert to the default option if no input was provided
                        $(selectBox).val('').niceSelect('update');
                    }
                }
            });
        }

        // Attach event handler to the Nice Select custom HTML
        $(document).on('click', '.nice-select ul li', function(e) {
            var $originalSelect = $(this).closest('.nice-select').prev('select');
            if ($(this).data('value') === 'other') {
                e.stopPropagation();
                handleOtherOption($originalSelect, $(this));
            }
        });
    });
</script>
