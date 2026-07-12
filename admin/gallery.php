<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-catalogo.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

/** Categoría válida: texto corto, nunca vacío. */
function normalizarCategoriaGaleria(?string $cat): string {
    $cat = trim(preg_replace('/\s+/', ' ', (string)$cat));
    if ($cat === '') return 'General';
    return mb_substr($cat, 0, 60);
}

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/gallery.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload' && !empty($_FILES['images']['name'][0])) {
        // Categoría elegida en el selector, o una nueva escrita por el usuario
        $categoria = ($_POST['category'] ?? '') === '__nueva'
            ? normalizarCategoriaGaleria($_POST['category_new'] ?? '')
            : normalizarCategoriaGaleria($_POST['category'] ?? '');

        $insert = $db->prepare("INSERT INTO gallery (file, category) VALUES (?, ?)");
        $ok = 0; $fail = 0; $ultimoError = '';

        // $_FILES llega "traspuesto" cuando el input es múltiple
        foreach ($_FILES['images']['name'] as $i => $nombre) {
            $subida = guardarImagenGaleria([
                'name'     => $nombre,
                'type'     => $_FILES['images']['type'][$i]     ?? '',
                'tmp_name' => $_FILES['images']['tmp_name'][$i] ?? '',
                'error'    => $_FILES['images']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['images']['size'][$i]     ?? 0,
            ]);

            if (!$subida['ok'] || $subida['file'] === null) {
                $fail++; $ultimoError = $subida['error'] ?? '';
                continue;
            }
            $insert->execute([$subida['file'], $categoria]);
            $ok++;
        }

        if ($ok) {
            setFlash('success', "{$ok} imagen(es) subida(s) en «{$categoria}»." . ($fail ? " {$fail} fallida(s): {$ultimoError}" : ''));
        } else {
            setFlash('error', $ultimoError !== '' ? $ultimoError : 'No se pudo subir ninguna imagen.');
        }
    }

    if ($action === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT file FROM gallery WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetchColumn();
        if ($file) {
            $db->prepare("DELETE FROM gallery WHERE id = ?")->execute([$id]);
            eliminarImagenGaleria($file);
            setFlash('info', 'Imagen eliminada.');
        }
    }

    if ($action === 'set_category') {
        $categoria = ($_POST['category'] ?? '') === '__nueva'
            ? normalizarCategoriaGaleria($_POST['category_new'] ?? '')
            : normalizarCategoriaGaleria($_POST['category'] ?? '');
        $db->prepare("UPDATE gallery SET category = ? WHERE id = ?")
           ->execute([$categoria, (int)($_POST['id'] ?? 0)]);
        setFlash('success', "Imagen movida a «{$categoria}».");
    }

    // Vuelve al filtro en que estaba (patrón PRG)
    $destino = '/Blue/admin/gallery.php';
    $catPost = trim((string)($_POST['cat'] ?? ''));
    if ($catPost !== '') $destino .= '?cat=' . rawurlencode($catPost);
    header('Location: ' . $destino); exit;
}

// ── Datos ─────────────────────────────────────────────────
// Registra las imágenes que ya estaban en disco y limpia filas huérfanas
sincronizarGaleria($db);

$catFiltro = trim((string)($_GET['cat'] ?? ''));

$sql    = "SELECT * FROM gallery";
$params = [];
if ($catFiltro !== '') { $sql .= " WHERE category = ?"; $params[] = $catFiltro; }
$sql   .= " ORDER BY category, id DESC";
$stmt   = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupadas por categoría (en el mismo orden en que se pintan)
$grupos = [];
foreach ($rows as $r) $grupos[$r['category']][] = $r;

// Lista plana para el lightbox: mismo orden de pintado
$lightbox = [];
foreach ($grupos as $cat => $items) {
    foreach ($items as $it) {
        $lightbox[] = ['src' => urlImagenGaleria($it['file']), 'cat' => $cat];
    }
}

// Categorías existentes con su conteo (sin filtrar), para chips y selectores
$conteoCat = [];
$total     = 0;
foreach ($db->query("SELECT category, COUNT(*) AS n FROM gallery GROUP BY category ORDER BY category") as $row) {
    $conteoCat[$row['category']] = (int)$row['n'];
    $total += (int)$row['n'];
}
$categorias = array_keys($conteoCat);
if (!in_array('General', $categorias, true)) $categorias[] = 'General';
sort($categorias);

$pageTitle  = 'Galería';
$activePage = 'gallery';
$extraCss   = ['/Blue/assets/css/m-catalogo.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-catalogo.css')];
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Galería</h2>
    <p><?= $total ?> imagen(es) · <?= count($conteoCat) ?> categoría(s) — se muestran en el sitio público</p>
  </div>
</div>

<!-- Zona de subida -->
<form method="POST" enctype="multipart/form-data" id="uploadForm" class="gal-upload">
  <input type="hidden" name="action" value="upload">
  <input type="hidden" name="cat" value="<?= e($catFiltro) ?>">
  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

  <div class="gal-upload-cat">
    <label for="upCat">Categoría de las imágenes</label>
    <select name="category" id="upCat" class="form-control">
      <?php foreach ($categorias as $cat): ?>
        <option value="<?= e($cat) ?>" <?= $cat === $catFiltro ? 'selected' : '' ?>><?= e($cat) ?></option>
      <?php endforeach; ?>
      <option value="__nueva">Nueva categoría…</option>
    </select>
    <input type="text" name="category_new" id="upCatNew" class="form-control" placeholder="Nombre de la nueva categoría" maxlength="60" hidden>
  </div>

  <input type="file" name="images[]" id="fileInput" accept=".jpg,.jpeg,.png,.webp" multiple hidden>
  <div class="upload-zone" id="uploadZone">
    <svg class="gal-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    <div style="font-weight:600;color:var(--dark);margin-bottom:4px">Haz clic para subir imágenes</div>
    <div style="font-size:12px;color:var(--muted)">JPG, PNG o WEBP · máx. 5 MB c/u · selección múltiple</div>
  </div>
</form>

<?php if ($total === 0): ?>
  <div class="card"><div class="card-body">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
      </div>
      <div class="empty-state-title">Galería vacía</div>
      <div class="empty-state-desc">Sube imágenes de tus tratamientos e instalaciones.</div>
    </div>
  </div></div>
<?php else: ?>

  <!-- Filtro por categoría -->
  <div class="cat-filter">
    <a class="cat-chip <?= $catFiltro === '' ? 'active' : '' ?>" href="gallery.php">
      Todas <span class="cat-chip-n"><?= $total ?></span>
    </a>
    <?php foreach ($conteoCat as $cat => $n): ?>
      <a class="cat-chip <?= $catFiltro === $cat ? 'active' : '' ?>" href="?cat=<?= rawurlencode($cat) ?>">
        <?= e($cat) ?> <span class="cat-chip-n"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($rows)): ?>
    <div class="card"><div class="card-body">
      <div class="empty-state">
        <div class="empty-state-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
        </div>
        <div class="empty-state-title">Sin imágenes en esta categoría</div>
        <div class="empty-state-desc">Prueba con otra categoría o sube imágenes aquí.</div>
      </div>
    </div></div>
  <?php else: ?>
    <?php $idx = 0; ?>
    <?php foreach ($grupos as $cat => $items): ?>
      <div class="gal-group">
        <div class="gal-group-head">
          <h3><?= e($cat) ?></h3>
          <span class="pill pill-muted"><?= count($items) ?></span>
        </div>

        <div class="gallery-grid">
          <?php foreach ($items as $img): ?>
            <div class="gal-card">
              <button type="button" class="gal-thumb" onclick="abrirLightbox(<?= $idx ?>)" title="Ver en grande">
                <img src="<?= e(urlImagenGaleria($img['file'])) ?>" alt="" loading="lazy">
              </button>

              <div class="gal-card-foot">
                <form method="POST" class="gal-cat-form">
                  <input type="hidden" name="action" value="set_category">
                  <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
                  <input type="hidden" name="cat" value="<?= e($catFiltro) ?>">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <select name="category" class="gal-cat-select" onchange="this.form.submit()" title="Cambiar de categoría">
                    <?php foreach ($categorias as $c): ?>
                      <option value="<?= e($c) ?>" <?= $c === $img['category'] ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>

                <form method="POST" onsubmit="return confirm('¿Eliminar esta imagen?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
                  <input type="hidden" name="cat" value="<?= e($catFiltro) ?>">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <button type="submit" class="gal-del" title="Eliminar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                  </button>
                </form>
              </div>
            </div>
            <?php $idx++; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" aria-hidden="true">
  <button type="button" class="lb-close" id="lbClose" title="Cerrar (Esc)">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <button type="button" class="lb-nav lb-prev" id="lbPrev" title="Anterior">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
  </button>
  <figure class="lb-figure">
    <img id="lbImg" alt="">
    <figcaption id="lbCaption"></figcaption>
  </figure>
  <button type="button" class="lb-nav lb-next" id="lbNext" title="Siguiente">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
  </button>
</div>

<script>
// ── Subida: categoría nueva y apertura del selector de archivos ──
const upCat    = document.getElementById('upCat');
const upCatNew = document.getElementById('upCatNew');
upCat.addEventListener('change', () => {
  const esNueva = upCat.value === '__nueva';
  upCatNew.hidden = !esNueva;
  if (esNueva) upCatNew.focus();
});
document.getElementById('uploadZone').addEventListener('click', () => {
  if (upCat.value === '__nueva' && upCatNew.value.trim() === '') {
    upCatNew.focus();
    return;   // pide el nombre antes de abrir el selector de archivos
  }
  document.getElementById('fileInput').click();
});
document.getElementById('fileInput').addEventListener('change', e => {
  if (e.target.files.length) document.getElementById('uploadForm').submit();
});

// ── Lightbox ──
const IMAGENES  = <?= json_encode($lightbox, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const lb        = document.getElementById('lightbox');
const lbImg     = document.getElementById('lbImg');
const lbCaption = document.getElementById('lbCaption');
let lbIndex = 0;

function abrirLightbox(i){
  if (!IMAGENES.length) return;
  lbIndex = i;
  pintarLightbox();
  lb.classList.add('open');
  lb.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}
function cerrarLightbox(){
  lb.classList.remove('open');
  lb.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}
function pintarLightbox(){
  const img = IMAGENES[lbIndex];
  lbImg.src = img.src;
  lbCaption.textContent = img.cat + ' · ' + (lbIndex + 1) + ' de ' + IMAGENES.length;   // textContent: nunca innerHTML
}
function moverLightbox(paso){
  lbIndex = (lbIndex + paso + IMAGENES.length) % IMAGENES.length;
  pintarLightbox();
}

document.getElementById('lbClose').addEventListener('click', cerrarLightbox);
document.getElementById('lbPrev').addEventListener('click', () => moverLightbox(-1));
document.getElementById('lbNext').addEventListener('click', () => moverLightbox(1));
lb.addEventListener('click', e => { if (e.target === lb) cerrarLightbox(); });
document.addEventListener('keydown', e => {
  if (!lb.classList.contains('open')) return;
  if (e.key === 'Escape')     cerrarLightbox();
  if (e.key === 'ArrowLeft')  moverLightbox(-1);
  if (e.key === 'ArrowRight') moverLightbox(1);
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
