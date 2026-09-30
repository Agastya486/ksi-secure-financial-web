<?php
session_start();
require_once 'db.php';

// Cek session, jika tidak ada, redirect ke login.php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';

// Tambah Transaksi Baru (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $tanggal    = trim($_POST['tanggal'] ?? date('Y-m-d'));
    $tipe       = trim($_POST['tipe'] ?? '');
    $kategori   = trim($_POST['kategori'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $jumlah     = str_replace(['.', ','], ['', '.'], trim($_POST['jumlah'] ?? ''));

    if (!in_array($tipe, ['pemasukan', 'pengeluaran'])) {
        $error = 'Tipe transaksi harus Pemasukan atau Pengeluaran!';
    } elseif (empty($kategori)) {
        $error = 'Kategori wajib dipilih!';
    } elseif (!is_numeric($jumlah) || (float)$jumlah <= 0) {
        $error = 'Nominal harus angka lebih dari 0!';
    } elseif (empty($tanggal)) {
        $error = 'Tanggal wajib diisi!';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, tipe, kategori, keterangan, jumlah, tanggal) VALUES (:user_id, :tipe, :kategori, :keterangan, :jumlah, :tanggal)");
            $stmt->execute([
                ':user_id'    => $_SESSION['user_id'],
                ':tipe'       => $tipe,
                ':kategori'   => $kategori,
                ':keterangan' => $keterangan !== '' ? $keterangan : null,
                ':jumlah'     => (float)$jumlah,
                ':tanggal'    => $tanggal,
            ]);
            header('Location: keuangan.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Gagal menyimpan transaksi. Silakan coba lagi.';
        }
    }
}

// Ringkasan: total pemasukan, pengeluaran, saldo (milik user login saja)
$totalMasuk = 0;
$totalKeluar = 0;
try {
    $stmt = $pdo->prepare("SELECT tipe, COALESCE(SUM(jumlah), 0) AS total FROM transactions WHERE user_id = :uid GROUP BY tipe");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['tipe'] === 'pemasukan') $totalMasuk = (float)$row['total'];
        if ($row['tipe'] === 'pengeluaran') $totalKeluar = (float)$row['total'];
    }
} catch (PDOException $e) {
    // biarkan 0 jika gagal
}
$saldo = $totalMasuk - $totalKeluar;

// Ambil SEMUA transaksi milik user (filter dilakukan via JS tanpa refresh)
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = :uid ORDER BY tanggal DESC, created_at DESC");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    $transactions = [];
}

// Helper
function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $w) {
        if (!empty($w)) $initials .= strtoupper($w[0]);
    }
    return substr($initials, 0, 2) ?: 'US';
}
function rupiah($n) {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

$kategoriMasuk = ['Gaji', 'Usaha', 'Bonus', 'Hadiah', 'Lainnya'];
$kategoriKeluar = ['Makan', 'Transport', 'Kuliah', 'Belanja', 'Tagihan', 'Hiburan', 'Kesehatan', 'Lainnya'];

$userInitials = getInitials($_SESSION['fullname'] ?? 'User');
$userName     = htmlspecialchars($_SESSION['fullname'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Keuangan - SI Keuangan</title>
  <link rel="stylesheet" href="./dist/output.css" />
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Outfit', sans-serif; }
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
    .blob { filter: blur(100px); z-index: -1; opacity: 0.4; }
  </style>
</head>

<body class="bg-slate-950 text-slate-200 antialiased min-h-screen flex flex-col justify-between selection:bg-emerald-500 selection:text-white relative overflow-x-hidden">

  <div class="fixed inset-0 grid-pattern pointer-events-none z-[-2]"></div>
  <div class="fixed top-[-10%] right-[-10%] w-[50vw] h-[50vw] rounded-full bg-emerald-600/20 blob pointer-events-none"></div>
  <div class="fixed bottom-[-10%] left-[-10%] w-[50vw] h-[50vw] rounded-full bg-teal-600/10 blob pointer-events-none"></div>

  <!-- Navbar -->
  <header class="fixed top-0 left-0 right-0 z-50 glass-nav transition-all duration-300">
    <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
      <a href="keuangan.php" class="text-2xl font-extrabold tracking-tight text-white flex items-center gap-3 group">
        <div class="relative w-10 h-10 flex items-center justify-center rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 shadow-lg shadow-emerald-500/30 group-hover:shadow-emerald-500/50 transition-all duration-300 transform group-hover:scale-105">
          <span class="text-white font-black text-lg">Rp</span>
        </div>
        <span class="bg-clip-text text-transparent bg-gradient-to-r from-white to-slate-400 hidden sm:inline-block">SI Keuangan</span>
      </a>

      <nav class="hidden md:flex items-center gap-8">
        <a href="keuangan.php" class="text-sm font-bold text-white relative after:content-[''] after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-emerald-500 after:rounded-full">Dashboard</a>
        <a href="profile.php" class="text-sm font-medium text-slate-400 hover:text-white transition-colors">Profil</a>
      </nav>

      <div class="flex items-center gap-5">
        <a href="profile.php" class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold text-sm hover:scale-105 transition-transform hover:shadow-lg hover:shadow-emerald-500/20 group relative">
          <?= htmlspecialchars($userInitials) ?>
          <span class="absolute -bottom-10 opacity-0 group-hover:opacity-100 transition-opacity bg-slate-800 text-xs px-2 py-1 rounded text-white whitespace-nowrap pointer-events-none"><?= $userName ?></span>
        </a>
        <div class="w-px h-6 bg-slate-700/50 hidden sm:block"></div>
        <a href="logout.php" class="text-xs font-bold uppercase tracking-wider text-slate-400 hover:text-rose-400 transition-colors flex items-center gap-1">
          <span>Logout</span><span class="text-lg">→</span>
        </a>
      </div>
    </div>
  </header>

  <main class="pt-32 pb-16 px-6 max-w-4xl mx-auto w-full flex-grow relative z-10">

    <div class="mb-10 flex flex-col items-center sm:items-start text-center sm:text-left">
      <h1 class="text-4xl sm:text-5xl font-black text-white tracking-tight mb-2">Dashboard Keuangan</h1>
      <p class="text-slate-400 text-base font-light">Catat pemasukan & pengeluaran harianmu dengan simpel.</p>
    </div>

    <!-- Ringkasan 3 kartu -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-10">
      <div class="glass-card rounded-2xl p-6 border-l-4 border-l-emerald-500">
        <p class="text-xs font-bold uppercase tracking-widest text-emerald-300 mb-1">Pemasukan</p>
        <p class="text-2xl font-black text-white"><?= rupiah($totalMasuk) ?></p>
      </div>
      <div class="glass-card rounded-2xl p-6 border-l-4 border-l-rose-500">
        <p class="text-xs font-bold uppercase tracking-widest text-rose-300 mb-1">Pengeluaran</p>
        <p class="text-2xl font-black text-white"><?= rupiah($totalKeluar) ?></p>
      </div>
      <div class="glass-card rounded-2xl p-6 border-l-4 border-l-teal-500">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-300 mb-1">Saldo</p>
        <p class="text-2xl font-black <?= $saldo >= 0 ? 'text-white' : 'text-rose-400' ?>"><?= rupiah($saldo) ?></p>
      </div>
    </div>

    <!-- Form Tambah Transaksi -->
    <div class="glass-card rounded-3xl p-6 sm:p-8 border-t border-white/10 shadow-2xl shadow-emerald-500/5 mb-10 hover:border-emerald-500/20 transition-all duration-300">
      <h2 class="text-lg font-bold text-white mb-6">Tambah Transaksi Baru</h2>
      <form action="keuangan.php" method="POST" class="space-y-4">
        <input type="hidden" name="action" value="add">

        <?php if (!empty($error)): ?>
          <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm text-center font-medium">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Tanggal</label>
            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required
              class="w-full p-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all" />
          </div>
          <div class="space-y-2">
            <label class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Tipe</label>
            <select name="tipe" id="tipeSelect" required
              class="w-full p-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all">
              <option value="pemasukan">Pemasukan (+)</option>
              <option value="pengeluaran" selected>Pengeluaran (−)</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Kategori</label>
            <select name="kategori" id="kategoriSelect" required
              class="w-full p-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all">
            </select>
          </div>
          <div class="space-y-2">
            <label class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Nominal (Rp)</label>
            <input type="number" name="jumlah" min="1" step="0.01" required placeholder="cth: 50000"
              class="w-full p-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-sm text-white placeholder-slate-600 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all" />
          </div>
        </div>

        <div class="space-y-2">
          <label class="block text-xs font-bold uppercase tracking-widest text-emerald-300">Keterangan (opsional)</label>
          <input type="text" name="keterangan" maxlength="255" placeholder="cth: Makan siang / Gaji bulanan"
            class="w-full p-4 bg-slate-900/50 border border-slate-700/50 rounded-xl text-sm text-white placeholder-slate-600 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all" />
        </div>

        <div class="flex justify-end">
          <button type="submit"
            class="cursor-pointer px-8 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/40 hover:-translate-y-1 active:translate-y-0 border border-white/25 ring-1 ring-white/10 drop-shadow-[0_0_12px_rgba(255,255,255,0.20)] hover:border-white/40 hover:ring-white/25 hover:drop-shadow-[0_0_18px_rgba(255,255,255,0.35)]">
            Simpan Transaksi
          </button>
        </div>
      </form>
    </div>

    <!-- Daftar Transaksi -->
    <div class="space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800/50 pb-4">
        <h2 class="text-xl font-bold text-white">Riwayat Transaksi</h2>
        <div class="flex items-center gap-2">
          <button type="button" data-filter="semua" class="filter-btn text-xs font-bold px-4 py-2 rounded-full transition-all bg-white text-slate-900">Semua</button>
          <button type="button" data-filter="pemasukan" class="filter-btn text-xs font-bold px-4 py-2 rounded-full transition-all bg-slate-800/50 text-slate-400 hover:text-white">Masuk</button>
          <button type="button" data-filter="pengeluaran" class="filter-btn text-xs font-bold px-4 py-2 rounded-full transition-all bg-slate-800/50 text-slate-400 hover:text-white">Keluar</button>
          <span id="trxCount" class="text-xs font-semibold text-slate-500 uppercase tracking-widest bg-slate-800/50 px-3 py-2 rounded-full"><?= count($transactions) ?></span>
        </div>
      </div>

      <div id="trxList" class="grid gap-4">
        <?php if (empty($transactions)): ?>
          <div class="p-8 glass-card rounded-2xl text-center text-slate-400 font-light">
            Belum ada transaksi. Catat pemasukan / pengeluaran pertamamu di atas!
          </div>
        <?php else: ?>
          <div id="trxEmptyFilter" class="hidden p-8 glass-card rounded-2xl text-center text-slate-400 font-light">
            Tidak ada transaksi pada filter ini.
          </div>
          <?php foreach ($transactions as $trx): ?>
            <?php
              $isMasuk = $trx['tipe'] === 'pemasukan';
              $tgl = date('d M Y', strtotime($trx['tanggal']));
            ?>
            <div data-tipe="<?= $trx['tipe'] ?>" class="trx-item p-5 glass-card rounded-2xl border-l-4 <?= $isMasuk ? 'border-l-emerald-500' : 'border-l-rose-500' ?> hover:bg-slate-800/30 transition-all duration-300">
              <div class="flex justify-between items-start gap-3">
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1 flex-wrap">
                    <span class="text-[11px] font-bold uppercase tracking-wider px-2 py-1 rounded-md <?= $isMasuk ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/20' : 'bg-rose-500/15 text-rose-300 border border-rose-500/20' ?>">
                      <?= $isMasuk ? '+ Masuk' : '− Keluar' ?>
                    </span>
                    <span class="text-[11px] font-semibold text-slate-400 bg-slate-800 px-2 py-1 rounded-md"><?= htmlspecialchars($trx['kategori']) ?></span>
                    <span class="text-[11px] text-slate-500"><?= $tgl ?></span>
                  </div>
                  <p class="text-sm text-slate-200 font-medium"><?= !empty($trx['keterangan']) ? htmlspecialchars($trx['keterangan']) : '<span class="text-slate-500 italic">Tanpa keterangan</span>' ?></p>
                </div>
                <div class="text-right shrink-0">
                  <p class="text-base font-black <?= $isMasuk ? 'text-emerald-400' : 'text-rose-400' ?>"><?= ($isMasuk ? '+' : '−') . rupiah($trx['jumlah']) ?></p>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </main>

  <footer class="p-6 relative z-10 text-center glass-nav mt-auto border-t border-white/5">
    <p class="text-slate-600 text-xs font-medium uppercase tracking-widest">&copy; 2026 SI Keuangan. Dashboard Pribadi.</p>
  </footer>

  <script>
    const katMasuk = <?= json_encode($kategoriMasuk) ?>;
    const katKeluar = <?= json_encode($kategoriKeluar) ?>;
    const tipeSelect = document.getElementById('tipeSelect');
    const katSelect = document.getElementById('kategoriSelect');
    function renderKategori() {
      const list = tipeSelect.value === 'pemasukan' ? katMasuk : katKeluar;
      katSelect.innerHTML = list.map(k => `<option value="${k}">${k}</option>`).join('');
    }
    tipeSelect.addEventListener('change', renderKategori);
    renderKategori();

    // Filter Tanpa Refresh: show/hide item via data-tipe
    const filterBtns = document.querySelectorAll('.filter-btn');
    const trxItems = document.querySelectorAll('.trx-item');
    const trxCount = document.getElementById('trxCount');
    const trxEmptyFilter = document.getElementById('trxEmptyFilter');
    const activeStyles = {
      semua: ['bg-white', 'text-slate-900'],
      pemasukan: ['bg-emerald-500', 'text-white'],
      pengeluaran: ['bg-rose-500', 'text-white']
    };
    const idleClasses = ['bg-slate-800/50', 'text-slate-400', 'hover:text-white'];
    function applyFilter(f) {
      let visible = 0;
      trxItems.forEach(el => {
        const show = (f === 'semua' || el.dataset.tipe === f);
        el.classList.toggle('hidden', !show);
        if (show) visible++;
      });
      if (trxEmptyFilter) trxEmptyFilter.classList.toggle('hidden', visible !== 0);
      if (trxCount) trxCount.textContent = visible;
      filterBtns.forEach(btn => {
        const isActive = btn.dataset.filter === f;
        btn.classList.remove('bg-white', 'text-slate-900', 'bg-emerald-500', 'text-white', 'bg-rose-500', ...idleClasses);
        if (isActive) {
          btn.classList.add(...activeStyles[f]);
        } else {
          btn.classList.add(...idleClasses);
        }
      });
    }
    filterBtns.forEach(btn => btn.addEventListener('click', () => applyFilter(btn.dataset.filter)));
  </script>
</body>

</html>
