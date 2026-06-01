<?php
require_once __DIR__ . '/../config/db.php';

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

require_once __DIR__ . '/../includes/admin_layout.php';

// ── Estadísticas ──────────────────────────────────────────
$today     = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$stats = [];
try {
    // Citas de hoy
    $s = $db->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND status != 'cancelled'");
    $s->execute([$today]); $stats['today'] = (int)$s->fetchColumn();

    // Pendientes
    $stats['pending'] = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();

    // Esta semana
    $s = $db->prepare("SELECT COUNT(*) FROM appointments WHERE date BETWEEN ? AND ? AND status != 'cancelled'");
    $s->execute([$weekStart, $weekEnd]); $stats['week'] = (int)$s->fetchColumn();

    // Ingresos del mes
    $s = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finances WHERE type='income' AND date BETWEEN ? AND ?");
    $s->execute([$monthStart, $monthEnd]); $stats['income'] = (float)$s->fetchColumn();

    // Últimas citas
    $recent = $db->query("
        SELECT a.id, a.date, a.time_start, a.time_end, a.status, a.created_at,
               c.name AS client_name, c.phone AS client_phone,
               GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') AS services
        FROM appointments a
        JOIN clients c ON a.client_id = c.id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id
        GROUP BY a.id
        ORDER BY a.created_at DESC
        LIMIT 8
    ")->fetchAll();

} catch (Exception $e) {
    $stats  = ['today' => 0, 'pending' => 0, 'week' => 0, 'income' => 0];
    $recent = [];
}

function badgeHtml(string $status): string {
    $map = [
        'pending'   => ['Pendiente',  'pending'],
        'confirmed' => ['Confirmada', 'confirmed'],
        'completed' => ['Completada', 'completed'],
        'cancelled' => ['Cancelada',  'cancelled'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'pending'];
    return "<span class=\"badge badge-{$cls}\">{$label}</span>";
}
?>

<!-- Stats grid -->
<div class="stats-grid">

  <div class="stat-card teal">
    <div class="stat-icon">📅</div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['today'] ?></div>
      <div class="stat-label">Citas hoy</div>
      <div class="stat-trend"><?= date('d M Y') ?></div>
    </div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon">⏳</div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['pending'] ?></div>
      <div class="stat-label">Pendientes de confirmar</div>
      <?php if ($stats['pending'] > 0): ?>
        <div class="stat-trend">
          <a href="/Blue/admin/appointments.php?status=pending" style="color:inherit;text-decoration:underline;font-size:11px">Ver todas →</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card green">
    <div class="stat-icon">📆</div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['week'] ?></div>
      <div class="stat-label">Esta semana</div>
      <div class="stat-trend"><?= date('d M', strtotime($weekStart)) ?> – <?= date('d M', strtotime($weekEnd)) ?></div>
    </div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon">💰</div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:20px"><?= formatPrice($stats['income']) ?></div>
      <div class="stat-label">Ingresos del mes</div>
      <div class="stat-trend"><?= date('F Y') ?></div>
    </div>
  </div>

</div>

<!-- Citas recientes -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Solicitudes recientes</span>
    <a href="/Blue/admin/appointments.php" class="topbar-btn" style="font-size:12px;padding:7px 14px">
      Ver todas
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
  </div>

  <div class="card-body--flush">
    <?php if (empty($recent)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">📭</div>
        <div class="empty-state-title">Sin citas aún</div>
        <div class="empty-state-desc">Las solicitudes de reserva aparecerán aquí.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Servicio(s)</th>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $appt): ?>
              <tr>
                <td>
                  <div class="client-cell">
                    <div class="client-avatar"><?= mb_substr($appt['client_name'], 0, 1) ?></div>
                    <div>
                      <div class="client-name"><?= e($appt['client_name']) ?></div>
                      <div class="client-phone"><?= e($appt['client_phone']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= e($appt['services'] ?? '—') ?>
                </td>
                <td><?= date('d M Y', strtotime($appt['date'])) ?></td>
                <td><?= date('g:i A', strtotime($appt['time_start'])) ?></td>
                <td><?= badgeHtml($appt['status']) ?></td>
                <td>
                  <div class="action-btns">
                    <?php if ($appt['status'] === 'pending'): ?>
                      <form method="POST" action="/Blue/admin/appointments.php" style="display:inline">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="id"     value="<?= $appt['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="btn-action btn-action-confirm">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                          Confirmar
                        </button>
                      </form>
                      <form method="POST" action="/Blue/admin/appointments.php" style="display:inline">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id"     value="<?= $appt['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="btn-action btn-action-cancel"
                                onclick="return confirm('¿Cancelar esta cita?')">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                          Cancelar
                        </button>
                      </form>
                    <?php else: ?>
                      <a href="/Blue/admin/appointments.php?id=<?= $appt['id'] ?>" class="btn-action">
                        Ver detalle
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
