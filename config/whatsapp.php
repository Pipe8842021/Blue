<?php
// ============================================================
// Blue Therapy — Configuración de WhatsApp (API oficial de Meta)
// ------------------------------------------------------------
// Avisos por WhatsApp al equipo (cancelaciones, novedades) y al cliente.
//
// Mientras el método siga en 'log', nada se envía de verdad: cada mensaje
// se guarda como archivo de texto en logs/whatsapp/ para revisar el
// contenido sin cuenta de Meta.
//
// El día que exista la cuenta de WhatsApp Business (Cloud API), NO pongas
// el token aquí (este archivo sí se versiona). Crea
// `config/whatsapp.local.php` — está en .gitignore — con lo que cambie:
//
//   <?php return [
//       'metodo'          => 'cloud_api',
//       'phone_number_id' => '123456789012345',
//       'token'           => 'EAAG...',   // token permanente de un usuario del sistema
//   ];
//
// Las plantillas de abajo hay que crearlas y hacerlas aprobar en el
// Administrador de WhatsApp (categoría «Utilidad»), con estos nombres y
// las variables en este mismo orden.
// ============================================================

$config = [

    // 'log'       = no envía nada; guarda el mensaje en logs/whatsapp/ (por defecto)
    // 'cloud_api' = lo envía de verdad por la API oficial de Meta
    'metodo' => 'log',

    // ── Cloud API (solo si 'metodo' => 'cloud_api') ────────────
    'version_api'     => 'v23.0',
    'phone_number_id' => '',
    'token'           => '',
    'tiempo_limite'   => 10,     // segundos de espera antes de rendirse

    // ── Webhook: mensajes que entran (api/whatsapp_webhook.php) ─
    // `verify_token` lo inventas tú y lo escribes igual en Meta al registrar la
    // URL del webhook. `app_secret` sale de la app de Meta (Configuración →
    // Básica → Clave secreta) y sirve para comprobar la firma de cada evento:
    // sin él el webhook rechaza todo, para que nadie pueda falsificar mensajes.
    'verify_token' => '',
    'app_secret'   => '',

    // El bot solo responde a este número de mensajes seguidos de una misma
    // persona en una hora; así una conversación no se dispara sola.
    'limite_respuestas_hora' => 20,

    // Idioma con el que se aprobaron las plantillas en Meta ('es', 'es_CO'…).
    'idioma' => 'es',

    // Se antepone a los celulares locales de 10 dígitos (Colombia).
    'prefijo_pais' => '57',

    // ── Plantillas aprobadas (nombre en Meta) ──────────────────
    'plantillas' => [
        // {{1}} cliente · {{2}} fecha · {{3}} hora · {{4}} quién canceló
        'cita_cancelada_equipo'  => 'aviso_cita_cancelada',
        // {{1}} título · {{2}} mensaje
        'novedad_equipo'         => 'aviso_novedad_equipo',
        // {{1}} nombre del cliente · {{2}} fecha · {{3}} hora
        'cita_cancelada_cliente' => 'cita_cancelada',
    ],

    // Avisos internos que además llegan por WhatsApp a quien tenga celular
    // cargado en su usuario. El resto solo aparece en la campana del panel.
    'avisos_equipo' => ['cita_cancelada', 'novedad'],
];

// Sobrescritura local (credenciales reales, nunca se sube al repositorio).
if (is_file(__DIR__ . '/whatsapp.local.php')) {
    $local = require __DIR__ . '/whatsapp.local.php';
    if (is_array($local)) {
        if (isset($local['plantillas']) && is_array($local['plantillas'])) {
            $local['plantillas'] = array_merge($config['plantillas'], $local['plantillas']);
        }
        $config = array_merge($config, $local);
    }
}

return $config;
