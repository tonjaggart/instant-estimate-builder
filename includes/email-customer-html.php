<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>Your Instant Estimate</title>
        <style>
            body {
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
                font-family: Arial, sans-serif;
            }
            .main {
                width: 100%;
                background-color: #f4f4f4;
                padding: 20px 0;
            }
            .container {
                width: 100%;
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                border: 1px solid #e0e0e0;
            }
            .header-logo {
                padding: 20px;
                text-align: center;
                background-color: #ffffff;
            }
            .nav {
                padding: 15px 0;
                text-align: center;
                background-color: <?php echo esc_attr($primary_color); ?>;
            }
            .nav a {
                font-size: 18px;
                color: #ffffff;
                text-decoration: none;
                font-weight: bold;
            }
            .content {
                padding: 30px;
            }
            h2 {
                color: #1a1a1a;
                margin-top: 0;
            }
            h3 {
                margin-top: 10px;
            }
            p {
                font-size: 16px;
                color: #333333;
                line-height: 1.5;
            }
        </style>
    </head>
    <body>
        <span style="display:none; font-size:1px; color:#ffffff; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">
            Based on your answers, here is your customized estimate. Open to view all of the details. 
        </span>
        <table class="main" cellpadding="0" cellspacing="0">
            <tr>
                <td align="center">
                    <table class="container" cellpadding="0" cellspacing="0">
                        <!-- Logo -->
                        <tr>
                            <td class="header-logo" style="padding: 20px; text-align: center; background-color: #ffffff;">
                                <?php if (!empty($logo_url)): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?> Logo" style="max-width: 200px; height: auto;">
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Phone Nav -->
                        <?php if (!empty($phone_text) && !empty($phone_number)): ?>
                        <tr>
                            <td class="nav" style="padding: 15px 0; text-align: center; background-color: <?php echo esc_attr($primary_color); ?>;">
                                <a href="tel:<?php echo esc_attr($sanitized_phone); ?>" style="font-size: 18px; color: #ffffff; text-decoration: none; font-weight: bold;">
                                    <?php echo esc_html($phone_text); ?> <?php echo esc_html($phone_number); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <!-- Email Body Content -->
                        <tr>
                            <td class="content">
                                <h3>Hi <?php echo esc_html($first_name); ?>,</h3>

                                <?php if (!empty($body_copy)): ?>
                                <p><?php echo nl2br(esc_html($body_copy)); ?></p>
                                <?php endif; ?>

                                <!-- Estimate Range -->
                                <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 20px;">
                                    <tr>
                                        <td style="text-align: center; font-size: 20px; color: #1a1a1a; font-weight: bold; padding: 10px 0;">
                                            🔧 Estimated Total: <?php echo esc_html($estimate_low); ?> – <?php echo esc_html($estimate_high); ?>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Disclaimer -->
                                <?php if (!empty($disclaimer)): ?>
                                <p style="font-size: 14px; color: #777; font-style: italic; margin-top: 15px;">
                                    <?php echo wp_kses_post($disclaimer); ?>
                                </p>
                                <?php endif; ?>

                                <!-- CTA Button -->
                                <?php if (!empty($button_link) && !empty($button_text)): ?>
                                <table width="100%" cellpadding="0" cellspacing="0" style="margin: 30px 0;">
                                    <tr>
                                        <td align="center">
                                            <a href="<?php echo esc_url($button_link); ?>" style="display: inline-block; padding: 15px 25px; background-color: <?php echo esc_attr($primary_color); ?>; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 4px;">
                                                <?php echo esc_html($button_text); ?>
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                <?php endif; ?>

                                <!-- Text Below Button -->
                                <?php if (!empty($text_below_btn) && !empty($phone_number)): ?>
                                <p style="font-size: 16px; color: #333; margin-top: 20px;">
                                    <?php echo wp_kses_post($text_below_btn); ?>
                                    <strong>
                                        <a href="tel:<?php echo esc_attr($sanitized_phone); ?>" style="color: inherit; text-decoration: none;">
                                            <?php echo esc_html($phone_number); ?>
                                        </a>
                                    </strong>
                                </p>
                                <?php endif; ?>

                                <!-- Sign-Off -->
                                <!-- Sign-Off -->
                                <?php if (!empty($company_name) || !empty($reply_to) || !empty($phone_number)): ?>
                                <p style="font-size: 14px; color: #333; margin-top: 30px;">
                                    Best,<br><br>
                                    <strong><?php echo esc_html($company_name); ?></strong><br>
                                    <?php if (!empty($reply_to)): ?>
                                    Email: <a href="mailto:<?php echo esc_attr($reply_to); ?>" style="color: inherit; text-decoration: none;"><?php echo esc_html($reply_to); ?></a><br>
                                    <?php endif; ?>
                                    <?php if (!empty($phone_number)): ?>
                                    Phone: <a href="tel:<?php echo esc_attr($sanitized_phone); ?>" style="color: inherit; text-decoration: none;"><?php echo esc_html($phone_number); ?></a>
                                    <?php endif; ?>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style="padding: 20px; text-align: center; background-color: #f4f4f4; font-size: 12px; color: #999;">
                                © <?php echo date("Y"); ?>
                                <a href="https://hvacgrowthmachine.com?utm_source=estimate&utm_medium=email&utm_campaign=<?php echo urlencode($company_name); ?>" style="color: #999; text-decoration: underline;">
                                    HVAC Growth Machine.
                                </a> All rights reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>