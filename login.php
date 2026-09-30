<?php
session_start();
require_once 'db.php';

// Jika session masih ada, redirect ke keuangan.php
if (isset($_SESSION['user_id'])) {
    header('Location: keuangan.php');
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

            // Verifikasi Password
            if ($user && (password_verify($password, $user['password']) || $password === $user['password'])) {
                // Regenerasi ID sesi untuk mencegah Session Fixation
                session_regenerate_id(true);

                // Simpan data login ke session
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['email']    = $user['email'];
                $_SESSION['fullname'] = $user['fullname'] ?? $user['name'] ?? 'User';

                // Redirect ke halaman keuangan.php setelah berhasil login
                header('Location: keuangan.php');
                exit;
            } else {
                $error = 'Email atau password yang Anda masukkan salah!';
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
  <title>Login - SI Keuangan</title>
  <!-- Import stylesheet Vite / Tailwind -->
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

  <!-- Header / Navigation Back -->
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

  <!-- Login Main Card -->
  <main class="relative z-10 my-auto py-12 px-4 flex items-center justify-center">
    <div
      class="w-full max-w-md glass-card p-8 sm:p-10 rounded-3xl border-t border-white/10 shadow-2xl shadow-emerald-500/10 hover:border-emerald-500/30 transition-all duration-500">

      <!-- Title -->
      <div class="text-center mb-10">
        <h1 class="text-3xl font-black text-white tracking-tight mb-2">Selamat Datang</h1>
        <p class="text-slate-400 text-sm font-light">Masukan kredensial Anda untuk melanjutkan ke dashboard.</p>
      </div>

      <!-- Form Login -->
      <form action="login.php" method="POST" class="space-y-6">

        <!-- Pesan Error Login -->
        <?php if (!empty($error)): ?>
          <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <!-- Input Email -->
        <div class="space-y-2">
          <label for="email" class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Email
            Address</label>
          <div class="relative group">
            <input type="email" id="email" name="email" required value="<?= htmlspecialchars($email); ?>" placeholder="nama@email.com"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600" />
          </div>
        </div>

        <!-- Input Password -->
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <label for="password"
              class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Password</label>
          </div>
          <div class="relative group">
            <input type="password" id="password" name="password" required placeholder="••••••••"
              class="w-full px-5 py-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-white placeholder-slate-600 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all duration-300 group-hover:border-slate-600 pr-16" />
            <button type="button" id="togglePassword"
              class="cursor-pointer absolute right-4 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-500 hover:text-emerald-400 transition-colors px-2 py-1 uppercase tracking-wider">
              Show
            </button>
          </div>
        </div>

        <!-- Submit Button -->
        <button type="submit"
          class="cursor-pointer w-full py-4 mt-4 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold rounded-xl text-sm transition-all duration-300 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/50 hover:-translate-y-1 active:translate-y-0 border border-white/25 ring-1 ring-white/10 drop-shadow-[0_0_12px_rgba(255,255,255,0.20)] hover:border-white/40 hover:ring-white/25 hover:drop-shadow-[0_0_18px_rgba(255,255,255,0.35)]">
          Masuk ke Sistem
        </button>
      </form>

      <!-- Footer / Register Link -->
      <div class="mt-8 text-center pt-8 border-t border-slate-800/50">
        <p class="text-sm text-slate-400 font-light">
          Belum punya akun?
          <a href="register.php"
            class="text-emerald-400 font-semibold hover:text-emerald-300 transition-colors ml-1 border-b border-emerald-400/30 hover:border-emerald-400 pb-0.5">Daftar
            sekarang</a>
        </p>
      </div>

    </div>
  </main>

  <footer class="p-6 relative z-10 text-center">
    <p class="text-slate-600 text-xs font-medium uppercase tracking-widest">&copy; 2026 SI Keuangan. Secured Login.</p>
  </footer>

  <!-- Script Toggle Password -->
  <script>
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePassword');

    toggleBtn.addEventListener('click', () => {
      const isPassword = passwordInput.type === 'password';
      passwordInput.type = isPassword ? 'text' : 'password';
      toggleBtn.textContent = isPassword ? 'HIDE' : 'SHOW';
    });
  </script>
</body>

</html>
