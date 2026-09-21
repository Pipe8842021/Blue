<?php
/**
 * Campana de avisos del panel (admin y profesional).
 * Se incluye en el topbar de includes/admin_layout.php y staff/_layout.php.
 * Las acciones (marcar como leído, enviar novedad) van a api/notificaciones.php.
 *
 * Las variables llevan el prefijo $campana para no chocar con las de la página
 * que incluye el layout.
 */
require_once __DIR__ . '/h-notificaciones.php';

$campanaUsuario = currentUser();
$campanaUserId  = (int)($campanaUsuario['id'] ?? 0);
$campanaEsAdmin = ($campanaUsuario['role'] ?? '') === 'admin';

try {
    $campanaNoLeidas = contarNotificacionesNoLeidas(getDB(), $campanaUserId);
    $campanaAvisos   = notificacionesDeUsuario(getDB(), $campanaUserId, 15);
} catch (Throwable $e) {
    // Sin la migración 03 (tabla notifications) el panel sigue funcionando, solo sin campana.
    error_log('[Blue/Avisos] No se pudo cargar la campana: ' . $e->getMessage());
    return;
}
?>
<link rel="stylesheet" href="/Blue/assets/css/m-notificaciones.css?v=<?= @filemtime(__DIR__ . '/../assets/css/m-notificaciones.css') ?>">

<div class="campana" id="campana" data-csrf="<?= e(csrfToken()) ?>">
  <button type="button" class="topbar-btn campana-boton" id="campanaBoton"
          aria-haspopup="true" aria-expanded="false" aria-controls="campanaPanel"
          aria-label="Avisos (<?= $campanaNoLeidas ?> sin leer)">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
    <span class="campana-contador" id="campanaContador" <?= $campanaNoLeidas ? '' : 'hidden' ?>><?= $campanaNoLeidas > 99 ? '99+' : $campanaNoLeidas ?></span>
  </button>

  <div class="campana-panel" id="campanaPanel" hidden>
    <div class="campana-cabecera">
      <strong>Avisos</strong>
      <button type="button" class="campana-link" id="campanaTodas" <?= $campanaNoLeidas ? '' : 'hidden' ?>>Marcar todas como leídas</button>
    </div>

    <div class="campana-lista">
      <?php if (!$campanaAvisos): ?>
        <p class="campana-vacio">No tienes avisos todavía. Aquí verás reservas nuevas, pagos, cancelaciones y novedades del equipo.</p>
      <?php endif; ?>
      <?php foreach ($campanaAvisos as $aviso):
          $campanaUrl = ($aviso['url'] && str_starts_with($aviso['url'], '/Blue/')) ? $aviso['url'] : '#';
      ?>
        <a class="campana-aviso campana-aviso--<?= e($aviso['type']) ?><?= $aviso['read_at'] ? '' : ' no-leido' ?>"
           href="<?= e($campanaUrl) ?>" data-id="<?= (int)$aviso['id'] ?>">
          <span class="campana-aviso-titulo"><?= e($aviso['title']) ?></span>
          <?php if ($aviso['body']): ?>
            <span class="campana-aviso-cuerpo"><?= e($aviso['body']) ?></span>
          <?php endif; ?>
          <span class="campana-aviso-hace"><?= e(haceCuantoNotificacion($aviso['created_at'])) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($campanaEsAdmin): ?>
      <details class="campana-novedad">
        <summary>Enviar una novedad al equipo</summary>
        <form id="campanaNovedad">
          <input type="text" name="titulo" class="form-control" maxlength="150" required
                 placeholder="Título (ej. Mañana abrimos a las 10:00)">
          <textarea name="mensaje" class="form-control" maxlength="500" rows="3" placeholder="Mensaje (opcional)"></textarea>
          <div class="campana-novedad-pie">
            <span class="campana-novedad-estado" id="campanaNovedadEstado" role="status"></span>
            <button type="submit" class="btn btn-primary">Enviar</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>

<script>
(() => {
  const raiz = document.getElementById('campana');
  if (!raiz) return;
  const boton    = document.getElementById('campanaBoton');
  const panel    = document.getElementById('campanaPanel');
  const contador = document.getElementById('campanaContador');
  const todas    = document.getElementById('campanaTodas');

  const enviar = datos => fetch('/Blue/api/notificaciones.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': raiz.dataset.csrf },
    body: new URLSearchParams(datos),
  }).then(r => r.json());

  const pintarContador = n => {
    contador.hidden      = n === 0;
    contador.textContent = n > 99 ? '99+' : n;
    todas.hidden         = n === 0;
    boton.setAttribute('aria-label', `Avisos (${n} sin leer)`);
  };

  const cerrar = () => { panel.hidden = true; boton.setAttribute('aria-expanded', 'false'); };

  // Si la campana está en la mitad izquierda de la pantalla (en el panel admin queda
  // junto al título, al lado del menú lateral), el panel abre hacia la derecha.
  const ubicarPanel = () => {
    const b = boton.getBoundingClientRect();
    panel.classList.toggle('campana-panel--derecha',
      window.innerWidth > 520 && b.left + b.width / 2 < window.innerWidth / 2);
  };

  boton.addEventListener('click', e => {
    e.stopPropagation();
    const abrir = panel.hidden;
    panel.hidden = !abrir;
    boton.setAttribute('aria-expanded', String(abrir));
    if (abrir) ubicarPanel();
  });
  document.addEventListener('click', e => { if (!raiz.contains(e.target)) cerrar(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });

  // Abrir un aviso lo marca como leído y lleva a la cita (si tiene enlace).
  panel.querySelectorAll('.campana-aviso').forEach(aviso => aviso.addEventListener('click', e => {
    const destino = aviso.getAttribute('href');
    if (!aviso.classList.contains('no-leido')) {
      if (destino === '#') e.preventDefault();
      return;
    }
    e.preventDefault();
    aviso.classList.remove('no-leido');
    enviar({ action: 'marcar_leida', id: aviso.dataset.id })
      .then(d => { if (typeof d.no_leidas === 'number') pintarContador(d.no_leidas); })
      .catch(() => {})
      .finally(() => { if (destino !== '#') window.location.href = destino; });
  }));

  todas.addEventListener('click', () => enviar({ action: 'marcar_todas' }).then(d => {
    if (!d.ok) return;
    panel.querySelectorAll('.no-leido').forEach(a => a.classList.remove('no-leido'));
    pintarContador(0);
  }).catch(() => {}));

  const novedad = document.getElementById('campanaNovedad');
  novedad?.addEventListener('submit', e => {
    e.preventDefault();
    const estado = document.getElementById('campanaNovedadEstado');
    const datos  = Object.fromEntries(new FormData(novedad));
    estado.textContent = 'Enviando…';
    enviar({ action: 'publicar', ...datos })
      .then(d => {
        estado.textContent = d.msg || d.error || 'No se pudo enviar.';
        if (d.ok) novedad.reset();
      })
      .catch(() => { estado.textContent = 'No se pudo enviar. Intenta de nuevo.'; });
  });
})();
</script>
