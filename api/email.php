<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/email.php';

const ASCT_EMAIL_LOGO_CID = 'asct-logo';

function resend_sender(): string
{
    $fromEmail = trim(RESEND_FROM_EMAIL);
    $fromName = trim(RESEND_FROM_NAME);

    if ($fromName === '') {
        return $fromEmail;
    }

    $safeName = str_replace(['"', "\r", "\n"], ['', '', ''], $fromName);
    return $safeName . ' <' . $fromEmail . '>';
}

function asct_email_asset_url(string $path): string
{
    $baseUrl = rtrim(trim(ASCT_APP_BASE_URL), '/');
    $cleanPath = trim(str_replace('\\', '/', $path), '/');
    $encodedPath = implode('/', array_map('rawurlencode', array_filter(explode('/', $cleanPath), 'strlen')));

    if ($baseUrl === '') {
        return $encodedPath;
    }

    return $baseUrl . '/' . $encodedPath;
}

function asct_email_logo_src(): string
{
    return 'cid:' . ASCT_EMAIL_LOGO_CID;
}

function asct_email_logo_attachment(): ?array
{
    $logoPath = dirname(__DIR__) . '/assets/img/asct-logo.png';

    if (!is_readable($logoPath)) {
        return null;
    }

    $logoContent = file_get_contents($logoPath);
    if ($logoContent === false) {
        return null;
    }

    return [
        'content' => base64_encode($logoContent),
        'filename' => 'asct-logo.png',
        'content_type' => 'image/png',
        'content_id' => ASCT_EMAIL_LOGO_CID,
    ];
}

function asct_gmail_blend_html(string $html): string
{
    return '<div class="gmail-blend-screen"><div class="gmail-blend-difference">' . $html . '</div></div>';
}

function asct_branded_email_html(string $title, string $preheader, string $contentHtml, string $footerNote = ''): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safePreheader = htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8');
    $safeAppName = htmlspecialchars(ASCT_APP_NAME, ENT_QUOTES, 'UTF-8');
    $logoUrl = htmlspecialchars(asct_email_logo_src(), ENT_QUOTES, 'UTF-8');
    $safeFooterNote = htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8');
    $footerHtml = $safeFooterNote !== ''
        ? '<p class="asct-email-footer-note" style="margin:12px 0 0;color:#8a8f98;-webkit-text-fill-color:transparent;text-shadow:0 0 0 #8a8f98;font-size:13px;line-height:20px;">' . $safeFooterNote . '</p>'
        : '';
    $titleHtml = asct_gmail_blend_html(
        '<h1 class="asct-email-title" style="margin:0 0 18px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:26px;line-height:34px;font-weight:800;">' . $safeTitle . '</h1>'
    );

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{$safeTitle}</title>
<style>
:root {
    color-scheme: light;
    supported-color-schemes: light;
}
body,
.asct-email-page {
    background-color: #f3f5f8 !important;
    background-image: linear-gradient(#f3f5f8, #f3f5f8) !important;
    color: #1c2430 !important;
}
.asct-email-header {
    background-color: #0b0c10 !important;
    background-image: linear-gradient(#0b0c10, #0b0c10) !important;
}
.asct-email-card {
    background-color: #111318 !important;
    background-image: linear-gradient(#111318, #111318) !important;
    color: #ffffff !important;
}
.asct-email-title {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}
.asct-email-copy {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}
.asct-email-code-box {
    background-color: #111318 !important;
    background-image: linear-gradient(#111318, #111318) !important;
    border-color: #242833 !important;
}
.asct-email-code-label {
    color: #ff8a5c !important;
    -webkit-text-fill-color: transparent !important;
    text-shadow: 0 0 0 #ff8a5c !important;
}
.asct-email-code {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}
.asct-email-warning {
    background-color: #2a2119 !important;
    background-image: linear-gradient(#2a2119, #2a2119) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}
.asct-email-footer {
    color: #6f7782 !important;
    -webkit-text-fill-color: transparent !important;
    text-shadow: 0 0 0 #6f7782 !important;
}
.asct-email-footer-note {
    color: #8a8f98 !important;
    -webkit-text-fill-color: transparent !important;
    text-shadow: 0 0 0 #8a8f98 !important;
}
u + .body .gmail-blend-screen {
    background: #000000 !important;
    mix-blend-mode: screen !important;
}
u + .body .gmail-blend-difference {
    background: #000000 !important;
    mix-blend-mode: difference !important;
}
</style>
</head>
<body class="body" style="margin:0;padding:0;background:#f3f5f8;background-color:#f3f5f8;background-image:linear-gradient(#f3f5f8,#f3f5f8);color:#1c2430;font-family:Arial,Helvetica,sans-serif;color-scheme:light;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$safePreheader}</div>
<table class="asct-email-page" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f8;background-color:#f3f5f8;background-image:linear-gradient(#f3f5f8,#f3f5f8);border-collapse:collapse;color-scheme:light;color:#1c2430;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;border-collapse:collapse;">
<tr>
<td class="asct-email-header" align="center" style="background:#0b0c10;background-color:#0b0c10;background-image:linear-gradient(#0b0c10,#0b0c10);border-radius:18px 18px 0 0;padding:28px 32px;border-bottom:4px solid #ff5a1f;text-align:center;color-scheme:light;">
<img src="{$logoUrl}" width="214" alt="{$safeAppName}" style="display:block;width:214px;max-width:100%;height:auto;margin:0 auto;border:0;outline:none;text-decoration:none;">
</td>
</tr>
<tr>
<td class="asct-email-card" style="background:#111318;background-color:#111318;background-image:linear-gradient(#111318,#111318);color:#ffffff;padding:34px 32px 30px;border:1px solid #252a34;border-top:0;border-radius:0 0 18px 18px;box-shadow:0 18px 45px rgba(17,24,39,0.12);color-scheme:light;">
{$titleHtml}
{$contentHtml}
</td>
</tr>
<tr>
<td align="center" style="padding:22px 24px 0;">
<p class="asct-email-footer" style="margin:0;color:#6f7782;-webkit-text-fill-color:transparent;text-shadow:0 0 0 #6f7782;font-size:13px;line-height:20px;">Sent by {$safeAppName}</p>
{$footerHtml}
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
}

function send_resend_email(string $to, string $subject, string $html, string $text, ?string $idempotencyKey = null): array
{
    $apiKey = trim(RESEND_API_KEY);
    $fromEmail = trim(RESEND_FROM_EMAIL);
    $payloadHtml = $html;

    if ($apiKey === '' || $fromEmail === '') {
        return [
            'success' => false,
            'message' => 'Email delivery is not configured. Set RESEND_API_KEY and RESEND_FROM_EMAIL.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'Email delivery is unavailable because the PHP cURL extension is not enabled.',
        ];
    }

    $attachments = [];
    if (str_contains($payloadHtml, asct_email_logo_src())) {
        $logoAttachment = asct_email_logo_attachment();

        if ($logoAttachment === null) {
            $fallbackLogoUrl = htmlspecialchars(asct_email_asset_url('assets/img/asct-logo.png'), ENT_QUOTES, 'UTF-8');
            $payloadHtml = str_replace(asct_email_logo_src(), $fallbackLogoUrl, $payloadHtml);
        } else {
            $attachments[] = $logoAttachment;
        }
    }

    $payload = [
        'from' => resend_sender(),
        'to' => [$to],
        'subject' => $subject,
        'html' => $payloadHtml,
        'text' => $text,
    ];

    if ($attachments !== []) {
        $payload['attachments'] = $attachments;
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'success' => false,
            'message' => 'Unable to reach Resend: ' . ($curlError ?: 'unknown network error'),
        ];
    }

    $decoded = json_decode((string)$body, true);
    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'id' => is_array($decoded) ? ($decoded['id'] ?? null) : null,
        ];
    }

    $message = 'Resend rejected the email request.';
    if (is_array($decoded) && isset($decoded['message'])) {
        $message = (string)$decoded['message'];
    }

    return [
        'success' => false,
        'message' => 'Unable to send verification email: ' . $message,
    ];
}
