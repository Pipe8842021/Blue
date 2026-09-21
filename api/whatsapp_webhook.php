<?php
// ============================================================
// Blue Therapy — Webhook de WhatsApp (Cloud API de Meta)
// ------------------------------------------------------------
// Registrar en Meta → la app → WhatsApp → Configuración → Webhooks:
//   URL de devolución:  https://TU-DOMINIO/Blue/api/whatsapp_webhook.php
//   Token de verificación: el mismo de config/whatsapp.php → verify_token
//   Suscribirse al campo: messages
//
//   GET  → Meta comprueba la URL: devuelve hub.challenge si el token coincide.
//   POST → mensajes entrantes. Se valida la firma (X-Hub-Signature-256) con la
//          clave secreta de la app; sin firma válida no se atiende nada.
//
// Sin sesión ni CSRF a propósito: quien llama es Meta, no un navegador.
// Responde 200 lo más rápido posible (Meta reintenta si no).
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-whatsapp-bot.php';

// ── Verificación de la URL ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $desafio = respuestaVerificacionWebhookWhatsApp([
        'hub_mode'         => $_GET['hub_mode']         ?? ($_GET['hub.mode']         ?? ''),
        'hub_verify_token' => $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? ''),
        'hub_challenge'    => $_GET['hub_challenge']    ?? ($_GET['hub.challenge']    ?? ''),
    ]);

    header('Content-Type: text/plain; charset=utf-8');
    if ($desafio === null) {
        error_log('[Blue/WhatsApp] Verificación del webhook rechazada.');
        http_response_code(403);
        echo 'Token de verificación inválido.';
        exit;
    }
    echo $desafio;
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

// ── Autenticidad del evento ───────────────────────────────
$cuerpoCrudo = file_get_contents('php://input');
if (!verificarFirmaWebhookWhatsApp($cuerpoCrudo, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null)) {
    error_log('[Blue/WhatsApp] Evento con firma inválida, se descarta.');
    http_response_code(401);
    echo json_encode(['error' => 'Firma inválida.']);
    exit;
}

$evento = json_decode($cuerpoCrudo, true);
if (!is_array($evento)) {
    http_response_code(400);
    echo json_encode(['error' => 'Evento inválido.']);
    exit;
}

try {
    $db        = getDB();
    $atendidos = 0;

    // Los avisos de entrega y lectura no traen mensajes: se ignoran sin ruido.
    foreach (mensajesEntrantesDeEvento($evento) as $mensaje) {
        $r = procesarMensajeEntranteWhatsApp($db, $mensaje);
        if ($r['atendido']) $atendidos++;
    }

    echo json_encode(['ok' => true, 'atendidos' => $atendidos]);

} catch (Exception $e) {
    error_log('[Blue/WhatsApp] Error procesando el webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno.']);
}
