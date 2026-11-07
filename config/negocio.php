<?php
// ============================================================
// Blue Therapy — Datos de contacto del negocio
// ------------------------------------------------------------
// Un solo lugar para lo que se muestra en el sitio público, el wizard
// de reservas, la vista de estado de la cita y los correos al cliente.
//
// Meta (WhatsApp) y Wompi comparan estos datos con los documentos del
// negocio: nombre, dirección y teléfono deben quedar EXACTAMENTE igual
// que en la Cámara de Comercio.
//
// PENDIENTE: los valores actuales son de relleno. Reemplazarlos por los
// datos reales que envíe el cliente.
// ============================================================

return [
    'nombre'    => 'Blue Therapy',
    'telefono'  => '+57 300 123 4567',
    'correo'    => 'info@bluetherapy.com',
    'direccion' => 'Calle Principal #123, Centro Comercial Blue',
    'horario'   => 'Lun – Sáb · 8:00 am – 7:00 pm',

    // ── Política de cancelación ──────────────────────────────
    // El cliente puede cancelar desde su enlace hasta estas horas antes de la
    // cita. Cancelar a tiempo deja el abono "por devolver"; después, solo el
    // equipo cancela y el centro conserva el abono.
    'horas_minimas_cancelacion' => 24,

    // ── «Otro servicio» ──────────────────────────────────────
    // Minutos que ocupa en la agenda una cita de un servicio fuera del
    // catálogo (el cliente lo describe y el equipo lo cotiza después).
    'otro_servicio_minutos' => 30,

    // ── Tienda ───────────────────────────────────────────────
    // Un pedido que nadie pagó se anula solo pasadas estas horas y su stock vuelve al
    // inventario (evita que pedidos abandonados dejen un producto «agotado» para siempre).
    // Aplica a los pedidos que el equipo cobra a mano; los de la pasarela vencen según
    // `minutos_reserva` de config/wompi.php. 0 = nunca se anulan solos.
    'horas_pedido_sin_pago' => 48,
];
