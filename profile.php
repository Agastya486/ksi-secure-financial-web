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
            } elseif ($inputOtp !== $_SESSION['otp']) {
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
                            <p>Email akun Anda di SI Keuangan telah berhasil diubah menjadi: <strong>" . htmlspecialchars($newEmail) . "</strong>.</p>
                        </div>
                    ";
                    sendEmail($newEmail, $subjek, $pesanHtml);
                    
                    $success = 'Profil dan Email Anda berhasil diperbarui!';
                    $_SESSION['fullname'] = $fullname;
                    unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'], $_SESSION['pending_fullname'], $_SESSION['pending_password']);
                    
                    $stmt->execute([':id' => $_SESSION['user_id']]);
                    $user = $stmt->fetch();
                } catch (PDOException $e) {
                    $error = 'Gagal memperbarui profil: ' . $e->getMessage();
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
                            $otp = sprintf("%06d", mt_rand(1, 999999));
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
                        $error = 'Gagal mengecek email: ' . $e->getMessage();
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
                        $error = 'Gagal memperbarui profil: ' . $e->getMessage();
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
  <title>Profil Saya - SI Keuangan</title>
  <link rel="stylesheet" href="./dist/output.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Outfit', sans-serif;
    }

    .glass-nav {
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .glass-card {
      background: rgba(30, 41, 59, 0.4);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
    }

    .grid-pattern {
      background-size: 40px 40px;
      background-image: linear-gradient(to right, rgba(255, 255, 255, 0.02) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
    }

    .blob {
      filter: blur(100px);
      z-index: -1;
      opacity: 0.4;
    }
  </style>
</head>

<body
  class="bg-slate-950 text-slate-200 antialiased min-h-screen flex flex-col justify-between selection:bg-emerald-500 selection:text-white relative overflow-x-hidden">

  <!-- Background decorations -->
  <div class="fixed inset-0 grid-pattern pointer-events-none z-[-2]"></div>
  <div class="fixed top-[-10%] left-[-10%] w-[50vw] h-[50vw] rounded-full bg-emerald-600/20 blob pointer-events-none">
  </div>
  <div class="fixed bottom-[-10%] right-[-10%] w-[50vw] h-[50vw] rounded-full bg-teal-600/10 blob pointer-events-none">
  </div>

  <!-- Navbar -->
  <header class="fixed top-0 left-0 right-0 z-50 glass-nav transition-all duration-300">
    <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
      <a href="keuangan.php" class="text-2xl font-extrabold tracking-tight text-white flex items-center gap-3 group">
        <div
          class="relative w-10 h-10 flex items-center justify-center rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 shadow-lg shadow-emerald-500/30 group-hover:shadow-emerald-500/50 transition-all duration-300 transform group-hover:scale-105">
          <span class="text-white font-black text-sm">Rp</span>
        </div>
        <span class="bg-clip-text text-transparent bg-gradient-to-r from-white to-slate-400 hidden sm:inline-block">SI
          Keuangan</span>
      </a>

      <nav class="hidden md:flex items-center gap-8">
        <a href="keuangan.php" class="text-sm font-medium text-slate-400 hover:text-white transition-colors">Dashboard</a>
        <a href="profile.php"
          class="text-sm font-bold text-white relative after:content-[''] after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-emerald-500 after:rounded-full">Profil</a>
      </nav>

      <div class="flex items-center gap-5">
        <a href="profile.php"
          class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold text-sm hover:scale-105 transition-transform hover:shadow-lg hover:shadow-emerald-500/20 group relative">
          <?= htmlspecialchars($userInitials) ?>
          <span
            class="absolute -bottom-10 opacity-0 group-hover:opacity-100 transition-opacity bg-slate-800 text-xs px-2 py-1 rounded text-white whitespace-nowrap pointer-events-none">
            <?= htmlspecialchars($user['fullname']) ?>
          </span>
        </a>
        <div class="w-px h-6 bg-slate-700/50 hidden sm:block"></div>
        <a href="logout.php"
          class="text-xs font-bold uppercase tracking-wider text-slate-400 hover:text-rose-400 transition-colors flex items-center gap-1">
          <span>Logout</span>
          <span class="text-lg">→</span>
        </a>
      </div>
    </div>
  </header>

  <main class="pt-32 pb-16 px-6 max-w-4xl mx-auto w-full flex-grow relative z-10">

    <!-- Title section -->
    <div class="mb-10 flex flex-col items-center sm:items-start text-center sm:text-left">
      <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-card mb-4 border-emerald-500/30">
        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
        <span class="text-xs font-bold tracking-widest text-emerald-300 uppercase">Pengaturan Akun</span>
      </div>
      <h1 class="text-4xl sm:text-5xl font-black text-white tracking-tight mb-2">Profil Pengguna</h1>
      <p class="text-slate-400 text-base font-light">Kelola informasi pribadi dan kata sandi akun Anda.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

      <!-- Profile summary card (Left) -->
      <div class="md:col-span-1 glass-card p-6 rounded-3xl border-t border-white/10 flex flex-col items-center text-center h-fit">
        <div
          class="w-24 h-24 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-600 flex items-center justify-center text-white font-black text-3xl mb-4 shadow-xl shadow-emerald-500/30">
          <?= htmlspecialchars($userInitials) ?>
        </div>
        <h2 class="text-xl font-extrabold text-white mb-1"><?= htmlspecialchars($user['fullname']) ?></h2>
        <p class="text-xs text-emerald-300 font-medium mb-4"><?= htmlspecialchars($user['email']) ?></p>

        <div class="w-full pt-4 border-t border-slate-800/80 space-y-3 text-left text-xs">
          <div class="flex justify-between items-center text-slate-400">
            <span>Status Akun</span>
            <span class="px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-bold">Aktif</span>
          </div>
          <div class="flex justify-between items-center text-slate-400">
            <span>ID Pengguna</span>
            <span class="font-mono text-slate-300">#<?= htmlspecialchars($user['id']) ?></span>
          </div>
          <div class="flex justify-between items-center text-slate-400">
            <span>Terdaftar</span>
            <span class="text-slate-300">
              <?= !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '-' ?>
            </span>
          </div>
        </div>
      </div>

      <!-- Profile & password edit form (Right) -->
      <div class="md:col-span-2 glass-card p-6 sm:p-8 rounded-3xl border-t border-white/10 shadow-2xl">
        <h2 class="text-xl font-bold text-white mb-6">Perbarui Data Akun</h2>

          <!-- Alert error / Success -->
          <?php if (!empty($error)): ?>
            <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($success)): ?>
            <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm text-center font-medium">
              <?= $success; ?>
            </div>
          <?php endif; ?>

        <?php if (isset($_SESSION['otp'])): ?>
          <!-- OTP Form -->
          <div class="p-6 border border-emerald-500/30 bg-emerald-500/10 rounded-2xl">
             <p class="text-sm text-emerald-200 mb-6 text-center leading-relaxed">
               Kami telah mengirimkan 6-digit kode OTP ke email <strong class="text-white"><?= htmlspecialchars($_SESSION['pending_email'] ?? '') ?></strong>.<br>Silakan masukkan kode tersebut di bawah ini untuk melanjutkan.
             </p>
             <form action="profile.php" method="POST" class="space-y-5">
               <div class="space-y-2">
                 <label for="otp" class="block text-xs font-bold uppercase tracking-widest text-emerald-300 text-center">Kode OTP</label>
                 <input type="text" id="otp" name="otp" required maxlength="6" placeholder="123456"
                  class="w-full px-5 py-4 bg-slate-900/80 border border-emerald-500/50 rounded-xl text-white placeholder-slate-600 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400 transition-all duration-300 text-center tracking-[0.5em] font-mono text-2xl" />
               </div>
               <div class="flex flex-col sm:flex-row gap-3 pt-2">
                 <button type="submit" name="verify_otp"
                   class="flex-1 cursor-pointer py-4 bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/50 hover:-translate-y-1">
                   Verifikasi & Simpan
                 </button>
                 <button type="submit" name="cancel_otp" formnovalidate
                   class="cursor-pointer py-4 px-6 bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold rounded-xl transition-all border border-slate-700">
                   Batal
                 </button>
               </div>
             </form>
          </div>
        <?php else: ?>
        <form action="profile.php" method="POST" class="space-y-6">
          <!-- Full name -->
          <div class="space-y-2">
            <label for="fullname" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Nama Lengkap</label>
            <input type="text" id="fullname" name="fullname" required value="<?= htmlspecialchars($user['fullname']) ?>"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300" />
          </div>

          <!-- Email (Can be changed) -->
          <div class="space-y-2">
            <label for="email" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Email Address</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300" />
          </div>

          <div class="pt-4 border-t border-slate-800/80">
            <h3 class="text-sm font-bold text-white mb-1">Ganti Password (Opsional)</h3>
            <p class="text-xs text-slate-400 font-light mb-4">Biarkan kosong jika tidak ingin mengubah password.</p>

            <div class="space-y-4">
              <!-- Old password -->
              <div class="space-y-2">
                <label for="old_password" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Password Lama</label>
                <input type="password" id="old_password" name="old_password" placeholder="Masukkan password saat ini"
                  class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300" />
              </div>

              <!-- New password -->
              <div class="space-y-2">
                <label for="new_password" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Password Baru</label>
                <input type="password" id="new_password" name="new_password" placeholder="Minimal 8 karakter"
                  class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300" />
              </div>

              <!-- New password confirmation -->
              <div class="space-y-2">
                <label for="confirm_password" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Konfirmasi Password Baru</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Ulangi password baru"
                  class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300" />
              </div>
            </div>
          </div>

          <!-- Submit button -->
          <div class="flex justify-end pt-2">
            <button type="submit" name="update_profile"
              class="cursor-pointer px-8 py-4 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold rounded-xl transition-all duration-300 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/50 hover:-translate-y-1 active:translate-y-0 border border-white/25 ring-1 ring-white/10 drop-shadow-[0_0_12px_rgba(255,255,255,0.20)] hover:border-white/40 hover:ring-white/25 hover:drop-shadow-[0_0_18px_rgba(255,255,255,0.35)]">
              Simpan Perubahan
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>

    </div>

    <!-- 2FA card -->
    <div class="mt-8 glass-card p-6 sm:p-8 rounded-3xl border-t border-white/10 shadow-2xl">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
          <h2 class="text-xl font-bold text-white">Two-Factor Authentication (2FA)</h2>
          <p class="text-sm text-slate-400 font-light mt-1">
            Proteksi tambahan memakai kode dari aplikasi authenticator.
          </p>
        </div>
        <span class="self-start sm:self-center px-3 py-1 rounded-full text-xs font-bold border
          <?= empty($user['totp_secret'])
              ? 'bg-amber-500/10 text-amber-400 border-amber-500/20'
              : 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' ?>">
          <?= empty($user['totp_secret']) ? 'Belum Aktif' : 'Aktif' ?>
        </span>
      </div>

      <?php if (!empty($error) || !empty($success)): ?>
        <div class="mb-5 space-y-3">
          <?php if (!empty($error)): ?>
            <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($success)): ?>
            <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm text-center font-medium">
              <?= htmlspecialchars($success); ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- New backup codes: shown only once -->
      <?php if (!empty($_SESSION['totp_new_codes'])): ?>
        <div class="p-6 border border-emerald-500/30 bg-emerald-500/10 rounded-2xl mb-6">
          <p class="text-sm text-emerald-200 text-center mb-4">
            Simpan kode pemulihan ini sekarang. Kode hanya bisa dipakai <strong>sekali</strong>
            untuk login jika HP anda hilang. Kode ini tidak akan ditampilkan lagi.
          </p>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <?php foreach ($_SESSION['totp_new_codes'] as $code): ?>
              <div class="py-2 px-3 text-center bg-slate-900/70 border border-emerald-500/20 rounded-lg">
                <span class="font-mono text-sm text-white tracking-wider"><?= htmlspecialchars($code) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <form action="profile.php" method="POST" class="mt-5">
            <button type="submit" name="ack_backup_codes"
              class="cursor-pointer w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-xl transition-all">
              Saya Sudah Menyimpannya
            </button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['totp_setup'])): ?>
        <!-- Scan the QR, then prove it works by typing a code -->
        <div class="p-6 border border-emerald-500/30 bg-slate-900/40 rounded-2xl">
          <div class="flex flex-col sm:flex-row gap-6 items-center">
            <div class="shrink-0 p-3 bg-white rounded-xl">
              <?= renderQrCode(getTotpUri($_SESSION['totp_setup'], $user['email'])); ?>
            </div>
            <div class="flex-1 w-full">
              <p class="text-sm text-slate-300 leading-relaxed mb-4">
                Pindai QR di atas dengan Google Authenticator / Authy, lalu masukkan kode 6 digit
                yang muncul untuk mengaktifkan 2FA.
              </p>
              <p class="text-xs text-slate-400 mb-2">Tidak bisa memindai? Masukkan kunci manual:</p>
              <p class="font-mono text-xs text-emerald-300 break-all mb-5 bg-slate-900/70 p-3 rounded-lg border border-slate-700/50">
                <?= htmlspecialchars($_SESSION['totp_setup']); ?>
              </p>
              <form action="profile.php" method="POST" class="space-y-3">
                <input type="text" name="totp_code" required maxlength="6" inputmode="numeric" placeholder="000000"
                  class="w-full px-5 py-3 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm text-center tracking-[0.5em] font-mono focus:outline-none focus:border-emerald-500" />
                <div class="flex flex-col sm:flex-row gap-3">
                  <button type="submit" name="confirm_totp"
                    class="flex-1 cursor-pointer py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold rounded-xl transition-all">
                    Aktivasi
                  </button>
                  <button type="submit" name="cancel_totp"
                    formnovalidate
                    class="cursor-pointer px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold rounded-xl border border-slate-700">
                    Batal
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php elseif (empty($user['totp_secret'])): ?>
        <form action="profile.php" method="POST">
          <button type="submit" name="start_totp"
            class="cursor-pointer px-6 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold rounded-xl transition-all">
            Aktifkan 2FA
          </button>
        </form>
      <?php else: ?>
        <!-- Already active: form to turn it off -->
        <p class="text-sm text-slate-400 mb-4">
          2FA aktif. Login berikutnya akan meminta kode dari aplikasi authenticator.
          Untuk mematikan 2FA, masukkan kode yang sedang aktif.
        </p>
        <form action="profile.php" method="POST" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
          <input type="text" name="totp_code" required maxlength="6" inputmode="numeric" placeholder="000000"
            class="w-full sm:max-w-[220px] px-5 py-3 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm text-center tracking-[0.5em] font-mono focus:outline-none focus:border-rose-500" />
          <button type="submit" name="disable_totp"
            class="cursor-pointer px-6 py-3 bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 text-sm font-bold rounded-xl border border-rose-500/30 transition-all">
            Matikan 2FA
          </button>
        </form>
      <?php endif; ?>
    </div>

  </main>

  <footer class="p-6 relative z-10 text-center glass-nav mt-auto border-t border-white/5">
    <p class="text-slate-600 text-xs font-medium uppercase tracking-widest">&copy; 2026 SI Keuangan. Secured Profile.
    </p>
  </footer>
</body>

</html>
