<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/h-agenda.php';

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$extraCss   = ['/Blue/assets/css/m-agenda.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-agenda.css')];

require_once __DIR__ . '/../includes/admin_layout.php';

// ── Fechas base ───────────────────────────────────────────
$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$weekEnd    = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$mesesEs   = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$diasCorto = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
$mesNombre = ucfirst($mesesEs[(int)date('n')]) . ' ' . date('Y');

$stats = ['today' => 0, 'pending' => 0, 'week' => 0, 'income' => 0];
$dayLabels = $dayData = [];
$estadoMap = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
$topServicios = $proximas = $recent = [];

try {
    // Tarjetas
    $s = $db->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND status != 'cancelled'");
    $s->execute([$today]); $stats['today'] = (int)$s->fetchColumn();

    $stats['pending'] = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM appointments WHERE date BETWEEN ? AND ? AND status != 'cancelled'");
    $s->execute([$weekStart, $weekEnd]); $stats['week'] = (int)$s->fetchColumn();

    $s = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finances WHERE type='income' AND date BETWEEN ? AND ?");
    $s->execute([$monthStart, $monthEnd]); $stats['income'] = (float)$s->fetchColumn();

    // Citas por día (últimos 7 días)
    $cnt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND status <> 'cancelled'");
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $cnt->execute([$d]);
        $dayLabels[] = $diasCorto[(int)date('w', strtotime($d))] . ' ' . (int)date('j', strtotime($d));
        $dayData[]   = (int)$cnt->fetchColumn();
    }

    // Distribución por estado (mes actual)
    $est = $db->prepare("SELECT status, COUNT(*) c FROM appointments WHERE date BETWEEN ? AND ? GROUP BY status");
    $est->execute([$monthStart, $monthEnd]);
    foreach ($est as $r) $estadoMap[$r['status']] = (int)$r['c'];

    // Top 5 servicios del mes
    $tp = $db->prepare("
        SELECT sv.name, COUNT(*) c
        FROM appointment_services aps
        JOIN appointments a ON a.id = aps.appointment_id
        JOIN services sv     ON sv.id = aps.service_id
        WHERE a.date BETWEEN ? AND ? AND a.status <> 'cancelled'
        GROUP BY sv.id ORDER BY c DESC LIMIT 5");
    $tp->execute([$monthStart, $monthEnd]);
    $topServicios = $tp->fetchAll();

    // Próximas citas
    $up = $db->prepare("
        SELECT a.id, a.date, a.time_start, a.status, c.name AS client_name,
               GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services
        FROM appointments a
        JOIN clients c ON a.client_id = c.id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id
        WHERE a.date >= ? AND a.status IN ('pending','confirmed')
        GROUP BY a.id ORDER BY a.date, a.time_start LIMIT 5");
    $up->execute([$today]);
    $proximas = $up->fetchAll();

    // Solicitudes recientes
    $recent = $db->query("
        SELECT a.id, a.date, a.time_start, a.status, c.name AS client_name,
               GROUP_CONCAT(DISTINCT sv.name ORDER BY sv.name SEPARATOR ', ') AS services
        FROM appointments a
        JOIN clients c ON a.client_id = c.id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services sv ON sv.id = aps.service_id
        GROUP BY a.id ORDER BY a.created_at DESC LIMIT 6")->fetchAll();

} catch (Exception $e) {
    // valores por defecto ya definidos
}

$estadoTotal = array_sum($estadoMap);
$topMax = $topServicios ? max(array_map(fn($r) => (int)$r['c'], $topServicios)) : 0;

function miniLista(array $citas): string {
    if (empty($citas)) {
        return '<div class="empty-state" style="padding:30px 20px"><div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="empty-state-desc">Nada por aquí todavía.</div></div>';
    }
    $html = '<ul class="mini-list">';
    foreach ($citas as $a) {
        $html .= '<li class="mini-item">';
        $html .= '<div class="mini-avatar">' . mb_substr($a['client_name'], 0, 1) . '</div>';
        $html .= '<div class="mini-main"><div class="mini-name">' . e($a['client_name']) . '</div>';
        $html .= '<div class="mini-sub">' . e($a['services'] ?? '—') . '</div></div>';
        $html .= '<div class="mini-meta">' . badgeEstadoCita($a['status'])
               . '<div class="mini-time">' . date('d M', strtotime($a['date'])) . ' · ' . date('g:i A', strtotime($a['time_start'])) . '</div></div>';
        $html .= '</li>';
    }
    return $html . '</ul>';
}
?>

<!-- Tarjetas resumen -->
<div class="stats-grid">
  <div class="stat-card teal">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['today'] ?></div><div class="stat-label">Citas hoy</div><div class="stat-trend"><?= date('d M Y') ?></div></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['pending'] ?></div><div class="stat-label">Pendientes de confirmar</div>
      <?php if ($stats['pending'] > 0): ?><div class="stat-trend"><a href="/Blue/admin/appointments.php?status=pending" style="color:inherit;text-decoration:underline;font-size:11px">Ver todas →</a></div><?php endif; ?>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><path d="M8 15h.01M12 15h.01M16 15h.01"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['week'] ?></div><div class="stat-label">Esta semana</div><div class="stat-trend"><?= date('d M', strtotime($weekStart)) ?> – <?= date('d M', strtotime($weekEnd)) ?></div></div>
  </div>
  <div class="stat-card purple">
    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="stat-body"><div class="stat-value" style="font-size:20px"><?= formatPrice($stats['income']) ?></div><div class="stat-label">Ingresos del mes</div><div class="stat-trend"><?= e($mesNombre) ?></div></div>
  </div>
</div>

<!-- Masonry de gráficas e información -->
<div class="dash-masonry">

  <div class="card">
    <div class="card-header"><span class="card-title">Citas por día</span><span class="dash-sub">Últimos 7 días</span></div>
    <div class="card-body"><div class="dash-chart-wrap"><canvas id="chartDias"></canvas></div></div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Estados de citas</span><span class="dash-sub"><?= e($mesNombre) ?></span></div>
    <div class="card-body">
      <?php if ($estadoTotal): ?>
        <div class="dash-chart-wrap" style="height:210px"><canvas id="chartEstados"></canvas></div>
      <?php else: ?>
        <div class="empty-state" style="padding:30px 20px"><div class="empty-state-desc">Sin citas este mes.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Top servicios del mes</span></div>
    <div class="card-body">
      <?php if (empty($topServicios)): ?>
        <div class="empty-state" style="padding:30px 20px"><div class="empty-state-desc">Aún no hay servicios agendados este mes.</div></div>
      <?php else: foreach ($topServicios as $i => $s): $pct = $topMax ? round((int)$s['c'] / $topMax * 100) : 0; ?>
        <div class="top-serv">
          <div class="top-serv-head">
            <span class="top-serv-rank">#<?= $i + 1 ?></span>
            <span class="top-serv-name"><?= e($s['name']) ?></span>
            <span class="top-serv-cnt"><?= (int)$s['c'] ?></span>
          </div>
          <div class="top-serv-bar"><span style="width:<?= $pct ?>%"></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Próximas citas</span><a href="/Blue/admin/appointments.php" class="dash-link">Ver todas</a></div>
    <div class="card-body--flush"><?= miniLista($proximas) ?></div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Solicitudes recientes</span><a href="/Blue/admin/appointments.php" class="dash-link">Ver todas</a></div>
    <div class="card-body--flush"><?= miniLista($recent) ?></div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
  Chart.defaults.color = '#9ca3af';

  var dias = document.getElementById('chartDias');
  if (dias) new Chart(dias, {
    type: 'bar',
    data: { labels: <?= json_encode($dayLabels) ?>,
      datasets: [{ data: <?= json_encode($dayData) ?>, backgroundColor: 'rgba(91,196,184,.8)', hoverBackgroundColor: '#3aa89e', borderRadius: 6, maxBarThickness: 36 }] },
    options: { plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f3f5' } }, x: { grid: { display: false } } },
      responsive: true, maintainAspectRatio: false }
  });

  var est = document.getElementById('chartEstados');
  if (est) new Chart(est, {
    type: 'doughnut',
    data: { labels: ['Pendiente', 'Confirmada', 'Completada', 'Cancelada'],
      datasets: [{ data: [<?= $estadoMap['pending'] ?>, <?= $estadoMap['confirmed'] ?>, <?= $estadoMap['completed'] ?>, <?= $estadoMap['cancelled'] ?>],
        backgroundColor: ['#f59e0b', '#10b981', '#6366f1', '#ef4444'], borderWidth: 0, hoverOffset: 6 }] },
    options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 11, padding: 12, font: { size: 11 } } } },
      responsive: true, maintainAspectRatio: false }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
