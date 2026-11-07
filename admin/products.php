<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-productos.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/products.php'); exit;
    }

    try {
        switch ($_POST['action'] ?? '') {
            case 'product_save':
                $r = guardarProducto($db, $_POST, $_FILES['image'] ?? []);
                setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
                break;

            case 'product_toggle':
                alternarProductoActivo($db, (int)($_POST['id'] ?? 0));
                setFlash('info', 'Estado del producto actualizado.');
                break;

            case 'product_feature':
                alternarProductoDestacado($db, (int)($_POST['id'] ?? 0));
                setFlash('info', 'Producto destacado actualizado.');
                break;

            case 'product_delete':
                eliminarProducto($db, (int)($_POST['id'] ?? 0));
                setFlash('info', 'Producto eliminado. Los pedidos que ya lo compraron conservan su detalle.');
                break;
        }
    } catch (Throwable $e) {
        error_log('[Blue/Tienda] Error en el panel de productos: ' . $e->getMessage());
        setFlash('error', 'Ocurrió un error al guardar.');
    }

    header('Location: /Blue/admin/products.php'); exit;   // patrón PRG
}

// ── Datos ─────────────────────────────────────────────────
$productos = productosParaPanel($db);
$agotados  = count(array_filter($productos, fn($p) => !productoDisponible($p)));
$pocoStock = count(array_filter($productos, fn($p) => productoConPocoStock($p)));

$pageTitle  = 'Productos';
$activePage = 'products';
$extraCss   = [
    '/Blue/assets/css/m-catalogo.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-catalogo.css'),   // tabla e imagen (como Servicios)
    '/Blue/assets/css/m-agenda.css?v='   . @filemtime(__DIR__ . '/../assets/css/m-agenda.css'),
];
$topbarActions = '<a href="/Blue/admin/orders.php" class="topbar-btn">Ver pedidos</a>';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Productos</h2>
    <p>
      <?= count($productos) ?> producto<?= count($productos) === 1 ? '' : 's' ?>
      <?php if ($agotados): ?> · <strong style="color:var(--cancelled)"><?= $agotados ?> agotado<?= $agotados === 1 ? '' : 's' ?></strong><?php endif; ?>
      <?php if ($pocoStock): ?> · <?= $pocoStock ?> con poco stock<?php endif; ?>
    </p>
  </div>
  <button class="btn btn-primary" onclick="newProduct()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Nuevo producto
  </button>
</div>

<div class="card">
  <div class="card-body--flush">
    <?php if (empty($productos)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        </div>
        <div class="empty-state-title">Sin productos</div>
        <div class="empty-state-desc">Crea tu primer producto para que los clientes puedan pedirlo desde el sitio.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Producto</th><th>Precio</th><th>Stock</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($productos as $p):
            $imgUrl     = urlImagenProducto($p['image'] ?? null);
            $disponible = productoDisponible($p); ?>
            <tr style="<?= $p['active'] ? '' : 'opacity:.55' ?>">
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
                      <?= e($p['name']) ?>
                      <?php if ((int)$p['featured'] === 1): ?>
                        <span class="svc-featured">
                          <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2l2.9 6.1 6.6.9-4.8 4.7 1.2 6.7L12 17.2 6.1 20.4l1.2-6.7L2.5 9l6.6-.9z"/></svg>
                          En inicio
                        </span>
                      <?php endif; ?>
                    </div>
                    <?php if ($p['description']): ?>
                      <div class="client-phone" style="max-width:280px;white-space:normal"><?= e($p['description']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td style="font-weight:600"><?= formatPrice((float)$p['price']) ?></td>
              <td>
                <?php if (!$disponible): ?>
                  <span class="badge badge-cancelled">Agotado</span>
                <?php elseif (productoConPocoStock($p)): ?>
                  <span class="badge badge-pending"><?= (int)$p['stock'] ?> uds. · poco</span>
                <?php else: ?>
                  <span class="pill"><?= e(textoStockProducto($p)) ?></span>
                <?php endif; ?>
              </td>
              <td><?= $p['active'] ? '<span class="badge badge-confirmed">Activo</span>' : '<span class="badge badge-cancelled">Inactivo</span>' ?></td>
              <td>
                <div class="rowmenu">
                  <button type="button" class="rowmenu-trigger" aria-label="Acciones">
                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                  </button>
                  <div class="rowmenu-panel">
                    <button class="rowmenu-item" onclick='editProduct(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Editar</button>

                    <form method="POST">
                      <input type="hidden" name="action" value="product_feature">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                      <button type="submit" class="rowmenu-item <?= $p['featured'] ? '' : 'primary' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15 8.5 22 9.3 17 14 18.2 21 12 17.6 5.8 21 7 14 2 9.3 9 8.5 12 2"/></svg><?= $p['featured'] ? 'Quitar de inicio' : 'Mostrar en inicio' ?></button>
                    </form>

                    <form method="POST">
                      <input type="hidden" name="action" value="product_toggle">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                      <button type="submit" class="rowmenu-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg><?= $p['active'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>

                    <div class="rowmenu-sep"></div>

                    <form method="POST" onsubmit="return confirm('¿Eliminar producto?')">
                      <input type="hidden" name="action" value="product_delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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

<!-- ══════ Modal: crear / editar producto ══════ -->
<div class="modal-overlay" id="productModal">
  <div class="modal" style="max-width:520px">
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-header">
        <span class="modal-title" id="prdModalTitle">Nuevo producto</span>
        <button type="button" class="modal-close" onclick="closeModal('productModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-field full">
            <label>Nombre del producto</label>
            <input type="text" name="name" id="prd_name" class="form-control" maxlength="<?= PRODUCTO_NOMBRE_MAX ?>" required>
          </div>
          <div class="form-field">
            <label>Precio (COP)</label>
            <input type="number" name="price" id="prd_price" class="form-control" min="1" step="1" required>
          </div>
          <div class="form-field">
            <label>Stock (unidades)</label>
            <input type="number" name="stock" id="prd_stock" class="form-control" min="0" max="<?= PRODUCTO_STOCK_MAX ?>" step="1" placeholder="Sin control">
            <div class="form-hint">Déjalo vacío si no llevas inventario de este producto.</div>
          </div>
          <div class="form-field full">
            <label>Descripción</label>
            <textarea name="description" id="prd_desc" class="form-control" placeholder="Qué es, para qué sirve, tamaño…"></textarea>
          </div>

          <div class="form-field full">
            <label>Foto</label>
            <div class="svc-upload">
              <div class="svc-upload-preview">
                <img id="prd_preview" alt="" hidden>
                <div class="svc-upload-empty" id="prd_preview_empty">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                </div>
              </div>
              <div class="svc-upload-side">
                <input type="file" name="image" id="prd_image" accept=".jpg,.jpeg,.png,.webp" hidden>
                <button type="button" class="btn btn-ghost svc-upload-btn" onclick="document.getElementById('prd_image').click()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  Elegir foto
                </button>
                <div class="svc-upload-hint">JPG, PNG o WEBP · máx. 2 MB</div>
                <label class="svc-check" id="prd_remove_wrap" hidden>
                  <input type="checkbox" name="remove_image" id="prd_remove" value="1"> Quitar la foto actual
                </label>
              </div>
            </div>
          </div>

          <div class="form-field full">
            <label class="svc-check">
              <input type="checkbox" name="featured" id="prd_featured" value="1">
              Mostrar en la página de inicio
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="product_save">
        <input type="hidden" name="id" id="prd_id" value="0">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('productModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
const PRD_IMG_BASE = '/Blue/assets/img/productos/';
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

// Vista previa de la foto
function mostrarPreview(src){
  const img   = document.getElementById('prd_preview');
  const vacio = document.getElementById('prd_preview_empty');
  if (src) { img.src = src; img.hidden = false; vacio.hidden = true; }
  else     { img.removeAttribute('src'); img.hidden = true; vacio.hidden = false; }
}
document.getElementById('prd_image').addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  mostrarPreview(URL.createObjectURL(f));
  document.getElementById('prd_remove').checked = false;
});
document.getElementById('prd_remove').addEventListener('change', e => {
  if (e.target.checked) {
    document.getElementById('prd_image').value = '';
    mostrarPreview(null);
  }
});

function newProduct(){
  document.getElementById('prdModalTitle').textContent = 'Nuevo producto';
  document.getElementById('prd_id').value = 0;
  ['prd_name', 'prd_price', 'prd_stock', 'prd_desc', 'prd_image'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('prd_featured').checked = false;
  document.getElementById('prd_remove').checked = false;
  document.getElementById('prd_remove_wrap').hidden = true;
  mostrarPreview(null);
  openModal('productModal');
}
function editProduct(p){
  document.getElementById('prdModalTitle').textContent = 'Editar producto';
  document.getElementById('prd_id').value = p.id;
  document.getElementById('prd_name').value = p.name;
  document.getElementById('prd_price').value = parseFloat(p.price);
  document.getElementById('prd_stock').value = p.stock === null ? '' : p.stock;
  document.getElementById('prd_desc').value = p.description || '';
  document.getElementById('prd_featured').checked = Number(p.featured) === 1;
  document.getElementById('prd_image').value = '';
  document.getElementById('prd_remove').checked = false;
  document.getElementById('prd_remove_wrap').hidden = !p.image;
  mostrarPreview(p.image ? PRD_IMG_BASE + encodeURIComponent(p.image) : null);
  openModal('productModal');
}
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>
<script src="/Blue/assets/js/row-menu.js"></script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
