<?php
session_start();
require_once 'db.php';
require_once 'totp.php';

// Redirect to main page if session exist
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Redirect to login if session nonexistent
if (empty($_SESSION['pending_user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$email = '';

// Load the user using the id stored in session, not from user input
try {
    $stmt = $pdo->prepare("SELECT id, fullname, email, totp_secret FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['pending_user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['pending_user_id'], $_SESSION['mfa_attempts']);
        header('Location: login.php');
        exit;
    }

    $email = $user['email'];
} catch (PDOException $e) {
    $error = 'Terjadi kesalahan pada sistem. Silakan coba lagi nanti.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($user)) {
    $code = preg_replace('/\s+/', '', $_POST['mfa_code'] ?? '');

    if (empty($code)) {
        $error = 'Kode verifikasi wajib diisi!';
    } else {
        try {
            // Try TOTP code, if fails, try a backup code
            $valid = verifyTotpCode($user['totp_secret'], $code);

            if (!$valid) {
                $valid = useBackupCode($pdo, $user['id'], $code);
            }

            if ($valid) {
                // New session ID before giving access
                session_regenerate_id(true);

                $_SESSION['user_id']  = $user['id'];
                $_SESSION['email']    = $user['email'];
                $_SESSION['fullname'] = $user['fullname'] ?? 'User';

                unset($_SESSION['pending_user_id'], $_SESSION['mfa_attempts']);

                header('Location: dashboard.php');
                exit;
            }

            // Wrong code: limit the attempts
            $_SESSION['mfa_attempts'] = ($_SESSION['mfa_attempts'] ?? 0) + 1;

            if ($_SESSION['mfa_attempts'] >= 5) {
                unset($_SESSION['pending_user_id'], $_SESSION['mfa_attempts']);
                header('Location: login.php');
                exit;
            }

            $error = 'Kode verifikasi salah! Sisa percobaan: ' . (5 - $_SESSION['mfa_attempts']);
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan pada sistem. Silakan coba lagi nanti.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verifikasi 2FA - SI Keuangan</title>
  <link rel="stylesheet" href="./dist/output.css" />
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Outfit', sans-serif;
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

  <div class="fixed inset-0 grid-pattern pointer-events-none z-[-2]"></div>
  <div class="fixed top-[-20%] left-[-10%] w-[60vw] h-[60vw] rounded-full bg-emerald-600/20 blob pointer-events-none">
  </div>
  <div
    class="fixed bottom-[-20%] right-[-10%] w-[60vw] h-[60vw] rounded-full bg-teal-600/20 blob pointer-events-none">
  </div>

  <main class="relative z-10 my-auto py-12 px-4 flex items-center justify-center">
    <div
      class="w-full max-w-md glass-card p-8 sm:p-10 rounded-3xl border-t border-white/10 shadow-2xl shadow-emerald-500/10">

      <div class="text-center mb-10">
        <h1 class="text-3xl font-black text-white tracking-tight mb-2">Verifikasi Diri</h1>
        <p class="text-slate-400 text-sm font-light">
          Masukkan kode 6 digit dari aplikasi authenticator Anda.
        </p>
      </div>

      <form action="verify-2fa.php" method="POST" class="space-y-6">

        <?php if (!empty($error)): ?>
          <div
            class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <div class="space-y-2">
          <label for="mfa_code" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Kode
            Verifikasi</label>
          <div class="relative group">
            <input type="text" id="mfa_code" name="mfa_code" required maxlength="10" inputmode="text"
              autocomplete="one-time-code" placeholder="000000 atau 4A9F-2C7B"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm text-center tracking-[0.4em] font-mono text-2xl focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300" />
          </div>
          <p class="text-xs text-slate-500 text-center">Pergi ke <strong><?= htmlspecialchars($email); ?></strong></p>
        </div>

        <button type="submit"
          class="cursor-pointer w-full py-4 mt-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold rounded-xl text-sm transition-all duration-300 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/50 hover:-translate-y-1 active:translate-y-0 border border-white/25 ring-1 ring-white/10">
          Verifikasi
        </button>
      </form>

      <div class="mt-8 text-center pt-8 border-t border-slate-800/50">
        <p class="text-xs text-slate-500 leading-relaxed">
          HP Anda hilang? Gunakan salah satu kode pemulihan yang Anda simpan saat aktivasi.
        </p>
      </div>

    </div>
  </main>

  <footer class="p-6 relative z-10 text-center">
    <p class="text-slate-600 text-xs font-medium uppercase tracking-widest">&copy; 2026 SI Keuangan. Secured
      Verification.</p>
  </footer>
</body>

</html>