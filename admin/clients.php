<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/clients.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'save':
                $id    = (int)($_POST['id'] ?? 0);
                $name  = trim($_POST['name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                if ($name === '' || $phone === '') { setFlash('error', 'Nombre y teléfono son obligatorios.'); break; }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { setFlash('error', 'El correo no es válido.'); break; }

                if ($id > 0) {
                    $db->prepare("UPDATE clients SET name=?, phone=?, email=?, notes=? WHERE id=?")
                       ->execute([$name, $phone, $email ?: null, $notes ?: null, $id]);
                    setFlash('success', 'Cliente actualizado.');
                } else {
                    $db->prepare("INSERT INTO clients (name, phone, email, notes) VALUES (?,?,?,?)")
                       ->execute([$name, $phone, $email ?: null, $notes ?: null]);
                    setFlash('success', 'Cliente creado.');
                }
                break;

            case 'delete':
                try {
                    $db->prepare("DELETE FROM clients WHERE id=?")->execute([(int)$_POST['id']]);
                    setFlash('info', 'Cliente eliminado.');
                } catch (PDOException $e) {
                    setFlash('error', 'No se puede eliminar: el cliente tiene citas registradas.');
                }
                break;
        }
    } catch (Exception $e) {
        setFlash('error', 'Ocurrió un error al guardar.');
    }
    header('Location: /Blue/admin/clients.php' . (($q = $_POST['return_q'] ?? '') ? '?q=' . urlencode($q) : '')); exit;
}

// ── Datos ─────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$args = []; $whereSql = '';
if ($search !== '') {
    $whereSql = "WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?";
    $args = ["%$search%", "%$search%", "%$search%"];
}

$sql = "
    SELECT c.*,
           COUNT(DISTINCT a.id) AS total_appts,
           MAX(a.date) AS last_visit,
           SUM(a.status='completed') AS completed_appts,
           COALESCE((
               SELECT SUM(sv.price)
               FROM appointments a2
               JOIN appointment_services aps ON aps.appointment_id = a2.id
               JOIN services sv ON sv.id = aps.service_id
               WHERE a2.client_id = c.id AND a2.status = 'completed'
           ), 0) AS total_spent
    FROM clients c
    LEFT JOIN appointments a ON a.client_id = c.id
    $whereSql
    GROUP BY c.id
    ORDER BY c.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($args);
$clients = $stmt->fetchAll();

// Historial de citas por cliente (para la ficha)
$citasPorCliente = [];
foreach ($db->query("
    SELECT a.client_id, a.date, a.time_start, a.status,
           GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
           COALESCE(SUM(sv.price),0) AS total
    FROM appointments a
    LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
    LEFT JOIN services sv ON sv.id = aps.service_id
    GROUP BY a.id
    ORDER BY a.date DESC, a.time_start DESC") as $r) {
    $citasPorCliente[$r['client_id']][] = $r;
}

// Etiqueta calculada del cliente: solo "Frecuente" (más de 5 citas) y "Nuevo".
// Los intermedios (2–5 citas) no llevan etiqueta.
function etiquetaCliente(int $total, int $completed, float $spent): array {
    if ($total > 5)  return ['Frecuente', 'frecuente'];
    if ($total <= 1) return ['Nuevo', 'nuevo'];
    return ['', ''];
}

$pageTitle  = 'Clientes';
$activePage = 'clients';
$extraCss   = ['/Blue/assets/css/m-catalogo.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-catalogo.css')];
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Clientes</h2>
    <p><?= count($clients) ?> cliente(s) registrados</p>
  </div>
  <button class="btn btn-primary" onclick="newClient()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Nuevo cliente
  </button>
</div>

<form method="GET" class="filters-bar">
  <input type="text" name="q" class="filter-input" placeholder="Buscar por nombre, teléfono o correo…" value="<?= e($search) ?>">
  <button type="submit" class="btn btn-sm">Buscar</button>
  <?php if ($search): ?><a href="/Blue/admin/clients.php" class="btn btn-sm btn-ghost">Limpiar</a><?php endif; ?>
</form>

<div class="card">
  <div class="card-body--flush">
    <?php if (empty($clients)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
        <div class="empty-state-title">Sin clientes</div><div class="empty-state-desc">Los clientes se crean al reservar o manualmente aquí.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Cliente</th><th>Contacto</th><th>Citas</th><th>Gastado</th><th>Última visita</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($clients as $c):
            $tag = etiquetaCliente((int)$c['total_appts'], (int)$c['completed_appts'], (float)$c['total_spent']);
            $ficha = [
                'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], 'notes' => $c['notes'],
                'total' => (int)$c['total_appts'], 'completed' => (int)$c['completed_appts'],
                'spent' => formatPrice((float)$c['total_spent']),
                'last' => $c['last_visit'] ? date('d M Y', strtotime($c['last_visit'])) : '—',
                'tag' => $tag[0], 'tagClass' => $tag[1],
                'wa' => preg_replace('/\D/', '', $c['phone']),
                'citas' => array_map(fn($x) => [
                    'date' => date('d M Y', strtotime($x['date'])),
                    'services' => $x['services'] ?? '—', 'status' => $x['status'],
                    'total' => formatPrice((float)$x['total']),
                ], array_slice($citasPorCliente[$c['id']] ?? [], 0, 8)),
            ]; ?>
            <tr>
              <td>
                <div class="client-cell">
                  <div class="client-avatar"><?= mb_substr($c['name'], 0, 1) ?></div>
                  <div>
                    <div class="client-name"><?= e($c['name']) ?><?php if ($tag[0] !== ''): ?> <span class="cli-tag <?= $tag[1] ?>"><?= $tag[0] ?></span><?php endif; ?></div>
                    <?php if ($c['notes']): ?><div class="client-phone" style="max-width:220px;white-space:normal"><?= e($c['notes']) ?></div><?php endif; ?>
                  </div>
                </div>
              </td>
              <td>
                <div><?= e($c['phone']) ?></div>
                <?php if ($c['email']): ?><div style="color:var(--muted);font-size:12px"><?= e($c['email']) ?></div><?php endif; ?>
              </td>
              <td>
                <span class="pill"><?= (int)$c['total_appts'] ?> total</span>
                <?php if ((int)$c['completed_appts'] > 0): ?><span class="pill pill-muted"><?= (int)$c['completed_appts'] ?> ✓</span><?php endif; ?>
              </td>
              <td style="font-weight:600"><?= formatPrice((float)$c['total_spent']) ?></td>
              <td><?= $c['last_visit'] ? date('d M Y', strtotime($c['last_visit'])) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td>
                <div class="rowmenu">
                  <button type="button" class="rowmenu-trigger" aria-label="Acciones">
                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                  </button>
                  <div class="rowmenu-panel">
                    <button class="rowmenu-item primary" onclick='openFicha(<?= json_encode($ficha, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Ficha</button>

                    <button class="rowmenu-item" onclick='editClient(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Editar</button>

                    <div class="rowmenu-sep"></div>

                    <form method="POST" onsubmit="return confirm('¿Eliminar cliente?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $c['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                      <button type="submit" class="rowmenu-item danger">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>Eliminar</button>
                    </form>
                  </div>
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

<!-- Modal: ficha del cliente -->
<div class="modal-overlay" id="fichaModal">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <span class="modal-title">Ficha del cliente</span>
      <button type="button" class="modal-close" onclick="closeModal('fichaModal')">&times;</button>
    </div>
    <div class="modal-body" id="fichaBody"></div>
  </div>
</div>

<!-- Modal: crear/editar cliente -->
<div class="modal-overlay" id="clientModal">
  <div class="modal" style="max-width:480px">
    <form method="POST">
      <div class="modal-header">
        <span class="modal-title" id="clModalTitle">Nuevo cliente</span>
        <button type="button" class="modal-close" onclick="closeModal('clientModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-field full"><label>Nombre completo</label><input type="text" name="name" id="cl_name" class="form-control" required></div>
          <div class="form-field"><label>Teléfono</label><input type="tel" name="phone" id="cl_phone" class="form-control" required></div>
          <div class="form-field"><label>Correo (opcional)</label><input type="email" name="email" id="cl_email" class="form-control"></div>
          <div class="form-field full"><label>Notas (opcional)</label><textarea name="notes" id="cl_notes" class="form-control" placeholder="Alergias, preferencias, observaciones…"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="cl_id" value="0">
        <input type="hidden" name="return_q" value="<?= e($search) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('clientModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

const BADGE = {pending:['Pendiente','pending'],confirmed:['Confirmada','confirmed'],completed:['Completada','completed'],cancelled:['Cancelada','cancelled']};

function openFicha(c){
  const inicial = esc((c.name||'?').charAt(0).toUpperCase());
  let tl = (c.citas && c.citas.length)
    ? c.citas.map(x => {
        const b = BADGE[x.status] || ['',''];
        return `<div class="ficha-tl-item">
          <div class="ficha-tl-dot cal-${x.status}"></div>
          <div class="ficha-tl-main"><div class="ficha-tl-serv">${esc(x.services)}</div>
            <div class="ficha-tl-meta">${esc(x.date)}</div></div>
          <div class="ficha-tl-right"><span class="badge badge-${b[1]}">${esc(b[0])}</span>
            <div class="ficha-tl-total">${esc(x.total)}</div></div></div>`;
      }).join('')
    : '<div class="empty-state" style="padding:24px"><div class="empty-state-desc">Sin citas registradas.</div></div>';

  document.getElementById('fichaBody').innerHTML = `
    <div class="ficha-head">
      <div class="ficha-avatar">${inicial}</div>
      <div class="ficha-head-info">
        <div class="ficha-name">${esc(c.name)}${c.tag ? ` <span class="cli-tag ${esc(c.tagClass)}">${esc(c.tag)}</span>` : ''}</div>
        <div class="ficha-contact">${esc(c.phone)}${c.email ? ' · ' + esc(c.email) : ''}</div>
      </div>
      ${c.wa ? `<a href="https://wa.me/${esc(c.wa)}" target="_blank" rel="noopener" class="btn btn-whatsapp btn-sm">WhatsApp</a>` : ''}
    </div>
    ${c.notes ? `<div class="ficha-notes"><strong>Notas:</strong> ${esc(c.notes)}</div>` : ''}
    <div class="ficha-stats">
      <div class="ficha-stat"><div class="ficha-stat-val">${c.total}</div><div class="ficha-stat-lbl">Citas</div></div>
      <div class="ficha-stat"><div class="ficha-stat-val">${c.completed}</div><div class="ficha-stat-lbl">Atendidas</div></div>
      <div class="ficha-stat"><div class="ficha-stat-val">${esc(c.spent)}</div><div class="ficha-stat-lbl">Total gastado</div></div>
      <div class="ficha-stat"><div class="ficha-stat-val" style="font-size:13px">${esc(c.last)}</div><div class="ficha-stat-lbl">Última visita</div></div>
    </div>
    <div class="ficha-tl-title">Historial de citas</div>
    <div class="ficha-timeline">${tl}</div>`;
  openModal('fichaModal');
}

function newClient(){
  document.getElementById('clModalTitle').textContent = 'Nuevo cliente';
  ['cl_name','cl_phone','cl_email','cl_notes'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('cl_id').value = 0;
  openModal('clientModal');
}
function editClient(c){
  document.getElementById('clModalTitle').textContent = 'Editar cliente';
  document.getElementById('cl_id').value = c.id;
  document.getElementById('cl_name').value = c.name;
  document.getElementById('cl_phone').value = c.phone;
  document.getElementById('cl_email').value = c.email || '';
  document.getElementById('cl_notes').value = c.notes || '';
  openModal('clientModal');
}
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>
<script src="/Blue/assets/js/row-menu.js"></script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
