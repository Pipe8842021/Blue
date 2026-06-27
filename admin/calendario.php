<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agenda.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Crear/editar cita desde el calendario ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/calendario.php'); exit;
    }
    if (($_POST['action'] ?? '') === 'save_cita') {
        $r = guardarCita($db, $_POST, null, (int)currentUser()['id']); // admin: profesional según el formulario
        setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
    }
    $qs = $_POST['return_qs'] ?? '';
    header('Location: /Blue/admin/calendario.php' . ($qs ? '?' . $qs : '')); exit;
}

// ── Mes y filtro de profesional ───────────────────────────
$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
[$year, $month] = array_map('intval', explode('-', $ym));
$staffFilter = (int)($_GET['staff'] ?? 0) ?: null;

$primero = sprintf('%04d-%02d-01', $year, $month);
$diasMes = (int)date('t', strtotime($primero));
$offset  = (int)date('N', strtotime($primero)) - 1;
$prevYm  = date('Y-m', strtotime($primero . ' -1 month'));
$nextYm  = date('Y-m', strtotime($primero . ' +1 month'));
$hoy     = date('Y-m-d');

$citasMes = citasDelMes($db, $staffFilter, $year, $month);

$mesesEs   = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$staffList = obtenerStaffActivo($db);
$clientes  = obtenerClientes($db);
$servicios = obtenerServiciosActivos($db);
$returnQs  = http_build_query(array_filter(['ym' => $ym, 'staff' => $staffFilter]));

$pageTitle  = 'Calendario';
$activePage = 'appointments';
$extraCss   = ['/Blue/assets/css/m-agenda.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-agenda.css')];
$topbarActions = '<a href="/Blue/admin/appointments.php" class="topbar-btn">Ver lista</a>'
               . '<button class="topbar-btn topbar-btn-primary" onclick="openNuevaCita()">+ Nueva cita</button>';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div><h2>Calendario de citas</h2><p>Disposición mensual de todas las citas</p></div>
  <div class="cal-nav">
    <form method="GET" style="margin-right:6px">
      <input type="hidden" name="ym" value="<?= e($ym) ?>">
      <select name="staff" class="filter-select" onchange="this.form.submit()">
        <option value="">Todos los profesionales</option>
        <?php foreach ($staffList as $st): ?>
          <option value="<?= $st['id'] ?>" <?= $staffFilter === (int)$st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn-sm" href="?ym=<?= $prevYm ?>&staff=<?= $staffFilter ?>">‹</a>
    <span class="cal-title"><?= $mesesEs[$month] ?> <?= $year ?></span>
    <a class="btn btn-sm" href="?ym=<?= $nextYm ?>&staff=<?= $staffFilter ?>">›</a>
    <a class="btn btn-sm btn-ghost" href="?ym=<?= date('Y-m') ?>&staff=<?= $staffFilter ?>">Hoy</a>
  </div>
</div>

<div class="card"><div class="card-body">
  <div class="cal-grid cal-head">
    <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d): ?>
      <div class="cal-dow"><?= $d ?></div>
    <?php endforeach; ?>
  </div>
  <div class="cal-grid">
    <?php for ($i = 0; $i < $offset; $i++): ?>
      <div class="cal-cell cal-empty"></div>
    <?php endfor; ?>

    <?php for ($d = 1; $d <= $diasMes; $d++):
      $fecha = sprintf('%04d-%02d-%02d', $year, $month, $d);
      $delDia = $citasMes[$fecha] ?? [];
      $esHoy  = $fecha === $hoy; ?>
      <div class="cal-cell <?= $esHoy ? 'cal-today' : '' ?>">
        <div class="cal-cell-head">
          <span class="cal-daynum"><?= $d ?></span>
          <button class="cal-add" title="Nueva cita" onclick="openNuevaCita('<?= $fecha ?>')">+</button>
        </div>
        <div class="cal-chips">
          <?php foreach ($delDia as $c): ?>
            <div class="cal-chip cal-<?= e($c['status']) ?>"
                 title="<?= e(date('g:i A', strtotime($c['time_start'])) . ' · ' . $c['client_name']
                          . ($c['staff_name'] ? ' · ' . $c['staff_name'] : ' · Sin asignar')
                          . ' · ' . ($c['services'] ?? '')) ?>">
              <span class="cal-chip-time"><?= date('g:i', strtotime($c['time_start'])) ?></span>
              <span class="cal-chip-name"><?= e($c['client_name']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <div class="cal-legend">
    <span><i class="cal-dot cal-pending"></i> Pendiente</span>
    <span><i class="cal-dot cal-confirmed"></i> Confirmada</span>
    <span><i class="cal-dot cal-completed"></i> Completada</span>
    <span><i class="cal-dot cal-cancelled"></i> Cancelada</span>
  </div>
</div></div>

<?php
$esAdmin = true;
require_once __DIR__ . '/../includes/_modal_cita.php';
require_once __DIR__ . '/../includes/admin_footer.php';
?>
