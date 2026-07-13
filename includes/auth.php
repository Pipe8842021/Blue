<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/session.php';

function login(string $email, string $password): bool {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user']    = [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
        'photo' => $user['photo'],
    ];

    session_regenerate_id(true);
    return true;
}

function logout(): void {
    session_unset();
    session_destroy();
    header('Location: /Blue/login.php');
    exit;
}

// ── Control de fuerza bruta (persistente por IP + correo) ─────────────
// A diferencia de un contador en $_SESSION, esto no se puede evadir
// descartando la cookie: la clave es (IP, correo) en la tabla login_throttle.
const LOGIN_MAX_ATTEMPTS = 5;     // intentos antes de bloquear
const LOGIN_LOCK_SECONDS = 300;   // 5 minutos de bloqueo

/** Segundos que faltan de bloqueo para esta IP+correo (0 = no bloqueado). */
function loginThrottleStatus(string $ip, string $email): int {
    $stmt = getDB()->prepare(
        'SELECT locked_until FROM login_throttle WHERE ip = ? AND email = ?'
    );
    $stmt->execute([$ip, $email]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil) return 0;
    $restante = strtotime($lockedUntil) - time();
    return $restante > 0 ? $restante : 0;
}

/** Registra un intento fallido; bloquea al llegar al máximo. */
function loginThrottleFail(string $ip, string $email): void {
    $db = getDB();
    $db->prepare(
        'INSERT INTO login_throttle (ip, email, attempts) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1'
    )->execute([$ip, $email]);

    // ¿Alcanzó el máximo? → fija el bloqueo y reinicia el contador.
    $db->prepare(
        'UPDATE login_throttle
            SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), attempts = 0
          WHERE ip = ? AND email = ? AND attempts >= ?'
    )->execute([LOGIN_LOCK_SECONDS, $ip, $email, LOGIN_MAX_ATTEMPTS]);
}

/** Limpia el registro tras un inicio de sesión correcto. */
function loginThrottleReset(string $ip, string $email): void {
    getDB()->prepare('DELETE FROM login_throttle WHERE ip = ? AND email = ?')
           ->execute([$ip, $email]);
}
