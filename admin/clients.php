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
           COUNT(a.id) AS total_appts,
           MAX(a.date) AS last_visit,
           SUM(a.status='completed') AS completed_appts
    FROM clients c
    LEFT JOIN appointments a ON a.client_id = c.id
    $whereSql
    GROUP BY c.id
    ORDER BY c.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($args);
$clients = $stmt->fetchAll();

$pageTitle  = 'Clientes';
$activePage = 'clients';
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
      <div class="empty-state"><div class="empty-state-icon">👥</div><div class="empty-state-title">Sin clientes</div><div class="empty-state-desc">Los clientes se crean al reservar o manualmente aquí.</div></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Cliente</th><th>Contacto</th><th>Citas</th><th>Última visita</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td>
                <div class="client-cell">
                  <div class="client-avatar"><?= mb_substr($c['name'], 0, 1) ?></div>
                  <div>
                    <div class="client-name"><?= e($c['name']) ?></div>
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
              <td><?= $c['last_visit'] ? date('d M Y', strtotime($c['last_visit'])) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td>
                <div class="action-btns">
                  <a class="btn-action" href="https://wa.me/<?= preg_replace('/\D/', '', $c['phone']) ?>" target="_blank">WhatsApp</a>
                  <button class="btn-action" onclick='editClient(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <button class="btn-action btn-action-cancel" onclick="return confirm('¿Eliminar cliente?')">Eliminar</button>
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

<!-- Modal cliente -->
<div class="modal-overlay" id="clientModal">
  <div class="modal" style="max-width:480px">
    <form method="POST">
      <div class="modal-header">
        <span class="modal-title" id="clModalTitle">Nuevo cliente</span>
        <button type="button" class="modal-close" onclick="closeModal('clientModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-field full">
            <label>Nombre completo</label>
            <input type="text" name="name" id="cl_name" class="form-control" required>
          </div>
          <div class="form-field">
            <label>Teléfono</label>
            <input type="tel" name="phone" id="cl_phone" class="form-control" required>
          </div>
          <div class="form-field">
            <label>Correo (opcional)</label>
            <input type="email" name="email" id="cl_email" class="form-control">
          </div>
          <div class="form-field full">
            <label>Notas (opcional)</label>
            <textarea name="notes" id="cl_notes" class="form-control" placeholder="Alergias, preferencias, observaciones…"></textarea>
          </div>
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

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
