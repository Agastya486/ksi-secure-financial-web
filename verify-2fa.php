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
  <title>Verifikasi 2FA - DompetKu</title>
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
  <div class="glow top-[-15%] right-[-10%] w-[34rem] h-[34rem] bg-accent-600/12"></div>

  <!-- Header -->
  <header class="px-6">
    <div class="mx-auto max-w-5xl h-16 flex items-center">
      <a href="index.html" class="flex items-center gap-2.5">
        <img src="./dist/logo.png" alt="DompetKu" class="w-9 h-9" />
        <span class="font-bold tracking-tight text-white">DompetKu</span>
      </a>
    </div>
  </header>

  <!-- Code verification -->
  <main class="flex-1 flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-sm">

      <div class="mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight">Verifikasi</h1>
        <p class="mt-1 text-ink-muted">Masukkan kode dari aplikasi authenticator.</p>
      </div>

      <form action="verify-2fa.php" method="POST" class="space-y-4">

        <?php if (!empty($error)): ?>
          <div class="p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <div class="space-y-1.5">
          <label for="mfa_code" class="block text-xs font-medium text-ink-muted">Kode</label>
          <input type="text" id="mfa_code" name="mfa_code" required maxlength="10" inputmode="text"
            autocomplete="one-time-code" placeholder="000000"
            class="w-full p-3 rounded-lg bg-surface border border-line text-white placeholder-ink-faint text-center tracking-[0.35em] font-mono focus:outline-none focus:border-accent-600 transition-colors" />
          <p class="text-xs text-ink-faint text-center">
            Dikirim ke <span class="text-ink-muted"><?= htmlspecialchars($email); ?></span>
          </p>
        </div>

        <button type="submit"
          class="cursor-pointer w-full py-2.5 mt-2 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
          Verifikasi
        </button>
      </form>

      <p class="mt-6 text-xs text-ink-faint text-center leading-relaxed">
        HP kamu hilang? Gunakan salah satu kode pemulihan yang kamu simpan saat aktivasi.
      </p>

    </div>
  </main>

  <footer class="border-t border-line px-6 py-6">
    <p class="mx-auto max-w-5xl text-sm text-ink-faint">&copy; 2026 DompetKu.</p>
  </footer>
</body>

</html>