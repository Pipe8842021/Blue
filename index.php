<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Servicios destacados y categorías reales (si la BD falla, la home sigue viva)
$destacados = [];
$categorias = [];
try {
    $db = getDB();
    $destacados = $db->query("
        SELECT s.name, s.description, s.image, s.duration_min, s.price, c.name AS category_name
        FROM services s JOIN categories c ON c.id = s.category_id
        WHERE s.active = 1 AND s.featured = 1
        ORDER BY c.name, s.name
        LIMIT 6")->fetchAll();

    $categorias = $db->query("
        SELECT c.name, COUNT(s.id) AS n
        FROM categories c LEFT JOIN services s ON s.category_id = c.id AND s.active = 1
        GROUP BY c.id, c.name
        HAVING n > 0
        ORDER BY n DESC, c.name
        LIMIT 4")->fetchAll();
} catch (Throwable $e) {
    // Sin datos no se rompe la página pública: las secciones simplemente no se pintan
}

// Cargar imágenes de la galería desde carpeta
$gallery_dir    = __DIR__ . '/assets/img/gallery/';
$gallery_images = [];
if (is_dir($gallery_dir)) {
    foreach (scandir($gallery_dir) as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
            $gallery_images[] = $file;
        }
    }
}

// Placeholders visuales mientras no hay imágenes subidas
$placeholders = [
    ['h' => 260, 'bg' => 'linear-gradient(135deg,#d4f0ec,#8ecfc8)'],
    ['h' => 200, 'bg' => 'linear-gradient(145deg,#fce4e4,#e8aaaa)'],
    ['h' => 320, 'bg' => 'linear-gradient(135deg,#f5e8f0,#d8a8c8)'],
    ['h' => 180, 'bg' => 'linear-gradient(135deg,#d4f0ec,#b8e8e0)'],
    ['h' => 240, 'bg' => 'linear-gradient(135deg,#fff3e0,#f0c880)'],
    ['h' => 300, 'bg' => 'linear-gradient(145deg,#fce4e4,#e0a8a8)'],
    ['h' => 200, 'bg' => 'linear-gradient(135deg,#d4f0ec,#a0d8d0)'],
    ['h' => 280, 'bg' => 'linear-gradient(135deg,#f5e8f0,#d8b8d0)'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blue Therapy — Tu momento de bienestar</title>
  <meta name="description" content="Centro especializado en tratamientos corporales, faciales, depilación láser, spa y terapias biológicas. Reserva tu cita en línea.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

  <style>
/* ─── Variables ─── */
:root {
  --teal:       #5bc4b8;
  --teal-dark:  #3aa89e;
  --teal-light: #e6f7f5;
  --pink-light: #fce8e8;
  --bg-hero:    linear-gradient(135deg, #d4f0ec 0%, #f5e8f0 60%, #fce4e4 100%);
  --dark:       #1a1a1a;
  --text:       #333;
  --muted:      #777;
  --white:      #ffffff;
  --gold:       #c8a96e;
}

/* ─── Reset ─── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { font-family: 'DM Sans', sans-serif; color: var(--text); background: #fafafa; }
a { text-decoration: none; color: inherit; }
img { max-width: 100%; display: block; }

/* ────────────────────────────────────────
   NAVBAR
──────────────────────────────────────── */
nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  background: rgba(255,255,255,0.92);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(0,0,0,0.06);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 40px; height: 72px;
  transition: box-shadow .25s;
}
.nav-logo { display: flex; align-items: center; gap: 10px; }
.nav-logo-circle {
  width: 42px; height: 42px; border-radius: 50%;
  background: var(--dark); display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.nav-logo-circle svg { width: 22px; height: 22px; fill: var(--teal); }
.nav-logo-text { display: flex; flex-direction: column; line-height: 1.1; }
.nav-logo-text span:first-child {
  font-family: 'Playfair Display', serif; font-style: italic;
  font-size: 18px; color: var(--teal);
}
.nav-logo-text span:last-child {
  font-size: 9px; letter-spacing: 3px; text-transform: uppercase; color: var(--dark);
}
.nav-links { display: flex; gap: 32px; }
.nav-links a {
  font-size: 14px; font-weight: 500; color: var(--dark); transition: color .2s;
}
.nav-links a:hover { color: var(--teal); }
.nav-right { display: flex; align-items: center; gap: 16px; }
.nav-phone { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
.nav-phone svg { width: 14px; height: 14px; flex-shrink: 0; }

/* Botón admin — sutil, solo ícono */
.nav-admin-btn {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 8px;
  border: 1.5px solid #e0e0e0; color: var(--muted);
  transition: all .2s; flex-shrink: 0;
}
.nav-admin-btn:hover { border-color: var(--dark); color: var(--dark); background: rgba(0,0,0,0.04); }
.nav-admin-btn svg { width: 16px; height: 16px; }

.btn-reservar {
  background: var(--teal); color: #fff; border: none; border-radius: 8px;
  padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; gap: 7px; transition: background .2s, transform .15s;
  font-family: 'DM Sans', sans-serif;
}
.btn-reservar:hover { background: var(--teal-dark); transform: translateY(-1px); }
.btn-reservar svg { width: 16px; height: 16px; }

/* ────────────────────────────────────────
   HERO
──────────────────────────────────────── */
.hero {
  min-height: 100vh;
  background: var(--bg-hero);
  display: flex; align-items: center; justify-content: center;
  text-align: center; padding: 100px 24px 60px;
}
.hero-inner { max-width: 760px; }
.hero-badge {
  display: inline-block; border: 1px solid var(--teal);
  color: var(--teal); font-size: 12px; padding: 5px 16px;
  border-radius: 20px; letter-spacing: .5px; margin-bottom: 28px;
}
.hero h1 {
  font-family: 'Playfair Display', serif; font-size: clamp(42px, 6vw, 72px);
  font-weight: 700; line-height: 1.1; color: var(--dark); margin-bottom: 22px;
}
.hero h1 .accent { color: var(--teal); }
.hero p {
  font-size: 16px; color: var(--muted); max-width: 520px; margin: 0 auto 38px; line-height: 1.7;
}
.hero-buttons { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
.btn-primary {
  background: var(--teal); color: #fff; border-radius: 50px;
  padding: 14px 28px; font-size: 15px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
  transition: background .2s, transform .15s; border: none; font-family: 'DM Sans', sans-serif;
}
.btn-primary:hover { background: var(--teal-dark); transform: translateY(-2px); }
.btn-outline {
  background: transparent; border: 1.5px solid var(--dark);
  color: var(--dark); border-radius: 50px;
  padding: 14px 28px; font-size: 15px; font-weight: 500;
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
  transition: border-color .2s, color .2s, transform .15s; font-family: 'DM Sans', sans-serif;
}
.btn-outline:hover { border-color: var(--teal); color: var(--teal); transform: translateY(-2px); }

/* ────────────────────────────────────────
   STATS
──────────────────────────────────────── */
.stats {
  background: var(--dark); padding: 56px 40px;
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 0;
}
.stat {
  text-align: center; padding: 20px;
  border-right: 1px solid rgba(255,255,255,0.08);
}
.stat:last-child { border-right: none; }
.stat-icon {
  width: 54px; height: 54px; border-radius: 14px;
  background: rgba(200,169,110,0.18); margin: 0 auto 16px;
  display: flex; align-items: center; justify-content: center;
}
.stat-icon svg { width: 26px; height: 26px; color: var(--gold); }
.stat-num {
  font-family: 'Playfair Display', serif; font-size: 36px;
  font-weight: 700; color: #fff; margin-bottom: 6px;
}
.stat-label { font-size: 12px; color: rgba(255,255,255,0.5); letter-spacing: .5px; }

/* ────────────────────────────────────────
   SERVICIOS
──────────────────────────────────────── */
.services { background: #f8f8f8; padding: 90px 40px; text-align: center; }
.section-badge {
  display: inline-block; font-size: 13px; color: var(--muted);
  border: 1px solid #ddd; border-radius: 20px; padding: 4px 16px; margin-bottom: 18px;
}
.section-title {
  font-family: 'Playfair Display', serif; font-size: clamp(32px, 4vw, 48px);
  font-weight: 700; color: var(--dark); margin-bottom: 14px; line-height: 1.2;
}
.section-title .accent { color: var(--teal); }
.section-sub { font-size: 15px; color: var(--muted); max-width: 480px; margin: 0 auto 48px; line-height: 1.6; }
.services-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px;
  max-width: 1100px; margin: 0 auto 40px;
}
.service-card {
  background: #fff; border-radius: 18px; padding: 36px 24px;
  border: 1.5px solid transparent; cursor: pointer;
  transition: border-color .25s, box-shadow .25s, transform .25s;
}
.service-card:nth-child(1) { background: #eaf7f5; }
.service-card:nth-child(2) { background: #fff; border-color: #eee; }
.service-card:nth-child(3) { background: #faf5ea; }
.service-card:nth-child(4) { background: #eaf7f5; }
.service-card:hover { box-shadow: 0 12px 36px rgba(0,0,0,.08); transform: translateY(-4px); }
.service-icon { font-size: 28px; width: 52px; height: 52px; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; }
.service-name { font-weight: 600; font-size: 15px; color: var(--dark); margin-bottom: 6px; }
.service-count { font-size: 12px; color: var(--muted); }
.btn-link {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 14px; font-weight: 500; color: var(--dark);
  border: 1.5px solid #ddd; border-radius: 50px; padding: 10px 22px;
  transition: border-color .2s, color .2s;
}
.btn-link:hover { border-color: var(--teal); color: var(--teal); }
.service-icon svg { width: 30px; height: 30px; color: var(--teal); }

/* Servicios destacados (vienen del panel: services.featured) */
.featured-head { margin: 56px auto 26px; }
.featured-head h3 {
  font-family: 'Playfair Display', serif; font-size: clamp(24px, 3vw, 32px);
  font-weight: 600; color: var(--dark); margin-bottom: 6px;
}
.featured-head p { font-size: 14px; color: var(--muted); }
.featured-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px;
  max-width: 1100px; margin: 0 auto 44px; text-align: left;
}
.featured-card {
  background: #fff; border-radius: 18px; overflow: hidden;
  border: 1px solid #eee; display: flex; flex-direction: column;
  transition: box-shadow .25s, transform .25s;
}
.featured-card:hover { box-shadow: 0 14px 40px rgba(0,0,0,.09); transform: translateY(-4px); }
.featured-img {
  position: relative; aspect-ratio: 16/10;
  background: linear-gradient(135deg, #d4f0ec, #8ecfc8);
}
.featured-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.featured-tag {
  position: absolute; top: 12px; left: 12px;
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 11px; border-radius: 50px;
  background: rgba(255,255,255,.94); color: var(--gold);
  font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
  box-shadow: 0 2px 10px rgba(0,0,0,.08);
}
.featured-tag svg { width: 11px; height: 11px; }
.featured-body { padding: 20px 22px 22px; display: flex; flex-direction: column; flex: 1; }
.featured-cat {
  font-size: 11px; font-weight: 600; letter-spacing: .06em;
  text-transform: uppercase; color: var(--teal); margin-bottom: 7px;
}
.featured-body h4 {
  font-family: 'Playfair Display', serif; font-size: 19px;
  font-weight: 600; color: var(--dark); margin-bottom: 7px;
}
.featured-body p {
  font-size: 13.5px; color: var(--muted); line-height: 1.6; margin-bottom: 16px;
}
.featured-foot {
  display: flex; align-items: baseline; justify-content: space-between;
  gap: 10px; margin-top: auto; padding-top: 14px; border-top: 1px solid #f0f0f0;
}
.featured-price { font-size: 17px; font-weight: 700; color: var(--dark); }
.featured-dur { font-size: 12.5px; color: var(--muted); }
@media (max-width: 900px) { .featured-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px) { .featured-grid { grid-template-columns: 1fr; } }

/* ────────────────────────────────────────
   CTA BANNER
──────────────────────────────────────── */
.cta-banner { background: var(--bg-hero); padding: 80px 24px; text-align: center; }
.cta-banner h2 {
  font-family: 'Playfair Display', serif; font-size: clamp(28px, 4vw, 44px);
  font-weight: 700; color: var(--dark); margin-bottom: 16px;
}
.cta-banner p { font-size: 15px; color: var(--muted); max-width: 520px; margin: 0 auto 32px; line-height: 1.6; }
.btn-dark {
  background: var(--dark); color: #fff; border-radius: 50px;
  padding: 14px 30px; font-size: 15px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
  transition: background .2s, transform .15s; border: none; font-family: 'DM Sans', sans-serif;
}
.btn-dark:hover { background: #333; transform: translateY(-2px); }

/* ────────────────────────────────────────
   TESTIMONIOS
──────────────────────────────────────── */
.testimonials { background: #fff; padding: 90px 40px; text-align: center; }
.testimonials-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;
  max-width: 1000px; margin: 48px auto 0;
}
.testi-card { background: #f8f8f8; border-radius: 18px; padding: 28px 24px; text-align: left; }
.testi-stars { color: #f4c430; font-size: 14px; margin-bottom: 14px; }
.testi-text { font-size: 14px; color: var(--text); line-height: 1.7; margin-bottom: 20px; }
.testi-author { display: flex; align-items: center; gap: 12px; }
.testi-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--teal); display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.testi-name { font-weight: 600; font-size: 13px; }
.testi-role { font-size: 11px; color: var(--muted); }

/* ────────────────────────────────────────
   GALERÍA — Masonry estático
──────────────────────────────────────── */
.gallery { background: #fafafa; padding: 90px 40px; }
.gallery-header { text-align: center; margin-bottom: 48px; }

.gallery-grid {
  columns: 4;
  column-gap: 14px;
  max-width: 1200px;
  margin: 0 auto;
}

/* Ítem con imagen real */
.gallery-item {
  position: relative;
  border-radius: 16px;
  overflow: hidden;
  background: #e8e8e8;
  cursor: pointer;
  margin-bottom: 14px;
  break-inside: avoid;
  display: block;
  transition: transform .3s, box-shadow .3s;
}
.gallery-item:hover { transform: scale(1.02); box-shadow: 0 12px 32px rgba(0,0,0,0.18); }
.gallery-item img { width: 100%; height: auto; display: block; border-radius: 16px; }

.gallery-item .overlay {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0); border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  transition: background .3s;
}
.gallery-item:hover .overlay { background: rgba(91,196,184,0.28); }
.gallery-item .overlay svg {
  color: #fff; width: 40px; height: 40px; opacity: 0;
  filter: drop-shadow(0 2px 6px rgba(0,0,0,0.4));
  transition: opacity .3s;
}
.gallery-item:hover .overlay svg { opacity: 1; }

/* Ítem placeholder — sin interacción */
.gallery-item--placeholder {
  cursor: default;
  border-radius: 16px;
  margin-bottom: 14px;
  break-inside: avoid;
  display: block;
}
.gallery-item--placeholder:hover { transform: none; box-shadow: none; }

/* Lightbox */
.lightbox {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.88);
  z-index: 200; align-items: center; justify-content: center; padding: 24px;
}
.lightbox.open { display: flex; }
.lightbox img { max-width: 90vw; max-height: 88vh; border-radius: 12px; object-fit: contain; }
.lightbox-close {
  position: absolute; top: 20px; right: 24px;
  background: rgba(255,255,255,.15); border: none; border-radius: 50%;
  width: 40px; height: 40px; font-size: 20px; color: #fff;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
}

/* ────────────────────────────────────────
   FOOTER
──────────────────────────────────────── */
footer { background: var(--dark); color: rgba(255,255,255,0.7); padding: 60px 40px 30px; }
.footer-grid {
  display: grid; grid-template-columns: 2fr 1fr 1fr 1.5fr; gap: 40px; margin-bottom: 40px;
}
.footer-brand p { font-size: 13px; line-height: 1.7; color: rgba(255,255,255,0.5); max-width: 220px; }
.footer-col h4 {
  font-size: 13px; font-weight: 600; color: rgba(200,169,110,0.9);
  letter-spacing: .5px; text-transform: uppercase; margin-bottom: 16px;
}
.footer-col ul { list-style: none; }
.footer-col li { margin-bottom: 10px; }
.footer-col a { font-size: 13px; color: rgba(255,255,255,0.55); transition: color .2s; }
.footer-col a:hover { color: var(--teal); }
.footer-contact p { font-size: 13px; color: rgba(255,255,255,0.55); line-height: 1.8; }
.footer-bottom {
  border-top: 1px solid rgba(255,255,255,0.08);
  padding-top: 22px; display: flex; justify-content: space-between; align-items: center;
  font-size: 12px; color: rgba(255,255,255,0.3);
}
.footer-admin-link { color: rgba(255,255,255,0.25); transition: color .2s; }
.footer-admin-link:hover { color: var(--teal); }

/* ────────────────────────────────────────
   RESPONSIVE
──────────────────────────────────────── */
@media (max-width: 1000px) { .gallery-grid { columns: 3; } }
@media (max-width: 900px) {
  .stats             { grid-template-columns: repeat(2, 1fr); }
  .services-grid     { grid-template-columns: repeat(2, 1fr); }
  .testimonials-grid { grid-template-columns: 1fr 1fr; }
  .footer-grid       { grid-template-columns: 1fr 1fr; }
  nav                { padding: 0 20px; }
  .nav-links, .nav-phone { display: none; }
}
@media (max-width: 680px)  { .gallery-grid { columns: 2; } }
@media (max-width: 600px) {
  .stats             { grid-template-columns: repeat(2, 1fr); }
  .services-grid     { grid-template-columns: 1fr 1fr; }
  .testimonials-grid { grid-template-columns: 1fr; }
  .footer-grid       { grid-template-columns: 1fr; }
  .services, .gallery, .testimonials { padding: 60px 20px; }
}
@media (max-width: 420px)  { .gallery-grid { columns: 1; } }
  </style>
</head>
<body>

<!-- ════════════════ NAVBAR ════════════════ -->
<nav id="navbar">
  <div class="nav-logo">
    <div class="nav-logo-circle">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
    </div>
    <div class="nav-logo-text">
      <span>Blue</span>
      <span>THERAPY</span>
    </div>
  </div>

  <div class="nav-links">
    <a href="#inicio">Inicio</a>
    <a href="#servicios">Servicios</a>
    <a href="#agendar">Agendar Cita</a>
    <a href="#contacto">Contacto</a>
  </div>

  <div class="nav-right">
    <div class="nav-phone">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.1 1.18 2 2 0 012.12 0h3a2 2 0 012 1.72 12.7 12.7 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.7 12.7 0 002.81.7A2 2 0 0122 16.92z"/></svg>
      +57 300 123 4567
    </div>

    <!-- Botón acceso administrativo -->
    <a href="/Blue/login.php" class="nav-admin-btn" title="Acceso administrativo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0110 0v4"/>
      </svg>
    </a>

    <button class="btn-reservar" onclick="window.location.href='/Blue/booking.php'">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Reservar Ahora
    </button>
  </div>
</nav>


<!-- ════════════════ HERO ════════════════ -->
<section class="hero" id="inicio">
  <div class="hero-inner">
    <div class="hero-badge">Tu momento de bienestar te espera</div>
    <h1>Reserva tu momento<br>de <span class="accent">bienestar</span></h1>
    <p>Descubre una experiencia única de relajación y belleza. Tratamientos personalizados con la más alta tecnología para realzar tu belleza natural.</p>
    <div class="hero-buttons">
      <button class="btn-primary" onclick="window.location.href='/Blue/booking.php'">
        Agendar Cita
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </button>
      <button class="btn-outline" onclick="document.getElementById('servicios').scrollIntoView({behavior:'smooth'})">
        Ver Servicios
      </button>
    </div>
  </div>
</section>


<!-- ════════════════ STATS ════════════════ -->
<section class="stats">
  <div class="stat">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
    </div>
    <div class="stat-num">2,500<span style="font-size:22px">+</span></div>
    <div class="stat-label">Clientes Felices</div>
  </div>
  <div class="stat">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div class="stat-num">4.9</div>
    <div class="stat-label">Calificación</div>
  </div>
  <div class="stat">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
    </div>
    <div class="stat-num">15<span style="font-size:22px">+</span></div>
    <div class="stat-label">Años de Experiencia</div>
  </div>
  <div class="stat">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="stat-num">5,000<span style="font-size:22px">+</span></div>
    <div class="stat-label">Tratamientos Realizados</div>
  </div>
</section>


<!-- ════════════════ SERVICIOS ════════════════ -->
<section class="services" id="servicios">
  <div class="section-badge">Nuestros Servicios</div>
  <h2 class="section-title">Tratamientos para tu <span class="accent">bienestar</span></h2>
  <p class="section-sub">Explora nuestra gama de servicios diseñados para rejuvenecer tu cuerpo y mente.</p>

  <?php if ($categorias): ?>
    <div class="services-grid">
      <?php foreach ($categorias as $cat): ?>
        <div class="service-card">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 3l1.9 4.6 4.6 1.9-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z"/><path d="M18 15l.8 1.9 1.9.8-1.9.8L18 21l-.8-1.5-1.9-.8 1.9-.8L18 15z"/></svg>
          </div>
          <div class="service-name"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="service-count"><?= (int)$cat['n'] ?> servicio<?= (int)$cat['n'] === 1 ? '' : 's' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($destacados): ?>
    <div class="featured-head">
      <h3>Los más pedidos</h3>
      <p>Nuestros tratamientos destacados</p>
    </div>

    <div class="featured-grid">
      <?php foreach ($destacados as $s): ?>
        <article class="featured-card">
          <div class="featured-img">
            <?php if (!empty($s['image'])): ?>
              <img src="/Blue/assets/img/servicios/<?= rawurlencode(basename($s['image'])) ?>" alt="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
            <?php endif; ?>
            <span class="featured-tag">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.1 6.6.9-4.8 4.7 1.2 6.7L12 17.2 6.1 20.4l1.2-6.7L2.5 9l6.6-.9z"/></svg>
              Destacado
            </span>
          </div>
          <div class="featured-body">
            <div class="featured-cat"><?= htmlspecialchars($s['category_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <h4><?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?></h4>
            <?php if (!empty($s['description'])): ?>
              <p><?= htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <div class="featured-foot">
              <span class="featured-price"><?= formatPrice((float)$s['price']) ?></span>
              <span class="featured-dur"><?= (int)$s['duration_min'] ?> min</span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <a href="/Blue/booking.php" class="btn-link">
    Ver Todos los Servicios
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
  </a>
</section>


<!-- ════════════════ CTA ════════════════ -->
<section class="cta-banner" id="agendar">
  <h2>¿Lista para tu transformación?</h2>
  <p>Agenda tu cita hoy y comienza tu viaje hacia el bienestar. Nuestro equipo de expertos está listo para atenderte.</p>
  <button class="btn-dark" onclick="window.location.href='/Blue/booking.php'">
    Reservar Ahora
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
  </button>
</section>


<!-- ════════════════ TESTIMONIOS ════════════════ -->
<section class="testimonials">
  <div class="section-badge">Testimonios</div>
  <h2 class="section-title">Lo que dicen<br>nuestras <span class="accent">clientas</span></h2>
  <div class="testimonials-grid">
    <div class="testi-card">
      <div class="testi-stars">★★★★★</div>
      <p class="testi-text">Increíble experiencia. El tratamiento facial dejó mi piel radiante. El equipo es muy profesional y el ambiente es súper relajante. ¡Volvería mil veces!</p>
      <div class="testi-author">
        <div class="testi-avatar">M</div>
        <div>
          <div class="testi-name">María González</div>
          <div class="testi-role">Cliente frecuente</div>
        </div>
      </div>
    </div>
    <div class="testi-card">
      <div class="testi-stars">★★★★★</div>
      <p class="testi-text">Me encantó la depilación láser. Sin dolor y resultados visibles desde la primera sesión. El personal muy atento y el lugar impecable.</p>
      <div class="testi-author">
        <div class="testi-avatar">L</div>
        <div>
          <div class="testi-name">Laura Pérez</div>
          <div class="testi-role">Clienta nueva</div>
        </div>
      </div>
    </div>
    <div class="testi-card">
      <div class="testi-stars">★★★★★</div>
      <p class="testi-text">El spa day fue una experiencia transformadora. Salí renovada y relajada. Definitivamente el mejor centro de bienestar de la ciudad.</p>
      <div class="testi-author">
        <div class="testi-avatar">A</div>
        <div>
          <div class="testi-name">Ana Zuluaga</div>
          <div class="testi-role">Clienta VIP</div>
        </div>
      </div>
    </div>
  </div>
</section>


<!-- ════════════════ GALERÍA ════════════════ -->
<section class="gallery" id="galeria">
  <div class="gallery-header">
    <div class="section-badge">Galería</div>
    <h2 class="section-title">Momentos de <span class="accent">bienestar</span></h2>
    <p class="section-sub">Nuestros espacios y resultados hablan por sí solos.</p>
  </div>

  <div class="gallery-grid" id="galleryGrid">

    <?php if (!empty($gallery_images)): ?>

      <?php foreach ($gallery_images as $img): ?>
        <div class="gallery-item" onclick="openLightbox('assets/img/gallery/<?= htmlspecialchars(rawurlencode($img)) ?>')">
          <img src="assets/img/gallery/<?= htmlspecialchars($img) ?>" alt="Blue Therapy" loading="lazy">
          <div class="overlay">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
          </div>
        </div>
      <?php endforeach; ?>

    <?php else: ?>

      <?php foreach ($placeholders as $p): ?>
        <div class="gallery-item--placeholder" style="height:<?= $p['h'] ?>px;background:<?= $p['bg'] ?>;border-radius:16px"></div>
      <?php endforeach; ?>

    <?php endif; ?>

  </div>
</section>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()">✕</button>
  <img id="lightboxImg" src="" alt="Foto ampliada" onclick="event.stopPropagation()">
</div>


<!-- ════════════════ FOOTER ════════════════ -->
<footer id="contacto">
  <div class="footer-grid">
    <div class="footer-brand">
      <div class="nav-logo" style="margin-bottom:16px">
        <div class="nav-logo-circle">
          <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"/></svg>
        </div>
        <div class="nav-logo-text">
          <span>Blue</span>
          <span>THERAPY</span>
        </div>
      </div>
      <p>Centro especializado en tratamientos estéticos y bienestar. Tu transformación empieza aquí.</p>
    </div>
    <div class="footer-col">
      <h4>Enlaces Rápidos</h4>
      <ul>
        <li><a href="#inicio">Inicio</a></li>
        <li><a href="#servicios">Servicios</a></li>
        <li><a href="#agendar">Agendar Cita</a></li>
        <li><a href="#galeria">Galería</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Servicios</h4>
      <ul>
        <li><a href="#servicios">Tratamientos Faciales</a></li>
        <li><a href="#servicios">Tratamiento Spa</a></li>
        <li><a href="#servicios">Depilación Láser</a></li>
        <li><a href="#servicios">Terapias Biológicas</a></li>
      </ul>
    </div>
    <div class="footer-col footer-contact">
      <h4>Contacto</h4>
      <p>
        📍 Calle Principal #123<br>
        Centro Comercial Blue<br><br>
        📞 +57 300 123 4567<br><br>
        ✉️ info@bluetherapy.com
      </p>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© <?= date('Y') ?> Blue Therapy. Todos los derechos reservados.</span>
    <a href="/Blue/login.php" class="footer-admin-link">Panel administrativo</a>
  </div>
</footer>


<script>
// Sombra navbar al hacer scroll
window.addEventListener('scroll', () => {
    const nav = document.getElementById('navbar');
    nav.style.boxShadow = window.scrollY > 20
        ? '0 4px 20px rgba(0,0,0,0.08)'
        : 'none';
}, { passive: true });

// Lightbox
const lightbox    = document.getElementById('lightbox');
const lightboxImg = document.getElementById('lightboxImg');

function openLightbox(src) {
    lightboxImg.src = src;
    lightbox.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    lightbox.classList.remove('open');
    document.body.style.overflow = '';
    lightboxImg.src = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLightbox();
});
</script>
</body>
</html>
