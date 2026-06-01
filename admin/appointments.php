<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Procesar acciones (POST) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido. Intenta de nuevo.');
        header('Location: /Blue/admin/appointments.php'); exit;
    }

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    try {
        switch ($action) {
            case 'confirm':
                $staffId = (int)($_POST['staff_id'] ?? 0) ?: null;
                $s = $db->prepare("UPDATE appointments SET status='confirmed', staff_id=? WHERE id=?");
                $s->execute([$staffId, $id]);
                setFlash('success', 'Cita confirmada correctamente.');
                break;

            case 'cancel':
                $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$id]);
                setFlash('info', 'La cita fue cancelada.');
                break;

            case 'complete':
                $db->beginTransaction();
                $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$id]);

                // Registrar ingreso (una sola vez por cita)
                $exists = $db->prepare("SELECT COUNT(*) FROM finances WHERE appointment_id=? AND type='income'");
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    $t = $db->prepare("
                        SELECT COALESCE(SUM(sv.price),0) AS total, a.date
                        FROM appointments a
                        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
                        LEFT JOIN services sv ON sv.id = aps.service_id
                        WHERE a.id = ?");
                    $t->execute([$id]);
                    $row = $t->fetch();
                    if ($row && (float)$row['total'] > 0) {
                        $db->prepare("INSERT INTO finances (type, category, description, amount, date, appointment_id, registered_by)
                                      VALUES ('income','Servicios',CONCAT('Cita #', ?), ?, ?, ?, ?)")
                           ->execute([$id, $row['total'], $row['date'], $id, currentUser()['id']]);
                    }
                }
                $db->commit();
                setFlash('success', 'Cita completada e ingreso registrado.');
                break;

            case 'assign':
                $staffId = (int)($_POST['staff_id'] ?? 0) ?: null;
                $db->prepare("UPDATE appointments SET staff_id=? WHERE id=?")->execute([$staffId, $id]);
                setFlash('success', 'Profesional asignado.');
                break;

            case 'delete':
                $db->prepare("DELETE FROM appointments WHERE id=?")->execute([$id]);
                setFlash('info', 'Cita eliminada.');
                break;
        }
    } catch (Exception $ex) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', 'No se pudo completar la acción.');
    }

    // Conservar filtros al volver
    $qs = $_POST['return_qs'] ?? '';
    header('Location: /Blue/admin/appointments.php' . ($qs ? '?' . $qs : '')); exit;
}

// ── Filtros (GET) ─────────────────────────────────────────
$fStatus = $_GET['status'] ?? '';
$fDate   = $_GET['date']   ?? '';
$fSearch = trim($_GET['q']  ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$where = [];
$args  = [];
if (in_array($fStatus, ['pending','confirmed','completed','cancelled'], true)) {
    $where[] = 'a.status = ?'; $args[] = $fStatus;
}
if ($fDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate)) {
    $where[] = 'a.date = ?'; $args[] = $fDate;
}
if ($fSearch !== '') {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ?)';
    $args[] = "%$fSearch%"; $args[] = "%$fSearch%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total para paginación
$cnt = $db->prepare("SELECT COUNT(DISTINCT a.id) FROM appointments a JOIN clients c ON a.client_id=c.id $whereSql");
$cnt->execute($args);
$total      = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// Listado
$sql = "
    SELECT a.id, a.date, a.time_start, a.time_end, a.status, a.notes, a.total_duration,
           c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
           u.name AS staff_name,
           GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
           COALESCE(SUM(sv.price),0) AS total_price
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    LEFT JOIN users u ON a.staff_id = u.id
    LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
    LEFT JOIN services sv ON sv.id = aps.service_id
    $whereSql
    GROUP BY a.id
    ORDER BY a.date DESC, a.time_start DESC
    LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// Staff disponible para asignar
$staffList = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();

// Query string para conservar filtros tras una acción
$returnQs = http_build_query(array_filter([
    'status' => $fStatus, 'date' => $fDate, 'q' => $fSearch, 'page' => $page > 1 ? $page : null,
]));

$pageTitle  = 'Citas';
$activePage = 'appointments';
require_once __DIR__ . '/../includes/admin_layout.php';

function badge(string $status): string {
    $map = [
        'pending'   => ['Pendiente',  'pending'],
        'confirmed' => ['Confirmada', 'confirmed'],
        'completed' => ['Completada', 'completed'],
        'cancelled' => ['Cancelada',  'cancelled'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'pending'];
    return "<span class=\"badge badge-{$cls}\">{$label}</span>";
}

function buildUrl(array $overrides): string {
    $base = ['status' => $_GET['status'] ?? '', 'date' => $_GET['date'] ?? '', 'q' => $_GET['q'] ?? ''];
    return '?' . http_build_query(array_filter(array_merge($base, $overrides)));
}
?>

<div class="page-head">
  <div>
    <h2>Gestión de citas</h2>
    <p><?= $total ?> cita(s) <?= $fStatus ? 'con estado «' . e($fStatus) . '»' : 'en total' ?></p>
  </div>
</div>

<!-- Filtros -->
<form method="GET" class="filters-bar">
  <select name="status" class="filter-select" onchange="this.form.submit()">
    <option value="">Todos los estados</option>
    <option value="pending"   <?= $fStatus==='pending'?'selected':'' ?>>Pendientes</option>
    <option value="confirmed" <?= $fStatus==='confirmed'?'selected':'' ?>>Confirmadas</option>
    <option value="completed" <?= $fStatus==='completed'?'selected':'' ?>>Completadas</option>
    <option value="cancelled" <?= $fStatus==='cancelled'?'selected':'' ?>>Canceladas</option>
  </select>

  <input type="date" name="date" class="filter-input" style="min-width:auto"
         value="<?= e($fDate) ?>" onchange="this.form.submit()">

  <input type="text" name="q" class="filter-input" placeholder="Buscar cliente o teléfono…"
         value="<?= e($fSearch) ?>">

  <button type="submit" class="btn btn-sm">Buscar</button>
  <?php if ($fStatus || $fDate || $fSearch): ?>
    <a href="/Blue/admin/appointments.php" class="btn btn-sm btn-ghost">Limpiar</a>
  <?php endif; ?>
</form>

<!-- Tabla -->
<div class="card">
  <div class="card-body--flush">
    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">🔍</div>
        <div class="empty-state-title">Sin resultados</div>
        <div class="empty-state-desc">No hay citas que coincidan con los filtros.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Servicio(s)</th>
              <th>Fecha / Hora</th>
              <th>Profesional</th>
              <th>Total</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $a): ?>
              <tr>
                <td>
                  <div class="client-cell">
                    <div class="client-avatar"><?= mb_substr($a['client_name'], 0, 1) ?></div>
                    <div>
                      <div class="client-name"><?= e($a['client_name']) ?></div>
                      <div class="client-phone"><?= e($a['client_phone']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="max-width:200px"><?= e($a['services'] ?? '—') ?></td>
                <td>
                  <div><?= date('d M Y', strtotime($a['date'])) ?></div>
                  <div style="color:var(--muted);font-size:12px"><?= date('g:i A', strtotime($a['time_start'])) ?></div>
                </td>
                <td><?= $a['staff_name'] ? e($a['staff_name']) : '<span class="pill pill-muted">Sin asignar</span>' ?></td>
                <td style="font-weight:600"><?= formatPrice((float)$a['total_price']) ?></td>
                <td><?= badge($a['status']) ?></td>
                <td>
                  <div class="action-btns">
                    <?php if ($a['status'] === 'pending'): ?>
                      <button class="btn-action btn-action-confirm"
                              onclick='openConfirm(<?= json_encode(["id"=>$a["id"],"client"=>$a["client_name"]]) ?>)'>
                        Confirmar
                      </button>
                      <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                        <button type="submit" class="btn-action btn-action-cancel"
                                onclick="return confirm('¿Cancelar esta cita?')">Cancelar</button>
                      </form>
                    <?php elseif ($a['status'] === 'confirmed'): ?>
                      <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                        <button type="submit" class="btn-action btn-action-confirm"
                                onclick="return confirm('¿Marcar como completada? Se registrará el ingreso.')">Completar</button>
                      </form>
                      <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                        <button type="submit" class="btn-action btn-action-cancel"
                                onclick="return confirm('¿Cancelar esta cita?')">Cancelar</button>
                      </form>
                    <?php else: ?>
                      <button class="btn-action"
                              onclick='openDetail(<?= json_encode([
                                  "client"=>$a["client_name"],"phone"=>$a["client_phone"],"email"=>$a["client_email"],
                                  "services"=>$a["services"],"date"=>date("d M Y",strtotime($a["date"])),
                                  "time"=>date("g:i A",strtotime($a["time_start"]))." – ".date("g:i A",strtotime($a["time_end"])),
                                  "staff"=>$a["staff_name"],"notes"=>$a["notes"],"total"=>formatPrice((float)$a["total_price"]),
                                  "status"=>$a["status"]
                              ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Ver detalle</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Paginación -->
<?php if ($totalPages > 1): ?>
  <div class="pagination">
    <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(buildUrl(['page' => $page - 1])) ?>">‹</a>
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="<?= $p === $page ? 'current' : '' ?>" href="<?= e(buildUrl(['page' => $p])) ?>"><?= $p ?></a>
    <?php endfor; ?>
    <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(buildUrl(['page' => $page + 1])) ?>">›</a>
  </div>
<?php endif; ?>

<!-- Modal: confirmar + asignar -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <span class="modal-title">Confirmar cita</span>
      <button class="modal-close" onclick="closeModal('confirmModal')">&times;</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <p style="font-size:13px;margin-bottom:16px">Confirmar la cita de <strong id="cmClient"></strong>.</p>
        <div class="form-field full">
          <label>Asignar profesional (opcional)</label>
          <select name="staff_id" class="form-control">
            <option value="">— Asignar más tarde —</option>
            <?php foreach ($staffList as $st): ?>
              <option value="<?= $st['id'] ?>"><?= e($st['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="id" id="cmId">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('confirmModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Confirmar cita</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: detalle -->
<div class="modal-overlay" id="detailModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Detalle de la cita</span>
      <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
    </div>
    <div class="modal-body" id="detailBody"></div>
  </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

function openConfirm(data){
  document.getElementById('cmId').value = data.id;
  document.getElementById('cmClient').textContent = data.client;
  openModal('confirmModal');
}

function openDetail(d){
  const row = (l,v) => v ? `<div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f0f1f3;font-size:13px"><span style="color:#9ca3af">${l}</span><span style="font-weight:600;text-align:right">${v}</span></div>` : '';
  document.getElementById('detailBody').innerHTML =
    row('Cliente', d.client) + row('Teléfono', d.phone) + row('Email', d.email) +
    row('Servicios', d.services) + row('Fecha', d.date) + row('Horario', d.time) +
    row('Profesional', d.staff || 'Sin asignar') + row('Total', d.total) +
    (d.notes ? `<div style="margin-top:14px"><div style="color:#9ca3af;font-size:12px;margin-bottom:4px">Notas</div><div style="font-size:13px">${d.notes}</div></div>` : '');
  openModal('detailModal');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
