<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: /Blue/' . (hasRole('admin') ? 'admin/' : 'staff/'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Por favor, completa todos los campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido.';
    } elseif (login($email, $password)) {
        header('Location: /Blue/' . (hasRole('admin') ? 'admin/' : 'staff/'));
        exit;
    } else {
        $error = 'Correo o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — Blue Therapy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="/Blue/assets/css/global.css">
    <link rel="stylesheet" href="/Blue/assets/css/auth.css">
</head>
<body>

<div class="auth-page">

    <!-- Panel izquierdo -->
    <div class="auth-left">
        <div class="auth-left__ambient" aria-hidden="true"></div>
        <div class="auth-left__deco"    aria-hidden="true"></div>

        <div class="auth-left__content">

            <div class="auth-left__logo">
                <div class="auth-left__logo-emblem">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 3C12 3 7 7 7 12C7 14.5 8.5 16.5 10 17.5L12 21L14 17.5C15.5 16.5 17 14.5 17 12C17 7 12 3 12 3Z" fill="#5BC4D0" opacity="0.9"/>
                        <path d="M9 8C9 8 6 10 6 13C6 14.5 6.8 15.8 8 16.5" stroke="#5BC4D0" stroke-width="1.2" stroke-linecap="round" opacity="0.5"/>
                        <path d="M15 8C15 8 18 10 18 13C18 14.5 17.2 15.8 16 16.5" stroke="#5BC4D0" stroke-width="1.2" stroke-linecap="round" opacity="0.5"/>
                    </svg>
                </div>
                <div class="auth-left__logo-text">
                    <span class="auth-left__logo-blue">Blue</span>
                    <span class="auth-left__logo-therapy">Therapy</span>
                </div>
            </div>

            <h2 class="auth-left__title">
                Gestiona tu centro con <em>facilidad</em>
            </h2>
            <p class="auth-left__desc">
                Controla citas, ingresos y servicios desde un solo panel diseñado para profesionales.
            </p>

            <div class="auth-left__features">
                <div class="auth-left__feature">
                    <div class="auth-left__feature-icon">📅</div>
                    <span class="auth-left__feature-text">Gestión completa de citas</span>
                </div>
                <div class="auth-left__feature">
                    <div class="auth-left__feature-icon">💰</div>
                    <span class="auth-left__feature-text">Control de ingresos y egresos</span>
                </div>
                <div class="auth-left__feature">
                    <div class="auth-left__feature-icon">👥</div>
                    <span class="auth-left__feature-text">Administración de personal</span>
                </div>
                <div class="auth-left__feature">
                    <div class="auth-left__feature-icon">📊</div>
                    <span class="auth-left__feature-text">Reportes y estadísticas</span>
                </div>
            </div>

        </div>
    </div>

    <!-- Panel derecho -->
    <div class="auth-right">
        <div class="auth-right__wrap">

            <a href="/Blue/" class="auth-right__back">
                ← Volver al inicio
            </a>

            <h1 class="auth-right__title">Bienvenido</h1>
            <p class="auth-right__subtitle">Ingresa con tu cuenta para continuar.</p>

            <?php if ($error): ?>
                <div class="flash flash--error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="/Blue/login.php" novalidate class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input
                        class="form-input<?= $error ? ' form-input--error' : '' ?>"
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        placeholder="correo@ejemplo.com"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <div class="auth-password-wrap">
                        <input
                            class="form-input<?= $error ? ' form-input--error' : '' ?>"
                            type="password"
                            id="password"
                            name="password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="auth-password-toggle" aria-label="Mostrar/ocultar contraseña" onclick="togglePassword(this)">
                            <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                                <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit">
                    Iniciar sesión
                </button>
            </form>

            <p class="auth-right__hint">¿Problemas para acceder? Contacta al administrador.</p>

        </div>
    </div>

</div>

<script>
function togglePassword(btn) {
    const input  = btn.closest('.auth-password-wrap').querySelector('input');
    const isPass = input.type === 'password';
    input.type = isPass ? 'text' : 'password';
    btn.querySelector('.icon-eye').style.display     = isPass ? 'none' : '';
    btn.querySelector('.icon-eye-off').style.display = isPass ? ''     : 'none';
}
</script>
</body>
</html>
