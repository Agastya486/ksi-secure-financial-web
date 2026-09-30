<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Fungsi untuk mengirim email menggunakan SMTP Brevo
 * 
 * @param string $to Alamat email tujuan
 * @param string $subject Subjek email
 * @param string $body Isi dari email (bisa HTML)
 * @param bool $isHtml Apakah format isi email berupa HTML (default true)
 * @return bool True jika berhasil, False jika gagal
 */
function sendEmail($to, $subject, $body, $isHtml = true) {
    $mail = new PHPMailer(true);
    
    try {
        // Konfigurasi Server SMTP Brevo
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        
        // Kredensial SMTP
        $mail->Username   = $_ENV('BREVO_SMTP_USER');

        $mail->Password   = $_ENV('BREVO_SMTP_KEY');
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Informasi Pengirim
        $mail->setFrom('agastyadevano9@gmail.com', 'Tim SI Keuangan'); 
        
        // Informasi Penerima
        $mail->addAddress($to);

        // Konten Email
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Kirim
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
