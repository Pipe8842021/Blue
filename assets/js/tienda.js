/* ============================================================
   BLUE THERAPY — Tienda: carrito y envío del pedido
   ------------------------------------------------------------
   El catálogo llega de tienda.php en window.BLUE_TIENDA. Aquí solo se
   decide QUÉ se pide (producto y cantidad): los precios y el total los
   vuelve a calcular el servidor (api/pedido_crear.php) con la base de datos.
   ============================================================ */

'use strict';

const DATOS = Object.assign(
  { productos: [], pagoEnLinea: false, maxUnidades: 20, correoActivo: false },
  window.BLUE_TIENDA || {}
);
const CLAVE_CARRITO = 'blue_carrito_v1';
const porId = new Map(DATOS.productos.map(p => [p.id, p]));

const $ = (id) => document.getElementById(id);

// ── Utilidades ──────────────────────────────────────────────
function fmtPrice(n) {
  return '$' + Math.round(n).toLocaleString('es-CO');
}

// Todo lo que se arme con innerHTML pasa por aquí: nombres de productos
// no deben poder inyectar etiquetas.
function escHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function tokenCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// Cuántas unidades se pueden pedir de un producto (0 = agotado).
function tope(p) {
  if (p.agotado) return 0;
  return p.stock === null ? DATOS.maxUnidades : Math.min(p.stock, DATOS.maxUnidades);
}

// ── Carrito (se conserva en el navegador) ───────────────────
function cargarCarrito() {
  let guardado = {};
  try { guardado = JSON.parse(localStorage.getItem(CLAVE_CARRITO) || '{}') || {}; } catch (e) { guardado = {}; }

  // Se descarta lo que ya no existe o se agotó y se ajusta lo que supera el stock actual.
  const limpio = {};
  for (const [id, cantidad] of Object.entries(guardado)) {
    const p = porId.get(Number(id));
    const q = Math.min(Math.floor(Number(cantidad)) || 0, p ? tope(p) : 0);
    if (p && q > 0) limpio[p.id] = q;
  }
  return limpio;
}

let carrito = cargarCarrito();

function guardarCarrito() {
  try { localStorage.setItem(CLAVE_CARRITO, JSON.stringify(carrito)); } catch (e) { /* modo privado: sigue sin persistir */ }
}

const cantidad = (id) => carrito[id] || 0;
const unidades = () => Object.values(carrito).reduce((s, q) => s + q, 0);
const total    = () => Object.entries(carrito).reduce((s, [id, q]) => s + porId.get(Number(id)).price * q, 0);

function fijarCantidad(id, q) {
  const p = porId.get(id);
  if (!p) return;
  q = Math.max(0, Math.min(q, tope(p)));
  if (q === 0) delete carrito[id]; else carrito[id] = q;
  guardarCarrito();
  pintarTodo();
}

// ── Pintar el catálogo ──────────────────────────────────────
function accionesProducto(p) {
  const q = cantidad(p.id);
  if (p.agotado) return '<button type="button" class="prd-add" disabled>Agotado</button>';
  if (q === 0) {
    return `<button type="button" class="prd-add" data-accion="sumar" data-id="${p.id}">`
         + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Agregar</button>';
  }
  return `<div class="stepper" role="group" aria-label="Cantidad de ${escHtml(p.name)}">`
       + `<button type="button" data-accion="restar" data-id="${p.id}" aria-label="Quitar uno">−</button>`
       + `<output>${q}</output>`
       + `<button type="button" data-accion="sumar" data-id="${p.id}" aria-label="Agregar uno"${q >= tope(p) ? ' disabled' : ''}>+</button></div>`;
}

function pintarCatalogo() {
  document.querySelectorAll('.prd-card').forEach(card => {
    const p = porId.get(Number(card.dataset.id));
    if (!p) return;
    card.classList.toggle('is-in-cart', cantidad(p.id) > 0);
    card.querySelector('.prd-actions').innerHTML = accionesProducto(p);
  });
}

// ── Pintar el pedido (panel lateral) ────────────────────────
function pintarBoton() {
  const n = unidades();
  $('cart-fab').classList.toggle('is-empty', n === 0);
  $('cart-fab-n').textContent = n;
  $('cart-fab-total').textContent = n > 0 ? fmtPrice(total()) : 'Mi pedido';
}

function pintarPedido() {
  const ids = Object.keys(carrito).map(Number);
  const hayItems = ids.length > 0;

  $('cart-vacio').hidden = hayItems;
  $('cart-form').hidden  = !hayItems;
  $('cart-foot').hidden  = !hayItems;

  $('cart-items').innerHTML = ids.map(id => {
    const p = porId.get(id);
    const q = carrito[id];
    const img = p.image
      ? `<img class="cart-linea-img" src="${escHtml(p.image)}" alt="" loading="lazy">`
      : '<span class="cart-linea-img"></span>';
    return `<div class="cart-linea">${img}
      <div class="cart-linea-info">
        <div class="cart-linea-nombre">${escHtml(p.name)}</div>
        <div class="cart-linea-precio">${fmtPrice(p.price)} c/u</div>
        <button type="button" class="cart-quitar" data-accion="quitar" data-id="${p.id}">Quitar</button>
      </div>
      <div class="stepper" role="group" aria-label="Cantidad de ${escHtml(p.name)}">
        <button type="button" data-accion="restar" data-id="${p.id}" aria-label="Quitar uno">−</button>
        <output>${q}</output>
        <button type="button" data-accion="sumar" data-id="${p.id}" aria-label="Agregar uno"${q >= tope(p) ? ' disabled' : ''}>+</button>
      </div>
      <div class="cart-linea-total">${fmtPrice(p.price * q)}</div>
    </div>`;
  }).join('');

  $('cart-total').textContent = fmtPrice(total());
  $('btn-pedido-label').textContent = DATOS.pagoEnLinea ? `Pagar ${fmtPrice(total())}` : 'Enviar pedido';
}

function pintarTodo() {
  pintarCatalogo();
  pintarBoton();
  pintarPedido();
}

// ── Abrir y cerrar el panel ─────────────────────────────────
function abrirPanel() {
  $('cart-overlay').classList.add('is-open');
  $('cart-panel').classList.add('is-open');
  $('cart-panel').setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  $('cart-close').focus();
}

function cerrarPanel() {
  $('cart-overlay').classList.remove('is-open');
  $('cart-panel').classList.remove('is-open');
  $('cart-panel').setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  $('cart-fab').focus();
}

// ── Formulario ──────────────────────────────────────────────
function marcarError(campo, hay) {
  campo.classList.toggle('error', hay);
}

function validarFormulario() {
  const nombre = $('f-nombre'), telefono = $('f-telefono'), correo = $('f-correo');
  const okNombre   = nombre.value.trim() !== '';
  const okTelefono = /^[+\d][\d\s\-\(\)]{5,}$/.test(telefono.value.trim());
  const okCorreo   = correo.value.trim() === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo.value.trim());
  marcarError(nombre, !okNombre);
  marcarError(telefono, !okTelefono);
  marcarError(correo, !okCorreo);
  if (!okNombre) nombre.focus(); else if (!okTelefono) telefono.focus(); else if (!okCorreo) correo.focus();
  return okNombre && okTelefono && okCorreo;
}

// El aviso por correo solo tiene sentido con un correo escrito (y si el sitio puede enviarlos).
function actualizarAvisoCorreo() {
  const etiqueta = $('aviso-correo');
  if (etiqueta) etiqueta.hidden = $('f-correo').value.trim() === '';
}

function mostrarMensaje(texto) {
  const caja = $('cart-mensaje');
  caja.textContent = texto;
  caja.hidden = texto === '';
}

function mostrarExito(data) {
  $('cart-form').hidden = true;
  $('cart-foot').hidden = true;
  $('cart-resumen').hidden = true;
  $('exito-numero').textContent = '#' + data.order_id;
  $('exito-enlace').href = data.status_url;
  $('cart-exito').hidden = false;
}

async function enviarPedido(evento) {
  evento.preventDefault();
  mostrarMensaje('');
  if (!validarFormulario()) return;

  const boton = $('btn-pedido');
  boton.disabled = true;
  $('btn-pedido-label').textContent = DATOS.pagoEnLinea ? 'Abriendo la pasarela…' : 'Enviando tu pedido…';

  const cuerpo = {
    items: Object.entries(carrito).map(([id, qty]) => ({ id: Number(id), qty })),
    name:  $('f-nombre').value.trim(),
    phone: $('f-telefono').value.trim(),
    email: $('f-correo').value.trim(),
    note:  $('f-nota').value.trim(),
    whatsapp: $('f-whatsapp').checked,
    email_reminder: !!($('f-correo-aviso') && $('f-correo-aviso').checked),
  };

  try {
    const res  = await fetch('/Blue/api/pedido_crear.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tokenCsrf() },
      body: JSON.stringify(cuerpo),
    });
    const data = await res.json();

    if (data.success && data.checkout_url) {
      // El stock ya quedó apartado: el carrito cumplió su trabajo.
      carrito = {};
      guardarCarrito();
      window.location.href = data.checkout_url;
      return;
    }
    if (data.success) {
      carrito = {};
      guardarCarrito();
      pintarBoton();
      pintarCatalogo();
      mostrarExito(data);
      return;
    }
    mostrarMensaje(data.error || 'No pudimos registrar tu pedido. Intenta de nuevo.');
  } catch (e) {
    mostrarMensaje('Error de conexión. Por favor intenta de nuevo.');
  }

  boton.disabled = false;
  pintarPedido();
}

// ── Eventos ─────────────────────────────────────────────────
document.addEventListener('click', (e) => {
  const boton = e.target.closest('[data-accion]');
  if (!boton) return;

  const id = Number(boton.dataset.id);
  switch (boton.dataset.accion) {
    case 'sumar':  fijarCantidad(id, cantidad(id) + 1); break;
    case 'restar': fijarCantidad(id, cantidad(id) - 1); break;
    case 'quitar': fijarCantidad(id, 0); break;
  }
});

$('cart-fab').addEventListener('click', abrirPanel);
$('cart-close').addEventListener('click', cerrarPanel);
$('cart-overlay').addEventListener('click', cerrarPanel);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && $('cart-panel').classList.contains('is-open')) cerrarPanel();
});
$('cart-seguir')?.addEventListener('click', cerrarPanel);

$('cart-form').addEventListener('submit', enviarPedido);
$('f-correo').addEventListener('input', actualizarAvisoCorreo);
['f-nombre', 'f-telefono', 'f-correo'].forEach(id => {
  $(id).addEventListener('input', () => marcarError($(id), false));
});

// ── Inicio ──────────────────────────────────────────────────
pintarTodo();
actualizarAvisoCorreo();

// Un enlace como /tienda.php#producto-3 (desde la página de inicio) resalta ese producto.
if (/^#producto-\d+$/.test(window.location.hash)) {
  const destino = document.getElementById(window.location.hash.slice(1));
  if (destino) {
    destino.scrollIntoView({ behavior: 'smooth', block: 'center' });
    destino.classList.add('is-target');
  }
}
