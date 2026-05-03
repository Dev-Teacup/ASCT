<?php
declare(strict_types=1);

function send_login_code_email(array $user, string $code, string $idempotencyKey): array
{
    $appName = ASCT_APP_NAME;
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = $appName . ' login verification code';
    $html = asct_branded_email_html(
        'Your login verification code',
        'Use this 6-digit code to finish signing in to ' . $appName . '.',
        asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Hello <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">' . $safeName . '</strong>,</p>')
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Use this verification code to finish signing in to ' . $safeAppName . '.</p>')
        . '<div class="asct-email-code-box" style="margin:26px 0;padding:24px 18px;background:#0b0c10;background-color:#0b0c10;background-image:linear-gradient(#0b0c10,#0b0c10);border:1px solid #303744;border-radius:14px;text-align:center;color-scheme:light;">'
        . '<div class="asct-email-code-label" style="margin:0 0 10px;color:#ff8a5c;-webkit-text-fill-color:transparent;text-shadow:0 0 0 #ff8a5c;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Verification code</div>'
        . asct_gmail_blend_html('<div class="asct-email-code" style="color:#ffffff;-webkit-text-fill-color:#ffffff;font-family:\'Courier New\',Courier,monospace;font-size:36px;line-height:42px;font-weight:800;letter-spacing:8px;">' . $safeCode . '</div>')
        . '</div>'
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">This code expires in <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">10 minutes</strong>.</p>')
        . '<div class="asct-email-warning" style="margin:0;padding:14px 16px;background:#2a2119;background-color:#2a2119;background-image:linear-gradient(#2a2119,#2a2119);border-left:4px solid #ff5a1f;border-radius:8px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:14px;line-height:22px;color-scheme:light;">'
        . asct_gmail_blend_html('<span style="color:#ffffff;-webkit-text-fill-color:#ffffff;">If you did not try to sign in, you can safely ignore this email.</span>')
        . '</div>',
        'For your security, ASCT will never ask you to share this code.'
    );
    $text = "Hello {$user['name']},\n\n"
        . "Use this verification code to finish signing in to {$appName}: {$code}\n\n"
        . "This code expires in 10 minutes. If you did not try to sign in, you can ignore this email.";

    return send_resend_email((string)$user['email'], $subject, $html, $text, $idempotencyKey);
}

function send_signup_confirmation_email(array $signup, string $code, string $idempotencyKey): array
{
    $appName = ASCT_APP_NAME;
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars((string)$signup['name'], ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = $appName . ' registration confirmation code';
    $html = asct_branded_email_html(
        'Confirm your registration email',
        'Use this 6-digit code to confirm your email address for ' . $appName . '.',
        asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Hello <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">' . $safeName . '</strong>,</p>')
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Use this confirmation code to verify your email address and submit your student account request to ' . $safeAppName . '.</p>')
        . '<div class="asct-email-code-box" style="margin:26px 0;padding:24px 18px;background:#0b0c10;background-color:#0b0c10;background-image:linear-gradient(#0b0c10,#0b0c10);border:1px solid #303744;border-radius:14px;text-align:center;color-scheme:light;">'
        . '<div class="asct-email-code-label" style="margin:0 0 10px;color:#ff8a5c;-webkit-text-fill-color:transparent;text-shadow:0 0 0 #ff8a5c;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Confirmation code</div>'
        . asct_gmail_blend_html('<div class="asct-email-code" style="color:#ffffff;-webkit-text-fill-color:#ffffff;font-family:\'Courier New\',Courier,monospace;font-size:36px;line-height:42px;font-weight:800;letter-spacing:8px;">' . $safeCode . '</div>')
        . '</div>'
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">This code expires in <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">10 minutes</strong>.</p>')
        . '<div class="asct-email-warning" style="margin:0;padding:14px 16px;background:#2a2119;background-color:#2a2119;background-image:linear-gradient(#2a2119,#2a2119);border-left:4px solid #ff5a1f;border-radius:8px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:14px;line-height:22px;color-scheme:light;">'
        . asct_gmail_blend_html('<span style="color:#ffffff;-webkit-text-fill-color:#ffffff;">If you did not request this account, you can safely ignore this email.</span>')
        . '</div>',
        'Only confirmed emails are sent to the admin approval queue.'
    );
    $text = "Hello {$signup['name']},\n\n"
        . "Use this confirmation code to verify your email address and submit your student account request to {$appName}: {$code}\n\n"
        . "This code expires in 10 minutes. If you did not request this account, you can ignore this email.";

    return send_resend_email((string)$signup['email'], $subject, $html, $text, $idempotencyKey);
}
