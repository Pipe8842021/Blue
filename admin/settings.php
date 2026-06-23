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
?>

<div class="page-head">
  <div>
    <h2>Configuración</h2>
    <p>Gestiona tu cuenta y el equipo</p>
  </div>
</div>

<div class="tabs">
  <button class="tab <?= $tab==='profile'?'active':'' ?>"  onclick="location='?tab=profile'">Mi perfil</button>
  <button class="tab <?= $tab==='security'?'active':'' ?>" onclick="location='?tab=security'">Seguridad</button>
  <button class="tab <?= $tab==='team'?'active':'' ?>"     onclick="location='?tab=team'">Equipo</button>
</div>

<!-- ══════ PERFIL ══════ -->
<?php if ($tab === 'profile'): ?>
  <div class="card"><div class="card-body">
    <div class="profile-header">
      <div class="profile-avatar-lg"><?= mb_strtoupper(mb_substr($profile['name'], 0, 1)) ?></div>
      <div class="profile-header-info">
        <div class="profile-name"><?= e($profile['name']) ?></div>
        <div class="profile-email"><?= e($profile['email']) ?></div>
      </div>
    </div>
    <form method="POST" style="max-width:520px">
      <div class="form-grid">
        <div class="form-field full"><label>Nombre</label><input type="text" name="name" class="form-control" value="<?= e($profile['name']) ?>" required></div>
        <div class="form-field"><label>Correo</label><input type="email" name="email" class="form-control" value="<?= e($profile['email']) ?>" required></div>
        <div class="form-field"><label>Teléfono</label><input type="tel" name="phone" class="form-control" value="<?= e($profile['phone'] ?? '') ?>"></div>
        <div class="form-field full"><label>Rol</label><input type="text" class="form-control" value="<?= e(ucfirst($profile['role'])) ?>" disabled></div>
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
  <div class="card"><div class="card-body">
    <form method="POST" style="max-width:440px">
      <div class="form-field full" style="margin-bottom:14px"><label>Contraseña actual</label><input type="password" name="current" class="form-control" required></div>
      <div class="form-field full" style="margin-bottom:14px">
        <label>Nueva contraseña</label>
        <input type="password" name="new" id="new-password" class="form-control" minlength="6" required>
        <div class="strength-wrap">
          <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
          <span class="strength-label" id="strength-label"></span>
        </div>
      </div>
      <div class="form-field full"><label>Repetir nueva contraseña</label><input type="password" name="repeat" class="form-control" minlength="6" required></div>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="tab" value="security">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <button type="submit" class="btn btn-primary" style="margin-top:18px">Actualizar contraseña</button>
    </form>
  </div></div>
<?php endif; ?>

<!-- ══════ EQUIPO ══════ -->
<?php if ($tab === 'team'): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
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
</script>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
