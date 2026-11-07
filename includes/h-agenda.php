<?php
/**
 * h-agenda.php — Helpers del módulo Agenda (Citas · Dashboard · Staff)
 * Responsable: Felipe.
 * Funciones con nombre descriptivo de su tarea. No editar el functions.php compartido.
 */

// Cancelar desde «Editar cita» aplica la misma política del abono y los mismos avisos.
require_once __DIR__ . '/h-cancelaciones.php';
// Color de cada profesional en calendarios, listados y asignación.
require_once __DIR__ . '/h-colores.php';

/**
 * Devuelve el HTML del badge de estado de una cita.
 */
function badgeEstadoCita(string $status): string {
    $map = [
        'pending'   => ['Pendiente',  'pending'],
        'confirmed' => ['Confirmada', 'confirmed'],
        'completed' => ['Completada', 'completed'],
        'cancelled' => ['Cancelada',  'cancelled'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'pending'];
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}

/**
 * Registra el ingreso de una cita completada (una sola vez por cita).
 * Suma el precio de los servicios de la cita y crea el movimiento en finanzas.
 * Devuelve true si registró un nuevo ingreso, false si ya existía o no había monto.
 */
function registrarIngresoCita(PDO $db, int $appointmentId, int $userId): bool {
    $existe = $db->prepare("SELECT COUNT(*) FROM finances WHERE appointment_id = ? AND type = 'income'");
    $existe->execute([$appointmentId]);
    if ($existe->fetchColumn()) {
        return false;
    }

    $t = $db->prepare("
        SELECT COALESCE(SUM(sv.price), 0) AS total, a.date
        FROM appointments a
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id
        WHERE a.id = ?");
    $t->execute([$appointmentId]);
    $row = $t->fetch();
    if (!$row || (float)$row['total'] <= 0) {
        return false;
    }

    $db->prepare("INSERT INTO finances (type, category, description, amount, date, appointment_id, registered_by)
                  VALUES ('income', 'Servicios', CONCAT('Cita #', ?), ?, ?, ?, ?)")
       ->execute([$appointmentId, $row['total'], $row['date'], $appointmentId, $userId]);
    return true;
}

/**
 * Saludo según la hora del día.
 */
function saludoSegunHora(): string {
    $h = (int)date('G');
    if ($h < 12) return 'Buenos días';
    if ($h < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

/* ── Listas auxiliares para los formularios de cita ── */
function obtenerClientes(PDO $db): array {
    return $db->query("SELECT id, name, phone FROM clients ORDER BY name")->fetchAll();
}
function obtenerServiciosActivos(PDO $db): array {
    return $db->query("SELECT id, name, duration_min, price FROM services WHERE active = 1 ORDER BY name")->fetchAll();
}
function obtenerStaffActivo(PDO $db): array {
    return $db->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll();
}

/**
 * Expresión SQL con los servicios de una cita en un solo texto: los del
 * catálogo y, si la cita es de «Otro servicio», lo que escribió el cliente.
 * Para consultas con `appointments a`, `services sv` y GROUP BY a.id.
 */
function sqlServiciosDeCita(): string {
    return "CONCAT_WS(', ',
                GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', '),
                CASE WHEN a.other_service IS NULL THEN NULL ELSE CONCAT('Otro servicio: ', a.other_service) END)";
}

/**
 * Crea o actualiza una cita (con sus servicios). Calcula duración y hora de fin.
 * $forcedStaffId: si se pasa, fuerza ese profesional (panel staff). Si es null, usa el del formulario (admin).
 * $actorId: id del usuario que realiza la acción (para registrar ingreso si queda 'completed').
 * Devuelve ['ok'=>bool, 'msg'=>string].
 */
function guardarCita(PDO $db, array $in, ?int $forcedStaffId, int $actorId): array {
    $id = (int)($in['id'] ?? 0);

    // Un profesional (panel staff) solo puede editar sus propias citas.
    if ($id > 0 && $forcedStaffId !== null) {
        $own = $db->prepare("SELECT COUNT(*) FROM appointments WHERE id=? AND staff_id=?");
        $own->execute([$id, $forcedStaffId]);
        if (!$own->fetchColumn()) {
            return ['ok' => false, 'msg' => 'No tienes permiso para editar esta cita.'];
        }
    }

    $services = array_values(array_unique(array_map('intval', (array)($in['services'] ?? []))));
    $services = array_filter($services, fn($v) => $v > 0);

    // «Otro servicio»: texto libre cuando el servicio no está en el catálogo. Va solo.
    $otroServicio = trim((string)preg_replace('/\s+/u', ' ', (string)($in['other_service'] ?? '')));
    if (mb_strlen($otroServicio) > OTRO_SERVICIO_MAX_CARACTERES) {
        return ['ok' => false, 'msg' => 'Describe el «Otro servicio» en máximo ' . OTRO_SERVICIO_MAX_CARACTERES . ' caracteres.'];
    }
    if (!empty($services) && $otroServicio !== '')     return ['ok' => false, 'msg' => 'Elige servicios del catálogo o describe un «Otro servicio», no ambos.'];
    if (empty($services) && $otroServicio === '')      return ['ok' => false, 'msg' => 'Selecciona al menos un servicio o describe el «Otro servicio».'];
    $date = $in['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))   return ['ok' => false, 'msg' => 'La fecha no es válida.'];
    $timeStart = $in['time_start'] ?? '';
    if (!preg_match('/^\d{2}:\d{2}/', $timeStart))     return ['ok' => false, 'msg' => 'La hora no es válida.'];

    // Duración total y regla de la valoración, a partir de los servicios.
    // Va antes de crear el cliente: si la cita se rechaza, no queda nada a medias.
    if ($otroServicio !== '') {
        $svc = ['dur' => duracionOtroServicio(), 'valoraciones' => 0];
        $dur = duracionOtroServicio();
    } else {
        $ph  = implode(',', array_fill(0, count($services), '?'));
        $q   = $db->prepare("SELECT COALESCE(SUM(duration_min),0) AS dur, COALESCE(SUM(is_assessment),0) AS valoraciones
                             FROM services WHERE id IN ($ph)");
        $q->execute(array_values($services));
        $svc = $q->fetch();
        $dur = max(15, (int)$svc['dur']);
    }

    // La valoración va sola: primero se valora al cliente y después se agenda el procedimiento.
    if ((int)$svc['valoraciones'] > 0 && count($services) > 1) {
        return ['ok' => false, 'msg' => 'La cita de valoración se agenda sola: quita los demás servicios.'];
    }

    // Cliente: existente o nuevo
    $clientId = (int)($in['client_id'] ?? 0);
    if ($clientId === 0) {
        $nm = trim($in['new_client_name'] ?? '');
        $ph = trim($in['new_client_phone'] ?? '');
        if ($nm === '' || $ph === '') return ['ok' => false, 'msg' => 'Elige un cliente o ingresa nombre y teléfono.'];
        $db->prepare("INSERT INTO clients (name, phone) VALUES (?, ?)")->execute([$nm, $ph]);
        $clientId = (int)$db->lastInsertId();
    }

    // Profesional
    $staffId = $forcedStaffId !== null ? $forcedStaffId : ((int)($in['staff_id'] ?? 0) ?: null);

    // Estado
    $status = in_array($in['status'] ?? '', ['pending','confirmed','completed','cancelled'], true) ? $in['status'] : 'pending';



    $timeStart = substr($timeStart, 0, 5) . ':00';
    $timeEnd   = date('H:i:s', strtotime($timeStart) + $dur * 60);
    $notes     = trim($in['notes'] ?? '') ?: null;

    try {
        $db->beginTransaction();
        $estadoAnterior = null;
        if ($id > 0) {
            $anterior = $db->prepare('SELECT status FROM appointments WHERE id = ?');
            $anterior->execute([$id]);
            $estadoAnterior = $anterior->fetchColumn() ?: null;

            $db->prepare("UPDATE appointments SET client_id=?, staff_id=?, date=?, time_start=?, time_end=?, total_duration=?, status=?, notes=?, other_service=? WHERE id=?")
               ->execute([$clientId, $staffId, $date, $timeStart, $timeEnd, $dur, $status, $notes, $otroServicio ?: null, $id]);
            $db->prepare("DELETE FROM appointment_services WHERE appointment_id=?")->execute([$id]);
        } else {
            $db->prepare("INSERT INTO appointments (client_id, staff_id, date, time_start, time_end, total_duration, status, notes, other_service) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$clientId, $staffId, $date, $timeStart, $timeEnd, $dur, $status, $notes, $otroServicio ?: null]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)");
        foreach ($services as $sid) $ins->execute([$id, $sid]);

        if ($status === 'completed') registrarIngresoCita($db, $id, $actorId);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => 'No se pudo guardar la cita.'];
    }

    // Una cita que pasa a «Cancelada» desde el formulario se registra como cancelación del equipo.
    if ($status === 'cancelled' && $estadoAnterior !== null && $estadoAnterior !== 'cancelled') {
        registrarCancelacionDesdeEdicion($db, $id, $actorId);
    }
    return ['ok' => true, 'msg' => 'Cita guardada correctamente.'];
}

/**
 * Elimina una cita. Si $staffId se pasa, solo elimina si le pertenece a ese profesional.
 */
function eliminarCita(PDO $db, int $id, ?int $staffId = null): bool {
    if ($staffId !== null) {
        $stmt = $db->prepare("DELETE FROM appointments WHERE id=? AND staff_id=?");
        return $stmt->execute([$id, $staffId]) && $stmt->rowCount() > 0;
    }
    return $db->prepare("DELETE FROM appointments WHERE id=?")->execute([$id]);
}

/**
 * Devuelve las citas de un mes agrupadas por día (para el calendario).
 * $staffId null = todas (admin); con valor = solo las de ese profesional.
 * Retorna [ 'YYYY-MM-DD' => [ {id,time_start,status,client_name,services}, ... ] ].
 */
function citasDelMes(PDO $db, ?int $staffId, int $year, int $month): array {
    $desde = sprintf('%04d-%02d-01', $year, $month);
    $hasta = date('Y-m-t', strtotime($desde));
    $cond  = $staffId !== null ? 'AND a.staff_id = ?' : '';
    $args  = [$desde, $hasta];
    if ($staffId !== null) $args[] = $staffId;

    $sql = "
        SELECT a.id, a.date, a.time_start, a.status, a.staff_id,
               c.name AS client_name, u.name AS staff_name,
               " . sqlServiciosDeCita() . " AS services
        FROM appointments a
        JOIN clients c ON a.client_id = c.id
        LEFT JOIN users u ON a.staff_id = u.id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id
        WHERE a.date BETWEEN ? AND ? $cond
        GROUP BY a.id
        ORDER BY a.time_start";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);

    $porDia = [];
    foreach ($stmt->fetchAll() as $row) {
        $porDia[$row['date']][] = $row;
    }
    return $porDia;
}
