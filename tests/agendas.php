<?php
// ============================================================
// Blue Therapy — Pruebas de las agendas por profesional (tarea 5)
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con Apache + MySQL encendidos:
//
//     php tests/agendas.php
//
//   1. Reglas de disponibilidad (sin base de datos)
//   2. Horario y servicios de cada profesional
//   3. Disponibilidad de la semana y asignación de profesional
//   4. Reservas en la base: cupo por profesional y citas sin asignar
//   5. Endpoints por HTTP
//
// Crea profesionales, horarios y citas de prueba y los borra al terminar.
// Solo corre por consola: la carpeta tests/ está bloqueada en .htaccess.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-reservas.php';
require_once __DIR__ . '/../includes/h-agendas.php';

// ── Utilidades de la suite ────────────────────────────────
$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$creado    = ['usuarios' => [], 'telefonos' => []];

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

function telefonoDePrueba(): string {
    global $creado;
    $tel = '+57 398 ' . random_int(1000000, 9999999);
    $creado['telefonos'][] = $tel;
    return $tel;
}

/** Crea un usuario de prueba y lo registra para borrarlo al final. */
function usuarioDePrueba(PDO $db, string $nombre, string $rol): int {
    global $creado;
    $db->prepare('INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, 1)')
       ->execute([$nombre, 'prueba.agenda.' . bin2hex(random_bytes(4)) . '@blue.test',
                  password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $rol]);
    $id = (int)$db->lastInsertId();
    $creado['usuarios'][] = $id;
    return $id;
}

/** Datos de reserva ya validados para una fecha y hora concretas. */
function datosDeReserva(array $servicios, string $fecha, string $hora, int $staffId): array {
    $v = validarDatosReserva([
        'services' => $servicios, 'date' => $fecha, 'time_start' => $hora, 'time_end' => $hora,
        'name' => 'Prueba Agenda', 'phone' => telefonoDePrueba(), 'email' => '', 'note' => '',
        'whatsapp' => false, 'staff_id' => $staffId,
    ]);
    if ($v['errores']) throw new RuntimeException(implode(' ', $v['errores']));
    return $v['datos'];
}

function staffDeCita(PDO $db, int $id): ?int {
    $s = $db->prepare('SELECT staff_id FROM appointments WHERE id = ?');
    $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === null || $v === false ? null : (int)$v;
}

function limpiar(): void {
    global $creado;
    try {
        $db = getDB();
        foreach (array_unique($creado['telefonos']) as $tel) {
            $ids = $db->prepare('SELECT a.id FROM appointments a JOIN clients c ON c.id = a.client_id WHERE c.phone = ?');
            $ids->execute([$tel]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $db->prepare('DELETE FROM finances WHERE appointment_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
            }
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([$tel]);
        }
        // Horarios y servicios de cada profesional se borran en cascada.
        foreach (array_unique($creado['usuarios']) as $id) {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar todos los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

echo "\033[1mBlue Therapy — pruebas de las agendas por profesional\033[0m\n";

// Un lunes lejano, para no chocar con la agenda real.
$lunes   = date('Y-m-d', strtotime('monday next week', strtotime('+120 days')));
$martes  = date('Y-m-d', strtotime($lunes . ' +1 day'));
$domingo = date('Y-m-d', strtotime($lunes . ' +6 days'));

// ══════════════════════════════════════════════════════════
seccion('1. Reglas de disponibilidad (sin base de datos)');
// ══════════════════════════════════════════════════════════
// Profesional 10: lunes 8:00–12:00 · Profesional 11: lunes 10:00–19:00.
$ctx = [
    'modo'     => 'agendas',
    'ahora'    => strtotime('2000-01-01'),
    'horarios' => [10 => [1 => [[480, 720]]], 11 => [1 => [[600, 1140]]]],
    'citas'    => [],
];
$h = fn(string $hora) => minutosDeHora($hora);

$r = evaluarHorario($ctx, $lunes, $h('08:00'), 60);
comprobar('A primera hora solo atiende quien ya empezó', $r['estado'] === 'libre' && $r['profesionales'] === [10]);
comprobar('Antes de que alguien trabaje el horario está fuera', evaluarHorario($ctx, $lunes, $h('07:00'), 60)['estado'] === 'fuera');

$r = evaluarHorario($ctx, $lunes, $h('11:30'), 60);
comprobar('Si la duración no cabe en un turno, se ofrece solo quien sí alcanza', $r['estado'] === 'libre' && $r['profesionales'] === [11]);
comprobar('Pasado el cierre no cabe la cita', evaluarHorario($ctx, $lunes, $h('18:30'), 60)['estado'] === 'no_cabe');
comprobar('Una profesional que no atiende ese día queda fuera', evaluarHorario($ctx, $martes, $h('10:00'), 60)['estado'] === 'fuera');

$ctx['citas'][$lunes] = [['id' => 1, 'staff_id' => 11, 'ini' => $h('10:00'), 'fin' => $h('11:00')]];
$r = evaluarHorario($ctx, $lunes, $h('10:00'), 60);
comprobar('Con una ocupada, «cualquiera» ofrece a la otra', $r['estado'] === 'libre' && $r['profesionales'] === [10]);
comprobar('Elegir a la ocupada muestra el horario ocupado', evaluarHorario($ctx, $lunes, $h('10:00'), 60, 11)['estado'] === 'ocupado');
comprobar('Elegir a la libre lo muestra libre', evaluarHorario($ctx, $lunes, $h('10:00'), 60, 10)['estado'] === 'libre');
comprobar('Una cita que se cruza a medias también ocupa', evaluarHorario($ctx, $lunes, $h('10:30'), 60, 11)['estado'] === 'ocupado');
comprobar('Excluir la propia cita la deja libre', evaluarHorario($ctx, $lunes, $h('10:00'), 60, 11, 1)['estado'] === 'libre');

$ctx['citas'][$lunes][] = ['id' => 2, 'staff_id' => null, 'ini' => $h('10:00'), 'fin' => $h('11:00')];
comprobar('Una cita sin profesional asignada consume un cupo', evaluarHorario($ctx, $lunes, $h('10:00'), 60)['estado'] === 'ocupado');
comprobar('…aunque se elija a la profesional libre', evaluarHorario($ctx, $lunes, $h('10:00'), 60, 10)['estado'] === 'ocupado');

comprobar('Una profesional que no hace el servicio queda fuera', evaluarHorario($ctx, $lunes, $h('12:00'), 60, 99)['estado'] === 'fuera');
comprobar('Un horario que ya pasó se marca pasado',
    evaluarHorario(['ahora' => strtotime($lunes . ' 12:00')] + $ctx, $lunes, $h('09:00'), 60)['estado'] === 'pasado');

$general = ['modo' => 'general', 'ahora' => strtotime('2000-01-01'), 'horarios' => [], 'citas' => []];
comprobar('Modo general: domingo cerrado', evaluarHorario($general, $domingo, $h('10:00'), 60)['estado'] === 'fuera');
comprobar('Modo general: a las 18:30 no cabe una hora', evaluarHorario($general, $lunes, $h('18:30'), 60)['estado'] === 'no_cabe');
comprobar('Modo general: un horario libre', evaluarHorario($general, $lunes, $h('10:00'), 60)['estado'] === 'libre');
$general['citas'][$lunes] = [['id' => 3, 'staff_id' => 7, 'ini' => $h('10:00'), 'fin' => $h('11:00')]];
comprobar('Modo general: un solo cupo para todo el centro', evaluarHorario($general, $lunes, $h('10:00'), 60)['estado'] === 'ocupado');

// ══════════════════════════════════════════════════════════
seccion('2. Horario y servicios de cada profesional');
// ══════════════════════════════════════════════════════════
$db = getDB();
$servicios = $db->query('SELECT id FROM services WHERE active = 1 AND is_assessment = 0 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($servicios) < 2) {
    omitir('Pruebas con base de datos', 'hacen falta al menos 2 servicios activos');
    goto resumen;
}
[$svcNormal, $svcRestringido] = array_map('intval', $servicios);
$profesionalesReales = count(profesionalesConHorario($db));

$staffA = usuarioDePrueba($db, 'Prueba Agenda A', 'staff');
$staffB = usuarioDePrueba($db, 'Prueba Agenda B', 'staff');
$staffD = usuarioDePrueba($db, 'Prueba Agenda sin horario', 'staff');
$adminC = usuarioDePrueba($db, 'Prueba Agenda Admin', 'admin');

$turno = fn(string $ini, string $fin) => ['ini' => $ini, 'fin' => $fin];
$diasLunASab = [];
for ($d = 1; $d <= 6; $d++) {
    $diasLunASab[$d] = ['activo' => '1', 'turnos' => [$turno('08:00', '12:00'), $turno('14:00', '18:00')]];
}

$r = guardarAgendaProfesional($db, $staffA, ['dias' => [1 => ['activo' => '1', 'turnos' => [$turno('12:00', '09:00')]]], 'servicios_modo' => 'todos']);
comprobar('Rechaza un turno que termina antes de empezar', !$r['ok'], $r['msg']);
$r = guardarAgendaProfesional($db, $staffA, ['dias' => [1 => ['activo' => '1', 'turnos' => [$turno('08:00', '12:00'), $turno('11:00', '15:00')]]], 'servicios_modo' => 'todos']);
comprobar('Rechaza turnos que se cruzan', !$r['ok'], $r['msg']);
$r = guardarAgendaProfesional($db, $staffA, ['dias' => $diasLunASab, 'servicios_modo' => 'algunos', 'servicios' => []]);
comprobar('Rechaza «solo algunos servicios» sin elegir ninguno', !$r['ok'], $r['msg']);
$r = guardarAgendaProfesional($db, $adminC, ['dias' => $diasLunASab, 'servicios_modo' => 'todos']);
comprobar('Un administrador no tiene agenda de reservas', !$r['ok'], $r['msg']);

$r = guardarAgendaProfesional($db, $staffA, ['dias' => $diasLunASab, 'servicios_modo' => 'todos']);
comprobar('Guarda un horario partido de lunes a sábado', $r['ok'], $r['msg']);
$semanaA = horarioSemanalDeProfesional($db, $staffA);
comprobar('El lunes quedó con sus dos turnos', count($semanaA[1] ?? []) === 2 && ($semanaA[1][1]['ini'] ?? '') === '14:00');
comprobar('El domingo quedó libre', empty($semanaA[0]));

$todosLosDias = [];
for ($d = 0; $d <= 6; $d++) $todosLosDias[$d] = ['activo' => '1', 'turnos' => [$turno('10:00', '19:00'), $turno('', '')]];
$r = guardarAgendaProfesional($db, $staffB, ['dias' => $todosLosDias, 'servicios_modo' => 'algunos', 'servicios' => [$svcRestringido]]);
comprobar('Guarda un horario de toda la semana con servicios limitados', $r['ok'], $r['msg']);
comprobar('La profesional B solo hace el servicio elegido', serviciosDeProfesional($db, $staffB) === [$svcRestringido]);

// Un administrador con horario cargado a mano igual no aparece en la reserva.
$db->prepare("INSERT INTO staff_schedules (staff_id, day_of_week, time_start, time_end) VALUES (?, 1, '08:00', '19:00')")->execute([$adminC]);

$conHorario = profesionalesConHorario($db);
comprobar('Con horarios cargados se activa el modo agendas', modoAgendasActivo($db));
comprobar('Aparecen las profesionales con horario', isset($conHorario[$staffA], $conHorario[$staffB]));
comprobar('No aparece quien no tiene horario', !isset($conHorario[$staffD]));
comprobar('No aparece el administrador', !isset($conHorario[$adminC]));

$paraNormal = profesionalesParaServicios($db, [$svcNormal]);
comprobar('Para un servicio que B no hace, solo queda A', isset($paraNormal[$staffA]) && !isset($paraNormal[$staffB]));
$paraRestringido = profesionalesParaServicios($db, [$svcRestringido]);
comprobar('Para el servicio de B aparecen las dos', isset($paraRestringido[$staffA], $paraRestringido[$staffB]));
comprobar('Pedir los dos servicios juntos deja solo a quien hace ambos',
    array_keys(array_intersect_key(profesionalesParaServicios($db, [$svcNormal, $svcRestringido]), [$staffA => 1, $staffB => 1])) === [$staffA]);

// ══════════════════════════════════════════════════════════
seccion('3. Disponibilidad de la semana y asignación');
// ══════════════════════════════════════════════════════════
$semana = disponibilidadSemana($db, $lunes, [$svcRestringido], $staffB);
comprobar('La semana se calcula en modo agendas', $semana['modo'] === 'agendas');
comprobar('Trae los 7 días', count($semana['dias']) === 7);
comprobar('Respeta a la profesional elegida', $semana['staff_id'] === $staffB);
comprobar('B atiende el domingo', $semana['dias'][$domingo]['abierto'] === true);
comprobar('A las 9:00 B todavía no trabaja', ($semana['dias'][$lunes]['horas']['09:00']['estado'] ?? '') === 'fuera');
comprobar('A las 10:00 B está libre', ($semana['dias'][$lunes]['horas']['10:00']['estado'] ?? '') === 'libre');
comprobar('La grilla cubre desde la primera hasta la última hora de trabajo',
    $semana['grilla']['desde'] === '08:00' && $semana['grilla']['hasta'] === '19:00');

$semana = disponibilidadSemana($db, $lunes, [$svcNormal], $staffB);
comprobar('Si la elegida no hace el servicio, vuelve a «cualquiera»', $semana['staff_id'] === 0);

$semana = disponibilidadSemana($db, $lunes, [$svcNormal], $staffA);
comprobar('A no atiende el domingo', $semana['dias'][$domingo]['abierto'] === false);
comprobar('A la hora del almuerzo A no atiende', ($semana['dias'][$lunes]['horas']['12:00']['estado'] ?? '') === 'fuera');

comprobar('Asignar a la profesional pedida', asignarProfesionalReserva($db, [$svcNormal], $lunes, '08:00', 60, $staffA) === $staffA);
try {
    asignarProfesionalReserva($db, [$svcNormal], $lunes, '10:00', 60, $staffB);
    comprobar('No asigna a quien no hace el servicio', false, 'la asignó');
} catch (RuntimeException $e) {
    comprobar('No asigna a quien no hace el servicio', str_contains($e->getMessage(), 'no realiza'), $e->getMessage());
}

// ══════════════════════════════════════════════════════════
seccion('4. Reservas en la base');
// ══════════════════════════════════════════════════════════
try {
    $cita1 = crearReservaPendiente($db, datosDeReserva([$svcNormal], $lunes, '08:00', $staffA));
    comprobar('La reserva queda asignada a la profesional elegida', staffDeCita($db, $cita1['appointment_id']) === $staffA);

    try {
        crearReservaPendiente($db, datosDeReserva([$svcNormal], $lunes, '08:00', $staffA));
        comprobar('No deja reservar dos veces a la misma profesional', false, 'se creó la segunda');
    } catch (RuntimeException $e) {
        comprobar('No deja reservar dos veces a la misma profesional', true, $e->getMessage());
    }

    if ($profesionalesReales === 0) {
        $cita2 = crearReservaPendiente($db, datosDeReserva([$svcRestringido], $lunes, '10:00', 0));
        comprobar('«Cualquiera» asigna a la que tiene menos citas ese día', staffDeCita($db, $cita2['appointment_id']) === $staffB);

        $cita3 = crearReservaPendiente($db, datosDeReserva([$svcRestringido], $lunes, '10:00', 0));
        comprobar('Otra reserva a la misma hora toma a la que queda libre', staffDeCita($db, $cita3['appointment_id']) === $staffA);

        try {
            crearReservaPendiente($db, datosDeReserva([$svcRestringido], $lunes, '10:00', 0));
            comprobar('Con todas ocupadas ya no hay cupo', false, 'se creó una tercera');
        } catch (RuntimeException $e) {
            comprobar('Con todas ocupadas ya no hay cupo', true, $e->getMessage());
        }

        // Una cita sin profesional (creada antes de las agendas) ocupa un cupo.
        $sinAsignar = crearReservaPendiente($db, datosDeReserva([$svcNormal], $lunes, '15:00', $staffA));
        $db->prepare('UPDATE appointments SET staff_id = NULL WHERE id = ?')->execute([$sinAsignar['appointment_id']]);
        try {
            crearReservaPendiente($db, datosDeReserva([$svcNormal], $lunes, '15:00', $staffA));
            comprobar('Una cita sin asignar consume el único cupo', false, 'se creó la reserva');
        } catch (RuntimeException $e) {
            comprobar('Una cita sin asignar consume el único cupo', true, $e->getMessage());
        }
    } else {
        omitir('«Cualquiera» y cupos compartidos', "hay {$profesionalesReales} profesional(es) real(es) con horario que cambiarían el reparto");
    }

    // ¿Una cita cancelada sigue cabiendo en su horario? (lo usa la confirmación del pago)
    $cancelada = crearReservaPendiente($db, datosDeReserva([$svcNormal], $martes, '09:00', $staffA));
    $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$cancelada['appointment_id']]);
    $filaCancelada = $db->query('SELECT * FROM appointments WHERE id = ' . (int)$cancelada['appointment_id'])->fetch();
    comprobar('Una cita cancelada con su horario libre todavía cabe', citaCabeEnSuHorario($db, $filaCancelada));
    crearReservaPendiente($db, datosDeReserva([$svcNormal], $martes, '09:00', $staffA));
    comprobar('Si otra reserva tomó el horario, ya no cabe', !citaCabeEnSuHorario($db, $filaCancelada));

    comprobar('La hora de fin la calcula el servidor',
        $cita1['time_end'] === date('H:i', strtotime('08:00') + duracionDeServicios($db, [$svcNormal]) * 60), $cita1['time_end']);
} catch (Throwable $e) {
    comprobar('Reservas en la base sin errores inesperados', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════
seccion('5. Endpoints por HTTP');
// ══════════════════════════════════════════════════════════
$base = urlPublicaBlue();
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) $base = rtrim(substr($arg, 7), '/');
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

[$codigo, $json] = http("{$base}/api/availability.php?week_start={$lunes}&services={$svcRestringido}&staff_id={$staffB}");
if ($codigo === 0) {
    omitir("Pruebas por HTTP contra {$base}", 'Apache no responde');
} else {
    $datos = json_decode($json, true) ?: [];
    comprobar('La disponibilidad responde en modo agendas', $codigo === 200 && ($datos['modo'] ?? '') === 'agendas', "HTTP {$codigo}");
    $nombres = array_column($datos['profesionales'] ?? [], 'nombre', 'id');
    comprobar('Lista a las profesionales que hacen el servicio', isset($nombres[$staffA], $nombres[$staffB]));
    // Nada de correo, teléfono ni rol: solo lo que la reserva necesita para dibujar a cada profesional.
    comprobar('Solo expone id, nombre, iniciales y color de cada profesional',
        array_keys($datos['profesionales'][0] ?? []) === ['id', 'nombre', 'iniciales', 'color', 'texto']);

    $cookies = tempnam(sys_get_temp_dir(), 'blue');
    [, $html] = http("{$base}/booking.php", ['cookies' => $cookies]);
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
    $cuerpo = json_encode(['services' => [$svcRestringido], 'date' => $martes, 'time_start' => '11:00', 'time_end' => '12:00',
        'name' => 'Prueba HTTP agenda', 'phone' => telefonoDePrueba(), 'email' => '', 'note' => '', 'whatsapp' => false,
        'staff_id' => $staffB]);
    [$codigo, $resp] = http("{$base}/api/book.php", ['cookies' => $cookies,
        'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($m[1] ?? '')], 'cuerpo' => $cuerpo]);
    $reserva = json_decode($resp, true) ?: [];

    if (($reserva['success'] ?? false) !== true && $codigo === 409 && str_contains($resp, 'abono')) {
        omitir('Reserva por HTTP con profesional', 'el abono es obligatorio: la reserva nace desde el pago');
    } else {
        comprobar('La reserva por HTTP queda con la profesional elegida',
            $codigo === 200 && !empty($reserva['appointment_id']) && staffDeCita($db, (int)$reserva['appointment_id']) === $staffB,
            "HTTP {$codigo}");
        comprobar('La respuesta trae el nombre de quien atiende', ($reserva['staff_nombre'] ?? '') === 'Prueba Agenda B');
    }
}

// ══════════════════════════════════════════════════════════
resumen:
seccion('Resumen');
// ══════════════════════════════════════════════════════════
printf("  %d correctas · %d fallas · %d omitidas\n", $resultado['ok'], $resultado['falla'], $resultado['omitida']);
exit($resultado['falla'] > 0 ? 1 : 0);
