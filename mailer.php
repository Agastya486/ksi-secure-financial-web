<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send transaction/verification emails via Brevo SMTP.
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email content (HTML supported)
 * @param bool $isHtml Format flag (default: true)
 * @return bool True on success, false on failure
 */
function sendEmail($to, $subject, $body, $isHtml = true) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Brevo server config
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        
        // SMTP credentials
        $mail->Username   = $_ENV['BREVO_SMTP_USER'];

        $mail->Password   = $_ENV['BREVO_SMTP_KEY'];
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender information
        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME'] ?? 'System');
        
        // Receiver information
        $mail->addAddress($to);

        // Email content
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Send
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
