<?php
// ============================================================
// Blue Therapy — Pruebas de la tienda de productos
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con Apache + MySQL encendidos:
//
//     php tests/tienda.php
//
//   1. Validación de lo que manda el cliente
//   2. Catálogo y stock
//   3. Crear el pedido (totales desde la BD, stock, errores)
//   4. Anular y devolver el stock (sin devolverlo dos veces)
//   5. Pago manual y su ingreso en Finanzas
//   6. Pago de Wompi simulado (aprobado, rechazado, tardío, repetido)
//   7. Estados y aviso al cliente cuando queda listo
//   8. Enlace privado y listados
//
// Crea productos, pedidos y clientes de prueba y los borra al terminar.
// Solo corre por consola: la carpeta tests/ está bloqueada en .htaccess.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-pedidos.php';

$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$creado    = ['productos' => [], 'telefonos' => []];

function seccion(string $titulo): void {
    echo "\n\033[1m{$titulo}\033[0m\n" . str_repeat('─', 60) . "\n";
}

function comprobar(string $descripcion, bool $cumple, string $detalle = ''): bool {
    global $resultado;
    $resultado[$cumple ? 'ok' : 'falla']++;
    $marca = $cumple ? "\033[32m[OK]\033[0m   " : "\033[31m[FALLA]\033[0m";
    echo "  {$marca} {$descripcion}" . ($detalle !== '' ? "  \033[90m({$detalle})\033[0m" : '') . "\n";
    return $cumple;
}

function telefonoDePrueba(): string {
    global $creado;
    return $creado['telefonos'][] = '+57 394 ' . random_int(1000000, 9999999);
}

/** Producto de prueba directo en la BD. $stock null = sin control de inventario. */
function productoDePrueba(PDO $db, string $nombre, float $precio, ?int $stock, int $activo = 1): int {
    global $creado;
    $db->prepare('INSERT INTO products (name, price, stock, active) VALUES (?, ?, ?, ?)')
       ->execute(['Zz Prueba ' . $nombre, $precio, $stock, $activo]);
    return $creado['productos'][] = (int)$db->lastInsertId();
}

function stockDe(PDO $db, int $id): ?int {
    $s = $db->prepare('SELECT stock FROM products WHERE id = ?');
    $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === null ? null : (int)$v;
}

/** Cuerpo de pedido como lo manda tienda.js; $extra pisa cualquier campo. */
function entradaDePedido(array $items, array $extra = []): array {
    return array_merge([
        'items' => array_map(fn($id, $q) => ['id' => $id, 'qty' => $q], array_keys($items), array_values($items)),
        'name' => 'Prueba Tienda', 'phone' => telefonoDePrueba(), 'email' => 'prueba.tienda@blue.test',
        'note' => '', 'whatsapp' => true, 'email_reminder' => true,
    ], $extra);
}

/** Crea un pedido válido y devuelve su id. */
function pedidoDePrueba(PDO $db, array $items, array $extra = []): int {
    $v = validarDatosPedido(entradaDePedido($items, $extra));
    if ($v['errores']) throw new RuntimeException(implode(' ', $v['errores']));
    return crearPedido($db, $v['datos'])['order_id'];
}

function estadoDe(PDO $db, int $id): string {
    $s = $db->prepare('SELECT status FROM orders WHERE id = ?');
    $s->execute([$id]);
    return (string)$s->fetchColumn();
}

/** Transacción de Wompi simulada. */
function transaccionDePrueba(string $estado, float $monto): array {
    return ['id' => 'TEST-' . bin2hex(random_bytes(5)), 'status' => $estado,
            'payment_method_type' => 'CARD', 'amount_in_cents' => montoEnCentavos($monto)];
}

function archivosEn(string $carpeta): int {
    return is_dir($carpeta) ? count(glob($carpeta . '/*')) : 0;
}

function limpiar(): void {
    global $creado;
    try {
        $db = getDB();
        foreach (array_unique($creado['telefonos']) as $tel) {
            $ids = $db->prepare('SELECT o.id FROM orders o JOIN clients c ON c.id = o.client_id WHERE c.phone = ?');
            $ids->execute([$tel]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $db->prepare('DELETE FROM finances WHERE description LIKE ?')->execute(["%pedido #{$id} %"]);
                $db->prepare('DELETE FROM notifications WHERE order_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
            }
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([$tel]);
        }
        foreach ($creado['productos'] as $id) {
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar todos los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

echo "\033[1mBlue Therapy — pruebas de la tienda de productos\033[0m\n";

try {
    $db = getDB();
    $db->query('SELECT 1 FROM orders LIMIT 1');
} catch (Throwable $e) {
    echo "\033[31mFaltan las tablas de la tienda: corre database/migrations/03_agenda.sql ({$e->getMessage()})\033[0m\n";
    exit(1);
}

// ── 1. Validación ─────────────────────────────────────────
seccion('1. Validación de lo que manda el cliente');

$v = validarDatosPedido(entradaDePedido([]));
comprobar('Un pedido vacío se rechaza', count($v['errores']) > 0, implode(' ', $v['errores']));

$v = validarDatosPedido(entradaDePedido([5 => 2], ['name' => '', 'phone' => '']));
comprobar('Pide nombre y teléfono', count($v['errores']) === 2, implode(' ', $v['errores']));

$v = validarDatosPedido(entradaDePedido([5 => 2], ['email' => 'no-es-correo']));
comprobar('Rechaza un correo inválido', count($v['errores']) === 1);

$v = validarDatosPedido(['items' => [['id' => 5, 'qty' => 1], ['id' => 5, 'qty' => 2], ['id' => 0, 'qty' => 4], ['id' => 6, 'qty' => -1]],
                         'name' => 'A', 'phone' => '1']);
comprobar('Suma el mismo producto repetido e ignora líneas inválidas', $v['datos']['items'] === [5 => 3], json_encode($v['datos']['items']));

$v = validarDatosPedido(entradaDePedido([5 => PEDIDO_MAX_UNIDADES_LINEA + 1]));
comprobar('Limita las unidades por producto', count($v['errores']) > 0);

$v = validarDatosPedido(entradaDePedido([5 => 1], ['email' => '', 'email_reminder' => true]));
comprobar('Sin correo no hay avisos por correo', $v['datos']['email_reminder'] === false);

$v = validarDatosPedido(entradaDePedido([5 => 1], ['delivery_type' => 'delivery']));
comprobar('El envío a domicilio está apagado: siempre se recoge', $v['datos']['delivery_type'] === 'pickup');

$v = validarDatosPedido(entradaDePedido([5 => 1], ['note' => str_repeat('x', PEDIDO_NOTA_MAX + 1)]));
comprobar('Limita el largo de la nota', count($v['errores']) > 0);

// ── 2. Catálogo y stock ───────────────────────────────────
seccion('2. Catálogo y stock');

$r = guardarProducto($db, ['name' => '', 'price' => 1000], []);
comprobar('Rechaza un producto sin nombre', !$r['ok'], $r['msg']);
$r = guardarProducto($db, ['name' => 'Zz Prueba sin precio', 'price' => 0], []);
comprobar('Rechaza un precio de cero', !$r['ok'], $r['msg']);
$r = guardarProducto($db, ['name' => 'Zz Prueba stock raro', 'price' => 1000, 'stock' => '-3'], []);
comprobar('Rechaza un stock negativo o con letras', !$r['ok'], $r['msg']);

$r = guardarProducto($db, ['name' => 'Zz Prueba crema', 'price' => '45000', 'stock' => '', 'featured' => 1], []);
$crema = (int)$db->query("SELECT id FROM products WHERE name = 'Zz Prueba crema'")->fetchColumn();
$creado['productos'][] = $crema;
comprobar('Crea un producto sin control de inventario', $r['ok'] && $crema > 0 && stockDe($db, $crema) === null, $r['msg']);

$agotado  = productoDePrueba($db, 'agotado', 20000, 0);
$pocos    = productoDePrueba($db, 'pocos', 10000, 3);
$inactivo = productoDePrueba($db, 'inactivo', 5000, 10, 0);
$libre    = productoDePrueba($db, 'libre', 8000, null);

$tienda = array_column(productosParaTienda($db), null, 'id');
comprobar('La tienda muestra los productos activos y marca los agotados',
    isset($tienda[$agotado]) && $tienda[$agotado]['agotado'] === true && $tienda[$pocos]['agotado'] === false);
comprobar('La tienda no muestra los inactivos', !isset($tienda[$inactivo]));
comprobar('Texto de stock', textoStockProducto(['stock' => null]) === 'Sin control'
    && textoStockProducto(['stock' => 0]) === 'Agotado' && textoStockProducto(['stock' => 7]) === '7 uds.');

alternarProductoDestacado($db, $pocos);
comprobar('Se puede destacar un producto', in_array($pocos, array_column(productosParaTienda($db, true), 'id'), true));
alternarProductoDestacado($db, $pocos);
comprobar('Y quitarle el destacado', !in_array($pocos, array_column(productosParaTienda($db, true), 'id'), true));

// ── 3. Crear el pedido ────────────────────────────────────
seccion('3. Crear el pedido');

$pedidoA = pedidoDePrueba($db, [$pocos => 2, $libre => 1]);
$items = itemsDePedido($db, $pedidoA);
$p = pedidoPorId($db, $pedidoA);
comprobar('El total sale de los precios de la BD', (float)$p['total'] === 2 * 10000.0 + 8000.0, (string)$p['total']);
comprobar('Queda «por pagar» y para recoger', $p['status'] === 'pending_payment' && $p['delivery_type'] === 'pickup');
comprobar('Guarda nombre y precio de cada línea', count($items) === 2 && $items[0]['unit_price'] !== null);
comprobar('Descuenta el stock de lo que tiene inventario', stockDe($db, $pocos) === 1, (string)stockDe($db, $pocos));
comprobar('No toca el stock de lo que no lo controla', stockDe($db, $libre) === null);
comprobar('Tiene un enlace privado de 32 caracteres', preg_match('/^[a-f0-9]{32}$/', $p['token']) === 1);

try {
    pedidoDePrueba($db, [$pocos => 2]);
    comprobar('No deja pedir más de lo que hay', false);
} catch (RuntimeException $e) {
    comprobar('No deja pedir más de lo que hay', true, $e->getMessage());
}
comprobar('Y el intento fallido no gastó stock', stockDe($db, $pocos) === 1);

try {
    pedidoDePrueba($db, [$agotado => 1]);
    comprobar('No deja pedir un producto agotado', false);
} catch (RuntimeException $e) {
    comprobar('No deja pedir un producto agotado', true, $e->getMessage());
}
try {
    pedidoDePrueba($db, [$inactivo => 1]);
    comprobar('No deja pedir un producto inactivo', false);
} catch (RuntimeException $e) {
    comprobar('No deja pedir un producto inactivo', true, $e->getMessage());
}

$lineasAntes = (int)$db->query("SELECT COUNT(*) FROM order_items WHERE name = 'Zz Prueba libre'")->fetchColumn();
try {
    pedidoDePrueba($db, [$libre => 1, $agotado => 1]);
} catch (RuntimeException $e) {
    // esperado: el agotado hace fallar todo el pedido
}
comprobar('Un pedido que falla no deja líneas a medias',
    (int)$db->query("SELECT COUNT(*) FROM order_items WHERE name = 'Zz Prueba libre'")->fetchColumn() === $lineasAntes);

$clienteMismo = telefonoDePrueba();
$p1 = pedidoDePrueba($db, [$libre => 1], ['phone' => $clienteMismo, 'name' => 'Nombre Uno']);
$p2 = pedidoDePrueba($db, [$libre => 1], ['phone' => $clienteMismo, 'name' => 'Nombre Dos']);
comprobar('Reutiliza al cliente por teléfono', pedidoPorId($db, $p1)['client_id'] === pedidoPorId($db, $p2)['client_id']);

// ── 4. Anular y devolver el stock ─────────────────────────
seccion('4. Anular y devolver el stock');

comprobar('Anular devuelve el stock', anularPedido($db, $pedidoA, 'prueba') && stockDe($db, $pocos) === 3, (string)stockDe($db, $pocos));
comprobar('Anular dos veces no devuelve el stock doble', !anularPedido($db, $pedidoA, 'otra vez') && stockDe($db, $pocos) === 3);
comprobar('El pedido queda cancelado con su motivo', estadoDe($db, $pedidoA) === 'cancelled'
    && pedidoPorId($db, $pedidoA)['cancel_reason'] === 'prueba');

// ── 5. Pago manual ────────────────────────────────────────
seccion('5. Pago manual y su ingreso');

$pedidoB = pedidoDePrueba($db, [$pocos => 1, $libre => 2]);
$admin = (int)$db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetchColumn();

$r = registrarPagoManualPedido($db, $pedidoB, 'Cheque', $admin);
comprobar('Rechaza un medio de pago que no existe', !$r['ok'], $r['msg']);

$r = registrarPagoManualPedido($db, $pedidoB, 'Efectivo', $admin);
comprobar('Registra el pago y deja el pedido pagado', $r['ok'] && estadoDe($db, $pedidoB) === 'paid', $r['msg']);
$ingreso = $db->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM finances WHERE description LIKE ? AND category = 'Venta de productos'");
$ingreso->execute(["%pedido #{$pedidoB} %"]);
[$cuantos, $monto] = $ingreso->fetch(PDO::FETCH_NUM);
comprobar('Crea un solo ingreso por el total del pedido', (int)$cuantos === 1 && (float)$monto === 26000.0, "{$cuantos} ingreso(s) · {$monto}");
$r = registrarPagoManualPedido($db, $pedidoB, 'Efectivo', $admin);
comprobar('No se puede cobrar dos veces el mismo pedido', !$r['ok'], $r['msg']);
$ingreso->execute(["%pedido #{$pedidoB} %"]);
comprobar('Y no duplica el ingreso', (int)$ingreso->fetchColumn() === 1);

// ── 6. Pago de Wompi simulado ─────────────────────────────
seccion('6. Pago de Wompi simulado');

$cliente = ['name' => 'Prueba', 'email' => '', 'phone' => ''];
$pedidoC = pedidoDePrueba($db, [$pocos => 1]);
$inicio  = iniciarPagoPedido($db, pedidoPorId($db, $pedidoC), $cliente);
$pagoC   = buscarPagoPedidoPorReferencia($db, $inicio['reference']);
comprobar('La referencia identifica un pedido (PED-)', referenciaEsDePedido($inicio['reference']) && !referenciaEsDePedido('BLUE-1-AB'), $inicio['reference']);
comprobar('Arma el checkout firmado con esa referencia', str_contains($inicio['checkout_url'], 'reference=' . $inicio['reference'])
    && str_contains($inicio['checkout_url'], 'signature%3Aintegrity'));
comprobar('El monto del cobro es el total del pedido', (float)$pagoC['amount'] === 10000.0);

$antes = estadoDe($db, $pedidoC);
$r = aplicarResultadoPedido($db, $pagoC, ['id' => 'T1', 'status' => 'PENDING']);
comprobar('Un pago todavía pendiente no cambia nada', !$r['aplicado'] && estadoDe($db, $pedidoC) === $antes);

$aprobada = transaccionDePrueba('APPROVED', 10000.0);
$r = aplicarResultadoPedido($db, $pagoC, $aprobada);
comprobar('Un pago aprobado deja el pedido pagado', $r['aplicado'] && estadoDe($db, $pedidoC) === 'paid');
$ingreso->execute(["%pedido #{$pedidoC} %"]);
comprobar('Registra el ingreso en Finanzas', (int)$ingreso->fetchColumn() === 1);
$avisos = $db->prepare("SELECT COUNT(*) FROM notifications WHERE order_id = ? AND type = 'pedido_pagado'");
$avisos->execute([$pedidoC]);
comprobar('Avisa al equipo en la campana', (int)$avisos->fetchColumn() >= 1);

$r = aplicarResultadoPedido($db, buscarPagoPedidoPorReferencia($db, $inicio['reference']), $aprobada);
comprobar('El mismo aviso repetido (webhook + regreso) no se aplica dos veces', !$r['aplicado']);
$ingreso->execute(["%pedido #{$pedidoC} %"]);
comprobar('Y no duplica el ingreso', (int)$ingreso->fetchColumn() === 1);

$pedidoD    = pedidoDePrueba($db, [$pocos => 1]);
$stockAntes = stockDe($db, $pocos);
$iniD       = iniciarPagoPedido($db, pedidoPorId($db, $pedidoD), $cliente);
aplicarResultadoPedido($db, buscarPagoPedidoPorReferencia($db, $iniD['reference']), transaccionDePrueba('DECLINED', 10000.0));
comprobar('Un pago rechazado cancela el pedido', estadoDe($db, $pedidoD) === 'cancelled');
comprobar('Y devuelve el stock', stockDe($db, $pocos) === $stockAntes + 1, "{$stockAntes} → " . stockDe($db, $pocos));

// Pago que llega después de vencer el tiempo (el pedido ya se anuló y su stock volvió)
$pedidoE = pedidoDePrueba($db, [$pocos => 1]);
$iniE    = iniciarPagoPedido($db, pedidoPorId($db, $pedidoE), $cliente);
$db->prepare('UPDATE order_payments SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE reference = ?')->execute([$iniE['reference']]);
liberarPedidosVencidos($db);
comprobar('Un pedido sin pagar a tiempo se anula solo', estadoDe($db, $pedidoE) === 'cancelled');
$stockLibre = stockDe($db, $pocos);
aplicarResultadoPedido($db, buscarPagoPedidoPorReferencia($db, $iniE['reference']), transaccionDePrueba('APPROVED', 10000.0));
comprobar('Si el pago llega tarde y hay stock, el pedido se recupera', estadoDe($db, $pedidoE) === 'paid' && stockDe($db, $pocos) === $stockLibre - 1);

// Pago tardío sin stock: otra persona se llevó la última unidad
$ultimo   = productoDePrueba($db, 'ultimo', 5000, 1);
$pedidoF  = pedidoDePrueba($db, [$ultimo => 1]);
$iniF     = iniciarPagoPedido($db, pedidoPorId($db, $pedidoF), $cliente);
$db->prepare('UPDATE order_payments SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE reference = ?')->execute([$iniF['reference']]);
liberarPedidosVencidos($db);
pedidoDePrueba($db, [$ultimo => 1]);
aplicarResultadoPedido($db, buscarPagoPedidoPorReferencia($db, $iniF['reference']), transaccionDePrueba('APPROVED', 5000.0));
comprobar('Si el pago llega tarde y ya no hay stock, queda pagado con una nota de alerta',
    estadoDe($db, $pedidoF) === 'paid' && str_contains((string)pedidoPorId($db, $pedidoF)['notes'], 'SIN STOCK'));
comprobar('Y el stock no queda en negativo', stockDe($db, $ultimo) === 0, (string)stockDe($db, $ultimo));

// ── 7. Estados y aviso de «listo» ─────────────────────────
seccion('7. Estados y aviso al cliente');

$carpetaCorreos = __DIR__ . '/../logs/correos';
$carpetaWhats   = __DIR__ . '/../logs/whatsapp';

$r = cambiarEstadoPedido($db, $pedidoB, 'delivered');
comprobar('No se salta pasos: pagado no pasa directo a entregado', !$r['ok'], $r['msg']);
$r = cambiarEstadoPedido($db, $pedidoB, 'preparing');
comprobar('Pagado → en preparación', $r['ok'] && estadoDe($db, $pedidoB) === 'preparing', $r['msg']);

// La recuperación del pago tardío (sección 6) gastó la última unidad: se repone para seguir probando.
$db->prepare('UPDATE products SET stock = 5 WHERE id = ?')->execute([$pocos]);

$correosAntes = archivosEn($carpetaCorreos);
$whatsAntes   = archivosEn($carpetaWhats);
$listaCorreos = glob($carpetaCorreos . '/*') ?: [];
$r      = cambiarEstadoPedido($db, $pedidoB, 'ready');
$pedido = pedidoPorId($db, $pedidoB);
comprobar('En preparación → listo', $r['ok'] && $pedido['status'] === 'ready', $r['msg']);
comprobar('Al quedar listo se avisa al cliente por correo', archivosEn($carpetaCorreos) === $correosAntes + 1,
    (archivosEn($carpetaCorreos) - $correosAntes) . ' correo(s) nuevos');
comprobar('Y por WhatsApp', archivosEn($carpetaWhats) === $whatsAntes + 1, (archivosEn($carpetaWhats) - $whatsAntes) . ' mensaje(s) nuevos');
comprobar('Queda registrado cuándo se le avisó', $pedido['ready_notified_at'] !== null && $pedido['ready_at'] !== null);

$nuevos = array_values(array_diff(glob($carpetaCorreos . '/*') ?: [], $listaCorreos));
if (count($nuevos) === 1) {
    $texto = (string)file_get_contents($nuevos[0]);
    comprobar('El correo dice que está listo para recoger y trae el detalle',
        str_contains($texto, 'listo para recoger') && str_contains($texto, 'Zz Prueba'));
    comprobar('Con el lugar y el enlace para ver el pedido',
        str_contains($texto, datoNegocio('direccion')) && str_contains($texto, 'pedido.php?id=' . $pedidoB));
}

$r = cambiarEstadoPedido($db, $pedidoB, 'ready');
comprobar('Marcarlo listo dos veces no avisa dos veces', !$r['ok'] && archivosEn($carpetaCorreos) === $correosAntes + 1);

// Un cliente que no aceptó avisos no recibe nada
$pedidoG = pedidoDePrueba($db, [$libre => 1], ['whatsapp' => false, 'email_reminder' => false]);
registrarPagoManualPedido($db, $pedidoG, 'Nequi', $admin);
$correosAntes = archivosEn($carpetaCorreos);
$whatsAntes   = archivosEn($carpetaWhats);
$r = cambiarEstadoPedido($db, $pedidoG, 'ready');
comprobar('Sin avisos aceptados no se envía nada y se le dice al equipo', $r['ok'] && str_contains($r['msg'], 'Avísale')
    && archivosEn($carpetaCorreos) === $correosAntes && archivosEn($carpetaWhats) === $whatsAntes, $r['msg']);

$r = cambiarEstadoPedido($db, $pedidoB, 'delivered');
comprobar('Listo → entregado', $r['ok'] && estadoDe($db, $pedidoB) === 'delivered');
$r = cancelarPedidoPorEquipo($db, $pedidoB, 'tarde');
comprobar('Un pedido entregado ya no se cancela', !$r['ok'], $r['msg']);

$stockAntes = stockDe($db, $pocos);
$pedidoH = pedidoDePrueba($db, [$pocos => 1]);
registrarPagoManualPedido($db, $pedidoH, 'Efectivo', $admin);
$r = cancelarPedidoPorEquipo($db, $pedidoH, 'El cliente ya no lo quiere');
comprobar('El equipo cancela un pedido pagado y devuelve el stock', $r['ok'] && estadoDe($db, $pedidoH) === 'cancelled' && stockDe($db, $pocos) === $stockAntes);
comprobar('Y se le recuerda devolver el dinero', str_contains($r['msg'], 'devuélvele el dinero'), $r['msg']);

// ── 8. Enlace privado y listados ──────────────────────────
seccion('8. Enlace privado y listados');

$pedido = pedidoPorId($db, $pedidoC);
comprobar('El token correcto abre el pedido', tokenPedidoEsValido($pedido, $pedido['token']));
comprobar('Un token distinto no', !tokenPedidoEsValido($pedido, str_repeat('a', 32)));
comprobar('Un token mal formado tampoco', !tokenPedidoEsValido($pedido, 'abc') && !tokenPedidoEsValido($pedido, ''));
comprobar('El enlace usa el id y el token', str_contains(urlEstadoPedido($pedido), 'pedido.php?id=' . $pedidoC . '&t=' . $pedido['token']));
comprobar('La línea de tiempo marca el paso actual', array_column(array_filter(pasosPedido('preparing'), fn($p) => $p['activo']), 'clave') === ['preparing']);

$conteo = conteoPedidosPorEstado($db);
comprobar('Cuenta los pedidos por estado', isset($conteo['paid'], $conteo['cancelled']) && $conteo['cancelled'] >= 3);
comprobar('Lista los pedidos del panel', count(listarPedidos($db, 'cancelled')) >= 3 && count(listarPedidos($db)) >= count(listarPedidos($db, 'paid')));

// ── 8b. Abuso: clientes ajenos y stock retenido ───────────
seccion('8b. Protección contra abuso');

// Quien pide sin sesión no puede renombrar a un cliente que ya existe (solo se completa el correo faltante).
$telVictima = telefonoDePrueba();
$db->prepare('INSERT INTO clients (name, phone) VALUES (?, ?)')->execute(['Nombre Original', $telVictima]);
pedidoDePrueba($db, [$libre => 1], ['phone' => $telVictima, 'name' => 'Nombre Falso', 'email' => 'nuevo@blue.test']);
$cl = $db->prepare('SELECT name, email FROM clients WHERE phone = ?');
$cl->execute([$telVictima]);
$fila = $cl->fetch();
comprobar('No se puede renombrar a un cliente existente desde la tienda', $fila['name'] === 'Nombre Original', $fila['name']);
comprobar('Pero sí se le completa el correo que le faltaba', $fila['email'] === 'nuevo@blue.test');

$db->prepare('UPDATE clients SET email = ? WHERE phone = ?')->execute(['viejo@blue.test', $telVictima]);
pedidoDePrueba($db, [$libre => 1], ['phone' => $telVictima, 'email' => 'otro@blue.test']);
$cl->execute([$telVictima]);
comprobar('Y no se le cambia un correo que ya tenía', $cl->fetch()['email'] === 'viejo@blue.test');

// Pedidos «por pagar» abandonados: se anulan pasadas las horas configuradas y el stock vuelve.
$horas = horasPedidoSinPago();
if ($horas > 0) {
    $viejo   = pedidoDePrueba($db, [$pocos => 1]);
    $reciente = pedidoDePrueba($db, [$libre => 1]);
    $stockAntes = stockDe($db, $pocos);
    $db->prepare('UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE id = ?')->execute([$horas + 1, $viejo]);

    liberarPedidosVencidos($db);
    comprobar('Un pedido sin pagar que pasó el límite se anula solo', estadoDe($db, $viejo) === 'cancelled');
    comprobar('Y devuelve su stock', stockDe($db, $pocos) === $stockAntes + 1, "{$stockAntes} → " . stockDe($db, $pocos));
    comprobar('Uno reciente no se toca', estadoDe($db, $reciente) === 'pending_payment');

    // Con un pago en línea abierto, manda su propio tiempo (minutos_reserva), no el de abandono.
    $conPago = pedidoDePrueba($db, [$libre => 1]);
    iniciarPagoPedido($db, pedidoPorId($db, $conPago), $cliente);
    $db->prepare('UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE id = ?')->execute([$horas + 1, $conPago]);
    liberarPedidosVencidos($db);
    comprobar('Un pedido con un pago en línea abierto no se anula por abandono', estadoDe($db, $conPago) === 'pending_payment');

    // Uno ya pagado nunca se anula por abandono, por viejo que sea.
    $pagado = pedidoDePrueba($db, [$libre => 1]);
    registrarPagoManualPedido($db, $pagado, 'Efectivo', $admin);
    $db->prepare('UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE id = ?')->execute([$horas + 1, $pagado]);
    liberarPedidosVencidos($db);
    comprobar('Un pedido ya pagado nunca se anula por abandono', estadoDe($db, $pagado) === 'paid');
}

// ── 9. Webhook de Wompi por HTTP ──────────────────────────
seccion('9. Webhook de Wompi (HTTP)');

/** Evento de Wompi con la firma que exige verificarFirmaEventoWompi(). */
function eventoWompiFirmado(string $referencia, string $estado, float $monto, string $secreto): array {
    $transaccion = ['id' => 'TX-' . bin2hex(random_bytes(5)), 'reference' => $referencia, 'status' => $estado,
                    'amount_in_cents' => montoEnCentavos($monto), 'payment_method_type' => 'CARD'];
    $timestamp = time();
    $cadena    = $transaccion['id'] . $transaccion['status'] . $transaccion['amount_in_cents'] . $timestamp . $secreto;
    return [
        'event' => 'transaction.updated', 'data' => ['transaction' => $transaccion],
        'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => hash('sha256', $cadena)],
        'timestamp' => $timestamp,
    ];
}

function postJson(string $url, array $cuerpo): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($cuerpo),
        'ignore_errors' => true, 'timeout' => 40,
    ]]);
    $respuesta = @file_get_contents($url, false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
    }
    return [$codigo, json_decode((string)$respuesta, true)];
}

$secretoEventos = (string)(configPagos()['secreto_eventos'] ?? '');
$urlWebhook     = 'http://localhost/Blue/api/wompi_webhook.php';

$apacheResponde = @file_get_contents('http://localhost/Blue/api/availability.php') !== false;
if ($secretoEventos === '') {
    echo "  (se omite: falta el secreto de eventos en config/wompi.php)\n";
} elseif (!$apacheResponde) {
    echo "  (se omite: Apache no responde en http://localhost/Blue)\n";
} else {
    $pedidoW = pedidoDePrueba($db, [$libre => 1]);
    $iniW    = iniciarPagoPedido($db, pedidoPorId($db, $pedidoW), $cliente);
    $total   = (float)pedidoPorId($db, $pedidoW)['total'];

    [$codigo] = postJson($urlWebhook, array_replace_recursive(
        eventoWompiFirmado($iniW['reference'], 'APPROVED', $total, $secretoEventos),
        ['signature' => ['checksum' => str_repeat('0', 64)]]
    ));
    comprobar('Un evento con firma falsa se rechaza (401)', $codigo === 401, "HTTP {$codigo}");
    comprobar('Y el pedido no cambia', estadoDe($db, $pedidoW) === 'pending_payment');

    [$codigo, $json] = postJson($urlWebhook, eventoWompiFirmado($iniW['reference'], 'APPROVED', $total, $secretoEventos));
    comprobar('Un evento aprobado y bien firmado responde 200', $codigo === 200 && ($json['ok'] ?? false) === true, "HTTP {$codigo} " . json_encode($json));
    comprobar('Y deja el pedido pagado', estadoDe($db, $pedidoW) === 'paid');

    [$codigo] = postJson($urlWebhook, eventoWompiFirmado($iniW['reference'], 'APPROVED', $total, $secretoEventos));
    $ingreso->execute(["%pedido #{$pedidoW} %"]);
    comprobar('Wompi reintenta el evento y no se duplica el ingreso', $codigo === 200 && (int)$ingreso->fetchColumn() === 1);

    [$codigo, $json] = postJson($urlWebhook, eventoWompiFirmado('PED-999999-ZZZZZZZZ', 'APPROVED', 1000.0, $secretoEventos));
    comprobar('Una referencia de pedido que no existe se ignora sin error', $codigo === 200 && ($json['ignorado'] ?? false) === true);
}

echo "\n" . str_repeat('═', 60) . "\n";
printf("Resultado: \033[32m%d correctas\033[0m · \033[31m%d fallas\033[0m · \033[33m%d omitidas\033[0m\n",
    $resultado['ok'], $resultado['falla'], $resultado['omitida']);
exit($resultado['falla'] > 0 ? 1 : 0);
