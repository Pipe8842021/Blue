<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin', '/Blue/login.php');

$galleryDir = __DIR__ . '/../assets/img/gallery';
$galleryUrl = '/Blue/assets/img/gallery';
if (!is_dir($galleryDir)) @mkdir($galleryDir, 0755, true);

$allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$maxSize = 5 * 1024 * 1024; // 5 MB

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/gallery.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload' && !empty($_FILES['images']['name'][0])) {
        $ok = 0; $fail = 0;
        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) { $fail++; continue; }
            if ($_FILES['images']['size'][$i] > $maxSize) { $fail++; continue; }

            $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
            $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : ($allowed[$ext] ?? '');
            if (!isset($allowed[$ext]) || !in_array($mime, $allowed, true)) { $fail++; continue; }

            $newName = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($tmp, $galleryDir . '/' . $newName)) $ok++; else $fail++;
        }
        setFlash($ok ? 'success' : 'error',
            $ok ? "$ok imagen(es) subida(s)." . ($fail ? " $fail fallida(s)." : '') : 'No se pudo subir ninguna imagen.');
    }

    if ($action === 'delete') {
        $file = basename($_POST['file'] ?? '');
        $path = $galleryDir . '/' . $file;
        if ($file && is_file($path)) { @unlink($path); setFlash('info', 'Imagen eliminada.'); }
    }

    header('Location: /Blue/admin/gallery.php'); exit;
}

// ── Listar imágenes ───────────────────────────────────────
$images = [];
foreach (glob($galleryDir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $f) {
    $images[] = basename($f);
}
rsort($images);

$pageTitle  = 'Galería';
$activePage = 'gallery';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Galería</h2>
    <p><?= count($images) ?> imagen(es) — se muestran en el sitio público</p>
  </div>
</div>

<!-- Zona de subida -->
<form method="POST" enctype="multipart/form-data" id="uploadForm" style="margin-bottom:24px">
  <input type="hidden" name="action" value="upload">
  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
  <input type="file" name="images[]" id="fileInput" accept="image/*" multiple hidden onchange="document.getElementById('uploadForm').submit()">
  <div class="upload-zone" onclick="document.getElementById('fileInput').click()">
    <div style="font-size:2rem;margin-bottom:10px">📤</div>
    <div style="font-weight:600;color:var(--dark);margin-bottom:4px">Haz clic para subir imágenes</div>
    <div style="font-size:12px;color:var(--muted)">JPG, PNG, WEBP o GIF · máx. 5 MB c/u · selección múltiple</div>
  </div>
</form>

<!-- Grid -->
<?php if (empty($images)): ?>
  <div class="card"><div class="card-body">
    <div class="empty-state"><div class="empty-state-icon">🖼️</div><div class="empty-state-title">Galería vacía</div><div class="empty-state-desc">Sube imágenes de tus tratamientos e instalaciones.</div></div>
  </div></div>
<?php else: ?>
  <div class="gallery-grid">
    <?php foreach ($images as $img): ?>
      <div class="gallery-item">
        <img src="<?= $galleryUrl . '/' . e($img) ?>" alt="" loading="lazy">
        <form method="POST" onsubmit="return confirm('¿Eliminar esta imagen?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="file" value="<?= e($img) ?>">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <button type="submit" class="del" title="Eliminar">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
          </button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
