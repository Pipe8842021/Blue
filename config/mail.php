<?php
// ============================================================
// Blue Therapy — Configuración de correo (avisos al cliente)
// ------------------------------------------------------------
// Con esto el cliente puede recibir por correo el estado de su cita:
// solicitud recibida, cita confirmada, pago rechazado, cita cancelada,
// cita completada y "ya quedó todo pagado".
//
// Para producción NO pongas las credenciales reales aquí (este archivo
// sí se versiona). Crea `config/mail.local.php` — está en .gitignore —
// con un array que sobrescriba solo lo que cambia:
//
//   <?php return [
//       'metodo' => 'smtp',
//       'smtp' => [
//           'host'      => 'smtp.gmail.com',
//           'puerto'    => 587,
//           'seguridad' => 'tls',           // 'tls' · 'ssl' · '' (sin cifrar)
//           'usuario'   => 'reservas@tudominio.com',
//           'clave'     => 'xxxx xxxx xxxx xxxx',   // contraseña de aplicación, no la normal
//       ],
//       'remitente_email'  => 'reservas@tudominio.com',
//       'remitente_nombre' => 'Blue Therapy',
//   ];
//
// Mientras el método siga en 'log', nada se envía de verdad: cada
// correo se guarda como archivo de texto en logs/correos/ para poder
// revisar el contenido sin necesidad de una cuenta de correo real.
// Así se puede probar y ajustar el mensaje antes de conectar el SMTP.
// ============================================================

$config = [

    // 'log'  = no envía nada; guarda el correo en logs/correos/ (por defecto)
    // 'smtp' = lo manda de verdad por el servidor configurado abajo
    // 'mail' = usa la función mail() de PHP (requiere sendmail configurado en el servidor)
    'metodo' => 'log',

    // ── Servidor SMTP (solo si 'metodo' => 'smtp') ────────────
    'smtp' => [
        'host'      => '',       // ej. smtp.gmail.com · smtp.office365.com · smtp.hostinger.com
        'puerto'    => 587,
        'seguridad' => 'tls',    // 'tls' (puerto 587) · 'ssl' (puerto 465) · '' (sin cifrar)
        'usuario'   => '',
        'clave'     => '',
        'tiempo_limite' => 12,   // segundos de espera antes de rendirse
    ],

    // ── Remitente ──────────────────────────────────────────────
    'remitente_email'  => 'reservas@blue-therapy.local',
    'remitente_nombre' => 'Blue Therapy',

    // Se le agrega automáticamente a cada correo para que el cliente pueda
    // responder directo (déjalo vacío para usar el mismo remitente).
    'responder_a' => '',

    // Prefijo opcional en el asunto, útil si comparten bandeja con otro sistema.
    'asunto_prefijo' => '',
];

// Sobrescritura local (credenciales reales, nunca se sube al repositorio).
if (is_file(__DIR__ . '/mail.local.php')) {
    $local = require __DIR__ . '/mail.local.php';
    if (is_array($local)) {
        if (isset($local['smtp']) && is_array($local['smtp'])) {
            $local['smtp'] = array_merge($config['smtp'], $local['smtp']);
        }
        $config = array_merge($config, $local);
    }
}

return $config;
