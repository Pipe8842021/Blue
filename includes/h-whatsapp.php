<?php
/**
 * h-whatsapp.php — Envío de mensajes de WhatsApp (API oficial de Meta).
 * Responsable: Felipe.
 *
 * Solo mensajes con plantilla aprobada: son los únicos que un negocio puede
 * enviar sin que la persona le haya escrito en las últimas 24 horas.
 *
 * Métodos (config/whatsapp.php):
 *   log       → no envía nada; guarda cada mensaje en logs/whatsapp/ (por defecto)
 *   cloud_api → POST a graph.facebook.com/{version}/{phone_number_id}/messages
 *
 * Nunca lanza excepciones hacia quien lo llama: un WhatsApp que no sale no
 * debe tumbar una cancelación ni una reserva. Los errores van al log de PHP.
 */

/** Configuración de WhatsApp (config/whatsapp.php, cacheada). */
function configWhatsApp(): array {
    static $config = null;
    if ($config === null) {
        $ruta   = __DIR__ . '/../config/whatsapp.php';
        $config = is_file($ruta) ? require $ruta : [];
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

/** Envío real por la Cloud API de Meta. Lanza RuntimeException si Meta responde con error. */
function whatsappEnviarPorCloudApi(string $numero, string $plantilla, array $variables): void {
    $c       = configWhatsApp();
    $idNum   = (string)($c['phone_number_id'] ?? '');
    $token   = (string)($c['token'] ?? '');
    $version = (string)($c['version_api'] ?? 'v23.0');

    if ($idNum === '' || $token === '') {
        throw new RuntimeException('Faltan phone_number_id o token en config/whatsapp.local.php.');
    }

    $cuerpo = [
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
    ];

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
