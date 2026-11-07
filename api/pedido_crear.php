<?php
// ============================================================
// Blue Therapy — Crea un pedido de la tienda
// ------------------------------------------------------------
// Lo llama tienda.js al confirmar el carrito. Precios y totales salen SIEMPRE de
// la base de datos (nunca del navegador) y el stock se descuenta al crear el pedido.
//
//   · Pasarela activa → responde `checkout_url` (Wompi): el pedido queda «por pagar»
//     y el stock apartado unos minutos.
//   · Sin pasarela    → el pedido queda «por pagar» y el equipo lo cobra y lo marca pagado.
// ============================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-pedidos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

if (!verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida. Recarga la página e intenta de nuevo.']);
    exit;
}

// ── Límite de frecuencia ──────────────────────────────────
// Evita pedidos en ráfaga desde una misma sesión (bots, doble clic).
$ahora = time();
if (!empty($_SESSION['last_order_at']) && ($ahora - $_SESSION['last_order_at']) < 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Ya enviaste un pedido hace un momento. Espera unos segundos e intenta de nuevo.']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos.']);
    exit;
}

['errores' => $errores, 'datos' => $datos] = validarDatosPedido($in);
if ($errores) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errores)]);
    exit;
}

// La conexión se abre aparte: si falla, el cliente recibe un 500 genérico
// y no el detalle de la infraestructura.
try {
    $db = getDB();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'No pudimos registrar tu pedido. Intenta de nuevo.']);
    exit;
}

$pedidoId = null;

try {
    // Devuelve al inventario lo apartado por pedidos que nunca se pagaron.
    liberarPedidosVencidos($db);

    $nuevo    = crearPedido($db, $datos);
    $pedidoId = $nuevo['order_id'];
    $pedido   = pedidoPorId($db, $pedidoId);

    $respuesta = [
        'success'    => true,
        'order_id'   => $pedidoId,
        'total'      => (float)$pedido['total'],
        'status_url' => urlEstadoPedido($pedido),
    ];

    if (pedidosPagoEnLinea()) {
        $pago = iniciarPagoPedido($db, $pedido, [
            'name' => $datos['name'], 'email' => $datos['email'], 'phone' => $datos['phone'],
        ]);
        $respuesta['checkout_url'] = $pago['checkout_url'];
        $respuesta['expires_in']   = $pago['expires_in'];
    } else {
        // Sin pasarela el equipo cobra por WhatsApp: se le avisa ya que hay un pedido por atender.
        avisarPedidoAlEquipo($db, $pedidoId, 'pedido_nuevo');
        avisarPedidoAlCliente($db, $pedidoId, 'recibido');
    }

    $_SESSION['last_order_at'] = $ahora;
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    // Producto agotado o ya no disponible: el mensaje sí es para el cliente.
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Si falló abrir la pasarela, el pedido no debe quedar apartando stock.
    if ($pedidoId) anularPedido($db, $pedidoId, 'No se pudo abrir la pasarela de pago.');
    error_log('[Blue/Tienda] Error al crear el pedido: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No pudimos registrar tu pedido. Intenta de nuevo.']);
}
