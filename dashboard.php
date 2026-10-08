<?php
session_start();
require_once 'db.php';

// Check session, if nothing, redirect to login.php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';

// Add new POST transaction
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
            header('Location: dashboard.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Gagal menyimpan transaksi. Silakan coba lagi.';
        }
    }
}

// Summary of user's finance
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
    // let it 0 if fail
}
$saldo = $totalMasuk - $totalKeluar;

// Take all user's transaction for filter
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = :uid ORDER BY tanggal DESC, created_at DESC");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    $transactions = [];
}

// Helper for initial avatar pfp
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
  <title>Dashboard Keuangan - DompetKu</title>
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
  <div class="glow top-[-20%] right-[-10%] w-[40rem] h-[40rem] bg-accent-600/10"></div>

  <!-- Navbar -->
  <header class="sticky top-0 z-50 border-b border-line/70 bg-surface/80 backdrop-blur-xl">
    <div class="mx-auto max-w-5xl px-6 h-16 flex items-center justify-between">
      <a href="dashboard.php" class="flex items-center gap-2.5">
        <img src="./dist/logo.png" alt="DompetKu" class="w-9 h-9" />
        <span class="font-bold tracking-tight text-white">DompetKu</span>
      </a>

      <nav class="hidden sm:flex items-center gap-6 text-sm">
        <a href="dashboard.php" class="text-white font-medium">Dashboard</a>
        <a href="profile.php" class="text-ink-muted hover:text-ink transition-colors">Profil</a>
      </nav>

      <div class="flex items-center gap-3">
        <a href="profile.php"
          class="w-9 h-9 grid place-items-center rounded-lg border border-line bg-raised text-sm font-semibold text-accent-400 hover:border-accent-600 transition-colors"
          title="<?= $userName ?>"><?= htmlspecialchars($userInitials) ?></a>
        <a href="logout.php" class="text-sm text-ink-muted hover:text-danger transition-colors">Keluar</a>
      </div>
    </div>
  </header>

  <main class="mx-auto max-w-5xl w-full px-6 py-10 flex-grow">

    <h1 class="text-2xl font-bold text-white tracking-tight">Dashboard</h1>
    <p class="mt-1 text-ink-muted">Catat pemasukan dan pengeluaran harianmu.</p>

    <!-- Summary (3 cards) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-8 mb-10">
      <div class="rounded-xl border border-line bg-raised/50 p-5">
        <p class="text-xs text-ink-faint">Pemasukan</p>
        <p class="mt-1 text-xl font-bold text-accent-400"><?= rupiah($totalMasuk) ?></p>
      </div>
      <div class="rounded-xl border border-line bg-raised/50 p-5">
        <p class="text-xs text-ink-faint">Pengeluaran</p>
        <p class="mt-1 text-xl font-bold text-danger"><?= rupiah($totalKeluar) ?></p>
      </div>
      <div class="rounded-xl border border-line bg-raised/50 p-5">
        <p class="text-xs text-ink-faint">Saldo</p>
        <p class="mt-1 text-xl font-bold <?= $saldo >= 0 ? 'text-white' : 'text-danger' ?>"><?= rupiah($saldo) ?></p>
      </div>
    </div>

    <!-- Add transaction form -->
    <section class="rounded-xl border border-line bg-raised/40 p-6 mb-10">
      <h2 class="font-semibold text-white mb-5">Tambah Transaksi</h2>

      <form action="dashboard.php" method="POST" class="space-y-4">
        <input type="hidden" name="action" value="add">

        <?php if (!empty($error)): ?>
          <div class="p-3 rounded-lg bg-danger/10 border border-danger/30 text-danger text-sm text-center">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-1.5">
            <label class="block text-xs font-medium text-ink-muted">Tanggal</label>
            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white focus:outline-none focus:border-accent-600 transition-colors" />
          </div>
          <div class="space-y-1.5">
            <label class="block text-xs font-medium text-ink-muted">Tipe</label>
            <select name="tipe" id="tipeSelect" required
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white focus:outline-none focus:border-accent-600 transition-colors">
              <option value="pemasukan">Pemasukan (+)</option>
              <option value="pengeluaran" selected>Pengeluaran (−)</option>
            </select>
          </div>
          <div class="space-y-1.5">
            <label class="block text-xs font-medium text-ink-muted">Kategori</label>
            <select name="kategori" id="kategoriSelect" required
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white focus:outline-none focus:border-accent-600 transition-colors">
            </select>
          </div>
          <div class="space-y-1.5">
            <label class="block text-xs font-medium text-ink-muted">Nominal (Rp)</label>
            <input type="number" name="jumlah" min="1" step="0.01" required placeholder="50000"
              class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="block text-xs font-medium text-ink-muted">Keterangan <span class="text-ink-faint">(opsional)</span></label>
          <input type="text" name="keterangan" maxlength="255" placeholder="Makan siang"
            class="w-full p-3 rounded-lg bg-surface border border-line text-sm text-white placeholder-ink-faint focus:outline-none focus:border-accent-600 transition-colors" />
        </div>

        <div class="flex justify-end">
          <button type="submit"
            class="cursor-pointer px-5 py-2.5 bg-accent-600 hover:bg-accent-500 text-white text-sm font-semibold rounded-lg transition-colors">
            Simpan
          </button>
        </div>
      </form>
    </section>

    <!-- Transaction history -->
    <section>
      <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-line">
        <h2 class="font-semibold text-white">Riwayat Transaksi</h2>

        <div class="flex items-center gap-2">
          <button type="button" data-filter="semua"
            class="filter-btn text-xs font-medium px-3 py-1.5 rounded-md transition-colors bg-accent-600 text-white">Semua</button>
          <button type="button" data-filter="pemasukan"
            class="filter-btn text-xs font-medium px-3 py-1.5 rounded-md transition-colors text-ink-muted hover:text-ink border border-line">Masuk</button>
          <button type="button" data-filter="pengeluaran"
            class="filter-btn text-xs font-medium px-3 py-1.5 rounded-md transition-colors text-ink-muted hover:text-ink border border-line">Keluar</button>
          <span id="trxCount" class="text-xs text-ink-faint tabular-nums"><?= count($transactions) ?></span>
        </div>
      </div>

      <div id="trxList" class="divide-y divide-line">
        <?php if (empty($transactions)): ?>
          <div class="py-12 text-center text-ink-muted">
            Belum ada transaksi. Catat yang pertama di atas.
          </div>
        <?php else: ?>
          <div id="trxEmptyFilter" class="hidden py-12 text-center text-ink-muted">
            Tidak ada transaksi pada filter ini.
          </div>
          <?php foreach ($transactions as $trx): ?>
            <?php
              $isMasuk = $trx['tipe'] === 'pemasukan';
              $tgl = date('d M Y', strtotime($trx['tanggal']));
            ?>
            <div data-tipe="<?= $trx['tipe'] ?>"
              class="trx-item flex items-center gap-4 py-3.5">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-ink">
                  <?= !empty($trx['keterangan']) ? htmlspecialchars($trx['keterangan']) : '<span class="text-ink-faint">Tanpa keterangan</span>' ?>
                </p>
                <p class="mt-0.5 text-xs text-ink-faint">
                  <?= htmlspecialchars($trx['kategori']) ?> &middot; <?= $tgl ?>
                </p>
              </div>
              <span class="shrink-0 text-sm font-semibold tabular-nums <?= $isMasuk ? 'text-accent-400' : 'text-danger' ?>">
                <?= ($isMasuk ? '+' : '−') . rupiah($trx['jumlah']) ?>
              </span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <footer class="border-t border-line px-6 py-6">
    <p class="mx-auto max-w-5xl text-sm text-ink-faint">&copy; 2026 DompetKu.</p>
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

    // Filter without refreshing page
    const filterBtns = document.querySelectorAll('.filter-btn');
    const trxItems = document.querySelectorAll('.trx-item');
    const trxCount = document.getElementById('trxCount');
    const trxEmptyFilter = document.getElementById('trxEmptyFilter');
    const activeClass = 'bg-accent-600';
    const activeText = 'text-white';
    const idleText = 'text-ink-muted';
    const idleBorder = 'border-line';
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
        btn.classList.toggle(activeClass, isActive);
        btn.classList.toggle(activeText, isActive);
        btn.classList.toggle(idleText, !isActive);
        btn.classList.toggle(idleBorder, !isActive);
      });
    }
    filterBtns.forEach(btn => btn.addEventListener('click', () => applyFilter(btn.dataset.filter)));
  </script>
</body>

</html>
