<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

// ── CSRF verification ─────────────────────────────────────
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida. Recarga la página e intenta de nuevo.']);
    exit;
}

// ── Leer y validar input ──────────────────────────────────
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);

if (!$in) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos.']);
    exit;
}

$services  = array_map('intval', $in['services']   ?? []);
$date      = trim($in['date']       ?? '');
$timeStart = trim($in['time_start'] ?? '');
$timeEnd   = trim($in['time_end']   ?? '');
$name      = trim($in['name']       ?? '');
$phone     = trim($in['phone']      ?? '');
$email     = trim($in['email']      ?? '');
$note      = trim($in['note']       ?? '');
$whatsapp  = !empty($in['whatsapp']);

// ── Validaciones básicas ──────────────────────────────────
$errors = [];
if (empty($services))                                            $errors[] = 'Debes seleccionar al menos un servicio.';
if (!$date      || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))     $errors[] = 'Fecha inválida.';
if (!$timeStart || !preg_match('/^\d{2}:\d{2}$/', $timeStart))       $errors[] = 'Hora de inicio inválida.';
if (!$timeEnd   || !preg_match('/^\d{2}:\d{2}$/', $timeEnd))         $errors[] = 'Hora de fin inválida.';
if (!$name)                                                      $errors[] = 'El nombre es requerido.';
if (!$phone)                                                     $errors[] = 'El teléfono es requerido.';

$slotDT = new DateTime($date . ' ' . $timeStart);
if ($slotDT <= new DateTime()) {
    $errors[] = 'No puedes reservar en una fecha u hora pasada.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

try {
    $db = getDB();
    $db->beginTransaction();

    // ── Verificar servicios y derivar total_min desde la BD ──
    // total_min no se acepta del cliente; se calcula aquí para evitar manipulación.
    $placeholders = implode(',', array_fill(0, count($services), '?'));
    $svcCheck = $db->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(duration_min), 60) AS total_min
         FROM services WHERE id IN ($placeholders) AND active = 1"
    );
    $svcCheck->execute($services);
    $svcRow = $svcCheck->fetch();

    if ((int)$svcRow['cnt'] !== count($services)) {
        throw new RuntimeException('Uno o más servicios no son válidos.');
    }
    $totalMin = max(30, (int)$svcRow['total_min']);

    // ── Verificar que el slot sigue libre ────────────────────
    $conflict = $db->prepare(
        "SELECT COUNT(*) FROM appointments
         WHERE date = ?
           AND status IN ('pending','confirmed')
           AND time_start < ?
           AND time_end   > ?"
    );
    $conflict->execute([$date, $timeEnd . ':00', $timeStart . ':00']);
    if ((int)$conflict->fetchColumn() > 0) {
        throw new RuntimeException('El horario seleccionado ya no está disponible. Por favor elige otro.');
    }

    // ── Crear o encontrar cliente (por teléfono) ─────────────
    $findClient = $db->prepare('SELECT id FROM clients WHERE phone = ? LIMIT 1');
    $findClient->execute([$phone]);
    $clientId = $findClient->fetchColumn();

    if (!$clientId) {
        $db->prepare('INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)')
           ->execute([$name, $email ?: null, $phone]);
        $clientId = (int)$db->lastInsertId();
    } else {
        $db->prepare('UPDATE clients SET name = ?, email = COALESCE(?, email) WHERE id = ?')
           ->execute([$name, $email ?: null, $clientId]);
    }

    // ── Crear la cita ─────────────────────────────────────────
    $db->prepare(
        "INSERT INTO appointments
            (client_id, date, time_start, time_end, total_duration, status, whatsapp_reminder, notes)
         VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)"
    )->execute([
        $clientId,
        $date,
        $timeStart . ':00',
        $timeEnd   . ':00',
        $totalMin,
        $whatsapp ? 1 : 0,
        $note ?: null,
    ]);
    $apptId = (int)$db->lastInsertId();

    // ── Registrar servicios de la cita ────────────────────────
    $insertSvc = $db->prepare(
        'INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)'
    );
    foreach ($services as $svcId) {
        $insertSvc->execute([$apptId, $svcId]);
    }

    $db->commit();

    echo json_encode(['success' => true, 'appointment_id' => $apptId]);

} catch (RuntimeException $e) {
    $db->rollBack();
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Error interno al guardar la cita. Intenta de nuevo.']);
}
