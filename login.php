<?php
session_start();
require_once 'db.php';

// If session still exists, redirect to main page
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email dan Password wajib diisi!';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            // Password verification. Using fake hash so the process time is similar
            $hash = $user['password'] ?? '$2y$10$HCyu1W.kJCT8PT4fpABiIeZDPD3V0zaSmZHNOEoXAxNtKBd6Bdeem';

            if (password_verify($password, $hash) && $user) {
                // If 2FA active, ask for code first
                if (!empty($user['totp_secret'])) {
                    $_SESSION['pending_user_id'] = $user['id'];
                    $_SESSION['mfa_attempts'] = 0;

                    header('Location: verify-2fa.php');
                    exit;
                }

                // New session ID for preventing Session Fixation
                session_regenerate_id(true);

                // Save login data to session
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['email']    = $user['email'];
                $_SESSION['fullname'] = $user['fullname'] ?? $user['name'] ?? 'User';

                // Redirect after login successful
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Email atau password yang anda masukkan salah!';
            }
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
  <title>Login - DompetKu</title>
  <!-- Import stylesheet Vite / Tailwind -->
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
  <div class="glow top-[-15%] left-[-10%] w-[38rem] h-[38rem] bg-accent-600/12"></div>

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

  <!-- Login form -->
  <main class="flex-1 flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-sm">

      <div class="mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight">Masuk</h1>
        <p class="mt-1 text-ink-muted">Gunakan akun yang sudah terdaftar.</p>
      </div>

      <form action="login.php" method="POST" class="space-y-4">

        <?php if (!empty($error)): ?>
          <div class="p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <div class="space-y-1.5">
          <label for="email" class="block text-xs font-medium text-ink-muted">Email</label>
          <input type="email" id="email" name="email" required value="<?= htmlspecialchars($email); ?>"
            placeholder="nama@email.com" autocomplete="email"
            class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
        </div>

        <div class="space-y-1.5">
          <label for="password" class="block text-xs font-medium text-ink-muted">Password</label>
          <div class="relative">
            <input type="password" id="password" name="password" required placeholder="Password" autocomplete="current-password"
              class="w-full p-3 pr-16 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
            <button type="button" id="togglePassword"
              class="cursor-pointer absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-ink-faint hover:text-accent-400 transition-colors">
              Lihat
            </button>
          </div>
        </div>

        <button type="submit"
          class="cursor-pointer w-full py-2.5 mt-2 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
          Masuk
        </button>
      </form>

      <p class="mt-6 text-sm text-ink-muted text-center">
        Belum punya akun?
        <a href="register.php" class="text-accent-400 hover:text-accent-300 transition-colors">Daftar</a>
      </p>

    </div>
  </main>

  <footer class="border-t border-line px-6 py-6">
    <p class="mx-auto max-w-5xl text-sm text-ink-faint">&copy; 2026 DompetKu.</p>
  </footer>

  <!-- Toggle password script -->
  <script>
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePassword');

    toggleBtn.addEventListener('click', () => {
      const isPassword = passwordInput.type === 'password';
      passwordInput.type = isPassword ? 'text' : 'password';
      toggleBtn.textContent = isPassword ? 'Sembunyikan' : 'Lihat';
    });
  </script>
</body>

</html>
