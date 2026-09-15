<?php
// ============================================================
// Blue Therapy — Resultado del pago del abono
// ------------------------------------------------------------
// Aquí aterriza el cliente cuando vuelve del checkout de Wompi.
// Se consulta la transacción contra la API (no se cree nada de lo
// que venga en la URL) y, si está aprobada, la cita se confirma sola.
//
// A propósito NO se abre sesión: la cookie de sesión es SameSite=Strict,
// así que el navegador no la manda al volver desde checkout.wompi.co.
// Todo lo que hace falta sale del id de transacción.
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/h-reservas.php';
require_once __DIR__ . '/includes/h-pagos.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

$transaccionId = trim((string)($_GET['id'] ?? ''));

// approved · approved_sin_cupo · pending · declined · voided · expired · error · sin_id · desconocido
// Sin id de transacción lo normal es que el cliente saliera del checkout antes de pagar.
$estado    = $transaccionId === '' ? 'sin_id' : 'desconocido';
$db        = null;   // solo se usa si hubo conexión: $pago y $cita dependen de ella
$pago      = null;
$cita      = null;
$servicios = [];

try {
    $db = getDB();

    if ($transaccionId !== '' && preg_match('/^[A-Za-z0-9\-_]{6,64}$/', $transaccionId)) {

        // La transacción se consulta contra Wompi: la URL de retorno la
        // controla el navegador y no sirve como prueba de que se pagó.
        $transaccion = consultarTransaccionWompi($transaccionId);
        $referencia  = (string)($transaccion['reference'] ?? '');

        if ($referencia !== '') {
            $pago = buscarPagoPorReferencia($db, $referencia);
        }
        if (!$pago) {
            // La API pudo no responder; se intenta por el id ya guardado.
            $pago = buscarPagoPorTransaccion($db, $transaccionId);
        }

        if ($pago && $transaccion) {
            $resultado = aplicarResultadoPago($db, $pago, $transaccion);
            $estado    = $resultado['status'];
            $pago      = buscarPagoPorReferencia($db, $pago['reference']);
        } elseif ($pago) {
            $estado = $pago['status'];
        }
    }

    if ($pago) {
        // 'approved_sin_cupo' no existe en la BD: lo devuelve aplicarResultadoPago()
        // solo para avisar en pantalla. En `payments` el pago sí quedó aprobado.
        if ($estado === 'desconocido') $estado = $pago['status'];

        $stmt = $db->prepare(
            'SELECT a.*, c.name AS client_name
               FROM appointments a
               JOIN clients c ON c.id = a.client_id
              WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$pago['appointment_id']]);
        $cita = $stmt->fetch() ?: null;

        $stmt = $db->prepare(
            'SELECT s.name, s.price, s.duration_min
               FROM appointment_services aps
               JOIN services s ON s.id = aps.service_id
              WHERE aps.appointment_id = ?'
        );
        $stmt->execute([$pago['appointment_id']]);
        $servicios = $stmt->fetchAll();
    }

} catch (Exception $e) {
    error_log('[Blue/Wompi] Error mostrando el resultado del pago: ' . $e->getMessage());
    $estado = 'error';
}

// ── Mensaje según el estado ───────────────────────────────
$vistas = [
    'approved' => [
        'clase'    => 'ok',
        'icono'    => '✓',
        'titulo'   => '¡Tu cita quedó confirmada!',
        'texto'    => 'Recibimos tu abono y tu horario ya está reservado a tu nombre. Te esperamos.',
    ],
    'approved_sin_cupo' => [
        'clase'    => 'aviso',
        'icono'    => '!',
        'titulo'   => 'Recibimos tu pago',
        'texto'    => 'Tu abono se aprobó, pero el horario que elegiste se ocupó mientras completabas el pago. Te contactaremos por WhatsApp para reprogramar tu cita o devolverte el dinero.',
    ],
    'pending' => [
        'clase'    => 'aviso',
        'icono'    => '⏳',
        'titulo'   => 'Tu pago está en proceso',
        'texto'    => 'Algunos medios de pago (como PSE) tardan unos minutos en confirmarse. Estamos guardando tu horario; apenas se acredite el abono, tu cita se confirma automáticamente.',
    ],
    'declined' => [
        'clase'    => 'error',
        'icono'    => '✕',
        'titulo'   => 'El pago fue rechazado',
        'texto'    => 'Tu banco no autorizó la transacción, así que la cita no quedó agendada. Puedes intentarlo de nuevo con otro medio de pago.',
    ],
    'voided' => [
        'clase'    => 'error',
        'icono'    => '✕',
        'titulo'   => 'El pago fue anulado',
        'texto'    => 'La transacción se anuló y la cita no quedó agendada. Puedes volver a intentarlo cuando quieras.',
    ],
    'expired' => [
        'clase'    => 'error',
        'icono'    => '✕',
        'titulo'   => 'Se venció el tiempo para pagar',
        'texto'    => 'Liberamos tu horario para que otra persona pudiera tomarlo. Vuelve a elegir fecha y hora para reservar.',
    ],
    'error' => [
        'clase'    => 'error',
        'icono'    => '✕',
        'titulo'   => 'No pudimos completar el pago',
        'texto'    => 'Hubo un problema con la transacción y la cita no quedó agendada. Intenta de nuevo o escríbenos por WhatsApp.',
    ],
    'sin_id' => [
        'clase'    => 'aviso',
        'icono'    => '!',
        'titulo'   => 'El pago no se completó',
        'texto'    => 'Parece que saliste del checkout antes de terminar. No se cobró nada; si tenías un horario apartado, se libera solo en unos minutos. Puedes volver a reservar cuando quieras.',
    ],
    'desconocido' => [
        'clase'    => 'error',
        'icono'    => '?',
        'titulo'   => 'No encontramos este pago',
        'texto'    => 'No pudimos identificar la transacción. Si el dinero salió de tu cuenta, escríbenos por WhatsApp con el comprobante y lo revisamos enseguida.',
    ],
];
$vista = $vistas[$estado] ?? $vistas['desconocido'];

$totalServicios = array_sum(array_map(fn($s) => (float)$s['price'], $servicios));
// Lo pendiente sale de todos los pagos aprobados (abono y saldos), igual que en cita.php:
// si el cliente vuelve a esta página después de pagar parte en el centro, no ve un saldo viejo.
$saldoPendiente = ($pago && $cita)
    ? resumenPagosCita($db, (int)$cita['id'], $totalServicios)['pendiente']
    : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resultado del pago — Blue Therapy</title>
  <meta name="robots" content="noindex">
  <?php if ($estado === 'pending'): ?>
  <!-- Los pagos por PSE se acreditan con demora: la página se refresca sola. -->
  <meta http-equiv="refresh" content="10">
  <?php endif; ?>
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

<main class="pago-wrap">
  <div class="pago-card">

    <div class="pago-status pago-status--<?= e($vista['clase']) ?>">
      <div class="pago-status-icon"><?= $vista['icono'] ?></div>
      <h1 class="pago-status-title"><?= e($vista['titulo']) ?></h1>
      <p class="pago-status-text"><?= e($vista['texto']) ?></p>
    </div>

    <?php if ($cita && in_array($estado, ['approved', 'approved_sin_cupo', 'pending'], true)): ?>
      <div class="pago-detalle">
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Cita</span>
          <span class="pago-detalle-valor">#<?= (int)$cita['id'] ?> · <?= e($cita['client_name']) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Servicio<?= count($servicios) > 1 ? 's' : '' ?></span>
          <span class="pago-detalle-valor"><?= e(implode(', ', array_column($servicios, 'name'))) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Fecha</span>
          <span class="pago-detalle-valor"><?= e(formatDate($cita['date'])) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Hora</span>
          <span class="pago-detalle-valor"><?= e(formatTime($cita['time_start'])) ?> – <?= e(formatTime($cita['time_end'])) ?></span>
        </div>
        <div class="pago-detalle-fila">
          <span class="pago-detalle-label">Estado de la cita</span>
          <span class="pago-detalle-valor"><?= e(statusLabel($cita['status'])['label']) ?></span>
        </div>
      </div>

      <div class="pago-montos">
        <div class="pago-monto">
          <span class="pago-monto-label">Abono <?= $estado === 'pending' ? 'en proceso' : 'pagado' ?></span>
          <span class="pago-monto-valor pago-monto-abono"><?= e(formatPrice((float)$pago['amount'])) ?></span>
        </div>
        <div class="pago-monto">
          <span class="pago-monto-label">Saldo el día de la cita</span>
          <span class="pago-monto-valor"><?= e(formatPrice($saldoPendiente)) ?></span>
        </div>
        <div class="pago-monto">
          <span class="pago-monto-label">Total del servicio</span>
          <span class="pago-monto-valor"><?= e(formatPrice($totalServicios)) ?></span>
        </div>
      </div>

      <p class="pago-referencia">
        Referencia de pago: <strong><?= e($pago['reference']) ?></strong>
        <?php if (!empty($pago['payment_method'])): ?> · <?= e($pago['payment_method']) ?><?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="pago-acciones">
      <?php if (in_array($estado, ['approved', 'approved_sin_cupo', 'pending'], true) && $cita): ?>
        <a href="<?= e(urlEstadoCita($db, (int)$cita['id'])) ?>" class="btn-home btn-home--primary">
          Ver el estado de mi cita
        </a>
      <?php endif; ?>
      <?php if (in_array($estado, ['approved', 'approved_sin_cupo'], true)): ?>
        <a href="/Blue/" class="btn-home">← Volver al inicio</a>
      <?php elseif ($estado === 'pending'): ?>
        <span class="pago-espera"><span class="spinner"></span> Verificando el pago…</span>
        <a href="/Blue/" class="btn-home">Volver al inicio</a>
      <?php else: ?>
        <a href="/Blue/booking.php" class="btn-home btn-home--primary">Intentar de nuevo</a>
        <a href="/Blue/" class="btn-home">Volver al inicio</a>
      <?php endif; ?>
    </div>

  </div>
</main>

</body>
</html>
