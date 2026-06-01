<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

// Cargar servicios agrupados por categoría
try {
    $db = getDB();
    $rows = $db->query(
        'SELECT s.id, s.name, s.description, s.duration_min, s.price,
                c.id AS cat_id, c.name AS cat_name, c.icon AS cat_icon
         FROM services s
         JOIN categories c ON s.category_id = c.id
         WHERE s.active = 1 AND c.active = 1
         ORDER BY c.id, s.id'
    )->fetchAll();

    $categories = [];
    foreach ($rows as $r) {
        $categories[$r['cat_id']] ??= ['name' => $r['cat_name'], 'icon' => $r['cat_icon'], 'services' => []];
        $categories[$r['cat_id']]['services'][] = $r;
    }
} catch (Exception $e) {
    $categories = [];
}

$catIcons = ['body'=>'🫀','face'=>'✨','laser'=>'💫','spa'=>'🌿','therapy'=>'🔬'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reservar cita — Blue Therapy</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/Blue/assets/css/booking.css">
  <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
</head>
<body class="booking-page">

<!-- ── Navbar ── -->
<nav style="position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border-bottom:1px solid rgba(0,0,0,.06);display:flex;align-items:center;justify-content:space-between;padding:0 32px;height:68px;font-family:'DM Sans',sans-serif">
  <a href="/Blue/" style="display:flex;align-items:center;gap:10px;text-decoration:none">
    <div style="width:38px;height:38px;border-radius:50%;background:#1a1a1a;display:flex;align-items:center;justify-content:center">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="#5bc4b8"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
    </div>
    <div style="line-height:1.1">
      <div style="font-family:'Playfair Display',serif;font-style:italic;font-size:17px;color:#5bc4b8">Blue</div>
      <div style="font-size:8px;letter-spacing:3px;text-transform:uppercase;color:#1a1a1a">THERAPY</div>
    </div>
  </a>
  <a href="/Blue/" style="font-size:13px;font-weight:500;color:#777;display:flex;align-items:center;gap:6px">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    Volver al inicio
  </a>
</nav>

<!-- ── Hero ── -->
<div class="booking-hero">
  <div class="booking-hero-eyebrow">Reserva en línea</div>
  <h1>Agenda tu <span class="accent">cita</span></h1>
  <p>Elige tu servicio, escoge tu horario y listo — sin llamadas, sin esperas</p>
</div>

<!-- ── Booking wizard ── -->
<div class="booking-wrapper">
<div class="booking-layout">

<!-- Columna principal -->
<div class="booking-main-col">
<div class="booking-card">

  <!-- Progress -->
  <div class="progress-nav" id="progress-nav">

    <?php
    $stepLabels = [1 => 'Servicio', 2 => 'Horario', 3 => 'Datos', 4 => 'Confirmación'];
    $checkSvg   = '<svg class="p-check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';
    foreach ($stepLabels as $n => $label):
    ?>
      <div class="progress-step <?= $n === 1 ? 'active' : '' ?>" id="ps-<?= $n ?>">
        <div class="progress-icon">
          <span class="p-num"><?= $n ?></span>
          <?= $checkSvg ?>
        </div>
        <div class="progress-label"><?= $label ?></div>
      </div>
      <?php if ($n < 4): ?>
        <div class="progress-line" id="pl-<?= $n ?>"></div>
      <?php endif; ?>
    <?php endforeach; ?>

  </div>

  <!-- ═══ STEP 1: Servicios ═══ -->
  <div class="step-body">
  <div class="step-panel active" id="step-1">
    <div class="step-heading">
      <div class="step-eyebrow">Paso 1 de 4</div>
      <div class="step-title">Selecciona tu servicio</div>
      <div class="step-subtitle">Puedes elegir uno o varios para la misma cita. El tiempo total se calcula automáticamente.</div>
    </div>

    <?php if (empty($categories)): ?>
      <p style="color:#999;font-size:14px;padding:20px 0">No hay servicios disponibles en este momento.</p>
    <?php else: ?>
      <div id="service-categories">
        <?php foreach ($categories as $catId => $cat): ?>
          <div class="category-group">
            <div class="category-label">
              <?= $catIcons[$cat['icon']] ?? '✦' ?> &nbsp;<?= htmlspecialchars($cat['name']) ?>
            </div>
            <div class="services-list">
              <?php foreach ($cat['services'] as $svc): ?>
                <label class="service-option"
                       data-id="<?= $svc['id'] ?>"
                       data-duration="<?= $svc['duration_min'] ?>"
                       data-price="<?= $svc['price'] ?>"
                       data-name="<?= htmlspecialchars($svc['name']) ?>">
                  <input type="checkbox" name="services[]" value="<?= $svc['id'] ?>">
                  <div class="service-opt-body">
                    <div class="service-opt-icon"><?= $catIcons[$cat['icon']] ?? '✦' ?></div>
                    <div class="service-opt-info">
                      <div class="service-opt-name"><?= htmlspecialchars($svc['name']) ?></div>
                      <div class="service-opt-meta"><?= $svc['duration_min'] ?> min</div>
                    </div>
                    <div class="service-opt-price"><?= formatPrice($svc['price']) ?></div>
                    <div class="service-opt-check">✓</div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="selection-bar" id="sel-bar">
      <span class="selection-bar-label" id="sel-bar-label">Selecciona al menos un servicio para continuar</span>
      <div class="selection-bar-right">
        <span class="selection-bar-duration" id="sel-bar-dur"></span>
        <span class="selection-bar-total"    id="sel-bar-value"></span>
      </div>
    </div>
  </div>

  <!-- ═══ STEP 2: Calendario ═══ -->
  <div class="step-panel" id="step-2">
    <div class="step-heading">
      <div class="step-eyebrow">Paso 2 de 4</div>
      <div class="step-title">Elige tu horario</div>
      <div class="step-subtitle">Los cajones en rojo ya están ocupados. Haz clic en uno disponible para seleccionarlo.</div>
    </div>

    <div class="cal-header">
      <button class="cal-nav-btn" id="btn-prev-week">&#8592; Anterior</button>
      <span class="cal-week-label" id="cal-week-label"></span>
      <button class="cal-nav-btn" id="btn-next-week">Siguiente &#8594;</button>
    </div>

    <div id="cal-container">
      <div class="cal-loading"><div class="spinner"></div>Cargando disponibilidad…</div>
    </div>

    <div class="cal-legend" style="margin-top:14px">
      <div class="legend-item">
        <span class="legend-dot" style="background:#fff;border:1.5px solid #e0e0e0"></span>
        Disponible
      </div>
      <div class="legend-item">
        <span class="legend-dot" style="background:#fff0f0;border:1.5px solid rgba(224,85,85,.25);color:#d05050">Ocupado</span>
        Ocupado
      </div>
      <div class="legend-item">
        <span class="legend-dot" style="background:#f5f5f5;color:#c0c0c0;font-size:8px">Cerrado</span>
        Cerrado
      </div>
      <div class="legend-item">
        <span class="legend-dot" style="background:#5bc4b8"></span>
        Seleccionado
      </div>
    </div>

    <div class="slot-banner" id="slot-banner">
      <span>📅</span>
      <span>Seleccionaste: <strong id="slot-banner-text"></strong></span>
    </div>
  </div>

  <!-- ═══ STEP 3: Datos personales ═══ -->
  <div class="step-panel" id="step-3">
    <div class="step-heading">
      <div class="step-eyebrow">Paso 3 de 4</div>
      <div class="step-title">Tus datos de contacto</div>
      <div class="step-subtitle">Solo usamos esta información para confirmar y recordarte tu cita.</div>
    </div>

    <div class="booking-summary-bar" id="bsb"></div>

    <form id="contact-form" novalidate>
      <div class="form-row">
        <div class="form-group">
          <label for="f-name">Nombre completo *</label>
          <input type="text" id="f-name" placeholder="Tu nombre completo" autocomplete="name">
        </div>
      </div>
      <div class="form-row two-col">
        <div class="form-group">
          <label for="f-phone">Teléfono / WhatsApp *</label>
          <input type="tel" id="f-phone" placeholder="+57 300 000 0000" autocomplete="tel">
        </div>
        <div class="form-group">
          <label for="f-email">Correo electrónico</label>
          <input type="email" id="f-email" placeholder="opcional" autocomplete="email">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="f-note">Nota adicional</label>
          <textarea id="f-note" rows="3" placeholder="¿Algo que debamos saber antes de tu cita?"></textarea>
        </div>
      </div>
      <label class="whatsapp-label">
        <input type="checkbox" id="f-whatsapp" checked>
        <div class="whatsapp-toggle"></div>
        <div class="whatsapp-text">
          <strong>Recordatorio por WhatsApp</strong>
          <span>Te enviaremos un mensaje de confirmación y recordatorio a tu número.</span>
        </div>
      </label>
    </form>
  </div>

  <!-- ═══ STEP 4: Confirmación ═══ -->
  <div class="step-panel" id="step-4">

    <!-- Enviando -->
    <div id="s4-sending" style="text-align:center;padding:40px 0">
      <div class="spinner" style="margin:0 auto 16px;width:40px;height:40px;border-width:4px"></div>
      <p style="color:#999;font-size:14px">Enviando tu solicitud…</p>
    </div>

    <!-- Éxito -->
    <div id="s4-success" class="confirm-wrap" style="display:none">
      <div class="confirm-icon">✓</div>
      <div class="confirm-title">¡Solicitud enviada!</div>
      <p class="confirm-subtitle">
        Hemos recibido tu solicitud de cita. A continuación el resumen:
      </p>
      <div class="confirm-details"><div class="confirm-details-grid" id="s4-details"></div></div>
      <div class="confirm-notice">
        <span class="confirm-notice-icon">💬</span>
        <div>
          <strong style="display:block;margin-bottom:4px;color:#92580a">Tu cita está pendiente de confirmación</strong>
          El equipo de Blue Therapy revisará tu solicitud y se pondrá en contacto contigo por WhatsApp al número que indicaste para confirmar la cita, coordinar el anticipo si aplica y cualquier detalle adicional.
        </div>
      </div>
      <a href="/Blue/" class="btn-home">← Volver al inicio</a>
    </div>

    <!-- Error -->
    <div id="s4-error" class="error-wrap" style="display:none">
      <div class="error-icon">😞</div>
      <div class="confirm-title" style="font-size:20px">Algo salió mal</div>
      <p class="error-msg" id="s4-error-msg">Por favor intenta de nuevo.</p>
      <button class="btn-back-step" onclick="goToStep(3)">← Volver a mis datos</button>
    </div>

  </div>
  </div><!-- /step-body -->

  <!-- ── Navigation buttons ── -->
  <div class="step-actions" id="step-actions">
    <button class="btn-back-step" id="btn-back" style="visibility:hidden">← Atrás</button>
    <button class="btn-next-step" id="btn-next" disabled>
      <span id="btn-next-label">Continuar</span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </button>
  </div>

</div><!-- /booking-card -->
</div><!-- /booking-main-col -->

<!-- Sidebar -->
<div class="booking-sidebar-col">
  <div class="booking-sidebar">

    <div class="sidebar-top">
      <div class="sidebar-top-title">Tu reserva</div>
      <div class="sidebar-top-sub">Resumen de tu selección</div>
    </div>

    <div class="sidebar-body" id="sidebar-body">
      <div class="sidebar-empty">
        <div class="sidebar-empty-icon">✦</div>
        <p>Selecciona un servicio para ver el resumen aquí</p>
      </div>
    </div>

    <div class="sidebar-info">
      <div class="sidebar-info-item">
        <div class="sii-icon">📍</div>
        <div>
          <div class="sii-label">Dirección</div>
          <div class="sii-value">Tu ciudad, Colombia</div>
        </div>
      </div>
      <div class="sidebar-info-item">
        <div class="sii-icon">🕐</div>
        <div>
          <div class="sii-label">Horario</div>
          <div class="sii-value">Lun – Sáb · 8:00 am – 7:00 pm</div>
        </div>
      </div>
      <div class="sidebar-info-item">
        <div class="sii-icon">📞</div>
        <div>
          <div class="sii-label">Teléfono</div>
          <div class="sii-value">+57 300 123 4567</div>
        </div>
      </div>
    </div>

  </div>
</div><!-- /booking-sidebar-col -->

</div><!-- /booking-layout -->
</div><!-- /booking-wrapper -->

<script src="/Blue/assets/js/booking.js"></script>
</body>
</html>
