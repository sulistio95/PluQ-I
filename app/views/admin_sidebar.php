<?php
declare(strict_types=1);

if (!isset($user) || !is_array($user)) {
    return;
}
$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$adminNavItems = [
    ['file' => 'index.php', 'href' => '/admin/', 'label' => 'Transaksi & Ringkasan'],
    ['file' => 'transaction-search.php', 'href' => '/admin/transaction-search.php', 'label' => 'Cari Transaksi'],
    ['file' => 'supplier-quality.php', 'href' => '/admin/supplier-quality.php', 'label' => 'Kualitas Supplier'],
    ['file' => 'api-settings.php', 'href' => '/admin/api-settings.php', 'label' => 'Pengaturan API'],
    ['file' => 'game-mappings.php', 'href' => '/admin/game-mappings.php', 'label' => 'Game List'],
    ['file' => 'template-settings.php', 'href' => '/admin/template-settings.php', 'label' => 'Pengaturan Template'],
    ['file' => 'pricing-settings.php', 'href' => '/admin/pricing-settings.php', 'label' => 'Pengaturan Keuntungan'],
    ['file' => 'supplier-prices.php', 'href' => '/admin/supplier-prices.php', 'label' => 'Harga Modal Supplier'],
];
if (function_exists('admin_can_manage_settings') && admin_can_manage_settings($user)) {
    $adminNavItems[] = ['file' => 'reconcile.php', 'href' => '/admin/reconcile.php', 'label' => 'Rekonsiliasi Duitku'];
}
?>
<aside class="admin-sidebar" aria-label="Navigasi admin">
  <a class="admin-sidebar-brand" href="/admin/">PluQ-I <small>PANEL ADMIN</small></a>
  <div class="admin-sidebar-user">
    <span>Masuk sebagai</span>
    <strong><?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
    <small><?= htmlspecialchars((string) ($user['role'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
  </div>
  <div class="admin-sidebar-section">Menu utama</div>
  <nav class="admin-sidebar-nav" aria-label="Menu utama">
    <?php foreach ($adminNavItems as $item): ?>
      <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $currentPage === $item['file'] ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="admin-sidebar-foot">PluQ-I · Administrasi</div>
</aside>
