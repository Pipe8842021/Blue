<?php
// ============================================================
// Blue Therapy — Estado detallado de una cita
// ------------------------------------------------------------
// Una sola vista para dos públicos:
//   · Cliente  → entra con el enlace privado (?id=N&t=<token>) y ve el
//                estado, los servicios, el profesional y sus pagos.
//   · Personal → entra logueado (?id=N, sin token) y además ve las notas
//                internas, el historial completo de pagos y puede
//                registrar el saldo que el cliente pagó en el centro.
//
// Las notas de la cita NUNCA se le muestran al cliente: ahí quedan
// anotaciones internas (avisos de la pasarela, observaciones del equipo).
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/h-reservas.php';
require_once __DIR__ . '/includes/h-pagos.php';
require_once __DIR__ . '/includes/h-negocio.php';
require_once __DIR__ . '/includes/h-cancelaciones.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');



$citaId = (int)($_GET['id'] ?? 0);
// El enlace del cliente llega en la URL; al cancelar, viaja también en el formulario.
$token  = (string)($_GET['t'] ?? $_POST['t'] ?? '');

try {
    $db = getDB();
} catch (Exception $e) {
    http_response_code(503);
    exit('No pudimos consultar tu cita en este momento. Intenta más tarde.');
}

// ── ¿Quién está mirando? ──────────────────────────────────
$usuario   = currentUser();
$esAdmin   = isLoggedIn() && ($usuario['role'] ?? '') === 'admin';
$esStaff   = isLoggedIn() && ($usuario['role'] ?? '') === 'staff';

$cita = $citaId > 0 ? citaConDetalle($db, $citaId) : null;

// Un profesional solo ve las citas que tiene asignadas, igual que en «Mis citas»;
// el administrador ve todas.
$esPersonal = $esAdmin || ($esStaff && $cita && (int)$cita['staff_id'] === (int)$usuario['id']);

// El cliente solo pasa con el token correcto; el personal, con tener la cita a su cargo.
$accesoCliente = $cita && !$esPersonal && tokenCitaEsValido($db, $citaId, $token);
if (!$cita || (!$esPersonal && !$accesoCliente)) {
    http_response_code(404);
    $noEncontrada = true;
}

// Valores por defecto: si la cita no existe o no hay acceso, la plantilla
// solo pinta el mensaje de "no encontrada" y nada de esto se usa.
$servicios      = [];
$total          = 0.0;
$resumen        = ['abono' => 0.0, 'saldo_pagado' => 0.0, 'pagado' => 0.0,
                   'pendiente' => 0.0, 'esta_saldada' => false, 'ingreso_externo' => 0.0];
$pagos          = [];
$pasos          = [];
$abonoCita      = null;
$cancelacion    = ['puede' => false, 'motivo' => ''];
$quienCancelo   = '';
$minimoCancelacion = horasMinimasCancelacion();
$estado         = ['label' => '', 'class' => ''];
$recibidoPor    = [];
$equipo         = [];
$enlaceCliente  = '';
$puedeRegistrar = false;

if (empty($noEncontrada)) {
    $servicios = serviciosDeCita($db, $citaId);
    $total     = array_sum(array_map(fn($s) => (float)$s['price'], $servicios));

    // Un profesional solo administra el dinero de las citas que tiene asignadas.
    $puedeRegistrar = $esAdmin || ($esStaff && (int)$cita['staff_id'] === (int)$usuario['id']);

    // ── Acciones (POST) ───────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // El cliente solo puede cancelar su propia cita, con su enlace
        // (el token ya se validó arriba: sin él la página responde 404).
        if (($_POST['action'] ?? '') === 'cancelar_cita' && $accesoCliente) {
            $volver = '/Blue/cita.php?id=' . $citaId . '&t=' . rawurlencode($token);
            if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
                setFlash('error', 'Tu sesión expiró. Recarga la página e intenta de nuevo.');
                header('Location: ' . $volver); exit;
            }
            $r = cancelarCita($db, $citaId, 'cliente', null, (string)($_POST['motivo'] ?? ''));
            if ($r['ok']) {
                setFlash('success', $r['abono'] === 'por_devolver'
                    ? 'Tu cita fue cancelada. Te devolveremos el abono en los próximos días hábiles.'
                    : 'Tu cita fue cancelada.');
            } else {
                setFlash('error', $r['msg']);
            }
            header('Location: ' . $volver); exit;
        }

        // ── Acciones del personal ─────────────────────────
        $volver = '/Blue/cita.php?id=' . $citaId;

        if (!$esPersonal || !$puedeRegistrar) {
            setFlash('error', 'No tienes permiso para registrar pagos de esta cita.');
            header('Location: ' . $volver); exit;
        }
        if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
            setFlash('error', 'Token de seguridad inválido. Intenta de nuevo.');
            header('Location: ' . $volver); exit;
        }

        switch ($_POST['action'] ?? '') {
            case 'registrar_saldo':
                $r = registrarPagoSaldo($db, $citaId, $total, $_POST, (int)$usuario['id']);
                setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
                break;

            case 'anular_pago':
                if (!$esAdmin) {
                    setFlash('error', 'Solo un administrador puede anular un pago.');
                    break;
                }
                $r = anularPagoSaldo($db, (int)($_POST['pago_id'] ?? 0), $citaId);
                setFlash($r['ok'] ? 'info' : 'error', $r['msg']);
                break;

            // Devolución del abono de una cita cancelada (h-cancelaciones.php).
            case 'marcar_devolucion':
            case 'registrar_devolucion':
                if (!$esAdmin) {
                    setFlash('error', 'Solo un administrador gestiona las devoluciones de abonos.');
                    break;
                }
                $pagoId = (int)($_POST['pago_id'] ?? 0);
                $r = $_POST['action'] === 'marcar_devolucion'
                    ? marcarAbonoPorDevolver($db, $pagoId, $citaId)
                    : registrarDevolucionAbono($db, $pagoId, $citaId, (int)$usuario['id']);
                setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
                break;
        }
        header('Location: ' . $volver); exit;
    }

    $resumen = resumenPagosCita($db, $citaId, $total);
    $pagos   = pagosDeCita($db, $citaId);
    $pasos   = pasosEstadoCita($cita['status']);
    $estado  = statusLabel($cita['status']);

    // Quién recibió el saldo (lo ve también el cliente: es su comprobante).
    $recibidoPor = [];
    foreach ($pagos as $p) {
        if ($p['kind'] === 'balance' && $p['status'] === 'approved' && $p['recibido_por_nombre']) {
            $recibidoPor[] = $p['recibido_por_nombre'];
        }
    }
    $recibidoPor = array_values(array_unique($recibidoPor));

    // Abono en línea aprobado (para mostrar su devolución si la cita se canceló).
    $abonoCita = null;
    foreach ($pagos as $p) {
        if ($p['kind'] === 'deposit' && $p['status'] === 'approved') { $abonoCita = $p; break; }
    }

    // Cancelación: quién, cuándo y si el cliente todavía puede cancelar desde aquí.
    $cancelacion       = $esPersonal ? ['puede' => false, 'motivo' => ''] : cancelacionClienteDisponible($db, $cita);
    // Las cancelaciones automáticas (pago vencido o rechazado) no registran quién canceló:
    // se reconocen porque el abono en línea nunca se aprobó.
    $canceladaPor = $cita['cancelled_by'] ?? null;
    if ($cita['status'] === 'cancelled' && $canceladaPor === null && !$abonoCita
        && array_filter($pagos, fn($p) => $p['kind'] === 'deposit' && in_array($p['status'], ['expired', 'declined', 'voided', 'error'], true))) {
        $canceladaPor = 'sistema';
    }
    $quienCancelo      = [
        'cliente' => $esPersonal ? 'Cancelada por el cliente desde su enlace' : 'La cancelaste desde este enlace',
        'equipo'  => 'Cancelada por el centro',
        'sistema' => 'Cancelada automáticamente: el pago en línea no se completó',
    ][$canceladaPor ?? ''] ?? '';

    if ($esPersonal) {
        $equipo        = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll();
        $enlaceCliente = urlEstadoCita($db, $citaId);
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= empty($noEncontrada) ? 'Cita #' . (int)$citaId : 'Cita no encontrada' ?> — Blue Therapy</title>
  <meta name="robots" content="noindex">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/Blue/assets/css/booking.css?v=<?= @filemtime(__DIR__ . '/assets/css/booking.css') ?>">
</head>
<body class="booking-page">

<nav class="pago-nav">
  <a href="/Blue/" class="pago-nav-brand">
    <span class="pago-nav-mark">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="#5bc4b8"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
    </span>
    <span>
      <span class="pago-nav-name">Blue</span>
      <span class="pago-nav-sub">THERAPY</span>
    </span>
  </a>
  <a href="/Blue/" class="pago-nav-link">Volver al inicio</a>
</nav>

<?php if (!empty($noEncontrada)): ?>

  <main class="pago-wrap">
    <div class="pago-card">
      <div class="pago-status pago-status--error">
        <div class="pago-status-icon">?</div>
        <h1 class="pago-status-title">No encontramos esta cita</h1>
        <p class="pago-status-text">
          El enlace puede haber caducado o estar incompleto. Escríbenos por WhatsApp
          y con gusto te ayudamos a consultar el estado de tu reserva.
        </p>
      </div>
      <div class="pago-acciones">
        <a href="https://wa.me/<?= e(telefonoNegocioDigitos()) ?>" class="btn-home btn-home--primary">Escribir por WhatsApp</a>
        <a href="/Blue/booking.php" class="btn-home">Reservar una cita</a>
      </div>
    </div>
  </main>

<?php else: ?>

<main class="cita-wrap">

  <?php if ($esPersonal): ?>
    <div class="cita-barra-staff">
      <span class="cbs-tag">Vista del personal</span>
      <span class="cbs-quien"><?= e($usuario['name']) ?> · <?= $esAdmin ? 'Administrador' : 'Profesional' ?></span>
      <div class="cbs-links">
        <a href="<?= $esAdmin ? '/Blue/admin/appointments.php' : '/Blue/staff/citas.php' ?>">← Volver a Citas</a>
        <?php if ($esAdmin): ?>
          <a href="/Blue/admin/imprimir_cita.php?id=<?= (int)$citaId ?>" target="_blank">Imprimir comprobante</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="cita-flash cita-flash--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
  <?php endif; ?>

  <div class="pago-card">

    <!-- ══ Encabezado ══ -->
    <div class="cita-head">
      <div>
        <div class="cita-eyebrow">Cita #<?= (int)$citaId ?></div>
        <h1 class="cita-titulo"><?= e($cita['cliente_nombre']) ?></h1>
        <p class="cita-sub"><?= e(formatDate($cita['date'])) ?> · <?= e(formatTime($cita['time_start'])) ?> – <?= e(formatTime($cita['time_end'])) ?></p>
      </div>
      <span class="cita-badge cita-badge--<?= e($cita['status']) ?>"><?= e($estado['label']) ?></span>
    </div>

    <!-- ══ Línea de tiempo del estado ══ -->
    <?php if ($cita['status'] === 'cancelled'): ?>
      <div class="cita-cancelada">
        <span class="cita-cancelada-icon">✕</span>
        <div>
          <strong>Esta cita fue cancelada</strong>
          <?php if ($quienCancelo): ?>
            <?= e($quienCancelo) ?><?= $cita['cancelled_at'] ? ' el ' . e(formatDate(substr($cita['cancelled_at'], 0, 10))) : '' ?>.
          <?php endif; ?>
          <?php if ($esPersonal && $cita['cancel_reason']): ?>
            Motivo: <?= e($cita['cancel_reason']) ?>.
          <?php elseif (!$esPersonal && $canceladaPor !== 'cliente'): ?>
            Si crees que se trata de un error, escríbenos por WhatsApp y la reprogramamos.
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <ol class="cita-timeline">
        <?php foreach ($pasos as $paso): ?>
          <li class="ct-paso <?= $paso['hecho'] ? 'ct-paso--hecho' : '' ?> <?= $paso['activo'] ? 'ct-paso--activo' : '' ?>">
            <span class="ct-punto"><?= $paso['hecho'] ? '✓' : '' ?></span>
            <span class="ct-label"><?= e($paso['label']) ?></span>
            <span class="ct-desc"><?= e($paso['desc']) ?></span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <!-- ══ Datos de la cita ══ -->
    <div class="cita-bloque">
      <h2 class="cita-bloque-titulo">Detalle de la cita</h2>
      <div class="pago-detalle">
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Fecha</span>
          <span class="pago-detalle-valor"><?= e(formatDate($cita['date'])) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Horario</span>
          <span class="pago-detalle-valor"><?= e(formatTime($cita['time_start'])) ?> – <?= e(formatTime($cita['time_end'])) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Duración</span>
          <span class="pago-detalle-valor"><?= (int)$cita['total_duration'] ?> minutos</span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Profesional</span>
          <span class="pago-detalle-valor">
            <?= $cita['staff_nombre'] ? e($cita['staff_nombre']) : 'Se asignará al confirmar' ?>
          </span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label"><?= $esPersonal ? 'Cliente' : 'A nombre de' ?></span>
          <span class="pago-detalle-valor"><?= e($cita['cliente_nombre']) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Teléfono</span>
          <span class="pago-detalle-valor"><?= e($cita['cliente_telefono']) ?></span>
        </div>
        <?php if ($cita['cliente_correo']): ?>
          <div class="pago-detalle-fila">
            <span class="pago-detalle-label">Correo</span>
            <span class="pago-detalle-valor"><?= e($cita['cliente_correo']) ?></span>
          </div>
        <?php endif; ?>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Recordatorio por WhatsApp</span>
          <span class="pago-detalle-valor"><?= (int)$cita['whatsapp_reminder'] === 1 ? 'Activado' : 'Desactivado' ?></span>
        </div>
      </div>
    </div>

    <!-- ══ Servicios ══ -->
    <div class="cita-bloque">
      <h2 class="cita-bloque-titulo">Servicio<?= count($servicios) > 1 ? 's' : '' ?></h2>
      <?php if (empty($servicios)): ?>
        <p class="cita-vacio">Esta cita no tiene servicios asociados.</p>
      <?php else: ?>
        <div class="cita-servicios">
          <?php foreach ($servicios as $s): ?>
            <div class="cs-fila">
              <span class="cs-nombre"><?= e($s['name']) ?><em><?= (int)$s['duration_min'] ?> min</em></span>
              <span class="cs-precio"><?= (float)$s['price'] > 0 ? e(formatPrice((float)$s['price'])) : 'Sin costo' ?></span>
            </div>
          <?php endforeach; ?>
          <div class="cs-fila cs-fila--total">
            <span>Total</span>
            <span><?= $total > 0 ? e(formatPrice($total)) : 'Sin costo' ?></span>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ══ Estado de los pagos ══ -->
    <div class="cita-bloque">
      <h2 class="cita-bloque-titulo">Pagos</h2>

      <?php if ($total <= 0): ?>
        <!-- Cita sin costo (la valoración): no hay abono ni saldo que mostrar -->
        <div class="cita-aviso cita-aviso--ok">
          <span class="cita-aviso-icon">✓</span>
          <div>
            <strong>Esta cita no tiene costo</strong>
            No requiere abono ni pago en el centro.
          </div>
        </div>

      <?php elseif ($cita['status'] === 'cancelled'): ?>
        <!-- Cita cancelada: lo único pendiente es la devolución del abono, si aplica -->
        <?php if (!$abonoCita): ?>
          <p class="cita-vacio">La cita se canceló sin abono pagado: no hay nada pendiente.</p>

        <?php elseif ($abonoCita['refund_status'] === 'done'): ?>
          <div class="cita-aviso cita-aviso--ok">
            <span class="cita-aviso-icon">✓</span>
            <div>
              <strong>Abono de <?= e(formatPrice((float)$abonoCita['amount'])) ?> devuelto</strong>
              Se registró la devolución el <?= e(formatDate(substr((string)$abonoCita['refunded_at'], 0, 10))) ?>.
            </div>
          </div>

        <?php elseif ($abonoCita['refund_status'] === 'pending'): ?>
          <div class="cita-aviso">
            <span class="cita-aviso-icon">↩</span>
            <div>
              <strong>Abono de <?= e(formatPrice((float)$abonoCita['amount'])) ?> por devolver</strong>
              <?php if ($esPersonal): ?>
                Devuélvelo desde el panel de Wompi (referencia <?= e($abonoCita['reference']) ?>) y regístralo aquí.
              <?php else: ?>
                Como cancelaste a tiempo, te devolveremos el abono al mismo medio de pago en los próximos días hábiles.
              <?php endif; ?>
            </div>
          </div>
          <?php if ($esAdmin): ?>
            <form method="POST" class="cita-devolucion" onsubmit="return confirm('¿Ya devolviste el abono en el panel de Wompi? Se registrará el egreso en Finanzas.')">
              <input type="hidden" name="action" value="registrar_devolucion">
              <input type="hidden" name="pago_id" value="<?= (int)$abonoCita['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <button type="submit" class="btn-home btn-home--primary">Ya lo devolví: registrar devolución</button>
            </form>
          <?php endif; ?>

        <?php else: ?>
          <div class="cita-aviso cita-aviso--alerta">
            <span class="cita-aviso-icon">!</span>
            <div>
              <strong>El abono de <?= e(formatPrice((float)$abonoCita['amount'])) ?> no se devuelve</strong>
              La cita se canceló con menos de <?= (int)$minimoCancelacion ?> horas de anticipación.
            </div>
          </div>
          <?php if ($esAdmin): ?>
            <form method="POST" class="cita-devolucion" onsubmit="return confirm('¿Devolver este abono aunque la cancelación fue tardía?')">
              <input type="hidden" name="action" value="marcar_devolucion">
              <input type="hidden" name="pago_id" value="<?= (int)$abonoCita['id'] ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <button type="submit" class="btn-home">Devolverlo de todas formas</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>

      <?php else: ?>

      <div class="pago-montos">
        <div class="pago-monto">
          <span class="pago-monto-label">Abono pagado</span>
          <span class="pago-monto-valor"><?= e(formatPrice($resumen['abono'])) ?></span>
        </div>
        <div class="pago-monto">
          <span class="pago-monto-label">Saldo pagado</span>
          <span class="pago-monto-valor"><?= e(formatPrice($resumen['saldo_pagado'])) ?></span>
        </div>
        <div class="pago-monto <?= $resumen['esta_saldada'] ? 'pago-monto--ok' : 'pago-monto--pendiente' ?>">
          <span class="pago-monto-label">Pendiente por pagar</span>
          <span class="pago-monto-valor pago-monto-abono"><?= e(formatPrice($resumen['pendiente'])) ?></span>
        </div>
      </div>

      <?php if ($resumen['esta_saldada'] && $total > 0): ?>
        <div class="cita-aviso cita-aviso--ok">
          <span class="cita-aviso-icon">✓</span>
          <div>
            <strong>Esta cita está totalmente pagada</strong>
            <?php if ($recibidoPor): ?>
              El saldo fue recibido por <?= e(implode(', ', $recibidoPor)) ?>.
            <?php else: ?>
              No queda ningún valor pendiente.
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($resumen['pendiente'] > 0): ?>
        <div class="cita-aviso">
          <span class="cita-aviso-icon">💳</span>
          <div>
            <strong>Queda un saldo de <?= e(formatPrice($resumen['pendiente'])) ?></strong>
            Se paga en el centro el día de la cita. Aceptamos efectivo, tarjeta, Nequi y transferencia.
          </div>
        </div>
      <?php endif; ?>

      <?php endif; /* $total > 0 */ ?>

      <!-- Historial de pagos -->
      <?php if (!empty($pagos)): ?>
        <div class="cita-pagos">
          <?php foreach ($pagos as $p):
            // Al cliente solo se le muestran los pagos que realmente se hicieron.
            if (!$esPersonal && $p['status'] !== 'approved') continue;
          ?>
            <div class="cp-fila">
              <div class="cp-info">
                <span class="cp-tipo cp-tipo--<?= e($p['kind']) ?>"><?= e(etiquetaTipoPago($p['kind'])) ?></span>
                <span class="cp-medio">
                  <?= e($p['payment_method'] ?: ($p['provider'] === 'wompi' ? 'En línea' : 'Sin medio')) ?>
                  <?php if ($p['recibido_por_nombre']): ?>
                    · recibido por <strong><?= e($p['recibido_por_nombre']) ?></strong>
                  <?php endif; ?>
                </span>
                <span class="cp-fecha">
                  <?= e(formatDate(substr((string)($p['paid_at'] ?: $p['created_at']), 0, 10))) ?>
                  <?php if ($esPersonal): ?> · <?= e($p['reference']) ?><?php endif; ?>
                  <?php if ($esPersonal && $p['note']): ?> · <?= e($p['note']) ?><?php endif; ?>
                </span>
              </div>
              <div class="cp-derecha">
                <span class="cp-monto"><?= e(formatPrice((float)$p['amount'])) ?></span>
                <span class="cp-estado cp-estado--<?= $p['status'] === 'approved' ? 'ok' : ($p['status'] === 'pending' ? 'wait' : 'no') ?>">
                  <?= e(etiquetaEstadoPago($p['status'])) ?>
                </span>
                <?php if (($p['refund_status'] ?? 'none') !== 'none'): ?>
                  <span class="cp-estado cp-estado--<?= $p['refund_status'] === 'done' ? 'ok' : 'wait' ?>">
                    <?= e(etiquetaDevolucion($p['refund_status'])) ?>
                  </span>
                <?php endif; ?>
                <?php if ($esAdmin && $p['provider'] === 'manual'): ?>
                  <form method="POST" class="cp-anular" onsubmit="return confirm('¿Anular este pago? También se borrará el ingreso en Finanzas.')">
                    <input type="hidden" name="action" value="anular_pago">
                    <input type="hidden" name="pago_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <button type="submit">Anular</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($total > 0 && $cita['status'] !== 'cancelled'): ?>
        <p class="cita-vacio">Todavía no hay pagos registrados para esta cita.</p>
      <?php endif; ?>
    </div>

    <?php if ($esPersonal): ?>
      <!-- ══════ BLOQUES SOLO PARA EL PERSONAL ══════ -->

      <!-- Registrar el saldo -->
      <div class="cita-bloque cita-bloque--staff">
        <h2 class="cita-bloque-titulo">Registrar pago del saldo</h2>

        <?php if ($resumen['ingreso_externo'] > 0): ?>
          <div class="cita-aviso cita-aviso--alerta">
            <span class="cita-aviso-icon">!</span>
            <div>
              <strong>Incluye <?= e(formatPrice($resumen['ingreso_externo'])) ?> registrados en Finanzas al completar la cita</strong>
              Ese dinero ya cuenta como saldo pagado, pero sin detalle de medio de pago ni de quién lo recibió.
            </div>
          </div>
        <?php endif; ?>

        <?php if (!$puedeRegistrar): ?>
          <p class="cita-vacio">Solo un administrador o el profesional asignado a esta cita pueden registrar su pago.</p>

        <?php elseif ($cita['status'] === 'cancelled'): ?>
          <p class="cita-vacio">La cita está cancelada: no se registran pagos del saldo.</p>

        <?php elseif ($total <= 0): ?>
          <p class="cita-vacio">Esta cita no tiene costo: no hay nada que cobrar.</p>

        <?php elseif ($resumen['pendiente'] <= 0): ?>
          <p class="cita-vacio">No hay saldo pendiente: la cita ya está pagada por completo.</p>

        <?php else: ?>
          <form method="POST" class="cita-form">
            <div class="cf-grid">
              <div class="cf-campo">
                <label for="monto">Monto recibido (COP)</label>
                <input type="number" id="monto" name="monto" min="1" step="1000"
                       max="<?= (int)ceil($resumen['pendiente']) ?>"
                       value="<?= (int)ceil($resumen['pendiente']) ?>" required>
                <span class="cf-hint">Saldo pendiente: <?= e(formatPrice($resumen['pendiente'])) ?></span>
              </div>

              <div class="cf-campo">
                <label for="medio">Medio de pago</label>
                <select id="medio" name="medio" required>
                  <?php foreach (mediosDePagoManual() as $m): ?>
                    <option value="<?= e($m) ?>"><?= e($m) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="cf-campo">
                <label for="recibido_por">¿A quién se le pagó?</label>
                <select id="recibido_por" name="recibido_por" required>
                  <?php foreach ($equipo as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$usuario['id'] ? 'selected' : '' ?>>
                      <?= e($u['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="cf-hint">Queda registrado en el comprobante del cliente.</span>
              </div>

              <div class="cf-campo cf-campo--ancho">
                <label for="nota">Observación (opcional)</label>
                <input type="text" id="nota" name="nota" maxlength="255"
                       placeholder="Ej: pagó en dos partes, dejó propina…">
              </div>
            </div>

            <input type="hidden" name="action" value="registrar_saldo">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button type="submit" class="cita-btn-primario">Registrar pago del saldo</button>
            <p class="cf-pie">
              Se crea el ingreso en Finanzas automáticamente. Marcar la cita como
              <em>completada</em> se sigue haciendo desde el módulo de Citas.
            </p>
          </form>
        <?php endif; ?>
      </div>

      <!-- Notas internas -->
      <div class="cita-bloque cita-bloque--staff">
        <h2 class="cita-bloque-titulo">Notas internas</h2>
        <?php if (trim((string)$cita['notes']) !== ''): ?>
          <pre class="cita-notas"><?= e($cita['notes']) ?></pre>
        <?php else: ?>
          <p class="cita-vacio">Sin notas. Se editan desde el módulo de Citas.</p>
        <?php endif; ?>
      </div>

      <!-- Enlace del cliente -->
      <div class="cita-bloque cita-bloque--staff">
        <h2 class="cita-bloque-titulo">Enlace para el cliente</h2>
        <p class="cita-ayuda">Compártelo por WhatsApp: el cliente ve el estado de su cita y sus pagos sin necesidad de cuenta.</p>
        <div class="cita-enlace">
          <input type="text" id="enlaceCliente" value="<?= e($enlaceCliente) ?>" readonly onclick="this.select()">
          <button type="button" id="btnCopiarEnlace">Copiar</button>
        </div>
        <a class="cita-wa"
           href="https://wa.me/<?= e(preg_replace('/\D+/', '', $cita['cliente_telefono'])) ?>?text=<?= rawurlencode('Hola ' . $cita['cliente_nombre'] . ', aquí puedes ver el estado de tu cita en Blue Therapy: ' . $enlaceCliente) ?>"
           target="_blank" rel="noopener">
          Enviar por WhatsApp a <?= e($cita['cliente_telefono']) ?>
        </a>
      </div>

    <?php else: ?>
      <!-- ══════ PIE PARA EL CLIENTE ══════ -->
      <div class="cita-bloque">
        <h2 class="cita-bloque-titulo">¿Necesitas cambiar algo?</h2>
        <?php if (in_array($cita['status'], ['pending', 'confirmed'], true)): ?>
          <p class="cita-ayuda">
            Escríbenos y reprogramamos tu cita sin costo. También puedes cancelarla desde aquí hasta
            <strong><?= (int)$minimoCancelacion ?> horas antes</strong>.
            <?php if ($abonoCita): ?>
              Si cancelas a tiempo te devolvemos el abono; después de ese plazo, el abono no es reembolsable.
            <?php endif; ?>
          </p>
        <?php else: ?>
          <p class="cita-ayuda">¿Quieres volver a agendar? Reserva una nueva cita cuando quieras.</p>
        <?php endif; ?>

        <div class="pago-acciones" style="justify-content:flex-start;margin-top:16px">
          <a href="https://wa.me/<?= e(telefonoNegocioDigitos()) ?>?text=<?= rawurlencode('Hola, quiero consultar mi cita #' . $citaId) ?>"
             class="btn-home btn-home--primary" target="_blank" rel="noopener">Escribir por WhatsApp</a>
          <a href="/Blue/booking.php" class="btn-home">Reservar otra cita</a>
        </div>

        <?php if ($cancelacion['puede']): ?>
          <details class="cita-cancelar">
            <summary>Cancelar mi cita</summary>
            <form method="POST" class="cita-cancelar-form"
                  onsubmit="return confirm('¿Seguro que quieres cancelar tu cita? Tu horario quedará libre para otra persona.')">
              <input type="hidden" name="action" value="cancelar_cita">
              <input type="hidden" name="t" value="<?= e($token) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <label for="motivoCancelacion">¿Nos cuentas por qué? <span>(opcional)</span></label>
              <textarea id="motivoCancelacion" name="motivo" rows="2" maxlength="255"
                        placeholder="Ej: me salió un viaje, prefiero otro día…"></textarea>
              <button type="submit" class="btn-cancelar-cita">Sí, cancelar mi cita</button>
            </form>
          </details>
        <?php elseif (in_array($cita['status'], ['pending', 'confirmed'], true) && $cancelacion['motivo']): ?>
          <p class="cita-cancelar-no"><?= e($cancelacion['motivo']) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>

  <?php if (!$esPersonal): ?>
    <p class="cita-pie">Guarda este enlace: aquí puedes consultar el estado de tu cita cuando quieras.</p>
  <?php endif; ?>
</main>

<?php if ($esPersonal): ?>
<script>
// Copiar el enlace del cliente al portapapeles.
document.getElementById('btnCopiarEnlace')?.addEventListener('click', async function () {
  const campo = document.getElementById('enlaceCliente');
  campo.select();
  try {
    await navigator.clipboard.writeText(campo.value);
  } catch (e) {
    document.execCommand('copy');   // navegadores sin permiso de portapapeles
  }
  const textoOriginal = this.textContent;
  this.textContent = '¡Copiado!';
  this.classList.add('copiado');
  setTimeout(() => { this.textContent = textoOriginal; this.classList.remove('copiado'); }, 1800);
});
</script>
<?php endif; ?>

<?php endif; ?>

</body>
</html>
