<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';
require_once 'totp.php';

// Redirect to login if session nonexistent
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// Fetch user data from DB
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
} catch (PDOException $e) {
    $error = 'Gagal mengambil data pengguna dari database.';
}

// Profile update process
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_totp'])) {
        // Make a secret, not saving it to the database yet
        $_SESSION['totp_setup'] = generateTotpSecret();
    } elseif (isset($_POST['confirm_totp'])) {
        // Save the secret after the code is correct
        $setupSecret = $_SESSION['totp_setup'] ?? '';
        $inputCode   = $_POST['totp_code'] ?? '';

        if (empty($setupSecret)) {
            $error = 'Sesi aktivasi tidak valid. Silakan ulangi.';
        } elseif (verifyTotpCode($setupSecret, $inputCode)) {
            try {
                $upd = $pdo->prepare("UPDATE users SET totp_secret = :secret WHERE id = :id");
                $upd->execute([':secret' => $setupSecret, ':id' => $_SESSION['user_id']]);

                // Backup codes: made once, shown once, stored as hashes
                $backupCodes = generateBackupCodes();
                $ins = $pdo->prepare("INSERT INTO backup_codes (user_id, code_hash) VALUES (:uid, :hash)");
                foreach ($backupCodes as $code) {
                    $ins->execute([':uid' => $_SESSION['user_id'], ':hash' => password_hash($code, PASSWORD_BCRYPT)]);
                }

                unset($_SESSION['totp_setup']);
                $_SESSION['totp_new_codes'] = $backupCodes;

                $success = 'Two-Factor Authentication berhasil diaktifkan!';
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                $error = 'Gagal mengaktifkan Two-Factor Authentication.';
            }
        } else {
            $error = 'Kode dari aplikasi authenticator salah!';
        }
    } elseif (isset($_POST['cancel_totp'])) {
        unset($_SESSION['totp_setup']);
    } elseif (isset($_POST['ack_backup_codes'])) {
        unset($_SESSION['totp_new_codes']);
    } elseif (isset($_POST['disable_totp'])) {
        // Turn off MFA: must prove with a current code first
        $inputCode = $_POST['totp_code'] ?? '';

        if (verifyTotpCode($user['totp_secret'] ?? '', $inputCode)) {
            try {
                $upd = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = :id");
                $upd->execute([':id' => $_SESSION['user_id']]);

                resetBackupCodes($pdo, $_SESSION['user_id']);
                unset($_SESSION['totp_new_codes']);

                $success = 'Two-Factor Authentication berhasil dimatikan.';
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                $error = 'Gagal mematikan Two-Factor Authentication.';
            }
        } else {
            $error = 'Kode dari aplikasi authenticator salah!';
        }
    } elseif (isset($_POST['verify_otp'])) {
        $inputOtp = $_POST['otp'] ?? '';
        if (isset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'])) {
            if (time() > $_SESSION['otp_expiry']) {
                $error = 'OTP telah kedaluwarsa. Silakan ulangi proses pembaruan.';
                unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'], $_SESSION['pending_fullname'], $_SESSION['pending_password']);
            } elseif (!hash_equals($_SESSION['otp'], $inputOtp)) {
                $error = 'Kode OTP salah!';
            } else {
                // OTP is valid
                $fullname = $_SESSION['pending_fullname'] ?? $user['fullname'];
                $newEmail = $_SESSION['pending_email'];
                $newPassword = $_SESSION['pending_password'] ?? '';
                
                try {
                    if (!empty($newPassword)) {
                        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                        $updateStmt = $pdo->prepare("UPDATE users SET fullname = :fullname, email = :email, password = :password WHERE id = :id");
                        $updateStmt->execute([
                            ':fullname' => $fullname,
                            ':email'    => $newEmail,
                            ':password' => $hashedPassword,
                            ':id'       => $_SESSION['user_id']
                        ]);
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE users SET fullname = :fullname, email = :email WHERE id = :id");
                        $updateStmt->execute([
                            ':fullname' => $fullname,
                            ':email'    => $newEmail,
                            ':id'       => $_SESSION['user_id']
                        ]);
                    }
                    
                    $subjek = "Email Anda Telah Diperbarui";
                    $pesanHtml = "
                        <div style='font-family: Arial, sans-serif;'>
                            <h2 style='color: #4F46E5;'>Pemberitahuan Perubahan Email</h2>
                            <p>Halo, <strong>" . htmlspecialchars($fullname) . "</strong>.</p>
                            <p>Email akun Anda di DompetKu telah berhasil diubah menjadi: <strong>" . htmlspecialchars($newEmail) . "</strong>.</p>
                        </div>
                    ";
                    sendEmail($newEmail, $subjek, $pesanHtml);
                    
                    $success = 'Profil dan Email Anda berhasil diperbarui!';
                    $_SESSION['fullname'] = $fullname;
                    unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'], $_SESSION['pending_fullname'], $_SESSION['pending_password']);
                    
                    $stmt->execute([':id' => $_SESSION['user_id']]);
                    $user = $stmt->fetch();
                } catch (PDOException $e) {
                    $error = 'Gagal memperbarui profil. Silahkan coba lagi nanti.';
                }
            }
        } else {
             $error = 'Sesi OTP tidak valid atau sudah berakhir.';
        }
    } elseif (isset($_POST['cancel_otp'])) {
        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'], $_SESSION['pending_fullname'], $_SESSION['pending_password']);
        $success = 'Pembaruan dibatalkan.';
    } elseif (isset($_POST['update_profile'])) {
        $fullname        = trim($_POST['fullname'] ?? '');
        $newEmail        = trim($_POST['email'] ?? '');
        $oldPassword     = $_POST['old_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($fullname)) {
            $error = 'Nama lengkap tidak boleh kosong!';
        } elseif (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email tidak valid!';
        } else {
            $isPasswordValid = true;
            if (!empty($newPassword)) {
                if (empty($oldPassword)) {
                    $error = 'Password lama harus diisi jika ingin mengubah password!';
                    $isPasswordValid = false;
                } elseif (!password_verify($oldPassword, $user['password'])) {
                    $error = 'Password lama salah!';
                    $isPasswordValid = false;
                } elseif (strlen($newPassword) < 8) {
                    $error = 'Password baru minimal 8 karakter!';
                    $isPasswordValid = false;
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Konfirmasi password baru tidak cocok!';
                    $isPasswordValid = false;
                }
            }
            
            if ($isPasswordValid && empty($error)) {
                if ($user['email'] !== $newEmail) {
                    try {
                        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
                        $checkStmt->execute([':email' => $newEmail, ':id' => $_SESSION['user_id']]);
                        if ($checkStmt->fetch()) {
                            $error = 'Email tersebut sudah digunakan oleh akun lain.';
                        } else {
                            $otp = sprintf("%06d", random_int(0, 999999));
                            $_SESSION['otp'] = $otp;
                            $_SESSION['otp_expiry'] = time() + 300; 
                            $_SESSION['pending_email'] = $newEmail;
                            $_SESSION['pending_fullname'] = $fullname;
                            $_SESSION['pending_password'] = $newPassword;
                            
                            $subjek = "Kode Verifikasi Perubahan Email";
                            $pesanHtml = "
                                <div style='font-family: Arial, sans-serif; text-align: center; background-color: #f4f4f4; padding: 20px;'>
                                    <div style='background-color: #ffffff; padding: 30px; border-radius: 10px; max-width: 600px; margin: auto;'>
                                        <h2 style='color: #4F46E5;'>Kode Verifikasi OTP</h2>
                                        <p>Anda telah meminta untuk mengubah email akun anda.</p>
                                        <p>Gunakan kode OTP berikut untuk memverifikasi alamat email ini:</p>
                                        <h1 style='letter-spacing: 5px; color: #333;'>{$otp}</h1>
                                        <p style='color: #888888; font-size: 12px;'>Kode ini berlaku selama 5 menit. Jika anda tidak melakukan permintaan ini, abaikan saja.</p>
                                    </div>
                                </div>
                            ";
                            
                            if (sendEmail($newEmail, $subjek, $pesanHtml)) {
                                $success = "Kode OTP telah dikirim ke email <strong>" . htmlspecialchars($newEmail) . "</strong>. Silakan periksa inbox/spam.";
                            } else {
                                $error = "Gagal mengirimkan email OTP. Pastikan email tujuan valid dan konfigurasi SMTP benar.";
                                unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'], $_SESSION['pending_fullname'], $_SESSION['pending_password']);
                            }
                        }
                    } catch (PDOException $e) {
                        $error = 'Gagal mengecek email. Silakan coba lagi nanti.';
                    }
                } else {
                    try {
                        if (!empty($newPassword)) {
                            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                            $updateStmt = $pdo->prepare("UPDATE users SET fullname = :fullname, password = :password WHERE id = :id");
                            $updateStmt->execute([
                                ':fullname' => $fullname,
                                ':password' => $hashedPassword,
                                ':id'       => $_SESSION['user_id']
                            ]);
                        } else {
                            $updateStmt = $pdo->prepare("UPDATE users SET fullname = :fullname WHERE id = :id");
                            $updateStmt->execute([
                                ':fullname' => $fullname,
                                ':id'       => $_SESSION['user_id']
                            ]);
                        }
                        $success = 'Profil berhasil diperbarui!';
                        $_SESSION['fullname'] = $fullname;
                        
                        $stmt->execute([':id' => $_SESSION['user_id']]);
                        $user = $stmt->fetch();
                    } catch (PDOException $e) {
                        $error = 'Gagal memperbarui profil. Silakan coba lagi nanti.';
                    }
                }
            }
        }
    }
}

// Helper for the initials
function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $w) {
        if (!empty($w)) {
            $initials .= strtoupper($w[0]);
        }
    }
    return substr($initials, 0, 2) ?: 'US';
}

$userInitials = getInitials($user['fullname'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Profil Saya - DompetKu</title>
  <link rel="stylesheet" href="./dist/output.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">
  <style>
    .grid-lines {
      background-size: 56px 56px;
      background-image: linear-gradient(to right, rgba(148, 163, 184, 0.05) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(148, 163, 184, 0.05) 1px, transparent 1px);
    }

    .glow {
      position: absolute;
      border-radius: 9999px;
      filter: blur(120px);
      pointer-events: none;
    }
  </style>
</head>

<body class="bg-surface text-ink antialiased min-h-screen flex flex-col font-sans selection:bg-accent-600 selection:text-white relative overflow-x-hidden">

  <div class="fixed inset-0 grid-lines pointer-events-none z-[-2]"></div>
  <div class="glow top-[-20%] left-[-10%] w-[38rem] h-[38rem] bg-accent-600/10"></div>

  <!-- Navbar -->
  <header class="sticky top-0 z-50 border-b border-line/70 bg-surface/80 backdrop-blur-xl">
    <div class="mx-auto max-w-5xl px-6 h-16 flex items-center justify-between">
      <a href="dashboard.php" class="flex items-center gap-2.5">
        <img src="./dist/logo.png" alt="DompetKu" class="w-9 h-9" />
        <span class="font-bold tracking-tight text-white">DompetKu</span>
      </a>

      <nav class="hidden sm:flex items-center gap-6 text-sm">
        <a href="dashboard.php" class="text-ink-muted hover:text-ink transition-colors">Dashboard</a>
        <a href="profile.php" class="text-white font-medium">Profil</a>
      </nav>

      <div class="flex items-center gap-3">
        <a href="profile.php"
          class="w-9 h-9 grid place-items-center rounded-lg border border-line bg-raised text-sm font-semibold text-accent-400 hover:border-accent-600 transition-colors"
          title="<?= htmlspecialchars($user['fullname']) ?>"><?= htmlspecialchars($userInitials) ?></a>
        <a href="logout.php" class="text-sm text-ink-muted hover:text-danger transition-colors">Keluar</a>
      </div>
    </div>
  </header>

  <main class="mx-auto max-w-5xl w-full px-6 py-10 flex-grow">

    <h1 class="text-2xl font-bold text-white tracking-tight">Profil</h1>
    <p class="mt-1 text-ink-muted">Kelola data akun dan keamananmu.</p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">

      <!-- Profile summary card (Left) -->
      <div class="rounded-xl border border-line bg-raised/50 p-6 h-fit">
        <div class="w-14 h-14 grid place-items-center rounded-full bg-accent-500/15 text-lg font-bold text-accent-400">
          <?= htmlspecialchars($userInitials) ?>
        </div>

        <h2 class="mt-4 font-semibold text-white"><?= htmlspecialchars($user['fullname']) ?></h2>
        <p class="mt-0.5 text-sm text-ink-muted break-all"><?= htmlspecialchars($user['email']) ?></p>

        <dl class="mt-5 pt-4 border-t border-line space-y-2 text-sm">
          <div class="flex justify-between">
            <dt class="text-ink-faint">Status</dt>
            <dd class="text-accent-400">Aktif</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-ink-faint">ID</dt>
            <dd class="font-mono text-ink-muted">#<?= htmlspecialchars($user['id']) ?></dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-ink-faint">Terdaftar</dt>
            <dd class="text-ink-muted">
              <?= !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '-' ?>
            </dd>
          </div>
        </dl>
      </div>

      <!-- Profile & password edit form (Right) -->
      <section class="md:col-span-2 rounded-xl border border-line bg-raised/40 p-6">
        <h2 class="font-semibold text-white mb-5">Data Akun</h2>

          <?php if (!empty($error)): ?>
            <div class="mb-5 p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($success)): ?>
            <div class="mb-5 p-3 rounded-lg bg-accent-500/10 border border-accent-600/30 text-accent-400 text-sm text-center">
              <?= $success; ?>
            </div>
          <?php endif; ?>

        <?php if (isset($_SESSION['otp'])): ?>
          <!-- OTP Form -->
          <div class="rounded-lg border border-accent-600/30 bg-accent-500/10 p-5">
             <p class="text-sm text-ink-muted mb-5 text-center leading-relaxed">
               Kode OTP 6 digit dikirim ke email <strong class="text-white"><?= htmlspecialchars($_SESSION['pending_email'] ?? '') ?></strong>.
             </p>
             <form action="profile.php" method="POST" class="space-y-4">
               <div class="space-y-1.5">
                 <label for="otp" class="block text-xs font-medium text-ink-muted text-center">Kode OTP</label>
                 <input type="text" id="otp" name="otp" required maxlength="6" placeholder="000000"
                  class="w-full p-3 rounded-lg bg-surface border border-line text-white placeholder-ink-faint text-center tracking-[0.4em] font-mono text-lg focus:outline-none focus:border-accent-600 transition-colors" />
               </div>
               <div class="flex flex-col sm:flex-row gap-2">
                 <button type="submit" name="verify_otp"
                   class="flex-1 cursor-pointer py-2.5 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
                   Verifikasi &amp; Simpan
                 </button>
                 <button type="submit" name="cancel_otp" formnovalidate
                   class="cursor-pointer py-2.5 px-4 rounded-lg border border-line text-ink-muted hover:text-ink transition-colors">
                   Batal
                 </button>
               </div>
             </form>
          </div>
        <?php else: ?>
        <form action="profile.php" method="POST" class="space-y-4">
          <div class="space-y-1.5">
            <label for="fullname" class="block text-xs font-medium text-ink-muted">Nama Lengkap</label>
            <input type="text" id="fullname" name="fullname" required value="<?= htmlspecialchars($user['fullname']) ?>"
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
          </div>

          <div class="space-y-1.5">
            <label for="email" class="block text-xs font-medium text-ink-muted">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
            <p class="text-xs text-ink-faint">Perubahan email memerlukan kode OTP.</p>
          </div>

          <fieldset class="pt-4 border-t border-line">
            <legend class="text-sm font-medium text-white">Ganti Password</legend>
            <p class="text-xs text-ink-faint mb-3">Kosongkan semua kolom bila tidak ingin mengganti password.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="space-y-1.5">
                <label for="old_password" class="block text-xs font-medium text-ink-muted">Password Lama</label>
                <input type="password" id="old_password" name="old_password" placeholder="Password saat ini"
                  class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
              </div>
              <div class="space-y-1.5">
                <label for="new_password" class="block text-xs font-medium text-ink-muted">Password Baru</label>
                <input type="password" id="new_password" name="new_password" placeholder="Minimal 8 karakter"
                  class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
              </div>
            </div>

            <div class="space-y-1.5 mt-3">
              <label for="confirm_password" class="block text-xs font-medium text-ink-muted">Konfirmasi Password Baru</label>
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Ulangi password baru"
                class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
            </div>
          </fieldset>

          <div class="flex justify-end pt-1">
            <button type="submit" name="update_profile"
              class="cursor-pointer px-5 py-2.5 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
              Simpan
            </button>
          </div>
        </form>
        <?php endif; ?>
      </section>

    </div>

    <!-- 2FA card -->
    <section class="mt-6 rounded-xl border border-line bg-raised/40 p-6">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="font-semibold text-white">Two-Factor Authentication</h2>
          <p class="text-sm text-ink-muted mt-0.5">
            Proteksi tambahan memakai kode dari aplikasi authenticator.
          </p>
        </div>
        <span class="text-xs font-medium px-2.5 py-1 rounded-md border
          <?= empty($user['totp_secret'])
              ? 'bg-warn/10 text-warn border-warn/30'
              : 'bg-accent-500/10 text-accent-400 border-accent-600/30' ?>">
          <?= empty($user['totp_secret']) ? 'Belum aktif' : 'Aktif' ?>
        </span>
      </div>

      <?php if (!empty($error) || !empty($success)): ?>
        <div class="mb-5 space-y-3">
          <?php if (!empty($error)): ?>
            <div class="p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($success)): ?>
            <div class="p-3 rounded-lg bg-accent-500/10 border border-accent-600/30 text-accent-400 text-sm text-center">
              <?= htmlspecialchars($success); ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- New backup codes: shown only once -->
      <?php if (!empty($_SESSION['totp_new_codes'])): ?>
        <div class="rounded-lg border border-accent-600/30 bg-accent-500/10 p-5 mb-5">
          <p class="text-sm text-ink-muted mb-4">
            Simpan kode pemulihan ini sekarang. Tiap kode hanya bisa dipakai <strong
              class="text-white">sekali</strong> untuk login bila HP kamu hilang, dan tidak akan
            ditampilkan lagi.
          </p>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <?php foreach ($_SESSION['totp_new_codes'] as $code): ?>
              <div class="py-2 px-2 text-center bg-surface border border-line rounded-lg">
                <span class="font-mono text-sm text-white"><?= htmlspecialchars($code) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <form action="profile.php" method="POST" class="mt-4">
            <button type="submit" name="ack_backup_codes"
              class="cursor-pointer w-full py-2.5 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
              Saya sudah menyimpannya
            </button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['totp_setup'])): ?>
        <!-- Scan the QR, then prove it works by typing a code -->
        <div class="rounded-lg border border-line bg-surface/50 p-5">
          <div class="flex flex-col sm:flex-row gap-5 items-start">
            <div class="shrink-0 p-2.5 bg-white rounded-lg">
              <?= renderQrCode(getTotpUri($_SESSION['totp_setup'], $user['email'])); ?>
            </div>
            <div class="flex-1 w-full">
              <p class="text-sm text-ink-muted leading-relaxed mb-3">
                Pindai QR di atas dengan Google Authenticator atau Authy, lalu masukkan kode 6 digit
                yang muncul untuk mengaktifkan 2FA.
              </p>
              <p class="text-xs text-ink-faint mb-1.5">Tidak bisa memindai? Masukkan kunci manual:</p>
              <p class="font-mono text-xs text-accent-400 break-all mb-4 bg-surface border border-line p-2.5 rounded-lg">
                <?= htmlspecialchars($_SESSION['totp_setup']); ?>
              </p>
              <form action="profile.php" method="POST" class="space-y-3">
                <input type="text" name="totp_code" required maxlength="6" inputmode="numeric" placeholder="000000"
                  class="w-full p-3 rounded-lg bg-surface border border-line text-white placeholder-ink-faint text-center tracking-[0.4em] font-mono focus:outline-none focus:border-accent-600 transition-colors" />
                <div class="flex flex-col sm:flex-row gap-2">
                  <button type="submit" name="confirm_totp"
                    class="flex-1 cursor-pointer py-2.5 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
                    Aktivasi
                  </button>
                  <button type="submit" name="cancel_totp"
                    formnovalidate
                    class="cursor-pointer px-4 py-2.5 rounded-lg border border-line text-ink-muted hover:text-ink transition-colors">
                    Batal
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php elseif (empty($user['totp_secret'])): ?>
        <p class="text-sm text-ink-muted mb-4">
          Tambahkan lapisan kedua agar akun tetap aman meski passwordmu bocor.
        </p>
        <form action="profile.php" method="POST">
          <button type="submit" name="start_totp"
            class="cursor-pointer px-5 py-2.5 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
            Aktifkan 2FA
          </button>
        </form>
      <?php else: ?>
        <!-- Already active: form to turn it off -->
        <p class="text-sm text-ink-muted mb-4">
          2FA aktif. Setiap login akan meminta kode dari aplikasi authenticator.
          Untuk mematikannya, masukkan kode yang sedang aktif.
        </p>
        <form action="profile.php" method="POST" class="flex flex-col sm:flex-row gap-2 items-stretch sm:items-center">
          <input type="text" name="totp_code" required maxlength="6" inputmode="numeric" placeholder="000000"
            class="w-full sm:max-w-[200px] p-3 rounded-lg bg-surface border border-line text-white placeholder-ink-faint text-center tracking-[0.4em] font-mono focus:outline-none focus:border-danger transition-colors" />
          <button type="submit" name="disable_totp"
            class="cursor-pointer px-4 py-2.5 rounded-lg border border-danger/30 text-danger hover:bg-danger/10 text-sm font-semibold transition-colors">
            Matikan 2FA
          </button>
        </form>
      <?php endif; ?>
    </section>

  </main>

  <footer class="border-t border-line px-6 py-6">
    <p class="mx-auto max-w-5xl text-sm text-ink-faint">&copy; 2026 DompetKu.</p>
  </footer>
</body>

</html>
