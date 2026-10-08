<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$fullname = '';
$email = '';
$step = isset($_SESSION['otp']) ? 2 : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
    // Verify the OTP
    $otp = trim($_POST['otp'] ?? '');

    if (empty($otp)) {
        $error = 'Kode verifikasi wajib diisi!';
    } elseif (time() - $_SESSION['otp_time'] > 300) {
        $error = 'Kode verifikasi kedaluwarsa. Silakan daftar ulang!';
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_email'], $_SESSION['otp_fullname'], $_SESSION['otp_password']);
    } elseif (!hash_equals($_SESSION['otp'], $otp)) {
        $_SESSION['otp_attempts']++;

        if ($_SESSION['otp_attempts'] >= 5) {
            $error = 'Terlalu banyak percobaan. Silakan daftar ulang!';
            unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_attempts'], $_SESSION['otp_email'], $_SESSION['otp_fullname'], $_SESSION['otp_password']);
        } else {
            $error = 'Kode verifikasi salah! Sisa percobaan: ' . (5 - $_SESSION['otp_attempts']);
        }
    } else {
        try {
            $insertStmt = $pdo->prepare("INSERT INTO users (fullname, email, password) VALUES (:fullname, :email, :password)");
            $inserted = $insertStmt->execute([
                ':fullname' => $_SESSION['otp_fullname'],
                ':email'    => $_SESSION['otp_email'],
                ':password' => $_SESSION['otp_password']
            ]);

            if ($inserted) {
                $newUserId = $pdo->lastInsertId();
                $fullname  = $_SESSION['otp_fullname'];
                $email     = $_SESSION['otp_email'];

                unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['otp_attempts'], $_SESSION['otp_email'], $_SESSION['otp_fullname'], $_SESSION['otp_password']);

                session_regenerate_id(true);
                $_SESSION['user_id']  = $newUserId;
                $_SESSION['email']    = $email;
                    $_SESSION['fullname'] = $fullname;

                    header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Gagal mendaftarkan akun. Silakan coba lagi!';
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan pada sistem database. Silakan coba lagi nanti.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname        = trim($_POST['fullname'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Basic input validation
    if (empty($fullname) || empty($email) || empty($password) || empty($confirmPassword)) {
        $error = 'Semua bidang form wajib diisi!';
    } if (strlen($email) > 254){
        $error = 'Email maksimal 254 huruf!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid!';
    } elseif (strlen($fullname) < 2){
        $error = 'Nama minimal 2 huruf!';
    } elseif (strlen($fullname) > 60){
        $error = 'Nama lengkap maksimal 60 huruf!';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal harus 8 karakter!';
    } elseif (strlen($password) > 128){
        $error = 'Password maksimal 128 huruf!';
    } elseif ($password !== $confirmPassword) {
        $error = 'Konfirmasi password tidak cocok!';
    } else {
        try {
            // Check if the email is already used
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $checkStmt->execute([':email' => $email]);

            if ($checkStmt->fetch()) {
                $error = 'Email sudah terdaftar. Silakan gunakan email lain!';
            } else {
                // Send the OTP so we know the email really exists
                $otp = (string) random_int(100000, 999999);

                $body = '<p>Kode verifikasi akun DompetKu anda:</p>'
                    . '<h2 style="letter-spacing:4px">' . $otp . '</h2>'
                    . '<p>Jangan bagikan kode ini kepada siapa pun.</p>';

                if (sendEmail($email, 'Kode Verifikasi DompetKu', $body)) {
                    $_SESSION['otp']         = $otp;
                    $_SESSION['otp_time']    = time();
                    $_SESSION['otp_attempts'] = 0;
                    $_SESSION['otp_email']   = $email;
                    $_SESSION['otp_fullname'] = $fullname;
                    $_SESSION['otp_password'] = password_hash($password, PASSWORD_BCRYPT);
                    $step = 2;
                } else {
                    $error = 'Gagal mengirim email verifikasi. Silakan coba lagi!';
                }
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan pada sistem database. Silakan coba lagi nanti.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Daftar - DompetKu</title>
  <link rel="stylesheet" href="./dist/output.css" />
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
  <div class="glow top-[-15%] left-[-10%] w-[34rem] h-[34rem] bg-accent-600/12"></div>

  <!-- Header -->
  <header class="px-6">
    <div class="mx-auto max-w-5xl h-16 flex items-center justify-between">
      <a href="index.html" class="flex items-center gap-2.5">
        <img src="./dist/logo.png" alt="DompetKu" class="w-9 h-9" />
        <span class="font-bold tracking-tight text-white">DompetKu</span>
      </a>
      <a href="index.html" class="text-sm text-ink-muted hover:text-ink transition-colors">Kembali</a>
    </div>
  </header>

  <!-- Register form -->
  <main class="flex-1 flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-sm">

      <?php if ($step === 2): ?>
        <?php $fullname = $_SESSION['otp_fullname']; $email = $_SESSION['otp_email']; ?>

        <div class="mb-8">
          <h1 class="text-2xl font-bold text-white tracking-tight">Verifikasi email</h1>
          <p class="mt-1 text-ink-muted">Masukkan kode yang kami kirim ke emailmu.</p>
        </div>

        <form action="register.php" method="POST" class="space-y-4">
          <input type="hidden" name="step" value="2" />

          <?php if (!empty($error)): ?>
            <div class="p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <div class="space-y-1.5">
            <label for="otp" class="block text-xs font-medium text-ink-muted">Kode OTP</label>
            <input type="text" id="otp" name="otp" required maxlength="6" inputmode="numeric" autocomplete="one-time-code"
              placeholder="000000"
              class="w-full p-3 rounded-lg bg-surface border border-line text-white placeholder-ink-faint text-center tracking-[0.4em] font-mono focus:outline-none focus:border-accent-600 transition-colors" />
            <p class="text-xs text-ink-faint">Dikirim ke <?= htmlspecialchars($email); ?></p>
          </div>

          <button type="submit"
            class="cursor-pointer w-full py-2.5 mt-2 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
            Verifikasi
          </button>
        </form>
      <?php else: ?>

        <div class="mb-8">
          <h1 class="text-2xl font-bold text-white tracking-tight">Buat akun</h1>
          <p class="mt-1 text-ink-muted">Gratis, hanya butuh satu menit.</p>
        </div>

        <form action="register.php" method="POST" class="space-y-4">
          <input type="hidden" name="step" value="1" />

          <?php if (!empty($error)): ?>
            <div class="p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <div class="space-y-1.5">
            <label for="fullname" class="block text-xs font-medium text-ink-muted">Nama Lengkap</label>
            <input type="text" id="fullname" name="fullname" required value="<?= htmlspecialchars($fullname); ?>"
              placeholder="John Doe" autocomplete="name"
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
          </div>

          <div class="space-y-1.5">
            <label for="email" class="block text-xs font-medium text-ink-muted">Email</label>
            <input type="email" id="email" name="email" required value="<?= htmlspecialchars($email); ?>"
              placeholder="nama@email.com" autocomplete="email"
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
          </div>

          <div class="space-y-1.5">
            <label for="password" class="block text-xs font-medium text-ink-muted">Password</label>
            <div class="relative">
              <input type="password" id="password" name="password" required placeholder="Minimal 8 karakter"
                autocomplete="new-password"
                class="w-full p-3 pr-16 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
              <button type="button" id="togglePassword"
                class="cursor-pointer absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-ink-faint hover:text-accent-400 transition-colors">
                Lihat
              </button>
            </div>
          </div>

          <div class="space-y-1.5">
            <label for="confirm_password" class="block text-xs font-medium text-ink-muted">Konfirmasi Password</label>
            <div class="relative">
              <input type="password" id="confirm_password" name="confirm_password" required placeholder="Ulangi password"
                autocomplete="new-password"
                class="w-full p-3 pr-16 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
              <button type="button" id="toggleConfirmPassword"
                class="cursor-pointer absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-ink-faint hover:text-accent-400 transition-colors">
                Lihat
              </button>
            </div>
          </div>

          <button type="submit"
            class="cursor-pointer w-full py-2.5 mt-2 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
            Daftar
          </button>
        </form>

        <p class="mt-6 text-sm text-ink-muted text-center">
          Sudah punya akun?
          <a href="login.php" class="text-accent-400 hover:text-accent-300 transition-colors">Masuk</a>
        </p>
      <?php endif; ?>

    </div>
  </main>

  <footer class="border-t border-line px-6 py-6">
    <p class="mx-auto max-w-5xl text-sm text-ink-faint">&copy; 2026 DompetKu.</p>
  </footer>

  <!-- Toggle password script -->
  <script>
    function bindToggle(inputId, btnId) {
      const input = document.getElementById(inputId);
      const btn = document.getElementById(btnId);
      if (!input || !btn) return;
      btn.addEventListener('click', () => {
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        btn.textContent = isPassword ? 'Sembunyikan' : 'Lihat';
      });
    }
    bindToggle('password', 'togglePassword');
    bindToggle('confirm_password', 'toggleConfirmPassword');
  </script>
</body>


</html>
