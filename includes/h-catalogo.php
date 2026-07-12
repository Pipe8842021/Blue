<?php
/**
 * Helper del módulo Catálogo (Clientes · Servicios · Galería).
 * Manejo de las imágenes de servicios y de la galería.
 */

/* ── Base común ──────────────────────────────────────────── */

/** Extensiones permitidas y su tipo MIME real esperado. */
function extensionesImagenPermitidas(): array {
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
}

/**
 * Valida y guarda una imagen subida.
 * Renombra el archivo (nunca se confía en el nombre original) y verifica
 * extensión + tipo MIME real + tamaño.
 *
 * @param array  $file      Entrada de $_FILES (ej. $_FILES['image']).
 * @param string $carpeta   Carpeta física destino.
 * @param string $prefijo   Prefijo del nombre generado (ej. 'svc' o 'img').
 * @param int    $tamanoMax Tamaño máximo en bytes.
 * @return array{ok:bool, file:?string, error:?string}
 */
function guardarImagenSubida(array $file, string $carpeta, string $prefijo, int $tamanoMax): array {
    $extensiones = extensionesImagenPermitidas();

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'file' => null, 'error' => null]; // no subieron nada: no es un error
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'file' => null, 'error' => 'No se pudo subir la imagen.'];
    }
    if (($file['size'] ?? 0) > $tamanoMax) {
        $mb = round($tamanoMax / 1048576);
        return ['ok' => false, 'file' => null, 'error' => "La imagen supera el máximo de {$mb} MB."];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!isset($extensiones[$ext])) {
        return ['ok' => false, 'file' => null, 'error' => 'Formato no permitido. Usa JPG, PNG o WEBP.'];
    }

    // Tipo MIME real del archivo, no el que declara el navegador.
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
    if (!in_array($mime, $extensiones, true)) {
        return ['ok' => false, 'file' => null, 'error' => 'El archivo no es una imagen válida.'];
    }

    $nombre = $prefijo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $carpeta . '/' . $nombre)) {
        return ['ok' => false, 'file' => null, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }
    return ['ok' => true, 'file' => $nombre, 'error' => null];
}

/** Borra un archivo de imagen de una carpeta (ignora rutas maliciosas). */
function eliminarImagenDe(string $carpeta, ?string $file): void {
    $file = basename(trim((string)$file));
    if ($file === '' || $file === '.' || $file === '..') return;
    $ruta = $carpeta . '/' . $file;
    if (is_file($ruta)) @unlink($ruta);
}

/* ── Servicios ───────────────────────────────────────────── */

/** Carpeta física donde se guardan las imágenes de servicios. */
function carpetaImagenesServicio(): string {
    $dir = __DIR__ . '/../assets/img/servicios';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** URL pública de una imagen de servicio (o null si no tiene). */
function urlImagenServicio(?string $file): ?string {
    $file = trim((string)$file);
    if ($file === '') return null;
    return '/Blue/assets/img/servicios/' . rawurlencode(basename($file));
}

/** Valida y guarda la imagen de un servicio (máx. 2 MB). */
function guardarImagenServicio(array $file): array {
    return guardarImagenSubida($file, carpetaImagenesServicio(), 'svc', 2 * 1024 * 1024);
}

/** Borra del disco la imagen de un servicio (si existe). */
function eliminarImagenServicio(?string $file): void {
    eliminarImagenDe(carpetaImagenesServicio(), $file);
}

/* ── Galería ─────────────────────────────────────────────── */

/** Carpeta física de la galería. */
function carpetaImagenesGaleria(): string {
    $dir = __DIR__ . '/../assets/img/gallery';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** URL pública de una imagen de la galería. */
function urlImagenGaleria(string $file): string {
    return '/Blue/assets/img/gallery/' . rawurlencode(basename($file));
}

/** Valida y guarda una imagen de la galería (máx. 5 MB). */
function guardarImagenGaleria(array $file): array {
    return guardarImagenSubida($file, carpetaImagenesGaleria(), 'img', 5 * 1024 * 1024);
}

/** Borra del disco una imagen de la galería (si existe). */
function eliminarImagenGaleria(?string $file): void {
    eliminarImagenDe(carpetaImagenesGaleria(), $file);
}

/**
 * Sincroniza la tabla `gallery` con los archivos del disco:
 *  - registra en "General" las imágenes que ya estaban antes de la tabla,
 *  - borra las filas cuyo archivo ya no existe.
 * Es idempotente: se puede llamar en cada carga de la página.
 */
function sincronizarGaleria(PDO $db): void {
    $carpeta  = carpetaImagenesGaleria();
    $enDisco  = [];
    foreach (glob($carpeta . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $ruta) {
        $enDisco[] = basename($ruta);
    }
    $registradas = $db->query("SELECT file FROM gallery")->fetchAll(PDO::FETCH_COLUMN);

    $nuevas = array_diff($enDisco, $registradas);
    if ($nuevas) {
        $insert = $db->prepare("INSERT IGNORE INTO gallery (file, category) VALUES (?, 'General')");
        foreach ($nuevas as $file) $insert->execute([$file]);
    }

    $huerfanas = array_diff($registradas, $enDisco);
    if ($huerfanas) {
        $borrar = $db->prepare("DELETE FROM gallery WHERE file = ?");
        foreach ($huerfanas as $file) $borrar->execute([$file]);
    }
}
