<?php
/**
 * h-notificaciones.php — Avisos internos para el equipo (campana del panel).
 * Responsable: Felipe.
 *
 * Una fila en `notifications` por aviso y por persona. Algunos tipos además
 * llegan por WhatsApp a quien tenga celular en su usuario (config/whatsapp.php
 * → avisos_equipo): cancelaciones y novedades.
 *
 * Tipos:
 *   reserva_nueva  · un cliente reservó en línea
 *   pago_aprobado  · Wompi aprobó el abono de una reserva
 *   cita_cancelada · el cliente o el equipo canceló una cita
 *   novedad        · aviso general publicado por un administrador
 *
 * Avisar nunca debe tumbar la acción que lo provoca: las funciones que se
 * llaman después de reservar, pagar o cancelar atrapan sus propios errores.
 */

require_once __DIR__ . '/h-whatsapp.php';
require_once __DIR__ . '/h-reservas.php';   // citaConDetalle(), serviciosDeCita()

/**
 * Crea el aviso para cada persona indicada.
 * @return int cuántos avisos se crearon
 */
function notificar(PDO $db, array $userIds, string $tipo, string $titulo, string $cuerpo = '', ?string $url = null, ?int $citaId = null, ?int $pedidoId = null): int {
    $insertar = $db->prepare(
        'INSERT INTO notifications (user_id, type, title, body, url, appointment_id, order_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $creados = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if ($userId <= 0) continue;
        $insertar->execute([
            $userId, $tipo, mb_substr($titulo, 0, 150), mb_substr($cuerpo, 0, 500) ?: null, $url, $citaId, $pedidoId,
        ]);
        $creados++;
    }
    return $creados;
}

/**
 * A quién le interesa lo que pasa con una cita: los administradores activos y
 * la profesional asignada. Quien hizo la acción no se avisa a sí misma.
 * @return array [id => ['id' => int, 'name' => string, 'phone' => ?string]]
 */
function destinatariosDeCita(PDO $db, ?int $staffId, ?int $excluirUsuario = null): array {
    $stmt = $db->prepare("SELECT id, name, phone FROM users WHERE active = 1 AND (role = 'admin' OR id = ?)");
    $stmt->execute([(int)$staffId]);

    $destinatarios = [];
    foreach ($stmt->fetchAll() as $u) {
        if ((int)$u['id'] === (int)$excluirUsuario) continue;
        $destinatarios[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone']];
    }
    return $destinatarios;
}

/**
 * Aviso en la campana y, si el tipo lo tiene activo, también por WhatsApp.
 * @param array $destinatarios salida de destinatariosDeCita() (o del mismo formato)
 */
function avisarAlEquipo(PDO $db, array $destinatarios, string $tipo, string $titulo, string $cuerpo,
                        ?string $url, ?int $citaId, ?string $plantillaWhatsapp = null, array $variablesWhatsapp = []): void {
    try {
        notificar($db, array_keys($destinatarios), $tipo, $titulo, $cuerpo, $url, $citaId);

        $porWhatsapp = in_array($tipo, (array)(configWhatsApp()['avisos_equipo'] ?? []), true);
        if (!$porWhatsapp || $plantillaWhatsapp === null) return;

        foreach ($destinatarios as $persona) {
            if (!empty($persona['phone'])) {
                enviarWhatsAppPlantilla((string)$persona['phone'], $plantillaWhatsapp, $variablesWhatsapp);
            }
        }
    } catch (Throwable $e) {
        error_log('[Blue/Avisos] No se pudo avisar al equipo (' . $tipo . '): ' . $e->getMessage());
    }
}

/**
 * Avisa al equipo de una reserva nueva o de un abono aprobado.
 * @param string $tipo 'reserva_nueva' o 'pago_aprobado'
 */
function avisarReservaAlEquipo(PDO $db, int $citaId, string $tipo = 'reserva_nueva'): void {
    try {
        $cita = citaConDetalle($db, $citaId);
        if (!$cita) return;

        $servicios = implode(', ', array_column(serviciosDeCita($db, $citaId), 'name'));
        $cuerpo    = $cita['cliente_nombre'] . ' · ' . formatDate($cita['date']) . ', ' . formatTime($cita['time_start'])
                   . ($servicios ? ' · ' . $servicios : '')
                   . ($cita['staff_nombre'] ? ' · con ' . $cita['staff_nombre'] : '');
        $titulo    = $tipo === 'pago_aprobado' ? 'Abono aprobado: cita confirmada' : 'Nueva reserva en línea';

        avisarAlEquipo(
            $db, destinatariosDeCita($db, $cita['staff_id'] ? (int)$cita['staff_id'] : null),
            $tipo, $titulo, $cuerpo, '/Blue/cita.php?id=' . $citaId, $citaId
        );
    } catch (Throwable $e) {
        error_log('[Blue/Avisos] No se pudo avisar la reserva ' . $citaId . ': ' . $e->getMessage());
    }
}

/**
 * Novedad para todo el equipo (ej. «mañana abrimos a las 10»), publicada por un administrador.
 * @return array ['ok' => bool, 'msg' => string]
 */
function publicarNovedad(PDO $db, int $actorId, string $titulo, string $mensaje): array {
    $titulo  = trim($titulo);
    $mensaje = trim($mensaje);
    if ($titulo === '') {
        return ['ok' => false, 'msg' => 'Escribe un título para la novedad.'];
    }
    if (mb_strlen($titulo) > 150 || mb_strlen($mensaje) > 500) {
        return ['ok' => false, 'msg' => 'La novedad es muy larga: máximo 150 caracteres de título y 500 de mensaje.'];
    }

    $stmt = $db->prepare('SELECT id, name, phone FROM users WHERE active = 1 AND id <> ?');
    $stmt->execute([$actorId]);
    $equipo = [];
    foreach ($stmt->fetchAll() as $u) {
        $equipo[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone']];
    }
    if (!$equipo) {
        return ['ok' => false, 'msg' => 'No hay más personas activas en el equipo para avisar.'];
    }

    avisarAlEquipo($db, $equipo, 'novedad', $titulo, $mensaje, null, null, 'novedad_equipo', [$titulo, $mensaje ?: $titulo]);
    return ['ok' => true, 'msg' => 'Novedad enviada a ' . count($equipo) . ' persona(s) del equipo.'];
}

// ── Campana del panel ───────────────────────────────────────

/** Avisos más recientes de una persona (leídos y sin leer). */
function notificacionesDeUsuario(PDO $db, int $userId, int $limite = 15): array {
    $stmt = $db->prepare(
        'SELECT id, type, title, body, url, appointment_id, read_at, created_at
           FROM notifications
          WHERE user_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT ' . max(1, min(50, $limite))
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function contarNotificacionesNoLeidas(PDO $db, int $userId): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/** Marca un aviso como leído, solo si es de esa persona. */
function marcarNotificacionLeida(PDO $db, int $id, int $userId): bool {
    $stmt = $db->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
    $stmt->execute([$id, $userId]);
    return $stmt->rowCount() === 1;
}

/** @return int cuántos avisos se marcaron */
function marcarTodasLeidas(PDO $db, int $userId): int {
    $stmt = $db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/** "hace 5 min", "hace 3 h", "ayer", "12 sep". */
function haceCuantoNotificacion(string $fechaHora): string {
    $segundos = time() - strtotime($fechaHora);
    if ($segundos < 60)     return 'justo ahora';
    if ($segundos < 3600)   return 'hace ' . intdiv($segundos, 60) . ' min';
    if ($segundos < 86400)  return 'hace ' . intdiv($segundos, 3600) . ' h';
    if ($segundos < 172800) return 'ayer';
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', strtotime($fechaHora)) . ' ' . $meses[(int)date('n', strtotime($fechaHora)) - 1];
}
