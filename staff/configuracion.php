<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin('/Blue/login.php');
$db   = getDB();
$me   = currentUser();
$myId = (int)$me['id'];

// ── Acciones (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: /Blue/staff/configuracion.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'profile') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                setFlash('error', 'Nombre y correo válido son obligatorios.');
            } else {
                $db->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")->execute([$name, $email, $phone ?: null, $myId]);
                $_SESSION['user']['name']  = $name;
                $_SESSION['user']['email'] = $email;
                setFlash('success', 'Perfil actualizado.');
            }
        } elseif ($action === 'password') {
            $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $rep = $_POST['repeat'] ?? '';
            if (strlen($new) < 6)       { setFlash('error', 'La nueva contraseña debe tener al menos 6 caracteres.'); }
            elseif ($new !== $rep)      { setFlash('error', 'Las contraseñas no coinciden.'); }
            else {
                $row = $db->prepare("SELECT password FROM users WHERE id=?"); $row->execute([$myId]);
                if (!password_verify($cur, $row->fetchColumn())) {
                    setFlash('error', 'La contraseña actual es incorrecta.');
                } else {
                    $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $myId]);
                    setFlash('success', 'Contraseña actualizada.');
                }
            }
        }
    } catch (PDOException $e) {
        setFlash('error', $e->getCode() === '23000' ? 'Ese correo ya está registrado.' : 'Ocurrió un error al guardar.');
    }
    header('Location: /Blue/staff/configuracion.php?tab=' . ($_POST['tab'] ?? 'perfil')); exit;
}

$tab = $_GET['tab'] ?? 'perfil';
$prof = $db->prepare("SELECT * FROM users WHERE id=?"); $prof->execute([$myId]); $profile = $prof->fetch();

$pageTitle  = 'Configuración';
$activePage = 'configuracion';
require_once __DIR__ . '/_layout.php';
?>

<div class="page-head"><div><h2>Configuración</h2><p>Gestiona tu cuenta</p></div></div>

<div class="tabs">
  <button class="tab <?= $tab==='perfil'?'active':'' ?>"     onclick="location='?tab=perfil'">Mi perfil</button>
  <button class="tab <?= $tab==='seguridad'?'active':'' ?>"   onclick="location='?tab=seguridad'">Seguridad</button>
</div>

<?php if ($tab === 'perfil'): ?>
  <div class="card"><div class="card-body">
    <div class="staff-profile-head">
      <div class="staff-profile-avatar"><?= mb_strtoupper(mb_substr($profile['name'],0,1)) ?></div>
      <div><div class="staff-profile-name"><?= e($profile['name']) ?></div><div class="staff-profile-email"><?= e($profile['email']) ?></div></div>
    </div>
    <form method="POST" style="max-width:520px">
      <div class="form-grid">
        <div class="form-field full"><label>Nombre</label><input type="text" name="name" class="form-control" value="<?= e($profile['name']) ?>" required></div>
        <div class="form-field"><label>Correo</label><input type="email" name="email" class="form-control" value="<?= e($profile['email']) ?>" required></div>
        <div class="form-field"><label>Teléfono</label><input type="tel" name="phone" class="form-control" value="<?= e($profile['phone'] ?? '') ?>"></div>
      </div>
      <input type="hidden" name="action" value="profile">
      <input type="hidden" name="tab" value="perfil">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <button class="btn btn-primary" style="margin-top:18px">Guardar cambios</button>
    </form>
  </div></div>
<?php endif; ?>

<?php if ($tab === 'seguridad'): ?>
  <div class="card"><div class="card-body">
    <form method="POST" style="max-width:440px">
      <div class="form-field full" style="margin-bottom:14px"><label>Contraseña actual</label><input type="password" name="current" class="form-control" required></div>
      <div class="form-field full" style="margin-bottom:14px"><label>Nueva contraseña</label><input type="password" name="new" class="form-control" minlength="6" required><div class="form-hint">Mínimo 6 caracteres.</div></div>
      <div class="form-field full"><label>Repetir nueva contraseña</label><input type="password" name="repeat" class="form-control" minlength="6" required></div>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="tab" value="seguridad">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <button class="btn btn-primary" style="margin-top:18px">Actualizar contraseña</button>
    </form>
  </div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
