<?php
/**
 * Minimal Brevo (formerly Sendinblue) transactional email helper.
 *
 * Uses Brevo's HTTP API directly (https://api.brevo.com/v3/smtp/email) via
 * cURL, so no Composer / SDK install is required.
 *
 * Setup:
 *   1. Log in to https://app.brevo.com
 *   2. Go to Settings -> SMTP & API -> API Keys -> Generate a new API key
 *   3. Paste it into BREVO_API_KEY in api/config.php
 *   4. Set BREVO_SENDER_EMAIL to an email/domain you verified in Brevo
 *      (Settings -> Senders, Domains & Dedicated IPs -> Senders)
 */

/**
 * Send an email through Brevo.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $htmlContent
 * @return array{success: bool, message: string}
 */
function sendBrevoEmail(string $toEmail, string $toName, string $subject, string $htmlContent): array {
    if (!defined('BREVO_API_KEY') || BREVO_API_KEY === '' || BREVO_API_KEY === 'YOUR_BREVO_API_KEY_HERE') {
        return ['success' => false, 'message' => 'Email service is not configured yet (missing Brevo API key).'];
    }

    $payload = [
        'sender' => [
            'name' => defined('BREVO_SENDER_NAME') ? BREVO_SENDER_NAME : 'CUSG Alijis',
            'email' => defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : '',
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName ?: $toEmail],
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => 'Could not reach the email service: ' . $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Email sent.'];
    }

    $decoded = json_decode($response ?: '', true);
    $errMsg = $decoded['message'] ?? ('Brevo API returned HTTP ' . $httpCode);
    return ['success' => false, 'message' => $errMsg];
}

/**
 * Builds the HTML body for the OTP / temporary password email.
 */
function buildOtpEmailHtml(string $studentName, string $otp): string {
    $safeName = htmlspecialchars($studentName ?: 'Student', ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px;">
    <h2 style="color: #059669; margin-top: 0;">CUSG Alijis - Password Reset</h2>
    <p>Hi {$safeName},</p>
    <p>We received a request to reset your CUSG Alijis student portal password. Use the one-time code below to continue:</p>
    <div style="text-align: center; margin: 24px 0;">
        <span style="display: inline-block; font-size: 28px; font-weight: 700; letter-spacing: 6px; color: #0f172a; background: #f8fafc; border: 1px dashed #059669; border-radius: 8px; padding: 12px 20px;">{$safeOtp}</span>
    </div>
    <p>This code will expire in <strong>10 minutes</strong>. If you did not request a password reset, you can safely ignore this email.</p>
    <p style="margin-top: 32px; font-size: 12px; color: #64748b;">CUSG Alijis - Carlos Hilado Memorial State University, Alijis Campus</p>
</div>
HTML;
}
