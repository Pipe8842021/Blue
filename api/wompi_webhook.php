<?php
// ============================================================
// Blue Therapy — Webhook de eventos de Wompi
// ------------------------------------------------------------
// Wompi llama a esta URL cada vez que cambia el estado de una
// transacción. Es el camino CONFIABLE de confirmación: funciona
// aunque el cliente cierre el navegador después de pagar.
//
// Registrar en el panel de Wompi (Desarrolladores → URL de eventos):
//   https://TU-DOMINIO/Blue/api/wompi_webhook.php
//
// Sin sesión ni CSRF a propósito: quien llama es Wompi, no un
// navegador. La autenticidad se comprueba con la firma del evento.
// ============================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-pagos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$evento = json_decode(file_get_contents('php://input'), true);

if (!is_array($evento) || empty($evento['data']['transaction'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Evento inválido.']);
    exit;
}

// ── Autenticidad del evento ───────────────────────────────
if (!verificarFirmaEventoWompi($evento)) {
    error_log('[Blue/Wompi] Evento con firma inválida, se descarta.');
    http_response_code(401);
    echo json_encode(['error' => 'Firma inválida.']);
    exit;
}

$transaccion = $evento['data']['transaction'];
$referencia  = (string)($transaccion['reference'] ?? '');

try {
    $db   = getDB();
    $pago = $referencia !== '' ? buscarPagoPorReferencia($db, $referencia) : null;

    if (!$pago) {
        // No es una reserva nuestra (o ya se borró la cita). Se responde 200
        // para que Wompi no siga reintentando un evento que nunca se podrá aplicar.
        error_log('[Blue/Wompi] Evento sin reserva asociada: ' . $referencia);
        echo json_encode(['ok' => true, 'ignorado' => true]);
        exit;
    }

    // Doble verificación: se le vuelve a preguntar a Wompi por la transacción.
    // Si la API no responde se usa el contenido del evento, que ya viene firmado.
    $confirmada = !empty($transaccion['id'])
        ? consultarTransaccionWompi((string)$transaccion['id'])
        : null;

    $resultado = aplicarResultadoPago($db, $pago, $confirmada ?? $transaccion);

    echo json_encode(['ok' => true, 'estado' => $resultado['status']]);

} catch (Exception $e) {
    error_log('[Blue/Wompi] Error procesando el webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno.']);
}
