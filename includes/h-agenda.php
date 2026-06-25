<?php
/**
 * h-agenda.php — Helpers del módulo Agenda (Citas · Dashboard · Staff)
 * Responsable: Felipe.
 * Funciones con nombre descriptivo de su tarea. No editar el functions.php compartido.
 */

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
 * Crea o actualiza una cita (con sus servicios). Calcula duración y hora de fin.
 * $forcedStaffId: si se pasa, fuerza ese profesional (panel staff). Si es null, usa el del formulario (admin).
 * $actorId: id del usuario que realiza la acción (para registrar ingreso si queda 'completed').
 * Devuelve ['ok'=>bool, 'msg'=>string].
 */
function guardarCita(PDO $db, array $in, ?int $forcedStaffId, int $actorId): array {
    $id       = (int)($in['id'] ?? 0);
    $services = array_values(array_unique(array_map('intval', (array)($in['services'] ?? []))));
    $services = array_filter($services, fn($v) => $v > 0);

    if (empty($services))                              return ['ok' => false, 'msg' => 'Selecciona al menos un servicio.'];
    $date = $in['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))   return ['ok' => false, 'msg' => 'La fecha no es válida.'];
    $timeStart = $in['time_start'] ?? '';
    if (!preg_match('/^\d{2}:\d{2}/', $timeStart))     return ['ok' => false, 'msg' => 'La hora no es válida.'];

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

    // Duración total a partir de los servicios
    $ph  = implode(',', array_fill(0, count($services), '?'));
    $q   = $db->prepare("SELECT COALESCE(SUM(duration_min),0) FROM services WHERE id IN ($ph)");
    $q->execute(array_values($services));
    $dur = max(15, (int)$q->fetchColumn());

    $timeStart = substr($timeStart, 0, 5) . ':00';
    $timeEnd   = date('H:i:s', strtotime($timeStart) + $dur * 60);
    $notes     = trim($in['notes'] ?? '') ?: null;

    try {
        $db->beginTransaction();
        if ($id > 0) {
            $db->prepare("UPDATE appointments SET client_id=?, staff_id=?, date=?, time_start=?, time_end=?, total_duration=?, status=?, notes=? WHERE id=?")
               ->execute([$clientId, $staffId, $date, $timeStart, $timeEnd, $dur, $status, $notes, $id]);
            $db->prepare("DELETE FROM appointment_services WHERE appointment_id=?")->execute([$id]);
        } else {
            $db->prepare("INSERT INTO appointments (client_id, staff_id, date, time_start, time_end, total_duration, status, notes) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$clientId, $staffId, $date, $timeStart, $timeEnd, $dur, $status, $notes]);
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
        SELECT a.id, a.date, a.time_start, a.status,
               c.name AS client_name,
               GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services
        FROM appointments a
        JOIN clients c ON a.client_id = c.id
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
