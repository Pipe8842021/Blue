


/* ============================================================
   BLUE THERAPY — Booking Wizard
   ============================================================ */

'use strict';

// ── Constants ──────────────────────────────────────────────
const SLOT_MIN   = 60;
const HOUR_START = 8;
const HOUR_END   = 19;
const DAY_NAMES  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
const MONTH_NAMES = ['enero','febrero','marzo','abril','mayo','junio',
                     'julio','agosto','septiembre','octubre','noviembre','diciembre'];
const MONTH_SHORT = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

// ── Pasarela de pago ───────────────────────────────────────
// Lo inyecta booking.php desde config/wompi.php. Si no hay llaves
// configuradas, `activo` llega en false y el wizard funciona como antes.
const PAGOS = Object.assign(
  { activo: false, obligatorio: false, porcentaje: 30, minimo: 0, maximo: 0, redondearA: 0, minutos: 20, demo: false },
  window.BLUE_PAGOS || {}
);

// ── Booking state ──────────────────────────────────────────
const booking = {
  step: 1,
  services: [],          // [{id, name, duration, price}]
  totalDuration: 0,
  date: null,            // "YYYY-MM-DD"
  timeStart: null,       // "HH:MM"
  timeEnd: null,         // "HH:MM"
  name: '', phone: '', email: '', note: '', whatsapp: true, emailReminder: false
};

let busySlots  = [];         // [{date, time_start, time_end}]
let busyMap    = new Map();  // Map<date, [{s, e}]> — indexed after each fetch
let cachedWeek = null;       // ISO date of last fetched week_start
let currentWeekStart = getMonday(new Date());

// ── Date helpers ───────────────────────────────────────────
function getMonday(d) {
  const r = new Date(d);
  const day = r.getDay();
  r.setDate(r.getDate() - day + (day === 0 ? -6 : 1));
  r.setHours(0, 0, 0, 0);
  return r;
}

function addDays(d, n) {
  const r = new Date(d);
  r.setDate(r.getDate() + n);
  return r;
}

function toISO(d) {
  return d.toISOString().split('T')[0];
}

function toMin(t) {
  const [h, m] = t.split(':').map(Number);
  return h * 60 + m;
}

function fromMin(m) {
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
}

function fmtTime(t) {
  const [h, m] = t.split(':').map(Number);
  const ap = h >= 12 ? 'PM' : 'AM';
  const h12 = h > 12 ? h - 12 : h || 12;
  return `${h12}:${String(m).padStart(2,'0')} ${ap}`;
}

function fmtDate(iso) {
  const d = new Date(iso + 'T12:00:00');
  return `${DAY_NAMES[d.getDay()]} ${d.getDate()} de ${MONTH_NAMES[d.getMonth()]}`;
}

function fmtPrice(n) {
  return '$' + parseInt(n).toLocaleString('es-CO');
}

// Todo lo que se arme con innerHTML pasa por aquí: los nombres de servicio
// y los datos que escribe el cliente no deben poder inyectar etiquetas.
function escHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

// ── Abono ──────────────────────────────────────────────────
// Misma regla que calcularAbonoReserva() en includes/h-pagos.php.
// Aquí es solo para mostrar: el monto que se cobra lo calcula el servidor.
function calcularAbono(total) {
  let abono = total * PAGOS.porcentaje / 100;
  if (PAGOS.redondearA > 0) abono = Math.ceil(abono / PAGOS.redondearA) * PAGOS.redondearA;
  if (PAGOS.minimo > 0 && abono < PAGOS.minimo) abono = PAGOS.minimo;
  if (PAGOS.maximo > 0 && abono > PAGOS.maximo) abono = PAGOS.maximo;
  if (total > 0 && abono > total) abono = total;
  return Math.round(abono);
}

function totalServicios() {
  return booking.services.reduce((suma, s) => suma + s.price, 0);
}

// ── Time slot list ─────────────────────────────────────────
function timeSlots() {
  const slots = [];
  for (let m = HOUR_START * 60; m < HOUR_END * 60; m += SLOT_MIN) {
    slots.push(fromMin(m));
  }
  return slots;
}

// ── Availability helpers ───────────────────────────────────

// Build date-keyed map for O(1) per-date lookup instead of O(n) full scan.
function indexBusySlots() {
  busyMap = new Map();
  for (const b of busySlots) {
    if (!busyMap.has(b.date)) busyMap.set(b.date, []);
    busyMap.get(b.date).push({ s: toMin(b.time_start), e: toMin(b.time_end) });
  }
}

function isBusy(date, time) {
  const t = toMin(time);
  return (busyMap.get(date) || []).some(b => t >= b.s && t < b.e);
}

function slotFits(date, time) {
  const now    = new Date();
  const dt     = new Date(date + 'T' + time + ':00');
  if (dt <= now) return false;

  const dur    = booking.totalDuration || 60;
  const tStart = toMin(time);
  const tEnd   = tStart + dur;
  if (tEnd > HOUR_END * 60) return false;

  return !(busyMap.get(date) || []).some(b => !(tEnd <= b.s || tStart >= b.e));
}

function inSelectedBlock(time) {
  if (!booking.date || !booking.timeStart || !booking.timeEnd) return false;
  const t = toMin(time);
  return t >= toMin(booking.timeStart) && t < toMin(booking.timeEnd);
}

// ── Calendar render ────────────────────────────────────────
function renderCalendar() {
  const slots = timeSlots();
  // Mon(0) → Sun(6): 7 days
  const days  = Array.from({length: 7}, (_, i) => addDays(currentWeekStart, i));
  const today = toISO(new Date());

  // Week label
  const fl = days[0], ll = days[days.length - 1];
  const sameMonth = fl.getMonth() === ll.getMonth();
  document.getElementById('cal-week-label').textContent =
    sameMonth
      ? `${fl.getDate()} - ${ll.getDate()} de ${MONTH_NAMES[fl.getMonth()]} ${fl.getFullYear()}`
      : `${fl.getDate()} ${MONTH_SHORT[fl.getMonth()]} – ${ll.getDate()} ${MONTH_SHORT[ll.getMonth()]} ${ll.getFullYear()}`;

  // Prev button: disable if already at current week
  const thisMonday = toISO(getMonday(new Date()));
  document.getElementById('btn-prev-week').disabled = toISO(currentWeekStart) <= thisMonday;

  // Build table
  let html = '<div class="cal-scroll"><table class="cal-table"><thead><tr>';
  html += '<th class="th-time">Hora</th>';
  days.forEach(d => {
    const iso  = toISO(d);
    const isTd = iso === today;
    const label = DAY_NAMES[d.getDay()].toLowerCase() + ' ' + d.getDate();
    html += `<th class="th-day${isTd ? ' today' : ''}">
      <div class="th-day-label">${label}</div>
    </th>`;
  });
  html += '</tr></thead><tbody>';

  slots.forEach(time => {
    html += `<tr><td class="td-time">${fmtTime(time)}</td>`;

    days.forEach(d => {
      const iso      = toISO(d);
      const isSunday = d.getDay() === 0;

      // Domingo = siempre cerrado
      if (isSunday) {
        html += `<td class="td-cell"><div class="cell-box closed">Cerrado</div></td>`;
        return;
      }

      const busy  = isBusy(iso, time);
      const fits  = slotFits(iso, time);
      const inSel = inSelectedBlock(time) && booking.date === iso;

      if (inSel) {
        const isFirst = toMin(time) === toMin(booking.timeStart);
        html += `<td class="td-cell">
          <div class="cell-box selected" data-date="${iso}" data-time="${time}">
            ${isFirst ? fmtTime(time) : ''}
          </div></td>`;

      } else if (busy) {
        html += `<td class="td-cell"><div class="cell-box busy">Ocupado</div></td>`;

      } else {
        const dt = new Date(iso + 'T' + time + ':00');
        if (dt <= new Date()) {
          html += `<td class="td-cell"><div class="cell-box past"></div></td>`;
        } else if (!fits) {
          html += `<td class="td-cell"><div class="cell-box no-fit"></div></td>`;
        } else {
          html += `<td class="td-cell">
            <div class="cell-box available"
                 data-date="${iso}" data-time="${time}"
                 onclick="selectSlot('${iso}','${time}')"
                 onmouseenter="previewSlot('${iso}','${time}')"
                 onmouseleave="clearPreview()">
            </div></td>`;
        }
      }
    });
    html += '</tr>';
  });

  html += '</tbody></table></div>';
  document.getElementById('cal-container').innerHTML = html;
}

function selectSlot(date, time) {
  booking.date      = date;
  booking.timeStart = time;
  booking.timeEnd   = fromMin(toMin(time) + (booking.totalDuration || 60));
  renderCalendar();
  updateSlotBanner();
  updateNextBtn();
  updateSidebar();
}

function previewSlot(date, time) {
  if (!slotFits(date, time)) return;
  const dur    = booking.totalDuration || 60;
  const tStart = toMin(time);
  document.querySelectorAll('.cell-box.available[data-date]').forEach(cell => {
    if (cell.dataset.date !== date) return;
    const ct = toMin(cell.dataset.time);
    if (ct >= tStart && ct < tStart + dur) cell.classList.add('hover-preview');
  });
}

function clearPreview() {
  document.querySelectorAll('.cell-box.hover-preview').forEach(c => c.classList.remove('hover-preview'));
}

function updateSlotBanner() {
  const banner = document.getElementById('slot-banner');
  const text   = document.getElementById('slot-banner-text');
  if (booking.date && booking.timeStart) {
    text.textContent = `${fmtDate(booking.date)} · ${fmtTime(booking.timeStart)} – ${fmtTime(booking.timeEnd)}`;
    banner.classList.add('visible');
  } else {
    banner.classList.remove('visible');
  }
}

// Fetch busy slots from API (cached per week to avoid redundant fetches on back/forward).
async function loadAvailability() {
  const weekKey = toISO(currentWeekStart);

  if (weekKey === cachedWeek) {
    renderCalendar();
    return;
  }

  document.getElementById('cal-container').innerHTML =
    '<div class="cal-loading"><div class="spinner"></div>Cargando disponibilidad…</div>';
  try {
    const res  = await fetch(`/Blue/api/availability.php?week_start=${weekKey}`);
    const data = await res.json();
    busySlots  = data.occupied || [];
    cachedWeek = weekKey;
    indexBusySlots();
    renderCalendar();
  } catch (e) {
    document.getElementById('cal-container').innerHTML =
      '<div class="cal-loading" style="color:#ef4444">Error al cargar disponibilidad. Recarga la página.</div>';
  }
}

// ── Step 1 helpers ─────────────────────────────────────────
function initServiceCards() {
  document.querySelectorAll('.service-option input[type="checkbox"]').forEach(chk => {
    chk.addEventListener('change', function () {
      toggleService(this.closest('.service-option'), this.checked);
    });
  });
}

function toggleService(card, checked) {
  const id       = parseInt(card.dataset.id);
  const duration = parseInt(card.dataset.duration);
  const price    = parseFloat(card.dataset.price);
  const name     = card.dataset.name;

  if (checked) {
    if (!booking.services.find(s => s.id === id)) {
      booking.services.push({id, name, duration, price});
    }
    card.classList.add('selected');
  } else {
    booking.services = booking.services.filter(s => s.id !== id);
    card.classList.remove('selected');
  }

  booking.totalDuration = booking.services.reduce((s, x) => s + x.duration, 0);
  updateSelectionBar();
  updateNextBtn();
  updateSidebar();

  // Reset slot selection when services change (duration may have changed)
  booking.date = booking.timeStart = booking.timeEnd = null;
  updateSlotBanner();
}

function updateSelectionBar() {
  const bar   = document.getElementById('sel-bar');
  const label = document.getElementById('sel-bar-label');
  const dur   = document.getElementById('sel-bar-dur');
  const val   = document.getElementById('sel-bar-value');
  const cnt   = booking.services.length;

  if (cnt === 0) {
    bar.classList.remove('has-items');
    label.textContent = 'Selecciona al menos un servicio para continuar';
    dur.textContent   = '';
    val.textContent   = '';
  } else {
    bar.classList.add('has-items');
    const total = booking.services.reduce((s, x) => s + x.price, 0);
    label.textContent = `${cnt} servicio${cnt > 1 ? 's' : ''} seleccionado${cnt > 1 ? 's' : ''}`;
    dur.textContent   = `${booking.totalDuration} min`;
    val.textContent   = fmtPrice(total);
  }
}

// ── Validación inline ─────────────────────────────────────
function showFieldError(inputId, msg) {
  const input = document.getElementById(inputId);
  if (!input) return;
  input.classList.add('error');
  let err = input.parentNode.querySelector('.field-error-msg');
  if (!err) {
    err = document.createElement('span');
    err.className = 'field-error-msg';
    input.parentNode.appendChild(err);
  }
  err.textContent = msg;
  err.style.display = 'block';
}

function clearFieldError(inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;
  input.classList.remove('error');
  const err = input.parentNode.querySelector('.field-error-msg');
  if (err) err.style.display = 'none';
}

// ── Step 3 helpers ─────────────────────────────────────────
function buildBookingSummaryBar() {
  const bsb = document.getElementById('bsb');
  const svcNames = booking.services.map(s => escHtml(s.name)).join(', ');
  bsb.innerHTML = `
    <div class="bsb-item">
      <span class="bsb-label">Servicio${booking.services.length > 1 ? 's' : ''}</span>
      <span class="bsb-value">${svcNames}</span>
    </div>
    <div class="bsb-item">
      <span class="bsb-label">Fecha</span>
      <span class="bsb-value">${fmtDate(booking.date)}</span>
    </div>
    <div class="bsb-item">
      <span class="bsb-label">Hora</span>
      <span class="bsb-value">${fmtTime(booking.timeStart)} – ${fmtTime(booking.timeEnd)}</span>
    </div>
    <div class="bsb-item">
      <span class="bsb-label">Duración</span>
      <span class="bsb-value">${booking.totalDuration} min</span>
    </div>`;
}

function validateStep3() {
  const name  = document.getElementById('f-name').value.trim();
  const phone = document.getElementById('f-phone').value.trim();
  let ok = true;

  if (!name) {
    showFieldError('f-name', 'El nombre completo es obligatorio');
    ok = false;
  } else {
    clearFieldError('f-name');
  }

  if (!phone) {
    showFieldError('f-phone', 'El teléfono es obligatorio');
    ok = false;
  } else if (!/^[+\d][\d\s\-\(\)]{5,}$/.test(phone)) {
    showFieldError('f-phone', 'Formato inválido — incluye código de país (ej: +57 300…)');
    ok = false;
  } else {
    clearFieldError('f-phone');
  }

  return ok;
}

// Pasa el formulario del paso 3 al estado de la reserva.
function guardarDatosContacto() {
  booking.name     = document.getElementById('f-name').value.trim();
  booking.phone    = document.getElementById('f-phone').value.trim();
  booking.email    = document.getElementById('f-email').value.trim();
  booking.note     = document.getElementById('f-note').value.trim();
  booking.whatsapp = document.getElementById('f-whatsapp').checked;
  // Sin correo escrito el checkbox queda oculto (o no existe si la pasarela
  // de correo no está activa): en ambos casos no hay a dónde avisar.
  const chkCorreo = document.getElementById('f-email-reminder');
  booking.emailReminder = !!(booking.email && chkCorreo && chkCorreo.checked);
}

// Cuerpo que esperan api/book.php y api/pago_iniciar.php.
function datosReserva() {
  return {
    services:       booking.services.map(s => s.id),
    date:           booking.date,
    time_start:     booking.timeStart,
    time_end:       booking.timeEnd,
    name:           booking.name,
    phone:          booking.phone,
    email:          booking.email,
    note:           booking.note,
    whatsapp:       booking.whatsapp,
    email_reminder: booking.emailReminder,
  };
}

// Muestra u oculta el checkbox de avisos por correo según si hay un correo escrito.
function actualizarToggleCorreo() {
  const etiqueta = document.getElementById('email-reminder-label');
  if (!etiqueta) return; // la pasarela de correo no está activa: ni siquiera se imprimió
  const hayCorreo = document.getElementById('f-email').value.trim() !== '';
  etiqueta.style.display = hayCorreo ? '' : 'none';
}

function tokenCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// ══════════════════════════════════════════════════════════
//   PASO 4 · CON PASARELA — abono en línea (Wompi)
// ══════════════════════════════════════════════════════════

// Arma el resumen del abono y deja listo el botón de pago.
function mostrarPanelPago() {
  const panel = document.getElementById('s4-pago');
  if (!panel) return;

  document.getElementById('s4-sending').style.display = 'none';
  document.getElementById('s4-success').style.display = 'none';
  document.getElementById('s4-error').style.display   = 'none';
  panel.style.display = 'block';

  const total = totalServicios();
  const abono = calcularAbono(total);
  const saldo = Math.max(0, total - abono);

  document.getElementById('pago-resumen').innerHTML = `
    <div class="pr-cita">
      <div class="pr-cita-titulo">${booking.services.length > 1 ? 'Tus servicios' : 'Tu servicio'}</div>
      ${booking.services.map(s => `
        <div class="pr-linea">
          <span class="pr-linea-nombre">${escHtml(s.name)}<em>${s.duration} min</em></span>
          <span class="pr-linea-precio">${fmtPrice(s.price)}</span>
        </div>`).join('')}
      <div class="pr-slot">
        <span class="pr-slot-icon">📅</span>
        <div>
          <strong>${escHtml(fmtDate(booking.date))}</strong>
          <span>${fmtTime(booking.timeStart)} – ${fmtTime(booking.timeEnd)}</span>
        </div>
      </div>
    </div>
    <div class="pr-montos">
      <div class="pr-monto">
        <span>Total de los servicios</span><span>${fmtPrice(total)}</span>
      </div>
      <div class="pr-monto pr-monto--abono">
        <span>Abono para reservar <em>${PAGOS.porcentaje}%</em></span><span>${fmtPrice(abono)}</span>
      </div>
      <div class="pr-monto pr-monto--saldo">
        <span>Saldo el día de la cita</span><span>${fmtPrice(saldo)}</span>
      </div>
    </div>`;

  document.getElementById('btn-pagar-label').textContent = `Pagar ${fmtPrice(abono)} con Wompi`;
  // En modo demostración el botón queda visible pero inactivo: no hay cuenta con qué cobrar.
  document.getElementById('btn-pagar').disabled = PAGOS.demo === true;
}

// Aparta el horario en el servidor y manda al checkout de Wompi.
async function iniciarPago() {
  const btn   = document.getElementById('btn-pagar');
  const label = document.getElementById('btn-pagar-label');
  btn.disabled      = true;
  label.textContent = 'Abriendo la pasarela…';

  guardarDatosContacto();

  try {
    const res = await fetch('/Blue/api/pago_iniciar.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tokenCsrf() },
      body:    JSON.stringify(datosReserva()),
    });
    const data = await res.json();

    if (data.success && data.checkout_url) {
      // El cupo ya quedó apartado: la disponibilidad en cache queda vieja.
      cachedWeek = null;
      window.location.href = data.checkout_url;
      return;
    }
    mostrarErrorPago(data.error || 'No pudimos abrir la pasarela de pago.');

  } catch (e) {
    mostrarErrorPago('Error de conexión. Por favor intenta de nuevo.');
  }
}

function mostrarErrorPago(mensaje) {
  const panel = document.getElementById('s4-pago');
  if (panel) panel.style.display = 'none';
  document.getElementById('step-actions').style.display = 'none';
  document.getElementById('s4-error-msg').textContent   = mensaje;
  document.getElementById('s4-error').style.display     = 'block';

  const btn = document.getElementById('btn-pagar');
  if (btn) {
    btn.disabled = false;
    document.getElementById('btn-pagar-label').textContent = 'Pagar abono';
  }
}

// ══════════════════════════════════════════════════════════
//   PASO 4 · SIN PASARELA — solicitud que confirma el equipo
// ══════════════════════════════════════════════════════════
async function submitBooking() {
  guardarDatosContacto();

  // Show sending state
  const panelPago = document.getElementById('s4-pago');
  if (panelPago) panelPago.style.display = 'none';
  document.getElementById('s4-sending').style.display = 'block';
  document.getElementById('s4-success').style.display = 'none';
  document.getElementById('s4-error').style.display   = 'none';
  document.getElementById('step-actions').style.display = 'none';

  try {
    const res  = await fetch('/Blue/api/book.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tokenCsrf() },
      body:    JSON.stringify(datosReserva()),
    });
    const data = await res.json();

    document.getElementById('s4-sending').style.display = 'none';

    if (data.success) {
      cachedWeek = null; // invalidate: this slot is now taken
      buildConfirmDetails();

      // Enlace privado con el que el cliente consulta su cita cuando quiera.
      const enlace = document.getElementById('s4-status-link');
      if (enlace && data.status_url) {
        enlace.href = data.status_url;
        enlace.style.display = '';
      }
      document.getElementById('s4-success').style.display = 'block';
    } else {
      document.getElementById('s4-error-msg').textContent = data.error || 'Error al procesar tu solicitud.';
      document.getElementById('s4-error').style.display   = 'block';
      document.getElementById('step-actions').style.display = 'flex';
    }
  } catch (e) {
    document.getElementById('s4-sending').style.display   = 'none';
    document.getElementById('s4-error-msg').textContent   = 'Error de conexión. Por favor intenta de nuevo.';
    document.getElementById('s4-error').style.display     = 'block';
    document.getElementById('step-actions').style.display = 'flex';
  }
}

function buildConfirmDetails() {
  const d = document.getElementById('s4-details');
  const svcNames = booking.services.map(s => escHtml(s.name)).join(', ');
  d.innerHTML = `
    <div class="cd-item">
      <span class="cd-label">Nombre</span>
      <span class="cd-value">${escHtml(booking.name)}</span>
    </div>
    <div class="cd-item">
      <span class="cd-label">WhatsApp</span>
      <span class="cd-value">${escHtml(booking.phone)}</span>
    </div>
    <div class="cd-item">
      <span class="cd-label">Servicio${booking.services.length > 1 ? 's' : ''}</span>
      <span class="cd-value">${svcNames}</span>
    </div>
    <div class="cd-item">
      <span class="cd-label">Fecha</span>
      <span class="cd-value">${fmtDate(booking.date)}</span>
    </div>
    <div class="cd-item">
      <span class="cd-label">Hora</span>
      <span class="cd-value">${fmtTime(booking.timeStart)} – ${fmtTime(booking.timeEnd)}</span>
    </div>
    <div class="cd-item">
      <span class="cd-label">Estado</span>
      <span class="cd-value" style="color:#d97706">⏳ Pendiente de confirmación</span>
    </div>`;
}

// ── Sidebar ────────────────────────────────────────────────
function updateSidebar() {
  const body = document.getElementById('sidebar-body');
  if (!body) return;

  if (booking.services.length === 0) {
    body.innerHTML = `
      <div class="sidebar-empty">
        <div class="sidebar-empty-icon">✦</div>
        <p>Selecciona un servicio para ver el resumen aquí</p>
      </div>`;
    return;
  }

  const total = booking.services.reduce((s, x) => s + x.price, 0);

  let html = '<div>';
  booking.services.forEach(s => {
    html += `
      <div class="sidebar-service-item">
        <div>
          <div class="ssi-name">${escHtml(s.name)}</div>
          <div class="ssi-meta">${s.duration} min</div>
        </div>
        <div class="ssi-price">${fmtPrice(s.price)}</div>
      </div>`;
  });
  html += '</div>';

  if (booking.date && booking.timeStart) {
    html += `
      <div class="sidebar-slot">
        <span class="ss-icon">📅</span>
        <div>
          <div class="ss-date">${fmtDate(booking.date)}</div>
          <div class="ss-time">${fmtTime(booking.timeStart)} – ${fmtTime(booking.timeEnd)}</div>
        </div>
      </div>`;
  }

  html += `
    <div class="sidebar-total">
      <span>Total estimado</span>
      <span class="st-price">${fmtPrice(total)}</span>
    </div>`;

  // Con pasarela activa se muestra qué se paga ahora y qué queda pendiente.
  if (PAGOS.activo && total > 0) {
    const abono = calcularAbono(total);
    html += `
      <div class="sidebar-abono">
        <div class="sab-fila sab-fila--destacada">
          <span>Abono para reservar</span>
          <span>${fmtPrice(abono)}</span>
        </div>
        <div class="sab-fila">
          <span>Saldo el día de la cita</span>
          <span>${fmtPrice(Math.max(0, total - abono))}</span>
        </div>
      </div>`;
  }

  body.innerHTML = html;
}

// ── Step navigation ────────────────────────────────────────
function goToStep(n) {
  // Hide current
  document.querySelector('.step-panel.active')?.classList.remove('active');
  document.getElementById(`step-${n}`).classList.add('active');

  // Update progress
  for (let i = 1; i <= 4; i++) {
    const ps = document.getElementById(`ps-${i}`);
    ps.classList.toggle('active',    i === n);
    ps.classList.toggle('done',      i < n);
    if (i < 4) {
      document.getElementById(`pl-${i}`).classList.toggle('done', i < n);
    }
  }

  booking.step = n;

  // Back button — en el paso de pago todavía se puede volver a corregir datos.
  const btnBack = document.getElementById('btn-back');
  btnBack.style.visibility = n > 1 && (n < 4 || PAGOS.activo) ? 'visible' : 'hidden';

  // Next button
  const btnNext  = document.getElementById('btn-next');
  const btnLabel = document.getElementById('btn-next-label');
  const acciones = document.getElementById('step-actions');

  if (n === 4) {
    if (PAGOS.activo) {
      // El botón de pagar vive dentro del panel; aquí solo queda "Atrás".
      btnNext.style.display  = 'none';
      acciones.style.display = 'flex';
      mostrarPanelPago();
    } else {
      acciones.style.display = 'none';
    }
    window.scrollTo({top: 0, behavior: 'smooth'});
    return;
  }

  btnNext.style.display = '';
  btnLabel.textContent  = n === 3
    ? (PAGOS.activo ? 'Continuar al pago' : 'Confirmar reserva')
    : 'Continuar';
  acciones.style.display = 'flex';
  updateNextBtn();

  // Step-specific side effects
  if (n === 2) {
    loadAvailability();
  } else if (n === 3) {
    buildBookingSummaryBar();
    actualizarToggleCorreo(); // por si el navegador autocompletó el correo sin disparar 'input'
  }

  window.scrollTo({top: 0, behavior: 'smooth'});
}

function updateNextBtn() {
  const btn = document.getElementById('btn-next');
  const n   = booking.step;
  let ok = false;
  if (n === 1) ok = booking.services.length > 0;
  if (n === 2) ok = !!(booking.date && booking.timeStart);
  if (n === 3) ok = true; // validated on click
  btn.disabled = !ok;
}

// ── Week navigation ────────────────────────────────────────
document.getElementById('btn-prev-week').addEventListener('click', () => {
  const prev = addDays(currentWeekStart, -7);
  if (toISO(prev) >= toISO(getMonday(new Date()))) {
    currentWeekStart = prev;
    booking.date = booking.timeStart = booking.timeEnd = null;
    updateSlotBanner();
    loadAvailability();
  }
});

document.getElementById('btn-next-week').addEventListener('click', () => {
  currentWeekStart = addDays(currentWeekStart, 7);
  booking.date = booking.timeStart = booking.timeEnd = null;
  updateSlotBanner();
  loadAvailability();
});

// ── Main button handlers ───────────────────────────────────
document.getElementById('btn-next').addEventListener('click', () => {
  const n = booking.step;
  if (n === 3) {
    if (!validateStep3()) return;
    guardarDatosContacto();
    goToStep(4);
    if (!PAGOS.activo) submitBooking();
  } else if (n < 4) {
    goToStep(n + 1);
  }
});

document.getElementById('btn-back').addEventListener('click', () => {
  if (booking.step > 1) goToStep(booking.step - 1);
});

// ── Listeners para validación en vivo (paso 3) ────────────
document.getElementById('f-name')?.addEventListener('blur', function () {
  if (!this.value.trim()) showFieldError('f-name', 'El nombre completo es obligatorio');
  else clearFieldError('f-name');
  updateNextBtn();
});

document.getElementById('f-phone')?.addEventListener('blur', function () {
  const v = this.value.trim();
  if (!v) {
    showFieldError('f-phone', 'El teléfono es obligatorio');
  } else if (!/^[+\d][\d\s\-\(\)]{5,}$/.test(v)) {
    showFieldError('f-phone', 'Formato inválido — incluye código de país (ej: +57 300…)');
  } else {
    clearFieldError('f-phone');
  }
  updateNextBtn();
});

['f-name', 'f-phone'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', function () {
    if (this.value.trim()) clearFieldError(id);
    updateNextBtn();
  });
});

// Aparece/desaparece junto con lo que escriba en el correo (incluye el
// autocompletado del navegador, que dispara 'input' igual que si tecleara).
document.getElementById('f-email')?.addEventListener('input', actualizarToggleCorreo);

// ── Category filter tabs ───────────────────────────────────
function initServiceFilters() {
  document.querySelectorAll('.sf-tab').forEach(tab => {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.sf-tab').forEach(t => t.classList.remove('active'));
      this.classList.add('active');
      const cat = this.dataset.cat;
      document.querySelectorAll('.category-group').forEach(group => {
        group.classList.toggle('hidden', cat !== 'all' && group.dataset.catGroup !== cat);
      });
    });
  });
}

// ── Init ──────────────────────────────────────────────────
document.getElementById('btn-pagar')?.addEventListener('click', iniciarPago);

// Solo existe cuando el abono está configurado como opcional.
document.getElementById('btn-sin-pago')?.addEventListener('click', submitBooking);

initServiceCards();
initServiceFilters();
updateNextBtn();
