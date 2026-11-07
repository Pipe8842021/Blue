/* ============================================================
   table-scroll.js — Indicador de scroll horizontal en tablas
   Patrón portado de AMIMBR3: cuando una tabla tiene más ancho del
   que cabe, muestra un degradado difuminado a la derecha y una
   píldora "Deslizar". Al deslizar hasta el final, ambos desaparecen.
   Se aplica a todos los .table-wrap (contenedor con overflow-x:auto).
   ============================================================ */
(function () {
  'use strict';
  if (window.__tableScrollReady) return;
  window.__tableScrollReady = true;

  var FLECHA =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' +
    '<polyline points="7 5 14 12 7 19"/><polyline points="13 5 20 12 13 19"/></svg>';

  function equipar(container) {
    // Evita envolver dos veces
    if (container.parentElement && container.parentElement.classList.contains('table-scroll-outer')) return;

    var outer = document.createElement('div');
    outer.className = 'table-scroll-outer';
    container.parentNode.insertBefore(outer, container);
    outer.appendChild(container);

    var badge = document.createElement('div');
    badge.className = 'table-scroll-badge';
    badge.innerHTML = FLECHA + '<span>Deslizar</span>';
    outer.appendChild(badge);

    function check() {
      var overflows = container.scrollWidth > container.clientWidth + 2;
      var atEnd = container.scrollLeft >= container.scrollWidth - container.clientWidth - 4;
      outer.classList.toggle('has-overflow', overflows && !atEnd);
    }
    check();
    container.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check, { passive: true });
  }

  // Rotula cada celda con el encabezado de su columna (data-label) para que, en celular,
  // la tabla se muestre como tarjetas (ver "Tablas en tarjetas" en admin.css).
  function etiquetar(tabla) {
    var cabeceras = Array.prototype.map.call(
      tabla.querySelectorAll('thead th'),
      function (th) { return th.textContent.trim(); }
    );
    tabla.querySelectorAll('tbody tr').forEach(function (fila) {
      Array.prototype.forEach.call(fila.children, function (celda, i) {
        if (celda.colSpan > 1) return;
        var texto = cabeceras[i] || '';
        // La primera columna es el título de la tarjeta; "Acciones" va sin rótulo
        if (i === 0) celda.classList.add('td-titulo');
        else if (texto && !/^acciones?$/i.test(texto)) {
          celda.setAttribute('data-label', texto);
          // Un solo hijo para que el contenido (varias líneas) no se reparta en columnas
          var valor = document.createElement('span');
          valor.className = 'td-valor';
          while (celda.firstChild) valor.appendChild(celda.firstChild);
          celda.appendChild(valor);
        } else celda.classList.add('td-acciones');
      });
    });
  }

  function init() {
    document.querySelectorAll('table.data-table').forEach(etiquetar);
    document.querySelectorAll('.table-wrap').forEach(equipar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
