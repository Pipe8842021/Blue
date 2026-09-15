<?php
// ============================================================
// Blue Therapy — Configuración de la pasarela de pagos (Wompi)
// ------------------------------------------------------------
// Las llaves salen del panel de Wompi:
//   https://comercios.wompi.co  →  Desarrolladores → Llaves de API
//
// NO pongas aquí las llaves de PRODUCCIÓN. Para producción crea
// el archivo `config/wompi.local.php` (está en .gitignore) con un
// array que sobrescriba solo lo que cambia:
//
//   <?php return [
//       'entorno'            => 'prod',
//       'llave_publica'      => 'pub_prod_...',
//       'llave_privada'      => 'prv_prod_...',
//       'secreto_integridad' => '...',
//       'secreto_eventos'    => '...',
//       'url_base'           => 'https://midominio.com/Blue',
//   ];
//
// Mientras las llaves estén vacías el agendamiento sigue
// funcionando como antes: la cita se envía como solicitud y el
// equipo la confirma a mano por WhatsApp.
// ============================================================

$config = [

    // 'test' = ambiente de pruebas (sandbox) · 'prod' = dinero real
    'entorno' => 'test',

    // ── Llaves de API ─────────────────────────────────────────
    'llave_publica'      => '',  // pub_test_xxxxxxxx
    'llave_privada'      => '',  // prv_test_xxxxxxxx
    'secreto_integridad' => '',  // firma de integridad del checkout
    'secreto_eventos'    => '',  // firma de los eventos (webhook)

    // ── URL pública del sitio, SIN barra final ────────────────
    // Wompi necesita una URL absoluta para devolver al cliente y
    // para enviar los eventos. En local basta con localhost.
    'url_base' => 'http://localhost/Blue',

    // ── Regla del abono ───────────────────────────────────────
    // El cliente paga solo una parte al reservar; el resto lo paga
    // en el centro el día de la cita.
    'abono' => [
        'porcentaje'  => 30,     // % del total de los servicios
        'minimo'      => 20000,  // nunca cobrar menos de esto (COP)
        'maximo'      => 0,      // 0 = sin tope
        'redondear_a' => 1000,   // redondear hacia arriba a múltiplos de $1.000
    ],

    // Minutos que se le guarda el horario al cliente mientras paga.
    // Pasado ese tiempo la cita se cancela sola y el cupo se libera.
    'minutos_reserva' => 20,

    // Si es true y la pasarela está configurada, NO se puede
    // agendar sin pagar el abono. Con false, el pago es opcional.
    'abono_obligatorio' => true,
];

// Sobrescritura local (llaves reales, dominio de producción…).
if (is_file(__DIR__ . '/wompi.local.php')) {
    $local = require __DIR__ . '/wompi.local.php';
    if (is_array($local)) {
        if (isset($local['abono']) && is_array($local['abono'])) {
            $local['abono'] = array_merge($config['abono'], $local['abono']);
        }
        $config = array_merge($config, $local);
    }
}

return $config;
