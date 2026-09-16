<?php
/**
 * Agenda de una profesional: horario semanal y servicios que realiza.
 * Se abre desde Configuración → Equipo. Solo admin.
 *
 * Con al menos una profesional con horario, la reserva en línea pasa a
 * trabajar con agendas: el cliente elige a una o «cualquiera disponible».
 * Mientras nadie tenga horario, el sitio usa el horario general.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agendas.php';

requireRole('admin', '/Blue/login.php');
$db = getDB();

// ── Guardar (POST) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = (int)($_POST['id'] ?? 0);
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
    } else {
        $r = guardarAgendaProfesional($db, $staffId, $_POST);
        setFlash($r['ok'] ? 'success' : 'error', $r['msg']);
    }
    header('Location: /Blue/admin/agenda_personal.php?id=' . $staffId);
    exit;
}

$staffId     = (int)($_GET['id'] ?? 0);
$profesional = profesionalStaff($db, $staffId);

if (!$profesional) {
    setFlash('error', 'Esa persona no existe o no tiene rol Staff. Los administradores no aparecen en la reserva en línea.');
    header('Location: /Blue/admin/settings.php?tab=team');
    exit;
}

$horario        = horarioSemanalDeProfesional($db, $staffId);
$serviciosSuyos = serviciosDeProfesional($db, $staffId);
$servicios      = $db->query(
    "SELECT s.id, s.name, s.is_assessment, c.name AS categoria
       FROM services s JOIN categories c ON c.id = s.category_id
      WHERE s.active = 1
      ORDER BY c.name, s.name"
)->fetchAll();

$conHorario = profesionalesConHorario($db);
$esLaUnica  = array_keys($conHorario) === [$staffId];
$sinAgendas = !$conHorario;

$pageTitle  = 'Agenda de ' . $profesional['name'];
$activePage = 'settings';
$extraCss   = ['/Blue/assets/css/m-agenda.css?v=' . @filemtime(__DIR__ . '/../assets/css/m-agenda.css')];
$topbarActions = '<a href="/Blue/admin/settings.php?tab=team" class="topbar-btn">Volver al equipo</a>';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="page-head">
  <div>
    <h2>Agenda de <?= e($profesional['name']) ?></h2>
    <p>Horario de atención y servicios que realiza en la reserva en línea</p>
  </div>
</div>

<?php if (!$profesional['active']): ?>
  <div class="flash flash-info"><div>
    Esta persona está <strong>inactiva</strong>: aunque tenga horario, no aparece en la reserva en línea.
  </div></div>
<?php elseif ($sinAgendas): ?>
  <div class="flash flash-info"><div>
    Todavía nadie tiene horario cargado, así que el sitio usa el <strong>horario general</strong>
    (lunes a sábado de 8:00 a 19:00, una cita por horario). Al guardar el primer horario, la reserva
    en línea pasa a trabajar con la agenda de cada profesional.
  </div></div>
<?php elseif ($esLaUnica): ?>
  <div class="flash flash-info"><div>
    Por ahora es la <strong>única profesional con horario</strong>: el sitio solo ofrece los horarios que
    ella atienda. Carga el horario de las demás para abrir más cupos.
  </div></div>
<?php endif; ?>

<form method="POST" id="formAgenda">
  <input type="hidden" name="id" value="<?= (int)$staffId ?>">
  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

  <!-- ══ Horario semanal ══ -->
  <div class="card">
    <div class="card-header">
      <h3>Horario semanal</h3>
      <span class="agenda-ayuda">Marca los días que trabaja. El segundo turno es opcional (ej. después del almuerzo).</span>
    </div>
    <div class="card-body">
      <?php
      // El formulario usa 0=Dom … 6=Sáb, pero se muestra empezando por el lunes.
      foreach ([1, 2, 3, 4, 5, 6, 0] as $dia):
          $turnos = $horario[$dia] ?? [];
          $activo = !empty($turnos);
      ?>
        <div class="agenda-dia<?= $activo ? ' activo' : '' ?>" data-dia="<?= $dia ?>">
          <label class="agenda-dia-check">
            <input type="checkbox" name="dias[<?= $dia ?>][activo]" value="1" <?= $activo ? 'checked' : '' ?>>
            <span><?= e(AGENDA_NOMBRES_DIA[$dia]) ?></span>
          </label>

          <div class="agenda-turnos">
            <?php for ($t = 0; $t < AGENDA_TURNOS_POR_DIA; $t++): ?>
              <div class="agenda-turno">
                <span class="agenda-turno-etiqueta"><?= $t === 0 ? 'Turno' : '2º turno' ?></span>
                <input type="time" class="form-control" name="dias[<?= $dia ?>][turnos][<?= $t ?>][ini]"
                       value="<?= e($turnos[$t]['ini'] ?? '') ?>" <?= $activo ? '' : 'disabled' ?>>
                <span class="agenda-turno-a">a</span>
                <input type="time" class="form-control" name="dias[<?= $dia ?>][turnos][<?= $t ?>][fin]"
                       value="<?= e($turnos[$t]['fin'] ?? '') ?>" <?= $activo ? '' : 'disabled' ?>>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ══ Servicios que realiza ══ -->
  <div class="card">
    <div class="card-header">
      <h3>Servicios que realiza</h3>
      <span class="agenda-ayuda">Si limitas los servicios, solo aparecerá cuando el cliente elija alguno de ellos.</span>
    </div>
    <div class="card-body">
      <label class="agenda-modo">
        <input type="radio" name="servicios_modo" value="todos" <?= $serviciosSuyos ? '' : 'checked' ?>>
        <span>Realiza <strong>todos</strong> los servicios activos</span>
      </label>
      <label class="agenda-modo">
        <input type="radio" name="servicios_modo" value="algunos" <?= $serviciosSuyos ? 'checked' : '' ?>>
        <span>Realiza <strong>solo los que marque</strong></span>
      </label>

      <div class="agenda-servicios" id="agendaServicios" <?= $serviciosSuyos ? '' : 'hidden' ?>>
        <?php $categoriaActual = null; foreach ($servicios as $s): ?>
          <?php if ($s['categoria'] !== $categoriaActual): $categoriaActual = $s['categoria']; ?>
            <div class="agenda-servicios-cat"><?= e($categoriaActual) ?></div>
          <?php endif; ?>
          <label class="agenda-servicio">
            <input type="checkbox" name="servicios[]" value="<?= (int)$s['id'] ?>"
                   <?= in_array((int)$s['id'], $serviciosSuyos, true) ? 'checked' : '' ?>>
            <span><?= e($s['name']) ?><?= (int)$s['is_assessment'] === 1 ? ' <span class="pill pill-muted">Valoración</span>' : '' ?></span>
          </label>
        <?php endforeach; ?>
        <?php if (!$servicios): ?>
          <p class="agenda-ayuda">No hay servicios activos todavía.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="agenda-acciones">
    <a href="/Blue/admin/settings.php?tab=team" class="btn btn-ghost">Cancelar</a>
    <button type="submit" class="btn btn-primary">Guardar agenda</button>
  </div>
</form>

<script>
// Habilita las horas solo de los días marcados.
document.querySelectorAll('.agenda-dia').forEach(fila => {
  const check = fila.querySelector('.agenda-dia-check input');
  const horas = fila.querySelectorAll('input[type="time"]');
  check.addEventListener('change', () => {
    fila.classList.toggle('activo', check.checked);
    horas.forEach(h => { h.disabled = !check.checked; });
  });
});

// La lista de servicios solo aparece si limita los que realiza.
document.querySelectorAll('input[name="servicios_modo"]').forEach(radio => {
  radio.addEventListener('change', () => {
    document.getElementById('agendaServicios').hidden = radio.value !== 'algunos';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
