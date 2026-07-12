<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agenda.php';

requireLogin('/Blue/login.php');
$db   = getDB();
$me   = currentUser();
$myId = (int)$me['id'];

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/staff/citas.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    try {
        if ($action === 'save_cita') {
            $r = guardarCita($db, $_POST, $myId, $myId);   // fuerza al profesional actual
            setFlash($r['ok'] ? 'success' : 'error', $r['msg']);

        } elseif ($action === 'delete') {
            eliminarCita($db, $id, $myId);
            setFlash('info', 'Cita eliminada.');

        } elseif (in_array($action, ['confirm','complete','cancel'], true)) {
            $map = ['confirm' => 'confirmed', 'complete' => 'completed', 'cancel' => 'cancelled'];
            $chk = $db->prepare("SELECT COUNT(*) FROM appointments WHERE id=? AND staff_id=?");
            $chk->execute([$id, $myId]);
            if ($chk->fetchColumn()) {
                $db->beginTransaction();
                $db->prepare("UPDATE appointments SET status=? WHERE id=?")->execute([$map[$action], $id]);
                if ($action === 'complete') registrarIngresoCita($db, $id, $myId);
                $db->commit();
                setFlash('success', 'Cita actualizada.');
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', 'No se pudo completar la acción.');
    }
    header('Location: /Blue/staff/citas.php' . (($qs = $_POST['return_qs'] ?? '') ? '?' . $qs : '')); exit;
}

// ── Filtros y listado ─────────────────────────────────────
$fStatus = $_GET['status'] ?? '';
$where = ['a.staff_id = ?']; $args = [$myId];
if (in_array($fStatus, ['pending','confirmed','completed','cancelled'], true)) { $where[] = 'a.status = ?'; $args[] = $fStatus; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = $db->prepare("
    SELECT a.id, a.date, a.time_start, a.time_end, a.status, a.notes, a.client_id, a.staff_id,
           c.name AS client_name, c.phone AS client_phone,
           GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
           GROUP_CONCAT(DISTINCT aps.service_id) AS service_ids,
           COALESCE(SUM(sv.price),0) AS total_price
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
    LEFT JOIN services sv ON sv.id = aps.service_id
    $whereSql
    GROUP BY a.id
    ORDER BY a.date DESC, a.time_start DESC");
$rows->execute($args);
$citas = $rows->fetchAll();

$clientes  = obtenerClientes($db);
$servicios = obtenerServiciosActivos($db);
$returnQs  = http_build_query(array_filter(['status' => $fStatus]));

$pageTitle  = 'Mis citas';
$activePage = 'citas';
$topbarActions = '<button class="topbar-btn topbar-btn-primary" onclick="openNuevaCita()">+ Nueva cita</button>';
require_once __DIR__ . '/_layout.php';
?>

<div class="page-head">
  <div><h2>Mis citas</h2><p><?= count($citas) ?> cita(s)<?= $fStatus ? ' · ' . e($fStatus) : '' ?></p></div>
</div>

<form method="GET" class="filters-bar">
  <select name="status" class="filter-select" onchange="this.form.submit()">
    <option value="">Todos los estados</option>
    <option value="pending"   <?= $fStatus==='pending'?'selected':'' ?>>Pendientes</option>
    <option value="confirmed" <?= $fStatus==='confirmed'?'selected':'' ?>>Confirmadas</option>
    <option value="completed" <?= $fStatus==='completed'?'selected':'' ?>>Atendidas</option>
    <option value="cancelled" <?= $fStatus==='cancelled'?'selected':'' ?>>Canceladas</option>
  </select>
  <?php if ($fStatus): ?><a href="/Blue/staff/citas.php" class="btn btn-sm btn-ghost">Limpiar</a><?php endif; ?>
</form>

<div class="card"><div class="card-body--flush">
  <?php if (empty($citas)): ?>
    <div class="empty-state"><div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="empty-state-title">Sin citas</div><div class="empty-state-desc">Crea una cita con el botón «Nueva cita».</div></div>
  <?php else:
    $icoCheck  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    $icoDone   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    $icoEdit   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    $icoTrash  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>';
  ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Cliente</th><th>Servicio(s)</th><th>Fecha / Hora</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($citas as $a):
        $data = [
          'id' => (int)$a['id'], 'client_id' => (int)$a['client_id'],
          'services' => array_map('intval', array_filter(explode(',', (string)$a['service_ids']))),
          'date' => $a['date'], 'time_start' => $a['time_start'],
          'status' => $a['status'], 'notes' => $a['notes'], 'staff_id' => $a['staff_id'],
        ]; ?>
        <tr>
          <td><div class="client-cell"><div class="client-avatar"><?= mb_substr($a['client_name'],0,1) ?></div>
            <div><div class="client-name"><?= e($a['client_name']) ?></div><div class="client-phone"><?= e($a['client_phone']) ?></div></div></div></td>
          <td style="max-width:200px"><?= e($a['services'] ?? '—') ?></td>
          <td><div><?= date('d M Y', strtotime($a['date'])) ?></div><div style="color:var(--muted);font-size:12px"><?= date('g:i A', strtotime($a['time_start'])) ?></div></td>
          <td style="font-weight:600"><?= formatPrice((float)$a['total_price']) ?></td>
          <td><?= badgeEstadoCita($a['status']) ?></td>
          <td>
            <div class="rowmenu">
              <button type="button" class="rowmenu-trigger" aria-label="Acciones">
                <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
              </button>
              <div class="rowmenu-panel">
                <?php if ($a['status'] === 'pending'): ?>
                  <?= botonAccionCita('confirm', $a['id'], $returnQs, 'primary', 'Confirmar', '', $icoCheck) ?>
                <?php elseif ($a['status'] === 'confirmed'): ?>
                  <?= botonAccionCita('complete', $a['id'], $returnQs, 'primary', 'Marcar atendida', '¿Marcar como atendida?', $icoDone) ?>
                <?php endif; ?>
                <button class="rowmenu-item" onclick='openEditarCita(<?= json_encode($data, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><?= $icoEdit ?>Editar</button>
                <div class="rowmenu-sep"></div>
                <?= botonAccionCita('delete', $a['id'], $returnQs, 'danger', 'Eliminar', '¿Eliminar esta cita?', $icoTrash) ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div></div>

<script src="/Blue/assets/js/row-menu.js"></script>

<?php
$esAdmin = false;
require_once __DIR__ . '/../includes/_modal_cita.php';
require_once __DIR__ . '/_footer.php';

// Helper local: item de menú con formulario (POST)
function botonAccionCita(string $action, int $id, string $returnQs, string $cls, string $label, string $confirm = '', string $icon = ''): string {
    $on = $confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '';
    return '<form method="POST"' . $on . '>'
         . '<input type="hidden" name="action" value="' . e($action) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . '<input type="hidden" name="return_qs" value="' . e($returnQs) . '">'
         . '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">'
         . '<button class="rowmenu-item ' . $cls . '">' . $icon . e($label) . '</button></form>';
}
?>
