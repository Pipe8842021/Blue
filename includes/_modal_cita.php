<?php
/**
 * _modal_cita.php — Modal/form reutilizable para crear y editar citas.
 * Parte del módulo Agenda (Felipe). Lo incluyen staff/citas.php y admin/appointments.php.
 *
 * Variables esperadas antes de incluir:
 *   $clientes   array   — lista de clientes (id, name, phone)
 *   $servicios  array   — servicios activos (id, name, duration_min, price)
 *   $esAdmin    bool    — si true muestra el selector de profesional
 *   $staffList  array   — (solo admin) lista de profesionales (id, name)
 *   $returnQs   string  — query string para conservar filtros tras guardar
 */
$staffList = $staffList ?? [];
$returnQs  = $returnQs  ?? '';
?>
<div class="modal-overlay" id="citaModal">
  <div class="modal" style="max-width:560px">
    <form method="POST">
      <div class="modal-header">
        <span class="modal-title" id="citaModalTitle">Nueva cita</span>
        <button type="button" class="modal-close" onclick="closeModal('citaModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">

          <div class="form-field full">
            <label>Cliente</label>
            <select name="client_id" id="cita_client" class="form-control" onchange="toggleNuevoCliente()">
              <option value="">— Selecciona un cliente —</option>
              <option value="0">+ Cliente nuevo…</option>
              <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> · <?= e($c['phone']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-field" id="cita_nc_name_wrap" style="display:none">
            <label>Nombre del nuevo cliente</label>
            <input type="text" name="new_client_name" id="cita_nc_name" class="form-control" placeholder="Nombre completo">
          </div>
          <div class="form-field" id="cita_nc_phone_wrap" style="display:none">
            <label>Teléfono</label>
            <input type="tel" name="new_client_phone" id="cita_nc_phone" class="form-control" placeholder="+57 300 000 0000">
          </div>

          <div class="form-field full">
            <label>Servicios</label>
            <div class="cita-servicios">
              <?php foreach ($servicios as $s): ?>
                <label class="cita-serv">
                  <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" data-dur="<?= (int)$s['duration_min'] ?>">
                  <span><?= e($s['name']) ?></span>
                  <em><?= (int)$s['duration_min'] ?> min · <?= formatPrice((float)$s['price']) ?></em>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="form-hint" id="cita_dur_hint">Duración total: 0 min</div>
          </div>

          <div class="form-field">
            <label>Fecha</label>
            <input type="date" name="date" id="cita_date" class="form-control" required>
          </div>
          <div class="form-field">
            <label>Hora de inicio</label>
            <input type="time" name="time_start" id="cita_time" class="form-control" required>
          </div>

          <?php if ($esAdmin): ?>
          <div class="form-field">
            <label>Profesional</label>
            <select name="staff_id" id="cita_staff" class="form-control">
              <option value="">— Sin asignar —</option>
              <?php foreach ($staffList as $st): ?>
                <option value="<?= $st['id'] ?>"><?= e($st['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="form-field">
            <label>Estado</label>
            <select name="status" id="cita_status" class="form-control">
              <option value="pending">Pendiente</option>
              <option value="confirmed">Confirmada</option>
              <option value="completed">Completada</option>
              <option value="cancelled">Cancelada</option>
            </select>
          </div>

          <div class="form-field full">
            <label>Notas (opcional)</label>
            <textarea name="notes" id="cita_notes" class="form-control" placeholder="Observaciones de la cita…"></textarea>
          </div>

        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="action" value="save_cita">
        <input type="hidden" name="id" id="cita_id" value="0">
        <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="button" class="btn btn-ghost" onclick="closeModal('citaModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar cita</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

function toggleNuevoCliente(){
  const es0 = document.getElementById('cita_client').value === '0';
  document.getElementById('cita_nc_name_wrap').style.display  = es0 ? '' : 'none';
  document.getElementById('cita_nc_phone_wrap').style.display = es0 ? '' : 'none';
}

function actualizarDuracion(){
  let total = 0;
  document.querySelectorAll('#citaModal input[name="services[]"]:checked').forEach(c => total += parseInt(c.dataset.dur || 0));
  document.getElementById('cita_dur_hint').textContent = 'Duración total: ' + total + ' min';
}
document.querySelectorAll('#citaModal input[name="services[]"]').forEach(c => c.addEventListener('change', actualizarDuracion));

function resetCita(){
  document.getElementById('cita_id').value = 0;
  document.getElementById('cita_client').value = '';
  document.getElementById('cita_nc_name').value = '';
  document.getElementById('cita_nc_phone').value = '';
  document.querySelectorAll('#citaModal input[name="services[]"]').forEach(c => c.checked = false);
  document.getElementById('cita_date').value = '';
  document.getElementById('cita_time').value = '';
  document.getElementById('cita_status').value = 'pending';
  document.getElementById('cita_notes').value = '';
  const staff = document.getElementById('cita_staff'); if (staff) staff.value = '';
  toggleNuevoCliente(); actualizarDuracion();
}

function openNuevaCita(fecha){
  resetCita();
  document.getElementById('citaModalTitle').textContent = 'Nueva cita';
  if (fecha) document.getElementById('cita_date').value = fecha;
  openModal('citaModal');
}

function openEditarCita(d){
  resetCita();
  document.getElementById('citaModalTitle').textContent = 'Editar cita';
  document.getElementById('cita_id').value = d.id;
  document.getElementById('cita_client').value = d.client_id;
  (d.services || []).forEach(sid => {
    const cb = document.querySelector('#citaModal input[name="services[]"][value="'+sid+'"]');
    if (cb) cb.checked = true;
  });
  document.getElementById('cita_date').value = d.date;
  document.getElementById('cita_time').value = (d.time_start || '').substring(0,5);
  document.getElementById('cita_status').value = d.status;
  document.getElementById('cita_notes').value = d.notes || '';
  const staff = document.getElementById('cita_staff'); if (staff) staff.value = d.staff_id || '';
  toggleNuevoCliente(); actualizarDuracion();
  openModal('citaModal');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>
