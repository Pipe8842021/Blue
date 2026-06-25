/* ============================================================
   confirm-modal.js — Modal de confirmación propio de Blue Therapy
   Reemplaza los confirm() nativos del navegador en todo el panel.
   Carga este script y funciona solo: intercepta cualquier
   onclick/onsubmit del tipo  return confirm('mensaje')  y muestra
   un modal con el estilo de la marca.
   También expone window.confirmModal({message, title, danger}) -> Promise<bool>
   ============================================================ */
(function () {
  'use strict';
  if (window.__confirmModalReady) return;
  window.__confirmModalReady = true;

  // ── Estilos (autoinyectados, usan los colores de la marca) ──
  var css = ''
    + '.cmodal-ov{position:fixed;inset:0;background:rgba(13,17,23,.5);backdrop-filter:blur(3px);'
    + 'display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}'
    + '.cmodal-ov.open{display:flex}'
    + '.cmodal{background:#fff;border-radius:16px;max-width:400px;width:100%;text-align:center;'
    + "box-shadow:0 20px 60px rgba(0,0,0,.25);animation:cmodalIn .2s ease;font-family:'DM Sans',system-ui,sans-serif}"
    + '@keyframes cmodalIn{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}'
    + '.cmodal-ic{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:26px auto 14px}'
    + '.cmodal-ic svg{width:26px;height:26px}'
    + '.cmodal-ttl{font-size:17px;font-weight:700;color:#111827;margin:0 24px 6px}'
    + '.cmodal-msg{font-size:14px;color:#6b7280;line-height:1.5;margin:0 28px 4px}'
    + '.cmodal-ft{display:flex;gap:10px;padding:22px 24px 24px}'
    + '.cmodal-btn{flex:1;padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;'
    + 'border:1.5px solid transparent;font-family:inherit;transition:all .18s ease}'
    + '.cmodal-cancel{background:#fff;border-color:#e5e7eb;color:#374151}'
    + '.cmodal-cancel:hover{background:#f3f4f6}'
    + '.cmodal-ok{background:#5bc4b8;color:#fff}'
    + '.cmodal-ok:hover{background:#3aa89e;transform:translateY(-1px)}'
    + '.cmodal.ask .cmodal-ic{background:rgba(91,196,184,.15);color:#3aa89e}'
    + '.cmodal.danger .cmodal-ic{background:#fee2e2;color:#ef4444}'
    + '.cmodal.danger .cmodal-ok{background:#ef4444}'
    + '.cmodal.danger .cmodal-ok:hover{background:#dc2626}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  var ICON_ASK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var ICON_DANGER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

  // ── DOM del modal ──
  var ov = document.createElement('div');
  ov.className = 'cmodal-ov';
  ov.innerHTML =
    '<div class="cmodal" role="alertdialog" aria-modal="true">'
    + '<div class="cmodal-ic"></div>'
    + '<div class="cmodal-ttl"></div>'
    + '<div class="cmodal-msg"></div>'
    + '<div class="cmodal-ft">'
    + '<button type="button" class="cmodal-btn cmodal-cancel"></button>'
    + '<button type="button" class="cmodal-btn cmodal-ok"></button>'
    + '</div></div>';

  var box, ic, ttl, msgEl, btnOk, btnCancel, resolver = null;

  function build() {
    document.body.appendChild(ov);
    box = ov.querySelector('.cmodal');
    ic = ov.querySelector('.cmodal-ic');
    ttl = ov.querySelector('.cmodal-ttl');
    msgEl = ov.querySelector('.cmodal-msg');
    btnOk = ov.querySelector('.cmodal-ok');
    btnCancel = ov.querySelector('.cmodal-cancel');
    btnOk.addEventListener('click', function () { done(true); });
    btnCancel.addEventListener('click', function () { done(false); });
    ov.addEventListener('click', function (e) { if (e.target === ov) done(false); });
    document.addEventListener('keydown', function (e) {
      if (!ov.classList.contains('open')) return;
      if (e.key === 'Escape') done(false);
    });
  }

  function open(opts) {
    opts = opts || {};
    if (!box) build();
    var danger = !!opts.danger;
    box.className = 'cmodal ' + (danger ? 'danger' : 'ask');
    ic.innerHTML = danger ? ICON_DANGER : ICON_ASK;
    if (opts.title) { ttl.textContent = opts.title; ttl.style.display = ''; }
    else { ttl.style.display = 'none'; }
    msgEl.textContent = opts.message || '¿Deseas continuar?';
    btnOk.textContent = opts.confirmText || (danger ? 'Eliminar' : 'Aceptar');
    btnCancel.textContent = opts.cancelText || 'Cancelar';
    ov.classList.add('open');
    (danger ? btnCancel : btnOk).focus();
    return new Promise(function (res) { resolver = res; });
  }

  function done(val) {
    ov.classList.remove('open');
    var r = resolver; resolver = null;
    if (r) r(val);
  }

  window.confirmModal = open;

  // ── Interceptar los confirm() inline existentes ──
  var RE_MSG = /confirm\(\s*(['"])([\s\S]*?)\1\s*\)/;
  function mensajeDe(attr) { var m = attr && attr.match(RE_MSG); return m ? m[2] : null; }
  function esPeligro(msg) { return /elimin|borrar|cancelar|quitar/i.test(msg); }

  // Formularios con onsubmit="return confirm('...')"
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    var msg = mensajeDe(form.getAttribute('onsubmit'));
    if (!msg) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    open({ message: msg, danger: esPeligro(msg) }).then(function (ok) {
      if (ok) form.submit(); // form.submit() no vuelve a disparar onsubmit
    });
  }, true);

  // Botones/enlaces con onclick="return confirm('...')"
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[onclick]');
    if (!el) return;
    var attr = el.getAttribute('onclick') || '';
    if (!/return\s+confirm\(/.test(attr)) return; // solo guardas puras de confirm
    var msg = mensajeDe(attr);
    if (!msg) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    open({ message: msg, danger: esPeligro(msg) }).then(function (ok) {
      if (!ok) return;
      if (el.form) el.form.submit();
      else if (el.tagName === 'A' && el.href) window.location.href = el.href;
    });
  }, true);
})();
