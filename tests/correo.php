<?php
// ============================================================
// Blue Therapy — Pruebas de los avisos por correo
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con Apache + MySQL encendidos:
//
//     php tests/correo.php
//
// Cubre:
//   1. Configuración y modo de prueba
//   2. Plantillas (contienen los datos correctos de cada evento)
//   3. Motor de envío en modo 'log' (guarda el archivo, valida direcciones)
//   4. Disparo desde una reserva nueva (con y sin el checkbox marcado)
//   5. Disparo desde el resultado del pago (aprobado / rechazado)
//   6. Disparo al vencer un cupo sin pagar
//   7. Disparo al terminar de pagar el saldo en el centro
//   8. Disparo desde las acciones del personal (confirmar/cancelar/completar)
//
// Crea citas, clientes y (si hace falta) un profesional de prueba, y los
// borra al terminar — junto con los archivos que haya dejado en
// logs/correos/. No manda ningún correo real: usa el modo 'log' salvo que
// config/mail.php ya esté en 'smtp' o 'mail', en cuyo caso avisa y sigue
// probando solo lo que no implica un envío real.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-reservas.php';
require_once __DIR__ . '/../includes/h-pagos.php';
require_once __DIR__ . '/../includes/h-correo.php';

// ── Utilidades de la suite ────────────────────────────────
$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$creado    = ['citas' => [], 'telefonos' => [], 'usuarios' => [], 'archivos_log' => []];

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

function telefonoDePrueba(): string {
    global $creado;
    $tel = '+57 398 ' . random_int(1000000, 9999999);
    $creado['telefonos'][] = $tel;
    return $tel;
}

function correoDePrueba(): string {
    return 'correo.prueba.' . bin2hex(random_bytes(4)) . '@blue.test';
}

/** Archivos que ya existen en logs/correos/ antes de disparar algo, para comparar después. */
function archivosLog(): array {
    $carpeta = __DIR__ . '/../logs/correos';
    return is_dir($carpeta) ? (glob($carpeta . '/*.txt') ?: []) : [];
}

/**
 * Ejecuta $accion() y devuelve el/los archivo(s) NUEVOS que aparecieron en
 * logs/correos/ (en modo 'log' cada envío = un archivo). Los deja marcados
 * para borrarlos al final de la suite.
 */
function capturarCorreosNuevos(callable $accion): array {
    global $creado;
    $antes = archivosLog();
    $accion();
    usleep(20000); // el nombre del archivo se arma con la hora hasta el segundo
    $nuevos = array_values(array_diff(archivosLog(), $antes));
    foreach ($nuevos as $f) $creado['archivos_log'][] = $f;
    return $nuevos;
}

function horarioLibre(PDO $db, int $duracion, int $desdeDias = 60): array {
    $choque = $db->prepare(
        "SELECT COUNT(*) FROM appointments WHERE date = ? AND status IN ('pending','confirmed')
            AND time_start < ? AND time_end > ?"
    );
    for ($d = $desdeDias; $d < $desdeDias + 90; $d++) {
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

/** Crea una cita 'confirmed' lista para probar, sin pasar por el flujo de pago. */
function citaDePrueba(PDO $db, array $servicio, int $desdeDias, bool $emailReminder, ?string $correo, ?int $staffId = null): array {
    global $creado;
    $correo = $correo ?? correoDePrueba();
    $db->prepare("INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)")
       ->execute(['Cliente de Prueba Correo', $correo, telefonoDePrueba()]);
    $clienteId = (int)$db->lastInsertId();

    [$fecha, $ini, $fin] = horarioLibre($db, max(30, (int)$servicio['duration_min']), $desdeDias);
    $db->prepare(
        "INSERT INTO appointments (client_id, staff_id, date, time_start, time_end, total_duration, status, email_reminder)
         VALUES (?, ?, ?, ?, ?, ?, 'confirmed', ?)"
    )->execute([$clienteId, $staffId, $fecha, $ini . ':00', $fin . ':00', (int)$servicio['duration_min'], $emailReminder ? 1 : 0]);
    $citaId = (int)$db->lastInsertId();
    $creado['citas'][] = $citaId;

    $db->prepare('INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)')->execute([$citaId, $servicio['id']]);

    return ['id' => $citaId, 'correo' => $correo, 'client_id' => $clienteId];
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
    foreach (array_unique($creado['archivos_log']) as $f) { if (is_file($f)) @unlink($f); }
    try {
        $db = getDB();
        foreach (array_unique($creado['citas']) as $id) {
            $st = $db->prepare('SELECT client_id FROM appointments WHERE id = ?');
            $st->execute([$id]);
            $clienteId = $st->fetchColumn();
            $db->prepare('DELETE FROM finances WHERE appointment_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
            if ($clienteId) $db->prepare('DELETE FROM clients WHERE id = ?')->execute([$clienteId]);
        }
        foreach (array_unique($creado['telefonos']) as $tel) {
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([$tel]);
        }
        foreach (array_unique($creado['usuarios']) as $id) {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar todos los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

// ══════════════════════════════════════════════════════════
$db      = getDB();
$mailCfg = configCorreo();
$metodo  = (string)($mailCfg['metodo'] ?? 'log');

echo "\033[1mBlue Therapy — pruebas de los avisos por correo\033[0m\n";
echo "Método configurado: $metodo" . ($metodo !== 'log' ? " \033[33m(se omiten los envíos reales)\033[0m" : '') . "\n";

// ══════════════════════════════════════════════════════════
seccion('1. Configuración y modo de prueba');
// ══════════════════════════════════════════════════════════
comprobar('correoEnModoPrueba() coincide con el método configurado', correoEnModoPrueba() === ($metodo === 'log'));
comprobar('correoActivo() es falso en modo de prueba (no se muestra como real al cliente)',
    $metodo !== 'log' || correoActivo() === false);
comprobar('gethostname_seguro() nunca devuelve vacío', gethostname_seguro() !== '');
comprobar('codificarCabeceraCorreo() sin acentos no toca el texto', codificarCabeceraCorreo('Cita confirmada') === 'Cita confirmada');
comprobar('codificarCabeceraCorreo() con acentos usa RFC 2047', str_starts_with(codificarCabeceraCorreo('Ñandú confirmó'), '=?UTF-8?B?'));
comprobar('correoHtmlATexto() quita etiquetas y deja el texto', str_contains(correoHtmlATexto('<p>Hola <strong>Ana</strong></p>'), 'Hola Ana'));

// ══════════════════════════════════════════════════════════
seccion('2. Plantillas por evento');
// ══════════════════════════════════════════════════════════
$citaFalsa = [
    'cliente_nombre' => 'Camila Restrepo', 'date' => date('Y-m-d', strtotime('+5 days')),
    'time_start' => '10:00:00', 'time_end' => '11:00:00', 'staff_nombre' => 'Laura Gómez',
];
$serviciosFalsos = [['name' => 'Masaje relajante', 'price' => 90000, 'duration_min' => 60]];

$m = correoSolicitudRecibida($citaFalsa, $serviciosFalsos, 'https://ejemplo.test/cita.php?id=1&t=x');
comprobar('Solicitud recibida: incluye nombre y servicio', str_contains($m['html'], 'Camila Restrepo') && str_contains($m['html'], 'Masaje relajante'));
comprobar('Solicitud recibida: trae botón al enlace de estado', str_contains($m['html'], 'cita.php?id=1&amp;t=x') || str_contains($m['html'], 'cita.php?id=1&t=x'));

$m = correoCitaConfirmada($citaFalsa, $serviciosFalsos, 'https://ejemplo.test/cita.php?id=1&t=x');
comprobar('Cita confirmada: menciona al profesional', str_contains($m['html'], 'Laura Gómez'));

$m = correoPagoRechazado($citaFalsa, $serviciosFalsos, 'https://ejemplo.test/booking.php', 'El banco no autorizó la transacción.');
comprobar('Pago rechazado: incluye el motivo', str_contains($m['html'], 'El banco no autorizó la transacción.'));
comprobar('Pago rechazado: el botón dice "Intentar de nuevo"', str_contains($m['html'], 'Intentar de nuevo'));

$m = correoCitaCancelada($citaFalsa, $serviciosFalsos);
comprobar('Cita cancelada: asunto correcto', str_contains($m['asunto'], 'cancelada'));

$m = correoCitaCompletada($citaFalsa, $serviciosFalsos);
comprobar('Cita completada: agradece la visita', str_contains($m['html'], 'Gracias por tu visita'));

$m = correoSaldoCompletado($citaFalsa, $serviciosFalsos, 90000.0, 'https://ejemplo.test/cita.php?id=1&t=x');
comprobar('Saldo completado: muestra el monto formateado', str_contains($m['html'], formatPrice(90000.0)));

$html = plantillaCorreo('Prueba', '<p>contenido</p>', [['texto' => 'Ver más', 'url' => 'https://x.test', 'primario' => true]]);
comprobar('La plantilla base es HTML válido con DOCTYPE', str_starts_with($html, '<!DOCTYPE html'));
comprobar('La plantilla base trae la marca Blue Therapy', str_contains($html, 'Blue') && str_contains($html, 'Therapy'));

// ══════════════════════════════════════════════════════════
seccion('3. Motor de envío');
// ══════════════════════════════════════════════════════════
comprobar('Dirección inválida: no envía y devuelve false', enviarCorreo('esto-no-es-un-correo', 'X', 'Asunto', '<p>x</p>') === false);

$correoTest = correoDePrueba();
$archivos = capturarCorreosNuevos(function () use ($correoTest, &$envioOk) {
    global $envioOk;
    $envioOk = enviarCorreo($correoTest, 'Prueba', 'Asunto de prueba', '<p>Cuerpo de prueba</p>');
});

if ($metodo === 'log') {
    comprobar('enviarCorreo() en modo log devuelve true', $envioOk === true);
    comprobar('Se guardó exactamente un archivo en logs/correos/', count($archivos) === 1, count($archivos) . ' archivo(s)');
    if ($archivos) {
        $contenido = file_get_contents($archivos[0]);
        comprobar('El archivo guardado trae el destinatario correcto', str_contains($contenido, $correoTest));
        comprobar('El archivo guardado trae el asunto correcto', str_contains($contenido, 'Asunto de prueba'));
        comprobar('El archivo guardado trae también el texto plano', str_contains($contenido, 'Cuerpo de prueba'));
    }
} else {
    omitir('Verificación de logs/correos/', "método configurado es '$metodo', no 'log'");
}

// ══════════════════════════════════════════════════════════
seccion('4. Disparo desde una reserva nueva (api/book.php → solicitud)');
// ══════════════════════════════════════════════════════════
$servicio = $db->query('SELECT id, name, duration_min, price FROM services WHERE active = 1 AND price > 0 ORDER BY price DESC LIMIT 1')->fetch();

if (!$servicio) {
    omitir('Disparo desde una reserva nueva', 'no hay servicios activos con precio');
} else {
    // Con el checkbox marcado: sí debe avisar.
    $v = validarDatosReserva([
        'services' => [(int)$servicio['id']], 'name' => 'Prueba Solicitud', 'phone' => telefonoDePrueba(),
        'email' => ($correoSolicitud = correoDePrueba()), 'email_reminder' => true,
    ] + array_combine(['date', 'time_start', 'time_end'], horarioLibre($db, max(30, (int)$servicio['duration_min']), 65)));
    comprobar('validarDatosReserva conserva email_reminder=true con correo presente', $v['datos']['email_reminder'] === true, implode(' ', $v['errores']));

    $archivos = capturarCorreosNuevos(function () use ($db, $v, &$reservaSolicitud) {
        global $creado;
        $reservaSolicitud = crearReservaPendiente($db, $v['datos']);
        $creado['citas'][] = $reservaSolicitud['appointment_id'];
        avisarPorCorreoSiCorresponde($db, $reservaSolicitud['appointment_id'], 'solicitud');
    });
    if ($metodo === 'log') {
        comprobar('Reserva con el checkbox marcado genera un correo', count($archivos) === 1);
        if ($archivos) comprobar('El correo es la plantilla de "solicitud recibida"', str_contains(file_get_contents($archivos[0]), 'Recibimos tu solicitud'));
    } else {
        omitir('Verificación del archivo generado', "método configurado es '$metodo'");
    }

    // Sin correo, aunque el checkbox venga en true desde el cliente: no hay a dónde avisar.
    $v2 = validarDatosReserva([
        'services' => [(int)$servicio['id']], 'name' => 'Prueba Sin Correo', 'phone' => telefonoDePrueba(),
        'email' => '', 'email_reminder' => true,
    ] + array_combine(['date', 'time_start', 'time_end'], horarioLibre($db, max(30, (int)$servicio['duration_min']), 66)));
    comprobar('Sin correo, el servidor apaga email_reminder aunque venga marcado', $v2['datos']['email_reminder'] === false);

    $archivos = capturarCorreosNuevos(function () use ($db, $v2) {
        global $creado;
        $r = crearReservaPendiente($db, $v2['datos']);
        $creado['citas'][] = $r['appointment_id'];
        avisarPorCorreoSiCorresponde($db, $r['appointment_id'], 'solicitud');
    });
    comprobar('Reserva sin correo no genera ningún archivo', count($archivos) === 0);

    // Checkbox sin marcar (opt-out): tampoco debe avisar aunque haya correo.
    $v3 = validarDatosReserva([
        'services' => [(int)$servicio['id']], 'name' => 'Prueba Opt Out', 'phone' => telefonoDePrueba(),
        'email' => correoDePrueba(), 'email_reminder' => false,
    ] + array_combine(['date', 'time_start', 'time_end'], horarioLibre($db, max(30, (int)$servicio['duration_min']), 67)));

    $archivos = capturarCorreosNuevos(function () use ($db, $v3) {
        global $creado;
        $r = crearReservaPendiente($db, $v3['datos']);
        $creado['citas'][] = $r['appointment_id'];
        avisarPorCorreoSiCorresponde($db, $r['appointment_id'], 'solicitud');
    });
    comprobar('Reserva con el checkbox desmarcado no genera ningún archivo', count($archivos) === 0);
}

// ══════════════════════════════════════════════════════════
seccion('5. Disparo desde el resultado del pago');
// ══════════════════════════════════════════════════════════
if (!$servicio) {
    omitir('Disparo desde el pago', 'no hay servicios activos con precio');
} else {
    // Aprobado → confirmada
    $cita = citaDePrueba($db, $servicio, 70, true, null);
    $db->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?")->execute([$cita['id']]);
    $ref = 'BLUE-' . $cita['id'] . '-CORREO';
    $db->prepare("INSERT INTO payments (appointment_id, provider, kind, reference, amount, service_total, status, expires_at)
                  VALUES (?, 'wompi', 'deposit', ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 20 MINUTE))")
       ->execute([$cita['id'], $ref, calcularAbonoReserva((float)$servicio['price']), $servicio['price']]);
    $pago = buscarPagoPorReferencia($db, $ref);

    $archivos = capturarCorreosNuevos(function () use ($db, $pago, $cita, $servicio) {
        aplicarResultadoPago($db, $pago, [
            'id' => 'TX-CORREO-' . bin2hex(random_bytes(3)), 'status' => 'APPROVED', 'reference' => $pago['reference'],
            'amount_in_cents' => montoEnCentavos((float)$pago['amount']), 'payment_method_type' => 'CARD',
        ]);
    });
    if ($metodo === 'log') {
        comprobar('Pago aprobado genera el correo de "cita confirmada"',
            count($archivos) === 1 && str_contains(file_get_contents($archivos[0]), 'confirmada'));
    } else {
        omitir('Verificación del archivo generado (aprobado)', "método configurado es '$metodo'");
    }

    // Rechazado → pago rechazado
    $cita2 = citaDePrueba($db, $servicio, 72, true, null);
    $db->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?")->execute([$cita2['id']]);
    $ref2 = 'BLUE-' . $cita2['id'] . '-CORREO-R';
    $db->prepare("INSERT INTO payments (appointment_id, provider, kind, reference, amount, service_total, status, expires_at)
                  VALUES (?, 'wompi', 'deposit', ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 20 MINUTE))")
       ->execute([$cita2['id'], $ref2, calcularAbonoReserva((float)$servicio['price']), $servicio['price']]);
    $pago2 = buscarPagoPorReferencia($db, $ref2);

    $archivos = capturarCorreosNuevos(function () use ($db, $pago2) {
        aplicarResultadoPago($db, $pago2, [
            'id' => 'TX-CORREO-R-' . bin2hex(random_bytes(3)), 'status' => 'DECLINED', 'reference' => $pago2['reference'],
            'amount_in_cents' => montoEnCentavos((float)$pago2['amount']),
        ]);
    });
    if ($metodo === 'log') {
        comprobar('Pago rechazado genera el correo correspondiente',
            count($archivos) === 1 && str_contains(file_get_contents($archivos[0]), 'No se pudo procesar tu pago'));
    } else {
        omitir('Verificación del archivo generado (rechazado)', "método configurado es '$metodo'");
    }

    // Cliente sin marcar el aviso: aprobado, pero no debe generar correo.
    $cita3 = citaDePrueba($db, $servicio, 74, false, null);
    $db->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?")->execute([$cita3['id']]);
    $ref3 = 'BLUE-' . $cita3['id'] . '-SINAVISO';
    $db->prepare("INSERT INTO payments (appointment_id, provider, kind, reference, amount, service_total, status, expires_at)
                  VALUES (?, 'wompi', 'deposit', ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 20 MINUTE))")
       ->execute([$cita3['id'], $ref3, calcularAbonoReserva((float)$servicio['price']), $servicio['price']]);
    $pago3 = buscarPagoPorReferencia($db, $ref3);

    $archivos = capturarCorreosNuevos(function () use ($db, $pago3) {
        aplicarResultadoPago($db, $pago3, [
            'id' => 'TX-CORREO-SA-' . bin2hex(random_bytes(3)), 'status' => 'APPROVED', 'reference' => $pago3['reference'],
            'amount_in_cents' => montoEnCentavos((float)$pago3['amount']), 'payment_method_type' => 'CARD',
        ]);
    });
    comprobar('Sin el aviso marcado, un pago aprobado no genera ningún correo', count($archivos) === 0);
}

// ══════════════════════════════════════════════════════════
seccion('6. Disparo al vencer un cupo sin pagar');
// ══════════════════════════════════════════════════════════
if (!$servicio) {
    omitir('Vencimiento de cupo', 'no hay servicios activos con precio');
} else {
    $cita = citaDePrueba($db, $servicio, 76, true, null);
    $db->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?")->execute([$cita['id']]);
    $ref = 'BLUE-' . $cita['id'] . '-VENCE';
    $db->prepare("INSERT INTO payments (appointment_id, provider, kind, reference, amount, service_total, status, expires_at)
                  VALUES (?, 'wompi', 'deposit', ?, 20000, ?, 'pending', DATE_SUB(NOW(), INTERVAL 5 MINUTE))")
       ->execute([$cita['id'], $ref, $servicio['price']]);

    $archivos = capturarCorreosNuevos(function () use ($db) { liberarReservasVencidas($db); });
    $estadoFinal = $db->prepare('SELECT status FROM appointments WHERE id = ?');
    $estadoFinal->execute([$cita['id']]);

    comprobar('El cupo vencido queda cancelado', $estadoFinal->fetchColumn() === 'cancelled');
    if ($metodo === 'log') {
        $encontrado = false;
        foreach ($archivos as $f) if (str_contains(file_get_contents($f), $cita['correo'])) $encontrado = true;
        comprobar('Se avisó por correo que el cupo se liberó', $encontrado);
    } else {
        omitir('Verificación del archivo generado (vencimiento)', "método configurado es '$metodo'");
    }
}

// ══════════════════════════════════════════════════════════
seccion('7. Disparo al terminar de pagar el saldo');
// ══════════════════════════════════════════════════════════
if (!$servicio || !($actor = (int)$db->query("SELECT id FROM users WHERE active = 1 ORDER BY role = 'admin' DESC, id LIMIT 1")->fetchColumn())) {
    omitir('Saldo completo', 'no hay servicio o usuario activo disponible');
} else {
    $cita = citaDePrueba($db, $servicio, 78, true, null);
    $total = (float)$servicio['price'];

    $archivos = capturarCorreosNuevos(function () use ($db, $cita, $total, $actor) {
        registrarPagoSaldo($db, $cita['id'], $total, ['monto' => $total, 'medio' => 'Efectivo', 'recibido_por' => $actor], $actor);
    });
    if ($metodo === 'log') {
        comprobar('Pagar el saldo completo genera el correo de "pago completo"',
            count($archivos) === 1 && str_contains(file_get_contents($archivos[0]), formatPrice($total)));
    } else {
        omitir('Verificación del archivo generado (saldo)', "método configurado es '$metodo'");
    }

    // Un abono parcial (no salda la cita) no debe generar el correo de "completo".
    $cita2 = citaDePrueba($db, $servicio, 79, true, null);
    $archivos = capturarCorreosNuevos(function () use ($db, $cita2, $total, $actor) {
        registrarPagoSaldo($db, $cita2['id'], $total, ['monto' => min(10000, $total - 1000), 'medio' => 'Efectivo', 'recibido_por' => $actor], $actor);
    });
    comprobar('Un abono parcial del saldo no dispara el correo de "completo"', count($archivos) === 0);
}

// ══════════════════════════════════════════════════════════
seccion('8. Disparo desde las acciones del personal (admin/appointments.php · staff/citas.php)');
// ══════════════════════════════════════════════════════════
$base = urlPublicaBlue();
foreach ($argv as $arg) if (str_starts_with($arg, '--base=')) $base = rtrim(substr($arg, 7), '/');

[$codigoPrueba] = http("$base/booking.php");
if ($codigoPrueba === 0) {
    omitir("Acciones del personal por HTTP contra $base", 'Apache no responde; enciéndelo o pasa --base=http://…/Blue');
} elseif (!$servicio) {
    omitir('Acciones del personal', 'no hay servicios activos con precio');
} else {
    function csrfDe(string $html): string { preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

    // ── Como admin: confirmar y cancelar ──
    $ckAdmin = tempnam(sys_get_temp_dir(), 'blue');
    [, $h] = http("$base/login.php", ['cookies' => $ckAdmin]);
    http("$base/login.php", ['cookies' => $ckAdmin, 'cuerpo' => http_build_query(['email' => 'admin@blue.com', 'password' => 'admin123', 'csrf_token' => csrfDe($h)])]);

    $citaAdmin = citaDePrueba($db, $servicio, 80, true, null);
    $db->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?")->execute([$citaAdmin['id']]);

    [, $h] = http("$base/admin/appointments.php", ['cookies' => $ckAdmin]);
    $archivos = capturarCorreosNuevos(function () use ($base, $ckAdmin, $citaAdmin, $h) {
        http("$base/admin/appointments.php", ['cookies' => $ckAdmin, 'cuerpo' => http_build_query([
            'action' => 'confirm', 'id' => $citaAdmin['id'], 'csrf_token' => csrfDe($h),
        ])]);
    });
    if ($metodo === 'log') {
        comprobar('El admin al confirmar dispara el correo de "cita confirmada"',
            count($archivos) === 1 && str_contains(file_get_contents($archivos[0]), 'confirmada'));
    } else {
        omitir('Verificación del archivo (confirmar, admin)', "método configurado es '$metodo'");
    }

    [, $h] = http("$base/admin/appointments.php", ['cookies' => $ckAdmin]);
    $archivos = capturarCorreosNuevos(function () use ($base, $ckAdmin, $citaAdmin, $h) {
        http("$base/admin/appointments.php", ['cookies' => $ckAdmin, 'cuerpo' => http_build_query([
            'action' => 'cancel', 'id' => $citaAdmin['id'], 'csrf_token' => csrfDe($h),
        ])]);
    });
    if ($metodo === 'log') {
        comprobar('El admin al cancelar dispara el correo de "cita cancelada"',
            count($archivos) === 1 && str_contains(file_get_contents($archivos[0]), 'cancelada'));
    } else {
        omitir('Verificación del archivo (cancelar, admin)', "método configurado es '$metodo'");
    }

    // Cita sin el aviso marcado: el admin la confirma, no debe salir ningún correo.
    $citaSinAviso = citaDePrueba($db, $servicio, 81, false, null);
    $db->prepare("UPDATE appointments SET status = 'pending' WHERE id = ?")->execute([$citaSinAviso['id']]);
    [, $h] = http("$base/admin/appointments.php", ['cookies' => $ckAdmin]);
    $archivos = capturarCorreosNuevos(function () use ($base, $ckAdmin, $citaSinAviso, $h) {
        http("$base/admin/appointments.php", ['cookies' => $ckAdmin, 'cuerpo' => http_build_query([
            'action' => 'confirm', 'id' => $citaSinAviso['id'], 'csrf_token' => csrfDe($h),
        ])]);
    });
    comprobar('Sin el aviso marcado, confirmar desde el panel no genera correo', count($archivos) === 0);

    // ── Como profesional: completar una cita propia ──
    $db->prepare("INSERT INTO users (name, email, password, role, active) VALUES ('Profesional Prueba Correo', ?, ?, 'staff', 1)")
       ->execute([correoDePrueba(), password_hash('staff12345', PASSWORD_DEFAULT)]);
    $staffId = (int)$db->lastInsertId();
    $creado['usuarios'][] = $staffId;
    $staffEmail = $db->prepare('SELECT email FROM users WHERE id = ?'); $staffEmail->execute([$staffId]);

    $ckStaff = tempnam(sys_get_temp_dir(), 'blue');
    [, $h] = http("$base/login.php", ['cookies' => $ckStaff]);
    http("$base/login.php", ['cookies' => $ckStaff, 'cuerpo' => http_build_query(['email' => $staffEmail->fetchColumn(), 'password' => 'staff12345', 'csrf_token' => csrfDe($h)])]);

    $citaStaff = citaDePrueba($db, $servicio, 82, true, null, $staffId);

    [, $h] = http("$base/staff/citas.php", ['cookies' => $ckStaff]);
    $archivos = capturarCorreosNuevos(function () use ($base, $ckStaff, $citaStaff, $h) {
        http("$base/staff/citas.php", ['cookies' => $ckStaff, 'cuerpo' => http_build_query([
            'action' => 'complete', 'id' => $citaStaff['id'], 'csrf_token' => csrfDe($h),
        ])]);
    });
    if ($metodo === 'log') {
        comprobar('El profesional al completar dispara el correo de "gracias por tu visita"',
            count($archivos) === 1 && str_contains(file_get_contents($archivos[0]), 'Gracias por tu visita'));
    } else {
        omitir('Verificación del archivo (completar, staff)', "método configurado es '$metodo'");
    }
}

// ══════════════════════════════════════════════════════════
seccion('Resumen');
// ══════════════════════════════════════════════════════════
printf("  %d correctas · %d fallas · %d omitidas\n", $resultado['ok'], $resultado['falla'], $resultado['omitida']);
if ($metodo === 'log' && !$resultado['falla']) {
    echo "  \033[32mListo. Cuando haya cuenta de correo real, cambia 'metodo' a 'smtp' en config/mail.local.php\033[0m\n"
       . "  \033[32my usa el botón «Enviar correo de prueba» en Configuración → Correo para confirmar la entrega.\033[0m\n";
}
exit($resultado['falla'] ? 1 : 0);
