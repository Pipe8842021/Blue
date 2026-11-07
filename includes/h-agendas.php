<?php
/**
 * h-agendas.php — Agendas por profesional (reserva en línea y panel).
 * Responsable: Felipe (módulo Agenda · Staff).
 *
 * Cada profesional (rol staff y activa) tiene su horario semanal en
 * `staff_schedules` y, si se quiere, la lista de servicios que realiza en
 * `staff_services` (sin filas = hace todos). La reserva en línea calcula la
 * disponibilidad de cada una: el cliente elige a una o «cualquiera disponible».
 * Los administradores nunca aparecen en la reserva en línea.
 *
 * Mientras ninguna profesional tenga horario, la reserva funciona en
 * «modo general», como antes de las agendas: un solo cupo por horario,
 * de lunes a sábado de 8:00 a 19:00.
 *
 * Estados de un horario (evaluarHorario):
 *   libre   · hay quien atienda y la cita completa cabe en su turno
 *   ocupado · alguien trabaja a esa hora, pero no queda cupo
 *   no_cabe · alguien trabaja a esa hora, pero la cita terminaría después de su turno
 *   fuera   · nadie (o la profesional elegida) atiende a esa hora
 *   pasado  · la hora ya pasó
 */

// Color de cada profesional (las chips de la reserva en línea).
require_once __DIR__ . '/h-colores.php';

// ── Modo general (sin agendas) ──────────────────────────────
const AGENDA_GENERAL_DIAS  = [1, 2, 3, 4, 5, 6];   // lunes a sábado (0 = domingo)
const AGENDA_GENERAL_DESDE = '08:00';
const AGENDA_GENERAL_HASTA = '19:00';

// Cada cuántos minutos se ofrece un horario en la reserva en línea.
const AGENDA_PASO_MIN = 60;

// Turnos por día que se pueden cargar en el panel (ej. mañana y tarde).
const AGENDA_TURNOS_POR_DIA = 2;

const AGENDA_NOMBRES_DIA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

/** "08:30" o "08:30:00" → 510 minutos desde la medianoche. */
function minutosDeHora(string $hora): int {
    $partes = array_map('intval', explode(':', $hora));
    return ($partes[0] ?? 0) * 60 + ($partes[1] ?? 0);
}

/** 510 → "08:30". */
function horaDeMinutos(int $minutos): string {
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

/** Ids enteros positivos, sin repetir. */
function idsLimpios(array $ids): array {
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
}

/** "Laura Gómez" → "LG". */
function inicialesDeNombre(string $nombre): string {
    $iniciales = '';
    foreach (array_slice(preg_split('/\s+/u', trim($nombre)) ?: [], 0, 2) as $palabra) {
        $iniciales .= mb_strtoupper(mb_substr($palabra, 0, 1));
    }
    return $iniciales;
}

// ══════════════════════════════════════════════════════════
//   PROFESIONALES Y HORARIOS
// ══════════════════════════════════════════════════════════

/**
 * Profesionales que aparecen en la reserva en línea: rol staff, activas y con
 * al menos un turno cargado.
 * @return array [id => ['id' => int, 'nombre' => string]]
 */
function profesionalesConHorario(PDO $db): array {
    $filas = $db->query(
        "SELECT DISTINCT u.id, u.name
           FROM users u
           JOIN staff_schedules ss ON ss.staff_id = u.id AND ss.active = 1
          WHERE u.active = 1 AND u.role = 'staff'
          ORDER BY u.name"
    )->fetchAll();

    $profesionales = [];
    foreach ($filas as $f) {
        $profesionales[(int)$f['id']] = ['id' => (int)$f['id'], 'nombre' => $f['name']];
    }
    return $profesionales;
}

/** ¿La reserva en línea usa agendas por profesional? Si no, funciona en modo general. */
function modoAgendasActivo(PDO $db): bool {
    return profesionalesConHorario($db) !== [];
}

/**
 * Profesionales con horario que realizan TODOS los servicios pedidos.
 * Quien no tiene servicios limitados en `staff_services` los hace todos.
 * @return array [id => ['id' => int, 'nombre' => string]]
 */
function profesionalesParaServicios(PDO $db, array $serviceIds): array {
    $profesionales = profesionalesConHorario($db);
    $servicios     = idsLimpios($serviceIds);
    if (!$profesionales || !$servicios) return $profesionales;

    $marcasSvc   = implode(',', array_fill(0, count($servicios), '?'));
    $marcasStaff = implode(',', array_fill(0, count($profesionales), '?'));
    $stmt = $db->prepare(
        "SELECT staff_id, SUM(service_id IN ($marcasSvc)) AS cubiertos
           FROM staff_services
          WHERE staff_id IN ($marcasStaff)
          GROUP BY staff_id"
    );
    $stmt->execute([...$servicios, ...array_keys($profesionales)]);

    foreach ($stmt->fetchAll() as $f) {
        if ((int)$f['cubiertos'] < count($servicios)) {
            unset($profesionales[(int)$f['staff_id']]);
        }
    }
    return $profesionales;
}

/**
 * Turnos activos de varias profesionales, en minutos.
 * @return array [staffId => [díaSemana => [[inicio, fin], ...]]]
 */
function horariosPorProfesional(PDO $db, array $staffIds): array {
    $ids = idsLimpios($staffIds);
    if (!$ids) return [];

    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT staff_id, day_of_week, time_start, time_end
           FROM staff_schedules
          WHERE active = 1 AND staff_id IN ($marcas)
          ORDER BY staff_id, day_of_week, time_start"
    );
    $stmt->execute($ids);

    $horarios = [];
    foreach ($stmt->fetchAll() as $f) {
        $horarios[(int)$f['staff_id']][(int)$f['day_of_week']][] = [
            minutosDeHora($f['time_start']),
            minutosDeHora($f['time_end']),
        ];
    }
    return $horarios;
}

/**
 * Duración de una cita con estos servicios: mínimo 30 min (60 si no hay servicios válidos).
 * Con $esOtroServicio (servicio fuera del catálogo) es el tiempo fijo de config/negocio.php.
 */
function duracionDeServicios(PDO $db, array $serviceIds, bool $esOtroServicio = false): int {
    if ($esOtroServicio) return duracionOtroServicio();

    $ids = idsLimpios($serviceIds);
    if (!$ids) return 60;

    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT COALESCE(SUM(duration_min), 60) FROM services WHERE id IN ($marcas) AND active = 1");
    $stmt->execute($ids);
    return max(30, (int)$stmt->fetchColumn());
}

/** Nombre de una profesional (vacío si no hay). */
function nombreProfesional(PDO $db, ?int $staffId): string {
    if (!$staffId) return '';
    $stmt = $db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    return (string)($stmt->fetchColumn() ?: '');
}

// ══════════════════════════════════════════════════════════
//   DISPONIBILIDAD
// ══════════════════════════════════════════════════════════

/**
 * Todo lo necesario para evaluar horarios entre dos fechas, con pocas consultas.
 * @return array modo · profesionales y horarios (solo de quienes hacen los servicios) · citas por fecha · ahora
 */
function contextoAgenda(PDO $db, string $desde, string $hasta, array $serviceIds): array {
    $modo          = modoAgendasActivo($db) ? 'agendas' : 'general';
    $profesionales = $modo === 'agendas' ? profesionalesParaServicios($db, $serviceIds) : [];

    $stmt = $db->prepare(
        "SELECT id, staff_id, DATE_FORMAT(date, '%Y-%m-%d') AS fecha, time_start, time_end
           FROM appointments
          WHERE date BETWEEN ? AND ? AND status IN ('pending', 'confirmed')"
    );
    $stmt->execute([$desde, $hasta]);

    $citas = [];
    foreach ($stmt->fetchAll() as $c) {
        $citas[$c['fecha']][] = [
            'id'       => (int)$c['id'],
            'staff_id' => $c['staff_id'] === null ? null : (int)$c['staff_id'],
            'ini'      => minutosDeHora($c['time_start']),
            'fin'      => minutosDeHora($c['time_end']),
        ];
    }

    return [
        'modo'          => $modo,
        'profesionales' => $profesionales,
        'horarios'      => horariosPorProfesional($db, array_keys($profesionales)),
        'citas'         => $citas,
        'ahora'         => time(),
    ];
}

/**
 * Estado de un horario concreto (ver los estados al inicio del archivo).
 *
 * @param array $ctx         contextoAgenda(), o uno armado a mano en las pruebas
 * @param int   $inicio      minutos desde la medianoche
 * @param int   $staffPedido 0 = «cualquiera disponible»
 * @param int   $excluirCita id de una cita que no cuenta como ocupación (la propia)
 * @return array ['estado' => string, 'profesionales' => int[] que pueden atender]
 */
function evaluarHorario(array $ctx, string $fecha, int $inicio, int $duracion, int $staffPedido = 0, int $excluirCita = 0): array {
    $fin       = $inicio + $duracion;
    $respuesta = fn(string $estado, array $profesionales = []) => ['estado' => $estado, 'profesionales' => $profesionales];

    if (strtotime($fecha . ' ' . horaDeMinutos($inicio)) <= $ctx['ahora']) {
        return $respuesta('pasado');
    }

    $cruzadas = array_filter(
        $ctx['citas'][$fecha] ?? [],
        fn($c) => $c['id'] !== $excluirCita && $c['ini'] < $fin && $c['fin'] > $inicio
    );
    $dia = (int)date('w', strtotime($fecha));

    if ($ctx['modo'] === 'general') {
        $abre   = minutosDeHora(AGENDA_GENERAL_DESDE);
        $cierra = minutosDeHora(AGENDA_GENERAL_HASTA);
        if (!in_array($dia, AGENDA_GENERAL_DIAS, true) || $inicio < $abre || $inicio >= $cierra) {
            return $respuesta('fuera');
        }
        if ($fin > $cierra) return $respuesta('no_cabe');
        return $respuesta($cruzadas ? 'ocupado' : 'libre');
    }

    // ¿Quién trabaja a esa hora y a quién le alcanza el turno para la cita completa?
    $trabajan = $caben = [];
    foreach ($ctx['horarios'] as $staffId => $porDia) {
        foreach ($porDia[$dia] ?? [] as [$desde, $hasta]) {
            if ($inicio >= $desde && $inicio < $hasta) $trabajan[$staffId] = true;
            if ($inicio >= $desde && $fin <= $hasta)   $caben[$staffId]    = true;
        }
    }

    $ocupadas   = [];
    $sinAsignar = 0;
    foreach ($cruzadas as $c) {
        if ($c['staff_id'] === null) $sinAsignar++;
        else $ocupadas[$c['staff_id']] = true;
    }
    $libres = array_values(array_filter(array_keys($caben), fn($id) => !isset($ocupadas[$id])));
    sort($libres);

    // Cada cita sin profesional asignada consume el cupo de alguna de las libres.
    $hayCupo = count($libres) > $sinAsignar;

    if ($staffPedido > 0) {
        if (!isset($trabajan[$staffPedido])) return $respuesta('fuera');
        if (!isset($caben[$staffPedido]))    return $respuesta('no_cabe');
        return in_array($staffPedido, $libres, true) && $hayCupo
            ? $respuesta('libre', [$staffPedido])
            : $respuesta('ocupado');
    }

    if ($hayCupo)   return $respuesta('libre', $libres);
    if (!$trabajan) return $respuesta('fuera');
    if (!$caben)    return $respuesta('no_cabe');
    return $respuesta('ocupado');
}

/**
 * Disponibilidad de una semana para la reserva en línea (la consume booking.js).
 *
 * @param string $lunes       primer día de la semana (Y-m-d)
 * @param int    $staffPedido 0 = «cualquiera»; si no hace los servicios, se vuelve a 0
 * @param bool   $esOtroServicio la cita es de «Otro servicio» (sin servicios del catálogo)
 */
function disponibilidadSemana(PDO $db, string $lunes, array $serviceIds, int $staffPedido, bool $esOtroServicio = false): array {
    $domingo  = date('Y-m-d', strtotime($lunes . ' +6 days'));
    $duracion = duracionDeServicios($db, $serviceIds, $esOtroServicio);
    $ctx      = contextoAgenda($db, $lunes, $domingo, $serviceIds);
    $colores  = coloresProfesionales($db);

    if ($ctx['modo'] !== 'agendas' || !isset($ctx['horarios'][$staffPedido])) {
        $staffPedido = 0;
    }

    // Grilla: de la primera hora de trabajo a la última de quienes pueden atender.
    $desde = minutosDeHora(AGENDA_GENERAL_DESDE);
    $hasta = minutosDeHora(AGENDA_GENERAL_HASTA);
    if ($ctx['modo'] === 'agendas' && $ctx['horarios']) {
        $inicios = $cierres = [];
        foreach ($ctx['horarios'] as $porDia) {
            foreach ($porDia as $turnos) {
                foreach ($turnos as [$ini, $fin]) {
                    $inicios[] = $ini;
                    $cierres[] = $fin;
                }
            }
        }
        $desde = intdiv(min($inicios), 60) * 60;
        $hasta = (int)ceil(max($cierres) / 60) * 60;
    }

    $dias = [];
    for ($i = 0; $i < 7; $i++) {
        $fecha = date('Y-m-d', strtotime($lunes . " +{$i} days"));
        $dia   = (int)date('w', strtotime($fecha));

        if ($ctx['modo'] === 'general') {
            $abierto = in_array($dia, AGENDA_GENERAL_DIAS, true);
        } elseif ($staffPedido > 0) {
            $abierto = !empty($ctx['horarios'][$staffPedido][$dia]);
        } else {
            $abierto = (bool)array_filter($ctx['horarios'], fn($porDia) => !empty($porDia[$dia]));
        }

        $horas = [];
        if ($abierto) {
            for ($t = $desde; $t < $hasta; $t += AGENDA_PASO_MIN) {
                $horas[horaDeMinutos($t)] = evaluarHorario($ctx, $fecha, $t, $duracion, $staffPedido);
            }
        }
        $dias[$fecha] = ['abierto' => $abierto, 'horas' => $horas];
    }

    return [
        'modo'          => $ctx['modo'],
        'grilla'        => ['desde' => horaDeMinutos($desde), 'hasta' => horaDeMinutos($hasta), 'paso' => AGENDA_PASO_MIN],
        'duracion'      => $duracion,
        'staff_id'      => $staffPedido,
        'profesionales' => array_map(
            fn($p) => [
                'id'        => $p['id'],
                'nombre'    => $p['nombre'],
                'iniciales' => inicialesDeNombre($p['nombre']),
                'color'     => colorDeProfesional($colores, $p['id']),
                'texto'     => colorTextoSobre(colorDeProfesional($colores, $p['id'])),
            ],
            array_values($ctx['profesionales'])
        ),
        'dias'          => $dias,
    ];
}

/**
 * Elige quién atiende una reserva nueva y confirma que el horario sigue libre.
 * Se llama dentro del candado por fecha de crearReservaPendiente().
 *
 * @return int|null id de la profesional; null en modo general (sin agendas)
 * @throws RuntimeException con el mensaje para el cliente si no hay cupo
 */
function asignarProfesionalReserva(PDO $db, array $serviceIds, string $fecha, string $horaInicio, int $duracion, int $staffPedido): ?int {
    $ctx = contextoAgenda($db, $fecha, $fecha, $serviceIds);

    if ($ctx['modo'] === 'agendas' && $staffPedido > 0 && !isset($ctx['horarios'][$staffPedido])) {
        throw new RuntimeException('La profesional que elegiste no realiza este servicio. Elige otra o «cualquiera disponible».');
    }

    $r = evaluarHorario($ctx, $fecha, minutosDeHora($horaInicio), $duracion, $staffPedido);
    if ($r['estado'] !== 'libre') {
        throw new RuntimeException('El horario seleccionado ya no está disponible. Por favor elige otro.');
    }

    if ($ctx['modo'] === 'general') return null;
    if ($staffPedido > 0)           return $staffPedido;

    // «Cualquiera»: la que tenga menos citas ese día, para repartir el trabajo.
    $citasDelDia = array_count_values(array_filter(array_column($ctx['citas'][$fecha] ?? [], 'staff_id')));
    $elegida = null;
    foreach ($r['profesionales'] as $staffId) {
        if ($elegida === null || ($citasDelDia[$staffId] ?? 0) < ($citasDelDia[$elegida] ?? 0)) {
            $elegida = $staffId;
        }
    }
    return $elegida;
}

/**
 * ¿Una cita (por ejemplo, cancelada porque venció su pago) todavía cabe en su horario?
 * La usa la confirmación de pagos para decidir si revive la cita.
 * @param array $cita fila de `appointments`
 */
function citaCabeEnSuHorario(PDO $db, array $cita): bool {
    $stmt = $db->prepare('SELECT service_id FROM appointment_services WHERE appointment_id = ?');
    $stmt->execute([(int)$cita['id']]);
    $servicios = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ctx    = contextoAgenda($db, $cita['date'], $cita['date'], $servicios);
    $inicio = minutosDeHora($cita['time_start']);

    return evaluarHorario(
        $ctx, $cita['date'], $inicio, minutosDeHora($cita['time_end']) - $inicio,
        (int)($cita['staff_id'] ?? 0), (int)$cita['id']
    )['estado'] === 'libre';
}

// ══════════════════════════════════════════════════════════
//   PANEL: HORARIO Y SERVICIOS DE CADA PROFESIONAL
// ══════════════════════════════════════════════════════════

/** Profesional con rol staff (activa o no); null si no existe o es administrador. */
function profesionalStaff(PDO $db, int $staffId): ?array {
    $stmt = $db->prepare("SELECT id, name, email, phone, active FROM users WHERE id = ? AND role = 'staff' LIMIT 1");
    $stmt->execute([$staffId]);
    return $stmt->fetch() ?: null;
}

/**
 * Horario semanal de una profesional, listo para el formulario.
 * @return array [díaSemana => [['ini' => '08:00', 'fin' => '12:00'], ...]]
 */
function horarioSemanalDeProfesional(PDO $db, int $staffId): array {
    $semana = [];
    foreach (horariosPorProfesional($db, [$staffId])[$staffId] ?? [] as $dia => $turnos) {
        foreach ($turnos as [$ini, $fin]) {
            $semana[$dia][] = ['ini' => horaDeMinutos($ini), 'fin' => horaDeMinutos($fin)];
        }
    }
    return $semana;
}

/** Servicios a los que está limitada una profesional (vacío = hace todos). */
function serviciosDeProfesional(PDO $db, int $staffId): array {
    $stmt = $db->prepare('SELECT service_id FROM staff_services WHERE staff_id = ? ORDER BY service_id');
    $stmt->execute([$staffId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Valida los turnos que llegan del formulario.
 * @return array ['error' => ?string, 'turnos' => [[díaSemana, 'HH:MM', 'HH:MM'], ...]]
 */
function validarTurnosSemana(array $dias): array {
    $formatoHora = '/^([01]\d|2[0-3]):[0-5]\d$/';
    $turnos = [];

    for ($dia = 0; $dia <= 6; $dia++) {
        $entrada = $dias[$dia] ?? [];
        if (empty($entrada['activo'])) continue;
        $nombreDia = AGENDA_NOMBRES_DIA[$dia];

        $delDia = [];
        foreach ((array)($entrada['turnos'] ?? []) as $t) {
            $ini = trim((string)($t['ini'] ?? ''));
            $fin = trim((string)($t['fin'] ?? ''));
            if ($ini === '' && $fin === '') continue;   // turno opcional sin llenar

            if (!preg_match($formatoHora, $ini) || !preg_match($formatoHora, $fin)) {
                return ['error' => "Revisa las horas del {$nombreDia}: usa el formato HH:MM.", 'turnos' => []];
            }
            if (minutosDeHora($fin) <= minutosDeHora($ini)) {
                return ['error' => "El turno del {$nombreDia} debe terminar después de empezar.", 'turnos' => []];
            }
            $delDia[] = [minutosDeHora($ini), minutosDeHora($fin)];
        }

        if (!$delDia) {
            return ['error' => "Marcaste el {$nombreDia} como día de trabajo, pero sin horas.", 'turnos' => []];
        }

        usort($delDia, fn($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($delDia); $i++) {
            if ($delDia[$i][0] < $delDia[$i - 1][1]) {
                return ['error' => "Los turnos del {$nombreDia} se cruzan entre sí.", 'turnos' => []];
            }
        }

        foreach ($delDia as [$ini, $fin]) {
            $turnos[] = [$dia, horaDeMinutos($ini), horaDeMinutos($fin)];
        }
    }
    return ['error' => null, 'turnos' => $turnos];
}

/**
 * Guarda el horario semanal y los servicios de una profesional (reemplaza lo anterior).
 * @param array $in dias[d][activo] · dias[d][turnos][n][ini|fin] · servicios_modo (todos|algunos) · servicios[]
 * @return array ['ok' => bool, 'msg' => string]
 */
function guardarAgendaProfesional(PDO $db, int $staffId, array $in): array {
    if (!profesionalStaff($db, $staffId)) {
        return ['ok' => false, 'msg' => 'Solo el personal con rol Staff tiene agenda de reservas.'];
    }

    $validacion = validarTurnosSemana((array)($in['dias'] ?? []));
    if ($validacion['error']) {
        return ['ok' => false, 'msg' => $validacion['error']];
    }

    $soloAlgunos = ($in['servicios_modo'] ?? 'todos') === 'algunos';
    $servicios   = $soloAlgunos ? idsLimpios((array)($in['servicios'] ?? [])) : [];
    if ($soloAlgunos && !$servicios) {
        return ['ok' => false, 'msg' => 'Elige al menos un servicio, o marca que realiza todos.'];
    }
    if ($servicios) {
        $marcas  = implode(',', array_fill(0, count($servicios), '?'));
        $existen = $db->prepare("SELECT COUNT(*) FROM services WHERE id IN ($marcas)");
        $existen->execute($servicios);
        if ((int)$existen->fetchColumn() !== count($servicios)) {
            return ['ok' => false, 'msg' => 'Uno o más servicios no existen.'];
        }
    }

    try {
        $db->beginTransaction();

        $db->prepare('DELETE FROM staff_schedules WHERE staff_id = ?')->execute([$staffId]);
        $insertarTurno = $db->prepare('INSERT INTO staff_schedules (staff_id, day_of_week, time_start, time_end) VALUES (?, ?, ?, ?)');
        foreach ($validacion['turnos'] as [$dia, $ini, $fin]) {
            $insertarTurno->execute([$staffId, $dia, $ini, $fin]);
        }

        $db->prepare('DELETE FROM staff_services WHERE staff_id = ?')->execute([$staffId]);
        $insertarServicio = $db->prepare('INSERT INTO staff_services (staff_id, service_id) VALUES (?, ?)');
        foreach ($servicios as $servicioId) {
            $insertarServicio->execute([$staffId, $servicioId]);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue] No se pudo guardar la agenda de la profesional ' . $staffId . ': ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo guardar la agenda. Intenta de nuevo.'];
    }

    return ['ok' => true, 'msg' => 'Agenda guardada.'];
}
