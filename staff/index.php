<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin('/Blue/login.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Staff — Blue</title>
    <link rel="stylesheet" href="/Blue/assets/css/global.css">
</head>
<body style="background:var(--blue-50);display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:16px">
    <div style="font-size:3rem">📅</div>
    <h1 style="color:var(--blue-900);font-size:1.5rem">Panel Staff — Próximamente</h1>
    <p style="color:var(--gray-600)">Bienvenido, <?= htmlspecialchars(currentUser()['name']) ?>.</p>
    <a href="/Blue/logout.php" class="btn btn-outline">Cerrar sesión</a>
</body>
</html>
