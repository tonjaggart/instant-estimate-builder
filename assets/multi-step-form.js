document.addEventListener('DOMContentLoaded', function () {

    jQuery(function($) {
        var currentStep = 0;
        var steps = $('#multiStepForm .form-step'); // ⬅ scoped

        // Show first step
        steps.hide().eq(currentStep).addClass('active').show();

        // Navigation
        $('#multiStepForm .hgm-next-btn').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const $currentStep = steps.eq(currentStep);
            let isValid = true;

            // Validate selects
            const $selects = $currentStep.find('select');
            $selects.each(function () {
                const selectedVal = $(this).val();
                if (!selectedVal || selectedVal === "") {
                    isValid = false;
                    $(this).addClass('border-danger');
                } else {
                    $(this).removeClass('border-danger');
                }
            });

            // ✅ Validate radio groups
            const $radioGroups = {};
            $currentStep.find('input[type="radio"]').each(function () {
                if (!$radioGroups[this.name]) {
                    $radioGroups[this.name] = [];
                }
                $radioGroups[this.name].push(this);
            });
            for (const groupName in $radioGroups) {
                const isChecked = $radioGroups[groupName].some(radio => radio.checked);
                if (!isChecked) {
                    isValid = false;
                    $($radioGroups[groupName][0]).closest('.form-group, .form-check').addClass('border-danger');
                } else {
                    $($radioGroups[groupName][0]).closest('.form-group, .form-check').removeClass('border-danger');
                }
            }

            // ✅ Validate text inputs
            $currentStep.find('input[type="text"]').each(function () {
                if (!$(this).val().trim()) {
                    isValid = false;
                    $(this).addClass('border-danger');
                } else {
                    $(this).removeClass('border-danger');
                }
            });

            // ✅ Only move forward if valid
            if (isValid) {
                $currentStep.find('.step-error-message').hide();
                steps.eq(currentStep).removeClass('active').hide();
                currentStep++;
                steps.eq(currentStep).addClass('active').show();
            } else {
                let $errorMsg = $currentStep.find('.step-error-message');
                if (!$errorMsg.length) {
                    $errorMsg = $('<div class="alert alert-danger step-error-message small py-1 px-2 mt-2" role="alert">Please complete all required fields before continuing.</div>');
                    const $label = $currentStep.find('label').first();
                    if ($label.length) {
                        $label.after($errorMsg);
                    } else {
                        $currentStep.append($errorMsg);
                    }
                } else {
                    $errorMsg.show();
                }
            }
        });

        $('#multiStepForm .prev-btn').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            if (currentStep > 0) {
                // Hide all steps to prevent multiple visible
                steps.removeClass('active').hide();

                currentStep--;

                const $newStep = steps.eq(currentStep);
                $newStep.addClass('active').show();
            } 
        });

        // Autoformat phone number input
        $('#multiStepForm input[name="phone"]').on('input', function () {
            let input = $(this).val().replace(/\D/g, ''); // Strip non-digits

            if (input.length > 10) input = input.slice(0, 10);

            if (input.length >= 6) {
                input = `(${input.slice(0, 3)}) ${input.slice(3, 6)}-${input.slice(6)}`;
            } else if (input.length >= 3) {
                input = `(${input.slice(0, 3)}) ${input.slice(3)}`;
            } else if (input.length > 0) {
                input = `(${input}`;
            }

            $(this).val(input);
        });

        // Inject selected options into hidden input before submit
        $('#multiStepForm').on('submit', async function (e) {
            console.log('🚀 Submit handler triggered');
            e.preventDefault();
            e.stopImmediatePropagation();

            const formData = {};
            let isValid = true;
            const $finalStep = $('#multiStepForm .form-step.active');

            // Clear old errors
            $finalStep.find('.border-danger').removeClass('border-danger');
            $finalStep.find('.field-error-message').remove();

            // Validate all visible required fields
            $finalStep.find('input[type="text"], input[type="email"], input[type="tel"]').each(function () {
                const $input = $(this);
                const value = $input.val().trim();

                if (!value) {
                    isValid = false;
                    $input.addClass('border-danger');
                    const $label = $input.closest('.form-group').find('label').first();
                    const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">This field is required.</div>');
                    $label.length ? $label.after($error) : $input.after($error);
                }
            });

            // First Name validation
            const $firstName = $finalStep.find('input[name="first_name"]');
            const firstNameVal = $firstName.val().trim();
            const nameRegex = /^[a-zA-Z'-]{2,30}$/;
            if (firstNameVal.length === 1) {
                isValid = false;
                $firstName.addClass('border-danger');
                const $label = $firstName.closest('.form-group').find('label').first();
                const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">First name must be at least 2 characters.</div>');
                $label.length ? $label.after($error) : $firstName.after($error);
            } else if (firstNameVal && !nameRegex.test(firstNameVal)) {
                isValid = false;
                $firstName.addClass('border-danger');
                const $label = $firstName.closest('.form-group').find('label').first();
                const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">Only letters, hyphens, and apostrophes are allowed.</div>');
                $label.length ? $label.after($error) : $firstName.after($error);
            }

            // Email validation
            const $email = $finalStep.find('input[name="email"]');
            const emailVal = $email.val().trim();
            const emailFormat = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const blockedEmails = ['test@test.com', 'example@example.com', 'abc@abc.com', 'noreply@noreply.com'];
            if (emailVal) {
                if (!emailFormat.test(emailVal)) {
                    isValid = false;
                    $email.addClass('border-danger');
                    const $label = $email.closest('.form-group').find('label').first();
                    const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">Please enter a valid email address.</div>');
                    $label.length ? $label.after($error) : $email.after($error);
                } else if (blockedEmails.includes(emailVal.toLowerCase())) {
                    isValid = false;
                    $email.addClass('border-danger');
                    const $label = $email.closest('.form-group').find('label').first();
                    const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">That email address is not allowed. Please enter your real email.</div>');
                    $label.length ? $label.after($error) : $email.after($error);
                }
            }

            // Phone validation
            const $phone = $finalStep.find('input[name="phone"]');
            const rawPhone = $phone.val().replace(/\D/g, '');
            if (!rawPhone || rawPhone.length !== 10 || /^(1234567890|0000000000)$/.test(rawPhone)) {
                isValid = false;
                $phone.addClass('border-danger');
                const $label = $phone.closest('.form-group').find('label').first();
                const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">Please enter a valid 10-digit phone number.</div>');
                $label.length ? $label.after($error) : $phone.after($error);
            }

            // ZIP code validation
            const $zip = $finalStep.find('input[name="zip_code"]');
            const zipVal = $zip.val().trim();
            const zipRegex = /^\d{5}$/;
            if (!zipRegex.test(zipVal) || zipVal === '00000') {
                isValid = false;
                $zip.addClass('border-danger');
                const $label = $zip.closest('.form-group').find('label').first();
                const $error = $('<div class="alert alert-danger small py-1 px-2 mt-1 field-error-message" role="alert">Please enter a valid 5-digit ZIP code.</div>');
                $label.length ? $label.after($error) : $zip.after($error);
            }

            if (!isValid) {
                console.log('❌ Form submission blocked due to validation errors.');
                return;
            }

            // ✅ Build formData BEFORE sending AJAX
            $('#multiStepForm .form-step').each(function(index) {
                const stepId = 'step_' + (index + 1);
                const $step = $(this);

                let selectedOption = null;
                let min = 0;
                let max = 0;

                const $radio = $step.find('input[type="radio"]:checked');
                if ($radio.length) {
                    selectedOption = $radio.val();
                    min = parseFloat($radio.data('min')) || 0;
                    max = parseFloat($radio.data('max')) || 0;
                }

                const $select = $step.find('select');
                if ($select.length && $select.val()) {
                    const $selected = $select.find('option:selected');
                    selectedOption = $selected.val();
                    min = parseFloat($selected.data('min')) || 0;
                    max = parseFloat($selected.data('max')) || 0;
                }

                if (selectedOption) {
                    formData[stepId] = {
                        label: selectedOption,
                        min: min,
                        max: max
                    };
                }
            });

            $('#hgmFormData').val(JSON.stringify(formData));

            // Refresh the nonce immediately before final submit so cached pages do not
            // send an expired nonce from old localized HTML.
            try {
                const nonceResponse = await $.post(hgm_ajax.ajax_url, {
                    action: 'hgm_get_quote_nonce'
                });

                if (nonceResponse && nonceResponse.success && nonceResponse.data && nonceResponse.data.nonce) {
                    hgm_ajax.nonce = nonceResponse.data.nonce;
                }
            } catch (nonceError) {
                console.warn('Could not refresh form nonce; submitting with localized nonce.', nonceError);
            }

            // ✅ Now trigger AJAX
            $.post(hgm_ajax.ajax_url, {
                action: 'hgm_submit_quote_form',
                nonce: hgm_ajax.nonce,
                first_name: $('#first_name').val().trim(),
                email: $('#email').val().trim(),
                phone: $('#phone').val().trim(),
                zip_code: $('input[name="zip_code"]').val().trim(),
                _hgm_form_data: $('#hgmFormData').val(),
                form_id: $('input[name="form_id"]').val()
            })
                .done(function (response) {
                console.log("✅ AJAX response received", response);

                if (response.success) {
                    $('#multiStepForm').fadeOut(300, function () {
                        $(this).html(`
                    <div class="form-step active" id="step-success">
                            <h2 class="step-title"> ✅ Success!</h2>
                            <p>Your estimate has been emailed to you. Please check your SPAM if you do not receive it.</p><p>Feel free to give us a call any time and we will be happy to answer any of your questions.</p>
                        </div>
                `).fadeIn(300);
                    });
                } else {
                    console.warn("❌ Server-side validation failed", response.data?.errors || response.data);
                    let errorMessages = '';
                    if (response.data?.errors && typeof response.data.errors === 'object') {
                        for (const [field, message] of Object.entries(response.data.errors)) {
                            errorMessages += `<div class="alert alert-danger">❌ ${message}</div>`;
                        }
                    } else {
                        errorMessages = `<div class="alert alert-danger">❌ ${response.data?.message || 'Something went wrong.'}</div>`;
                    }
                    $('#multiStepForm').prepend(errorMessages);
                }
            })
                .fail(function (xhr, status, error) {
                console.error("🔥 AJAX request failed", status, error);
                $('#multiStepForm').prepend('<div class="alert alert-danger">❌ Submission failed. Please try again.</div>');
            });

            // ✅ Button loading state
            const submitButton = document.querySelector('#multiStepForm button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerText = 'Creating...';
            }
        });
    });

    // Image update logic
    const domSteps = document.querySelectorAll('#multiStepForm .form-step');
    const stepImage = document.querySelector('.step-image');
    function updateStepImage(stepElement) {
        const newImage = stepElement.getAttribute('data-step-image');
        const newAlt = stepElement.getAttribute('data-step-title');
        if (newImage && stepImage) {
            stepImage.src = newImage;
            stepImage.alt = newAlt || '';
        }
    }

    const firstVisibleStep = document.querySelector('#multiStepForm .form-step.active') || domSteps[0];
    if (firstVisibleStep) updateStepImage(firstVisibleStep);

    document.querySelectorAll('#multiStepForm .hgm-next-btn, #multiStepForm .prev-btn').forEach(button => {
        button.addEventListener('click', function () {
            setTimeout(() => {
                const activeStep = document.querySelector('#multiStepForm .form-step.active');
                if (activeStep) updateStepImage(activeStep);
            }, 50);
        });
    });
});