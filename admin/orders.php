<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-pedidos.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pedidoId = (int)($_POST['id'] ?? 0);
    $volver   = '/Blue/admin/orders.php?id=' . $pedidoId;

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: ' . $volver); exit;
    }

    try {
        switch ($_POST['action'] ?? '') {
            case 'pedido_estado':
                $r = cambiarEstadoPedido($db, $pedidoId, (string)($_POST['estado'] ?? ''));
                setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
                break;

            case 'pedido_pagar':
                $r = registrarPagoManualPedido($db, $pedidoId, (string)($_POST['medio'] ?? ''), (int)currentUser()['id']);
                setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
                break;

            case 'pedido_cancelar':
                $r = cancelarPedidoPorEquipo($db, $pedidoId, (string)($_POST['motivo'] ?? ''));
                setFlash($r['ok'] ? 'info' : 'error', $r['msg']);
                break;

            case 'pedido_reavisar':
                $pedido = pedidoPorId($db, $pedidoId);
                if ($pedido && $pedido['status'] === 'ready' && avisarPedidoAlCliente($db, $pedidoId, 'listo')) {
                    setFlash('success', 'Aviso reenviado al cliente.');
                } else {
                    setFlash('error', 'No se pudo avisar: el cliente no dejó correo ni WhatsApp para avisos. Escríbele tú.');
                }
                break;
        }
    } catch (Throwable $e) {
        error_log('[Blue/Tienda] Error en el panel de pedidos: ' . $e->getMessage());
        setFlash('error', 'Ocurrió un error. Intenta de nuevo.');
    }

    header('Location: ' . $volver); exit;   // patrón PRG
}

// ── Datos ─────────────────────────────────────────────────
liberarPedidosVencidos($db);   // anula los que no se pagaron a tiempo y libera su stock

$detalleId = (int)($_GET['id'] ?? 0);
$pedido    = $detalleId > 0 ? pedidoPorId($db, $detalleId) : null;
$estadoSel = (string)($_GET['estado'] ?? '');
if (!isset(estadosPedido()[$estadoSel])) $estadoSel = '';

$conteo  = conteoPedidosPorEstado($db);
$pedidos = $pedido ? [] : listarPedidos($db, $estadoSel);

$pageTitle  = $pedido ? 'Pedido #' . $pedido['id'] : 'Pedidos';
$activePage = 'orders';
$extraCss   = ['/Blue/assets/css/m-agenda.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-agenda.css')];
$topbarActions = '<a href="/Blue/admin/products.php" class="topbar-btn">Ver productos</a>';
require_once __DIR__ . '/../includes/admin_layout.php';

/** "02 nov 2026 · 3:05 PM" o un guion si no hay fecha. */
function fechaHoraPedido(?string $fecha): string {
    return $fecha ? date('d M Y', strtotime($fecha)) . ' · ' . date('g:i A', strtotime($fecha)) : '—';
}
?>

<?php if ($pedido):
    $items  = itemsDePedido($db, (int)$pedido['id']);
    $pagos  = pagosDePedido($db, (int)$pedido['id']);
    $estado = $pedido['status'];
    $numeroWa = telefonoWhatsapp((string)$pedido['cliente_telefono']);
    $mensajeWa = 'Hola ' . $pedido['cliente_nombre'] . ', te escribimos de ' . datoNegocio('nombre')
               . ' sobre tu pedido #' . $pedido['id'] . '.';
    if ($estado === 'ready') {
        $mensajeWa = 'Hola ' . $pedido['cliente_nombre'] . ', tu pedido #' . $pedido['id'] . ' de ' . datoNegocio('nombre')
                   . ' ya está listo para recoger. Te esperamos en ' . datoNegocio('direccion') . ' (' . datoNegocio('horario') . ').';
    } elseif ($estado === 'pending_payment') {
        $mensajeWa = 'Hola ' . $pedido['cliente_nombre'] . ', recibimos tu pedido #' . $pedido['id'] . ' de ' . datoNegocio('nombre')
                   . ' por ' . formatPrice((float)$pedido['total']) . '. ¿Cómo prefieres pagarlo?';
    }
?>

<div class="page-head">
  <div>
    <h2>Pedido #<?= (int)$pedido['id'] ?> <?= badgeEstadoPedido($estado) ?></h2>
    <p>Hecho el <?= e(fechaHoraPedido($pedido['created_at'])) ?> · <a href="/Blue/admin/orders.php">← Volver a los pedidos</a></p>
  </div>
</div>

<div class="ord-grid">
  <div>
    <div class="card"><div class="card-header"><span class="card-title">Productos</span></div>
      <div class="card-body--flush"><div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Producto</th><th>Precio</th><th>Cant.</th><th>Subtotal</th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): ?>
            <tr>
              <td><?= e($i['name']) ?></td>
              <td><?= formatPrice((float)$i['unit_price']) ?></td>
              <td><?= (int)$i['quantity'] ?></td>
              <td style="font-weight:600"><?= formatPrice((float)$i['unit_price'] * (int)$i['quantity']) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr><td colspan="3" style="text-align:right;font-weight:700">Total</td><td style="font-weight:700"><?= formatPrice((float)$pedido['total']) ?></td></tr>
          </tbody>
        </table>
      </div></div>
    </div>

    <?php if (!empty($pedido['notes'])): ?>
      <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">Notas</span></div>
        <div class="card-body" style="white-space:pre-line;font-size:14px"><?= e($pedido['notes']) ?></div>
      </div>
    <?php endif; ?>

    <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">Pagos</span></div>
      <div class="card-body--flush">
        <?php if (!$pagos): ?>
          <div class="empty-state" style="padding:24px"><div class="empty-state-desc">Todavía no hay pagos de este pedido.</div></div>
        <?php else: ?>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Referencia</th><th>Tipo</th><th>Medio</th><th>Monto</th><th>Estado</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $pg): ?>
              <tr>
                <td style="font-size:12px"><?= e($pg['reference']) ?></td>
                <td><?= $pg['kind'] === 'manual' ? 'En el centro' . ($pg['recibido_por_nombre'] ? ' · ' . e($pg['recibido_por_nombre']) : '') : 'En línea' ?></td>
                <td><?= e($pg['payment_method'] ?? '—') ?></td>
                <td style="font-weight:600"><?= formatPrice((float)$pg['amount']) ?></td>
                <td><?= e(etiquetaEstadoPago($pg['status'])) ?></td>
                <td><?= e(fechaHoraPedido($pg['paid_at'] ?? $pg['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card"><div class="card-header"><span class="card-title">Cliente</span></div>
      <div class="card-body">
        <div class="client-name"><?= e($pedido['cliente_nombre']) ?></div>
        <div class="client-phone"><?= e($pedido['cliente_telefono']) ?></div>
        <?php if ($pedido['cliente_correo']): ?><div class="client-phone"><?= e($pedido['cliente_correo']) ?></div><?php endif; ?>
        <?php if ($numeroWa): ?>
          <a class="btn btn-sm btn-ghost" style="margin-top:12px" target="_blank" rel="noopener"
             href="https://wa.me/<?= e($numeroWa) ?>?text=<?= rawurlencode($mensajeWa) ?>">Escribir por WhatsApp</a>
        <?php endif; ?>
        <div class="ord-avisos">
          Avisos:
          <?= (int)$pedido['email_reminder'] === 1 ? '<span class="pill">Correo</span>' : '' ?>
          <?= (int)$pedido['whatsapp_reminder'] === 1 ? '<span class="pill">WhatsApp</span>' : '' ?>
          <?= ((int)$pedido['email_reminder'] + (int)$pedido['whatsapp_reminder']) === 0 ? '<span class="pill pill-muted">Ninguno: avísale tú</span>' : '' ?>
        </div>
        <div class="ord-avisos">Entrega: <strong><?= $pedido['delivery_type'] === 'pickup' ? 'Recoge en el centro' : 'Envío a domicilio' ?></strong></div>
      </div>
    </div>

    <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">Seguimiento</span></div>
      <div class="card-body">
        <ul class="ord-lineas">
          <li><span>Creado</span><strong><?= e(fechaHoraPedido($pedido['created_at'])) ?></strong></li>
          <li><span>Pagado</span><strong><?= e(fechaHoraPedido($pedido['paid_at'])) ?></strong></li>
          <li><span>En preparación</span><strong><?= e(fechaHoraPedido($pedido['preparing_at'])) ?></strong></li>
          <li><span>Listo</span><strong><?= e(fechaHoraPedido($pedido['ready_at'])) ?></strong></li>
          <li><span>Cliente avisado</span><strong><?= e(fechaHoraPedido($pedido['ready_notified_at'])) ?></strong></li>
          <li><span>Entregado</span><strong><?= e(fechaHoraPedido($pedido['delivered_at'])) ?></strong></li>
          <?php if ($estado === 'cancelled'): ?>
            <li><span>Cancelado</span><strong><?= e(fechaHoraPedido($pedido['cancelled_at'])) ?></strong></li>
            <li><span>Motivo</span><strong><?= e($pedido['cancel_reason'] ?? '—') ?></strong></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <?php if (!in_array($estado, ['delivered', 'cancelled'], true)): ?>
    <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">Qué sigue</span></div>
      <div class="card-body ord-acciones">

        <?php if ($estado === 'pending_payment'): ?>
          <form method="POST">
            <input type="hidden" name="action" value="pedido_pagar">
            <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label class="ord-etiqueta">El cliente pagó en el centro o por transferencia</label>
            <div class="ord-fila">
              <select name="medio" class="form-control" required>
                <?php foreach (mediosDePagoManual() as $m): ?><option><?= e($m) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-primary" type="submit" onclick="return confirm('¿Confirmas que ya recibiste el pago de <?= e(formatPrice((float)$pedido['total'])) ?>?')">Registrar pago</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($estado === 'paid'): ?>
          <form method="POST"><input type="hidden" name="action" value="pedido_estado"><input type="hidden" name="estado" value="preparing">
            <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="btn btn-primary" type="submit">Empezar a preparar</button></form>
        <?php endif; ?>

        <?php if (in_array($estado, ['paid', 'preparing'], true)): ?>
          <form method="POST"><input type="hidden" name="action" value="pedido_estado"><input type="hidden" name="estado" value="ready">
            <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="btn <?= $estado === 'preparing' ? 'btn-primary' : 'btn-ghost' ?>" type="submit">Marcar listo y avisar al cliente</button></form>
        <?php endif; ?>

        <?php if ($estado === 'ready'): ?>
          <form method="POST"><input type="hidden" name="action" value="pedido_estado"><input type="hidden" name="estado" value="delivered">
            <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="btn btn-primary" type="submit">Marcar como entregado</button></form>
          <form method="POST"><input type="hidden" name="action" value="pedido_reavisar">
            <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="btn btn-ghost" type="submit">Reenviar el aviso al cliente</button></form>
        <?php endif; ?>

        <form method="POST" class="ord-cancelar">
          <input type="hidden" name="action" value="pedido_cancelar">
          <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <label class="ord-etiqueta">Cancelar el pedido (el stock vuelve al inventario)</label>
          <div class="ord-fila">
            <input type="text" name="motivo" class="form-control" maxlength="200" placeholder="Motivo (opcional)">
            <button class="btn btn-danger" type="submit" onclick="return confirm('¿Cancelar el pedido #<?= (int)$pedido['id'] ?>?')">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<div class="page-head">
  <div>
    <h2>Pedidos</h2>
    <p>Lo que los clientes piden desde el sitio · <?= (int)($conteo['paid'] + $conteo['preparing']) ?> por atender</p>
  </div>
</div>

<div class="tabs">
  <button class="tab <?= $estadoSel === '' ? 'active' : '' ?>" onclick="location='?'">Todos <span class="cat-chip-n"><?= array_sum($conteo) ?></span></button>
  <?php foreach (estadosPedido() as $clave => $info): ?>
    <button class="tab <?= $estadoSel === $clave ? 'active' : '' ?>" onclick="location='?estado=<?= e($clave) ?>'">
      <?= e($info['label']) ?> <span class="cat-chip-n"><?= (int)$conteo[$clave] ?></span>
    </button>
  <?php endforeach; ?>
</div>

<div class="card"><div class="card-body--flush">
  <?php if (!$pedidos): ?>
    <div class="empty-state">
      <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></div>
      <div class="empty-state-title">Sin pedidos</div>
      <div class="empty-state-desc"><?= $estadoSel === '' ? 'Cuando un cliente pida productos desde el sitio, aparecerá aquí.' : 'No hay pedidos en este estado.' ?></div>
    </div>
  <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Pedido</th><th>Cliente</th><th>Productos</th><th>Total</th><th>Estado</th><th>Fecha</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pedidos as $o): ?>
        <tr class="<?= in_array($o['status'], ['paid', 'preparing'], true) ? 'ord-por-atender' : '' ?>">
          <td style="font-weight:700"><a href="?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td>
          <td><div class="client-name"><?= e($o['cliente_nombre']) ?></div><div class="client-phone"><?= e($o['cliente_telefono']) ?></div></td>
          <td><?= (int)$o['unidades'] ?> ud.</td>
          <td style="font-weight:600"><?= formatPrice((float)$o['total']) ?></td>
          <td><?= badgeEstadoPedido($o['status']) ?></td>
          <td style="color:var(--muted);font-size:13px"><?= e(fechaHoraPedido($o['created_at'])) ?></td>
          <td><a class="btn-action" href="?id=<?= (int)$o['id'] ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div></div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
