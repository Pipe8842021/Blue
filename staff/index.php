<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agenda.php';

requireLogin('/Blue/login.php');
$db   = getDB();
$me   = currentUser();
$myId = (int)$me['id'];

// ── Acción: marcar una cita como atendida ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/staff/'); exit;
    }
    if (($_POST['action'] ?? '') === 'complete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $chk = $db->prepare("SELECT COUNT(*) FROM appointments WHERE id=? AND staff_id=? AND status='confirmed'");
            $chk->execute([$id, $myId]);
            if ($chk->fetchColumn()) {
                $db->beginTransaction();
                $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$id]);
                registrarIngresoCita($db, $id, $myId);
                $db->commit();
                setFlash('success', 'Cita marcada como atendida.');
            } else {
                setFlash('error', 'No puedes modificar esa cita.');
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', 'No se pudo completar la acción.');
        }
    }
    header('Location: /Blue/staff/'); exit;
}

// ── Datos de la agenda ────────────────────────────────────
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

function citasDelStaff(PDO $db, int $staffId, string $cond, array $extra = []): array {
    $sql = "
        SELECT a.id, a.date, a.time_start, a.time_end, a.status,
               c.name AS client_name, c.phone AS client_phone,
               GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
               COALESCE(SUM(sv.price),0) AS total_price
        FROM appointments a
        JOIN clients c ON a.client_id = c.id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id
        WHERE a.staff_id = ? AND $cond
        GROUP BY a.id
        ORDER BY a.date, a.time_start";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$staffId], $extra));
    return $stmt->fetchAll();
}

$hoy      = citasDelStaff($db, $myId, "a.date = ? AND a.status <> 'cancelled'", [$today]);
$proximas = citasDelStaff($db, $myId, "a.date > ? AND a.status <> 'cancelled'", [$today]);

$st = $db->prepare("SELECT
    SUM(date = ?  AND status <> 'cancelled') AS hoy,
    SUM(date > ?  AND status <> 'cancelled') AS proximas,
    SUM(status = 'completed' AND date >= ?)  AS completadas_mes
    FROM appointments WHERE staff_id = ?");
$st->execute([$today, $today, $monthStart, $myId]);
$stats = $st->fetch();

$mesesEs = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$diasEs  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$hoyTexto = $diasEs[(int)date('w')] . ', ' . (int)date('j') . ' de ' . $mesesEs[(int)date('n')] . ' de ' . date('Y');

function filaCitaStaff(array $a, bool $conAccion): string {
    $hora = date('g:i A', strtotime($a['time_start'])) . ' – ' . date('g:i A', strtotime($a['time_end']));
    $html  = '<tr>';
    $html .= '<td><div class="client-cell"><div class="client-avatar">' . mb_substr($a['client_name'],0,1) . '</div>';
    $html .= '<div><div class="client-name">' . e($a['client_name']) . '</div>';
    $html .= '<div class="client-phone">' . e($a['client_phone']) . '</div></div></div></td>';
    $html .= '<td style="max-width:220px">' . e($a['services'] ?? '—') . '</td>';
    $html .= '<td><div>' . date('d M Y', strtotime($a['date'])) . '</div><div style="color:var(--muted);font-size:12px">' . $hora . '</div></td>';
    $html .= '<td style="font-weight:600">' . formatPrice((float)$a['total_price']) . '</td>';
    $html .= '<td>' . badgeEstadoCita($a['status']) . '</td>';
    if ($conAccion) {
        $html .= '<td>';
        if ($a['status'] === 'confirmed') {
            $html .= '<form method="POST" onsubmit="return confirm(\'¿Marcar esta cita como atendida?\')">';
            $html .= '<input type="hidden" name="action" value="complete">';
            $html .= '<input type="hidden" name="id" value="' . (int)$a['id'] . '">';
            $html .= '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
            $html .= '<button class="btn-action btn-action-confirm">Marcar atendida</button></form>';
        } elseif ($a['status'] === 'completed') {
            $html .= '<span class="pill">Atendida ✓</span>';
        } else {
            $html .= '<span class="pill pill-muted">En espera</span>';
        }
        $html .= '</td>';
    }
    $html .= '</tr>';
    return $html;
}

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/_layout.php';
?>

<div class="staff-greeting">
  <h1><?= e(saludoSegunHora()) ?>, <?= e(explode(' ', $me['name'])[0]) ?> 👋</h1>
  <p><?= e($hoyTexto) ?></p>
</div>

<div class="stats-grid">
  <div class="stat-card teal">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= (int)$stats['hoy'] ?></div><div class="stat-label">Citas hoy</div></div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= (int)$stats['proximas'] ?></div><div class="stat-label">Próximas</div></div>
  </div>
  <div class="stat-card purple">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= (int)$stats['completadas_mes'] ?></div><div class="stat-label">Atendidas este mes</div></div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Mi agenda de hoy</span>
    <a href="/Blue/staff/citas.php" class="topbar-btn" style="font-size:12px;padding:7px 14px">Ver todas mis citas</a>
  </div>
  <div class="card-body--flush">
    <?php if (empty($hoy)): ?>
      <div class="empty-state"><div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg></div><div class="empty-state-title">Sin citas para hoy</div><div class="empty-state-desc">Disfruta tu día. Las citas que te asignen aparecerán aquí.</div></div>
    <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Cliente</th><th>Servicio(s)</th><th>Fecha / Hora</th><th>Total</th><th>Estado</th><th>Acción</th></tr></thead>
        <tbody><?php foreach ($hoy as $a) echo filaCitaStaff($a, true); ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:22px">
  <div class="card-header"><span class="card-title">Próximas citas</span></div>
  <div class="card-body--flush">
    <?php if (empty($proximas)): ?>
      <div class="empty-state"><div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="empty-state-title">No tienes citas próximas</div><div class="empty-state-desc">Cuando te asignen nuevas citas, las verás aquí.</div></div>
    <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Cliente</th><th>Servicio(s)</th><th>Fecha / Hora</th><th>Total</th><th>Estado</th></tr></thead>
        <tbody><?php foreach ($proximas as $a) echo filaCitaStaff($a, false); ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
