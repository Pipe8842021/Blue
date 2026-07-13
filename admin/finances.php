<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

// Cabeceras de seguridad — panel con datos financieros sensibles.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/finances.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    $returnParams = http_build_query(array_filter([
        'date_from' => $_POST['return_from'] ?? '',
        'date_to'   => $_POST['return_to']   ?? '',
        'type'      => $_POST['return_type'] ?? '',
    ]));
    try {
        switch ($action) {
            case 'save':
                $id     = (int)($_POST['id'] ?? 0);
                $type   = in_array($_POST['type'] ?? '', ['income','expense'], true) ? $_POST['type'] : 'income';
                $cat    = trim($_POST['category'] ?? '');
                $desc   = trim($_POST['description'] ?? '');
                $amount = max(0, (float)($_POST['amount'] ?? 0));
                $date   = $_POST['date'] ?? date('Y-m-d');
                if ($cat === '' || $amount <= 0) { setFlash('error', 'Categoría y monto son obligatorios.'); break; }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

                if ($id > 0) {
                    $db->prepare("UPDATE finances SET type=?, category=?, description=?, amount=?, date=? WHERE id=?")
                       ->execute([$type, $cat, $desc ?: null, $amount, $date, $id]);
                    setFlash('success', 'Movimiento actualizado.');
                } else {
                    $db->prepare("INSERT INTO finances (type, category, description, amount, date, registered_by) VALUES (?,?,?,?,?,?)")
                       ->execute([$type, $cat, $desc ?: null, $amount, $date, currentUser()['id']]);
                    setFlash('success', 'Movimiento registrado.');
                }
                break;

            case 'delete':
                $db->prepare("DELETE FROM finances WHERE id=?")->execute([(int)$_POST['id']]);
                setFlash('info', 'Movimiento eliminado.');
                break;
        }
    } catch (Exception $e) {
        setFlash('error', 'Ocurrió un error al guardar.');
    }
    header('Location: /Blue/admin/finances.php?' . $returnParams); exit;
}

// ── Filtros por rango de fechas ───────────────────────────
$fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$fTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');
if ($fTo < $fFrom) $fTo = $fFrom;
$fType = in_array($_GET['type'] ?? '', ['income','expense'], true) ? $_GET['type'] : '';

$where = ['date BETWEEN ? AND ?'];
$args  = [$fFrom, $fTo];
if ($fType) { $where[] = 'type = ?'; $args[] = $fType; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

// Resumen del periodo seleccionado (sin filtro de tipo)
$sum = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) AS income,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS expense
    FROM finances WHERE date BETWEEN ? AND ?");
$sum->execute([$fFrom, $fTo]);
$totals  = $sum->fetch();
$balance = (float)$totals['income'] - (float)$totals['expense'];

// Movimientos filtrados
$stmt = $db->prepare("
    SELECT f.*, u.name AS registered_name
    FROM finances f LEFT JOIN users u ON f.registered_by = u.id
    $whereSql ORDER BY f.date DESC, f.id DESC");
$stmt->execute($args);
$movements = $stmt->fetchAll();

// Datos gráfico — últimos 6 meses completos
$chartRows = $db->query("
    SELECT DATE_FORMAT(date,'%Y-%m') AS ym,
           COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) AS income,
           COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS expense
    FROM finances
    WHERE date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
    GROUP BY ym ORDER BY ym
")->fetchAll();

// ── Exportar CSV ──────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finanzas-' . $fFrom . '-al-' . $fTo . '.csv"');
    header('Cache-Control: no-cache, no-store');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM para compatibilidad con Excel

    // Evita CSV/Formula Injection: si un valor empieza con =, +, -, @ o tab,
    // Excel/Sheets puede interpretarlo como fórmula al abrir el archivo.
    $csvSafe = function ($v) {
        $v = (string)$v;
        return $v !== '' && preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v;
    };

    fputcsv($out, ['Fecha', 'Categoría', 'Descripción', 'Tipo', 'Monto (COP)']);
    foreach ($movements as $m) {
        fputcsv($out, [
            $m['date'],
            $csvSafe($m['category']),
            $csvSafe($m['description'] ?? ''),
            $m['type'] === 'income' ? 'Ingreso' : 'Egreso',
            number_format((float)$m['amount'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Finanzas';
$activePage = 'finances';
$extraCss   = ['/Blue/assets/css/m-finanzas.css'];
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Finanzas</h2>
    <p>Del <?= e(date('d/m/Y', strtotime($fFrom))) ?> al <?= e(date('d/m/Y', strtotime($fTo))) ?></p>
  </div>
  <button class="btn btn-primary" onclick="newMovement()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Nuevo movimiento
  </button>
</div>

<!-- Resumen del periodo -->
<div class="summary-grid">
  <div class="summary-card income">
    <div class="label">Ingresos del periodo</div>
    <div class="value"><?= formatPrice((float)$totals['income']) ?></div>
  </div>
  <div class="summary-card expense">
    <div class="label">Egresos del periodo</div>
    <div class="value"><?= formatPrice((float)$totals['expense']) ?></div>
  </div>
  <div class="summary-card balance">
    <div class="label">Balance</div>
    <div class="value" style="<?= $balance < 0 ? 'color:var(--cancelled)' : '' ?>"><?= formatPrice($balance) ?></div>
  </div>
</div>

<!-- Gráfico ingresos vs egresos -->
<?php if (!empty($chartRows)): ?>
<?php
$mesAbrev = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun',
             '07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
$chartLabels  = implode(',', array_map(
    fn($r) => '"' . ($mesAbrev[substr($r['ym'],5,2)] ?? '') . ' ' . substr($r['ym'],0,4) . '"',
    $chartRows
));
$chartIncome  = implode(',', array_map(fn($r) => (float)$r['income'],  $chartRows));
$chartExpense = implode(',', array_map(fn($r) => (float)$r['expense'], $chartRows));
?>
<div class="card card-chart">
  <div class="card-header">
    <span>Ingresos vs. Egresos — últimos 6 meses</span>
    <div class="chart-legend">
      <span><span class="chart-legend-dot" style="background:rgba(16,185,129,.8)"></span>Ingresos</span>
      <span><span class="chart-legend-dot" style="background:rgba(239,68,68,.75)"></span>Egresos</span>
    </div>
  </div>
  <div class="card-body">
    <div class="fin-chart-wrap"><canvas id="finChart"></canvas></div>
  </div>
</div>
<?php endif; ?>

<!-- Filtros -->
<form method="GET" class="filters-bar">
  <div class="fin-date-range">
    <input type="date" name="date_from" class="filter-input" value="<?= e($fFrom) ?>" onchange="this.form.submit()">
    <span class="filter-sep">al</span>
    <input type="date" name="date_to" class="filter-input" value="<?= e($fTo) ?>" onchange="this.form.submit()">
  </div>
  <select name="type" class="filter-select" onchange="this.form.submit()">
    <option value="">Todos los movimientos</option>
    <option value="income"  <?= $fType==='income'  ?'selected':'' ?>>Solo ingresos</option>
    <option value="expense" <?= $fType==='expense' ?'selected':'' ?>>Solo egresos</option>
  </select>
  <a href="?date_from=<?= urlencode($fFrom) ?>&date_to=<?= urlencode($fTo) ?>&type=<?= urlencode($fType) ?>&export=csv"
     class="btn-export">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Exportar CSV
  </a>
</form>

<div class="card">
  <div class="card-body--flush">
    <?php if (empty($movements)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <div class="empty-state-title">Sin movimientos</div>
        <div class="empty-state-desc">No hay registros en este periodo.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Tipo</th><th>Monto</th><th>Registró</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($movements as $m): ?>
            <tr>
              <td><?= date('d M Y', strtotime($m['date'])) ?></td>
              <td><span class="pill"><?= e($m['category']) ?></span></td>
              <td style="color:var(--muted);max-width:240px"><?= e($m['description'] ?? '—') ?></td>
              <td><?= $m['type']==='income' ? '<span class="type-income">Ingreso</span>' : '<span class="type-expense">Egreso</span>' ?></td>
              <td class="<?= $m['type']==='income' ? 'type-income' : 'type-expense' ?>">
                <?= $m['type']==='income' ? '+' : '−' ?> <?= formatPrice((float)$m['amount']) ?>
              </td>
              <td style="color:var(--muted);font-size:12px"><?= e($m['registered_name'] ?? '—') ?></td>
              <td>
                <div class="action-btns">
                  <button class="btn-action" onclick='editMovement(<?= json_encode($m, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <input type="hidden" name="return_from" value="<?= e($fFrom) ?>">
                    <input type="hidden" name="return_to"   value="<?= e($fTo) ?>">
                    <input type="hidden" name="return_type" value="<?= e($fType) ?>">
                    <input type="hidden" name="csrf_token"  value="<?= e(csrfToken()) ?>">
                    <button class="btn-action btn-action-cancel" onclick="return confirm('¿Eliminar movimiento?')">Eliminar</button>
                  </form>
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

<!-- Modal movimiento -->
<div class="modal-overlay" id="movModal">
  <div class="modal" style="max-width:480px">
    <form method="POST">
      <div class="modal-header">
        <span class="modal-title" id="movTitle">Nuevo movimiento</span>
        <button type="button" class="modal-close" onclick="closeModal('movModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-field">
            <label>Tipo</label>
            <select name="type" id="mv_type" class="form-control">
              <option value="income">Ingreso</option>
              <option value="expense">Egreso</option>
            </select>
          </div>
          <div class="form-field">
            <label>Fecha</label>
            <input type="date" name="date" id="mv_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-field full">
            <label>Categoría</label>
            <input type="text" name="category" id="mv_cat" class="form-control" list="catList" placeholder="Ej: Servicios, Insumos, Arriendo…" required>
            <datalist id="catList">
              <option value="Servicios"><option value="Productos"><option value="Insumos">
              <option value="Arriendo"><option value="Servicios públicos"><option value="Nómina">
              <option value="Marketing"><option value="Otros">
            </datalist>
          </div>
          <div class="form-field full">
            <label>Monto (COP)</label>
            <input type="number" name="amount" id="mv_amount" class="form-control" min="0" step="1000" required>
          </div>
          <div class="form-field full">
            <label>Descripción (opcional)</label>
            <textarea name="description" id="mv_desc" class="form-control"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="mv_id" value="0">
        <input type="hidden" name="return_from" value="<?= e($fFrom) ?>">
        <input type="hidden" name="return_to"   value="<?= e($fTo) ?>">
        <input type="hidden" name="return_type" value="<?= e($fType) ?>">
        <input type="hidden" name="csrf_token"  value="<?= e(csrfToken()) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('movModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function newMovement() {
  document.getElementById('movTitle').textContent  = 'Nuevo movimiento';
  document.getElementById('mv_id').value           = 0;
  document.getElementById('mv_type').value         = 'income';
  document.getElementById('mv_cat').value          = '';
  document.getElementById('mv_amount').value       = '';
  document.getElementById('mv_desc').value         = '';
  document.getElementById('mv_date').value         = '<?= date('Y-m-d') ?>';
  openModal('movModal');
}

function editMovement(m) {
  document.getElementById('movTitle').textContent  = 'Editar movimiento';
  document.getElementById('mv_id').value           = m.id;
  document.getElementById('mv_type').value         = m.type;
  document.getElementById('mv_cat').value          = m.category;
  document.getElementById('mv_amount').value       = parseFloat(m.amount);
  document.getElementById('mv_desc').value         = m.description || '';
  document.getElementById('mv_date').value         = m.date;
  openModal('movModal');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// ── Gráfico Chart.js ─────────────────────────────────────
(function () {
  const canvas = document.getElementById('finChart');
  if (!canvas) return;

  new Chart(canvas.getContext('2d'), {
    type: 'bar',
    data: {
      labels:   [<?= $chartLabels  ?? '' ?>],
      datasets: [
        {
          label:           'Ingresos',
          data:            [<?= $chartIncome  ?? '' ?>],
          backgroundColor: 'rgba(16,185,129,.75)',
          borderRadius:    6,
          borderSkipped:   false,
        },
        {
          label:           'Egresos',
          data:            [<?= $chartExpense ?? '' ?>],
          backgroundColor: 'rgba(239,68,68,.65)',
          borderRadius:    6,
          borderSkipped:   false,
        },
      ],
    },
    options: {
      responsive:         true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + ': $' + parseInt(ctx.raw).toLocaleString('es-CO'),
          },
        },
      },
      scales: {
        x: { grid: { display: false }, border: { display: false } },
        y: {
          beginAtZero: true,
          border:  { display: false },
          grid:    { color: 'rgba(0,0,0,.05)' },
          ticks:   { callback: v => '$' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v) },
        },
      },
    },
  });
}());
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
