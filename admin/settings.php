<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-pagos.php';
require_once __DIR__ . '/../includes/h-correo.php';

// Cabeceras de seguridad — página de credenciales/usuarios del panel.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

requireRole('admin', '/Blue/login.php');
$db   = getDB();
$me   = currentUser();

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/admin/settings.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'profile':
                $name  = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { setFlash('error', 'Nombre y correo válido son obligatorios.'); break; }
                $db->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")
                   ->execute([$name, $email, $phone ?: null, $me['id']]);
                $_SESSION['user']['name']  = $name;
                $_SESSION['user']['email'] = $email;
                setFlash('success', 'Perfil actualizado.');
                break;

            case 'password':
                $cur = $_POST['current'] ?? '';
                $new = $_POST['new'] ?? '';
                $rep = $_POST['repeat'] ?? '';
                if (strlen($new) < 6) { setFlash('error', 'La nueva contraseña debe tener al menos 6 caracteres.'); break; }
                if ($new !== $rep) { setFlash('error', 'Las contraseñas no coinciden.'); break; }
                $row = $db->prepare("SELECT password FROM users WHERE id=?");
                $row->execute([$me['id']]);
                if (!password_verify($cur, $row->fetchColumn())) { setFlash('error', 'La contraseña actual es incorrecta.'); break; }
                $db->prepare("UPDATE users SET password=? WHERE id=?")
                   ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                setFlash('success', 'Contraseña actualizada.');
                break;

            case 'staff_save':
                $id    = (int)($_POST['id'] ?? 0);
                $name  = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $role  = in_array($_POST['role'] ?? '', ['admin','staff'], true) ? $_POST['role'] : 'staff';
                $pass  = $_POST['password'] ?? '';
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { setFlash('error', 'Nombre y correo válido son obligatorios.'); break; }

                if ($id > 0) {
                    if ($pass !== '') {
                        $db->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, password=? WHERE id=?")
                           ->execute([$name, $email, $phone ?: null, $role, password_hash($pass, PASSWORD_DEFAULT), $id]);
                    } else {
                        $db->prepare("UPDATE users SET name=?, email=?, phone=?, role=? WHERE id=?")
                           ->execute([$name, $email, $phone ?: null, $role, $id]);
                    }
                    setFlash('success', 'Usuario actualizado.');
                } else {
                    if (strlen($pass) < 6) { setFlash('error', 'La contraseña debe tener al menos 6 caracteres.'); break; }
                    $db->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?,?,?,?,?)")
                       ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $phone ?: null]);
                    setFlash('success', 'Usuario creado.');
                }
                break;

            case 'staff_toggle':
                $id = (int)$_POST['id'];
                if ($id === (int)$me['id']) { setFlash('error', 'No puedes desactivar tu propia cuenta.'); break; }
                $db->prepare("UPDATE users SET active = 1 - active WHERE id=?")->execute([$id]);
                setFlash('info', 'Estado del usuario actualizado.');
                break;

            case 'probar_wompi':
                // Revisa llaves, URL y conexión con Wompi sin cobrar nada. El resultado
                // se guarda en la sesión para mostrarlo después del redirect (PRG).
                $diag = diagnosticoPasarelaWompi($db);
                $_SESSION['diagnostico_wompi'] = $diag;
                if ($diag['listo']) {
                    setFlash('success', 'Conexión verificada: la pasarela está lista para cobrar'
                        . ($diag['avisos'] ? ' (revisa los avisos).' : '.'));
                } else {
                    setFlash('error', 'La pasarela todavía no puede cobrar: hay ' . $diag['errores']
                        . ($diag['errores'] === 1 ? ' punto' : ' puntos') . ' por corregir.');
                }
                break;

            case 'probar_correo':
                // Se envía a la cuenta del admin que está probando, no a un cliente real.
                $asunto = 'Correo de prueba — Blue Therapy';
                $html   = plantillaCorreo('Correo de prueba', '<h1 style="font-family:Georgia,serif;'
                    . 'font-size:22px;margin:0 0 10px;color:#1a1a1a">Este es un correo de prueba</h1>'
                    . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555">Si lo estás leyendo en tu bandeja de '
                    . 'entrada, la configuración de correo de Blue Therapy quedó lista para avisar a los clientes.</p>'
                    . '<p style="margin:16px 0 0;font-size:12.5px;color:#999">Enviado a ' . e($me['email']) . ' el '
                    . e(date('d/m/Y \\a \\l\\a\\s g:i A')) . '.</p>');
                $enviado = enviarCorreo($me['email'], $me['name'], $asunto, $html);

                if (correoEnModoPrueba()) {
                    setFlash('info', 'Modo de prueba: el correo no se envió de verdad, quedó guardado en logs/correos/.');
                } elseif ($enviado) {
                    setFlash('success', 'Correo de prueba enviado a ' . $me['email'] . '. Revisa tu bandeja (y spam).');
                } else {
                    setFlash('error', 'No se pudo enviar el correo de prueba. Revisa la configuración en config/mail.local.php.');
                }
                break;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') setFlash('error', 'Ese correo ya está registrado.');
        else setFlash('error', 'Ocurrió un error al guardar.');
    }
    header('Location: /Blue/admin/settings.php?tab=' . ($_POST['tab'] ?? 'profile')); exit;
}

// ── Datos ─────────────────────────────────────────────────
$tab   = $_GET['tab'] ?? 'profile';
$staff = $db->query("SELECT * FROM users ORDER BY role, name")->fetchAll();

// Datos completos del perfil actual
$prof = $db->prepare("SELECT * FROM users WHERE id=?");
$prof->execute([$me['id']]);
$profile = $prof->fetch();

// ── Pagos en línea ────────────────────────────────────────
// La configuración vive en config/wompi.php (archivo, no base de datos):
// aquí solo se muestra el estado y los últimos movimientos de la pasarela.
$pagosUltimos = [];
$pagosError   = null;
$pagosResumen = ['aprobados' => 0, 'total' => 0.0, 'pendientes' => 0];

// Última verificación de la cuenta; queda "vieja" si la configuración cambió después.
$diagnostico      = $_SESSION['diagnostico_wompi'] ?? null;
$diagnosticoViejo = $diagnostico && ($diagnostico['huella'] ?? '') !== huellaConfigPagos();

if ($tab === 'payments') {
    try {
        $pagosUltimos = $db->query(
            "SELECT p.*, a.date AS cita_fecha, a.time_start AS cita_hora, a.status AS cita_estado,
                    c.name AS cliente
               FROM payments p
               JOIN appointments a ON a.id = p.appointment_id
               JOIN clients      c ON c.id = a.client_id
              ORDER BY p.id DESC LIMIT 20"
        )->fetchAll();

        $pagosResumen = $db->query(
            "SELECT COUNT(CASE WHEN status = 'approved' THEN 1 END)         AS aprobados,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN amount END), 0) AS total,
                    COUNT(CASE WHEN status = 'pending'  THEN 1 END)         AS pendientes
               FROM payments"
        )->fetch();
    } catch (PDOException $e) {
        // Lo más probable: falta correr database/migrations/02_finanzas.sql.
        $pagosError = 'No se pudo leer la tabla de pagos. ¿Ya ejecutaste la migración 02_finanzas.sql?';
    }
}

$pageTitle  = 'Configuración';
$activePage = 'settings';
$extraCss   = ['/Blue/assets/css/m-finanzas.css'];
require_once __DIR__ . '/../includes/admin_layout.php';

// ── Categorías de configuración (agregar aquí para escalar el módulo) ──
$sections = [
    'profile' => [
        'label'    => 'Mi cuenta',
        'desc'     => 'Nombre, correo y teléfono',
        'icon'     => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'critical' => false,
    ],
    'security' => [
        'label'    => 'Seguridad',
        'desc'     => 'Contraseña de acceso',
        'icon'     => '<rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>',
        'critical' => true,
    ],
    'team' => [
        'label'    => 'Equipo',
        'desc'     => 'Usuarios y roles del panel',
        'icon'     => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        'critical' => false,
    ],
    'payments' => [
        'label'    => 'Pagos en línea',
        'desc'     => 'Pasarela Wompi y abonos',
        'icon'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'critical' => true,
    ],
    'mail' => [
        'label'    => 'Correo',
        'desc'     => 'Avisos al cliente por email',
        'icon'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/>',
        'critical' => false,
    ],
];
if (!isset($sections[$tab])) { $tab = 'profile'; }
?>

<div class="page-head">
  <div>
    <h2>Configuración</h2>
    <p>Gestiona tu cuenta, la seguridad del acceso y el equipo del panel</p>
  </div>
</div>

<div class="settings-shell">

  <!-- ══════ NAV DE CATEGORÍAS ══════ -->
  <nav class="settings-nav" aria-label="Categorías de configuración">
    <div class="settings-search-wrap">
      <input type="search" class="settings-search" id="settingsSearch"
             placeholder="Buscar configuración…" aria-label="Buscar configuración">
    </div>
    <?php foreach ($sections as $key => $s): ?>
      <a href="?tab=<?= $key ?>"
         class="settings-nav-item <?= $tab === $key ? 'active' : '' ?>"
         data-search="<?= e(mb_strtolower($s['label'] . ' ' . $s['desc'])) ?>">
        <span class="settings-nav-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $s['icon'] ?></svg>
        </span>
        <span class="settings-nav-text">
          <span class="settings-nav-label">
            <?= e($s['label']) ?>
            <?php if ($s['critical']): ?><span class="settings-nav-critical-dot" title="Configuración sensible"></span><?php endif; ?>
          </span>
          <span class="settings-nav-desc"><?= e($s['desc']) ?></span>
        </span>
      </a>
    <?php endforeach; ?>
    <p class="settings-nav-empty" id="settingsNavEmpty" hidden>Sin resultados</p>
  </nav>

  <!-- ══════ CONTENIDO ══════ -->
  <div class="settings-content">

<!-- ══════ MI CUENTA ══════ -->
<?php if ($tab === 'profile'): ?>
  <div class="settings-panel-head">
    <div>
      <h3>Mi cuenta</h3>
      <p>Esta información aparece en el panel y en las citas que gestionas</p>
    </div>
    <span class="settings-unsaved-pill" id="unsaved-profile">Cambios sin guardar</span>
  </div>
  <div class="card"><div class="card-body">
    <div class="profile-header">
      <div class="profile-avatar-lg"><?= mb_strtoupper(mb_substr($profile['name'], 0, 1)) ?></div>
      <div class="profile-header-info">
        <div class="profile-name"><?= e($profile['name']) ?></div>
        <div class="profile-email"><?= e($profile['email']) ?></div>
      </div>
    </div>
    <form method="POST" class="js-settings-form" data-pill="unsaved-profile" style="max-width:520px">
      <div class="form-grid">
        <div class="form-field full">
          <label for="prof_name">Nombre</label>
          <input type="text" id="prof_name" name="name" class="form-control" value="<?= e($profile['name']) ?>" required>
          <span class="form-hint">Como aparecerá en el panel y en las citas asignadas.</span>
        </div>
        <div class="form-field">
          <label for="prof_email">Correo</label>
          <input type="email" id="prof_email" name="email" class="form-control" value="<?= e($profile['email']) ?>" required>
          <span class="form-hint">Se usa para iniciar sesión.</span>
          <span class="field-feedback error" id="prof_email_err" hidden>Ingresa un correo válido.</span>
        </div>
        <div class="form-field">
          <label for="prof_phone">Teléfono</label>
          <input type="tel" id="prof_phone" name="phone" class="form-control" value="<?= e($profile['phone'] ?? '') ?>">
          <span class="form-hint">Opcional, para contacto por WhatsApp.</span>
        </div>
        <div class="form-field full">
          <span class="form-hint" style="display:block;margin-bottom:6px">Rol asignado</span>
          <span class="pill settings-role-pill"><?= e(ucfirst($profile['role'])) ?></span>
        </div>
      </div>
      <input type="hidden" name="action" value="profile">
      <input type="hidden" name="tab" value="profile">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <button type="submit" class="btn btn-primary" style="margin-top:18px">Guardar cambios</button>
    </form>
  </div></div>
<?php endif; ?>

<!-- ══════ SEGURIDAD ══════ -->
<?php if ($tab === 'security'): ?>
  <div class="settings-panel-head">
    <div>
      <h3>Seguridad</h3>
      <p>Actualiza la contraseña con la que accedes al panel</p>
    </div>
    <span class="settings-unsaved-pill" id="unsaved-security">Cambios sin guardar</span>
  </div>
  <div class="card"><div class="card-body">
    <form method="POST" class="js-settings-form" data-pill="unsaved-security" id="securityForm" style="max-width:440px">
      <div class="form-field full" style="margin-bottom:14px">
        <label for="cur_password">Contraseña actual</label>
        <input type="password" id="cur_password" name="current" class="form-control" required>
      </div>
      <div class="form-field full" style="margin-bottom:14px">
        <label for="new-password">Nueva contraseña</label>
        <input type="password" name="new" id="new-password" class="form-control" minlength="6" required>
        <span class="form-hint">Mínimo 6 caracteres; combina letras, números y símbolos.</span>
        <div class="strength-wrap">
          <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
          <span class="strength-label" id="strength-label"></span>
        </div>
      </div>
      <div class="form-field full">
        <label for="rep_password">Repetir nueva contraseña</label>
        <input type="password" id="rep_password" name="repeat" class="form-control" minlength="6" required>
        <span class="field-feedback" id="rep_password_fb"></span>
      </div>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="tab" value="security">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <button type="submit" class="btn btn-primary" style="margin-top:18px">Actualizar contraseña</button>
    </form>
  </div></div>
<?php endif; ?>

<!-- ══════ EQUIPO ══════ -->
<?php if ($tab === 'team'): ?>
  <div class="settings-panel-head">
    <div>
      <h3>Equipo</h3>
      <p>Usuarios con acceso al panel y sus roles</p>
    </div>
    <button class="btn btn-primary" onclick="newStaff()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nuevo usuario
    </button>
  </div>
  <div class="card"><div class="card-body--flush">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $u): ?>
          <tr style="<?= $u['active'] ? '' : 'opacity:.55' ?>">
            <td>
              <div class="client-cell">
                <div class="client-avatar"><?= mb_substr($u['name'], 0, 1) ?></div>
                <div>
                  <div class="client-name"><?= e($u['name']) ?><?= $u['id']==$me['id']?' <span class="pill pill-muted">tú</span>':'' ?></div>
                  <?php if ($u['phone']): ?><div class="client-phone"><?= e($u['phone']) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= e($u['email']) ?></td>
            <td><span class="pill"><?= $u['role']==='admin'?'Administrador':'Staff' ?></span></td>
            <td><?= $u['active'] ? '<span class="badge badge-confirmed">Activo</span>' : '<span class="badge badge-cancelled">Inactivo</span>' ?></td>
            <td>
              <div class="action-btns">
                <button class="btn-action" onclick='editStaff(<?= json_encode(["id"=>$u["id"],"name"=>$u["name"],"email"=>$u["email"],"phone"=>$u["phone"],"role"=>$u["role"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button>
                <?php if ($u['id'] != $me['id']): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="staff_toggle">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="tab" value="team">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <button class="btn-action"><?= $u['active'] ? 'Desactivar' : 'Activar' ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>

  <!-- Modal staff -->
  <div class="modal-overlay" id="staffModal">
    <div class="modal" style="max-width:480px">
      <form method="POST">
        <div class="modal-header">
          <span class="modal-title" id="staffTitle">Nuevo usuario</span>
          <button type="button" class="modal-close" onclick="closeModal('staffModal')">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-field full"><label>Nombre</label><input type="text" name="name" id="st_name" class="form-control" required></div>
            <div class="form-field"><label>Correo</label><input type="email" name="email" id="st_email" class="form-control" required></div>
            <div class="form-field"><label>Teléfono</label><input type="tel" name="phone" id="st_phone" class="form-control"></div>
            <div class="form-field"><label>Rol</label>
              <select name="role" id="st_role" class="form-control"><option value="staff">Staff</option><option value="admin">Administrador</option></select>
            </div>
            <div class="form-field"><label>Contraseña</label><input type="password" name="password" id="st_pass" class="form-control"><div class="form-hint" id="st_passHint">Mínimo 6 caracteres.</div></div>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="staff_save">
          <input type="hidden" name="id" id="st_id" value="0">
          <input type="hidden" name="tab" value="team">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <button type="button" class="btn btn-ghost" onclick="closeModal('staffModal')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  function openModal(id){ document.getElementById(id).classList.add('open'); }
  function closeModal(id){ document.getElementById(id).classList.remove('open'); }
  function newStaff(){
    document.getElementById('staffTitle').textContent = 'Nuevo usuario';
    document.getElementById('st_id').value = 0;
    ['st_name','st_email','st_phone','st_pass'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('st_role').value = 'staff';
    document.getElementById('st_pass').required = true;
    document.getElementById('st_passHint').textContent = 'Mínimo 6 caracteres.';
    openModal('staffModal');
  }
  function editStaff(u){
    document.getElementById('staffTitle').textContent = 'Editar usuario';
    document.getElementById('st_id').value = u.id;
    document.getElementById('st_name').value = u.name;
    document.getElementById('st_email').value = u.email;
    document.getElementById('st_phone').value = u.phone || '';
    document.getElementById('st_role').value = u.role;
    document.getElementById('st_pass').value = '';
    document.getElementById('st_pass').required = false;
    document.getElementById('st_passHint').textContent = 'Déjalo en blanco para no cambiarla.';
    openModal('staffModal');
  }
  document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
  });
  </script>
<?php endif; ?>

<!-- ══════ PAGOS EN LÍNEA ══════ -->
<?php if ($tab === 'payments'): ?>
  <?php
  $pagoCfg    = configPagos();
  $pagoActivo = pagosEnLineaActivos();
  $llavePub   = (string)($pagoCfg['llave_publica'] ?? '');
  $enmascarar = fn(string $v) => $v === '' ? '' : substr($v, 0, 12) . '····' . substr($v, -4);
  $urlEventos = urlBaseSitio() . '/api/wompi_webhook.php';
  $urlRetorno = urlBaseSitio() . '/pago_resultado.php';
  $abonoCfg   = $pagoCfg['abono'] ?? [];
  ?>
  <div class="settings-panel-head">
    <div>
      <h3>Pagos en línea</h3>
      <p>Wompi cobra un abono al reservar y la cita se confirma sola cuando el pago se aprueba</p>
    </div>
    <?php
      [$pillClase, $pillTexto] = !$pagoActivo       ? ['is-off',  'Sin configurar']
                               : (pagosEnModoDemo() ? ['is-demo', 'Modo demostración']
                                                    : ['is-on',   'Pasarela activa']);
    ?>
    <span class="pago-estado-pill <?= $pillClase ?>"><?= $pillTexto ?></span>
  </div>

  <?php // .flash es flex: el texto va en un solo <span> para que no se parta en columnas. ?>
  <?php if (!$pagoActivo): ?>
    <div class="flash flash-info" style="margin-bottom:18px">
      <span>Mientras no haya llaves cargadas, el agendamiento sigue funcionando como antes:
      el cliente envía una solicitud y el equipo la confirma por WhatsApp.</span>
    </div>
  <?php elseif (pagosEnModoDemo()): ?>
    <div class="flash flash-error" style="margin-bottom:18px">
      <span><strong>Modo demostración.</strong> El paso de pago se ve en el sitio, pero no se puede cobrar:
      reemplaza las llaves de relleno de <code>config/wompi.local.php</code> por las de tu cuenta de Wompi.</span>
    </div>
  <?php elseif (!pagosEnProduccion()): ?>
    <div class="flash flash-info" style="margin-bottom:18px">
      <span>Estás en <strong>ambiente de pruebas</strong>: los pagos no mueven dinero real.
      Para salir a producción, carga las llaves <code>pub_prod_…</code> en <code>config/wompi.local.php</code>.</span>
    </div>
  <?php endif; ?>

  <!-- ══ Verificación: ¿lista para cobrar? ══ -->
  <div class="card diag-card"><div class="card-body">
    <div class="diag-head">
      <div>
        <h4 class="pago-bloque-titulo">Verificación de la cuenta</h4>
        <p class="diag-intro">Revisa las llaves, la URL del sitio y la conexión real con Wompi. No cobra nada.</p>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="probar_wompi">
        <input type="hidden" name="tab" value="payments">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="submit" class="btn btn-primary diag-boton">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
          Probar conexión con Wompi
        </button>
      </form>
    </div>

    <?php if (!$diagnostico): ?>
      <ol class="diag-pasos">
        <li><strong>Crea la cuenta</strong> en comercios.wompi.co — el ambiente de pruebas es gratis.</li>
        <li><strong>Copia las 4 llaves de pruebas</strong> (Desarrolladores → Llaves de API) en <code>config/wompi.local.php</code>.</li>
        <li><strong>Pega la URL de eventos</strong> que aparece abajo en Desarrolladores → URL de eventos.</li>
        <li><strong>Pulsa «Probar conexión»</strong> y corrige lo que salga en rojo.</li>
        <li><strong>Haz un pago de prueba</strong> desde el sitio con una tarjeta de sandbox de Wompi.</li>
      </ol>
    <?php else:
      $gruposDiag = [];
      foreach ($diagnostico['checks'] as $ck) $gruposDiag[$ck['grupo']][] = $ck;
      $iconosDiag = ['ok' => '✓', 'aviso' => '!', 'error' => '✕', 'omitido' => '–'];
    ?>
      <div class="diag-resumen diag-resumen--<?= $diagnostico['listo'] ? 'ok' : 'error' ?>">
        <span class="diag-resumen-icono"><?= $diagnostico['listo'] ? '✓' : '!' ?></span>
        <div>
          <strong><?= $diagnostico['listo'] ? 'Lista para cobrar' : 'Todavía no puede cobrar' ?></strong>
          <span>
            <?= (int)$diagnostico['errores'] ?> por corregir · <?= (int)$diagnostico['avisos'] ?> <?= $diagnostico['avisos'] === 1 ? 'aviso' : 'avisos' ?>
            · probado el <?= e(date('d/m/Y \a \l\a\s g:i A', strtotime($diagnostico['fecha']))) ?>
          </span>
        </div>
      </div>

      <?php if ($diagnosticoViejo): ?>
        <p class="diag-viejo">La configuración cambió desde esta prueba: vuelve a pulsar «Probar conexión».</p>
      <?php endif; ?>

      <?php foreach ($gruposDiag as $grupo => $items): ?>
        <div class="diag-grupo">
          <div class="diag-grupo-titulo"><?= e($grupo) ?></div>
          <ul class="diag-lista">
            <?php foreach ($items as $ck): ?>
              <li class="diag-item diag-item--<?= e($ck['estado']) ?>">
                <span class="diag-icono" aria-hidden="true"><?= $iconosDiag[$ck['estado']] ?? '·' ?></span>
                <span class="diag-texto"><strong><?= e($ck['nombre']) ?></strong><?= e($ck['detalle']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div></div>

  <div class="card"><div class="card-body">
    <h4 class="pago-bloque-titulo">Conexión</h4>
    <div class="pago-config-grid">
      <div class="pago-config-item">
        <span class="pci-label">Ambiente</span>
        <span class="pci-value"><?= pagosEnProduccion() ? 'Producción (dinero real)' : 'Pruebas (sandbox)' ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Llave pública</span>
        <span class="pci-value pci-mono"><?= $llavePub ? e($enmascarar($llavePub)) : '— sin cargar —' ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Llave privada</span>
        <span class="pci-value"><?= !empty($pagoCfg['llave_privada']) ? 'Cargada' : '— sin cargar —' ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Secreto de integridad</span>
        <span class="pci-value"><?= !empty($pagoCfg['secreto_integridad']) ? 'Cargado' : '— sin cargar —' ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Secreto de eventos</span>
        <span class="pci-value"><?= !empty($pagoCfg['secreto_eventos']) ? 'Cargado' : '— sin cargar (el webhook no funcionará) —' ?></span>
      </div>
      <div class="pago-config-item full">
        <span class="pci-label">URL de eventos — pégala en Wompi (Desarrolladores → URL de eventos)</span>
        <span class="pci-copiable">
          <span class="pci-value pci-mono" id="urlEventosWompi"><?= e($urlEventos) ?></span>
          <button type="button" class="pci-copiar" data-copiar="urlEventosWompi">Copiar</button>
        </span>
      </div>
      <div class="pago-config-item full">
        <span class="pci-label">URL de redirección tras el pago</span>
        <span class="pci-value pci-mono"><?= e($urlRetorno) ?></span>
      </div>
    </div>

    <h4 class="pago-bloque-titulo" style="margin-top:26px">Regla del abono</h4>
    <div class="pago-config-grid">
      <div class="pago-config-item">
        <span class="pci-label">Porcentaje del total</span>
        <span class="pci-value"><?= e((string)($abonoCfg['porcentaje'] ?? 30)) ?>%</span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Abono mínimo</span>
        <span class="pci-value"><?= e(formatPrice((float)($abonoCfg['minimo'] ?? 0))) ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Abono máximo</span>
        <span class="pci-value"><?= ((float)($abonoCfg['maximo'] ?? 0)) > 0 ? e(formatPrice((float)$abonoCfg['maximo'])) : 'Sin tope' ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Redondeo</span>
        <span class="pci-value"><?= ((float)($abonoCfg['redondear_a'] ?? 0)) > 0 ? 'Hacia arriba a ' . e(formatPrice((float)$abonoCfg['redondear_a'])) : 'Sin redondeo' ?></span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Tiempo para pagar</span>
        <span class="pci-value"><?= (int)($pagoCfg['minutos_reserva'] ?? 20) ?> minutos</span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Abono obligatorio</span>
        <span class="pci-value">
          <?php if (empty($pagoCfg['abono_obligatorio'])): ?>No — el pago es opcional
          <?php elseif (pagosEnModoDemo()): ?>En pausa — se exige cuando haya llaves reales
          <?php else: ?>Sí — no se agenda sin pagar<?php endif; ?>
        </span>
      </div>
    </div>

    <p class="form-hint" style="margin-top:18px">
      Estos valores se editan en <code>config/wompi.php</code> (o en <code>config/wompi.local.php</code>
      para las llaves de producción, que nunca se suben al repositorio).
    </p>
  </div></div>

  <!-- Últimos pagos -->
  <div class="settings-panel-head" style="margin-top:26px">
    <div>
      <h3>Últimos abonos</h3>
      <p>Cada abono aprobado confirma su cita y queda registrado como ingreso en Finanzas</p>
    </div>
  </div>

  <?php if ($pagosError): ?>
    <div class="flash flash-error"><?= e($pagosError) ?></div>
  <?php else: ?>
    <div class="summary-grid" style="margin-bottom:18px">
      <div class="summary-card income">
        <div class="label">Total recibido</div>
        <div class="value"><?= e(formatPrice((float)$pagosResumen['total'])) ?></div>
      </div>
      <div class="summary-card">
        <div class="label">Pagos aprobados</div>
        <div class="value"><?= (int)$pagosResumen['aprobados'] ?></div>
      </div>
      <div class="summary-card">
        <div class="label">En proceso</div>
        <div class="value"><?= (int)$pagosResumen['pendientes'] ?></div>
      </div>
    </div>

    <div class="card"><div class="card-body--flush">
      <?php if (empty($pagosUltimos)): ?>
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
          </div>
          <div class="empty-state-title">Todavía no hay pagos</div>
          <div class="empty-state-desc">Aquí aparecerán los abonos que hagan los clientes al reservar.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr><th>Referencia</th><th>Tipo</th><th>Cliente</th><th>Cita</th><th>Monto</th><th>Medio</th><th>Estado</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pagosUltimos as $pg): ?>
              <tr>
                <td style="font-size:12px;font-family:ui-monospace,monospace"><?= e($pg['reference']) ?></td>
                <td><span class="pill"><?= e(etiquetaTipoPago($pg['kind'])) ?></span></td>
                <td><?= e($pg['cliente']) ?></td>
                <td style="font-size:12px;color:var(--muted)">
                  <a class="fin-cita-link" href="/Blue/cita.php?id=<?= (int)$pg['appointment_id'] ?>">#<?= (int)$pg['appointment_id'] ?></a>
                  · <?= e(date('d M Y', strtotime($pg['cita_fecha']))) ?>
                  <?= e(formatTime($pg['cita_hora'])) ?>
                </td>
                <td style="font-weight:600"><?= e(formatPrice((float)$pg['amount'])) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= e($pg['payment_method'] ?: '—') ?></td>
                <td>
                  <span class="badge badge-<?= $pg['status'] === 'approved' ? 'confirmed' : ($pg['status'] === 'pending' ? 'pending' : 'cancelled') ?>">
                    <?= e(etiquetaEstadoPago($pg['status'])) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <script>
  // Copiar la URL de eventos para pegarla en el panel de Wompi.
  document.querySelectorAll('.pci-copiar').forEach(function (boton) {
    boton.addEventListener('click', async function () {
      const texto = document.getElementById(boton.dataset.copiar).textContent.trim();
      try { await navigator.clipboard.writeText(texto); } catch (e) { return; }
      const original = boton.textContent;
      boton.textContent = '¡Copiada!';
      boton.classList.add('is-copiado');
      setTimeout(function () { boton.textContent = original; boton.classList.remove('is-copiado'); }, 1800);
    });
  });
  </script>
<?php endif; ?>

<!-- ══════ CORREO ══════ -->
<?php if ($tab === 'mail'):
    $mailCfg       = configCorreo();
    $mailSmtp      = $mailCfg['smtp'] ?? [];
    $mailActivo    = correoActivo();
    $mailModoPrueba = correoEnModoPrueba();

    // Últimos correos guardados en modo de prueba (logs/correos/), para poder
    // revisar el contenido sin salir del panel ni tener una cuenta real todavía.
    $ultimosCorreos = [];
    $carpetaLogs = __DIR__ . '/../logs/correos';
    if ($mailModoPrueba && is_dir($carpetaLogs)) {
        $archivos = glob($carpetaLogs . '/*.txt') ?: [];
        usort($archivos, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($archivos, 0, 8) as $ruta) {
            $lineas = @file($ruta, FILE_IGNORE_NEW_LINES) ?: [];
            $ultimosCorreos[] = [
                'archivo' => basename($ruta),
                'para'    => preg_replace('/^Para:\s*/', '', $lineas[0] ?? ''),
                'asunto'  => preg_replace('/^Asunto:\s*/', '', $lineas[1] ?? ''),
                'fecha'   => date('d/m/Y g:i A', filemtime($ruta)),
            ];
        }
    }
?>
  <div class="settings-panel-head">
    <div>
      <h3>Correo</h3>
      <p>El cliente marca en el paso de datos si quiere que le avisemos por correo; desde aquí se ve y se prueba esa conexión</p>
    </div>
    <?php
      [$mailPillClase, $mailPillTexto] = !$mailActivo && !$mailModoPrueba ? ['is-off', 'Sin configurar']
                                       : ($mailModoPrueba               ? ['is-demo', 'Modo de prueba']
                                                                          : ['is-on', 'Activo']);
    ?>
    <span class="pago-estado-pill <?= $mailPillClase ?>"><?= $mailPillTexto ?></span>
  </div>

  <?php if ($mailModoPrueba): ?>
    <div class="flash flash-info" style="margin-bottom:18px">
      <span>En modo de prueba los correos no se envían de verdad: cada uno se guarda como archivo de texto en
      <code>logs/correos/</code> (se ven abajo). El checkbox de avisos tampoco aparece en el sitio público mientras
      esté así, para no prometerle al cliente algo que todavía no funciona.</span>
    </div>
  <?php endif; ?>

  <div class="card"><div class="card-body">
    <h4 class="pago-bloque-titulo">Conexión</h4>
    <div class="pago-config-grid">
      <div class="pago-config-item">
        <span class="pci-label">Método</span>
        <span class="pci-value">
          <?= ['log' => 'Modo de prueba (archivo)', 'smtp' => 'Servidor SMTP', 'mail' => 'mail() de PHP'][$mailCfg['metodo'] ?? 'log'] ?? e((string)($mailCfg['metodo'] ?? '')) ?>
        </span>
      </div>
      <div class="pago-config-item">
        <span class="pci-label">Remitente</span>
        <span class="pci-value"><?= e($mailCfg['remitente_nombre'] ?? '') ?> &lt;<?= e($mailCfg['remitente_email'] ?? '') ?>&gt;</span>
      </div>
      <?php if (($mailCfg['metodo'] ?? '') === 'smtp'): ?>
        <div class="pago-config-item">
          <span class="pci-label">Servidor</span>
          <span class="pci-value pci-mono"><?= !empty($mailSmtp['host']) ? e($mailSmtp['host']) . ':' . (int)($mailSmtp['puerto'] ?? 587) : '— sin cargar —' ?></span>
        </div>
        <div class="pago-config-item">
          <span class="pci-label">Seguridad</span>
          <span class="pci-value"><?= ['tls' => 'STARTTLS', 'ssl' => 'SSL directo', '' => 'Sin cifrar'][$mailSmtp['seguridad'] ?? 'tls'] ?? e((string)($mailSmtp['seguridad'] ?? '')) ?></span>
        </div>
        <div class="pago-config-item">
          <span class="pci-label">Usuario</span>
          <span class="pci-value pci-mono"><?= !empty($mailSmtp['usuario']) ? e($mailSmtp['usuario']) : '— sin cargar —' ?></span>
        </div>
        <div class="pago-config-item">
          <span class="pci-label">Contraseña</span>
          <span class="pci-value"><?= !empty($mailSmtp['clave']) ? 'Cargada' : '— sin cargar —' ?></span>
        </div>
      <?php endif; ?>
    </div>

    <p class="form-hint" style="margin-top:18px">
      Estos valores se editan en <code>config/mail.php</code> (o en <code>config/mail.local.php</code> para las
      credenciales reales, que nunca se suben al repositorio). Con Gmail se necesita una
      <em>contraseña de aplicación</em>, no la contraseña normal de la cuenta.
    </p>
  </div></div>

  <!-- Correo de prueba -->
  <div class="card diag-card"><div class="card-body">
    <div class="diag-head">
      <div>
        <h4 class="pago-bloque-titulo">Correo de prueba</h4>
        <p class="diag-intro">Se envía a tu propio correo (<?= e($me['email']) ?>) para comprobar que todo llega bien.</p>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="probar_correo">
        <input type="hidden" name="tab" value="mail">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button type="submit" class="btn btn-primary diag-boton">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
          Enviar correo de prueba
        </button>
      </form>
    </div>
  </div></div>

  <!-- Últimos correos guardados en modo de prueba -->
  <?php if ($mailModoPrueba): ?>
    <div class="settings-panel-head" style="margin-top:26px">
      <div>
        <h3>Últimos correos (modo de prueba)</h3>
        <p>Ninguno se envió de verdad; están guardados como texto en <code>logs/correos/</code></p>
      </div>
    </div>
    <div class="card"><div class="card-body--flush">
      <?php if (empty($ultimosCorreos)): ?>
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
          </div>
          <div class="empty-state-title">Todavía no hay ninguno</div>
          <div class="empty-state-desc">Aparecerán aquí cuando alguien reserve marcando "Avisos por correo", o al usar el botón de arriba.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Para</th><th>Asunto</th><th>Guardado</th></tr></thead>
            <tbody>
            <?php foreach ($ultimosCorreos as $c): ?>
              <tr>
                <td style="font-size:12.5px"><?= e($c['para']) ?></td>
                <td><?= e($c['asunto']) ?></td>
                <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= e($c['fecha']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>
<?php endif; ?>

  </div><!-- /.settings-content -->
</div><!-- /.settings-shell -->

<script>
// ── Fortaleza de contraseña ───────────────────────────────
(function () {
  const input  = document.getElementById('new-password');
  const fill   = document.getElementById('strength-fill');
  const label  = document.getElementById('strength-label');
  if (!input) return;

  const levels = [
    { pct:  0, color: '',         text: '' },
    { pct: 20, color: '#ef4444',  text: 'Muy débil'  },
    { pct: 40, color: '#f97316',  text: 'Débil'       },
    { pct: 60, color: '#f59e0b',  text: 'Regular'     },
    { pct: 80, color: '#10b981',  text: 'Buena'       },
    { pct:100, color: '#059669',  text: 'Excelente'   },
  ];

  input.addEventListener('input', function () {
    const v = this.value;
    let score = 0;
    if (v.length >= 6)           score++;
    if (v.length >= 10)          score++;
    if (/[A-Z]/.test(v))        score++;
    if (/[0-9]/.test(v))        score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const lvl = v.length === 0 ? levels[0] : levels[score] ?? levels[5];
    fill.style.width           = lvl.pct + '%';
    fill.style.backgroundColor = lvl.color;
    label.textContent          = lvl.text;
    label.style.color          = lvl.color;
  });
}());

// ── Buscador de categorías de configuración ───────────────
(function () {
  const input = document.getElementById('settingsSearch');
  const empty = document.getElementById('settingsNavEmpty');
  if (!input) return;
  const items = document.querySelectorAll('.settings-nav-item');
  input.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    items.forEach(el => {
      const match = !q || el.dataset.search.includes(q);
      el.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    empty.hidden = visible !== 0;
  });
}());

// ── Indicador de cambios sin guardar ──────────────────────
document.querySelectorAll('.js-settings-form').forEach(function (form) {
  const pill = document.getElementById(form.dataset.pill);
  if (!pill) return;
  form.addEventListener('input', () => pill.classList.add('show'));
  form.addEventListener('submit', () => pill.classList.remove('show'));
});

// ── Validación en vivo — correo de perfil ─────────────────
(function () {
  const email = document.getElementById('prof_email');
  const err   = document.getElementById('prof_email_err');
  if (!email) return;
  email.addEventListener('input', function () {
    const valid = email.checkValidity();
    email.classList.toggle('is-invalid', !valid && email.value !== '');
    err.hidden = valid || email.value === '';
  });
}());

// ── Validación en vivo — coincidencia de contraseñas ──────
(function () {
  const pass = document.getElementById('new-password');
  const rep  = document.getElementById('rep_password');
  const fb   = document.getElementById('rep_password_fb');
  const form = document.getElementById('securityForm');
  if (!pass || !rep) return;

  function check() {
    if (rep.value === '') {
      fb.textContent = ''; fb.className = 'field-feedback'; rep.classList.remove('is-invalid');
      return true;
    }
    const match = rep.value === pass.value;
    fb.textContent = match ? 'Coinciden' : 'Las contraseñas no coinciden';
    fb.className   = 'field-feedback ' + (match ? 'ok' : 'error');
    rep.classList.toggle('is-invalid', !match);
    return match;
  }
  rep.addEventListener('input', check);
  pass.addEventListener('input', check);
  form.addEventListener('submit', function (e) {
    if (!check()) e.preventDefault();
  });
}());
</script>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
