<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: keuangan.php');
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

                header('Location: keuangan.php');
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

                $body = '<p>Kode verifikasi akun SI Keuangan anda:</p>'
                    . '<h2 style="letter-spacing:4px">' . $otp . '</h2>'
                    . '<p>Jangan bagikan kode ini kepada siapa pun.</p>';

                if (sendEmail($email, 'Kode Verifikasi SI Keuangan', $body)) {
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
  <title>Daftar - SI Keuangan</title>
  <link rel="stylesheet" href="./dist/output.css" />
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
  <div class="fixed top-[-20%] left-[-10%] w-[60vw] h-[60vw] rounded-full bg-emerald-600/20 blob pointer-events-none">
  </div>
  <div
    class="fixed bottom-[-20%] right-[-10%] w-[60vw] h-[60vw] rounded-full bg-teal-600/20 blob pointer-events-none">
  </div>

  <!-- Header -->
  <header class="p-6 relative z-10 w-full">
    <div class="max-w-7xl mx-auto flex items-center justify-between">
      <a href="index.html" class="text-2xl font-extrabold tracking-tight text-white flex items-center gap-3 group">
        <div
          class="relative w-10 h-10 flex items-center justify-center rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 shadow-lg shadow-emerald-500/30 group-hover:shadow-emerald-500/50 transition-all duration-300 transform group-hover:scale-105">
          <span class="text-white font-black text-sm">Rp</span>
        </div>
        <span class="bg-clip-text text-transparent bg-gradient-to-r from-white to-slate-400">SI Keuangan</span>
      </a>
      <a href="index.html"
        class="px-5 py-2.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-semibold transition-all hover:scale-105 active:scale-95 flex items-center gap-2">
        <span>&larr;</span> Kembali
      </a>
    </div>
  </header>

  <!-- Register main card -->
  <main class="relative z-10 my-auto py-8 px-4 flex items-center justify-center">
    <div
      class="w-full max-w-md glass-card p-8 sm:p-10 rounded-3xl border-t border-white/10 shadow-2xl shadow-emerald-500/10 hover:border-emerald-500/30 transition-all duration-500">

      <!-- Title -->
      <div class="text-center mb-8">
        <h1 class="text-3xl font-black text-white tracking-tight mb-2">
          <?= $step === 2 ? 'Verifikasi Email' : 'Buat Akun Baru' ?>
        </h1>
        <p class="text-slate-400 text-sm font-light">
          <?= $step === 2 ? 'Masukkan kode yang kami kirim ke email anda.' : 'Mari bergabung bersama kami sekarang.' ?>
        </p>
      </div>

      <?php if ($step === 2): ?>
        <?php $fullname = $_SESSION['otp_fullname']; $email = $_SESSION['otp_email']; ?>

        <form action="register.php" method="POST" class="space-y-5">
          <input type="hidden" name="step" value="2" />

          <?php if (!empty($error)): ?>
            <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
              <?= htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <div class="space-y-2">
            <label for="otp" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Kode
              Verifikasi</label>
            <div class="relative group">
              <input type="text" id="otp" name="otp" required maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                placeholder="6 digit kode" class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm tracking-[8px] focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600" />
            </div>
            <p class="text-xs text-slate-500">Dikirim ke <?= htmlspecialchars($email); ?></p>
          </div>

          <button type="submit"
            class="cursor-pointer w-full py-4 mt-6 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold rounded-xl text-sm transition-all duration-300 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/50 hover:-translate-y-1 active:translate-y-0 border border-white/25 ring-1 ring-white/10">
            Verifikasi
          </button>
        </form>
      <?php else: ?>

      <!-- Register form -->
      <form action="register.php" method="POST" class="space-y-5">
        <input type="hidden" name="step" value="1" />

        <!-- Error message -->
        <?php if (!empty($error)): ?>
          <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <!-- Full name input -->
        <div class="space-y-2">
          <label for="fullname" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Nama
            Lengkap</label>
          <div class="relative group">
            <input type="text" id="fullname" name="fullname" required value="<?= htmlspecialchars($fullname); ?>" placeholder="John Doe"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600" />
          </div>
        </div>

        <!-- Email input -->
        <div class="space-y-2">
          <label for="email" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Email
            Address</label>
          <div class="relative group">
            <input type="email" id="email" name="email" required value="<?= htmlspecialchars($email); ?>" placeholder="nama@email.com"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600" />
          </div>
        </div>

        <!-- Password input -->
        <div class="space-y-2">
          <label for="password"
            class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Password</label>
          <div class="relative group">
            <input type="password" id="password" name="password" required placeholder="Minimal 8 karakter"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600 pr-16" />
            <button type="button" id="togglePassword"
              class="cursor-pointer absolute right-4 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-500 hover:text-emerald-400 transition-colors px-2 py-1 uppercase tracking-wider">
              Show
            </button>
          </div>
        </div>

        <!-- Password confirmation input -->
        <div class="space-y-2">
          <label for="confirm_password"
            class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Konfirmasi Password</label>
          <div class="relative group">
            <input type="password" id="confirm_password" name="confirm_password" required placeholder="Ulangi password"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600 pr-16" />
            <button type="button" id="toggleConfirmPassword"
              class="cursor-pointer absolute right-4 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-500 hover:text-emerald-400 transition-colors px-2 py-1 uppercase tracking-wider">
              Show
            </button>
          </div>
        </div>

        <!-- Submit Button -->
        <button type="submit"
          class="cursor-pointer w-full py-4 mt-6 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold rounded-xl text-sm transition-all duration-300 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/50 hover:-translate-y-1 active:translate-y-0 border border-white/25 ring-1 ring-white/10 drop-shadow-[0_0_12px_rgba(255,255,255,0.20)] hover:border-white/40 hover:ring-white/25 hover:drop-shadow-[0_0_18px_rgba(255,255,255,0.35)]">
          Daftar Sekarang
        </button>
      </form>
      <?php endif; ?>

      <!-- Footer -->
      <div class="mt-8 text-center pt-8 border-t border-slate-800/50">
        <p class="text-sm text-slate-400 font-light">
          Sudah punya akun?
          <a href="login.php"
            class="text-emerald-400 font-semibold hover:text-emerald-300 transition-colors ml-1 border-b border-emerald-400/30 hover:border-emerald-400 pb-0.5">Masuk
            di sini</a>
        </p>
      </div>

    </div>
  </main>

  <footer class="p-6 relative z-10 text-center">
    <p class="text-slate-600 text-xs font-medium uppercase tracking-widest">&copy; 2026 SI Keuangan. Secured Registration.
    </p>
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
        btn.textContent = isPassword ? 'HIDE' : 'SHOW';
      });
    }
    bindToggle('password', 'togglePassword');
    bindToggle('confirm_password', 'toggleConfirmPassword');
  </script>
</body>

</html>
