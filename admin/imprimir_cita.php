<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agenda.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("
    SELECT a.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
           u.name AS staff_name,
           GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services,
           COALESCE(SUM(sv.price),0) AS total_price
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    LEFT JOIN users u ON a.staff_id = u.id
    LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
    LEFT JOIN services sv ON sv.id = aps.service_id
    WHERE a.id = ? GROUP BY a.id");
$stmt->execute([$id]);
$cita = $stmt->fetch();

if (!$cita) {
    http_response_code(404);
    exit('Cita no encontrada.');
}

$est = statusLabel($cita['status']);
$fechaLarga = formatDate($cita['date']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cita #<?= (int)$cita['id'] ?> — Blue Therapy</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap');
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', system-ui, sans-serif; color: #1f2937; background: #f0f2f5; padding: 30px; }
    .sheet { max-width: 620px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 40px; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
    .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #5bc4b8; padding-bottom: 18px; margin-bottom: 24px; }
    .brand { font-family: 'Playfair Display', serif; font-size: 24px; }
    .brand b { color: #111; } .brand span { color: #3aa89e; }
    .doc-id { text-align: right; font-size: 12px; color: #6b7280; }
    .doc-id strong { display: block; font-size: 16px; color: #111; }
    h1 { font-family: 'Playfair Display', serif; font-size: 20px; margin-bottom: 4px; }
    .sub { color: #6b7280; font-size: 13px; margin-bottom: 24px; }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .b-pending{background:#fef3c7;color:#92400e}.b-confirmed{background:#d1fae5;color:#065f46}
    .b-completed{background:#e0e7ff;color:#3730a3}.b-cancelled{background:#fee2e2;color:#991b1b}
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    td { padding: 11px 0; border-bottom: 1px solid #eef0f2; font-size: 14px; vertical-align: top; }
    td.k { color: #6b7280; width: 38%; } td.v { font-weight: 600; text-align: right; }
    .total td { border-top: 2px solid #111; border-bottom: none; padding-top: 14px; font-size: 16px; }
    .total .v { color: #3aa89e; font-weight: 700; }
    .foot { margin-top: 28px; text-align: center; color: #9ca3af; font-size: 12px; }
    .actions { max-width: 620px; margin: 18px auto 0; display: flex; gap: 10px; justify-content: center; }
    .btn { padding: 10px 20px; border-radius: 9px; border: 1.5px solid #e5e7eb; background: #fff; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; color: #374151; text-decoration: none; }
    .btn-print { background: #5bc4b8; border-color: #5bc4b8; color: #fff; }
    @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; border-radius: 0; max-width: 100%; } .actions { display: none; } }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="head">
      <div class="brand"><b>Blue</b> <span>Therapy</span></div>
      <div class="doc-id">Comprobante de cita<strong>#<?= (int)$cita['id'] ?></strong></div>
    </div>

    <h1>Detalle de la cita</h1>
    <div class="sub">Generado el <?= formatDate(date('Y-m-d')) ?></div>

    <table>
      <tr><td class="k">Estado</td><td class="v"><span class="badge b-<?= e($cita['status']) ?>"><?= e($est['label']) ?></span></td></tr>
      <tr><td class="k">Cliente</td><td class="v"><?= e($cita['client_name']) ?></td></tr>
      <tr><td class="k">Teléfono</td><td class="v"><?= e($cita['client_phone']) ?></td></tr>
      <?php if ($cita['client_email']): ?><tr><td class="k">Correo</td><td class="v"><?= e($cita['client_email']) ?></td></tr><?php endif; ?>
      <tr><td class="k">Fecha</td><td class="v"><?= e($fechaLarga) ?></td></tr>
      <tr><td class="k">Horario</td><td class="v"><?= formatTime($cita['time_start']) ?> – <?= formatTime($cita['time_end']) ?></td></tr>
      <tr><td class="k">Duración</td><td class="v"><?= (int)$cita['total_duration'] ?> min</td></tr>
      <tr><td class="k">Profesional</td><td class="v"><?= $cita['staff_name'] ? e($cita['staff_name']) : 'Sin asignar' ?></td></tr>
      <tr><td class="k">Servicio(s)</td><td class="v"><?= e($cita['services'] ?? '—') ?></td></tr>
      <?php if ($cita['notes']): ?><tr><td class="k">Notas</td><td class="v"><?= e($cita['notes']) ?></td></tr><?php endif; ?>
      <tr class="total"><td class="k">Total</td><td class="v"><?= formatPrice((float)$cita['total_price']) ?></td></tr>
    </table>

    <div class="foot">Gracias por confiar en Blue Therapy · Este documento es un comprobante interno de la cita.</div>
  </div>

  <div class="actions">
    <button class="btn btn-print" onclick="window.print()">Imprimir</button>
    <a class="btn" href="/Blue/admin/appointments.php">Volver</a>
  </div>

  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
