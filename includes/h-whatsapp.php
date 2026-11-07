<?php
/**
 * h-whatsapp.php — Envío de mensajes de WhatsApp (API oficial de Meta).
 * Responsable: Felipe.
 *
 * Dos formas de enviar:
 *   · con plantilla aprobada → la única que sirve para escribirle primero a alguien
 *   · texto libre → solo válido dentro de las 24 horas siguientes a un mensaje
 *     del cliente (es lo que usa el bot para contestar)
 *
 * También valida la firma de los eventos que manda Meta al webhook.
 *
 * Métodos (config/whatsapp.php):
 *   log       → no envía nada; guarda cada mensaje en logs/whatsapp/ (por defecto)
 *   cloud_api → POST a graph.facebook.com/{version}/{phone_number_id}/messages
 *
 * Nunca lanza excepciones hacia quien lo llama: un WhatsApp que no sale no
 * debe tumbar una cancelación ni una reserva. Los errores van al log de PHP.
 */

/**
 * Configuración de WhatsApp (config/whatsapp.php, cacheada).
 * Con $cambios se puede ajustar en memoria: lo usan las pruebas para trabajar
 * con un secreto y un token de ejemplo sin tocar los archivos de configuración.
 */
function configWhatsApp(array $cambios = []): array {
    static $config = null;
    if ($config === null) {
        $ruta   = __DIR__ . '/../config/whatsapp.php';
        $config = is_file($ruta) ? require $ruta : [];
    }
    if ($cambios) {
        $config = array_merge($config, $cambios);
    }
    return $config;
}

/** ¿Los mensajes se guardan en logs/whatsapp/ en vez de enviarse? */
function whatsappEnModoPrueba(): bool {
    return (string)(configWhatsApp()['metodo'] ?? 'log') !== 'cloud_api';
}

/**
 * Número en el formato que pide WhatsApp: solo dígitos, con código de país.
 * "300 123 4567" → "573001234567". Devuelve null si no parece un número válido.
 */
function telefonoWhatsapp(string $telefono): ?string {
    $digitos = preg_replace('/\D+/', '', $telefono);
    $prefijo = (string)(configWhatsApp()['prefijo_pais'] ?? '57');

    // Celular colombiano sin indicativo: 10 dígitos que empiezan por 3.
    if (strlen($digitos) === 10 && $digitos[0] === '3') {
        return $prefijo . $digitos;
    }
    return strlen($digitos) >= 11 && strlen($digitos) <= 15 ? $digitos : null;
}

/**
 * Envía un mensaje con plantilla.
 *
 * @param string $clavePlantilla clave en config/whatsapp.php → plantillas (ej. 'novedad_equipo')
 * @param array  $variables      valores de {{1}}, {{2}}… en ese orden
 * @return bool  true si se envió (o se guardó en modo log)
 */
function enviarWhatsAppPlantilla(string $telefono, string $clavePlantilla, array $variables): bool {
    $config    = configWhatsApp();
    $plantilla = (string)($config['plantillas'][$clavePlantilla] ?? '');
    $numero    = telefonoWhatsapp($telefono);

    if ($plantilla === '') {
        error_log('[Blue/WhatsApp] Plantilla sin configurar: ' . $clavePlantilla);
        return false;
    }
    if ($numero === null) {
        error_log('[Blue/WhatsApp] Número inválido, no se envía: ' . $telefono);
        return false;
    }

    // WhatsApp rechaza variables vacías o con saltos de línea en plantillas.
    $variables = array_map(
        fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v)) ?: '-',
        array_values($variables)
    );

    try {
        if (whatsappEnModoPrueba()) {
            whatsappGuardarEnLog($numero, $plantilla, $variables);
            return true;
        }
        whatsappEnviarPorCloudApi($numero, $plantilla, $variables);
        return true;
    } catch (Throwable $e) {
        error_log('[Blue/WhatsApp] No se pudo enviar ' . $plantilla . ' a ' . $numero . ': ' . $e->getMessage());
        return false;
    }
}

/** Envío de una plantilla por la Cloud API de Meta. */
function whatsappEnviarPorCloudApi(string $numero, string $plantilla, array $variables): void {
    $c = configWhatsApp();
    whatsappPeticionCloudApi([
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $numero,
        'type'              => 'template',
        'template'          => [
            'name'       => $plantilla,
            'language'   => ['code' => (string)($c['idioma'] ?? 'es')],
            'components' => $variables ? [[
                'type'       => 'body',
                'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => $v], $variables),
            ]] : [],
        ],
    ]);
}

/**
 * Envía el cuerpo ya armado a la Cloud API.
 * @throws RuntimeException si faltan credenciales o Meta responde con error
 */
function whatsappPeticionCloudApi(array $cuerpo): void {
    $c       = configWhatsApp();
    $idNum   = (string)($c['phone_number_id'] ?? '');
    $token   = (string)($c['token'] ?? '');
    $version = (string)($c['version_api'] ?? 'v23.0');

    if ($idNum === '' || $token === '') {
        throw new RuntimeException('Faltan phone_number_id o token en config/whatsapp.local.php.');
    }

    $ch = curl_init("https://graph.facebook.com/{$version}/{$idNum}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)($c['tiempo_limite'] ?? 10),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
    ]);
    $respuesta = curl_exec($ch);
    $codigo    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorRed  = curl_error($ch);
    curl_close($ch);

    if ($respuesta === false) {
        throw new RuntimeException('Sin respuesta de Meta: ' . $errorRed);
    }
    $datos = json_decode((string)$respuesta, true) ?: [];
    if ($codigo >= 400 || empty($datos['messages'][0]['id'])) {
        $detalle = $datos['error']['message'] ?? substr((string)$respuesta, 0, 300);
        throw new RuntimeException("Meta respondió HTTP {$codigo}: {$detalle}");
    }
}

/** Modo de prueba: guarda el mensaje como archivo de texto en logs/whatsapp/. */
function whatsappGuardarEnLog(string $numero, string $plantilla, array $variables): void {
    $carpeta = __DIR__ . '/../logs/whatsapp';
    if (!is_dir($carpeta)) @mkdir($carpeta, 0775, true);

    // El sufijo aleatorio evita que dos mensajes del mismo segundo se pisen.
    $archivo = $carpeta . '/' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '_' . $numero . '.txt';

    $contenido = "Para:      +{$numero}\n"
               . "Plantilla: {$plantilla}\n"
               . 'Idioma:    ' . (configWhatsApp()['idioma'] ?? 'es') . "\n"
               . 'Fecha:     ' . date('Y-m-d H:i:s') . "\n"
               . str_repeat('-', 60) . "\n";
    foreach ($variables as $i => $valor) {
        $contenido .= '{{' . ($i + 1) . "}} {$valor}\n";
    }
    @file_put_contents($archivo, $contenido);
}

/**
 * Responde un texto libre. Solo se puede dentro de las 24 horas siguientes a un
 * mensaje de la persona (ventana de atención al cliente); fuera de esa ventana
 * Meta lo rechaza y hay que usar una plantilla.
 */
function enviarWhatsAppTexto(string $telefono, string $texto): bool {
    $numero = telefonoWhatsapp($telefono);
    $texto  = trim($texto);

    if ($numero === null) {
        error_log('[Blue/WhatsApp] Número inválido, no se envía: ' . $telefono);
        return false;
    }
    if ($texto === '') return false;

    // WhatsApp corta los mensajes muy largos: mejor recortar aquí.
    $texto = mb_substr($texto, 0, 3800);

    try {
        if (whatsappEnModoPrueba()) {
            whatsappGuardarTextoEnLog($numero, $texto);
            return true;
        }
        whatsappPeticionCloudApi([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $numero,
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $texto],
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[Blue/WhatsApp] No se pudo responder a ' . $numero . ': ' . $e->getMessage());
        return false;
    }
}

/** Modo de prueba: guarda la respuesta de texto en logs/whatsapp/. */
function whatsappGuardarTextoEnLog(string $numero, string $texto): void {
    $carpeta = __DIR__ . '/../logs/whatsapp';
    if (!is_dir($carpeta)) @mkdir($carpeta, 0775, true);

    $archivo = $carpeta . '/' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '_' . $numero . '_texto.txt';
    @file_put_contents(
        $archivo,
        "Para:      +{$numero}\nTipo:      texto libre (ventana de 24 h)\n"
        . 'Fecha:     ' . date('Y-m-d H:i:s') . "\n" . str_repeat('-', 60) . "\n{$texto}\n"
    );
}

// ══════════════════════════════════════════════════════════
//   WEBHOOK: LO QUE MANDA META
// ══════════════════════════════════════════════════════════

/**
 * Verificación inicial del webhook: Meta llama con hub.mode, hub.verify_token y
 * hub.challenge, y espera que le devolvamos el challenge tal cual.
 * @return string|null el challenge si el token coincide; null si no
 */
function respuestaVerificacionWebhookWhatsApp(array $params): ?string {
    $esperado = (string)(configWhatsApp()['verify_token'] ?? '');
    $recibido = (string)($params['hub_verify_token'] ?? $params['hub.verify_token'] ?? '');
    $modo     = (string)($params['hub_mode'] ?? $params['hub.mode'] ?? '');
    $desafio  = (string)($params['hub_challenge'] ?? $params['hub.challenge'] ?? '');

    if ($esperado === '' || $modo !== 'subscribe' || !hash_equals($esperado, $recibido)) {
        return null;
    }
    return $desafio;
}

/**
 * ¿El evento viene de verdad de Meta? Se comprueba la firma HMAC del cuerpo con
 * la clave secreta de la app (cabecera X-Hub-Signature-256: sha256=...).
 * Sin clave configurada devuelve false: preferimos no atender nada antes que
 * atender mensajes falsos.
 */
function verificarFirmaWebhookWhatsApp(string $cuerpoCrudo, ?string $cabecera): bool {
    $secreto = (string)(configWhatsApp()['app_secret'] ?? '');
    if ($secreto === '' || !$cabecera || !str_starts_with($cabecera, 'sha256=')) {
        return false;
    }
    $esperada = 'sha256=' . hash_hmac('sha256', $cuerpoCrudo, $secreto);
    return hash_equals($esperada, trim($cabecera));
}

/**
 * Saca los mensajes de texto de un evento de Meta.
 * Ignora los avisos de entrega/lectura (value.statuses) y los tipos que no
 * sabemos leer (audio, ubicación…), que se responden con el menú.
 * @return array [['id','telefono','texto','nombre','tipo'], ...]
 */
function mensajesEntrantesDeEvento(array $evento): array {
    $mensajes = [];
    foreach ($evento['entry'] ?? [] as $entrada) {
        foreach ($entrada['changes'] ?? [] as $cambio) {
            $valor   = $cambio['value'] ?? [];
            $nombres = [];
            foreach ($valor['contacts'] ?? [] as $contacto) {
                $nombres[(string)($contacto['wa_id'] ?? '')] = (string)($contacto['profile']['name'] ?? '');
            }
            foreach ($valor['messages'] ?? [] as $m) {
                $telefono = preg_replace('/\D+/', '', (string)($m['from'] ?? ''));
                if ($telefono === '') continue;
                $tipo  = (string)($m['type'] ?? '');
                $texto = match ($tipo) {
                    'text'        => (string)($m['text']['body'] ?? ''),
                    'button'      => (string)($m['button']['text'] ?? ''),
                    'interactive' => (string)($m['interactive']['button_reply']['title']
                                     ?? $m['interactive']['list_reply']['title'] ?? ''),
                    default       => '',
                };
                $mensajes[] = [
                    'id'       => (string)($m['id'] ?? ''),
                    'telefono' => $telefono,
                    'texto'    => trim($texto),
                    'nombre'   => $nombres[$telefono] ?? '',
                    'tipo'     => $tipo,
                ];
            }
        }
    }
    return $mensajes;
}
