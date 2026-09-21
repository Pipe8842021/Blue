<?php
// ============================================================
// Blue Therapy — Pruebas del webhook y del bot de WhatsApp (tarea 1)
// ------------------------------------------------------------
//     php tests/whatsapp.php
//
//   1. Firma y verificación del webhook
//   2. Lectura de los eventos de Meta
//   3. Respuestas del bot
//   4. Endpoint por HTTP
//
// Trabaja con mensajes simulados: no necesita cuenta de Meta ni envía nada
// (WhatsApp queda en modo log). Borra sus datos y sus archivos al terminar.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-whatsapp-bot.php';

$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$creado    = ['telefonos' => [], 'citas' => []];
$logsAntes = glob(__DIR__ . '/../logs/whatsapp/*.txt') ?: [];

function seccion(string $t): void { echo "\n\033[1m{$t}\033[0m\n" . str_repeat('─', 60) . "\n"; }

function comprobar(string $desc, bool $ok, string $detalle = ''): bool {
    global $resultado;
    $resultado[$ok ? 'ok' : 'falla']++;
    echo '  ' . ($ok ? "\033[32m[OK]\033[0m   " : "\033[31m[FALLA]\033[0m") . " {$desc}"
       . ($detalle !== '' ? "  \033[90m({$detalle})\033[0m" : '') . "\n";
    return $ok;
}

function omitir(string $desc, string $motivo): void {
    global $resultado;
    $resultado['omitida']++;
    echo "  \033[33m[--]\033[0m    {$desc}  \033[90m({$motivo})\033[0m\n";
}

/** Evento de Meta con un mensaje de texto, como el que llega al webhook. */
function eventoDePrueba(string $telefono, string $texto, string $id, string $nombre = 'Cliente WhatsApp'): array {
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '123', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => $telefono, 'profile' => ['name' => $nombre]]],
        'messages' => [['from' => $telefono, 'id' => $id, 'timestamp' => (string)time(), 'type' => 'text', 'text' => ['body' => $texto]]],
    ]]]]]];
}

function http(string $url, array $o = []): array {
    $ch   = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $o['cabeceras'] ?? []];
    if (array_key_exists('cuerpo', $o)) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $o['cuerpo']; }
    curl_setopt_array($ch, $opts);
    $cuerpo = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, (string)$cuerpo];
}

function limpiar(): void {
    global $creado, $logsAntes;
    try {
        $db = getDB();
        foreach (array_unique($creado['citas']) as $id) {
            $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
        }
        foreach (array_unique($creado['telefonos']) as $tel) {
            $db->prepare('DELETE FROM wa_messages WHERE phone = ?')->execute([$tel]);
            $db->prepare('DELETE FROM wa_conversations WHERE phone = ?')->execute([$tel]);
            $db->prepare("DELETE FROM notifications WHERE type = 'whatsapp' AND body LIKE ?")->execute(['%' . $tel . '%']);
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([substr($tel, -10)]);
        }
        foreach (array_diff(glob(__DIR__ . '/../logs/whatsapp/*.txt') ?: [], $logsAntes) as $archivo) {
            @unlink($archivo);
        }
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

echo "\033[1mBlue Therapy — pruebas del webhook y el bot de WhatsApp\033[0m\n";
$db = getDB();

// ══════════════════════════════════════════════════════════
seccion('1. Firma y verificación del webhook');
// ══════════════════════════════════════════════════════════
// Secreto y token de ejemplo solo en memoria: no se tocan los archivos de configuración.
configWhatsApp(['app_secret' => 'secreto-de-prueba', 'verify_token' => 'token-de-prueba', 'metodo' => 'log']);
$cuerpo = '{"hola":"mundo"}';
$firma  = 'sha256=' . hash_hmac('sha256', $cuerpo, 'secreto-de-prueba');

comprobar('Acepta un evento bien firmado', verificarFirmaWebhookWhatsApp($cuerpo, $firma));
comprobar('Rechaza una firma que no corresponde', !verificarFirmaWebhookWhatsApp($cuerpo, 'sha256=' . str_repeat('a', 64)));
comprobar('Rechaza un evento sin firma', !verificarFirmaWebhookWhatsApp($cuerpo, null));
comprobar('Rechaza el evento si el cuerpo cambió', !verificarFirmaWebhookWhatsApp($cuerpo . ' ', $firma));

comprobar('Responde el desafío de Meta con el token correcto',
    respuestaVerificacionWebhookWhatsApp(['hub_mode' => 'subscribe', 'hub_verify_token' => 'token-de-prueba', 'hub_challenge' => '9988']) === '9988');
comprobar('No responde con un token equivocado',
    respuestaVerificacionWebhookWhatsApp(['hub_mode' => 'subscribe', 'hub_verify_token' => 'otro', 'hub_challenge' => '9988']) === null);

// ══════════════════════════════════════════════════════════
seccion('2. Lectura de los eventos de Meta');
// ══════════════════════════════════════════════════════════
$mensajes = mensajesEntrantesDeEvento(eventoDePrueba('573009990101', 'Hola', 'wamid.PRUEBA1', 'Marcela Duque'));
comprobar('Saca el teléfono, el texto y el nombre del evento',
    count($mensajes) === 1 && $mensajes[0]['telefono'] === '573009990101'
    && $mensajes[0]['texto'] === 'Hola' && $mensajes[0]['nombre'] === 'Marcela Duque');

$soloEstados = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.X', 'status' => 'delivered']]]]]]]];
comprobar('Los avisos de entrega no se toman como mensajes', mensajesEntrantesDeEvento($soloEstados) === []);

// ══════════════════════════════════════════════════════════
seccion('3. Respuestas del bot');
// ══════════════════════════════════════════════════════════
$telefono = '573009990101';
$creado['telefonos'][] = $telefono;

$responder = function (string $texto, string $id) use ($db, $telefono) {
    return procesarMensajeEntranteWhatsApp($db, [
        'id' => $id, 'telefono' => $telefono, 'texto' => $texto, 'nombre' => 'Marcela Duque', 'tipo' => 'text',
    ]);
};

$r = $responder('Hola', 'wamid.T1');
comprobar('Saluda con el menú', $r['atendido'] && str_contains($r['texto'], 'Servicios y precios'));
comprobar('El menú saluda por el nombre', str_contains($r['texto'], 'Marcela'));
comprobar('Un mensaje repetido no se contesta dos veces', $responder('Hola', 'wamid.T1')['duplicado']);

$r = $responder('1', 'wamid.T2');
comprobar('La opción 1 muestra las categorías', $r['estado'] === WA_ESTADO_CATEGORIAS && str_contains($r['texto'], 'categorías'));

$r = $responder('1', 'wamid.T3');
comprobar('Elegir una categoría lista sus servicios con precio',
    $r['estado'] === WA_ESTADO_MENU && (bool)preg_match('/• .+ — (\$|Sin costo)/u', $r['texto']));

comprobar('La opción 2 manda el enlace para agendar', str_contains($responder('2', 'wamid.T4')['texto'], '/booking.php'));
comprobar('La opción 4 responde horario y dirección', str_contains($responder('horario', 'wamid.T5')['texto'], datoNegocio('horario')));
comprobar('Lo que no entiende vuelve al menú', str_contains($responder('quiero un unicornio', 'wamid.T6')['texto'], 'Servicios y precios'));
comprobar('Sin citas invita a reservar', str_contains($responder('3', 'wamid.T7')['texto'], 'No encontré citas'));

// Con una cita próxima de ese mismo número.
$db->prepare("INSERT INTO clients (name, phone) VALUES ('Marcela Duque (prueba WA)', ?)")->execute([substr($telefono, -10)]);
$clienteId = (int)$db->lastInsertId();
$servicio  = (int)$db->query('SELECT id FROM services WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$fecha     = date('Y-m-d', strtotime('+9 days'));
$db->prepare("INSERT INTO appointments (client_id, date, time_start, time_end, total_duration, status)
              VALUES (?, ?, '11:00:00', '12:00:00', 60, 'confirmed')")->execute([$clienteId, $fecha]);
$citaId = (int)$db->lastInsertId();
$creado['citas'][] = $citaId;
$db->prepare('INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)')->execute([$citaId, $servicio]);

$r = $responder('3', 'wamid.T8');
comprobar('Con cita próxima responde fecha y enlace privado',
    str_contains($r['texto'], formatDate($fecha)) && str_contains($r['texto'], 'cita.php?id=' . $citaId));

$r = $responder('5', 'wamid.T9');
$avisos = $db->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'whatsapp' AND body LIKE ?");
$avisos->execute(['%' . $telefono . '%']);
comprobar('Pedir una persona avisa al equipo',
    str_contains($r['texto'], 'ya le avisé al equipo') && (int)$avisos->fetchColumn() >= 1);

$guardados = $db->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND direction = 'out'");
$guardados->execute([$telefono]);
comprobar('Cada respuesta queda registrada', (int)$guardados->fetchColumn() >= 8);

configWhatsApp(['limite_respuestas_hora' => 1]);
comprobar('Con el tope alcanzado deja de responder', $responder('Hola otra vez', 'wamid.T10')['limite']);
configWhatsApp(['limite_respuestas_hora' => 20]);

// ══════════════════════════════════════════════════════════
seccion('4. Endpoint por HTTP');
// ══════════════════════════════════════════════════════════
$base = urlPublicaBlue();
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) $base = rtrim(substr($arg, 7), '/');
}

[$codigo] = http("{$base}/api/whatsapp_webhook.php?hub.mode=subscribe&hub.verify_token=inventado&hub.challenge=1");
if ($codigo === 0) {
    omitir("Pruebas por HTTP contra {$base}", 'Apache no responde');
} else {
    comprobar('La verificación con token inventado se rechaza', $codigo === 403, "HTTP {$codigo}");

    [$codigo] = http("{$base}/api/whatsapp_webhook.php", ['cuerpo' => '{}', 'cabeceras' => ['Content-Type: application/json']]);
    comprobar('Un evento sin firma se rechaza', $codigo === 401, "HTTP {$codigo}");

    [$codigo] = http("{$base}/api/whatsapp_webhook.php", ['cuerpo' => '{}', 'cabeceras' => ['X-Hub-Signature-256: sha256=' . str_repeat('b', 64)]]);
    comprobar('Un evento mal firmado tampoco pasa', $codigo === 401, "HTTP {$codigo}");

    omitir('Evento bien firmado por HTTP', 'el servidor necesita app_secret en config/whatsapp.local.php');
}

seccion('Resumen');
printf("  %d correctas · %d fallas · %d omitidas\n", $resultado['ok'], $resultado['falla'], $resultado['omitida']);
exit($resultado['falla'] > 0 ? 1 : 0);
