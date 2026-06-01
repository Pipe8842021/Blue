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
        header('Location: /Blue/admin/services.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'service_save':
                $id    = (int)($_POST['id'] ?? 0);
                $catId = (int)($_POST['category_id'] ?? 0);
                $name  = trim($_POST['name'] ?? '');
                $desc  = trim($_POST['description'] ?? '');
                $dur   = max(1, (int)($_POST['duration_min'] ?? 60));
                $price = max(0, (float)str_replace([',', '.'], ['', ''], $_POST['price'] ?? 0));

                if ($name === '' || $catId === 0) { setFlash('error', 'Nombre y categoría son obligatorios.'); break; }

                if ($id > 0) {
                    $db->prepare("UPDATE services SET category_id=?, name=?, description=?, duration_min=?, price=? WHERE id=?")
                       ->execute([$catId, $name, $desc, $dur, $price, $id]);
                    setFlash('success', 'Servicio actualizado.');
                } else {
                    $db->prepare("INSERT INTO services (category_id, name, description, duration_min, price) VALUES (?,?,?,?,?)")
                       ->execute([$catId, $name, $desc, $dur, $price]);
                    setFlash('success', 'Servicio creado.');
                }
                break;

            case 'service_toggle':
                $db->prepare("UPDATE services SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
                setFlash('info', 'Estado del servicio actualizado.');
                break;

            case 'service_delete':
                try {
                    $db->prepare("DELETE FROM services WHERE id=?")->execute([(int)$_POST['id']]);
                    setFlash('info', 'Servicio eliminado.');
                } catch (PDOException $e) {
                    setFlash('error', 'No se puede eliminar: el servicio está en uso por citas. Desactívalo en su lugar.');
                }
                break;

            case 'category_save':
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($name === '') { setFlash('error', 'El nombre de la categoría es obligatorio.'); break; }
                if ($id > 0) {
                    $db->prepare("UPDATE categories SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
                    setFlash('success', 'Categoría actualizada.');
                } else {
                    $db->prepare("INSERT INTO categories (name, description) VALUES (?,?)")->execute([$name, $desc]);
                    setFlash('success', 'Categoría creada.');
                }
                break;

            case 'category_delete':
                try {
                    $db->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$_POST['id']]);
                    setFlash('info', 'Categoría eliminada.');
                } catch (PDOException $e) {
                    setFlash('error', 'No se puede eliminar: la categoría tiene servicios asociados.');
                }
                break;
        }
    } catch (Exception $e) {
        setFlash('error', 'Ocurrió un error al guardar.');
    }
    header('Location: /Blue/admin/services.php?tab=' . ($_POST['tab'] ?? 'services')); exit;
}

// ── Datos ─────────────────────────────────────────────────
$tab        = $_GET['tab'] ?? 'services';
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$services   = $db->query("
    SELECT s.*, c.name AS category_name
    FROM services s JOIN categories c ON s.category_id = c.id
    ORDER BY c.name, s.name")->fetchAll();

$pageTitle  = 'Servicios';
$activePage = 'services';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Servicios y categorías</h2>
    <p><?= count($services) ?> servicios · <?= count($categories) ?> categorías</p>
  </div>
  <button class="btn btn-primary" id="addBtn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    <span id="addBtnLabel">Nuevo servicio</span>
  </button>
</div>

<div class="tabs">
  <button class="tab <?= $tab==='services'?'active':'' ?>"   onclick="location='?tab=services'">Servicios</button>
  <button class="tab <?= $tab==='categories'?'active':'' ?>" onclick="location='?tab=categories'">Categorías</button>
</div>

<!-- ══════ SERVICIOS ══════ -->
<div class="card" id="panel-services" style="<?= $tab==='services'?'':'display:none' ?>">
  <div class="card-body--flush">
    <?php if (empty($services)): ?>
      <div class="empty-state"><div class="empty-state-icon">💆</div><div class="empty-state-title">Sin servicios</div><div class="empty-state-desc">Crea tu primer servicio.</div></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Servicio</th><th>Categoría</th><th>Duración</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr style="<?= $s['active'] ? '' : 'opacity:.55' ?>">
              <td>
                <div class="client-name"><?= e($s['name']) ?></div>
                <?php if ($s['description']): ?><div class="client-phone" style="max-width:280px;white-space:normal"><?= e($s['description']) ?></div><?php endif; ?>
              </td>
              <td><span class="pill"><?= e($s['category_name']) ?></span></td>
              <td><?= (int)$s['duration_min'] ?> min</td>
              <td style="font-weight:600"><?= formatPrice((float)$s['price']) ?></td>
              <td><?= $s['active'] ? '<span class="badge badge-confirmed">Activo</span>' : '<span class="badge badge-cancelled">Inactivo</span>' ?></td>
              <td>
                <div class="action-btns">
                  <button class="btn-action" onclick='editService(<?= json_encode($s, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="service_toggle">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <button class="btn-action"><?= $s['active'] ? 'Desactivar' : 'Activar' ?></button>
                  </form>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="service_delete">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <button class="btn-action btn-action-cancel" onclick="return confirm('¿Eliminar servicio?')">Eliminar</button>
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

<!-- ══════ CATEGORÍAS ══════ -->
<div class="card" id="panel-categories" style="<?= $tab==='categories'?'':'display:none' ?>">
  <div class="card-body--flush">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Categoría</th><th>Descripción</th><th>Servicios</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c):
          $count = 0; foreach ($services as $s) if ($s['category_id'] == $c['id']) $count++; ?>
          <tr>
            <td><div class="client-name"><?= e($c['name']) ?></div></td>
            <td style="color:var(--muted)"><?= e($c['description'] ?? '—') ?></td>
            <td><span class="pill pill-muted"><?= $count ?></span></td>
            <td>
              <div class="action-btns">
                <button class="btn-action" onclick='editCategory(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="category_delete">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <input type="hidden" name="tab" value="categories">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <button class="btn-action btn-action-cancel" onclick="return confirm('¿Eliminar categoría?')">Eliminar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal servicio -->
<div class="modal-overlay" id="serviceModal">
  <div class="modal">
    <form method="POST">
      <div class="modal-header">
        <span class="modal-title" id="svcModalTitle">Nuevo servicio</span>
        <button type="button" class="modal-close" onclick="closeModal('serviceModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-field full">
            <label>Nombre del servicio</label>
            <input type="text" name="name" id="svc_name" class="form-control" required>
          </div>
          <div class="form-field full">
            <label>Categoría</label>
            <select name="category_id" id="svc_cat" class="form-control" required>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Duración (min)</label>
            <input type="number" name="duration_min" id="svc_dur" class="form-control" value="60" min="1" required>
          </div>
          <div class="form-field">
            <label>Precio (COP)</label>
            <input type="number" name="price" id="svc_price" class="form-control" value="0" min="0" step="1000" required>
          </div>
          <div class="form-field full">
            <label>Descripción</label>
            <textarea name="description" id="svc_desc" class="form-control"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="service_save">
        <input type="hidden" name="id" id="svc_id" value="0">
        <input type="hidden" name="tab" value="services">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('serviceModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal categoría -->
<div class="modal-overlay" id="categoryModal">
  <div class="modal" style="max-width:440px">
    <form method="POST">
      <div class="modal-header">
        <span class="modal-title" id="catModalTitle">Nueva categoría</span>
        <button type="button" class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-field full" style="margin-bottom:14px">
          <label>Nombre</label>
          <input type="text" name="name" id="cat_name" class="form-control" required>
        </div>
        <div class="form-field full">
          <label>Descripción</label>
          <textarea name="description" id="cat_desc" class="form-control"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="category_save">
        <input type="hidden" name="id" id="cat_id" value="0">
        <input type="hidden" name="tab" value="categories">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('categoryModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
const TAB = <?= json_encode($tab) ?>;
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

document.getElementById('addBtn').addEventListener('click', () => {
  if (TAB === 'categories') newCategory(); else newService();
});
document.getElementById('addBtnLabel').textContent = TAB === 'categories' ? 'Nueva categoría' : 'Nuevo servicio';

function newService(){
  document.getElementById('svcModalTitle').textContent = 'Nuevo servicio';
  document.getElementById('svc_id').value = 0;
  document.getElementById('svc_name').value = '';
  document.getElementById('svc_desc').value = '';
  document.getElementById('svc_dur').value = 60;
  document.getElementById('svc_price').value = 0;
  openModal('serviceModal');
}
function editService(s){
  document.getElementById('svcModalTitle').textContent = 'Editar servicio';
  document.getElementById('svc_id').value = s.id;
  document.getElementById('svc_name').value = s.name;
  document.getElementById('svc_cat').value = s.category_id;
  document.getElementById('svc_dur').value = s.duration_min;
  document.getElementById('svc_price').value = parseFloat(s.price);
  document.getElementById('svc_desc').value = s.description || '';
  openModal('serviceModal');
}
function newCategory(){
  document.getElementById('catModalTitle').textContent = 'Nueva categoría';
  document.getElementById('cat_id').value = 0;
  document.getElementById('cat_name').value = '';
  document.getElementById('cat_desc').value = '';
  openModal('categoryModal');
}
function editCategory(c){
  document.getElementById('catModalTitle').textContent = 'Editar categoría';
  document.getElementById('cat_id').value = c.id;
  document.getElementById('cat_name').value = c.name;
  document.getElementById('cat_desc').value = c.description || '';
  openModal('categoryModal');
}
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
