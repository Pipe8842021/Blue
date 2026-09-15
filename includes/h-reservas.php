<?php
// ============================================================
// Blue Therapy — Reservas del sitio público (módulo Reservas)
// ------------------------------------------------------------
// Lógica compartida por los dos caminos de agendamiento:
//   · api/book.php        → solicitud sin pago (el equipo confirma)
//   · api/pago_iniciar.php → reserva con abono en línea (Wompi)
// Así la validación y el bloqueo del horario viven en un solo sitio.
// ============================================================

/**
 * Normaliza y valida lo que llega del navegador.
 *
 * @return array ['errores' => string[], 'datos' => array]
 */
function validarDatosReserva(array $in): array {
    $d = [
        'services'   => array_values(array_unique(array_map('intval', $in['services'] ?? []))),
        'date'       => trim((string)($in['date']       ?? '')),
        'time_start' => trim((string)($in['time_start'] ?? '')),
        'time_end'   => trim((string)($in['time_end']   ?? '')),
        'name'       => trim((string)($in['name']       ?? '')),
        'phone'      => trim((string)($in['phone']      ?? '')),
        'email'      => trim((string)($in['email']      ?? '')),
        'note'       => trim((string)($in['note']       ?? '')),
        'whatsapp'   => !empty($in['whatsapp']),
        'email_reminder' => !empty($in['email_reminder']),
    ];

    // Se valida el formato y se sale ANTES de tocar DateTime: un valor que no
    // matchee el patrón hace que `new DateTime()` lance una excepción no
    // controlada (crash 500).
    $errores = [];
    if (empty($d['services']))                                                $errores[] = 'Debes seleccionar al menos un servicio.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date']))                      $errores[] = 'Fecha inválida.';
    if (!preg_match('/^\d{2}:\d{2}$/', $d['time_start']))                      $errores[] = 'Hora de inicio inválida.';
    if (!preg_match('/^\d{2}:\d{2}$/', $d['time_end']))                        $errores[] = 'Hora de fin inválida.';
    if ($d['name'] === '')                                                     $errores[] = 'El nombre es requerido.';
    if ($d['phone'] === '')                                                    $errores[] = 'El teléfono es requerido.';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es válido.';

    // Sin correo no hay a dónde avisar, sin importar lo que haya marcado el checkbox.
    if ($d['email'] === '') $d['email_reminder'] = false;

    if (!$errores && new DateTime($d['date'] . ' ' . $d['time_start']) <= new DateTime()) {
        $errores[] = 'No puedes reservar en una fecha u hora pasada.';
    }

    return ['errores' => $errores, 'datos' => $d];
}

/**
 * Crea la cita en estado 'pending' junto con su cliente y sus servicios.
 *
 * El horario queda bloqueado apenas termina la transacción, así que este es
 * también el mecanismo que "aparta" el cupo mientras el cliente paga.
 *
 * @throws RuntimeException  errores que sí se le muestran al cliente (409/422)
 * @return array ['appointment_id','client_id','total_min','total_price']
 */
function crearReservaPendiente(PDO $db, array $d): array {
    // ── Mutex por fecha ────────────────────────────────────────
    // Evita que dos reservas simultáneas para el mismo día pasen ambas la
    // verificación de disponibilidad antes de que la primera haga su INSERT
    // (condición de carrera → doble-reserva del mismo horario).
    $lockName = 'blue_booking_' . $d['date'];
    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('El sistema está ocupado procesando otra reserva para esa fecha. Intenta de nuevo en unos segundos.');
    }

    try {
        $db->beginTransaction();

        // ── Verificar servicios y derivar duración/precio desde la BD ──
        // Ninguno de los dos se acepta del cliente: se calculan aquí para
        // que nadie pueda manipular ni la agenda ni el monto a pagar.
        $marcadores = implode(',', array_fill(0, count($d['services']), '?'));
        $svcCheck = $db->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(duration_min), 60) AS total_min,
                    COALESCE(SUM(price), 0)         AS total_price
               FROM services WHERE id IN ($marcadores) AND active = 1"
        );
        $svcCheck->execute($d['services']);
        $svcRow = $svcCheck->fetch();

        if ((int)$svcRow['cnt'] !== count($d['services'])) {
            throw new RuntimeException('Uno o más servicios no son válidos.');
        }
        $totalMin   = max(30, (int)$svcRow['total_min']);
        $totalPrice = (float)$svcRow['total_price'];

        // ── Verificar que el slot sigue libre ────────────────────
        $conflicto = $db->prepare(
            "SELECT COUNT(*) FROM appointments
              WHERE date = ?
                AND status IN ('pending','confirmed')
                AND time_start < ?
                AND time_end   > ?"
        );
        $conflicto->execute([$d['date'], $d['time_end'] . ':00', $d['time_start'] . ':00']);
        if ((int)$conflicto->fetchColumn() > 0) {
            throw new RuntimeException('El horario seleccionado ya no está disponible. Por favor elige otro.');
        }

        // ── Crear o encontrar cliente (por teléfono) ─────────────
        $buscarCliente = $db->prepare('SELECT id FROM clients WHERE phone = ? LIMIT 1');
        $buscarCliente->execute([$d['phone']]);
        $clientId = $buscarCliente->fetchColumn();

        if (!$clientId) {
            $db->prepare('INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)')
               ->execute([$d['name'], $d['email'] ?: null, $d['phone']]);
            $clientId = (int)$db->lastInsertId();
        } else {
            $db->prepare('UPDATE clients SET name = ?, email = COALESCE(?, email) WHERE id = ?')
               ->execute([$d['name'], $d['email'] ?: null, $clientId]);
        }

        // ── Crear la cita ─────────────────────────────────────────
        $db->prepare(
            "INSERT INTO appointments
                (client_id, date, time_start, time_end, total_duration, status, whatsapp_reminder, email_reminder, notes)
             VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
        )->execute([
            $clientId,
            $d['date'],
            $d['time_start'] . ':00',
            $d['time_end']   . ':00',
            $totalMin,
            $d['whatsapp'] ? 1 : 0,
            $d['email_reminder'] ? 1 : 0,
            $d['note'] ?: null,
        ]);
        $apptId = (int)$db->lastInsertId();

        // ── Registrar servicios de la cita ────────────────────────
        $insertarSvc = $db->prepare(
            'INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)'
        );
        foreach ($d['services'] as $svcId) {
            $insertarSvc->execute([$apptId, $svcId]);
        }

        $db->commit();

        return [
            'appointment_id' => $apptId,
            'client_id'      => (int)$clientId,
            'total_min'      => $totalMin,
            'total_price'    => $totalPrice,
        ];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

/**
 * Cancela una cita recién creada cuando el paso siguiente falla
 * (por ejemplo si no se pudo abrir el pago). Libera el cupo enseguida.
 */
function anularReservaPendiente(PDO $db, int $apptId): void {
    try {
        $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND status = 'pending'")
           ->execute([$apptId]);
    } catch (Exception $e) {
        error_log('[Blue] No se pudo anular la reserva ' . $apptId . ': ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════
//   ESTADO DE LA CITA — enlace privado y datos completos
// ══════════════════════════════════════════════════════════

/**
 * URL pública del sitio, sin barra final.
 * Se arma con el dominio por el que entró la petición, que es el que el
 * cliente tiene en pantalla. Solo cuando no hay petición (línea de comandos)
 * se recurre a la url_base de config/wompi.php.
 */
function urlPublicaBlue(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    // El Host lo controla quien hace la petición: solo se acepta si parece un host real.
    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($esHttps ? 'https://' : 'http://') . $host . '/Blue';
    }

    if (function_exists('urlBaseSitio')) {
        $configurada = urlBaseSitio();
        if ($configurada !== '') return $configurada;
    }
    return 'http://localhost/Blue';
}

/**
 * Token del enlace con el que el cliente consulta su cita.
 * Se crea la primera vez que se pide y queda guardado en `appointment_links`.
 */
function tokenEnlaceCita(PDO $db, int $citaId): string {
    $stmt = $db->prepare('SELECT token FROM appointment_links WHERE appointment_id = ? LIMIT 1');
    $stmt->execute([$citaId]);
    $token = $stmt->fetchColumn();
    if ($token) return (string)$token;

    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO appointment_links (appointment_id, token) VALUES (?, ?)')
       ->execute([$citaId, $token]);
    return $token;
}

/** Enlace completo que se le comparte al cliente (WhatsApp, correo…). */
function urlEstadoCita(PDO $db, int $citaId): string {
    return urlPublicaBlue() . '/cita.php?id=' . $citaId . '&t=' . tokenEnlaceCita($db, $citaId);
}

/** ¿El token de la URL corresponde a esta cita? Comparación en tiempo constante. */
function tokenCitaEsValido(PDO $db, int $citaId, string $token): bool {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return false;
    $stmt = $db->prepare('SELECT token FROM appointment_links WHERE appointment_id = ? LIMIT 1');
    $stmt->execute([$citaId]);
    $guardado = $stmt->fetchColumn();
    return $guardado !== false && hash_equals((string)$guardado, $token);
}

/** Cita con su cliente y su profesional asignado. */
function citaConDetalle(PDO $db, int $citaId): ?array {
    $stmt = $db->prepare(
        'SELECT a.*,
                c.name  AS cliente_nombre, c.phone AS cliente_telefono, c.email AS cliente_correo,
                u.name  AS staff_nombre,   u.phone AS staff_telefono
           FROM appointments a
           JOIN clients c ON c.id = a.client_id
           LEFT JOIN users u ON u.id = a.staff_id
          WHERE a.id = ? LIMIT 1'
    );
    $stmt->execute([$citaId]);
    return $stmt->fetch() ?: null;
}

/** Servicios de la cita, con su precio y duración. */
function serviciosDeCita(PDO $db, int $citaId): array {
    $stmt = $db->prepare(
        'SELECT s.id, s.name, s.price, s.duration_min
           FROM appointment_services aps
           JOIN services s ON s.id = aps.service_id
          WHERE aps.appointment_id = ?
          ORDER BY s.name'
    );
    $stmt->execute([$citaId]);
    return $stmt->fetchAll();
}

/**
 * Pasos por los que avanza una cita, para dibujar la línea de tiempo.
 * Una cita cancelada no tiene línea: se muestra aparte.
 */
function pasosEstadoCita(string $estado): array {
    $orden = ['pending' => 0, 'confirmed' => 1, 'completed' => 2];
    $actual = $orden[$estado] ?? 0;
    $pasos  = [
        ['clave' => 'pending',   'label' => 'Solicitada', 'desc' => 'Recibimos tu reserva'],
        ['clave' => 'confirmed', 'label' => 'Confirmada', 'desc' => 'Tu horario está separado'],
        ['clave' => 'completed', 'label' => 'Completada', 'desc' => 'Servicio realizado'],
    ];
    foreach (array_keys($pasos) as $i) {
        $pasos[$i]['hecho']  = $i <  $actual;
        $pasos[$i]['activo'] = $i === $actual;
    }
    return $pasos;
}
