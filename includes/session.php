<?php
if (session_status() === PHP_SESSION_NONE) {
    // Detecta HTTPS directo o detrás de un proxy/balanceador que termina TLS.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('blue_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function hasRole(string $role): bool {
    return ($_SESSION['user']['role'] ?? '') === $role;
}

function requireLogin(string $redirect = '/Blue/login.php'): void {
    if (!isLoggedIn()) {
        header('Location: ' . $redirect);
        exit;
    }
}

function requireRole(string $role, string $redirect = '/Blue/login.php'): void {
    requireLogin($redirect);
    if (!hasRole($role)) {
        header('Location: /Blue/index.php');
        exit;
    }
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
