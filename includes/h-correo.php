<?php
// ============================================================
// Blue Therapy — Avisos por correo al cliente (módulo Reservas)
// ------------------------------------------------------------
// El cliente marca en el wizard si quiere que le avisemos por correo
// (igual que el toggle de WhatsApp). Con eso activado se le escribe
// automáticamente cuando cambia el estado de su cita o de su pago:
//
//   solicitud recibida · cita confirmada · pago rechazado ·
//   cita cancelada · cita completada · cita pagada por completo
//
// No usa ninguna librería externa: trae su propio cliente SMTP mínimo
// (sockets + STARTTLS/SSL + AUTH LOGIN). Mientras `config/mail.php` no
// tenga un servidor real, cada correo se guarda como archivo de texto
// en logs/correos/ — sirve para revisar el mensaje sin cuenta real.
//
// Ningún envío interrumpe el flujo que lo dispara: los errores solo
// quedan en error_log(), la reserva o el pago siguen su curso normal.
// ============================================================

// avisarPorCorreoEstadoCita() usa citaConDetalle(), serviciosDeCita() y
// urlEstadoCita(): se asegura aquí que existan, sin depender de que quien
// incluya este archivo se acuerde de incluir también h-reservas.php.
require_once __DIR__ . '/h-reservas.php';

/** Configuración de correo (config/mail.php, cacheada). */
function configCorreo(): array {
    static $config = null;
    if ($config === null) {
        $ruta   = __DIR__ . '/../config/mail.php';
        $config = is_file($ruta) ? require $ruta : [];
    }
    return $config;
}

/** ¿Los avisos por correo llegan de verdad (SMTP real o mail() de PHP)? */
function correoActivo(): bool {
    $c = configCorreo();
    $metodo = $c['metodo'] ?? 'log';
    if ($metodo === 'mail') return true;
    if ($metodo === 'smtp') {
        $s = $c['smtp'] ?? [];
        return !empty($s['host']) && !empty($s['usuario']) && !empty($s['clave']);
    }
    return false; // 'log' → modo de prueba, no se muestra como opción real al cliente
}

/** ¿Estamos en modo de prueba (los correos se guardan en logs/correos/, no se envían)? */
function correoEnModoPrueba(): bool {
    return (string)(configCorreo()['metodo'] ?? 'log') === 'log';
}

// ── Cliente SMTP mínimo ─────────────────────────────────────

/**
 * Lee una respuesta SMTP completa (puede venir en varias líneas con "-").
 * Devuelve el código de 3 dígitos.
 */
function smtpLeerRespuesta($socket): int {
    $codigo = 0;
    while (($linea = fgets($socket, 512)) !== false) {
        $codigo = (int)substr($linea, 0, 3);
        if (isset($linea[3]) && $linea[3] === ' ') break; // última línea del bloque
    }
    return $codigo;
}

/** Envía un comando SMTP y exige que la respuesta empiece por alguno de los códigos esperados. */
function smtpComando($socket, string $comando, array $esperados): void {
    if ($comando !== '') fwrite($socket, $comando . "\r\n");
    $codigo = smtpLeerRespuesta($socket);
    if (!in_array($codigo, $esperados, true)) {
        throw new RuntimeException("El servidor de correo respondió $codigo a: " . trim($comando ?: '(saludo)'));
    }
}

/**
 * Envía un correo por SMTP real (sockets + STARTTLS/SSL + AUTH LOGIN).
 * Sin ninguna librería: solo la extensión OpenSSL que trae PHP.
 */
function smtpEnviar(array $smtp, string $deEmail, string $deNombre, string $paraEmail, string $paraNombre, string $asunto, string $html, string $texto, ?string $responderA): void {
    $host      = (string)$smtp['host'];
    $puerto    = (int)($smtp['puerto'] ?? 587);
    $seguridad = (string)($smtp['seguridad'] ?? 'tls');
    $limite    = max(5, (int)($smtp['tiempo_limite'] ?? 12));

    $destino = ($seguridad === 'ssl' ? 'ssl://' : '') . $host;
    $socket  = @fsockopen($destino, $puerto, $errno, $errstr, $limite);
    if (!$socket) {
        throw new RuntimeException("No se pudo conectar a $host:$puerto ($errstr)");
    }
    stream_set_timeout($socket, $limite);

    try {
        smtpLeerRespuesta($socket); // saludo del servidor (220)
        smtpComando($socket, 'EHLO ' . gethostname_seguro(), [250]);

        if ($seguridad === 'tls') {
            smtpComando($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo activar TLS con el servidor de correo.');
            }
            smtpComando($socket, 'EHLO ' . gethostname_seguro(), [250]);
        }

        if (!empty($smtp['usuario'])) {
            smtpComando($socket, 'AUTH LOGIN', [334]);
            smtpComando($socket, base64_encode($smtp['usuario']), [334]);
            smtpComando($socket, base64_encode((string)$smtp['clave']), [235]);
        }

        smtpComando($socket, 'MAIL FROM:<' . $deEmail . '>', [250]);
        smtpComando($socket, 'RCPT TO:<' . $paraEmail . '>', [250, 251]);
        smtpComando($socket, 'DATA', [354]);

        $cuerpo = smtpArmarMensaje($deEmail, $deNombre, $paraEmail, $paraNombre, $asunto, $html, $texto, $responderA);
        // Un punto solo en una línea termina los datos; si el mensaje trae una
        // línea así, hay que "escaparla" duplicándola (regla del protocolo SMTP).
        $cuerpo = preg_replace('/^\./m', '..', $cuerpo);
        fwrite($socket, $cuerpo . "\r\n.\r\n");
        smtpComando($socket, '', [250]);

        smtpComando($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/** Nombre de host para el saludo EHLO; nunca debe tumbar el envío si falla. */
function gethostname_seguro(): string {
    $h = @gethostname();
    return $h ?: 'localhost';
}

/** Arma el mensaje MIME completo (cabeceras + texto plano + HTML). */
function smtpArmarMensaje(string $deEmail, string $deNombre, string $paraEmail, string $paraNombre, string $asunto, string $html, string $texto, ?string $responderA): string {
    $limite = 'blue-' . bin2hex(random_bytes(10));
    $fecha  = date(DATE_RFC2822);

    $cabeceras = [
        'From: ' . codificarCabeceraCorreo($deNombre) . ' <' . $deEmail . '>',
        'To: ' . codificarCabeceraCorreo($paraNombre) . ' <' . $paraEmail . '>',
        'Subject: ' . codificarCabeceraCorreo($asunto),
        'Date: ' . $fecha,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $limite . '"',
    ];
    if ($responderA) $cabeceras[] = 'Reply-To: ' . $responderA;

    $cuerpo = "--$limite\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto))
            . "--$limite\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$limite--";

    return implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo;
}

/** Codifica un texto para una cabecera de correo si trae acentos/ñ (RFC 2047). */
function codificarCabeceraCorreo(string $texto): string {
    return preg_match('/[^\x20-\x7E]/', $texto)
        ? '=?UTF-8?B?' . base64_encode($texto) . '?='
        : $texto;
}

// ── Envío principal ──────────────────────────────────────────

/**
 * Punto único de envío: elige entre log / mail() / smtp según la configuración.
 * Nunca lanza excepción hacia afuera — cualquier problema queda en error_log()
 * y en el valor de retorno, para que el flujo que llamó siga funcionando.
 */
function enviarCorreo(string $paraEmail, string $paraNombre, string $asunto, string $html, string $texto = ''): bool {
    if (!filter_var($paraEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[Blue/Correo] Dirección inválida, no se envía: ' . $paraEmail);
        return false;
    }

    $c        = configCorreo();
    $metodo   = (string)($c['metodo'] ?? 'log');
    $deEmail  = (string)($c['remitente_email']  ?: 'no-responder@blue-therapy.local');
    $deNombre = (string)($c['remitente_nombre'] ?: 'Blue Therapy');
    $prefijo  = (string)($c['asunto_prefijo']   ?: '');
    $responderA = trim((string)($c['responder_a'] ?? '')) ?: null;
    $texto    = $texto ?: correoHtmlATexto($html);
    $asuntoFinal = $prefijo . $asunto;

    try {
        switch ($metodo) {
            case 'smtp':
                smtpEnviar($c['smtp'] ?? [], $deEmail, $deNombre, $paraEmail, $paraNombre, $asuntoFinal, $html, $texto, $responderA);
                return true;

            case 'mail':
                $cabeceras = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                           . 'From: ' . codificarCabeceraCorreo($deNombre) . " <$deEmail>\r\n";
                if ($responderA) $cabeceras .= "Reply-To: $responderA\r\n";
                if (!mail($paraEmail, codificarCabeceraCorreo($asuntoFinal), $html, $cabeceras)) {
                    throw new RuntimeException('mail() de PHP devolvió false (¿hay sendmail configurado?).');
                }
                return true;

            default: // 'log' — modo de prueba
                correoGuardarEnLog($paraEmail, $paraNombre, $asuntoFinal, $html, $texto);
                return true;
        }
    } catch (Throwable $e) {
        error_log('[Blue/Correo] No se pudo enviar a ' . $paraEmail . ': ' . $e->getMessage());
        return false;
    }
}

/** Quita las etiquetas HTML para tener una versión en texto plano razonable. */
function correoHtmlATexto(string $html): string {
    $texto = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $texto = preg_replace('/<\/(p|div|tr|h[1-6])>/i', "\n", $texto);
    $texto = strip_tags($texto);
    $texto = html_entity_decode($texto, ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\n{3,}/', "\n\n", $texto));
}

/** Modo de prueba: guarda el correo como archivo de texto en logs/correos/. */
function correoGuardarEnLog(string $paraEmail, string $paraNombre, string $asunto, string $html, string $texto): void {
    $carpeta = __DIR__ . '/../logs/correos';
    if (!is_dir($carpeta)) @mkdir($carpeta, 0775, true);

    // Un sufijo aleatorio evita que dos correos al mismo cliente dentro del
    // mismo segundo (ej. confirmar y luego cancelar) se pisen entre sí.
    $archivo = $carpeta . '/' . date('Y-m-d_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6)
             . '_' . preg_replace('/[^a-z0-9]+/i', '-', $paraEmail) . '.txt';
    $contenido = "Para:    $paraNombre <$paraEmail>\n"
               . "Asunto:  $asunto\n"
               . "Fecha:   " . date('Y-m-d H:i:s') . "\n"
               . str_repeat('-', 60) . "\n[TEXTO PLANO]\n\n$texto\n\n"
               . str_repeat('-', 60) . "\n[HTML]\n\n$html\n";
    @file_put_contents($archivo, $contenido);
}

// ── Plantilla visual ─────────────────────────────────────────

/**
 * Envoltorio HTML compartido por todos los correos. Los estilos van en línea
 * (los clientes de correo no cargan hojas de estilo externas) usando los
 * mismos colores del sitio público: teal #5bc4b8/#3aa89e, oscuro #1a1a1a.
 */
function plantillaCorreo(string $tituloCorto, string $contenidoHtml, array $botones = []): string {
    $botonesHtml = '';
    foreach ($botones as $b) {
        $botonesHtml .= '<a href="' . e($b['url']) . '" style="display:inline-block;margin:6px 8px;padding:13px 28px;'
            . 'border-radius:50px;background:' . ($b['primario'] ?? true ? 'linear-gradient(135deg,#5bc4b8,#3aa89e)' : '#1a1a1a')
            . ';color:#ffffff;font-family:\'DM Sans\',Arial,sans-serif;font-size:14px;font-weight:700;text-decoration:none">'
            . e($b['texto']) . '</a>';
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . e($tituloCorto) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#f4f6f7;font-family:\'DM Sans\',Arial,sans-serif;color:#333">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f7;padding:32px 16px">'
    . '<tr><td align="center">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;'
    . 'border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.07)">'

    // Cabecera de marca
    . '<tr><td style="background:#1a1a1a;padding:24px 32px;text-align:center">'
    . '<span style="font-family:Georgia,\'Times New Roman\',serif;font-style:italic;font-size:20px;color:#5bc4b8">Blue</span>'
    . '<span style="font-family:\'DM Sans\',Arial,sans-serif;font-size:10px;letter-spacing:3px;color:#ffffff;'
    . 'text-transform:uppercase;margin-left:6px">Therapy</span>'
    . '</td></tr>'

    // Contenido
    . '<tr><td style="padding:36px 32px 8px">' . $contenidoHtml . '</td></tr>'

    // Botones
    . ($botonesHtml ? '<tr><td style="padding:8px 32px 32px;text-align:center">' . $botonesHtml . '</td></tr>' : '<tr><td style="height:20px"></td></tr>')

    // Pie
    . '<tr><td style="padding:20px 32px;border-top:1px solid #eee;text-align:center">'
    . '<p style="margin:0;font-size:11.5px;color:#999;line-height:1.6">'
    . 'Blue Therapy · Tu ciudad, Colombia<br>Este correo se generó automáticamente, no es necesario responderlo.'
    . '</p></td></tr>'

    . '</table></td></tr></table></body></html>';
}

// ── Datos comunes a todas las plantillas ────────────────────

/** Fila "etiqueta / valor" reutilizada en varios correos. */
function correoFila(string $etiqueta, string $valor): string {
    return '<tr>'
        . '<td style="padding:9px 0;border-bottom:1px solid #f0f0f0;color:#888;font-size:12.5px;white-space:nowrap">' . e($etiqueta) . '</td>'
        . '<td style="padding:9px 0 9px 14px;border-bottom:1px solid #f0f0f0;color:#1a1a1a;font-size:13.5px;font-weight:600;text-align:right">' . $valor . '</td>'
        . '</tr>';
}

/** Bloque con los datos de la cita: servicios, fecha, hora, profesional. */
function correoDetalleCita(array $cita, array $servicios): string {
    $nombresSvc = e(implode(', ', array_column($servicios, 'name')));
    $filas  = correoFila('Servicio' . (count($servicios) > 1 ? 's' : ''), $nombresSvc);
    $filas .= correoFila('Fecha', e(formatDate($cita['date'])));
    $filas .= correoFila('Hora', e(formatTime($cita['time_start']) . ' – ' . formatTime($cita['time_end'])));
    if (!empty($cita['staff_nombre'])) {
        $filas .= correoFila('Profesional', e($cita['staff_nombre']));
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0">' . $filas . '</table>';
}

/** Botón hacia el enlace privado de estado, común a casi todas las plantillas. */
function botonVerEstado(string $url): array {
    return ['texto' => 'Ver el estado de mi cita', 'url' => $url, 'primario' => true];
}

// ── Plantillas por evento ────────────────────────────────────

function correoSolicitudRecibida(array $cita, array $servicios, string $urlEstado): array {
    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">Recibimos tu solicitud</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($cita['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">Gracias por reservar con nosotros. Tu cita quedó registrada '
          . 'como <strong>pendiente de confirmación</strong>; te escribiremos por WhatsApp para coordinar los últimos detalles.</p>'
          . correoDetalleCita($cita, $servicios);
    return ['asunto' => '📩 Recibimos tu solicitud de cita', 'html' => plantillaCorreo('Solicitud recibida', $html, [botonVerEstado($urlEstado)])];
}

function correoCitaConfirmada(array $cita, array $servicios, string $urlEstado): array {
    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">¡Tu cita está confirmada! ✓</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($cita['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">Ya tenemos tu horario separado. Te esperamos en la fecha y hora que confirmaste.</p>'
          . correoDetalleCita($cita, $servicios);
    return ['asunto' => '✓ Tu cita quedó confirmada', 'html' => plantillaCorreo('Cita confirmada', $html, [botonVerEstado($urlEstado)])];
}

function correoPagoRechazado(array $cita, array $servicios, string $urlReintentar, string $motivo): array {
    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">No pudimos confirmar tu pago</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($cita['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">' . e($motivo) . ' Tu horario no quedó reservado, pero puedes intentarlo de nuevo cuando quieras.</p>'
          . correoDetalleCita($cita, $servicios);
    return ['asunto' => '✕ No se pudo procesar tu pago', 'html' => plantillaCorreo('Pago rechazado', $html, [['texto' => 'Intentar de nuevo', 'url' => $urlReintentar, 'primario' => true]])];
}

function correoCitaCancelada(array $cita, array $servicios): array {
    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">Tu cita fue cancelada</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($cita['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">Te confirmamos que esta cita quedó cancelada. Si fue un error o quieres reprogramarla, escríbenos por WhatsApp.</p>'
          . correoDetalleCita($cita, $servicios);
    return ['asunto' => 'Tu cita fue cancelada', 'html' => plantillaCorreo('Cita cancelada', $html)];
}

function correoCitaCompletada(array $cita, array $servicios): array {
    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">¡Gracias por tu visita! 💆</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($cita['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">Esperamos que hayas disfrutado tu sesión. Cuando quieras agendar otra, aquí estaremos.</p>'
          . correoDetalleCita($cita, $servicios);
    return ['asunto' => '💆 Gracias por tu visita', 'html' => plantillaCorreo('Cita completada', $html)];
}

function correoSaldoCompletado(array $cita, array $servicios, float $total, string $urlEstado): array {
    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">Tu cita ya está pagada por completo ✓</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($cita['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">Registramos el pago de tu saldo. No queda nada pendiente por este valor: <strong>' . e(formatPrice($total)) . '</strong>.</p>'
          . correoDetalleCita($cita, $servicios);
    return ['asunto' => '✓ Pago completo registrado', 'html' => plantillaCorreo('Pago completo', $html, [botonVerEstado($urlEstado)])];
}

// ── Disparador único ──────────────────────────────────────────

/**
 * Envía (o no) el correo correspondiente a un evento de la cita.
 * Se llama desde todos los puntos donde cambia el estado; siempre revisa
 * primero si el cliente tiene correo Y pidió que le avisáramos, así que
 * es seguro llamarla siempre sin duplicar comprobaciones en cada sitio.
 *
 * $evento: 'solicitud' · 'confirmada' · 'rechazada' · 'cancelada' · 'completada' · 'saldada'
 * $extra:  datos propios del evento (ver cada rama)
 */
function avisarPorCorreoEstadoCita(PDO $db, int $citaId, string $evento, array $extra = []): bool {
    $cita = citaConDetalle($db, $citaId);
    if (!$cita || empty($cita['cliente_correo']) || (int)$cita['email_reminder'] !== 1) {
        return false; // sin correo, o el cliente no pidió avisos
    }

    $servicios = serviciosDeCita($db, $citaId);
    $urlEstado = urlEstadoCita($db, $citaId);

    switch ($evento) {
        case 'solicitud':  $m = correoSolicitudRecibida($cita, $servicios, $urlEstado); break;
        case 'confirmada': $m = correoCitaConfirmada($cita, $servicios, $urlEstado); break;
        case 'rechazada':  $m = correoPagoRechazado($cita, $servicios, $extra['url_reintentar'] ?? urlPublicaBlue() . '/booking.php', $extra['motivo'] ?? 'Tu pago no fue aprobado.'); break;
        case 'cancelada':  $m = correoCitaCancelada($cita, $servicios); break;
        case 'completada': $m = correoCitaCompletada($cita, $servicios); break;
        case 'saldada':    $m = correoSaldoCompletado($cita, $servicios, (float)($extra['total'] ?? 0), $urlEstado); break;
        default: return false;
    }

    return enviarCorreo($cita['cliente_correo'], $cita['cliente_nombre'], $m['asunto'], $m['html']);
}

/**
 * Igual que avisarPorCorreoEstadoCita(), pero sin poder lanzar nunca una
 * excepción hacia quien la llama. Pensada para usarse como una sola línea
 * justo después de un UPDATE de estado, sin envolverla en try/catch cada vez
 * (por ejemplo desde los manejadores de admin/appointments.php o staff/citas.php).
 */
function avisarPorCorreoSiCorresponde(PDO $db, int $citaId, string $evento, array $extra = []): void {
    try {
        avisarPorCorreoEstadoCita($db, $citaId, $evento, $extra);
    } catch (Throwable $e) {
        error_log('[Blue/Correo] No se pudo avisar el estado de la cita ' . $citaId . ': ' . $e->getMessage());
    }
}
