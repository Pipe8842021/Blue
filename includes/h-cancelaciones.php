<?php
/**
 * h-cancelaciones.php — Cancelación de citas y devolución del abono.
 * Responsable: Felipe.
 *
 * Política (config/negocio.php → horas_minimas_cancelacion, 24 h por defecto):
 *   · El cliente cancela desde su enlace hasta esas horas antes de la cita.
 *     Después solo puede cancelar el equipo.
 *   · Cancelación a tiempo → el abono pagado queda «por devolver». El equipo lo
 *     devuelve en el panel de Wompi y lo registra aquí (egreso en Finanzas).
 *   · Cancelación tardía → el centro conserva el abono. Un administrador puede
 *     decidir devolverlo igual (por ejemplo, si canceló el centro).
 *
 * Todas las cancelaciones hechas por personas pasan por cancelarCita() o
 * registrarCancelacionDesdeEdicion(), para que la regla del abono y los
 * avisos (equipo por campana y WhatsApp, cliente por correo y WhatsApp) sean
 * siempre los mismos.
 */

require_once __DIR__ . '/h-negocio.php';
require_once __DIR__ . '/h-notificaciones.php';
require_once __DIR__ . '/h-correo.php';   // avisarPorCorreoSiCorresponde()

/** Horas que faltan para la cita (negativo si ya pasó). */
function horasParaCita(array $cita, ?int $ahora = null): float {
    $inicio = strtotime($cita['date'] . ' ' . $cita['time_start']);
    return ($inicio - ($ahora ?? time())) / 3600;
}

/**
 * ¿El cliente puede cancelar esta cita desde su enlace?
 * @param array $cita fila de `appointments`
 * @return array ['puede' => bool, 'motivo' => string para mostrarle si no puede]
 */
function cancelacionClienteDisponible(PDO $db, array $cita): array {
    $no     = fn(string $motivo) => ['puede' => false, 'motivo' => $motivo];
    $minimo = horasMinimasCancelacion();

    if (!in_array($cita['status'], ['pending', 'confirmed'], true)) {
        return $no('Esta cita ya no se puede cancelar.');
    }
    $horas = horasParaCita($cita);
    if ($horas <= 0) {
        return $no('La hora de la cita ya pasó.');
    }
    if ($horas < $minimo) {
        return $no("Faltan menos de {$minimo} horas para tu cita. Para cancelarla escríbenos por WhatsApp.");
    }

    // Si el cliente está pagando en este momento, cancelar dejaría el pago sin cita.
    $enProceso = $db->prepare("SELECT COUNT(*) FROM payments WHERE appointment_id = ? AND status = 'pending'");
    $enProceso->execute([(int)$cita['id']]);
    if ((int)$enProceso->fetchColumn() > 0) {
        return $no('Tu pago todavía está en proceso. Cuando se confirme podrás cancelar desde aquí.');
    }

    return ['puede' => true, 'motivo' => ''];
}

/**
 * Cancela una cita aplicando la política del abono y avisa a quien corresponda.
 *
 * @param string $origen 'cliente' (desde su enlace) o 'equipo' (panel)
 * @return array ['ok' => bool, 'msg' => string, 'abono' => sin_abono|por_devolver|se_conserva]
 */
function cancelarCita(PDO $db, int $citaId, string $origen, ?int $actorId, string $motivo = ''): array {
    $origen = $origen === 'cliente' ? 'cliente' : 'equipo';
    $motivo = mb_substr(trim($motivo), 0, 255);
    $error  = fn(string $msg) => ['ok' => false, 'msg' => $msg, 'abono' => 'sin_abono'];

    try {
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
        $stmt->execute([$citaId]);
        $cita = $stmt->fetch();

        if (!$cita) {
            $db->rollBack();
            return $error('La cita no existe.');
        }
        if ($origen === 'cliente') {
            $permiso = cancelacionClienteDisponible($db, $cita);
            if (!$permiso['puede']) {
                $db->rollBack();
                return $error($permiso['motivo']);
            }
        } elseif (!in_array($cita['status'], ['pending', 'confirmed'], true)) {
            $db->rollBack();
            return $error('Esta cita ya estaba cancelada o completada.');
        }

        $db->prepare(
            "UPDATE appointments
                SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
              WHERE id = ?"
        )->execute([$origen, $motivo ?: null, $citaId]);

        $abono = aplicarPoliticaAbono($db, $citaId, horasParaCita($cita) >= horasMinimasCancelacion());
        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue] No se pudo cancelar la cita ' . $citaId . ': ' . $e->getMessage());
        return $error('No se pudo cancelar la cita. Intenta de nuevo.');
    }

    trasCancelarCita($db, $citaId, $origen, $actorId, $abono);

    $mensajes = [
        'sin_abono'    => 'La cita fue cancelada.',
        'por_devolver' => 'La cita fue cancelada. El abono quedó por devolver.',
        'se_conserva'  => 'La cita fue cancelada. Como fue con menos de ' . horasMinimasCancelacion()
                        . ' horas de anticipación, el abono no se devuelve.',
    ];
    return ['ok' => true, 'msg' => $mensajes[$abono], 'abono' => $abono];
}

/**
 * Regla del abono al cancelar: a tiempo queda por devolver; tarde, se conserva.
 * @return string sin_abono · por_devolver · se_conserva
 */
function aplicarPoliticaAbono(PDO $db, int $citaId, bool $aTiempo): string {
    $abonos = $db->prepare("SELECT COUNT(*) FROM payments WHERE appointment_id = ? AND kind = 'deposit' AND status = 'approved'");
    $abonos->execute([$citaId]);
    if ((int)$abonos->fetchColumn() === 0) return 'sin_abono';
    if (!$aTiempo) return 'se_conserva';

    $db->prepare(
        "UPDATE payments SET refund_status = 'pending', refund_requested_at = NOW()
          WHERE appointment_id = ? AND kind = 'deposit' AND status = 'approved' AND refund_status = 'none'"
    )->execute([$citaId]);
    return 'por_devolver';
}

/**
 * Para cuando la cita se deja «Cancelada» desde el formulario de edición del panel
 * (guardarCita ya cambió el estado): registra la cancelación y aplica la regla.
 */
function registrarCancelacionDesdeEdicion(PDO $db, int $citaId, ?int $actorId): void {
    try {
        $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ?');
        $stmt->execute([$citaId]);
        $cita = $stmt->fetch();
        if (!$cita || $cita['status'] !== 'cancelled') return;

        $db->prepare("UPDATE appointments SET cancelled_at = NOW(), cancelled_by = 'equipo' WHERE id = ?")->execute([$citaId]);
        $abono = aplicarPoliticaAbono($db, $citaId, horasParaCita($cita) >= horasMinimasCancelacion());
        trasCancelarCita($db, $citaId, 'equipo', $actorId, $abono);
    } catch (Throwable $e) {
        error_log('[Blue] No se pudo registrar la cancelación de la cita ' . $citaId . ': ' . $e->getMessage());
    }
}

/** Avisos después de cancelar: equipo (campana + WhatsApp) y cliente (correo + WhatsApp). */
function trasCancelarCita(PDO $db, int $citaId, string $origen, ?int $actorId, string $abono): void {
    try {
        $cita = citaConDetalle($db, $citaId);
        if (!$cita) return;

        $fecha = formatDate($cita['date']);
        $hora  = formatTime($cita['time_start']);
        $quien = $origen === 'cliente' ? 'el cliente' : (nombreProfesional($db, $actorId) ?: 'el equipo');

        $cuerpo = "{$cita['cliente_nombre']} · {$fecha}, {$hora}. Canceló {$quien}."
                . ($cita['cancel_reason'] ? " Motivo: {$cita['cancel_reason']}." : '')
                . ['sin_abono' => '', 'por_devolver' => ' Hay un abono por devolver.',
                   'se_conserva' => ' Cancelación tardía: el abono no se devuelve.'][$abono];

        avisarAlEquipo(
            $db,
            destinatariosDeCita($db, $cita['staff_id'] ? (int)$cita['staff_id'] : null, $actorId),
            'cita_cancelada', 'Cita cancelada', $cuerpo, '/Blue/cita.php?id=' . $citaId, $citaId,
            'cita_cancelada_equipo', [$cita['cliente_nombre'], $fecha, $hora, $quien]
        );

        avisarPorCorreoSiCorresponde($db, $citaId, 'cancelada');
        if ((int)$cita['whatsapp_reminder'] === 1 && !empty($cita['cliente_telefono'])) {
            enviarWhatsAppPlantilla($cita['cliente_telefono'], 'cita_cancelada_cliente', [$cita['cliente_nombre'], $fecha, $hora]);
        }
    } catch (Throwable $e) {
        error_log('[Blue] No se pudo avisar la cancelación de la cita ' . $citaId . ': ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════
//   DEVOLUCIÓN DEL ABONO
// ══════════════════════════════════════════════════════════

/** Abono aprobado de una cita, con el estado de la cita. null si no existe. */
function abonoAprobadoDeCita(PDO $db, int $pagoId, int $citaId): ?array {
    $stmt = $db->prepare(
        "SELECT p.*, a.status AS cita_estado
           FROM payments p
           JOIN appointments a ON a.id = p.appointment_id
          WHERE p.id = ? AND p.appointment_id = ? AND p.kind = 'deposit' AND p.status = 'approved'
          LIMIT 1"
    );
    $stmt->execute([$pagoId, $citaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Decisión de un administrador: devolver el abono de una cita cancelada tarde
 * (por ejemplo, si el que canceló fue el centro).
 * @return array ['ok' => bool, 'msg' => string]
 */
function marcarAbonoPorDevolver(PDO $db, int $pagoId, int $citaId): array {
    $abono = abonoAprobadoDeCita($db, $pagoId, $citaId);
    if (!$abono) {
        return ['ok' => false, 'msg' => 'Ese abono no se puede devolver.'];
    }
    if ($abono['cita_estado'] !== 'cancelled') {
        return ['ok' => false, 'msg' => 'Solo se devuelven abonos de citas canceladas.'];
    }
    if ($abono['refund_status'] !== 'none') {
        return ['ok' => false, 'msg' => 'Ese abono ya está marcado para devolución.'];
    }

    $db->prepare(
        "UPDATE payments SET refund_status = 'pending', refund_requested_at = NOW() WHERE id = ? AND refund_status = 'none'"
    )->execute([$pagoId]);
    return ['ok' => true, 'msg' => 'El abono quedó por devolver.'];
}

/**
 * Registra que el abono ya se le devolvió al cliente (la devolución se hace en
 * el panel de Wompi). Crea el egreso en Finanzas.
 * @return array ['ok' => bool, 'msg' => string]
 */
function registrarDevolucionAbono(PDO $db, int $pagoId, int $citaId, int $actorId): array {
    $abono = abonoAprobadoDeCita($db, $pagoId, $citaId);
    if (!$abono || $abono['refund_status'] !== 'pending') {
        return ['ok' => false, 'msg' => 'Este abono no está pendiente de devolución.'];
    }

    try {
        $db->beginTransaction();

        $marcar = $db->prepare(
            "UPDATE payments SET refund_status = 'done', refunded_at = NOW(), refunded_by = ?
              WHERE id = ? AND refund_status = 'pending'"
        );
        $marcar->execute([$actorId, $pagoId]);
        if ($marcar->rowCount() !== 1) {
            $db->rollBack();
            return ['ok' => false, 'msg' => 'Este abono no está pendiente de devolución.'];
        }

        $db->prepare(
            "INSERT INTO finances (type, category, description, amount, date, appointment_id, registered_by)
             VALUES ('expense', 'Devolución de abono', ?, ?, CURDATE(), ?, ?)"
        )->execute(['Devolución de abono · cita #' . $citaId . ' · ' . $abono['reference'], $abono['amount'], $citaId, $actorId]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue] No se pudo registrar la devolución del pago ' . $pagoId . ': ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo registrar la devolución. Intenta de nuevo.'];
    }

    return ['ok' => true, 'msg' => 'Devolución de ' . formatPrice((float)$abono['amount']) . ' registrada.'];
}

/** Etiqueta del estado de devolución de un abono. */
function etiquetaDevolucion(string $estado): string {
    return ['pending' => 'Por devolver', 'done' => 'Devuelto'][$estado] ?? '';
}
