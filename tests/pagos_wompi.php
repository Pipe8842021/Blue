<?php
// ============================================================
// Blue Therapy — Pruebas de la pasarela de pagos (Wompi)
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con Apache + MySQL encendidos:
//
//     php tests/pagos_wompi.php
//
// Pensado para correrlo justo después de pegar las llaves de la cuenta:
//   1. Diagnóstico de la cuenta (llaves, URL y conexión real con Wompi)
//   2. Cálculo del abono y armado del checkout firmado
//   3. Firma de los eventos del webhook
//   4. Flujo completo en la base: apartar cupo, aprobar, rechazar,
//      vencer, idempotencia, saldo en el centro y enlace del cliente
//   5. Endpoints por HTTP, según el modo en que esté la pasarela
//
// Crea citas de prueba y las borra al terminar. Nunca cobra dinero.
// Solo corre por consola: la carpeta tests/ está bloqueada en .htaccess.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-reservas.php';
require_once __DIR__ . '/../includes/h-pagos.php';

// ── Utilidades de la suite ────────────────────────────────
$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$creado    = ['citas' => [], 'telefonos' => []];

function seccion(string $titulo): void {
    echo "\n\033[1m" . $titulo . "\033[0m\n" . str_repeat('─', 60) . "\n";
}

function comprobar(string $descripcion, bool $cumple, string $detalle = ''): bool {
    global $resultado;
    $resultado[$cumple ? 'ok' : 'falla']++;
    echo ($cumple ? "  \033[32m[OK]\033[0m    " : "  \033[31m[FALLA]\033[0m ") . $descripcion
       . ($detalle !== '' ? "  \033[90m(" . $detalle . ")\033[0m" : '') . "\n";
    return $cumple;
}

function omitir(string $descripcion, string $motivo): void {
    global $resultado;
    $resultado['omitida']++;
    echo "  \033[33m[--]\033[0m    " . $descripcion . "  \033[90m(" . $motivo . ")\033[0m\n";
}

/** Teléfono de prueba único, para no chocar con clientes reales. */
function telefonoDePrueba(): string {
    global $creado;
    $tel = '+57 399 ' . random_int(1000000, 9999999);
    $creado['telefonos'][] = $tel;
    return $tel;
}

/** Busca un horario libre lejos en el futuro, para no interferir con la agenda real. */
function horarioLibre(PDO $db, int $duracion, int $desdeDias = 30): array {
    $choque = $db->prepare(
        "SELECT COUNT(*) FROM appointments WHERE date = ? AND status IN ('pending','confirmed')
            AND time_start < ? AND time_end > ?"
    );
    for ($d = $desdeDias; $d < $desdeDias + 60; $d++) {
        $fecha = date('Y-m-d', strtotime("+{$d} days"));
        if (date('N', strtotime($fecha)) == 7) continue;
        for ($h = 8; ($h * 60 + $duracion) <= 19 * 60; $h++) {
            $ini = sprintf('%02d:00', $h);
            $fin = date('H:i', strtotime($ini) + $duracion * 60);
            $choque->execute([$fecha, $fin . ':00', $ini . ':00']);
            if ((int)$choque->fetchColumn() === 0) return [$fecha, $ini, $fin];
        }
    }
    throw new RuntimeException('No se encontró un horario libre para las pruebas.');
}

/** Crea una reserva pendiente con los mismos pasos que api/pago_iniciar.php. */
function reservaDePrueba(PDO $db, array $servicio, int $desdeDias, string $venceEn = '+20 minutes'): array {
    global $creado;
    [$fecha, $ini, $fin] = horarioLibre($db, max(30, (int)$servicio['duration_min']), $desdeDias);
    $v = validarDatosReserva([
        'services' => [(int)$servicio['id']], 'date' => $fecha, 'time_start' => $ini, 'time_end' => $fin,
        'name' => 'Prueba Automática Wompi', 'phone' => telefonoDePrueba(), 'email' => 'pruebas@blue.test',
        'note' => 'Creada por tests/pagos_wompi.php', 'whatsapp' => false,
    ]);
    if ($v['errores']) throw new RuntimeException(implode(' ', $v['errores']));

    $reserva = crearReservaPendiente($db, $v['datos']);
    $creado['citas'][] = $reserva['appointment_id'];

    $referencia = generarReferenciaPago($reserva['appointment_id']);
    $db->prepare(
        "INSERT INTO payments (appointment_id, provider, kind, environment, reference, amount, service_total, currency, status, expires_at)
         VALUES (?, 'wompi', 'deposit', ?, ?, ?, ?, 'COP', 'pending', ?)"
    )->execute([
        $reserva['appointment_id'], pagosEnProduccion() ? 'prod' : 'test', $referencia,
        calcularAbonoReserva($reserva['total_price']), $reserva['total_price'],
        date('Y-m-d H:i:s', strtotime($venceEn)),
    ]);

    return $reserva + ['reference' => $referencia, 'date' => $fecha, 'time_start' => $ini, 'time_end' => $fin,
                       'pago' => buscarPagoPorReferencia($db, $referencia)];
}

function estadoCita(PDO $db, int $id): string {
    $s = $db->prepare('SELECT status FROM appointments WHERE id = ?');
    $s->execute([$id]);
    return (string)$s->fetchColumn();
}

function ingresosDeCita(PDO $db, int $id): int {
    $s = $db->prepare("SELECT COUNT(*) FROM finances WHERE appointment_id = ? AND type = 'income'");
    $s->execute([$id]);
    return (int)$s->fetchColumn();
}

/** Evento de Wompi firmado con el secreto configurado. */
function eventoFirmado(array $transaccion, string $secreto): array {
    $ts = time();
    return [
        'event' => 'transaction.updated',
        'data'  => ['transaction' => $transaccion],
        'timestamp' => $ts,
        'signature' => [
            'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
            'checksum'   => hash('sha256', $transaccion['id'] . $transaccion['status'] . $transaccion['amount_in_cents'] . $ts . $secreto),
        ],
        'environment' => pagosEnProduccion() ? 'prod' : 'test',
    ];
}

function http(string $url, array $opciones = []): array {
    $ch = curl_init($url);
    $o  = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $opciones['cabeceras'] ?? []];
    if (!empty($opciones['cookies'])) { $o[CURLOPT_COOKIEJAR] = $opciones['cookies']; $o[CURLOPT_COOKIEFILE] = $opciones['cookies']; }
    if (array_key_exists('cuerpo', $opciones)) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $opciones['cuerpo']; }
    curl_setopt_array($ch, $o);
    $cuerpo = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, (string)$cuerpo];
}

function limpiar(): void {
    global $creado;
    try {
        $db = getDB();
        foreach (array_unique($creado['citas']) as $id) {
            $db->prepare('DELETE FROM finances WHERE appointment_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
        }
        foreach (array_unique($creado['telefonos']) as $tel) {
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([$tel]);
        }
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar todos los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

// ══════════════════════════════════════════════════════════
$db     = getDB();
$config = configPagos();
$modo   = !pagosEnLineaActivos() ? 'sin llaves' : (pagosEnModoDemo() ? 'demostración' : 'llaves reales');

echo "\033[1mBlue Therapy — pruebas de la pasarela de pagos\033[0m\n";
echo 'Ambiente: ' . (pagosEnProduccion() ? 'PRODUCCIÓN' : 'pruebas (sandbox)') . " · modo: {$modo}\n";
if (pagosEnProduccion()) {
    echo "\033[33mAtención: estás en producción. Las pruebas no cobran, pero sí consultan la API real.\033[0m\n";
}

// ══════════════════════════════════════════════════════════
seccion('1. Diagnóstico de la cuenta');
// ══════════════════════════════════════════════════════════
$diag = diagnosticoPasarelaWompi($db);
$marcas = ['ok' => "\033[32m✓\033[0m", 'aviso' => "\033[33m!\033[0m", 'error' => "\033[31m✕\033[0m", 'omitido' => "\033[90m–\033[0m"];
$grupoActual = '';
foreach ($diag['checks'] as $ck) {
    if ($ck['grupo'] !== $grupoActual) { echo "  " . $ck['grupo'] . "\n"; $grupoActual = $ck['grupo']; }
    echo "    " . $marcas[$ck['estado']] . ' ' . $ck['nombre'] . ": \033[90m" . $ck['detalle'] . "\033[0m\n";
}
echo $diag['listo']
    ? "  \033[32m→ Lista para cobrar\033[0m" . ($diag['avisos'] ? " ({$diag['avisos']} aviso(s))" : '') . "\n"
    : "  \033[31m→ Todavía no puede cobrar: {$diag['errores']} punto(s) por corregir\033[0m\n";
echo "  \033[90m(el diagnóstico informa; no suma fallas a la suite)\033[0m\n";

// ══════════════════════════════════════════════════════════
seccion('2. Abono y checkout firmado');
// ══════════════════════════════════════════════════════════
$reglas   = $config['abono'] ?? [];
$minimo   = (float)($reglas['minimo'] ?? 0);
$redondeo = (float)($reglas['redondear_a'] ?? 0);

foreach ([80000.0, 320000.0, 35000.0, 12000.0] as $total) {
    $abono = calcularAbonoReserva($total);
    $enLimite = in_array($abono, [$total, $minimo, (float)($reglas['maximo'] ?? 0)], true);
    comprobar("Abono de " . formatPrice($total) . " = " . formatPrice($abono),
        $abono > 0 && $abono <= $total
        && ($total < $minimo || $abono >= $minimo)
        && ($redondeo <= 0 || $enLimite || fmod($abono, $redondeo) == 0.0),
        'dentro de total, mínimo y redondeo');
}
comprobar('Montos en centavos', montoEnCentavos(24000.0) === 2400000 && montoEnCentavos(24000.5) === 2400050);

comprobar('Celular colombiano con +57', telefonoParaWompi('+57 300 123 4567') === ['+57', '3001234567']);
comprobar('Celular sin prefijo',        telefonoParaWompi('300-123-4567') === ['+57', '3001234567']);
comprobar('Fijo o extranjero no se envía', telefonoParaWompi('604 123 4567') === null && telefonoParaWompi('+1 415 555 0101') === null);

$pagoFicticio = ['reference' => 'BLUE-0-PRUEBA', 'amount' => '24000.00', 'currency' => 'COP',
                 'expires_at' => date('Y-m-d H:i:s', strtotime('+20 minutes'))];
$campos = camposCheckoutWompi($pagoFicticio, ['name' => 'Ana Prueba', 'email' => 'ana@blue.test', 'phone' => '+57 311 222 3344']);

$faltan = array_diff(['public-key', 'currency', 'amount-in-cents', 'reference', 'signature:integrity', 'expiration-time', 'redirect-url'], array_keys($campos));
comprobar('El checkout lleva todos los campos obligatorios', !$faltan, $faltan ? 'faltan: ' . implode(', ', $faltan) : '');
comprobar('Monto en centavos en el checkout', $campos['amount-in-cents'] === '2400000');
comprobar('Fecha de expiración en ISO-8601 UTC', (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/', $campos['expiration-time']));
comprobar('Firma de integridad = SHA-256(ref+centavos+moneda+expiración+secreto)',
    hash_equals(hash('sha256', 'BLUE-0-PRUEBA' . '2400000' . 'COP' . $campos['expiration-time'] . (string)($config['secreto_integridad'] ?? '')),
                $campos['signature:integrity']));
comprobar('Vuelve a pago_resultado.php', str_ends_with($campos['redirect-url'], '/pago_resultado.php'));
comprobar('Teléfono con prefijo separado',
    ($campos['customer-data:phone-number-prefix'] ?? '') === '+57' && ($campos['customer-data:phone-number'] ?? '') === '3112223344');

// ══════════════════════════════════════════════════════════
seccion('3. Firma de los eventos del webhook');
// ══════════════════════════════════════════════════════════
$secretoEventos = (string)($config['secreto_eventos'] ?? '');
if ($secretoEventos === '') {
    omitir('Verificación de firmas', 'falta el secreto de eventos');
} else {
    $tx = ['id' => 'TX-PRUEBA-1', 'status' => 'APPROVED', 'reference' => 'BLUE-0-PRUEBA', 'amount_in_cents' => 2400000];
    $ev = eventoFirmado($tx, $secretoEventos);
    comprobar('Evento bien firmado se acepta', verificarFirmaEventoWompi($ev));

    $alterado = $ev; $alterado['data']['transaction']['status'] = 'DECLINED';
    comprobar('Evento con el estado cambiado se rechaza', !verificarFirmaEventoWompi($alterado));

    $alterado = $ev; $alterado['data']['transaction']['amount_in_cents'] = 100;
    comprobar('Evento con el monto cambiado se rechaza', !verificarFirmaEventoWompi($alterado));

    $alterado = $ev; $alterado['signature']['checksum'] = str_repeat('0', 64);
    comprobar('Checksum falso se rechaza', !verificarFirmaEventoWompi($alterado));

    comprobar('Evento firmado con otro secreto se rechaza', !verificarFirmaEventoWompi(eventoFirmado($tx, 'otro_secreto')));
}

// ══════════════════════════════════════════════════════════
seccion('4. Flujo completo en la base de datos');
// ══════════════════════════════════════════════════════════
$servicio = $db->query('SELECT id, name, duration_min, price FROM services WHERE active = 1 AND price > 0 ORDER BY price DESC LIMIT 1')->fetch();
$actor    = (int)$db->query("SELECT id FROM users WHERE active = 1 ORDER BY role = 'admin' DESC, id LIMIT 1")->fetchColumn();

if (!$servicio) {
    omitir('Flujo en la base', 'no hay servicios activos con precio');
} else {
    try {
        // ── Apartar el cupo ──
        $r = reservaDePrueba($db, $servicio, 40);
        comprobar('La reserva nace pendiente', estadoCita($db, $r['appointment_id']) === 'pending',
            "cita #{$r['appointment_id']} · {$servicio['name']} · abono " . formatPrice((float)$r['pago']['amount']));

        try {
            $v = validarDatosReserva(['services' => [(int)$servicio['id']], 'date' => $r['date'],
                'time_start' => $r['time_start'], 'time_end' => $r['time_end'],
                'name' => 'Intruso', 'phone' => telefonoDePrueba()]);
            $otra = crearReservaPendiente($db, $v['datos']);
            $creado['citas'][] = $otra['appointment_id'];
            comprobar('El horario apartado no se puede tomar dos veces', false);
        } catch (RuntimeException $e) {
            comprobar('El horario apartado no se puede tomar dos veces', true);
        }

        // ── Aprobar ──
        $tx = ['id' => 'TX-SUITE-' . bin2hex(random_bytes(4)), 'status' => 'APPROVED', 'reference' => $r['reference'],
               'amount_in_cents' => montoEnCentavos((float)$r['pago']['amount']), 'currency' => 'COP', 'payment_method_type' => 'CARD'];
        $ap = aplicarResultadoPago($db, $r['pago'], $tx);
        comprobar('Pago aprobado confirma la cita', $ap['aplicado'] && estadoCita($db, $r['appointment_id']) === 'confirmed');
        comprobar('El abono entra una vez a Finanzas', ingresosDeCita($db, $r['appointment_id']) === 1);

        $ap2 = aplicarResultadoPago($db, buscarPagoPorReferencia($db, $r['reference']), $tx);
        comprobar('Reaplicar el mismo pago no duplica nada', !$ap2['aplicado'] && ingresosDeCita($db, $r['appointment_id']) === 1);

        // ── Saldo en el centro ──
        $total   = (float)$r['total_price'];
        $resumen = resumenPagosCita($db, $r['appointment_id'], $total);
        comprobar('Pendiente = total − abono', abs($resumen['pendiente'] - ($total - (float)$r['pago']['amount'])) < 0.01,
            formatPrice($resumen['pendiente']));

        if (!$actor) {
            omitir('Registro del saldo', 'no hay usuarios activos para registrarlo');
        } else {
            $parcial = min(10000.0, $resumen['pendiente']);
            $s1 = registrarPagoSaldo($db, $r['appointment_id'], $total, ['monto' => $parcial, 'medio' => 'Efectivo', 'recibido_por' => $actor], $actor);
            comprobar('Registrar un pago parcial del saldo', $s1['ok'], $s1['msg']);

            $s2 = registrarPagoSaldo($db, $r['appointment_id'], $total, ['monto' => $total * 2, 'medio' => 'Efectivo', 'recibido_por' => $actor], $actor);
            comprobar('No deja cobrar más que el pendiente', !$s2['ok']);

            $s3 = registrarPagoSaldo($db, $r['appointment_id'], $total, ['monto' => 1000, 'medio' => 'Bitcoin', 'recibido_por' => $actor], $actor);
            comprobar('Rechaza un medio de pago no permitido', !$s3['ok']);

            $falta = resumenPagosCita($db, $r['appointment_id'], $total)['pendiente'];
            if ($falta > 0) {
                registrarPagoSaldo($db, $r['appointment_id'], $total, ['monto' => $falta, 'medio' => 'Nequi', 'recibido_por' => $actor], $actor);
            }
            comprobar('Con todo pagado la cita queda saldada', resumenPagosCita($db, $r['appointment_id'], $total)['esta_saldada']);

            $saldo = $db->prepare("SELECT id FROM payments WHERE appointment_id = ? AND kind = 'balance' ORDER BY id LIMIT 1");
            $saldo->execute([$r['appointment_id']]);
            $an = anularPagoSaldo($db, (int)$saldo->fetchColumn(), $r['appointment_id']);
            comprobar('Anular un saldo lo descuenta de Finanzas', $an['ok']);

            $an2 = anularPagoSaldo($db, (int)$r['pago']['id'], $r['appointment_id']);
            comprobar('El abono de Wompi no se puede anular desde el sistema', !$an2['ok']);
        }

        // ── Enlace privado del cliente ──
        $token = tokenEnlaceCita($db, $r['appointment_id']);
        comprobar('Token del enlace: 32 caracteres hex', (bool)preg_match('/^[a-f0-9]{32}$/', $token));
        comprobar('El token válido abre la cita y uno inventado no',
            tokenCitaEsValido($db, $r['appointment_id'], $token) && !tokenCitaEsValido($db, $r['appointment_id'], str_repeat('a', 32)));

        // ── Rechazo ──
        $rr = reservaDePrueba($db, $servicio, 45);
        $rechazo = aplicarResultadoPago($db, $rr['pago'], ['id' => 'TX-SUITE-R' . bin2hex(random_bytes(3)), 'status' => 'DECLINED',
            'reference' => $rr['reference'], 'amount_in_cents' => montoEnCentavos((float)$rr['pago']['amount'])]);
        comprobar('Pago rechazado libera el horario', $rechazo['status'] === 'declined' && estadoCita($db, $rr['appointment_id']) === 'cancelled');
        comprobar('Un pago rechazado no suma ingresos', ingresosDeCita($db, $rr['appointment_id']) === 0);

        // ── Vencimiento ──
        $rv = reservaDePrueba($db, $servicio, 50, '-1 minute');
        liberarReservasVencidas($db);
        comprobar('Cupo sin pagar a tiempo se libera solo',
            estadoCita($db, $rv['appointment_id']) === 'cancelled'
            && buscarPagoPorReferencia($db, $rv['reference'])['status'] === 'expired');

    } catch (Throwable $e) {
        comprobar('Flujo en la base sin errores inesperados', false, $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════
seccion('5. Endpoints por HTTP');
// ══════════════════════════════════════════════════════════
$base = urlPublicaBlue();
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) $base = rtrim(substr($arg, 7), '/');
}
[$codigo, $html] = http("{$base}/booking.php");

if ($codigo === 0) {
    omitir("Pruebas por HTTP contra {$base}", 'Apache no responde; enciéndelo o pasa --base=http://…/Blue');
} else {
    $cookies = tempnam(sys_get_temp_dir(), 'blue');
    [$codigo, $html] = http("{$base}/booking.php", ['cookies' => $cookies]);
    preg_match('/window\.BLUE_PAGOS = (\{.*?\});/', $html, $m);
    $pagosJs = json_decode($m[1] ?? '{}', true) ?: [];
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';

    comprobar('booking.php responde', $codigo === 200);
    comprobar('El wizard sabe si la pasarela está activa', ($pagosJs['activo'] ?? null) === pagosEnLineaActivos());
    comprobar('El wizard sabe si está en modo demostración', ($pagosJs['demo'] ?? false) === (pagosEnLineaActivos() && pagosEnModoDemo()));

    if ($servicio) {
        [$fecha, $ini, $fin] = horarioLibre($db, max(30, (int)$servicio['duration_min']), 55);
        $cuerpo = json_encode(['services' => [(int)$servicio['id']], 'date' => $fecha, 'time_start' => $ini, 'time_end' => $fin,
            'name' => 'Prueba HTTP Wompi', 'phone' => telefonoDePrueba(), 'email' => '', 'note' => '', 'whatsapp' => false]);
        $cab = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];

        [$codigo, $resp] = http("{$base}/api/pago_iniciar.php", ['cookies' => $cookies, 'cabeceras' => $cab, 'cuerpo' => $cuerpo]);
        $datos = json_decode($resp, true) ?: [];
        if (!empty($datos['appointment_id'])) $creado['citas'][] = (int)$datos['appointment_id'];

        if (!pagosEnLineaActivos()) {
            comprobar('Sin llaves, pago_iniciar no abre el checkout', $codigo === 503, "HTTP {$codigo}");
        } elseif (pagosEnModoDemo()) {
            comprobar('En modo demostración no se aparta ningún horario', $codigo === 503 && empty($datos['appointment_id']), "HTTP {$codigo}");
        } else {
            comprobar('pago_iniciar aparta el cupo y devuelve el checkout', $codigo === 200 && !empty($datos['checkout_url']), "HTTP {$codigo}");
            if (!empty($datos['checkout_url'])) {
                $url = parse_url($datos['checkout_url']);
                parse_str($url['query'] ?? '', $q);
                comprobar('El checkout apunta a Wompi', ($url['host'] ?? '') === 'checkout.wompi.co');
                comprobar('La firma del checkout real es válida', hash_equals(
                    hash('sha256', $q['reference'] . $q['amount-in-cents'] . $q['currency'] . $q['expiration-time'] . (string)$config['secreto_integridad']),
                    (string)($q['signature:integrity'] ?? '')));
                comprobar('El monto sale de la base, no del navegador',
                    (int)$q['amount-in-cents'] === montoEnCentavos(calcularAbonoReserva((float)$servicio['price'])));

                if ($secretoEventos !== '') {
                    $pagoHttp = buscarPagoPorReferencia($db, $q['reference']);
                    $txHttp = ['id' => 'TX-HTTP-' . bin2hex(random_bytes(4)), 'status' => 'APPROVED', 'reference' => $q['reference'],
                               'amount_in_cents' => (int)$q['amount-in-cents'], 'currency' => 'COP', 'payment_method_type' => 'CARD'];
                    [$c1] = http("{$base}/api/wompi_webhook.php", ['cabeceras' => ['Content-Type: application/json'],
                        'cuerpo' => json_encode(eventoFirmado($txHttp, $secretoEventos))]);
                    comprobar('El webhook confirma la cita con un evento firmado',
                        $c1 === 200 && estadoCita($db, (int)$pagoHttp['appointment_id']) === 'confirmed', "HTTP {$c1}");
                }
            }
        }

        // Sesión nueva con su propio token CSRF y otro horario: así un 409 solo
        // puede venir de la regla del abono, no de la seguridad ni de un choque.
        $cookiesBook = tempnam(sys_get_temp_dir(), 'blue');
        [, $htmlBook] = http("{$base}/booking.php", ['cookies' => $cookiesBook]);
        preg_match('/name="csrf-token" content="([^"]+)"/', $htmlBook, $mb);
        [$fechaB, $iniB, $finB] = horarioLibre($db, max(30, (int)$servicio['duration_min']), 58);
        $cuerpoBook = json_encode(['services' => [(int)$servicio['id']], 'date' => $fechaB, 'time_start' => $iniB, 'time_end' => $finB,
            'name' => 'Prueba HTTP sin abono', 'phone' => telefonoDePrueba(), 'email' => '', 'note' => '', 'whatsapp' => false]);
        [$codigo, $respBook] = http("{$base}/api/book.php", ['cookies' => $cookiesBook,
            'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($mb[1] ?? '')], 'cuerpo' => $cuerpoBook]);
        $datosBook = json_decode($respBook, true) ?: [];
        if (!empty($datosBook['appointment_id'])) $creado['citas'][] = (int)$datosBook['appointment_id'];

        if (abonoEsObligatorio()) {
            comprobar('Con abono obligatorio no se puede reservar sin pagar', $codigo === 409, "HTTP {$codigo}");
        } else {
            comprobar('Sin abono obligatorio se reserva directo y recibe su enlace',
                $codigo === 200 && !empty($datosBook['status_url']), "HTTP {$codigo}");
        }
    }

    [$codigo] = http("{$base}/api/wompi_webhook.php");
    comprobar('El webhook rechaza GET', $codigo === 405, "HTTP {$codigo}");

    [$codigo] = http("{$base}/api/wompi_webhook.php", ['cabeceras' => ['Content-Type: application/json'],
        'cuerpo' => json_encode(eventoFirmado(['id' => 'X', 'status' => 'APPROVED', 'reference' => 'X', 'amount_in_cents' => 1], 'secreto_falso'))]);
    comprobar('El webhook rechaza eventos mal firmados', $codigo === 401, "HTTP {$codigo}");

    if (!empty($r['appointment_id'])) {
        [$codigo] = http("{$base}/cita.php?id={$r['appointment_id']}&t=" . tokenEnlaceCita($db, $r['appointment_id']));
        comprobar('El cliente abre su cita con el enlace', $codigo === 200, "HTTP {$codigo}");
        [$codigo] = http("{$base}/cita.php?id={$r['appointment_id']}&t=" . str_repeat('b', 32));
        comprobar('Un enlace inventado no abre nada', $codigo === 404, "HTTP {$codigo}");
    }

    [$codigo, $html] = http("{$base}/pago_resultado.php");
    comprobar('Salir del checkout sin pagar muestra un mensaje claro', $codigo === 200 && str_contains($html, 'El pago no se completó'));

    [$codigo] = http("{$base}/tests/pagos_wompi.php");
    comprobar('La carpeta tests/ no es accesible desde el navegador', $codigo === 404, "HTTP {$codigo}");
}

// ══════════════════════════════════════════════════════════
seccion('Resumen');
// ══════════════════════════════════════════════════════════
printf("  %d correctas · %d fallas · %d omitidas\n", $resultado['ok'], $resultado['falla'], $resultado['omitida']);
if (!$diag['listo']) {
    echo "  \033[33mLa lógica está probada, pero la cuenta todavía no puede cobrar (ver sección 1).\033[0m\n";
} elseif (!$resultado['falla']) {
    echo "  \033[32mSiguiente paso: un pago de prueba desde el sitio con una tarjeta de sandbox de Wompi.\033[0m\n";
}
exit($resultado['falla'] ? 1 : 0);
