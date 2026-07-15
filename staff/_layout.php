<?php
/**
 * Layout del panel del profesional (staff). Módulo Agenda (Felipe).
 * Variables esperadas antes de incluir:
 *   $pageTitle  string  — título del topbar
 *   $activePage string  — slug activo (dashboard|citas|calendario|configuracion)
 *   $topbarActions string (opcional) — HTML de botones en el topbar
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin('/Blue/login.php');

$currentUser   = currentUser();
$flash         = getFlash();
$pageTitle   ??= 'Mi panel';
$activePage  ??= '';
$topbarActions ??= '';

$navItems = [
    ['slug' => 'dashboard',     'label' => 'Dashboard',     'href' => '/Blue/staff/',                 'icon' => '<path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
    ['slug' => 'citas',         'label' => 'Mis citas',     'href' => '/Blue/staff/citas.php',        'icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'],
    ['slug' => 'calendario',    'label' => 'Calendario',    'href' => '/Blue/staff/calendario.php',   'icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="14"/>'],
    ['slug' => 'configuracion', 'label' => 'Configuración', 'href' => '/Blue/staff/configuracion.php','icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>'],
];

// Badge de citas pendientes del profesional
try {
    $db = getDB();
    $pc = $db->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id = ? AND status = 'pending'");
    $pc->execute([(int)$currentUser['id']]);
    $pendingCount = (int)$pc->fetchColumn();
} catch (Exception $e) {
    $pendingCount = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> — Blue Therapy</title>
  <link rel="stylesheet" href="/Blue/assets/css/admin.css?v=<?= @filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
  <link rel="stylesheet" href="/Blue/assets/css/m-agenda.css?v=<?= @filemtime(__DIR__ . '/../assets/css/m-agenda.css') ?>">
</head>
<body>
<div class="admin-wrap">

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
    <div class="sidebar-section-label">Profesional</div>
    <?php foreach ($navItems as $item): ?>
      <a href="<?= $item['href'] ?>" class="sidebar-link <?= $activePage === $item['slug'] ? 'active' : '' ?>">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
        <?= $item['label'] ?>
        <?php if ($item['slug'] === 'citas' && $pendingCount > 0): ?>
          <span class="sidebar-badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="sidebar-user">
    <div class="sidebar-user-avatar"><?= mb_strtoupper(mb_substr($currentUser['name'] ?? 'P', 0, 1)) ?></div>
    <div>
      <div class="sidebar-user-name"><?= e($currentUser['name'] ?? '') ?></div>
      <div class="sidebar-user-role">Profesional</div>
    </div>
    <a href="/Blue/logout.php" class="sidebar-logout" title="Cerrar sesión">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>
</aside>

<div class="admin-main">
  <header class="admin-topbar">
    <button class="topbar-btn" id="sidebarToggle" style="display:none" aria-label="Menú">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div><div class="topbar-title"><?= e($pageTitle) ?></div></div>
    <div class="topbar-actions"><?= $topbarActions ?></div>
  </header>

  <main class="admin-content">
    <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
