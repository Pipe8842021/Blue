<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agenda.php';

requireLogin('/Blue/login.php');
$db   = getDB();
$me   = currentUser();
$myId = (int)$me['id'];

// ── Crear cita desde el calendario ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/staff/calendario.php'); exit;
    }
    if (($_POST['action'] ?? '') === 'save_cita') {
        $r = guardarCita($db, $_POST, $myId, $myId);
        setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
    }
    $qs = $_POST['return_qs'] ?? '';
    header('Location: /Blue/staff/calendario.php' . ($qs ? '?' . $qs : '')); exit;
}

// ── Mes a mostrar ─────────────────────────────────────────
$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
[$year, $month] = array_map('intval', explode('-', $ym));

$primero    = sprintf('%04d-%02d-01', $year, $month);
$diasMes    = (int)date('t', strtotime($primero));
$offset     = (int)date('N', strtotime($primero)) - 1; // 0=lunes
$prevYm     = date('Y-m', strtotime($primero . ' -1 month'));
$nextYm     = date('Y-m', strtotime($primero . ' +1 month'));
$hoy        = date('Y-m-d');

$citasMes = citasDelMes($db, $myId, $year, $month);

$mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$clientes  = obtenerClientes($db);
$servicios = obtenerServiciosActivos($db);
$returnQs  = 'ym=' . $ym;

$pageTitle  = 'Calendario';
$activePage = 'calendario';
$topbarActions = '<button class="topbar-btn topbar-btn-primary" onclick="openNuevaCita()">+ Nueva cita</button>';
require_once __DIR__ . '/_layout.php';
?>

<div class="page-head">
  <div><h2>Calendario</h2><p>Disposición de tus citas del mes</p></div>
  <div class="cal-nav">
    <a class="btn btn-sm" href="?ym=<?= $prevYm ?>">‹</a>
    <span class="cal-title"><?= $mesesEs[$month] ?> <?= $year ?></span>
    <a class="btn btn-sm" href="?ym=<?= $nextYm ?>">›</a>
    <a class="btn btn-sm btn-ghost" href="?ym=<?= date('Y-m') ?>">Hoy</a>
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
            <div class="cal-chip cal-<?= e($c['status']) ?>" title="<?= e(date('g:i A', strtotime($c['time_start'])) . ' · ' . $c['client_name'] . ' · ' . ($c['services'] ?? '')) ?>">
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
    <span><i class="cal-dot cal-completed"></i> Atendida</span>
    <span><i class="cal-dot cal-cancelled"></i> Cancelada</span>
  </div>
</div></div>

<?php
$esAdmin = false;
require_once __DIR__ . '/../includes/_modal_cita.php';
require_once __DIR__ . '/_footer.php';
?>
