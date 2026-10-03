<?php
require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP;


// New random secret (20 bytes = 160 bit, the RFC standard size).
function generateTotpSecret(): string {
    return TOTP::generate(secretSize: 20)->getSecret();
}

// The otpauth://. link that gets encoded into the QR code.
function getTotpUri(string $secret, string $email): string {
    $totp = TOTP::createFromSecret($secret);
    $totp->setLabel($email);
    $totp->setIssuer('SI Keuangan');

    return $totp->getProvisioningUri();
}

// Check TOTP code
function verifyTotpCode(string $secret, string $code): bool {
    $code = preg_replace('/\s+/', '', $code);

    return TOTP::createFromSecret($secret)->verify($code, null, 1);
}

// Render the QR code as SVG (needs the dom extension, no GD needed).
function renderQrCode(string $data): string {
    return (new Builder(writer: new SvgWriter(), data: $data, size: 220))
        ->build()
        ->getString();
}

// Backup codes if phone is lost
function generateBackupCodes(int $jumlah = 8): array {
    $codes = [];

    for ($i = 0; $i < $jumlah; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    return $codes;
}

// Check backup code and mark it as used (one time only)
function useBackupCode(PDO $pdo, int $userId, string $code): bool {
    $stmt = $pdo->prepare("SELECT id, code_hash FROM backup_codes WHERE user_id = :uid AND used_at IS NULL");
    $stmt->execute([':uid' => $userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (password_verify(strtoupper(trim($code)), $row['code_hash'])) {
            $update = $pdo->prepare("UPDATE backup_codes SET used_at = NOW() WHERE id = :id");
            $update->execute([':id' => $row['id']]);

            return true;
        }
    }

    return false;
}

// Delete all backup codes (used when MFA is turned off).
function resetBackupCodes(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("DELETE FROM backup_codes WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
}