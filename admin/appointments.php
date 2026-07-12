<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agenda.php';

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

            case 'save_cita':
                $r = guardarCita($db, $_POST, null, (int)currentUser()['id']); // admin: profesional según el formulario
                setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
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
           a.client_id, a.staff_id,
           c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
           u.name AS staff_name,
           GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
           GROUP_CONCAT(DISTINCT aps.service_id) AS service_ids,
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

// Listas para el modal de crear/editar cita
$clientes  = obtenerClientes($db);
$servicios = obtenerServiciosActivos($db);

// Query string para conservar filtros tras una acción
$returnQs = http_build_query(array_filter([
    'status' => $fStatus, 'date' => $fDate, 'q' => $fSearch, 'page' => $page > 1 ? $page : null,
]));

$pageTitle  = 'Citas';
$activePage = 'appointments';
$extraCss   = ['/Blue/assets/css/m-agenda.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-agenda.css')];
$topbarActions = '<a href="/Blue/admin/calendario.php" class="topbar-btn">'
               . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>'
               . '<span class="topbar-btn-text">Calendario</span></a>'
               . '<button class="topbar-btn topbar-btn-primary" onclick="openNuevaCita()">+ Nueva cita</button>';
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
        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
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
                    <?php endif; ?>
                    <?php
                      $detData = [
                          "id"=>(int)$a["id"], "client"=>$a["client_name"], "phone"=>$a["client_phone"], "email"=>$a["client_email"],
                          "services"=>$a["services"], "date"=>date("d M Y",strtotime($a["date"])),
                          "time"=>date("g:i A",strtotime($a["time_start"]))." – ".date("g:i A",strtotime($a["time_end"])),
                          "staff"=>$a["staff_name"], "notes"=>$a["notes"], "total"=>formatPrice((float)$a["total_price"]),
                          "status"=>$a["status"],
                      ];
                      $editData = [
                          'id' => (int)$a['id'], 'client_id' => (int)$a['client_id'],
                          'services' => array_map('intval', array_filter(explode(',', (string)$a['service_ids']))),
                          'date' => $a['date'], 'time_start' => $a['time_start'],
                          'status' => $a['status'], 'notes' => $a['notes'], 'staff_id' => $a['staff_id'],
                      ];
                    ?>
                    <button class="btn-action" onclick='openDetail(<?= json_encode($detData, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Detalle</button>
                    <button class="btn-action" onclick='openEditarCita(<?= json_encode($editData, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta cita?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $a['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                      <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                      <button type="submit" class="btn-action btn-action-cancel">Eliminar</button>
                    </form>
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
    <div class="modal-footer">
      <a id="detailWhats" href="#" target="_blank" rel="noopener" class="btn btn-whatsapp">
        <svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M17.5 14.4c-.3-.1-1.7-.8-1.9-.9-.3-.1-.5-.1-.6.1-.2.3-.7.9-.8 1-.2.2-.3.2-.6.1-.3-.1-1.2-.5-2.3-1.4-.9-.8-1.4-1.7-1.6-2-.2-.3 0-.5.1-.6.1-.1.3-.3.4-.5.1-.1.2-.3.2-.4.1-.2 0-.3 0-.5 0-.1-.6-1.5-.8-2-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.2.3-.9.9-.9 2.2s.9 2.5 1.1 2.7c.1.2 1.8 2.8 4.4 3.9.6.3 1.1.4 1.5.5.6.2 1.2.2 1.6.1.5-.1 1.7-.7 1.9-1.3.2-.7.2-1.2.2-1.3-.1-.2-.3-.2-.6-.4zM12 2a10 10 0 00-8.5 15.3L2 22l4.8-1.3A10 10 0 1012 2zm0 18.2c-1.5 0-3-.4-4.3-1.2l-.3-.2-2.9.8.8-2.8-.2-.3A8.2 8.2 0 1112 20.2z"/></svg>
        WhatsApp
      </a>
      <a id="detailPrint" href="#" target="_blank" rel="noopener" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Imprimir
      </a>
    </div>
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

function telWhatsapp(phone){
  let n = (phone || '').replace(/\D/g, '');
  if (n.length === 10) n = '57' + n;   // celular Colombia sin indicativo
  return n;
}
function escHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function openDetail(d){
  const row = (l,v) => v ? `<div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f0f1f3;font-size:13px"><span style="color:#9ca3af">${l}</span><span style="font-weight:600;text-align:right">${escHtml(v)}</span></div>` : '';
  document.getElementById('detailBody').innerHTML =
    row('Cliente', d.client) + row('Teléfono', d.phone) + row('Email', d.email) +
    row('Servicios', d.services) + row('Fecha', d.date) + row('Horario', d.time) +
    row('Profesional', d.staff || 'Sin asignar') + row('Total', d.total) +
    (d.notes ? `<div style="margin-top:14px"><div style="color:#9ca3af;font-size:12px;margin-bottom:4px">Notas</div><div style="font-size:13px">${escHtml(d.notes)}</div></div>` : '');

  // Recordatorio por WhatsApp (mensaje prellenado)
  const msg = `Hola ${d.client}, te recordamos tu cita en *Blue Therapy*:\n`
            + `Fecha: ${d.date}\nHora: ${d.time}\nServicio: ${d.services || ''}\n\n¡Te esperamos!`;
  const wa = document.getElementById('detailWhats');
  const tel = telWhatsapp(d.phone);
  if (tel) { wa.href = 'https://wa.me/' + tel + '?text=' + encodeURIComponent(msg); wa.style.display = ''; }
  else { wa.style.display = 'none'; }
  document.getElementById('detailPrint').href = '/Blue/admin/imprimir_cita.php?id=' + d.id;

  openModal('detailModal');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>

<?php
$esAdmin = true;
require_once __DIR__ . '/../includes/_modal_cita.php';
require_once __DIR__ . '/../includes/admin_footer.php';
?>
