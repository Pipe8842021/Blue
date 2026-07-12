/* ============================================================
   row-menu.js — Menú desplegable de acciones por fila (Blue Therapy)
   Un botón "⋯" abre un panel con todas las acciones de la fila.
   El panel se posiciona con position:fixed para no recortarse dentro
   de tablas con overflow. Se cierra al hacer clic fuera, scroll o Esc.
   ============================================================ */
(function () {
  'use strict';
  if (window.__rowMenuReady) return;
  window.__rowMenuReady = true;

  var openPanel = null, openTrigger = null;

  function close() {
    if (!openPanel) return;
    openPanel.classList.remove('open');
    openPanel.style.cssText = '';
    if (openTrigger) openTrigger.classList.remove('active');
    openPanel = null; openTrigger = null;
  }

  function place(trigger, panel) {
    var r = trigger.getBoundingClientRect();
    panel.classList.add('open');
    panel.style.position = 'fixed';
    panel.style.visibility = 'hidden';
    var pw = panel.offsetWidth, ph = panel.offsetHeight;
    var left = r.right - pw;
    if (left < 8) left = 8;
    var top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = r.top - 6 - ph; // abrir hacia arriba si no cabe
    if (top < 8) top = 8;
    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
    panel.style.visibility = '';
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.rowmenu-trigger');
    if (trigger) {
      e.preventDefault();
      var panel = trigger.parentElement.querySelector('.rowmenu-panel');
      if (panel === openPanel) { close(); return; }
      close();
      openPanel = panel; openTrigger = trigger;
      trigger.classList.add('active');
      place(trigger, panel);
      return;
    }
    if (openPanel && e.target.closest('.rowmenu-panel')) { setTimeout(close, 0); return; }
    close();
  });

  window.addEventListener('scroll', close, true);
  window.addEventListener('resize', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
