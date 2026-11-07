<?php
/**
 * h-pedidos.php — Pedidos de la tienda: creación, pago, estados y avisos.
 * Responsable: Felipe (módulo Tienda).
 *
 * Flujo:
 *   1. El cliente arma su pedido en tienda.php → api/pedido_crear.php → crearPedido().
 *      El stock se descuenta al crearlo, para que nadie compre lo mismo dos veces.
 *   2. Con la pasarela activa paga en el checkout de Wompi (iniciarPagoPedido());
 *      si no, el pedido queda «por pagar» y el equipo lo cobra y lo marca pagado
 *      (registrarPagoManualPedido()).
 *   3. Pagado → el equipo lo prepara → lo marca «Listo» → el cliente recibe el aviso
 *      (correo y/o WhatsApp) para que pase por él → «Entregado».
 *   4. Si el pago no se completa a tiempo, o el equipo cancela, el pedido se anula y
 *      el stock vuelve (anularPedido()).
 *
 * El pago reutiliza las funciones de Wompi de h-pagos.php; la diferencia con las
 * citas es la referencia (PED-…) y la tabla (order_payments).
 */

require_once __DIR__ . '/h-pagos.php';      // Wompi · h-correo · h-notificaciones · h-reservas
require_once __DIR__ . '/h-productos.php';

const PEDIDO_MAX_LINEAS         = 30;   // productos distintos en un pedido
const PEDIDO_MAX_UNIDADES_LINEA = 20;   // unidades de un mismo producto
const PEDIDO_NOTA_MAX           = 300;

// ══════════════════════════════════════════════════════════
//   ESTADOS
// ══════════════════════════════════════════════════════════

/** Estados del pedido: etiqueta para el equipo, clase de insignia y mensaje para el cliente. */
function estadosPedido(): array {
    return [
        'pending_payment' => ['label' => 'Por pagar',          'clase' => 'pending',   'cliente' => 'Recibimos tu pedido. Falta confirmar el pago.'],
        'paid'            => ['label' => 'Pagado',             'clase' => 'confirmed', 'cliente' => 'Pago recibido. En breve empezamos a prepararlo.'],
        'preparing'       => ['label' => 'En preparación',     'clase' => 'completed', 'cliente' => 'Estamos preparando tu pedido.'],
        'ready'           => ['label' => 'Listo para recoger', 'clase' => 'listo',     'cliente' => '¡Tu pedido está listo! Puedes pasar por él al centro.'],
        'delivered'       => ['label' => 'Entregado',          'clase' => 'entregado', 'cliente' => 'Pedido entregado. ¡Gracias por tu compra!'],
        'cancelled'       => ['label' => 'Cancelado',          'clase' => 'cancelled', 'cliente' => 'Este pedido fue cancelado.'],
    ];
}

/** ['label' => …, 'clase' => …, 'cliente' => …] de un estado (con valores por defecto si no existe). */
function etiquetaEstadoPedido(string $estado): array {
    return estadosPedido()[$estado] ?? ['label' => $estado, 'clase' => 'pending', 'cliente' => ''];
}

/** Insignia HTML del estado (usa las clases .badge de admin.css y m-agenda.css). */
function badgeEstadoPedido(string $estado): string {
    $e = etiquetaEstadoPedido($estado);
    return '<span class="badge badge-' . e($e['clase']) . '">' . e($e['label']) . '</span>';
}

/**
 * Pasos de la línea de tiempo del cliente. Un pedido cancelado no tiene línea.
 * @return array [['clave','label','hecho','activo'], ...]
 */
function pasosPedido(string $estado): array {
    $orden  = ['pending_payment' => 0, 'paid' => 1, 'preparing' => 2, 'ready' => 3, 'delivered' => 4];
    $actual = $orden[$estado] ?? 0;
    $pasos  = [
        ['clave' => 'pending_payment', 'label' => 'Recibido'],
        ['clave' => 'paid',            'label' => 'Pagado'],
        ['clave' => 'preparing',       'label' => 'En preparación'],
        ['clave' => 'ready',           'label' => 'Listo'],
        ['clave' => 'delivered',       'label' => 'Entregado'],
    ];
    foreach (array_keys($pasos) as $i) {
        $pasos[$i]['hecho']  = $i <  $actual;
        $pasos[$i]['activo'] = $i === $actual;
    }
    return $pasos;
}

/** ¿Se cobra en línea? Solo con la pasarela configurada de verdad (no en modo demostración). */
function pedidosPagoEnLinea(): bool {
    return pagosEnLineaActivos() && !pagosEnModoDemo();
}

// ══════════════════════════════════════════════════════════
//   CONSULTAS
// ══════════════════════════════════════════════════════════

/** Pedido con los datos de su cliente; null si no existe. */
function pedidoPorId(PDO $db, int $id): ?array {
    $stmt = $db->prepare(
        'SELECT o.*, c.name AS cliente_nombre, c.phone AS cliente_telefono, c.email AS cliente_correo
           FROM orders o JOIN clients c ON c.id = o.client_id
          WHERE o.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Líneas del pedido (nombre y precio del momento de la compra). */
function itemsDePedido(PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/** Intentos de pago del pedido, del más nuevo al más viejo. */
function pagosDePedido(PDO $db, int $id): array {
    $stmt = $db->prepare(
        'SELECT p.*, u.name AS recibido_por_nombre
           FROM order_payments p LEFT JOIN users u ON u.id = p.received_by
          WHERE p.order_id = ? ORDER BY p.id DESC'
    );
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/** Enlace privado con el que el cliente sigue su pedido. */
function urlEstadoPedido(array $pedido): string {
    return urlPublicaBlue() . '/pedido.php?id=' . (int)$pedido['id'] . '&t=' . $pedido['token'];
}

/** ¿El token de la URL corresponde a este pedido? Comparación en tiempo constante. */
function tokenPedidoEsValido(array $pedido, string $token): bool {
    return preg_match('/^[a-f0-9]{32}$/', $token) === 1 && hash_equals((string)$pedido['token'], $token);
}

/** Total de unidades de un pedido. */
function unidadesDePedido(array $items): int {
    return array_sum(array_map(fn($i) => (int)$i['quantity'], $items));
}

/**
 * Pedidos para el panel, más nuevos primero.
 * @param string $estado '' = todos
 */
function listarPedidos(PDO $db, string $estado = '', int $limite = 200): array {
    $donde = isset(estadosPedido()[$estado]) ? 'WHERE o.status = ' . $db->quote($estado) : '';
    return $db->query(
        "SELECT o.*, c.name AS cliente_nombre, c.phone AS cliente_telefono,
                (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS unidades
           FROM orders o JOIN clients c ON c.id = o.client_id
           $donde
          ORDER BY o.id DESC LIMIT " . max(1, $limite)
    )->fetchAll();
}

/** Cuántos pedidos hay en cada estado: ['pending_payment' => 2, …] (todos los estados aparecen). */
function conteoPedidosPorEstado(PDO $db): array {
    $conteo = array_fill_keys(array_keys(estadosPedido()), 0);
    foreach ($db->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $f) {
        $conteo[$f['status']] = (int)$f['n'];
    }
    return $conteo;
}

/** Pedidos que el equipo todavía tiene que atender (pagados o en preparación). */
function pedidosPorAtender(PDO $db): int {
    try {
        return (int)$db->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','preparing')")->fetchColumn();
    } catch (PDOException $e) {
        return 0;   // la migración de la tienda todavía no se ha corrido
    }
}

// ══════════════════════════════════════════════════════════
//   CREAR EL PEDIDO
// ══════════════════════════════════════════════════════════

/**
 * Normaliza y valida lo que llega del navegador.
 *
 * @param array $in items [{id, qty}] · name · phone · email · note · whatsapp · email_reminder
 * @return array ['errores' => string[], 'datos' => array] (datos['items'] = [productoId => unidades])
 */
function validarDatosPedido(array $in): array {
    $items = [];
    foreach ((array)($in['items'] ?? []) as $linea) {
        $id  = (int)($linea['id']  ?? 0);
        $qty = (int)($linea['qty'] ?? 0);
        if ($id > 0 && $qty > 0) $items[$id] = ($items[$id] ?? 0) + $qty;   // un producto repetido se suma
    }

    $d = [
        'items'          => $items,
        'name'           => trim((string)($in['name']  ?? '')),
        'phone'          => trim((string)($in['phone'] ?? '')),
        'email'          => trim((string)($in['email'] ?? '')),
        'note'           => trim((string)preg_replace('/\s+/u', ' ', (string)($in['note'] ?? ''))),
        'whatsapp'       => !empty($in['whatsapp']),
        'email_reminder' => !empty($in['email_reminder']),
        // Solo se recoge en el centro por ahora; el envío a domicilio está pendiente de definir.
        'delivery_type'  => 'pickup',
    ];

    $errores = [];
    if (!$items)                                   $errores[] = 'Tu pedido está vacío.';
    if (count($items) > PEDIDO_MAX_LINEAS)         $errores[] = 'Tu pedido tiene demasiados productos distintos (máximo ' . PEDIDO_MAX_LINEAS . ').';
    if ($items && max($items) > PEDIDO_MAX_UNIDADES_LINEA) {
        $errores[] = 'Puedes pedir máximo ' . PEDIDO_MAX_UNIDADES_LINEA . ' unidades de cada producto.';
    }
    if ($d['name'] === '')                         $errores[] = 'El nombre es requerido.';
    if ($d['phone'] === '')                        $errores[] = 'El teléfono es requerido.';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es válido.';
    if (mb_strlen($d['note']) > PEDIDO_NOTA_MAX)   $errores[] = 'La nota es muy larga (máximo ' . PEDIDO_NOTA_MAX . ' caracteres).';

    // Sin correo no hay a dónde avisar, sin importar lo que haya marcado el checkbox.
    if ($d['email'] === '') $d['email_reminder'] = false;

    return ['errores' => $errores, 'datos' => $d];
}

/**
 * Cliente por teléfono: lo reutiliza si ya existe, lo crea si no.
 *
 * Quien pide desde el sitio no está autenticado, así que NO se le deja pisar los datos de
 * un cliente que ya existe (cualquiera que conozca un teléfono podría renombrarlo). Solo se
 * completa el correo cuando el cliente no tenía ninguno.
 */
function buscarOCrearCliente(PDO $db, string $nombre, string $correo, string $telefono): int {
    $buscar = $db->prepare('SELECT id FROM clients WHERE phone = ? LIMIT 1');
    $buscar->execute([$telefono]);
    $id = $buscar->fetchColumn();

    if ($id) {
        if ($correo !== '') {
            $db->prepare("UPDATE clients SET email = ? WHERE id = ? AND (email IS NULL OR email = '')")
               ->execute([$correo, $id]);
        }
        return (int)$id;
    }
    $db->prepare('INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)')
       ->execute([$nombre, $correo ?: null, $telefono]);
    return (int)$db->lastInsertId();
}

/**
 * Crea el pedido en estado «por pagar» y descuenta el stock.
 * Precios y totales salen SIEMPRE de la base de datos, nunca del navegador.
 *
 * @param array $d resultado de validarDatosPedido()['datos']
 * @throws RuntimeException con un mensaje para el cliente (producto agotado, ya no disponible…)
 * @return array ['order_id' => int, 'total' => float, 'token' => string, 'client_id' => int]
 */
function crearPedido(PDO $db, array $d): array {
    if (empty($d['items'])) throw new RuntimeException('Tu pedido está vacío.');

    try {
        $db->beginTransaction();

        // FOR UPDATE: dos pedidos a la vez no pueden llevarse la misma última unidad.
        $marcas = implode(',', array_fill(0, count($d['items']), '?'));
        $stmt = $db->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($marcas) AND active = 1 FOR UPDATE");
        $stmt->execute(array_keys($d['items']));
        $productos = [];
        foreach ($stmt->fetchAll() as $p) $productos[(int)$p['id']] = $p;

        $total = 0.0;
        foreach ($d['items'] as $productoId => $cantidad) {
            $p = $productos[$productoId] ?? null;
            if (!$p) {
                throw new RuntimeException('Uno de los productos ya no está disponible. Actualiza la tienda e intenta de nuevo.');
            }
            if ($p['stock'] !== null && (int)$p['stock'] < $cantidad) {
                throw new RuntimeException((int)$p['stock'] > 0
                    ? '«' . $p['name'] . '» solo tiene ' . (int)$p['stock'] . ' unidades disponibles.'
                    : '«' . $p['name'] . '» está agotado.');
            }
            $total += (float)$p['price'] * $cantidad;
        }

        $clienteId = buscarOCrearCliente($db, $d['name'], $d['email'], $d['phone']);
        $token     = bin2hex(random_bytes(16));

        $db->prepare(
            "INSERT INTO orders (client_id, status, delivery_type, total, notes, token, email_reminder, whatsapp_reminder)
             VALUES (?, 'pending_payment', ?, ?, ?, ?, ?, ?)"
        )->execute([
            $clienteId, $d['delivery_type'], round($total, 2), $d['note'] ?: null, $token,
            $d['email_reminder'] ? 1 : 0, $d['whatsapp'] ? 1 : 0,
        ]);
        $pedidoId = (int)$db->lastInsertId();

        $insertarLinea = $db->prepare('INSERT INTO order_items (order_id, product_id, name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)');
        $descontar     = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL');
        foreach ($d['items'] as $productoId => $cantidad) {
            $p = $productos[$productoId];
            $insertarLinea->execute([$pedidoId, $productoId, $p['name'], $p['price'], $cantidad]);
            $descontar->execute([$cantidad, $productoId]);
        }

        $db->commit();
        return ['order_id' => $pedidoId, 'total' => round($total, 2), 'token' => $token, 'client_id' => $clienteId];

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════
//   ANULAR Y DEVOLVER EL STOCK
// ══════════════════════════════════════════════════════════

/** Devuelve al inventario las unidades de un pedido (solo productos con control de stock). */
function devolverStockPedido(PDO $db, int $pedidoId): void {
    $db->prepare(
        'UPDATE products p JOIN order_items i ON i.product_id = p.id
            SET p.stock = p.stock + i.quantity
          WHERE i.order_id = ? AND p.stock IS NOT NULL'
    )->execute([$pedidoId]);
}

/**
 * Cancela un pedido y devuelve su stock. Es seguro llamarla dos veces: el UPDATE
 * condicional solo deja pasar a la primera, así el stock no se devuelve doble.
 *
 * @param string[] $desdeEstados estados desde los que se permite cancelar
 * @return bool true si esta llamada fue la que lo canceló
 */
function anularPedido(PDO $db, int $pedidoId, string $motivo, array $desdeEstados = ['pending_payment']): bool {
    $marcas = implode(',', array_fill(0, count($desdeEstados), '?'));
    try {
        $db->beginTransaction();

        $cancelar = $db->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?
              WHERE id = ? AND status IN ($marcas)"
        );
        $cancelar->execute([mb_substr($motivo, 0, 255), $pedidoId, ...$desdeEstados]);

        if ($cancelar->rowCount() !== 1) {
            $db->rollBack();
            return false;
        }
        devolverStockPedido($db, $pedidoId);
        $db->prepare("UPDATE order_payments SET status = 'expired' WHERE order_id = ? AND status = 'pending'")
           ->execute([$pedidoId]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue/Tienda] No se pudo anular el pedido ' . $pedidoId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Anula los pedidos cuyo pago en línea nunca se completó y libera su stock.
 * Se llama al mostrar la tienda y al crear un pedido, así no hace falta una tarea programada.
 */
function liberarPedidosVencidos(PDO $db): void {
    try {
        $vencidos = $db->query(
            "SELECT DISTINCT o.id FROM orders o
               JOIN order_payments p ON p.order_id = o.id
              WHERE o.status = 'pending_payment' AND p.kind = 'online'
                AND p.status = 'pending' AND p.expires_at < NOW()"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('[Blue/Tienda] No se pudieron revisar los pedidos vencidos: ' . $e->getMessage());
        return;   // nunca debe tumbar la página que la invoca
    }

    foreach ($vencidos as $pedidoId) {
        anularPedido($db, (int)$pedidoId, 'Se venció el tiempo para completar el pago.');
    }

    liberarPedidosAbandonados($db);
}

/**
 * Anula los pedidos «por pagar» que nadie cobró en `horas_pedido_sin_pago` horas (config/negocio.php).
 * Son los que se cobran a mano: sin este límite, pedidos abandonados dejarían el stock retenido
 * para siempre. Los que tienen un pago en línea abierto vencen por su propio tiempo.
 */
function liberarPedidosAbandonados(PDO $db): void {
    $horas = horasPedidoSinPago();
    if ($horas <= 0) return;

    try {
        $abandonados = $db->query(
            "SELECT o.id FROM orders o
              WHERE o.status = 'pending_payment'
                AND o.created_at < DATE_SUB(NOW(), INTERVAL {$horas} HOUR)
                AND NOT EXISTS (SELECT 1 FROM order_payments p
                                 WHERE p.order_id = o.id AND p.kind = 'online' AND p.status = 'pending')"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('[Blue/Tienda] No se pudieron revisar los pedidos abandonados: ' . $e->getMessage());
        return;
    }

    foreach ($abandonados as $pedidoId) {
        anularPedido($db, (int)$pedidoId, 'Pasaron ' . $horas . ' horas sin confirmar el pago.');
    }
}

// ══════════════════════════════════════════════════════════
//   PAGO EN LÍNEA (Wompi)
// ══════════════════════════════════════════════════════════

/** Intento de pago por su referencia (PED-…). */
function buscarPagoPedidoPorReferencia(PDO $db, string $referencia): ?array {
    $stmt = $db->prepare('SELECT * FROM order_payments WHERE reference = ? LIMIT 1');
    $stmt->execute([$referencia]);
    return $stmt->fetch() ?: null;
}

/** Intento de pago por el id de transacción de Wompi. */
function buscarPagoPedidoPorTransaccion(PDO $db, string $transaccionId): ?array {
    $stmt = $db->prepare('SELECT * FROM order_payments WHERE transaction_id = ? LIMIT 1');
    $stmt->execute([$transaccionId]);
    return $stmt->fetch() ?: null;
}

/** ¿La referencia de Wompi es de un pedido de la tienda? (las de citas empiezan por BLUE-). */
function referenciaEsDePedido(string $referencia): bool {
    return str_starts_with($referencia, 'PED-');
}

/**
 * Crea el intento de pago de un pedido y arma el checkout firmado de Wompi.
 * El stock queda guardado hasta `expires_at`; si no paga a tiempo, vuelve (liberarPedidosVencidos).
 *
 * @return array ['checkout_url' => string, 'reference' => string, 'expires_in' => int]
 */
function iniciarPagoPedido(PDO $db, array $pedido, array $cliente): array {
    $minutos    = max(5, (int)(configPagos()['minutos_reserva'] ?? 20));
    $venceEn    = date('Y-m-d H:i:s', time() + $minutos * 60);
    $referencia = 'PED-' . (int)$pedido['id'] . '-' . strtoupper(bin2hex(random_bytes(4)));

    $db->prepare(
        "INSERT INTO order_payments (order_id, kind, environment, reference, amount, currency, status, expires_at)
         VALUES (?, 'online', ?, ?, ?, 'COP', 'pending', ?)"
    )->execute([(int)$pedido['id'], pagosEnProduccion() ? 'prod' : 'test', $referencia, $pedido['total'], $venceEn]);

    $pago   = buscarPagoPedidoPorReferencia($db, $referencia);
    $campos = camposCheckoutWompi($pago, $cliente);

    return [
        'checkout_url' => urlCheckoutWompi() . '?' . http_build_query($campos),
        'reference'    => $referencia,
        'expires_in'   => $minutos * 60,
    ];
}

/**
 * Lleva el pago a su estado final y, si fue aprobado, marca el pedido como pagado.
 * Es idempotente: el cliente que vuelve del checkout y el webhook llegan casi a la
 * vez y solo uno puede cambiar el pedido (el candado es el UPDATE sobre order_payments).
 *
 * @param array $transaccion datos `data` de la transacción según Wompi
 * @return array ['status' => estado del pago, 'order_id' => int, 'aplicado' => bool]
 */
function aplicarResultadoPedido(PDO $db, array $pago, array $transaccion): array {
    $estado    = ESTADOS_WOMPI[strtoupper((string)($transaccion['status'] ?? ''))] ?? null;
    $resultado = ['status' => $pago['status'], 'order_id' => (int)$pago['order_id'], 'aplicado' => false];

    // Un pago «expired» (se venció el tiempo y el pedido se anuló) igual se procesa si Wompi
    // lo aprobó: es dinero ya recibido y el pedido se recupera en marcarPedidoPagado().
    $abierto = $pago['status'] === 'pending' || ($pago['status'] === 'expired' && $estado === 'approved');

    // Estado desconocido o aún en proceso, o ya resuelto por el otro camino: no se toca nada.
    if ($estado === null || $estado === 'pending' || !$abierto) {
        return $resultado;
    }

    $transaccionId = (string)($transaccion['id'] ?? '');
    $medioPago     = substr((string)($transaccion['payment_method_type'] ?? ''), 0, 40);
    $centavos      = (int)($transaccion['amount_in_cents'] ?? 0);
    $montoDistinto = $centavos > 0 && $centavos !== montoEnCentavos((float)$pago['amount']);
    $sinStock      = false;

    try {
        $db->beginTransaction();

        $candado = $db->prepare(
            "UPDATE order_payments
                SET status = ?, transaction_id = ?, payment_method = ?,
                    paid_at = CASE WHEN ? = 'approved' THEN NOW() ELSE paid_at END
              WHERE id = ? AND status = ?"
        );
        $candado->execute([$estado, $transaccionId ?: null, $medioPago ?: null, $estado, $pago['id'], $pago['status']]);

        if ($candado->rowCount() !== 1) {
            $db->rollBack();
            $actual = buscarPagoPedidoPorReferencia($db, $pago['reference']);
            $resultado['status'] = $actual['status'] ?? $pago['status'];
            return $resultado;
        }

        if ($estado === 'approved') {
            $sinStock = !marcarPedidoPagado($db, (int)$pago['order_id'], $montoDistinto
                ? ' · OJO: el monto recibido no coincide con el del pedido, verificar en Wompi.' : '');
            registrarIngresoPedido($db, (int)$pago['order_id'], (float)$pago['amount'], $pago['reference'], null, $medioPago);
        }

        $db->commit();
        $resultado['status']   = $estado;
        $resultado['aplicado'] = true;

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue/Tienda] Error al aplicar el pago ' . $pago['reference'] . ': ' . $e->getMessage());
        return $resultado;
    }

    // Avisos y cancelaciones van fuera de la transacción: nunca deben deshacer un pago ya registrado.
    if ($estado === 'approved') {
        avisarPedidoAlEquipo($db, (int)$pago['order_id'], 'pedido_pagado', $sinStock);
        avisarPedidoAlCliente($db, (int)$pago['order_id'], 'pagado');
    } elseif (in_array($estado, ['declined', 'voided', 'error'], true)) {
        anularPedido($db, (int)$pago['order_id'], $estado === 'declined'
            ? 'El banco no autorizó el pago.' : 'Hubo un problema al procesar el pago.');
    }

    return $resultado;
}

/**
 * Pasa el pedido a «pagado». Contempla el caso raro de que el pago llegue cuando el
 * pedido ya se había anulado por vencimiento y su stock se devolvió: se intenta
 * volver a apartar; si ya no alcanza, igual queda pagado y con una nota para el equipo.
 *
 * @return bool false si quedó pagado SIN stock (el equipo debe contactar al cliente)
 */
function marcarPedidoPagado(PDO $db, int $pedidoId, string $notaExtra = ''): bool {
    $stmt = $db->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$pedidoId]);
    $estado = $stmt->fetchColumn();

    if ($estado === 'pending_payment') {
        $db->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$pedidoId]);
        if ($notaExtra !== '') agregarNotaPedido($db, $pedidoId, trim($notaExtra, ' ·'));
        return true;
    }
    if ($estado !== 'cancelled') return true;   // ya estaba pagado (o más adelante): nada que hacer

    $hayStock = volverAApartarStock($db, $pedidoId);
    $db->prepare("UPDATE orders SET status = 'paid', paid_at = NOW(), cancelled_at = NULL, cancel_reason = NULL WHERE id = ?")
       ->execute([$pedidoId]);
    if (!$hayStock) {
        agregarNotaPedido($db, $pedidoId, 'PAGO RECIBIDO SIN STOCK: el pedido se había anulado por vencimiento y ya no alcanza el inventario. Contactar al cliente para reprogramar o devolver el dinero.');
    }
    if ($notaExtra !== '') agregarNotaPedido($db, $pedidoId, trim($notaExtra, ' ·'));
    return $hayStock;
}

/** Vuelve a descontar el stock de un pedido anulado; false (sin tocar nada) si ya no alcanza. */
function volverAApartarStock(PDO $db, int $pedidoId): bool {
    $faltante = $db->prepare(
        'SELECT COUNT(*) FROM order_items i JOIN products p ON p.id = i.product_id
          WHERE i.order_id = ? AND p.stock IS NOT NULL AND p.stock < i.quantity'
    );
    $faltante->execute([$pedidoId]);
    if ((int)$faltante->fetchColumn() > 0) return false;

    $db->prepare(
        'UPDATE products p JOIN order_items i ON i.product_id = p.id
            SET p.stock = p.stock - i.quantity
          WHERE i.order_id = ? AND p.stock IS NOT NULL'
    )->execute([$pedidoId]);
    return true;
}

/** Agrega una línea a las notas del pedido (visible para el equipo). */
function agregarNotaPedido(PDO $db, int $pedidoId, string $nota): void {
    $db->prepare("UPDATE orders SET notes = TRIM(CONCAT(COALESCE(notes, ''), '\n', ?)) WHERE id = ?")
       ->execute([$nota, $pedidoId]);
}

/**
 * Registra el pago de un pedido como ingreso en Finanzas (una sola vez por referencia).
 * `registered_by` queda en NULL cuando lo cobró la pasarela: en el listado se ve «Pago en línea».
 */
function registrarIngresoPedido(PDO $db, int $pedidoId, float $monto, string $referencia, ?int $actorId, string $medio = ''): void {
    $existe = $db->prepare('SELECT COUNT(*) FROM finances WHERE description LIKE ?');
    $existe->execute(['%' . $referencia . '%']);
    if ((int)$existe->fetchColumn() > 0) return;

    $descripcion = 'Venta de productos · pedido #' . $pedidoId . ' · ' . $referencia . ($medio !== '' ? ' · ' . $medio : '');
    $db->prepare(
        "INSERT INTO finances (type, category, description, amount, date, appointment_id, registered_by)
         VALUES ('income', 'Venta de productos', ?, ?, CURDATE(), NULL, ?)"
    )->execute([$descripcion, $monto, $actorId]);
}

// ══════════════════════════════════════════════════════════
//   ACCIONES DEL EQUIPO
// ══════════════════════════════════════════════════════════

/**
 * El equipo cobró el pedido por otro medio (efectivo, Nequi…) y lo marca pagado.
 * Es el camino cuando la pasarela no está activa.
 *
 * @return array ['ok' => bool, 'msg' => string]
 */
function registrarPagoManualPedido(PDO $db, int $pedidoId, string $medio, int $actorId): array {
    if (!in_array($medio, mediosDePagoManual(), true)) {
        return ['ok' => false, 'msg' => 'Selecciona un medio de pago válido.'];
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT total, status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();
        if (!$pedido) { $db->rollBack(); return ['ok' => false, 'msg' => 'El pedido no existe.']; }
        if ($pedido['status'] !== 'pending_payment') {
            $db->rollBack();
            return ['ok' => false, 'msg' => 'Este pedido ya no está por pagar.'];
        }

        $referencia = 'PEDM-' . $pedidoId . '-' . strtoupper(bin2hex(random_bytes(3)));
        $db->prepare(
            "INSERT INTO order_payments (order_id, kind, reference, amount, status, payment_method, received_by, paid_at)
             VALUES (?, 'manual', ?, ?, 'approved', ?, ?, NOW())"
        )->execute([$pedidoId, $referencia, $pedido['total'], $medio, $actorId]);

        // Un checkout en línea que quedara abierto ya no debe poder cobrar de nuevo.
        $db->prepare("UPDATE order_payments SET status = 'expired' WHERE order_id = ? AND kind = 'online' AND status = 'pending'")
           ->execute([$pedidoId]);

        marcarPedidoPagado($db, $pedidoId);
        registrarIngresoPedido($db, $pedidoId, (float)$pedido['total'], $referencia, $actorId, $medio);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Blue/Tienda] No se pudo registrar el pago del pedido ' . $pedidoId . ': ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo registrar el pago. Intenta de nuevo.'];
    }

    avisarPedidoAlCliente($db, $pedidoId, 'pagado');
    return ['ok' => true, 'msg' => 'Pago registrado. El pedido quedó listo para preparar.'];
}

/**
 * Avanza el pedido: pagado → en preparación → listo → entregado.
 * Al marcarlo «Listo» se avisa al cliente.
 *
 * @return array ['ok' => bool, 'msg' => string]
 */
function cambiarEstadoPedido(PDO $db, int $pedidoId, string $nuevo): array {
    // nuevo estado => [estados desde los que se puede llegar, columna con la fecha]
    $reglas = [
        'preparing' => [['paid'],              'preparing_at'],
        'ready'     => [['paid', 'preparing'], 'ready_at'],
        'delivered' => [['ready'],             'delivered_at'],
    ];
    if (!isset($reglas[$nuevo])) return ['ok' => false, 'msg' => 'Estado no válido.'];
    [$desde, $columna] = $reglas[$nuevo];

    $marcas = implode(',', array_fill(0, count($desde), '?'));
    $stmt = $db->prepare("UPDATE orders SET status = ?, $columna = NOW() WHERE id = ? AND status IN ($marcas)");
    $stmt->execute([$nuevo, $pedidoId, ...$desde]);

    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'msg' => 'El pedido ya cambió de estado. Recarga la página para ver el actual.'];
    }

    if ($nuevo === 'ready') {
        $avisado = avisarPedidoAlCliente($db, $pedidoId, 'listo');
        return ['ok' => true, 'msg' => $avisado
            ? 'Pedido listo. Le avisamos al cliente.'
            : 'Pedido listo, pero no pudimos avisarle al cliente (no dejó correo ni WhatsApp). Avísale tú.'];
    }
    return ['ok' => true, 'msg' => $nuevo === 'preparing' ? 'Pedido en preparación.' : 'Pedido entregado.'];
}

/**
 * El equipo cancela un pedido (devuelve el stock). Si ya estaba pagado, avisa que el dinero
 * hay que devolverlo a mano: esta versión no hace reembolsos automáticos por Wompi.
 *
 * @return array ['ok' => bool, 'msg' => string]
 */
function cancelarPedidoPorEquipo(PDO $db, int $pedidoId, string $motivo): array {
    $motivo = trim($motivo) !== '' ? trim($motivo) : 'Cancelado por el equipo.';

    $pagado = $db->prepare("SELECT COUNT(*) FROM order_payments WHERE order_id = ? AND status = 'approved'");
    $pagado->execute([$pedidoId]);
    $teniaPago = (int)$pagado->fetchColumn() > 0;

    if (!anularPedido($db, $pedidoId, $motivo, ['pending_payment', 'paid', 'preparing', 'ready'])) {
        return ['ok' => false, 'msg' => 'No se pudo cancelar: el pedido ya fue entregado o cancelado.'];
    }
    avisarPedidoAlCliente($db, $pedidoId, 'cancelado');

    return ['ok' => true, 'msg' => $teniaPago
        ? 'Pedido cancelado y stock devuelto. Ojo: el cliente ya había pagado; devuélvele el dinero por fuera del sistema.'
        : 'Pedido cancelado y stock devuelto.'];
}

// ══════════════════════════════════════════════════════════
//   AVISOS
// ══════════════════════════════════════════════════════════

/**
 * Avisa a los administradores en la campana del panel.
 * @param string $tipo 'pedido_nuevo' (por pagar) o 'pedido_pagado' (a preparar)
 */
function avisarPedidoAlEquipo(PDO $db, int $pedidoId, string $tipo, bool $sinStock = false): void {
    try {
        $pedido = pedidoPorId($db, $pedidoId);
        if (!$pedido) return;

        $admins = $db->query("SELECT id FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        $items  = itemsDePedido($db, $pedidoId);
        $titulo = $tipo === 'pedido_pagado' ? 'Pedido pagado: a preparar' : 'Nuevo pedido por cobrar';
        $cuerpo = '#' . $pedidoId . ' · ' . $pedido['cliente_nombre'] . ' · ' . unidadesDePedido($items) . ' producto(s) · '
                . formatPrice((float)$pedido['total']) . ($sinStock ? ' · SIN STOCK, revisar' : '');

        notificar($db, $admins, $tipo, $titulo, $cuerpo, '/Blue/admin/orders.php?id=' . $pedidoId, null, $pedidoId);
    } catch (Throwable $e) {
        error_log('[Blue/Tienda] No se pudo avisar el pedido ' . $pedidoId . ' al equipo: ' . $e->getMessage());
    }
}

/** Bloque con las líneas del pedido para los correos. */
function correoDetallePedido(array $pedido, array $items): string {
    $filas = '';
    foreach ($items as $i) {
        $filas .= correoFila($i['quantity'] . ' × ' . $i['name'], e(formatPrice((float)$i['unit_price'] * (int)$i['quantity'])));
    }
    $filas .= correoFila('Total', e(formatPrice((float)$pedido['total'])));
    $filas .= correoFila('Entrega', 'Recoger en el centro');
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0">' . $filas . '</table>';
}

/**
 * Arma el correo de un evento del pedido.
 * @param string $evento 'recibido' · 'pagado' · 'listo' · 'cancelado'
 * @return array ['asunto' => string, 'html' => string]
 */
function correoPedido(array $pedido, array $items, string $evento, string $url): array {
    $textos = [
        'recibido'  => ['Recibimos tu pedido',       '📦 Recibimos tu pedido',                'Tu pedido quedó registrado. Falta confirmar el pago; te avisaremos cuando lo recibamos.'],
        'pagado'    => ['Pago recibido ✓',           '✓ Recibimos el pago de tu pedido',      'Ya recibimos tu pago. Empezaremos a preparar tu pedido y te avisaremos apenas esté listo.'],
        'listo'     => ['¡Tu pedido está listo! 🛍️', '🛍️ Tu pedido está listo para recoger',  'Ya puedes pasar por tu pedido al centro. Te esperamos con él alistado.'],
        'cancelado' => ['Tu pedido fue cancelado',   'Tu pedido fue cancelado',               'Te confirmamos que este pedido quedó cancelado. Si fue un error o ya habías pagado, escríbenos por WhatsApp y lo resolvemos.'],
    ];
    [$titulo, $asunto, $mensaje] = $textos[$evento];

    $html = '<h1 style="font-family:Georgia,serif;font-size:22px;margin:0 0 10px;color:#1a1a1a">' . e($titulo) . '</h1>'
          . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#555">Hola ' . e($pedido['cliente_nombre']) . ',</p>'
          . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">' . e($mensaje) . '</p>'
          . '<p style="margin:14px 0 0;font-size:12.5px;color:#888">Pedido #' . (int)$pedido['id'] . '</p>'
          . correoDetallePedido($pedido, $items);

    if ($evento === 'listo') {
        $html .= '<p style="margin:0;font-size:13px;line-height:1.6;color:#555"><strong>Dónde:</strong> ' . e(datoNegocio('direccion'))
               . '<br><strong>Horario:</strong> ' . e(datoNegocio('horario')) . '</p>';
    }
    return ['asunto' => $asunto, 'html' => plantillaCorreo($titulo, $html, [['texto' => 'Ver mi pedido', 'url' => $url, 'primario' => true]])];
}

/**
 * Avisa al cliente por los canales que aceptó: correo (todos los eventos) y WhatsApp
 * (solo «listo», con plantilla). Nunca lanza: avisar no debe romper la acción que lo provoca.
 *
 * @param string $evento 'recibido' · 'pagado' · 'listo' · 'cancelado'
 * @return bool true si se avisó por al menos un canal
 */
function avisarPedidoAlCliente(PDO $db, int $pedidoId, string $evento): bool {
    try {
        $pedido = pedidoPorId($db, $pedidoId);
        if (!$pedido) return false;

        $enviado = false;
        if ((int)$pedido['email_reminder'] === 1 && !empty($pedido['cliente_correo'])) {
            $m = correoPedido($pedido, itemsDePedido($db, $pedidoId), $evento, urlEstadoPedido($pedido));
            $enviado = enviarCorreo($pedido['cliente_correo'], $pedido['cliente_nombre'], $m['asunto'], $m['html']) || $enviado;
        }

        if ($evento === 'listo' && (int)$pedido['whatsapp_reminder'] === 1 && !empty($pedido['cliente_telefono'])) {
            // {{1}} nombre del cliente · {{2}} número de pedido
            $enviado = enviarWhatsAppPlantilla($pedido['cliente_telefono'], 'pedido_listo_cliente',
                [$pedido['cliente_nombre'], '#' . $pedidoId]) || $enviado;
        }

        if ($evento === 'listo' && $enviado) {
            $db->prepare('UPDATE orders SET ready_notified_at = NOW() WHERE id = ?')->execute([$pedidoId]);
        }
        return $enviado;
    } catch (Throwable $e) {
        error_log('[Blue/Tienda] No se pudo avisar al cliente del pedido ' . $pedidoId . ' (' . $evento . '): ' . $e->getMessage());
        return false;
    }
}
