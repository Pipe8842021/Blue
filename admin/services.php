<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-catalogo.php';

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
                $id       = (int)($_POST['id'] ?? 0);
                $catId    = (int)($_POST['category_id'] ?? 0);
                $name     = trim($_POST['name'] ?? '');
                $desc     = trim($_POST['description'] ?? '');
                $dur      = max(1, (int)($_POST['duration_min'] ?? 60));
                // El campo es <input type="number">: el navegador envía siempre
                // formato canónico (punto decimal, sin separador de miles), así que
                // basta castear. Conserva decimales: "80000.50" -> 80000.50.
                $price    = max(0, (float)($_POST['price'] ?? 0));
                $featured = isset($_POST['featured']) ? 1 : 0;

                if ($name === '' || $catId === 0) { setFlash('error', 'Nombre y categoría son obligatorios.'); break; }

                // Imagen que ya tenía el servicio (solo al editar)
                $imagenActual = null;
                if ($id > 0) {
                    $stmt = $db->prepare("SELECT image FROM services WHERE id=?");
                    $stmt->execute([$id]);
                    $imagenActual = $stmt->fetchColumn() ?: null;
                }

                // Subida (valida extensión, MIME real y tamaño; renombra el archivo)
                $subida = guardarImagenServicio($_FILES['image'] ?? []);
                if (!$subida['ok']) { setFlash('error', $subida['error']); break; }

                $imagen = $imagenActual;
                if ($subida['file'] !== null) {              // subió una nueva → reemplaza
                    $imagen = $subida['file'];
                    eliminarImagenServicio($imagenActual);
                } elseif (isset($_POST['remove_image'])) {   // pidió quitar la actual
                    eliminarImagenServicio($imagenActual);
                    $imagen = null;
                }

                if ($id > 0) {
                    $db->prepare("UPDATE services SET category_id=?, name=?, description=?, image=?, duration_min=?, price=?, featured=? WHERE id=?")
                       ->execute([$catId, $name, $desc, $imagen, $dur, $price, $featured, $id]);
                    setFlash('success', 'Servicio actualizado.');
                } else {
                    $db->prepare("INSERT INTO services (category_id, name, description, image, duration_min, price, featured) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$catId, $name, $desc, $imagen, $dur, $price, $featured]);
                    setFlash('success', 'Servicio creado.');
                }
                break;

            case 'service_toggle':
                $db->prepare("UPDATE services SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
                setFlash('info', 'Estado del servicio actualizado.');
                break;

            case 'service_feature':
                $db->prepare("UPDATE services SET featured = 1 - featured WHERE id=?")->execute([(int)$_POST['id']]);
                setFlash('info', 'Servicio destacado actualizado.');
                break;

            case 'service_delete':
                try {
                    $sid  = (int)$_POST['id'];
                    $stmt = $db->prepare("SELECT image FROM services WHERE id=?");
                    $stmt->execute([$sid]);
                    $imagen = $stmt->fetchColumn() ?: null;

                    $db->prepare("DELETE FROM services WHERE id=?")->execute([$sid]);
                    eliminarImagenServicio($imagen);   // solo si el DELETE no lanzó excepción
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

    // Vuelve a la pestaña y al filtro de categoría en que estaba (patrón PRG)
    $destino = '/Blue/admin/services.php?tab=' . rawurlencode($_POST['tab'] ?? 'services');
    $catPost = (int)($_POST['cat'] ?? 0);
    if ($catPost > 0) $destino .= '&cat=' . $catPost;
    header('Location: ' . $destino); exit;
}

// ── Datos ─────────────────────────────────────────────────
$tab        = $_GET['tab'] ?? 'services';
$catFiltro  = (int)($_GET['cat'] ?? 0);
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Servicios (filtrados por categoría si se pidió). Los destacados van primero.
$sql    = "SELECT s.*, c.name AS category_name
           FROM services s JOIN categories c ON s.category_id = c.id";
$params = [];
if ($catFiltro > 0) { $sql .= " WHERE s.category_id = ?"; $params[] = $catFiltro; }
$sql   .= " ORDER BY s.featured DESC, c.name, s.name";
$stmt   = $db->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

// Conteo por categoría (sin filtrar) para los chips y la pestaña de categorías
$conteoCat = [];
$totalServicios = 0;
foreach ($db->query("SELECT category_id, COUNT(*) AS n FROM services GROUP BY category_id") as $row) {
    $conteoCat[(int)$row['category_id']] = (int)$row['n'];
    $totalServicios += (int)$row['n'];
}

$pageTitle  = 'Servicios';
$activePage = 'services';
$extraCss   = ['/Blue/assets/css/m-catalogo.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-catalogo.css')];
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Servicios y categorías</h2>
    <p><?= $totalServicios ?> servicios · <?= count($categories) ?> categorías</p>
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
<div id="panel-services" style="<?= $tab==='services'?'':'display:none' ?>">

  <!-- Filtro por categoría -->
  <div class="cat-filter">
    <a class="cat-chip <?= $catFiltro===0?'active':'' ?>" href="?tab=services">
      Todas <span class="cat-chip-n"><?= $totalServicios ?></span>
    </a>
    <?php foreach ($categories as $c): ?>
      <a class="cat-chip <?= $catFiltro===(int)$c['id']?'active':'' ?>" href="?tab=services&amp;cat=<?= (int)$c['id'] ?>">
        <?= e($c['name']) ?> <span class="cat-chip-n"><?= $conteoCat[(int)$c['id']] ?? 0 ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-body--flush">
      <?php if (empty($services)): ?>
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
          </div>
          <div class="empty-state-title"><?= $catFiltro > 0 ? 'Sin servicios en esta categoría' : 'Sin servicios' ?></div>
          <div class="empty-state-desc"><?= $catFiltro > 0 ? 'Prueba con otra categoría o crea un servicio aquí.' : 'Crea tu primer servicio.' ?></div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Servicio</th><th>Categoría</th><th>Duración</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($services as $s):
              $imgUrl = urlImagenServicio($s['image'] ?? null); ?>
              <tr style="<?= $s['active'] ? '' : 'opacity:.55' ?>">
                <td>
                  <div class="svc-cell">
                    <?php if ($imgUrl): ?>
                      <img class="svc-thumb" src="<?= e($imgUrl) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <div class="svc-thumb svc-thumb-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                      </div>
                    <?php endif; ?>
                    <div class="svc-cell-main">
                      <div class="client-name">
                        <?= e($s['name']) ?>
                        <?php if ((int)$s['featured'] === 1): ?>
                          <span class="svc-featured">
                            <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2l2.9 6.1 6.6.9-4.8 4.7 1.2 6.7L12 17.2 6.1 20.4l1.2-6.7L2.5 9l6.6-.9z"/></svg>
                            Destacado
                          </span>
                        <?php endif; ?>
                      </div>
                      <?php if ($s['description']): ?>
                        <div class="client-phone" style="max-width:280px;white-space:normal"><?= e($s['description']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><span class="pill"><?= e($s['category_name']) ?></span></td>
                <td><?= (int)$s['duration_min'] ?> min</td>
                <td style="font-weight:600"><?= formatPrice((float)$s['price']) ?></td>
                <td><?= $s['active'] ? '<span class="badge badge-confirmed">Activo</span>' : '<span class="badge badge-cancelled">Inactivo</span>' ?></td>
                <td>
                  <div class="rowmenu">
                    <button type="button" class="rowmenu-trigger" aria-label="Acciones">
                      <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                    </button>
                    <div class="rowmenu-panel">
                      <button class="rowmenu-item" onclick='editService(<?= json_encode($s, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Editar</button>

                      <form method="POST">
                        <input type="hidden" name="action" value="service_feature">
                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="cat" value="<?= $catFiltro ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="rowmenu-item <?= $s['featured'] ? '' : 'primary' ?>">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15 8.5 22 9.3 17 14 18.2 21 12 17.6 5.8 21 7 14 2 9.3 9 8.5 12 2"/></svg><?= $s['featured'] ? 'Quitar destacado' : 'Destacar' ?></button>
                      </form>

                      <form method="POST">
                        <input type="hidden" name="action" value="service_toggle">
                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="cat" value="<?= $catFiltro ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="rowmenu-item">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg><?= $s['active'] ? 'Desactivar' : 'Activar' ?></button>
                      </form>

                      <div class="rowmenu-sep"></div>

                      <form method="POST" onsubmit="return confirm('¿Eliminar servicio?')">
                        <input type="hidden" name="action" value="service_delete">
                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="cat" value="<?= $catFiltro ?>">
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
</div>

<!-- ══════ CATEGORÍAS ══════ -->
<div class="card" id="panel-categories" style="<?= $tab==='categories'?'':'display:none' ?>">
  <div class="card-body--flush">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Categoría</th><th>Descripción</th><th>Servicios</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><div class="client-name"><?= e($c['name']) ?></div></td>
            <td style="color:var(--muted)"><?= e($c['description'] ?? '—') ?></td>
            <td><span class="pill pill-muted"><?= $conteoCat[(int)$c['id']] ?? 0 ?></span></td>
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
    <form method="POST" enctype="multipart/form-data">
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

          <div class="form-field full">
            <label>Imagen</label>
            <div class="svc-upload">
              <div class="svc-upload-preview">
                <img id="svc_preview" alt="" hidden>
                <div class="svc-upload-empty" id="svc_preview_empty">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                </div>
              </div>
              <div class="svc-upload-side">
                <input type="file" name="image" id="svc_image" accept=".jpg,.jpeg,.png,.webp" hidden>
                <button type="button" class="btn btn-ghost svc-upload-btn" onclick="document.getElementById('svc_image').click()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  Elegir imagen
                </button>
                <div class="svc-upload-hint">JPG, PNG o WEBP · máx. 2 MB</div>
                <label class="svc-check" id="svc_remove_wrap" hidden>
                  <input type="checkbox" name="remove_image" id="svc_remove"> Quitar la imagen actual
                </label>
              </div>
            </div>
          </div>

          <div class="form-field full">
            <label class="svc-check">
              <input type="checkbox" name="featured" id="svc_featured" value="1">
              Marcar como destacado (se muestra con badge en el listado)
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="service_save">
        <input type="hidden" name="id" id="svc_id" value="0">
        <input type="hidden" name="tab" value="services">
        <input type="hidden" name="cat" value="<?= $catFiltro ?>">
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
const SVC_IMG_BASE = '/Blue/assets/img/servicios/';
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

document.getElementById('addBtn').addEventListener('click', () => {
  if (TAB === 'categories') newCategory(); else newService();
});
document.getElementById('addBtnLabel').textContent = TAB === 'categories' ? 'Nueva categoría' : 'Nuevo servicio';

// Vista previa de la imagen del servicio
function mostrarPreview(src){
  const img   = document.getElementById('svc_preview');
  const vacio = document.getElementById('svc_preview_empty');
  if (src) { img.src = src; img.hidden = false; vacio.hidden = true; }
  else     { img.removeAttribute('src'); img.hidden = true; vacio.hidden = false; }
}
document.getElementById('svc_image').addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  mostrarPreview(URL.createObjectURL(f));
  document.getElementById('svc_remove').checked = false;
});
document.getElementById('svc_remove').addEventListener('change', e => {
  if (e.target.checked) {
    document.getElementById('svc_image').value = '';
    mostrarPreview(null);
  }
});

function newService(){
  document.getElementById('svcModalTitle').textContent = 'Nuevo servicio';
  document.getElementById('svc_id').value = 0;
  document.getElementById('svc_name').value = '';
  document.getElementById('svc_desc').value = '';
  document.getElementById('svc_dur').value = 60;
  document.getElementById('svc_price').value = 0;
  document.getElementById('svc_featured').checked = false;
  document.getElementById('svc_image').value = '';
  document.getElementById('svc_remove').checked = false;
  document.getElementById('svc_remove_wrap').hidden = true;
  mostrarPreview(null);
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
  document.getElementById('svc_featured').checked = Number(s.featured) === 1;
  document.getElementById('svc_image').value = '';
  document.getElementById('svc_remove').checked = false;
  document.getElementById('svc_remove_wrap').hidden = !s.image;
  mostrarPreview(s.image ? SVC_IMG_BASE + encodeURIComponent(s.image) : null);
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
<script src="/Blue/assets/js/row-menu.js"></script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
