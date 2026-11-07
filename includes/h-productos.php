<?php
/**
 * h-productos.php — Catálogo de productos de la tienda.
 * Responsable: Felipe (módulo Tienda).
 *
 * Lo usan el panel (admin/products.php), la tienda pública (tienda.php) y la
 * página de inicio. Los pedidos y sus pagos viven en h-pedidos.php.
 *
 * `products.stock`:
 *   NULL → sin control de inventario (siempre disponible)
 *   N    → unidades disponibles; al llegar a 0 el producto aparece «Agotado»
 */

// Subida y borrado de imágenes (mismas reglas que servicios y galería).
require_once __DIR__ . '/h-catalogo.php';

const PRODUCTO_NOMBRE_MAX   = 150;
const PRODUCTO_STOCK_MAX    = 100000;
const PRODUCTO_IMAGEN_BYTES = 2 * 1024 * 1024;

// ── Imágenes ────────────────────────────────────────────────

/** Carpeta física de las fotos de productos. */
function carpetaImagenesProducto(): string {
    $dir = __DIR__ . '/../assets/img/productos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** URL pública de la foto de un producto (o null si no tiene). */
function urlImagenProducto(?string $file): ?string {
    $file = trim((string)$file);
    if ($file === '') return null;
    return '/Blue/assets/img/productos/' . rawurlencode(basename($file));
}

/** Valida y guarda la foto de un producto (máx. 2 MB). */
function guardarImagenProducto(array $file): array {
    return guardarImagenSubida($file, carpetaImagenesProducto(), 'prd', PRODUCTO_IMAGEN_BYTES);
}

/** Borra del disco la foto de un producto (si existe). */
function eliminarImagenProducto(?string $file): void {
    eliminarImagenDe(carpetaImagenesProducto(), $file);
}

// ── Disponibilidad ──────────────────────────────────────────

/** ¿Se puede comprar? Sin control de inventario siempre; con él, mientras queden unidades. */
function productoDisponible(array $producto): bool {
    return $producto['stock'] === null || (int)$producto['stock'] > 0;
}

/** Con estas unidades o menos, el panel avisa «poco stock». */
const PRODUCTO_POCO_STOCK = 3;

/** ¿Quedan pocas unidades (pero no está agotado)? Solo con control de inventario. */
function productoConPocoStock(array $producto): bool {
    return $producto['stock'] !== null && (int)$producto['stock'] > 0 && (int)$producto['stock'] <= PRODUCTO_POCO_STOCK;
}

/** Texto de stock para el panel: «Sin control», «12 uds.» o «Agotado». */
function textoStockProducto(array $producto): string {
    if ($producto['stock'] === null) return 'Sin control';
    return (int)$producto['stock'] > 0 ? (int)$producto['stock'] . ' uds.' : 'Agotado';
}

// ── Consultas ───────────────────────────────────────────────

/**
 * Productos activos para mostrar al cliente (los destacados primero).
 * Los agotados se incluyen: aparecen con su etiqueta, pero no se pueden pedir.
 */
function productosParaTienda(PDO $db, bool $soloDestacados = false, int $limite = 0): array {
    $sql = 'SELECT * FROM products WHERE active = 1'
         . ($soloDestacados ? ' AND featured = 1' : '')
         . ' ORDER BY featured DESC, name'
         . ($limite > 0 ? ' LIMIT ' . $limite : '');

    return array_map(function (array $p): array {
        $p['id']       = (int)$p['id'];
        $p['price']    = (float)$p['price'];
        $p['stock']    = $p['stock'] === null ? null : (int)$p['stock'];
        $p['agotado']  = !productoDisponible($p);
        return $p;
    }, $db->query($sql)->fetchAll());
}

/** Todos los productos (activos e inactivos) para el panel. */
function productosParaPanel(PDO $db): array {
    return $db->query('SELECT * FROM products ORDER BY active DESC, featured DESC, name')->fetchAll();
}

// ── Guardar ─────────────────────────────────────────────────

/**
 * Crea o actualiza un producto.
 *
 * @param array $in      id · name · description · price · stock ('' = sin control) · featured · remove_image
 * @param array $archivo entrada de $_FILES['image']
 * @return array ['ok' => bool, 'msg' => string]
 */
function guardarProducto(PDO $db, array $in, array $archivo): array {
    $id        = (int)($in['id'] ?? 0);
    $nombre    = trim((string)($in['name'] ?? ''));
    $desc      = trim((string)($in['description'] ?? ''));
    $precio    = (float)($in['price'] ?? 0);
    $stockTxt  = trim((string)($in['stock'] ?? ''));
    $destacado = !empty($in['featured']) ? 1 : 0;

    if ($nombre === '')                                  return ['ok' => false, 'msg' => 'El nombre del producto es obligatorio.'];
    if (mb_strlen($nombre) > PRODUCTO_NOMBRE_MAX)        return ['ok' => false, 'msg' => 'El nombre es muy largo (máximo ' . PRODUCTO_NOMBRE_MAX . ' caracteres).'];
    if ($precio <= 0)                                    return ['ok' => false, 'msg' => 'El precio debe ser mayor a cero.'];

    $stock = null;
    if ($stockTxt !== '') {
        if (!ctype_digit($stockTxt) || (int)$stockTxt > PRODUCTO_STOCK_MAX) {
            return ['ok' => false, 'msg' => 'El stock debe ser un número entero (déjalo vacío para no controlar inventario).'];
        }
        $stock = (int)$stockTxt;
    }

    // Foto que ya tenía el producto (solo al editar).
    $imagenActual = null;
    if ($id > 0) {
        $stmt = $db->prepare('SELECT image FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $imagenActual = $stmt->fetchColumn() ?: null;
    }

    $subida = guardarImagenProducto($archivo);
    if (!$subida['ok']) return ['ok' => false, 'msg' => $subida['error']];

    $imagen = $imagenActual;
    if ($subida['file'] !== null) {                     // subió una nueva → reemplaza
        $imagen = $subida['file'];
        eliminarImagenProducto($imagenActual);
    } elseif (!empty($in['remove_image'])) {            // pidió quitar la actual
        eliminarImagenProducto($imagenActual);
        $imagen = null;
    }

    if ($id > 0) {
        $db->prepare('UPDATE products SET name = ?, description = ?, price = ?, image = ?, stock = ?, featured = ? WHERE id = ?')
           ->execute([$nombre, $desc ?: null, $precio, $imagen, $stock, $destacado, $id]);
        return ['ok' => true, 'msg' => 'Producto actualizado.'];
    }

    $db->prepare('INSERT INTO products (name, description, price, image, stock, featured) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$nombre, $desc ?: null, $precio, $imagen, $stock, $destacado]);
    return ['ok' => true, 'msg' => 'Producto creado.'];
}

/** Activa o desactiva un producto (desactivado no aparece en la tienda). */
function alternarProductoActivo(PDO $db, int $id): void {
    $db->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([$id]);
}

/** Marca o quita «destacado» (los destacados salen en la página de inicio). */
function alternarProductoDestacado(PDO $db, int $id): void {
    $db->prepare('UPDATE products SET featured = 1 - featured WHERE id = ?')->execute([$id]);
}

/**
 * Elimina un producto y su foto. Los pedidos que lo compraron conservan nombre y
 * precio (order_items guarda una copia), así que borrarlo no los afecta.
 */
function eliminarProducto(PDO $db, int $id): void {
    $stmt = $db->prepare('SELECT image FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $imagen = $stmt->fetchColumn() ?: null;

    $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    eliminarImagenProducto($imagen);   // solo si el DELETE no lanzó excepción
}
