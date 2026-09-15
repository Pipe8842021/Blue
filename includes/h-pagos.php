<?php
// ============================================================
// Blue Therapy — Pagos en línea con Wompi  (módulo Reservas/Finanzas)
// ------------------------------------------------------------
// Flujo completo:
//   1. El cliente arma su cita en booking.php y pide pagar el abono.
//   2. api/pago_iniciar.php crea la cita en estado 'pending' y una fila
//      en `payments`: el horario le queda RESERVADO unos minutos.
//   3. El cliente paga en el checkout de Wompi y vuelve a
//      pago_resultado.php; en paralelo Wompi avisa a api/wompi_webhook.php.
//   4. Cualquiera de los dos caminos llama a aplicarResultadoPago():
//      si el pago quedó aprobado, la cita pasa a 'confirmed' sola y el
//      abono se registra como ingreso en Finanzas.
//   5. Si el cliente nunca paga, liberarReservasVencidas() cancela la
//      cita y devuelve el cupo a la agenda.
// ============================================================

// Trae también h-reservas.php (citaConDetalle, urlEstadoCita, etc.): las
// funciones de este archivo avisan por correo cuando cambia el estado.
require_once __DIR__ . '/h-correo.php';

// ── Estados de Wompi traducidos a los nuestros ──────────────
const ESTADOS_WOMPI = [
    'APPROVED' => 'approved',
    'DECLINED' => 'declined',
    'VOIDED'   => 'voided',
    'ERROR'    => 'error',
    'PENDING'  => 'pending',
];

/** Configuración de la pasarela (config/wompi.php, cacheada). */
function configPagos(): array {
    static $config = null;
    if ($config === null) {
        $ruta   = __DIR__ . '/../config/wompi.php';
        $config = is_file($ruta) ? require $ruta : [];
    }
    return $config;
}

/** ¿Están las llaves cargadas? Sin ellas el sitio funciona sin pasarela. */
function pagosEnLineaActivos(): bool {
    $c = configPagos();
    return !empty($c['llave_publica'])
        && !empty($c['secreto_integridad'])
        && str_starts_with((string)$c['llave_publica'], 'pub_');
}

/**
 * ¿El abono es requisito para agendar?
 * En modo demostración nunca: no hay cuenta con qué cobrar, y exigirlo dejaría
 * el sitio sin poder recibir reservas. El cliente ve el paso de pago y además
 * la opción de enviar la solicitud para que el equipo lo contacte.
 */
function abonoEsObligatorio(): bool {
    return pagosEnLineaActivos() && !pagosEnModoDemo() && !empty(configPagos()['abono_obligatorio']);
}

/** true si las llaves son de producción (dinero real). */
function pagosEnProduccion(): bool {
    return (configPagos()['entorno'] ?? 'test') === 'prod';
}

/** URL base del sitio, sin barra final. */
function urlBaseSitio(): string {
    return rtrim((string)(configPagos()['url_base'] ?? ''), '/');
}

/**
 * ¿Siguen puestas las llaves de relleno del modo demostración?
 * Con ellas el paso de pago se ve, pero el checkout de Wompi las rechaza,
 * así que el sistema no aparta horarios mientras estén cargadas.
 */
function pagosEnModoDemo(): bool {
    $c = configPagos();
    foreach (['llave_publica', 'llave_privada', 'secreto_integridad', 'secreto_eventos'] as $clave) {
        $valor = (string)($c[$clave] ?? '');
        if (str_contains($valor, '000000000000DEMO') || str_starts_with($valor, 'demo_')) return true;
    }
    return false;
}

/** Huella de la configuración: sirve para saber si un diagnóstico guardado quedó viejo. */
function huellaConfigPagos(): string {
    $c = configPagos();
    return hash('sha256', json_encode([
        $c['entorno'] ?? '', $c['llave_publica'] ?? '', $c['llave_privada'] ?? '',
        $c['secreto_integridad'] ?? '', $c['secreto_eventos'] ?? '', urlBaseSitio(),
    ]));
}

// ── Cálculo del abono ───────────────────────────────────────

/**
 * Abono que debe pagar el cliente para separar la cita.
 * Aplica el porcentaje configurado, respeta el mínimo/máximo y
 * redondea hacia arriba para no pedir cifras incómodas.
 */
function calcularAbonoReserva(float $totalServicios): float {
    $r = configPagos()['abono'] ?? [];
    $porcentaje = max(1, min(100, (float)($r['porcentaje'] ?? 30)));
    $minimo     = max(0, (float)($r['minimo'] ?? 0));
    $maximo     = max(0, (float)($r['maximo'] ?? 0));
    $redondeo   = max(0, (float)($r['redondear_a'] ?? 0));

    $abono = $totalServicios * $porcentaje / 100;

    if ($redondeo > 0) {
        $abono = ceil($abono / $redondeo) * $redondeo;
    }
    if ($minimo > 0 && $abono < $minimo) {
        $abono = $minimo;
    }
    if ($maximo > 0 && $abono > $maximo) {
        $abono = $maximo;
    }
    // Nunca cobrar más que el servicio completo.
    if ($totalServicios > 0 && $abono > $totalServicios) {
        $abono = $totalServicios;
    }
    return round($abono, 2);
}

/** Wompi trabaja en centavos: $30.000 → 3000000. */
function montoEnCentavos(float $monto): int {
    return (int)round($monto * 100);
}

/** Referencia única e irrepetible para la transacción. */
function generarReferenciaPago(int $citaId): string {
    return 'BLUE-' . $citaId . '-' . strtoupper(bin2hex(random_bytes(4)));
}

// ── Checkout de Wompi ───────────────────────────────────────

/**
 * Firma de integridad exigida por el checkout.
 * La cadena a firmar es <referencia><centavos><moneda><expiracion><secreto>;
 * la fecha de expiración solo entra si se envía ese campo en el formulario.
 */
function firmaIntegridadWompi(string $referencia, int $centavos, string $moneda, string $expiraEn = ''): string {
    $secreto = (string)(configPagos()['secreto_integridad'] ?? '');
    return hash('sha256', $referencia . $centavos . $moneda . $expiraEn . $secreto);
}

/** Fecha de expiración en el formato ISO-8601 UTC que espera Wompi. */
function fechaExpiracionWompi(string $fechaHoraLocal): string {
    return (new DateTime($fechaHoraLocal))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s.000\Z');
}

/** URL del checkout alojado por Wompi (el entorno lo define la llave pública). */
function urlCheckoutWompi(): string {
    return 'https://checkout.wompi.co/p/';
}

/**
 * Separa un celular colombiano en prefijo y número, como lo pide el checkout.
 * "+57 300 123 4567" → ['+57', '3001234567']. Si no es un celular colombiano
 * reconocible devuelve null y no se envía: el cliente lo escribe en Wompi.
 */
function telefonoParaWompi(string $telefono): ?array {
    $digitos = (string)preg_replace('/\D+/', '', $telefono);
    if (strlen($digitos) === 12 && str_starts_with($digitos, '57')) {
        $digitos = substr($digitos, 2);
    }
    return preg_match('/^3\d{9}$/', $digitos) ? ['+57', $digitos] : null;
}

/**
 * Campos del formulario que se envían al checkout.
 * El monto y la firma se calculan SIEMPRE aquí, nunca llegan del navegador.
 */
function camposCheckoutWompi(array $pago, array $cliente): array {
    $centavos = montoEnCentavos((float)$pago['amount']);
    $moneda   = $pago['currency'] ?: 'COP';
    $expira   = fechaExpiracionWompi($pago['expires_at']);

    $campos = [
        'public-key'          => (string)configPagos()['llave_publica'],
        'currency'            => $moneda,
        'amount-in-cents'     => (string)$centavos,
        'reference'           => $pago['reference'],
        'signature:integrity' => firmaIntegridadWompi($pago['reference'], $centavos, $moneda, $expira),
        'expiration-time'     => $expira,
        'redirect-url'        => urlBaseSitio() . '/pago_resultado.php',
    ];

    if (!empty($cliente['name'])) {
        $campos['customer-data:full-name'] = mb_substr(trim($cliente['name']), 0, 100);
    }
    if (!empty($cliente['email']) && filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) {
        $campos['customer-data:email'] = $cliente['email'];
    }
    $telefono = telefonoParaWompi((string)($cliente['phone'] ?? ''));
    if ($telefono) {
        $campos['customer-data:phone-number-prefix'] = $telefono[0];
        $campos['customer-data:phone-number']        = $telefono[1];
    }

    return $campos;
}

// ── Consulta a la API de Wompi ──────────────────────────────

/** Raíz de la API según el entorno configurado. */
function urlApiWompi(): string {
    return pagosEnProduccion()
        ? 'https://production.wompi.co/v1'
        : 'https://sandbox.wompi.co/v1';
}

/**
 * GET a la API de Wompi, con el detalle completo de la respuesta.
 * $llave: token Bearer a enviar, o null para las consultas públicas.
 *
 * @return array ['codigo' => int (0 si no hubo respuesta), 'json' => ?array, 'error' => string]
 */
function peticionApiWompi(string $ruta, ?string $llave = null): array {
    if (!function_exists('curl_init')) {
        return ['codigo' => 0, 'json' => null, 'error' => 'La extensión cURL de PHP no está habilitada.'];
    }
    $cabeceras = ['Accept: application/json'];
    if ($llave) $cabeceras[] = 'Authorization: Bearer ' . $llave;

    $ch = curl_init(urlApiWompi() . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $cabeceras,
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    $json = is_string($cuerpo) ? json_decode($cuerpo, true) : null;
    return [
        'codigo' => $cuerpo === false ? 0 : $codigo,
        'json'   => is_array($json) ? $json : null,
        'error'  => $error,
    ];
}

/** GET a la API de Wompi. Devuelve el JSON decodificado o null si falla. */
function consultarApiWompi(string $ruta, ?string $llave = null): ?array {
    $r = peticionApiWompi($ruta, $llave);
    if ($r['codigo'] === 0 || $r['codigo'] >= 400) {
        error_log('[Blue/Wompi] GET ' . $ruta . ' -> HTTP ' . $r['codigo'] . ' ' . $r['error']);
        return null;
    }
    return $r['json'];
}

/**
 * Datos de una transacción según Wompi (fuente de verdad del estado).
 * Es una consulta pública: responde igual con o sin llave, así que la llave
 * privada no se envía — no tiene sentido exponer el secreto donde no hace falta.
 */
function consultarTransaccionWompi(string $transaccionId): ?array {
    $json = consultarApiWompi('/transactions/' . rawurlencode($transaccionId));
    return $json['data'] ?? null;
}

// ── Webhook: verificación de la firma del evento ────────────

/**
 * Comprueba el checksum que Wompi envía con cada evento.
 * Se arma concatenando los valores de `signature.properties` en orden,
 * más el timestamp del evento y el secreto de eventos.
 */
function verificarFirmaEventoWompi(array $evento): bool {
    $secreto     = (string)(configPagos()['secreto_eventos'] ?? '');
    $propiedades = $evento['signature']['properties'] ?? null;
    $checksum    = $evento['signature']['checksum']   ?? null;
    $timestamp   = $evento['timestamp']               ?? null;

    if ($secreto === '' || !is_array($propiedades) || !$checksum || $timestamp === null) {
        return false;
    }

    $cadena = '';
    foreach ($propiedades as $ruta) {
        $valor = $evento['data'] ?? null;
        foreach (explode('.', (string)$ruta) as $clave) {
            if (!is_array($valor) || !array_key_exists($clave, $valor)) return false;
            $valor = $valor[$clave];
        }
        $cadena .= is_scalar($valor) ? (string)$valor : '';
    }
    $cadena .= $timestamp . $secreto;

    return hash_equals(strtolower(hash('sha256', $cadena)), strtolower((string)$checksum));
}

// ── Reservas vencidas ───────────────────────────────────────

/**
 * Cancela las citas cuyo abono nunca se pagó y libera su horario.
 * Se llama antes de mostrar disponibilidad y antes de crear una reserva,
 * así no hace falta una tarea programada.
 */
function liberarReservasVencidas(PDO $db): void {
    $citasLiberadas = [];
    try {
        // 0) Cuáles se van a liberar (para avisarles por correo después del UPDATE).
        $citasLiberadas = $db->query(
            "SELECT a.id FROM appointments a
               JOIN payments p ON p.appointment_id = a.id
              WHERE a.status = 'pending' AND p.status = 'pending' AND p.expires_at < NOW()"
        )->fetchAll(PDO::FETCH_COLUMN);

        // 1) Cancelar la cita mientras el pago sigue marcado como pendiente.
        $db->prepare(
            "UPDATE appointments a
               JOIN payments p ON p.appointment_id = a.id
                SET a.status = 'cancelled'
              WHERE a.status = 'pending'
                AND p.status = 'pending'
                AND p.expires_at < NOW()"
        )->execute();

        // 2) Marcar el intento de pago como vencido.
        $db->prepare(
            "UPDATE payments SET status = 'expired'
              WHERE status = 'pending' AND expires_at < NOW()"
        )->execute();
    } catch (Exception $e) {
        // Nunca debe tumbar la página que la invoca.
        error_log('[Blue/Wompi] No se pudieron liberar reservas vencidas: ' . $e->getMessage());
        return;
    }

    // Avisos por correo, ya con las citas canceladas de verdad. Esta función se
    // llama en cada carga de disponibilidad, así que casi siempre no hay nada
    // que liberar; solo se entra aquí cuando de verdad venció algún cupo.
    foreach ($citasLiberadas as $citaId) {
        avisarPorCorreoSiCorresponde($db, (int)$citaId, 'rechazada', [
            'motivo' => 'Se venció el tiempo para completar el pago del abono.',
        ]);
    }
}

// ── Aplicar el resultado del pago ───────────────────────────

/** Busca un intento de pago por su referencia. */
function buscarPagoPorReferencia(PDO $db, string $referencia): ?array {
    $stmt = $db->prepare('SELECT * FROM payments WHERE reference = ? LIMIT 1');
    $stmt->execute([$referencia]);
    return $stmt->fetch() ?: null;
}

/** Busca un intento de pago por el id de transacción de Wompi. */
function buscarPagoPorTransaccion(PDO $db, string $transaccionId): ?array {
    $stmt = $db->prepare('SELECT * FROM payments WHERE transaction_id = ? LIMIT 1');
    $stmt->execute([$transaccionId]);
    return $stmt->fetch() ?: null;
}

/**
 * Lleva el pago a su estado final y, si fue aprobado, confirma la cita.
 *
 * Es idempotente a propósito: el cliente que vuelve del checkout y el
 * webhook de Wompi suelen llegar casi al mismo tiempo, y solo uno de los
 * dos debe confirmar la cita y registrar el ingreso. El "candado" es el
 * UPDATE condicional sobre payments (status = 'pending').
 *
 * @param array $transaccion Datos `data` de la transacción según Wompi.
 * @return array ['status' => estado final, 'appointment_id' => int, 'aplicado' => bool]
 */
function aplicarResultadoPago(PDO $db, array $pago, array $transaccion): array {
    $estadoWompi = strtoupper((string)($transaccion['status'] ?? ''));
    $estado      = ESTADOS_WOMPI[$estadoWompi] ?? null;

    $resultado = [
        'status'         => $pago['status'],
        'appointment_id' => (int)$pago['appointment_id'],
        'aplicado'       => false,
    ];

    // Estado desconocido o todavía en proceso: no se toca nada.
    if ($estado === null || $estado === 'pending') {
        return $resultado;
    }
    // Ya tenía un estado final (lo aplicó el webhook o la otra pestaña).
    if ($pago['status'] !== 'pending') {
        return $resultado;
    }

    $transaccionId = (string)($transaccion['id'] ?? '');
    $medioPago     = substr((string)($transaccion['payment_method_type'] ?? ''), 0, 40);
    $centavos      = (int)($transaccion['amount_in_cents'] ?? 0);
    $montoEsperado = montoEnCentavos((float)$pago['amount']);
    $montoDistinto = $centavos > 0 && $centavos !== $montoEsperado;

    try {
        $db->beginTransaction();

        // Candado: solo una ejecución consigue mover el pago fuera de 'pending'.
        $lock = $db->prepare(
            "UPDATE payments
                SET status = ?, transaction_id = ?, payment_method = ?,
                    paid_at = CASE WHEN ? = 'approved' THEN NOW() ELSE paid_at END
              WHERE id = ? AND status = 'pending'"
        );
        $lock->execute([$estado, $transaccionId ?: null, $medioPago ?: null, $estado, $pago['id']]);

        if ($lock->rowCount() !== 1) {
            $db->rollBack();
            $actual = buscarPagoPorReferencia($db, $pago['reference']);
            $resultado['status'] = $actual['status'] ?? $pago['status'];
            return $resultado;
        }

        if ($estado === 'approved') {
            $resultado['status'] = confirmarCitaPagada($db, $pago, $medioPago, $montoDistinto);
            registrarIngresoPorAbono($db, $pago, $medioPago);
        } else {
            // Pago rechazado o anulado: se libera el horario de inmediato.
            $db->prepare(
                "UPDATE appointments SET status = 'cancelled'
                  WHERE id = ? AND status = 'pending'"
            )->execute([$pago['appointment_id']]);
            $resultado['status'] = $estado;
        }

        $db->commit();
        $resultado['aplicado'] = true;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue/Wompi] Error al aplicar el pago ' . $pago['reference'] . ': ' . $e->getMessage());
    }

    // Aviso por correo, ya con la transacción cerrada (nunca dentro de ella).
    // Si 'approved_sin_cupo' la cita NO quedó confirmada (el horario se ocupó
    // mientras pagaban): ese caso ya se le explica en pantalla y lo resuelve
    // el equipo a mano, así que aquí no se envía nada para no confundir.
    if ($resultado['aplicado']) {
        if ($resultado['status'] === 'approved') {
            avisarPorCorreoSiCorresponde($db, $resultado['appointment_id'], 'confirmada');
        } elseif (in_array($estado, ['declined', 'voided', 'error'], true)) {
            $motivo = $estado === 'declined'
                ? 'El banco no autorizó la transacción.'
                : 'Hubo un problema al procesar el pago.';
            avisarPorCorreoSiCorresponde($db, $resultado['appointment_id'], 'rechazada', ['motivo' => $motivo]);
        }
    }

    return $resultado;
}

/**
 * Confirma la cita de un abono aprobado.
 * Contempla el caso raro de que el pago llegue después de que el cupo
 * venció y otra persona lo tomó: ahí la cita NO se revive, se deja una
 * nota visible para que el equipo contacte al cliente.
 *
 * @return string 'approved' o 'approved_sin_cupo'
 */
function confirmarCitaPagada(PDO $db, array $pago, string $medioPago, bool $montoDistinto): string {
    $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
    $stmt->execute([$pago['appointment_id']]);
    $cita = $stmt->fetch();
    if (!$cita) return 'approved';

    $nota = 'Abono en linea aprobado: ' . formatPrice((float)$pago['amount'])
          . ' · Wompi ref ' . $pago['reference']
          . ($medioPago ? ' · ' . $medioPago : '');
    if ($montoDistinto) {
        $nota .= ' · OJO: el monto recibido no coincide con el solicitado, verificar en el panel de Wompi.';
    }

    $horarioLibre = true;
    if ($cita['status'] === 'cancelled') {
        // La cita se había cancelado por vencimiento: ¿el cupo sigue libre?
        $choque = $db->prepare(
            "SELECT COUNT(*) FROM appointments
              WHERE id <> ? AND date = ? AND status IN ('pending','confirmed')
                AND time_start < ? AND time_end > ?"
        );
        $choque->execute([$cita['id'], $cita['date'], $cita['time_end'], $cita['time_start']]);
        $horarioLibre = (int)$choque->fetchColumn() === 0;
    }

    if ($horarioLibre) {
        $db->prepare(
            "UPDATE appointments SET status = 'confirmed'
              WHERE id = ? AND status IN ('pending','cancelled')"
        )->execute([$cita['id']]);
    } else {
        $nota .= ' · PAGO RECIBIDO SIN CUPO: el horario se ocupo mientras el cliente pagaba.'
               . ' Contactar para reprogramar o devolver el abono.';
    }

    $db->prepare(
        "UPDATE appointments
            SET notes = TRIM(CONCAT(COALESCE(notes, ''), '\n', ?))
          WHERE id = ?"
    )->execute([$nota, $cita['id']]);

    return $horarioLibre ? 'approved' : 'approved_sin_cupo';
}

/**
 * Registra el abono como ingreso en Finanzas.
 * `registered_by` queda en NULL porque no lo registró una persona:
 * en el listado esos movimientos aparecen como "Pago en línea".
 */
function registrarIngresoPorAbono(PDO $db, array $pago, string $medioPago): void {
    $yaExiste = $db->prepare(
        "SELECT COUNT(*) FROM finances WHERE appointment_id = ? AND description LIKE ?"
    );
    $yaExiste->execute([$pago['appointment_id'], '%' . $pago['reference'] . '%']);
    if ((int)$yaExiste->fetchColumn() > 0) return;

    $descripcion = 'Abono de reserva · cita #' . $pago['appointment_id']
                 . ' · ' . $pago['reference']
                 . ($medioPago ? ' · ' . $medioPago : '');

    $db->prepare(
        "INSERT INTO finances (type, category, description, amount, date, appointment_id, registered_by)
         VALUES ('income', 'Abono en línea', ?, ?, CURDATE(), ?, NULL)"
    )->execute([$descripcion, $pago['amount'], $pago['appointment_id']]);
}

// ── Presentación ────────────────────────────────────────────

/** Etiqueta legible para un estado de pago. */
function etiquetaEstadoPago(string $estado): string {
    return match ($estado) {
        'approved' => 'Aprobado',
        'pending'  => 'Pendiente',
        'declined' => 'Rechazado',
        'voided'   => 'Anulado',
        'expired'  => 'Vencido',
        default    => 'Con error',
    };
}

// ══════════════════════════════════════════════════════════
//   SALDO DE LA CITA — pagos cobrados en el centro
// ══════════════════════════════════════════════════════════

/** Medios con los que el equipo puede recibir el saldo en el centro. */
function mediosDePagoManual(): array {
    return ['Efectivo', 'Nequi', 'Daviplata', 'Tarjeta débito', 'Tarjeta crédito', 'Transferencia', 'Otro'];
}

/** Todos los pagos de una cita (abono en línea y saldos), del más nuevo al más viejo. */
function pagosDeCita(PDO $db, int $citaId): array {
    $stmt = $db->prepare(
        'SELECT p.*, u.name AS recibido_por_nombre
           FROM payments p
           LEFT JOIN users u ON u.id = p.received_by
          WHERE p.appointment_id = ?
          ORDER BY p.id DESC'
    );
    $stmt->execute([$citaId]);
    return $stmt->fetchAll();
}

/**
 * Cuentas de la cita: cuánto vale, cuánto se ha pagado y cuánto falta.
 *
 * `ingreso_externo` son ingresos que esta cita ya tiene en Finanzas pero que no
 * salieron de un pago registrado aquí — típicamente el que crea el módulo de
 * Agenda al marcar la cita como completada. Se muestra como advertencia para
 * que nadie registre el saldo dos veces.
 */
function resumenPagosCita(PDO $db, int $citaId, float $totalServicios): array {
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN kind = 'deposit' THEN amount END), 0) AS abono,
                COALESCE(SUM(CASE WHEN kind = 'balance' THEN amount END), 0) AS saldo_pagado
           FROM payments
          WHERE appointment_id = ? AND status = 'approved'"
    );
    $stmt->execute([$citaId]);
    $r = $stmt->fetch() ?: ['abono' => 0, 'saldo_pagado' => 0];

    $externo = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0)
           FROM finances
          WHERE appointment_id = ? AND type = 'income'
            AND category NOT IN ('Abono en línea', 'Saldo de cita')"
    );
    $externo->execute([$citaId]);

    $abono  = (float)$r['abono'];
    $saldo  = (float)$r['saldo_pagado'];
    $pagado = $abono + $saldo;

    return [
        'total'           => $totalServicios,
        'abono'           => $abono,
        'saldo_pagado'    => $saldo,
        'pagado'          => $pagado,
        'pendiente'       => max(0.0, round($totalServicios - $pagado, 2)),
        'esta_saldada'    => round($totalServicios - $pagado, 2) <= 0,
        'ingreso_externo' => (float)$externo->fetchColumn(),
    ];
}

/**
 * Registra el saldo que el cliente pagó en el centro.
 *
 * Deja rastro en dos sitios: una fila en `payments` (kind = 'balance', con la
 * persona que recibió el dinero) y el ingreso correspondiente en Finanzas.
 *
 * @param array $in  monto · medio · recibido_por · nota
 * @return array ['ok' => bool, 'msg' => string]
 */
function registrarPagoSaldo(PDO $db, int $citaId, float $totalServicios, array $in, int $actorId): array {
    $monto    = round((float)($in['monto'] ?? 0), 2);
    $medio    = trim((string)($in['medio'] ?? ''));
    $recibido = (int)($in['recibido_por'] ?? 0);
    $nota     = trim((string)($in['nota'] ?? ''));

    if (!in_array($medio, mediosDePagoManual(), true)) {
        return ['ok' => false, 'msg' => 'Selecciona un medio de pago válido.'];
    }
    if ($monto <= 0) {
        return ['ok' => false, 'msg' => 'El monto debe ser mayor a cero.'];
    }

    $quien = $db->prepare('SELECT name FROM users WHERE id = ? AND active = 1 LIMIT 1');
    $quien->execute([$recibido]);
    $nombreQuien = $quien->fetchColumn();
    if (!$nombreQuien) {
        return ['ok' => false, 'msg' => 'Indica a quién del equipo se le pagó.'];
    }

    // El pendiente se recalcula aquí: es lo único confiable, no lo que venga del formulario.
    $resumen = resumenPagosCita($db, $citaId, $totalServicios);
    if ($resumen['pendiente'] <= 0) {
        return ['ok' => false, 'msg' => 'Esta cita ya está totalmente pagada.'];
    }
    if ($monto > $resumen['pendiente'] + 0.01) {
        return ['ok' => false, 'msg' => 'El monto supera el saldo pendiente (' . formatPrice($resumen['pendiente']) . ').'];
    }

    $referencia  = 'SALDO-' . $citaId . '-' . strtoupper(bin2hex(random_bytes(3)));
    $descripcion = 'Saldo de cita #' . $citaId . ' · ' . $medio . ' · recibió ' . $nombreQuien
                 . ' · ' . $referencia;

    try {
        $db->beginTransaction();

        $db->prepare(
            "INSERT INTO payments
                (appointment_id, provider, kind, reference, amount, service_total, currency,
                 status, payment_method, received_by, note, paid_at)
             VALUES (?, 'manual', 'balance', ?, ?, ?, 'COP', 'approved', ?, ?, ?, NOW())"
        )->execute([$citaId, $referencia, $monto, $totalServicios, $medio, $recibido, $nota ?: null]);

        $db->prepare(
            "INSERT INTO finances (type, category, description, amount, date, appointment_id, registered_by)
             VALUES ('income', 'Saldo de cita', ?, ?, CURDATE(), ?, ?)"
        )->execute([$descripcion, $monto, $citaId, $actorId]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue] No se pudo registrar el saldo de la cita ' . $citaId . ': ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo registrar el pago. Intenta de nuevo.'];
    }

    // Si con este pago quedó todo saldado, se avisa aparte del abono: es la
    // confirmación de que no queda ningún valor pendiente por esta cita.
    if (resumenPagosCita($db, $citaId, $totalServicios)['esta_saldada']) {
        avisarPorCorreoSiCorresponde($db, $citaId, 'saldada', ['total' => $totalServicios]);
    }

    return ['ok' => true, 'msg' => 'Pago de ' . formatPrice($monto) . ' registrado a nombre de ' . $nombreQuien . '.'];
}

/**
 * Deshace un pago de saldo registrado por error (solo los manuales:
 * un abono cobrado por Wompi no se borra desde aquí, se anula en Wompi).
 * Borra también el ingreso que había creado en Finanzas.
 */
function anularPagoSaldo(PDO $db, int $pagoId, int $citaId): array {
    $stmt = $db->prepare(
        "SELECT * FROM payments WHERE id = ? AND appointment_id = ? AND provider = 'manual' LIMIT 1"
    );
    $stmt->execute([$pagoId, $citaId]);
    $pago = $stmt->fetch();

    if (!$pago) {
        return ['ok' => false, 'msg' => 'Ese pago no se puede anular desde aquí.'];
    }

    try {
        $db->beginTransaction();
        $db->prepare('DELETE FROM finances WHERE appointment_id = ? AND description LIKE ?')
           ->execute([$citaId, '%' . $pago['reference'] . '%']);
        $db->prepare('DELETE FROM payments WHERE id = ?')->execute([$pagoId]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue] No se pudo anular el pago ' . $pagoId . ': ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo anular el pago.'];
    }

    return ['ok' => true, 'msg' => 'Pago anulado y descontado de Finanzas.'];
}

/** Etiqueta del tipo de pago, para las tablas del panel. */
function etiquetaTipoPago(string $kind): string {
    return $kind === 'balance' ? 'Saldo' : 'Abono';
}

// ══════════════════════════════════════════════════════════
//   RESUMEN DE PAGOS PARA LOS LISTADOS DE CITAS
// ══════════════════════════════════════════════════════════

/**
 * Cuánto se ha pagado (aprobado) de cada cita, indexado por id de cita.
 * Pensado para listados: una sola consulta para toda la página.
 *
 * Si la migración 02_finanzas.sql todavía no se ha corrido, devuelve un
 * arreglo vacío en vez de tumbar la pantalla que lo pide.
 */
function pagosPorCita(PDO $db, array $citaIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $citaIds))));
    if (!$ids) return [];

    try {
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT appointment_id, COALESCE(SUM(amount), 0) AS pagado
               FROM payments
              WHERE status = 'approved' AND appointment_id IN ($marcadores)
              GROUP BY appointment_id"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        error_log('[Blue] No se pudieron leer los pagos de las citas: ' . $e->getMessage());
        return [];
    }
}

/**
 * Insignia corta del estado de pago, para la columna Total de un listado.
 * Devuelve cadena vacía cuando no hay nada que decir (cita sin precio o sin
 * pagos registrados), para no ensuciar las filas de siempre.
 */
function insigniaPagoCita(float $total, float $pagado): string {
    if ($total <= 0 || $pagado <= 0) return '';

    return round($total - $pagado, 2) <= 0
        ? '<span class="badge badge-confirmed">Pagada</span>'
        : '<span class="pill pill-muted">Falta ' . e(formatPrice($total - $pagado)) . '</span>';
}

// ══════════════════════════════════════════════════════════
//   DIAGNÓSTICO — ¿está todo listo para cobrar?
// ══════════════════════════════════════════════════════════

/**
 * Revisa configuración, servidor y conexión con Wompi, sin cobrar nada.
 * Lo usan Configuración → Pagos en línea («Probar conexión») y tests/pagos_wompi.php.
 *
 * Cada revisión trae un estado:
 *   ok · aviso (funciona, pero conviene corregirlo) · error (no se puede cobrar) · omitido
 *
 * Los secretos de integridad y de eventos no se pueden validar contra la API porque
 * solo firman datos: su comprobación final es un pago de prueba en sandbox.
 */
function diagnosticoPasarelaWompi(?PDO $db = null): array {
    $c        = configPagos();
    $entorno  = ($c['entorno'] ?? 'test') === 'prod' ? 'prod' : 'test';
    $ambiente = $entorno === 'prod' ? 'producción' : 'pruebas';
    $pub      = trim((string)($c['llave_publica'] ?? ''));
    $prv      = trim((string)($c['llave_privada'] ?? ''));
    $integ    = trim((string)($c['secreto_integridad'] ?? ''));
    $eventos  = trim((string)($c['secreto_eventos'] ?? ''));
    $urlBase  = urlBaseSitio();
    $hayCurl  = function_exists('curl_init');

    $checks  = [];
    $agregar = function (string $grupo, string $nombre, string $estado, string $detalle) use (&$checks): void {
        $checks[] = ['grupo' => $grupo, 'nombre' => $nombre, 'estado' => $estado, 'detalle' => $detalle];
    };

    // ── 1. Configuración ─────────────────────────────────
    if (pagosEnModoDemo()) {
        $agregar('Configuración', 'Llaves reales', 'error',
            'Siguen puestas las llaves de demostración. Reemplázalas en config/wompi.local.php por las de tu cuenta de Wompi.');
    }

    foreach ([
        ['Llave pública', $pub, "pub_{$entorno}_", 'error', 'sin ella no se puede abrir el checkout.'],
        ['Llave privada', $prv, "prv_{$entorno}_", 'aviso', 'no se usa para cobrar, pero sin ella no se puede verificar la cuenta.'],
    ] as [$nombre, $valor, $prefijo, $gravedad, $sinElla]) {
        if ($valor === '') {
            $agregar('Configuración', $nombre, $gravedad, 'Falta cargarla: ' . $sinElla);
        } elseif (!str_starts_with($valor, $prefijo)) {
            $agregar('Configuración', $nombre, 'error',
                "Debe empezar por «{$prefijo}» porque el ambiente configurado es {$ambiente}. ¿Se mezclaron llaves de pruebas y de producción?");
        } else {
            $agregar('Configuración', $nombre, 'ok', "Formato correcto para el ambiente de {$ambiente}.");
        }
    }

    foreach ([
        ['Secreto de integridad', $integ,   "{$entorno}_integrity_", 'error', 'firma el checkout: sin él Wompi rechaza el pago.'],
        ['Secreto de eventos',    $eventos, "{$entorno}_events_",    'aviso', 'firma los avisos del webhook: sin él la cita solo se confirma cuando el cliente vuelve del checkout.'],
    ] as [$nombre, $valor, $prefijo, $gravedad, $paraQue]) {
        if ($valor === '') {
            $agregar('Configuración', $nombre, $gravedad, 'Falta cargarlo. Este secreto ' . $paraQue);
        } elseif (!str_starts_with($valor, $prefijo)) {
            $agregar('Configuración', $nombre, 'aviso',
                "Normalmente empieza por «{$prefijo}». Verifica que se copió completo y del ambiente de {$ambiente}.");
        } else {
            $agregar('Configuración', $nombre, 'ok', 'Cargado. Se confirma de verdad con un pago de prueba.');
        }
    }

    $partes = parse_url($urlBase) ?: [];
    $esquema = strtolower((string)($partes['scheme'] ?? ''));
    $host    = strtolower((string)($partes['host'] ?? ''));
    if ($urlBase === '' || !in_array($esquema, ['http', 'https'], true) || $host === '') {
        $agregar('Configuración', 'URL del sitio', 'error',
            'url_base debe ser una dirección completa, por ejemplo https://midominio.com/Blue');
    } elseif ($entorno === 'prod' && $esquema !== 'https') {
        $agregar('Configuración', 'URL del sitio', 'error', 'En producción el sitio debe servirse por https.');
    } elseif (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
        $agregar('Configuración', 'URL del sitio', 'aviso',
            "{$urlBase} solo existe en este equipo: Wompi no podrá avisar al webhook. "
            . 'El pago igual se confirma cuando el cliente vuelve del checkout.');
    } else {
        $agregar('Configuración', 'URL del sitio', 'ok', $urlBase);
    }

    // ── 2. Servidor ──────────────────────────────────────
    $agregar('Servidor', 'Extensión cURL', $hayCurl ? 'ok' : 'error',
        $hayCurl ? 'Disponible.' : 'Actívala en php.ini (extension=curl) y reinicia Apache.');

    if ($db) {
        $faltan = [];
        $existe = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        foreach (['payments', 'appointment_links'] as $tabla) {
            $existe->execute([$tabla]);
            if (!(int)$existe->fetchColumn()) $faltan[] = $tabla;
        }
        $agregar('Servidor', 'Base de datos', $faltan ? 'error' : 'ok',
            $faltan ? 'Faltan las tablas ' . implode(', ', $faltan) . '. Ejecuta database/migrations/02_finanzas.sql.'
                    : 'Las tablas de pagos existen.');
    }

    // ── 3. Conexión real con Wompi ───────────────────────
    $grupo = 'Conexión con Wompi (' . $ambiente . ')';

    if ($pub === '' || !$hayCurl) {
        $agregar($grupo, 'Cuenta de comercio', 'omitido', 'Se revisa cuando haya llave pública y cURL.');
    } else {
        $r = peticionApiWompi('/merchants/' . rawurlencode($pub));
        $comercio = $r['json']['data'] ?? null;
        if ($r['codigo'] === 200 && is_array($comercio)) {
            $agregar($grupo, 'Cuenta de comercio', 'ok',
                'Wompi reconoce la llave pública' . (!empty($comercio['name']) ? ': comercio «' . $comercio['name'] . '».' : '.'));
        } elseif ($r['codigo'] === 0) {
            $agregar($grupo, 'Cuenta de comercio', 'error',
                'No hubo respuesta de ' . urlApiWompi() . ($r['error'] ? " ({$r['error']})" : '')
                . '. Revisa la conexión a internet o el certificado SSL de PHP.');
        } elseif (in_array($r['codigo'], [401, 404, 422], true)) {
            $agregar($grupo, 'Cuenta de comercio', 'error',
                "Wompi no reconoce esta llave pública (HTTP {$r['codigo']}). Cópiala de nuevo desde el panel, en el ambiente de {$ambiente}.");
        } else {
            $agregar($grupo, 'Cuenta de comercio', 'aviso',
                "Respuesta inesperada de Wompi (HTTP {$r['codigo']}). Intenta de nuevo en unos minutos.");
        }
    }

    if ($prv === '' || !$hayCurl) {
        $agregar($grupo, 'Llave privada', 'omitido', 'Se revisa cuando haya llave privada y cURL.');
    } else {
        // Búsqueda de una referencia inventada: no toca datos, solo prueba la autenticación.
        $r = peticionApiWompi('/transactions?reference=' . rawurlencode('BLUE-DIAGNOSTICO-' . bin2hex(random_bytes(3))), $prv);
        if ($r['codigo'] >= 200 && $r['codigo'] < 300) {
            $agregar($grupo, 'Llave privada', 'ok', 'Wompi aceptó la llave privada.');
        } elseif (in_array($r['codigo'], [401, 403], true)) {
            $agregar($grupo, 'Llave privada', 'error',
                'Wompi rechazó la llave privada (' . ($r['json']['error']['reason'] ?? 'no válida') . ').');
        } elseif ($r['codigo'] === 0) {
            $agregar($grupo, 'Llave privada', 'error', 'No hubo respuesta de Wompi al verificar la llave privada.');
        } else {
            $agregar($grupo, 'Llave privada', 'aviso',
                "Wompi respondió HTTP {$r['codigo']}: no confirmó la llave, pero tampoco la rechazó.");
        }
    }

    $errores = count(array_filter($checks, fn($x) => $x['estado'] === 'error'));
    $avisos  = count(array_filter($checks, fn($x) => $x['estado'] === 'aviso'));

    return [
        'listo'   => $errores === 0,
        'errores' => $errores,
        'avisos'  => $avisos,
        'checks'  => $checks,
        'entorno' => $entorno,
        'fecha'   => date('Y-m-d H:i:s'),
        'huella'  => huellaConfigPagos(),
    ];
}
