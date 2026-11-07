<?php
// ============================================================
// Blue Therapy — Seguimiento de un pedido de la tienda
// ------------------------------------------------------------
// Enlace privado (id + token) que el cliente recibe al pedir y desde el
// correo/WhatsApp. A propósito NO abre sesión: al volver del checkout de
// Wompi la cookie SameSite=Strict no viajaría, y el token ya autentica.
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/h-negocio.php';
require_once __DIR__ . '/includes/h-pedidos.php';
require_once __DIR__ . '/includes/h-iconos.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

$pedido = null;
$items  = [];
$verificandoPago = false;

try {
    $db = getDB();
    liberarPedidosVencidos($db);   // si el tiempo de pago se acabó, el pedido ya aparece cancelado

    $id      = (int)($_GET['id'] ?? 0);
    $token   = (string)($_GET['t'] ?? '');
    $buscado = $id > 0 ? pedidoPorId($db, $id) : null;

    // Sin el token correcto la página responde igual que si el pedido no existiera.
    if ($buscado && tokenPedidoEsValido($buscado, $token)) {
        $pedido = $buscado;
        $items  = itemsDePedido($db, $id);

        // Algunos medios (PSE) tardan en acreditarse: hay un pago en línea esperando respuesta.
        $verificandoPago = $pedido['status'] === 'pending_payment' && (bool)array_filter(
            pagosDePedido($db, $id),
            fn($p) => $p['kind'] === 'online' && $p['status'] === 'pending'
        );
    }
} catch (Throwable $e) {
    error_log('[Blue/Tienda] Error mostrando el pedido: ' . $e->getMessage());
}

if (!$pedido) http_response_code(404);

// ── Mensaje según el estado ───────────────────────────────
$estado = $pedido['status'] ?? '';
$vistas = [
    'pending_payment' => ['', iconoSvg('nota', 28), 'Recibimos tu pedido',          'Falta confirmar el pago. Te contactaremos por WhatsApp para coordinarlo y, apenas lo recibamos, empezamos a prepararlo.'],
    'paid'            => ['', '✓',  'Pago recibido',                'Gracias. En breve empezamos a preparar tu pedido y te avisaremos cuando esté listo para recoger.'],
    'preparing'       => ['', iconoSvg('brillo', 28), 'Estamos preparando tu pedido', 'Lo estamos alistando con cuidado. Te avisaremos apenas esté listo para recoger.'],
    'ready'           => ['listo', iconoSvg('bolsa', 28), '¡Tu pedido está listo!', 'Ya puedes pasar por él al centro. Te esperamos.'],
    'delivered'       => ['', iconoSvg('corazon', 28), 'Pedido entregado',             'Gracias por tu compra. ¡Esperamos que lo disfrutes!'],
    'cancelled'       => ['cancelado', '✕', 'Pedido cancelado',     'Este pedido fue cancelado. Si ya habías pagado o crees que es un error, escríbenos por WhatsApp y lo resolvemos enseguida.'],
];
if ($verificandoPago) {
    $vistas['pending_payment'] = ['', iconoSvg('reloj', 28), 'Estamos verificando tu pago', 'Algunos medios (como PSE) tardan unos minutos en confirmarse. Esta página se actualiza sola; apenas se acredite, empezamos a preparar tu pedido.'];
}
[$claseEstado, $icono, $titulo, $texto] = $vistas[$estado] ?? ['', '?', 'No encontramos este pedido', 'Revisa el enlace que recibiste o escríbenos por WhatsApp.'];
if (!$pedido) {
    $titulo = 'No encontramos este pedido';
    $texto  = 'El enlace no es válido o el pedido ya no existe. Si necesitas ayuda, escríbenos por WhatsApp.';
}

$pasos          = $pedido && $estado !== 'cancelled' ? pasosPedido($estado) : [];
$mostrarRecoger = in_array($estado, ['paid', 'preparing', 'ready'], true);
$telefonoWa     = telefonoNegocioDigitos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pedido ? 'Pedido #' . (int)$pedido['id'] : 'Pedido' ?> — Blue Therapy</title>
  <meta name="robots" content="noindex">
  <?php if ($verificandoPago): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/Blue/assets/css/tienda.css?v=<?= @filemtime(__DIR__ . '/assets/css/tienda.css') ?>">
</head>
<body class="tienda-page">

<nav class="t-nav">
  <a href="/Blue/" class="t-brand">
    <span class="t-brand-mark">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="#5bc4b8"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
    </span>
    <span>
      <span class="t-brand-name" style="display:block">Blue</span>
      <span class="t-brand-sub">THERAPY</span>
    </span>
  </a>
  <div class="t-nav-links">
    <a href="/Blue/tienda.php">Ir a la tienda</a>
  </div>
</nav>

<main class="ped-wrap">
  <div class="ped-card">

    <div class="ped-estado<?= $claseEstado ? ' ped-estado--' . e($claseEstado) : '' ?>">
      <?php if ($pedido): ?><div class="ped-num">Pedido #<?= (int)$pedido['id'] ?></div><?php endif; ?>
      <div class="ped-estado-icono"><?= $icono ?></div>
      <h1><?= e($titulo) ?></h1>
      <p><?= e($texto) ?></p>
      <?php if ($estado === 'cancelled' && !empty($pedido['cancel_reason'])): ?>
        <p style="margin-top:10px;font-size:13px">Motivo: <?= e($pedido['cancel_reason']) ?></p>
      <?php endif; ?>
    </div>

    <?php if ($pasos): ?>
      <div class="ped-pasos" aria-label="Avance del pedido">
        <?php foreach ($pasos as $paso): ?>
          <div class="ped-paso<?= $paso['hecho'] ? ' is-hecho' : '' ?><?= $paso['activo'] ? ' is-activo' : '' ?>">
            <div class="ped-paso-punto"><?= $paso['hecho'] ? '✓' : '' ?></div>
            <?= e($paso['label']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($pedido): ?>
      <div class="ped-bloque">
        <h2>Tu pedido</h2>
        <?php foreach ($items as $i): ?>
          <div class="ped-fila">
            <span><?= (int)$i['quantity'] ?> × <?= e($i['name']) ?></span>
            <span><?= e(formatPrice((float)$i['unit_price'] * (int)$i['quantity'])) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="ped-fila ped-fila--total"><span>Total</span><span><?= e(formatPrice((float)$pedido['total'])) ?></span></div>
      </div>

      <?php if ($mostrarRecoger): ?>
        <div class="ped-bloque ped-recoger">
          <h2>Dónde recogerlo</h2>
          <p>
            <strong><?= e(datoNegocio('nombre')) ?></strong><br>
            <?= e(datoNegocio('direccion')) ?><br>
            <?= e(datoNegocio('horario')) ?>
          </p>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="ped-acciones">
      <?php if ($telefonoWa !== ''): ?>
        <a class="btn-sec" target="_blank" rel="noopener"
           href="https://wa.me/<?= e($telefonoWa) ?>?text=<?= rawurlencode($pedido ? 'Hola, te escribo por mi pedido #' . (int)$pedido['id'] . '.' : 'Hola, necesito ayuda con mi pedido.') ?>">
          Escribirnos por WhatsApp
        </a>
      <?php endif; ?>
      <a class="btn-sec btn-sec--primario" href="/Blue/tienda.php">Seguir en la tienda</a>
    </div>

  </div>
</main>

</body>
</html>
