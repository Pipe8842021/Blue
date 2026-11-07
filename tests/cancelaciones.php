<?php
// ============================================================
// Blue Therapy — Pruebas de cancelaciones y notificaciones (tarea 6)
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con Apache + MySQL encendidos:
//
//     php tests/cancelaciones.php
//
//   1. Canal de WhatsApp (modo log)
//   2. Política de cancelación: ¿puede cancelar el cliente?
//   3. Cancelar y regla del abono (a tiempo se devuelve, tarde se pierde)
//   4. Devolución del abono
//   5. Notificaciones internas y novedades
//   6. Por HTTP: el cliente cancela desde su enlace
//
// Crea usuarios, citas y pagos de prueba y los borra al terminar, junto
// con los mensajes de WhatsApp que se guarden en logs/whatsapp/.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-reservas.php';
require_once __DIR__ . '/../includes/h-pagos.php';
require_once __DIR__ . '/../includes/h-agenda.php';
require_once __DIR__ . '/../includes/h-cancelaciones.php';

// ── Utilidades de la suite ────────────────────────────────
$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$creado    = ['usuarios' => [], 'telefonos' => [], 'citas' => [], 'novedades' => []];
$carpetaWhatsapp = __DIR__ . '/../logs/whatsapp';
$whatsappAntes   = glob($carpetaWhatsapp . '/*.txt') ?: [];

function seccion(string $titulo): void {
    echo "\n\033[1m{$titulo}\033[0m\n" . str_repeat('─', 60) . "\n";
}

function comprobar(string $descripcion, bool $cumple, string $detalle = ''): bool {
    global $resultado;
    $resultado[$cumple ? 'ok' : 'falla']++;
    $marca = $cumple ? "\033[32m[OK]\033[0m   " : "\033[31m[FALLA]\033[0m";
    echo "  {$marca} {$descripcion}" . ($detalle !== '' ? "  \033[90m({$detalle})\033[0m" : '') . "\n";
    return $cumple;
}

function omitir(string $descripcion, string $motivo): void {
    global $resultado;
    $resultado['omitida']++;
    echo "  \033[33m[--]\033[0m    {$descripcion}  \033[90m({$motivo})\033[0m\n";
}

function usuarioDePrueba(PDO $db, string $nombre, string $rol, ?string $telefono): int {
    global $creado;
    $db->prepare('INSERT INTO users (name, email, password, role, phone, active) VALUES (?, ?, ?, ?, ?, 1)')
       ->execute([$nombre, 'prueba.cancelacion.' . bin2hex(random_bytes(4)) . '@blue.test',
                  password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $rol, $telefono]);
    $id = (int)$db->lastInsertId();
    $creado['usuarios'][] = $id;
    return $id;
}

/** Cita confirmada a $horas desde ahora, con abono aprobado si se pide. */
function citaDePrueba(PDO $db, float $horas, ?int $staffId, bool $conAbono, string $estadoAbono = 'approved'): int {
    global $creado;
    $tel = '+57 397 ' . random_int(1000000, 9999999);
    $creado['telefonos'][] = $tel;
    $db->prepare('INSERT INTO clients (name, phone) VALUES (?, ?)')->execute(['Cliente Prueba Cancelación', $tel]);
    $clienteId = (int)$db->lastInsertId();

    $inicio = (int)(floor((time() + $horas * 3600) / 3600) * 3600);
    $db->prepare(
        "INSERT INTO appointments (client_id, staff_id, date, time_start, time_end, total_duration, status, whatsapp_reminder)
         VALUES (?, ?, ?, ?, ?, 60, 'confirmed', 1)"
    )->execute([$clienteId, $staffId, date('Y-m-d', $inicio), date('H:i:s', $inicio), date('H:i:s', $inicio + 3600)]);
    $citaId = (int)$db->lastInsertId();
    $creado['citas'][] = $citaId;

    if ($conAbono) {
        $db->prepare(
            "INSERT INTO payments (appointment_id, provider, kind, reference, amount, service_total, status, paid_at, expires_at)
             VALUES (?, 'wompi', 'deposit', ?, 24000, 80000, ?, NOW(), NOW())"
        )->execute([$citaId, 'CANC-' . $citaId . '-' . bin2hex(random_bytes(3)), $estadoAbono]);
    }
    return $citaId;
}

function filaCita(PDO $db, int $id): array {
    return $db->query('SELECT * FROM appointments WHERE id = ' . (int)$id)->fetch() ?: [];
}

function abonoDeCita(PDO $db, int $id): array {
    return $db->query("SELECT * FROM payments WHERE kind = 'deposit' AND appointment_id = " . (int)$id)->fetch() ?: [];
}

function notificacionesDe(PDO $db, int $userId, int $citaId, string $tipo): int {
    $s = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND appointment_id = ? AND type = ?');
    $s->execute([$userId, $citaId, $tipo]);
    return (int)$s->fetchColumn();
}

/** Mensajes de WhatsApp guardados en modo log desde que empezó la suite. */
function whatsappNuevos(): array {
    global $carpetaWhatsapp, $whatsappAntes;
    return array_values(array_diff(glob($carpetaWhatsapp . '/*.txt') ?: [], $whatsappAntes));
}

function whatsappPara(string $digitos, string $plantilla): int {
    return count(array_filter(whatsappNuevos(), function ($archivo) use ($digitos, $plantilla) {
        $texto = (string)file_get_contents($archivo);
        return str_contains($texto, $digitos) && str_contains($texto, $plantilla);
    }));
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
            $db->prepare('DELETE FROM notifications WHERE appointment_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM finances WHERE appointment_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
        }
        foreach (array_unique($creado['telefonos']) as $tel) {
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([$tel]);
        }
        foreach (array_unique($creado['novedades']) as $titulo) {
            $db->prepare("DELETE FROM notifications WHERE type = 'novedad' AND title = ?")->execute([$titulo]);
        }
        foreach (array_unique($creado['usuarios']) as $id) {
            $db->prepare('DELETE FROM finances WHERE registered_by = ?')->execute([$id]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        foreach (whatsappNuevos() as $archivo) @unlink($archivo);
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar todos los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

echo "\033[1mBlue Therapy — pruebas de cancelaciones y notificaciones\033[0m\n";
$db     = getDB();
$minimo = horasMinimasCancelacion();
echo "Política: el cliente cancela hasta {$minimo} h antes · WhatsApp en modo: "
   . (whatsappEnModoPrueba() ? 'log (no envía)' : 'envío real') . "\n";

// ══════════════════════════════════════════════════════════
seccion('1. Canal de WhatsApp');
// ══════════════════════════════════════════════════════════
comprobar('Un celular local recibe el prefijo del país', telefonoWhatsapp('300 123 4567') === '573001234567');
comprobar('Un celular con +57 queda igual', telefonoWhatsapp('+57 300 123 4567') === '573001234567');
comprobar('Un número incompleto se descarta', telefonoWhatsapp('12345') === null);
comprobar('Una plantilla desconocida no se envía', !enviarWhatsAppPlantilla('3001234567', 'no_existe', []));

if (!whatsappEnModoPrueba()) {
    omitir('Mensaje guardado en logs/whatsapp/', 'WhatsApp está en envío real: la suite no manda mensajes');
} else {
    $enviado = enviarWhatsAppPlantilla('3009998877', 'novedad_equipo', ['Prueba de canal', 'Hola equipo']);
    comprobar('En modo log el mensaje se guarda en logs/whatsapp/',
        $enviado && whatsappPara('573009998877', 'aviso_novedad_equipo') === 1);
}

// Equipo de prueba: una profesional y un administrador, ambos con celular.
$staff = usuarioDePrueba($db, 'Prueba Cancelación Staff', 'staff', '3001110001');
$admin = usuarioDePrueba($db, 'Prueba Cancelación Admin', 'admin', '3001110002');

// ══════════════════════════════════════════════════════════
seccion('2. ¿Puede cancelar el cliente?');
// ══════════════════════════════════════════════════════════
$aTiempo = citaDePrueba($db, $minimo + 24, $staff, true);
$tarde   = citaDePrueba($db, 3, $staff, true);

$r = cancelacionClienteDisponible($db, filaCita($db, $aTiempo));
comprobar("Con más de {$minimo} h de anticipación puede cancelar", $r['puede'], $r['motivo']);
$r = cancelacionClienteDisponible($db, filaCita($db, $tarde));
comprobar("Con menos de {$minimo} h ya no puede", !$r['puede'] && str_contains($r['motivo'], (string)$minimo), $r['motivo']);

$enProceso = citaDePrueba($db, $minimo + 30, $staff, true, 'pending');
$r = cancelacionClienteDisponible($db, filaCita($db, $enProceso));
comprobar('Con un pago en proceso no puede cancelar todavía', !$r['puede'], $r['motivo']);

$completada = citaDePrueba($db, $minimo + 40, $staff, false);
$db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?")->execute([$completada]);
comprobar('Una cita completada no se cancela', !cancelacionClienteDisponible($db, filaCita($db, $completada))['puede']);

// ══════════════════════════════════════════════════════════
seccion('3. Cancelar y regla del abono');
// ══════════════════════════════════════════════════════════
$r = cancelarCita($db, $tarde, 'cliente', null, 'No alcanzo a llegar');
comprobar('El cliente no puede cancelar tarde', !$r['ok'] && filaCita($db, $tarde)['status'] === 'confirmed', $r['msg']);

$r = cancelarCita($db, $aTiempo, 'cliente', null, 'Me salió un viaje');
$fila = filaCita($db, $aTiempo);
comprobar('El cliente cancela a tiempo', $r['ok'] && $fila['status'] === 'cancelled', $r['msg']);
comprobar('Queda registrado quién canceló, cuándo y por qué',
    $fila['cancelled_by'] === 'cliente' && $fila['cancelled_at'] !== null && $fila['cancel_reason'] === 'Me salió un viaje');
comprobar('A tiempo, el abono queda por devolver', $r['abono'] === 'por_devolver' && abonoDeCita($db, $aTiempo)['refund_status'] === 'pending');

comprobar('La profesional asignada recibe el aviso en la campana', notificacionesDe($db, $staff, $aTiempo, 'cita_cancelada') === 1);
comprobar('El administrador también', notificacionesDe($db, $admin, $aTiempo, 'cita_cancelada') === 1);
if (whatsappEnModoPrueba()) {
    comprobar('Y les llega por WhatsApp', whatsappPara('573001110001', 'aviso_cita_cancelada') >= 1
        && whatsappPara('573001110002', 'aviso_cita_cancelada') >= 1);
}

$r = cancelarCita($db, $aTiempo, 'cliente', null);
comprobar('Una cita ya cancelada no se cancela dos veces', !$r['ok'], $r['msg']);

$r = cancelarCita($db, $tarde, 'equipo', $admin, 'Llamó a cancelar');
comprobar('El equipo sí puede cancelar tarde', $r['ok'] && filaCita($db, $tarde)['cancelled_by'] === 'equipo', $r['msg']);
comprobar('Tarde, el centro conserva el abono', $r['abono'] === 'se_conserva' && abonoDeCita($db, $tarde)['refund_status'] === 'none');
comprobar('Quien cancela no se avisa a sí mismo', notificacionesDe($db, $admin, $tarde, 'cita_cancelada') === 0
    && notificacionesDe($db, $staff, $tarde, 'cita_cancelada') === 1);

$sinAbono = citaDePrueba($db, $minimo + 50, $staff, false);
comprobar('Sin abono no hay nada que devolver', cancelarCita($db, $sinAbono, 'equipo', $admin)['abono'] === 'sin_abono');

// Editar la cita desde el panel y dejarla «Cancelada» aplica la misma regla.
$editada = citaDePrueba($db, $minimo + 60, $staff, true);
$f = filaCita($db, $editada);
$svc = (int)$db->query('SELECT id FROM services WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$g = guardarCita($db, ['id' => $editada, 'services' => [$svc], 'date' => $f['date'], 'time_start' => substr($f['time_start'], 0, 5),
                       'client_id' => $f['client_id'], 'staff_id' => $staff, 'status' => 'cancelled'], null, $admin);
$f = filaCita($db, $editada);
comprobar('Cancelar desde «Editar cita» también registra la cancelación',
    $g['ok'] && $f['status'] === 'cancelled' && $f['cancelled_by'] === 'equipo', $g['msg']);
comprobar('…y deja el abono por devolver si fue a tiempo', abonoDeCita($db, $editada)['refund_status'] === 'pending');

// ══════════════════════════════════════════════════════════
seccion('4. Devolución del abono');
// ══════════════════════════════════════════════════════════
$pagoTarde = (int)abonoDeCita($db, $tarde)['id'];
$r = marcarAbonoPorDevolver($db, $pagoTarde, $tarde);
comprobar('El admin puede decidir devolver un abono de cancelación tardía',
    $r['ok'] && abonoDeCita($db, $tarde)['refund_status'] === 'pending', $r['msg']);
comprobar('No se marca dos veces', !marcarAbonoPorDevolver($db, $pagoTarde, $tarde)['ok']);

$pagoVigente = (int)abonoDeCita($db, $enProceso)['id'];
comprobar('No se marca por devolver el abono de una cita que sigue activa', !marcarAbonoPorDevolver($db, $pagoVigente, $enProceso)['ok']);

$pagoATiempo = (int)abonoDeCita($db, $aTiempo)['id'];
$r = registrarDevolucionAbono($db, $pagoATiempo, $aTiempo, $admin);
$abono = abonoDeCita($db, $aTiempo);
comprobar('Registrar la devolución la marca como hecha', $r['ok'] && $abono['refund_status'] === 'done'
    && (int)$abono['refunded_by'] === $admin && $abono['refunded_at'] !== null, $r['msg']);
$egreso = $db->prepare("SELECT amount FROM finances WHERE appointment_id = ? AND type = 'expense' AND category = 'Devolución de abono'");
$egreso->execute([$aTiempo]);
comprobar('Y crea el egreso en Finanzas por el valor del abono', (float)$egreso->fetchColumn() === 24000.0);
comprobar('No se registra dos veces', !registrarDevolucionAbono($db, $pagoATiempo, $aTiempo, $admin)['ok']);

// ══════════════════════════════════════════════════════════
seccion('5. Notificaciones internas');
// ══════════════════════════════════════════════════════════
$noLeidas = contarNotificacionesNoLeidas($db, $staff);
comprobar('La profesional tiene avisos sin leer', $noLeidas >= 3, "{$noLeidas} sin leer");

$una = (int)$db->query('SELECT id FROM notifications WHERE user_id = ' . $staff . ' AND read_at IS NULL LIMIT 1')->fetchColumn();
comprobar('Nadie marca como leído el aviso de otra persona', !marcarNotificacionLeida($db, $una, $admin));
comprobar('La dueña sí lo marca', marcarNotificacionLeida($db, $una, $staff)
    && contarNotificacionesNoLeidas($db, $staff) === $noLeidas - 1);
marcarTodasLeidas($db, $staff);
comprobar('«Marcar todas» deja la campana en cero', contarNotificacionesNoLeidas($db, $staff) === 0);

$lista = notificacionesDeUsuario($db, $staff, 5);
comprobar('La lista trae los avisos más recientes con su enlace', count($lista) >= 1 && isset($lista[0]['url'], $lista[0]['title']));

comprobar('Una novedad sin título se rechaza', !publicarNovedad($db, $admin, '  ', 'Mensaje')['ok']);
$titulo = 'Novedad de prueba ' . bin2hex(random_bytes(3));
$creado['novedades'][] = $titulo;
$r = publicarNovedad($db, $admin, $titulo, 'Mañana el centro abre a las 10:00.');
$paraStaff = $db->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'novedad' AND title = ? AND user_id = ?");
$paraStaff->execute([$titulo, $staff]);
$paraAdmin = $db->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'novedad' AND title = ? AND user_id = ?");
$paraAdmin->execute([$titulo, $admin]);
comprobar('Una novedad le llega al equipo', $r['ok'] && (int)$paraStaff->fetchColumn() === 1, $r['msg']);
comprobar('…pero no a quien la publica', (int)$paraAdmin->fetchColumn() === 0);
if (whatsappEnModoPrueba()) {
    comprobar('La novedad también sale por WhatsApp', whatsappPara('573001110001', 'aviso_novedad_equipo') >= 1);
}

// ══════════════════════════════════════════════════════════
seccion('6. Por HTTP');
// ══════════════════════════════════════════════════════════
$base = urlPublicaBlue();
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) $base = rtrim(substr($arg, 7), '/');
}
[$codigo] = http("{$base}/api/notificaciones.php");
if ($codigo === 0) {
    omitir("Pruebas por HTTP contra {$base}", 'Apache no responde');
} else {
    comprobar('Las notificaciones exigen sesión', $codigo === 401, "HTTP {$codigo}");

    $porEnlace = citaDePrueba($db, $minimo + 70, $staff, true);
    $token     = tokenEnlaceCita($db, $porEnlace);
    $cookies   = tempnam(sys_get_temp_dir(), 'blue');
    [$codigo, $html] = http("{$base}/cita.php?id={$porEnlace}&t={$token}", ['cookies' => $cookies]);
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
    comprobar('El cliente ve el botón para cancelar', $codigo === 200 && str_contains($html, 'cancelar_cita'), "HTTP {$codigo}");

    $cuerpo = ['action' => 'cancelar_cita', 't' => str_repeat('a', 32), 'csrf_token' => $m[1] ?? '', 'motivo' => 'Prueba'];
    http("{$base}/cita.php?id={$porEnlace}", ['cookies' => $cookies, 'cuerpo' => http_build_query($cuerpo)]);
    comprobar('Con un enlace inventado no se cancela nada', filaCita($db, $porEnlace)['status'] === 'confirmed');

    $cuerpo['t'] = $token;
    [$codigo] = http("{$base}/cita.php?id={$porEnlace}", ['cookies' => $cookies, 'cuerpo' => http_build_query($cuerpo)]);
    $fila = filaCita($db, $porEnlace);
    comprobar('Con su enlace el cliente cancela la cita', $codigo === 302 && $fila['status'] === 'cancelled' && $fila['cancelled_by'] === 'cliente', "HTTP {$codigo}");

    [, $html] = http("{$base}/cita.php?id={$porEnlace}&t={$token}", ['cookies' => $cookies]);
    comprobar('Después ve que su abono será devuelto', str_contains($html, 'devol'));
}

// ══════════════════════════════════════════════════════════
seccion('Resumen');
// ══════════════════════════════════════════════════════════
printf("  %d correctas · %d fallas · %d omitidas\n", $resultado['ok'], $resultado['falla'], $resultado['omitida']);
exit($resultado['falla'] > 0 ? 1 : 0);
