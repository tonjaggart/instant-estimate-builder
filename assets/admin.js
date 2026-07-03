jQuery(document).ready(function($) {

    // Enforce numeric-only typing for price fields (delegated so it works on new rows too)
    $(document).on('input', '.hgm-answer-low, .hgm-answer-high', function () {
        // keep digits and a single dot
        let v = $(this).val().replace(/[^\d.]/g, '');
        const firstDot = v.indexOf('.');
        if (firstDot !== -1) {
            v = v.substring(0, firstDot + 1) + v.substring(firstDot + 1).replace(/\./g, '');
        }
        $(this).val(v);
    });

    // Final clean on blur (remove leading dots/zeros like ".5" -> "0.5", "00" -> "0")
    $(document).on('blur', '.hgm-answer-low, .hgm-answer-high', function () {
        let v = $(this).val().replace(/[^\d.]/g, '');
        if (v.startsWith('.')) v = '0' + v;
        if (v === '') v = '0';
        // trim to at most 2 decimals if you like:
        const m = v.match(/^\d+(?:\.(\d{0,2}))?/);
        if (m) v = m[0];
        $(this).val(v);
    });

    try {
        $('.hgm-quote-form-color, .hgm-color-field').wpColorPicker();
    } catch (e) {
        console.warn('wpColorPicker init failed:', e);
    }

    (function ($) {
        function initPickers(context) {
            $(context)
                .find('.hgm-quote-form-color, .hgm-color-field')
                .not('.wp-color-picker')
                .wpColorPicker();
        }

        // run on DOM ready
        $(document).ready(function () { initPickers(document); });

        // run after full window load (late meta boxes)
        $(window).on('load', function () {
            initPickers(document);
        });

        // run when classic meta boxes are ajax-loaded by Gutenberg
        $(document).ajaxComplete(function (e, xhr, settings) {
            if (settings && settings.data && String(settings.data).indexOf('action=meta-box-loader') !== -1) {
                initPickers(document);
            }
        });
    })(jQuery);

    // ✅ Remove answer button handler (delegated)
    $('#hgm-steps-container').on('click', '.hgm-remove-answer', function(e) {
        e.preventDefault();
        $(this).closest('.hgm-answer-row').remove();
    });

    let stepCount = 0;
    const stepsContainer = $('#hgm-steps-container');

    // ✅ Define renderSteps and call it if questions exist
    function renderSteps(steps = []) {
        stepsContainer.empty();
        steps.forEach((step) => addStep(step));
    }
    window.renderSteps = renderSteps;

    if (typeof window.hgm_quote_questions !== 'undefined' && Array.isArray(window.hgm_quote_questions)) {
        renderSteps(window.hgm_quote_questions);
    }

    // ✅ Continue with your addStep() function below
    function addStep(data = {}) {
        stepCount++;
        const stepId = 'hgm-step-' + stepCount;

        let answerCount = (data.answers && data.answers.length) || 0;

        const optionsHTML = (data.options || []).map((opt, i) => `
          <div class="hgm-answer-row">
              <div class="hgm-price-input">
                  <label>Answer Options</label>
                  <input type="text" class="hgm-answer-label" placeholder="Answer" value="${opt.label || ''}" />
              </div>
              <div class="hgm-price-input">
                  <label for="hgm-low-${stepCount}-${i}">Low $ Amount</label>
                  <div class="hgm-dollar-wrapper">
                      <span class="dollar-sign">$</span>
                      <input type="text" id="hgm-low-${stepCount}-${i}" class="hgm-answer-low" placeholder="e.g. 500" value="${opt.low || ''}" />
                  </div>
                  <small class="hgm-price-hint">Only numbers. No $ or commas allowed.</small>
              </div>
              <div class="hgm-price-input">
                  <label for="hgm-high-${stepCount}-${i}">High $ Amount</label>
                  <div class="hgm-dollar-wrapper">
                      <span class="dollar-sign">$</span>
                      <input type="text" id="hgm-high-${stepCount}-${i}" class="hgm-answer-high" placeholder="e.g. 2000" value="${opt.high || ''}" />
                  </div>
                  <small class="hgm-price-hint">Only numbers. No $ or commas allowed.</small>
              </div>
              <button class="button-link hgm-remove-answer">×</button>
          </div>
      `).join('');

        const step = $(`
          <div class="hgm-step-card" data-id="${stepId}">
              <div class="hgm-step-header">
                  <h4>Question ${stepCount}</h4>
                  <button type="button" class="button-link-delete hgm-remove-step">Remove Question</button>
              </div>

              <label>Question Title</label>
              <input type="text" class="hgm-step-title" value="${data.title || ''}" />

              <label>Question</label>
              <input type="text" class="hgm-step-subtitle" value="${data.subtitle || ''}" />

              <label>Bold Label Text (optional)</label>
              <small style="display:block; margin-bottom:5px; color:#666;">
                  This will describe the Answer they need to select or type. e.g. Square Footage
              </small>
              <input type="text" class="hgm-step-bold-label" value="${data.boldLabel || ''}" />

              <label>Input Type</label>
              <select class="hgm-step-type">
                  <option value="radio" ${data.type === 'radio' ? 'selected' : ''}>Radio (Multiple Options. One Answer)</option>
                  <option value="dropdown" ${data.type === 'dropdown' ? 'selected' : ''}>Dropdown</option>
                  <option value="text" ${data.type === 'text' ? 'selected' : ''}>Text Input</option>
              </select>

              <div class="hgm-answer-options" style="display: ${data.type === 'text' ? 'none' : 'block'};">
                  <label>Answer Options</label>
                  <div class="hgm-answers-wrapper">${optionsHTML}</div>
                  <button type="button" class="button button-secondary hgm-add-answer">+ Add Answer</button>
              </div>

              <label>Tooltip (optional)</label>
              <small style="display:block; margin-bottom:5px; color:#666;">
                  This icon appears next to the question and shows more information on how to answer when the user hovers over it.
              </small>
              <input type="text" class="hgm-step-tooltip" value="${data.tooltip || ''}" />

              <label>Question Image</label>
              <input type="text" class="hgm-step-image-url" value="${data.image || ''}" readonly />
              <button type="button" class="button hgm-upload-image">Upload Image</button>
              <button type="button" class="button hgm-choose-media">Choose Media</button>

              <div class="hgm-image-preview-wrapper" style="position: relative; display: inline-block; max-width: 150px; margin-top: 10px;">
                  <img src="${data.image || ''}" class="hgm-image-preview" style="${data.image ? '' : 'display:none;'} width: 100%;" />
                  <button type="button" class="hgm-image-remove-x" style="position: absolute; top: 2px; right: 2px; background: white; color: red; font-weight: bold; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; display: ${data.image ? 'block' : 'none'};">×</button>
              </div>
          </div>
      `);
        // Validation for Question Title maxlength 20
        $(document).on('input', '.hgm-step-title', function() {
            if ($(this).val().length > 20) {
                alert('Question Title cannot be longer than 20 characters.');
                $(this).val($(this).val().substring(0, 20));
            }
        });
        // Remove step handler
        step.find('.hgm-remove-step').on('click', function () {
            if (confirm('Are you sure you want to remove this question?')) {
                step.remove();
            }
        });

        // Add answer handler - increments answerCount for unique IDs
        step.find('.hgm-add-answer').on('click', function (e) {
            e.preventDefault();

            answerCount++;
            const newAnswerHTML = `
      <div class="hgm-answer-row">
          <div class="hgm-price-input">
              <label>Answer Options</label>
              <input type="text" class="hgm-answer-label" placeholder="Answer" />
          </div>
          <div class="hgm-price-input">
              <label for="hgm-low-${stepCount}-${answerCount}">Low $ Amount</label>
              <div class="hgm-dollar-wrapper">
                  <span class="dollar-sign">$</span>
                  <input type="text" id="hgm-low-${stepCount}-${answerCount}" class="hgm-answer-low" placeholder="e.g. 500" />
              </div>
              <small class="hgm-price-hint">Only numbers. No $ or commas allowed.</small>
          </div>
          <div class="hgm-price-input">
              <label for="hgm-high-${stepCount}-${answerCount}">High $ Amount</label>
              <div class="hgm-dollar-wrapper">
                  <span class="dollar-sign">$</span>
                  <input type="text" id="hgm-high-${stepCount}-${answerCount}" class="hgm-answer-high" placeholder="e.g. 2000" />
              </div>
              <small class="hgm-price-hint">Only numbers. No $ or commas allowed.</small>
          </div>
          <button class="button-link hgm-remove-answer">×</button>
      </div>
    `;

            step.find('.hgm-answers-wrapper').append(newAnswerHTML);
        });

        // Upload image handler
        step.find('.hgm-upload-image').on('click', function (e) {
            e.preventDefault();
            const fileInput = $('<input type="file" accept="image/*">').trigger('click');

            fileInput.on('change', function () {
                const file = this.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.onload = function (event) {
                    const image = new Image();
                    image.src = event.target.result;

                    image.onload = function () {
                        const modal = $(`
                          <div class="hgm-cropper-modal">
                              <div class="hgm-cropper-overlay">
                                  <div class="hgm-cropper-container">
                                      <img id="hgm-crop-image" src="${image.src}" />
                                      <div style="margin-top: 10px;">
                                          <button class="button button-primary" id="hgm-crop-confirm">Crop & Use Image</button>
                                          <button class="button" id="hgm-crop-cancel">Cancel</button>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      `).appendTo('body');

                        const cropper = new Cropper(document.getElementById('hgm-crop-image'), {
                            aspectRatio: 400 / 395,
                            viewMode: 1,
                            dragMode: 'move',
                            autoCropArea: 1,
                            cropBoxResizable: false,
                            cropBoxMovable: false,
                        });

                        $('#hgm-crop-cancel').on('click', () => modal.remove());
                        $('#hgm-crop-confirm').on('click', () => {
                            cropper.getCroppedCanvas({ width: 400, height: 395 }).toBlob((blob) => {
                                const formData = new FormData();
                                formData.append('file', blob, 'cropped.jpg');
                                formData.append('action', 'hgm_upload_cropped_image');
                                formData.append('nonce', window.hgmAdminSecurity?.uploadNonce || '');

                                $.ajax({
                                    url: ajaxurl,
                                    method: 'POST',
                                    data: formData,
                                    processData: false,
                                    contentType: false,
                                    success: function (response) {
                                        if (response.success && response.data.url) {
                                            step.find('.hgm-step-image-url').val(response.data.url);
                                            // Update and show the preview image
                                            const previewImg = step.find('.hgm-image-preview');
                                            previewImg.attr('src', response.data.url);
                                            previewImg.show();
                                            step.find('.hgm-image-remove-x').show();
                                            modal.remove();
                                        } else {
                                            alert('Upload failed.');
                                        }
                                    },
                                    error: function () {
                                        alert('Upload error.');
                                    }
                                });
                            }, 'image/png');
                        });
                    };
                };
                reader.readAsDataURL(file);
            });
        });

        // Choose media button handler
        step.find('.hgm-choose-media').on('click', function(e) {
            e.preventDefault();

            const mediaFrame = wp.media({
                title: 'Select Image from Media Library',
                button: {
                    text: 'Choose Image'
                },
                library: { type: 'image' }, // Only images
                multiple: false
            });

            mediaFrame.on('select', function() {
                const attachment = mediaFrame.state().get('selection').first().toJSON();
                step.find('.hgm-step-image-url').val(attachment.url);
                const previewImg = step.find('.hgm-image-preview');
                previewImg.attr('src', attachment.url);
                previewImg.show();
                step.find('.hgm-image-remove-x').show();
            });

            mediaFrame.open();
        });

        // Remove image with X button handler
        step.find('.hgm-image-remove-x').on('click', function(e) {
            e.preventDefault();
            step.find('.hgm-step-image-url').val('');
            step.find('.hgm-image-preview').hide().attr('src', '');
            $(this).hide();
        });

        // Clean numeric inputs on blur
        step.find('.hgm-answer-low, .hgm-answer-high').on('blur', function () {
            const clean = $(this).val().replace(/[^0-9.]/g, '');
            $(this).val(clean);
        });

        stepsContainer.append(step);
    }

    // Add new step button
    $('#hgm-add-step').on('click', () => addStep());



    // Hidden input to save JSON
    const saveField = $('<input type="hidden" name="hgm_form_data_json" id="hgm_form_data_json" />');
    $('form#post').append(saveField);

    // Serialize form data on submit
    $('form#post').on('submit', function () {
        const allSteps = [];

        $('.hgm-step-card').each(function () {
            const $step = $(this);
            const options = [];

            $step.find('.hgm-answer-row').each(function () {
                options.push({
                    label: $(this).find('.hgm-answer-label').val(),
                    low: parseFloat($(this).find('.hgm-answer-low').val()) || 0,
                    high: parseFloat($(this).find('.hgm-answer-high').val()) || 0
                });
            });

            allSteps.push({
                title: $step.find('.hgm-step-title').val(),
                subtitle: $step.find('.hgm-step-subtitle').val(),
                boldLabel: $step.find('.hgm-step-bold-label').val(),
                type: $step.find('.hgm-step-type').val(),
                tooltip: $step.find('.hgm-step-tooltip').val(),
                image: $step.find('.hgm-step-image-url').val(),
                options: options
            });
        });

        $('#hgm_form_data_json').val(JSON.stringify(allSteps));
    });

    // Upload logo button
    $('#hgm_upload_logo_button').on('click', function(e) {
        e.preventDefault();

        const custom_uploader = wp.media({
            title: 'Select Logo Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        custom_uploader.on('select', function() {
            const attachment = custom_uploader.state().get('selection').first().toJSON();
            $('#hgm_logo_url').val(attachment.url);
        });

        custom_uploader.open();
    });

});
jQuery(document).ready(function($) {
    function collectStepsFromUI() {
        const steps = [];

        $('.hgm-step-card').each(function () {
            const $step = $(this);
            const options = [];

            $step.find('.hgm-answer-row').each(function () {
                options.push({
                    label: $(this).find('.hgm-answer-label').val(),
                    low: parseFloat($(this).find('.hgm-answer-low').val()) || 0,
                    high: parseFloat($(this).find('.hgm-answer-high').val()) || 0
                });
            });

            steps.push({
                title: $step.find('.hgm-step-title').val(),
                subtitle: $step.find('.hgm-step-subtitle').val(),
                boldLabel: $step.find('.hgm-step-bold-label').val(),
                type: $step.find('.hgm-step-type').val(),
                tooltip: $step.find('.hgm-step-tooltip').val(),
                image: $step.find('.hgm-step-image-url').val(),
                options: options
            });
        });

        return steps;
    }
    $('#hgm-save-questions').on('click', function () {
        const stepsData = collectStepsFromUI();
        const encodedJson = JSON.stringify(stepsData);

        if (!stepsData || stepsData.length === 0) {
            alert('No steps found.');
            return;
        }

        $('#hgm_quote_questions_json').val(encodedJson);
        $('#post').submit();
    });
});
// Text Message Notifications
jQuery(document).ready(function ($) {
    let maxRecipients = 5;

    const carrierDomains = {
        verizon: '@vtext.com',
        att: '@txt.att.net',
        tmobile: '@tmomail.net',
        sprint: '@messaging.sprintpcs.com',
        uscellular: '@email.uscc.net',
    };

    function buildSmsAddress(phone, carrier) {
        const digits = phone.replace(/\D/g, '');
        const domain = carrierDomains[carrier] || '';
        return digits && domain ? `${digits}${domain}` : '';
    }

    $('#add-sms-recipient').on('click', function (e) {
        e.preventDefault();

        const $list = $('#sms-recipient-list');
        const count = $list.find('.sms-recipient-row').length;

        if (count >= maxRecipients) {
            alert('You can only add up to 5 recipients.');
            return;
        }

        const index = count;
        const newRow = $(`
            <div class="sms-recipient-row" style="margin-bottom: 10px;">
                <input type="text" name="hgm_notification_settings[sms_recipients][${index}][phone]" placeholder="Phone Number" class="regular-text" />
                <select name="hgm_notification_settings[sms_recipients][${index}][carrier]">
                    <option value="verizon">Verizon</option>
                    <option value="att">AT&T</option>
                    <option value="tmobile">T-Mobile</option>
                    <option value="sprint">Sprint</option>
                    <option value="uscellular">US Cellular</option>
                </select>
                <a href="#" class="remove-recipient">Remove</a>
            </div>
        `);
        $list.append(newRow);
    });

    $(document).on('input', 'input[name*="[phone]"]', function () {
        const digits = $(this).val().replace(/\D/g, '').substring(0, 10);
        $(this).val(digits);
    });

    // Safer targeting: only apply this to settings forms
    $('form[action="options.php"]').on('submit', function (e) {
        let valid = true;
        const fakeNumbers = ['0000000000', '1234567890'];

        $('input[name*="[phone]"]').each(function () {
            const phone = $(this).val().replace(/\D/g, '');
            if (phone.length !== 10 || fakeNumbers.includes(phone)) {
                alert('Please enter a valid 10-digit phone number. Fake numbers like 0000000000 or 1234567890 are not allowed.');
                $(this).focus();
                valid = false;
                return false;
            }
        });

        if (!valid) {
            e.preventDefault();
        }
    });

    $(document).on('click', '.remove-recipient', function (e) {
        e.preventDefault();
        $(this).closest('.sms-recipient-row').remove();

        $('#sms-recipient-list .sms-recipient-row').each(function (i) {
            $(this).find('input').attr('name', `hgm_notification_settings[sms_recipients][${i}][phone]`);
            $(this).find('select').attr('name', `hgm_notification_settings[sms_recipients][${i}][carrier]`);
        });
    });
    // ======================
    // KLAVIYO TOGGLE (QUOTE FORM)
    // ======================

    function toggleKlaviyoListField() {
        const isChecked = $('#hgm_enable_klaviyo').is(':checked');

        if ($('.hgm-klaviyo-list-row').length) {
            if (isChecked) {
                $('.hgm-klaviyo-list-row').show();
            } else {
                $('.hgm-klaviyo-list-row').hide();
            }
        }
    }

    // Run on page load
    toggleKlaviyoListField();

    // Run on checkbox change
    $(document).on('change', '#hgm_enable_klaviyo', function () {
        toggleKlaviyoListField();
    });
});