<?php
// ============================================================
// Blue Therapy — Pruebas de «Otro servicio» en la reserva en línea
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con Apache + MySQL encendidos:
//
//     php tests/otro_servicio.php
//
//   1. Validación de lo que escribe el cliente
//   2. Reglas: va solo y no se cobra en línea
//   3. Reserva en la base: duración, texto guardado y sin servicios
//   4. Cómo lo ven las demás vistas (serviciosDeCita) y la disponibilidad
//
// Crea citas de prueba y las borra al terminar.
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

$resultado = ['ok' => 0, 'falla' => 0, 'omitida' => 0];
$telefonos = [];

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
    global $telefonos;
    return $telefonos[] = '+57 397 ' . random_int(1000000, 9999999);
}

/** Cuerpo de reserva como lo manda el wizard; $extra pisa cualquier campo. */
function entradaDeReserva(array $extra = []): array {
    return array_merge([
        'services' => [], 'otro_servicio' => '', 'date' => date('Y-m-d', strtotime('+7 days')),
        'time_start' => '10:00', 'time_end' => '10:30', 'name' => 'Prueba Otro Servicio',
        'phone' => telefonoDePrueba(), 'email' => '', 'note' => '', 'whatsapp' => false, 'staff_id' => 0,
    ], $extra);
}

function limpiar(): void {
    global $telefonos;
    try {
        $db = getDB();
        foreach (array_unique($telefonos) as $tel) {
            $ids = $db->prepare('SELECT a.id FROM appointments a JOIN clients c ON c.id = a.client_id WHERE c.phone = ?');
            $ids->execute([$tel]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $db->prepare('DELETE FROM finances WHERE appointment_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
            }
            $db->prepare('DELETE FROM clients WHERE phone = ?')->execute([$tel]);
        }
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar todos los datos de prueba: " . $e->getMessage() . "\033[0m\n";
    }
}
register_shutdown_function('limpiar');

echo "\033[1mBlue Therapy — pruebas de «Otro servicio»\033[0m\n";

try {
    $db = getDB();
} catch (Throwable $e) {
    echo "\033[31mNo hay conexión con la base de datos: {$e->getMessage()}\033[0m\n";
    exit(1);
}

$tieneColumna = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'other_service'"
)->fetchColumn();
if (!$tieneColumna) {
    echo "\033[31mFalta la columna appointments.other_service: corre database/migrations/03_agenda.sql\033[0m\n";
    exit(1);
}

$servicioNormal = (int)$db->query(
    'SELECT id FROM services WHERE active = 1 AND is_assessment = 0 AND price > 0 ORDER BY id LIMIT 1'
)->fetchColumn();

// ── 1. Validación ─────────────────────────────────────────
seccion('1. Validación de lo que escribe el cliente');

$v = validarDatosReserva(entradaDeReserva());
comprobar('Sin servicios y sin texto se rechaza', count($v['errores']) > 0, implode(' ', $v['errores']));

$v = validarDatosReserva(entradaDeReserva(['otro_servicio' => 'Perfilado de cejas']));
comprobar('Solo «Otro servicio» con texto es válido', $v['errores'] === [], implode(' ', $v['errores']));

$v = validarDatosReserva(entradaDeReserva(['otro_servicio' => "  Perfilado \n\n de   cejas  "]));
comprobar('Espacios y saltos de línea se colapsan', $v['datos']['otro_servicio'] === 'Perfilado de cejas', $v['datos']['otro_servicio']);

$v = validarDatosReserva(entradaDeReserva(['otro_servicio' => '   ']));
comprobar('Un texto de solo espacios cuenta como vacío', count($v['errores']) > 0);

if ($servicioNormal) {
    $v = validarDatosReserva(entradaDeReserva(['services' => [$servicioNormal], 'otro_servicio' => 'Algo más']));
    comprobar('No se mezcla con servicios del catálogo', count($v['errores']) > 0, implode(' ', $v['errores']));
} else {
    omitir('No se mezcla con servicios del catálogo', 'no hay servicios activos con precio');
}

$v = validarDatosReserva(entradaDeReserva(['otro_servicio' => str_repeat('a', OTRO_SERVICIO_MAX_CARACTERES + 1)]));
comprobar('Más de ' . OTRO_SERVICIO_MAX_CARACTERES . ' caracteres se rechaza', count($v['errores']) > 0);

$v = validarDatosReserva(entradaDeReserva(['otro_servicio' => str_repeat('a', OTRO_SERVICIO_MAX_CARACTERES)]));
comprobar('Exactamente ' . OTRO_SERVICIO_MAX_CARACTERES . ' caracteres se acepta', $v['errores'] === []);

// ── 2. Reglas de cobro ────────────────────────────────────
seccion('2. Va solo y no se cobra en línea');

$otro = validarDatosReserva(entradaDeReserva(['otro_servicio' => 'Perfilado de cejas']))['datos'];
comprobar('«Otro servicio» es una reserva sin costo', reservaEsSinCosto($db, $otro));

if ($servicioNormal) {
    $normal = validarDatosReserva(entradaDeReserva(['services' => [$servicioNormal]]))['datos'];
    comprobar('Un servicio del catálogo con precio sí se cobra', !reservaEsSinCosto($db, $normal));

    try {
        crearReservaPendiente($db, array_merge($otro, ['services' => [$servicioNormal]]));
        comprobar('El servidor rechaza mezclarlo aunque salte la validación', false);
    } catch (RuntimeException $e) {
        comprobar('El servidor rechaza mezclarlo aunque salte la validación', true, $e->getMessage());
    }
}

comprobar('Su duración es la configurada en el negocio',
    duracionDeServicios($db, [], true) === duracionOtroServicio(), duracionOtroServicio() . ' min');

// ── 3. Reserva en la base ─────────────────────────────────
seccion('3. Reserva en la base');

// Primer horario libre de las próximas semanas para este servicio.
$fecha = $hora = null;
for ($semana = 1; $semana <= 4 && !$fecha; $semana++) {
    $lunes = date('Y-m-d', strtotime("monday +{$semana} week"));
    foreach (disponibilidadSemana($db, $lunes, [], 0, true)['dias'] as $dia => $info) {
        foreach ($info['horas'] as $h => $estado) {
            if ($estado['estado'] === 'libre') { $fecha = $dia; $hora = $h; break 2; }
        }
    }
}

if (!$fecha) {
    omitir('Crear la reserva', 'no hay horarios libres en las próximas 4 semanas');
} else {
    $datos = validarDatosReserva(entradaDeReserva([
        'otro_servicio' => 'Perfilado de cejas', 'date' => $fecha, 'time_start' => $hora,
    ]))['datos'];
    $reserva = crearReservaPendiente($db, $datos);
    $citaId  = $reserva['appointment_id'];

    $fila = $db->prepare('SELECT other_service, total_duration, time_start, time_end, status FROM appointments WHERE id = ?');
    $fila->execute([$citaId]);
    $cita = $fila->fetch();

    comprobar('El texto queda guardado en la cita', $cita['other_service'] === 'Perfilado de cejas', (string)$cita['other_service']);
    comprobar('La cita queda pendiente', $cita['status'] === 'pending');
    comprobar('Ocupa la duración configurada', (int)$cita['total_duration'] === duracionOtroServicio(), $cita['total_duration'] . ' min');
    comprobar('La hora de fin es inicio + duración',
        substr($cita['time_end'], 0, 5) === date('H:i', strtotime($hora) + duracionOtroServicio() * 60), $cita['time_end']);
    comprobar('El total es cero', (float)$reserva['total_price'] === 0.0);

    $n = $db->prepare('SELECT COUNT(*) FROM appointment_services WHERE appointment_id = ?');
    $n->execute([$citaId]);
    comprobar('No tiene servicios del catálogo asociados', (int)$n->fetchColumn() === 0);

    // ── 4. Cómo lo ven las demás vistas ──────────────────
    seccion('4. Vistas y disponibilidad');

    $servicios = serviciosDeCita($db, $citaId);
    comprobar('serviciosDeCita lo devuelve como una fila marcada', count($servicios) === 1 && !empty($servicios[0]['otro']));
    comprobar('La fila lleva el texto del cliente', str_contains($servicios[0]['name'] ?? '', 'Perfilado de cejas'), $servicios[0]['name'] ?? '');
    comprobar('La fila no suma al total', array_sum(array_column($servicios, 'price')) == 0);

    $lunesDeLaCita = date('Y-m-d', strtotime($fecha . ' monday this week'));
    $semana = disponibilidadSemana($db, $lunesDeLaCita, [], 0, true);
    comprobar('El horario reservado deja de estar libre', ($semana['dias'][$fecha]['horas'][$hora]['estado'] ?? '') !== 'libre',
        $semana['dias'][$fecha]['horas'][$hora]['estado'] ?? '?');
    comprobar('La disponibilidad usa la duración de «Otro servicio»', $semana['duracion'] === duracionOtroServicio(), $semana['duracion'] . ' min');

    $json = @file_get_contents('http://localhost/Blue/api/availability.php?week_start=' . $lunesDeLaCita . '&services=&staff_id=0&otro=1');
    $api  = $json ? json_decode($json, true) : null;
    if ($api) {
        comprobar('El endpoint de disponibilidad acepta otro=1', ($api['duracion'] ?? 0) === duracionOtroServicio());
    } else {
        omitir('El endpoint de disponibilidad acepta otro=1', 'Apache no responde');
    }

    // ── 5. Panel: calendario, listados y formulario de cita ──
    seccion('5. Panel del equipo');
    require_once __DIR__ . '/../includes/h-agenda.php';

    [$anio, $mes] = array_map('intval', explode('-', substr($fecha, 0, 7)));
    $delMes = citasDelMes($db, null, $anio, $mes)[$fecha] ?? [];
    $enCalendario = array_values(array_filter($delMes, fn($c) => (int)$c['id'] === $citaId))[0] ?? null;
    comprobar('El calendario muestra el «Otro servicio»',
        $enCalendario && str_contains((string)$enCalendario['services'], 'Otro servicio: Perfilado de cejas'),
        (string)($enCalendario['services'] ?? 'no apareció'));

    $listado = $db->prepare('SELECT ' . sqlServiciosDeCita() . ' AS services FROM appointments a
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id WHERE a.id = ? GROUP BY a.id');
    $listado->execute([$citaId]);
    comprobar('Los listados muestran el texto sin servicios del catálogo',
        $listado->fetchColumn() === 'Otro servicio: Perfilado de cejas');

    $clienteId = (int)$db->query("SELECT client_id FROM appointments WHERE id = {$citaId}")->fetchColumn();
    $formulario = ['id' => $citaId, 'client_id' => $clienteId, 'date' => $fecha, 'time_start' => $hora,
                   'status' => 'pending', 'other_service' => 'Perfilado y tinte de cejas'];
    $r = guardarCita($db, $formulario, null, 1);
    $nuevo = $db->query("SELECT other_service, total_duration FROM appointments WHERE id = {$citaId}")->fetch();
    comprobar('guardarCita edita el texto de una cita de «Otro servicio»',
        $r['ok'] && $nuevo['other_service'] === 'Perfilado y tinte de cejas', $r['msg']);

    $r = guardarCita($db, array_merge($formulario, ['other_service' => '']), null, 1);
    comprobar('guardarCita rechaza una cita sin servicio ni texto', !$r['ok'], $r['msg']);

    if ($servicioNormal) {
        $r = guardarCita($db, array_merge($formulario, ['services' => [$servicioNormal]]), null, 1);
        comprobar('guardarCita rechaza mezclar catálogo y «Otro servicio»', !$r['ok'], $r['msg']);

        // El equipo «cotiza» la cita: la pasa a un servicio del catálogo y el texto desaparece.
        $r = guardarCita($db, array_merge($formulario, ['services' => [$servicioNormal], 'other_service' => '']), null, 1);
        $cotizada = $db->query("SELECT other_service FROM appointments WHERE id = {$citaId}")->fetchColumn();
        comprobar('Al cotizarla con un servicio del catálogo se limpia el texto', $r['ok'] && $cotizada === null, $r['msg']);
    }
}

echo "\n" . str_repeat('═', 60) . "\n";
printf("Resultado: \033[32m%d correctas\033[0m · \033[31m%d fallas\033[0m · \033[33m%d omitidas\033[0m\n",
    $resultado['ok'], $resultado['falla'], $resultado['omitida']);
exit($resultado['falla'] > 0 ? 1 : 0);
