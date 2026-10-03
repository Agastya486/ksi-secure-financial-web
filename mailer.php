<?php

/**
 * Send an email using the Brevo HTTPS API.
 * Need Brevo API Key
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email content
 * @param bool $isHtml True if the content is HTML, false for plain text
 * @return bool True if Brevo accepted the email, false if not
 */
function sendEmail($to, $subject, $body, $isHtml = true) {
    $payload = [
        'sender'  => [
            'name'  => $_ENV['SMTP_FROM_NAME'] ?? 'System',
            'email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
        ],
        'to'      => [['email' => $to]],
        'subject' => $subject,
    ];

    $payload[$isHtml ? 'htmlContent' : 'textContent'] = $body;

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'accept: application/json',
            'api-key: ' . ($_ENV['BREVO_API_KEY'] ?? ''),
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Answer 201 when it accepted the email
    if ($status === 201) {
        return true;
    }

    error_log('[MAIL FAIL] status=' . $status . ' err=' . $curlErr . ' resp=' . $response);

    return false;
}