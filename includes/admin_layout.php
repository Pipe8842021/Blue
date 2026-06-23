<?php
/**
 * Layout compartido del panel admin.
 * Variables esperadas antes de incluir:
 *   $pageTitle  string  — título en el topbar
 *   $activePage string  — slug del ítem activo (dashboard|appointments|services|clients|finances|gallery|settings)
 *   $topbarActions string (opcional) — HTML de botones en el topbar
 */
require_once __DIR__ . '/../includes/session.php';
requireRole('admin', '/Blue/login.php');
require_once __DIR__ . '/../includes/functions.php';

$currentUser = currentUser();
$flash       = getFlash();
$pageTitle   ??= 'Panel Admin';
$activePage  ??= '';
$topbarActions ??= '';
$extraCss    ??= [];   // CSS propio de cada módulo (ej. ['/Blue/assets/css/m-agenda.css'])

$navItems = [
    ['slug' => 'dashboard',    'label' => 'Dashboard',    'href' => '/Blue/admin/',               'icon' => '<path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
    ['slug' => 'appointments', 'label' => 'Citas',        'href' => '/Blue/admin/appointments.php','icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'],
    ['slug' => 'services',     'label' => 'Servicios',    'href' => '/Blue/admin/services.php',    'icon' => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>'],
    ['slug' => 'clients',      'label' => 'Clientes',     'href' => '/Blue/admin/clients.php',     'icon' => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>'],
    ['slug' => 'finances',     'label' => 'Finanzas',     'href' => '/Blue/admin/finances.php',    'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>'],
    ['slug' => 'gallery',      'label' => 'Galería',      'href' => '/Blue/admin/gallery.php',     'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>'],
    ['slug' => 'settings',     'label' => 'Configuración','href' => '/Blue/admin/settings.php',    'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>'],
];

// Badge de citas pendientes en el nav
try {
    $db = getDB();
    $pendingCount = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $pendingCount = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> — Blue Therapy Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="/Blue/assets/css/admin.css">
  <?php foreach ($extraCss as $css): ?>
  <link rel="stylesheet" href="<?= e($css) ?>">
  <?php endforeach; ?>
</head>
<body>
<div class="admin-wrap">

<!-- ════════ SIDEBAR ════════ -->
<aside class="admin-sidebar" id="adminSidebar">

  <div class="sidebar-logo">
    <div class="sidebar-logo-mark">
      <svg viewBox="0 0 24 24" fill="#5bc4b8"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
    </div>
    <div class="sidebar-logo-text">
      <div class="sidebar-logo-blue">Blue</div>
      <div class="sidebar-logo-therapy">Therapy</div>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Menú</div>

    <?php foreach ($navItems as $item): ?>
      <a href="<?= $item['href'] ?>"
         class="sidebar-link <?= $activePage === $item['slug'] ? 'active' : '' ?>">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <?= $item['icon'] ?>
        </svg>
        <?= $item['label'] ?>
        <?php if ($item['slug'] === 'appointments' && $pendingCount > 0): ?>
          <span class="sidebar-badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="sidebar-user">
    <div class="sidebar-user-avatar">
      <?= mb_substr($currentUser['name'] ?? 'A', 0, 1) ?>
    </div>
    <div>
      <div class="sidebar-user-name"><?= e($currentUser['name'] ?? '') ?></div>
      <div class="sidebar-user-role"><?= e($currentUser['role'] ?? '') ?></div>
    </div>
    <a href="/Blue/logout.php" class="sidebar-logout" title="Cerrar sesión">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>

</aside>

<!-- ════════ MAIN ════════ -->
<div class="admin-main">

  <!-- Topbar -->
  <header class="admin-topbar">
    <button class="topbar-btn" id="sidebarToggle" style="display:none" aria-label="Menú">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div>
      <div class="topbar-title"><?= e($pageTitle) ?></div>
    </div>
    <div class="topbar-actions">
      <?= $topbarActions ?>
      <a href="/Blue/" target="_blank" class="topbar-btn" title="Ver sitio público">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        <span class="topbar-btn-text">Ver sitio</span>
      </a>
    </div>
  </header>

  <!-- Content -->
  <main class="admin-content">

    <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
