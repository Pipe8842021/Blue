<?php
// ============================================================
// Blue Therapy — Inicia el pago del abono de una reserva
// ------------------------------------------------------------
// Aparta el horario creando la cita en estado 'pending' y devuelve
// al navegador la URL del checkout de Wompi ya firmada.
// El monto NUNCA llega del cliente: se calcula con los precios de la BD.
// ============================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-reservas.php';
require_once __DIR__ . '/../includes/h-pagos.php';

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

if (!pagosEnLineaActivos()) {
    http_response_code(503);
    echo json_encode(['error' => 'Los pagos en línea no están disponibles en este momento.']);
    exit;
}

// Con las llaves de demostración el checkout de Wompi siempre falla: no se
// aparta ningún horario, para no bloquear la agenda con reservas imposibles.
if (pagosEnModoDemo()) {
    http_response_code(503);
    echo json_encode(['error' => 'La pasarela está en modo demostración: todavía no se pueden recibir pagos.']);
    exit;
}

// ── Límite de frecuencia ──────────────────────────────────
$ahora = time();
if (!empty($_SESSION['last_booking_at']) && ($ahora - $_SESSION['last_booking_at']) < 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Ya iniciaste un pago hace un momento. Espera unos segundos e intenta de nuevo.']);
    exit;
}

// ── Leer y validar input ──────────────────────────────────
$in = json_decode(file_get_contents('php://input'), true);
if (!$in) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos.']);
    exit;
}

['errores' => $errores, 'datos' => $datos] = validarDatosReserva($in);
if ($errores) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errores)]);
    exit;
}

// La conexión se abre aparte: si falla, el cliente recibe un 500 genérico
// y no el detalle de la infraestructura.
try {
    $db = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo abrir la pasarela de pago. Intenta de nuevo.']);
    exit;
}

$apptId = null;

try {
    // Devuelve a la agenda los cupos de abonos que nunca se pagaron.
    liberarReservasVencidas($db);

    // Si esta misma persona dejó un pago a medias, se suelta ese cupo
    // antes de apartarle otro: nadie debe bloquear dos horarios a la vez.
    if (!empty($_SESSION['pago_referencia'])) {
        $anterior = buscarPagoPorReferencia($db, $_SESSION['pago_referencia']);
        if ($anterior && $anterior['status'] === 'pending') {
            $db->prepare("UPDATE payments SET status = 'expired' WHERE id = ? AND status = 'pending'")
               ->execute([$anterior['id']]);
            anularReservaPendiente($db, (int)$anterior['appointment_id']);
        }
    }

    // ── Apartar el horario ────────────────────────────────
    $reserva = crearReservaPendiente($db, $datos);
    $apptId  = $reserva['appointment_id'];

    // ── Calcular el abono con los precios reales de la BD ──
    $abono = calcularAbonoReserva($reserva['total_price']);
    if ($abono <= 0) {
        throw new RuntimeException('No se pudo calcular el abono de esta reserva.');
    }

    $minutos   = max(5, (int)(configPagos()['minutos_reserva'] ?? 20));
    $venceEn   = date('Y-m-d H:i:s', $ahora + $minutos * 60);
    $referencia = generarReferenciaPago($apptId);

    $db->prepare(
        "INSERT INTO payments
            (appointment_id, provider, kind, environment, reference, amount, service_total, currency, status, expires_at)
         VALUES (?, 'wompi', 'deposit', ?, ?, ?, ?, 'COP', 'pending', ?)"
    )->execute([
        $apptId,
        pagosEnProduccion() ? 'prod' : 'test',
        $referencia,
        $abono,
        $reserva['total_price'],
        $venceEn,
    ]);

    $pago = buscarPagoPorReferencia($db, $referencia);

    // ── Armar el checkout firmado ─────────────────────────
    $campos = camposCheckoutWompi($pago, [
        'name'  => $datos['name'],
        'email' => $datos['email'],
        'phone' => $datos['phone'],
    ]);

    // Se recuerda la referencia por si Wompi devuelve al cliente sin el id
    // de la transacción (por ejemplo si cancela el pago a medio camino).
    $_SESSION['pago_referencia'] = $referencia;
    $_SESSION['last_booking_at'] = $ahora;

    echo json_encode([
        'success'        => true,
        'checkout_url'   => urlCheckoutWompi() . '?' . http_build_query($campos),
        'reference'      => $referencia,
        'appointment_id' => $apptId,
        'amount'         => (float)$abono,
        'amount_label'   => formatPrice((float)$abono),
        'total'          => (float)$reserva['total_price'],
        'total_label'    => formatPrice((float)$reserva['total_price']),
        'saldo_label'    => formatPrice(max(0, (float)$reserva['total_price'] - (float)$abono)),
        'expires_at'     => $venceEn,
        'expires_in'     => $minutos * 60,
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    if ($apptId) anularReservaPendiente($db, $apptId);
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);

} catch (Exception $e) {
    if ($apptId) anularReservaPendiente($db, $apptId);
    error_log('[Blue/Wompi] Error al iniciar el pago: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo abrir la pasarela de pago. Intenta de nuevo.']);
}
