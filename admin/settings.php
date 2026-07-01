<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

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
