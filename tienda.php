<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/h-negocio.php';
require_once __DIR__ . '/includes/h-pedidos.php';   // trae h-productos y los pagos

// Cabeceras de seguridad
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Catálogo. Antes se devuelve al inventario lo apartado por pedidos que nunca se pagaron.
$productos = [];
try {
    $db = getDB();
    liberarPedidosVencidos($db);
    $productos = productosParaTienda($db);
} catch (Throwable $e) {
    error_log('[Blue/Tienda] No se pudo cargar el catálogo: ' . $e->getMessage());
}

$pagoEnLinea  = pedidosPagoEnLinea();
$correoActivo = correoActivo();

// Lo único que necesita tienda.js de cada producto (los precios solo se muestran: el cobro lo calcula el servidor).
$productosJs = array_map(fn($p) => [
    'id'      => $p['id'],
    'name'    => $p['name'],
    'price'   => $p['price'],
    'stock'   => $p['stock'],
    'agotado' => $p['agotado'],
    'image'   => urlImagenProducto($p['image'] ?? null),
], $productos);
$configJs = [
    'productos'    => $productosJs,
    'pagoEnLinea'  => $pagoEnLinea,
    'maxUnidades'  => PEDIDO_MAX_UNIDADES_LINEA,
    'correoActivo' => $correoActivo,
];
$icoBolsa = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tienda — Blue Therapy</title>
  <meta name="description" content="Productos de Blue Therapy: arma tu pedido en línea y recógelo en el centro.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/Blue/assets/css/tienda.css?v=<?= @filemtime(__DIR__ . '/assets/css/tienda.css') ?>">
  <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
</head>
<body class="tienda-page">

<!-- ── Barra superior ── -->
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
    <a href="/Blue/booking.php" class="t-nav-extra">Agendar cita</a>
    <a href="/Blue/">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>Volver al inicio
    </a>
  </div>
</nav>

<!-- ── Cabecera ── -->
<header class="t-hero">
  <div class="t-eyebrow">Tienda</div>
  <h1>Cuida tu piel también <em>en casa</em></h1>
  <p>Elige tus productos, arma tu pedido y recógelo en el centro cuando esté listo. Te avisamos apenas lo alistemos.</p>
  <div class="t-hero-pickup">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Recoge en <?= e(datoNegocio('direccion')) ?>
  </div>
</header>

<!-- ── Catálogo ── -->
<main class="t-wrap">
  <?php if (!$productos): ?>
    <div class="t-vacio">
      <h2>Estamos preparando la tienda</h2>
      <p>Por ahora no hay productos disponibles. Vuelve pronto o escríbenos por WhatsApp.</p>
      <a href="/Blue/" class="btn-sec" style="margin-top:14px">← Volver al inicio</a>
    </div>
  <?php else: ?>
    <div class="t-grid">
      <?php foreach ($productos as $p):
        $img   = urlImagenProducto($p['image'] ?? null);
        $pocas = productoConPocoStock($p); ?>
        <article class="prd-card" id="producto-<?= (int)$p['id'] ?>" data-id="<?= (int)$p['id'] ?>">
          <div class="prd-img">
            <?php if ($img): ?>
              <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            <?php else: ?>
              <div class="prd-img-vacia"><?= $icoBolsa ?></div>
            <?php endif; ?>
            <?php if ($p['agotado']): ?>
              <span class="prd-etiqueta prd-etiqueta--agotado">Agotado</span>
            <?php elseif ($pocas): ?>
              <span class="prd-etiqueta prd-etiqueta--poco">Últimas <?= (int)$p['stock'] ?></span>
            <?php endif; ?>
          </div>
          <div class="prd-body">
            <h3><?= e($p['name']) ?></h3>
            <?php if (!empty($p['description'])): ?><p><?= e($p['description']) ?></p><?php endif; ?>
            <div class="prd-foot">
              <span class="prd-price"><?= e(formatPrice($p['price'])) ?></span>
              <div class="prd-actions"></div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<!-- ── Botón flotante del pedido ── -->
<button type="button" class="cart-fab is-empty" id="cart-fab" aria-label="Ver mi pedido" aria-controls="cart-panel">
  <?= $icoBolsa ?>
  <span id="cart-fab-total">Mi pedido</span>
  <span class="cart-fab-n" id="cart-fab-n">0</span>
</button>

<!-- ── Panel del pedido ── -->
<div class="cart-overlay" id="cart-overlay"></div>
<aside class="cart-panel" id="cart-panel" role="dialog" aria-modal="true" aria-labelledby="cart-titulo" aria-hidden="true">
  <div class="cart-head">
    <h2 id="cart-titulo">Tu pedido</h2>
    <button type="button" class="cart-close" id="cart-close" aria-label="Cerrar">&times;</button>
  </div>

  <div class="cart-scroll">
    <div class="cart-vacio" id="cart-vacio">
      Todavía no has agregado productos.<br>
      <button type="button" class="btn-sec" id="cart-seguir" style="margin-top:16px">Ver productos</button>
    </div>

    <div id="cart-resumen">
      <div id="cart-items"></div>
      <div class="cart-totales"><span>Total</span><strong id="cart-total">$0</strong></div>
      <div class="cart-entrega">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <div>
          <strong>Recoges en el centro</strong><br>
          <?= e(datoNegocio('direccion')) ?> · <?= e(datoNegocio('horario')) ?>
          <small>Envío a domicilio: próximamente.</small>
        </div>
      </div>
    </div>

    <form id="cart-form" novalidate hidden>
      <div class="cart-mensaje" id="cart-mensaje" role="alert" hidden></div>

      <div class="t-campo">
        <label for="f-nombre">Nombre completo *</label>
        <input type="text" id="f-nombre" autocomplete="name" placeholder="Tu nombre completo">
        <span class="t-error">Escribe tu nombre.</span>
      </div>
      <div class="t-campo">
        <label for="f-telefono">Teléfono / WhatsApp *</label>
        <input type="tel" id="f-telefono" autocomplete="tel" placeholder="+57 300 000 0000">
        <span class="t-error">Escribe un teléfono válido (con código de país si no es de Colombia).</span>
      </div>
      <div class="t-campo">
        <label for="f-correo">Correo electrónico</label>
        <input type="email" id="f-correo" autocomplete="email" placeholder="opcional">
        <span class="t-error">El correo no parece válido.</span>
      </div>
      <div class="t-campo">
        <label for="f-nota">Nota para el pedido</label>
        <textarea id="f-nota" rows="2" maxlength="<?= PEDIDO_NOTA_MAX ?>" placeholder="¿Algo que debamos saber?"></textarea>
      </div>

      <label class="t-aviso">
        <input type="checkbox" id="f-whatsapp" checked>
        <span><strong>Avisarme por WhatsApp</strong><small>Te escribimos cuando tu pedido esté listo para recoger.</small></span>
      </label>
      <?php if ($correoActivo): ?>
      <label class="t-aviso" id="aviso-correo" hidden>
        <input type="checkbox" id="f-correo-aviso" checked>
        <span><strong>Avisarme por correo</strong><small>Te enviamos la confirmación y el aviso de que está listo.</small></span>
      </label>
      <?php endif; ?>
    </form>

    <!-- Pedido enviado (sin pasarela): el equipo coordina el pago -->
    <div class="cart-exito" id="cart-exito" hidden>
      <div class="cart-exito-icono">✓</div>
      <h3>¡Pedido recibido! <span id="exito-numero"></span></h3>
      <p>Te contactaremos por WhatsApp para coordinar el pago. Cuando lo recibamos empezamos a prepararlo y te avisamos apenas esté listo.</p>
      <a href="#" id="exito-enlace" class="btn-sec btn-sec--primario">Ver el estado de mi pedido</a>
    </div>
  </div>

  <div class="cart-foot" id="cart-foot" hidden>
    <button type="submit" form="cart-form" class="btn-pedido" id="btn-pedido">
      <?= $icoBolsa ?><span id="btn-pedido-label"><?= $pagoEnLinea ? 'Pagar' : 'Enviar pedido' ?></span>
    </button>
    <p class="cart-legal">
      <?php if ($pagoEnLinea): ?>
        Te llevaremos al checkout seguro de <strong>Wompi</strong> (tarjetas, PSE, Nequi y Bancolombia). Guardamos tus productos unos minutos mientras pagas.
      <?php else: ?>
        Sin pagar ahora: el equipo te contacta para coordinar el pago y la entrega.
      <?php endif; ?>
    </p>
  </div>
</aside>

<script>
// Catálogo y reglas que usa tienda.js (el servidor vuelve a validar todo al crear el pedido).
window.BLUE_TIENDA = <?= json_encode($configJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/Blue/assets/js/tienda.js?v=<?= @filemtime(__DIR__ . '/assets/js/tienda.js') ?>"></script>
</body>
</html>
