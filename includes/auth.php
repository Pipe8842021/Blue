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
