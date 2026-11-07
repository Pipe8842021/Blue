<?php
/**
 * h-whatsapp-bot.php — Respuestas automáticas de WhatsApp.
 * Responsable: Felipe.
 *
 * El bot solo informa: servicios y precios, estado de la cita, horario y
 * ubicación, y pasa la conversación al equipo si la persona lo pide. Para
 * agendar le manda el enlace de la página, donde el wizard valida horarios,
 * duración y pago. Agendar conversando se equivoca fácil con fechas y horas.
 *
 * Todo lo que entra y lo que sale queda en `wa_messages`; en qué punto del menú
 * va cada número, en `wa_conversations`.
 *
 * Los mensajes salen como texto libre, que Meta solo permite dentro de las 24
 * horas siguientes a un mensaje de la persona. Como el bot siempre responde a
 * algo que acaba de llegar, la ventana está abierta.
 */

require_once __DIR__ . '/h-whatsapp.php';
require_once __DIR__ . '/h-negocio.php';
require_once __DIR__ . '/h-reservas.php';        // urlPublicaBlue(), urlEstadoCita()
require_once __DIR__ . '/h-pagos.php';           // resumenPagosCita()
require_once __DIR__ . '/h-notificaciones.php';  // avisar al equipo

/** Estados de la conversación. */
const WA_ESTADO_MENU       = 'menu';
const WA_ESTADO_CATEGORIAS = 'categorias';

/**
 * Procesa un mensaje entrante: lo registra, arma la respuesta y la envía.
 *
 * @param array $mensaje ['id','telefono','texto','nombre','tipo'] (mensajesEntrantesDeEvento)
 * @return array ['atendido'=>bool, 'duplicado'=>bool, 'limite'=>bool, 'texto'=>string, 'estado'=>string]
 */
function procesarMensajeEntranteWhatsApp(PDO $db, array $mensaje): array {
    $telefono  = preg_replace('/\D+/', '', (string)($mensaje['telefono'] ?? ''));
    $idMeta    = trim((string)($mensaje['id'] ?? ''));
    $resultado = ['atendido' => false, 'duplicado' => false, 'limite' => false, 'texto' => '', 'estado' => WA_ESTADO_MENU];

    if ($telefono === '') return $resultado;

    // Meta reintenta los eventos: un mensaje ya procesado no se vuelve a contestar.
    if ($idMeta !== '') {
        $existe = $db->prepare('SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = ?');
        $existe->execute([$idMeta]);
        if ((int)$existe->fetchColumn() > 0) {
            $resultado['duplicado'] = true;
            return $resultado;
        }
    }

    $db->prepare("INSERT INTO wa_messages (direction, phone, wa_message_id, body) VALUES ('in', ?, ?, ?)")
       ->execute([$telefono, $idMeta ?: null, mb_substr((string)($mensaje['texto'] ?? ''), 0, 1000)]);

    $conversacion = conversacionWhatsApp($db, $telefono);
    $db->prepare('UPDATE wa_conversations SET last_in_at = NOW() WHERE phone = ?')->execute([$telefono]);

    // Tope de respuestas por hora: evita que un bucle o un bot ajeno nos dispare solos.
    $limite  = max(1, (int)(configWhatsApp()['limite_respuestas_hora'] ?? 20));
    $salidas = $db->prepare("SELECT COUNT(*) FROM wa_messages WHERE direction = 'out' AND phone = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $salidas->execute([$telefono]);
    if ((int)$salidas->fetchColumn() >= $limite) {
        error_log('[Blue/WhatsApp] Tope de respuestas por hora alcanzado con ' . $telefono);
        $resultado['limite'] = true;
        return $resultado;
    }

    $respuesta = respuestaDelBot($db, $conversacion, (string)($mensaje['texto'] ?? ''), (string)($mensaje['nombre'] ?? ''), $telefono);
    $resultado['texto']  = $respuesta['texto'];
    $resultado['estado'] = $respuesta['estado'];

    if (enviarWhatsAppTexto($telefono, $respuesta['texto'])) {
        $db->prepare("INSERT INTO wa_messages (direction, phone, body) VALUES ('out', ?, ?)")
           ->execute([$telefono, mb_substr($respuesta['texto'], 0, 1000)]);
        $db->prepare('UPDATE wa_conversations SET state = ?, data = ?, last_out_at = NOW() WHERE phone = ?')
           ->execute([$respuesta['estado'], $respuesta['data'] ?? null, $telefono]);
        $resultado['atendido'] = true;
    }

    return $resultado;
}

/** Conversación de un número (la crea si es la primera vez). */
function conversacionWhatsApp(PDO $db, string $telefono): array {
    $stmt = $db->prepare('SELECT * FROM wa_conversations WHERE phone = ? LIMIT 1');
    $stmt->execute([$telefono]);
    $conversacion = $stmt->fetch();

    if (!$conversacion) {
        $db->prepare('INSERT INTO wa_conversations (phone, state) VALUES (?, ?)')->execute([$telefono, WA_ESTADO_MENU]);
        $stmt->execute([$telefono]);
        $conversacion = $stmt->fetch();
    }
    return $conversacion ?: ['phone' => $telefono, 'state' => WA_ESTADO_MENU, 'data' => null];
}

/** Texto en minúsculas y sin tildes, para comparar lo que escribió la persona. */
function textoNormalizado(string $texto): string {
    $texto = mb_strtolower(trim($texto));
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/**
 * Decide qué contestar.
 * @return array ['texto'=>string, 'estado'=>string, 'data'=>?string]
 */
function respuestaDelBot(PDO $db, array $conversacion, string $texto, string $nombre, string $telefono): array {
    $t      = textoNormalizado($texto);
    $opcion = preg_match('/^([1-5])\b/', $t, $m) ? (int)$m[1] : 0;
    $estado = (string)($conversacion['state'] ?? WA_ESTADO_MENU);

    // Volver al inicio desde cualquier punto.
    if (in_array($t, ['menu', 'inicio', 'volver', '0', 'hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches'], true)) {
        return ['texto' => menuWhatsApp($nombre), 'estado' => WA_ESTADO_MENU, 'data' => null];
    }

    // Eligiendo una categoría de servicios.
    if ($estado === WA_ESTADO_CATEGORIAS) {
        $categorias = json_decode((string)($conversacion['data'] ?? '[]'), true) ?: [];
        $elegida    = $opcion ?: (int)preg_replace('/\D+/', '', $t);
        if (isset($categorias[$elegida - 1])) {
            return ['texto' => serviciosDeCategoriaWhatsApp($db, (int)$categorias[$elegida - 1]), 'estado' => WA_ESTADO_MENU, 'data' => null];
        }
        return ['texto' => "No encontré esa opción. Responde con el número de la categoría, o escribe *menú* para volver al inicio.",
                'estado' => WA_ESTADO_CATEGORIAS, 'data' => $conversacion['data']];
    }

    // Menú principal: por número o por palabras.
    $quiere = fn(array $palabras) => (bool)array_filter($palabras, fn($p) => str_contains($t, $p));

    if ($opcion === 1 || $quiere(['servicio', 'precio', 'cuanto', 'catalogo', 'tratamiento'])) {
        return categoriasWhatsApp($db);
    }
    if ($opcion === 2 || $quiere(['agendar', 'reservar', 'pedir cita', 'nueva cita', 'disponibilidad'])) {
        return ['texto' => textoAgendarWhatsApp(), 'estado' => WA_ESTADO_MENU, 'data' => null];
    }
    if ($opcion === 3 || $quiere(['mi cita', 'estado', 'confirmar', 'tengo cita'])) {
        return ['texto' => estadoDeCitaWhatsApp($db, $telefono), 'estado' => WA_ESTADO_MENU, 'data' => null];
    }
    if ($opcion === 4 || $quiere(['horario', 'ubicacion', 'direccion', 'donde', 'atienden'])) {
        return ['texto' => textoNegocioWhatsApp(), 'estado' => WA_ESTADO_MENU, 'data' => null];
    }
    if ($opcion === 5 || $quiere(['persona', 'asesor', 'humano', 'hablar', 'llamar'])) {
        return ['texto' => pasarAlEquipoWhatsApp($db, $telefono, $nombre, $texto), 'estado' => WA_ESTADO_MENU, 'data' => null];
    }

    return ['texto' => "No estoy seguro de haber entendido 🙈\n\n" . menuWhatsApp($nombre), 'estado' => WA_ESTADO_MENU, 'data' => null];
}

/** Menú principal. */
function menuWhatsApp(string $nombre = ''): string {
    $saludo = $nombre !== '' ? '¡Hola ' . explode(' ', trim($nombre))[0] . '!' : '¡Hola!';
    return "{$saludo} 💙 Soy el asistente de *" . datoNegocio('nombre') . "*.\n\n"
         . "Responde con el número de lo que necesitas:\n\n"
         . "*1.* Servicios y precios\n"
         . "*2.* Agendar una cita\n"
         . "*3.* Estado de mi cita\n"
         . "*4.* Horario y ubicación\n"
         . "*5.* Hablar con una persona del equipo";
}

/** Categorías activas, numeradas para que la persona elija. */
function categoriasWhatsApp(PDO $db): array {
    $categorias = $db->query(
        "SELECT c.id, c.name
           FROM categories c
           JOIN services s ON s.category_id = c.id AND s.active = 1
          WHERE c.active = 1
          GROUP BY c.id, c.name
          ORDER BY c.name"
    )->fetchAll();

    if (!$categorias) {
        return ['texto' => "Todavía no tengo el catálogo a mano. Escribe *5* y te atiende alguien del equipo.",
                'estado' => WA_ESTADO_MENU, 'data' => null];
    }

    $texto = "Estas son nuestras categorías 💙\n\nResponde con el número:\n\n";
    foreach ($categorias as $i => $c) {
        $texto .= '*' . ($i + 1) . ".* {$c['name']}\n";
    }
    $texto .= "\nEscribe *menú* para volver al inicio.";

    return ['texto' => $texto, 'estado' => WA_ESTADO_CATEGORIAS,
            'data' => json_encode(array_map('intval', array_column($categorias, 'id')))];
}

/** Servicios de una categoría, con precio y duración. */
function serviciosDeCategoriaWhatsApp(PDO $db, int $categoriaId): string {
    $stmt = $db->prepare(
        'SELECT s.name, s.price, s.duration_min, s.is_assessment, c.name AS categoria
           FROM services s JOIN categories c ON c.id = s.category_id
          WHERE s.category_id = ? AND s.active = 1
          ORDER BY s.is_assessment DESC, s.name'
    );
    $stmt->execute([$categoriaId]);
    $servicios = $stmt->fetchAll();

    if (!$servicios) {
        return "Por ahora no tengo servicios en esa categoría. Escribe *menú* para volver al inicio.";
    }

    $texto = '*' . $servicios[0]['categoria'] . "*\n\n";
    foreach ($servicios as $s) {
        $precio = (int)$s['is_assessment'] === 1 || (float)$s['price'] <= 0 ? 'Sin costo' : formatPrice((float)$s['price']);
        $texto .= "• {$s['name']} — {$precio} · " . (int)$s['duration_min'] . " min\n";
    }
    return $texto . "\nPara agendar: " . urlPublicaBlue() . "/booking.php\n\nEscribe *menú* para volver al inicio.";
}

/** Invitación a reservar en la página. */
function textoAgendarWhatsApp(): string {
    return "Puedes agendar tú mismo en este enlace 👇\n" . urlPublicaBlue() . "/booking.php\n\n"
         . "Ahí eliges el servicio, con quién quieres tu cita y el horario disponible.\n\n"
         . "Escribe *menú* para volver al inicio.";
}

/** Horario, dirección y teléfono del centro. */
function textoNegocioWhatsApp(): string {
    return '*' . datoNegocio('nombre') . "*\n\n"
         . '🕐 ' . datoNegocio('horario') . "\n"
         . '📍 ' . datoNegocio('direccion') . "\n"
         . '📞 ' . datoNegocio('telefono') . "\n\n"
         . "Escribe *menú* para volver al inicio.";
}

/**
 * Próxima cita del número que escribe. Solo se le muestra la cita de su propio
 * teléfono: se comparan los últimos 10 dígitos, porque en la base el número
 * puede estar guardado con o sin indicativo.
 */
function estadoDeCitaWhatsApp(PDO $db, string $telefono): string {
    $ultimos = substr(preg_replace('/\D+/', '', $telefono), -10);

    $stmt = $db->prepare(
        "SELECT a.id
           FROM appointments a
           JOIN clients c ON c.id = a.client_id
          WHERE RIGHT(REGEXP_REPLACE(c.phone, '[^0-9]', ''), 10) = ?
            AND a.status IN ('pending', 'confirmed')
            AND CONCAT(a.date, ' ', a.time_start) >= NOW()
          ORDER BY a.date, a.time_start
          LIMIT 1"
    );
    $stmt->execute([$ultimos]);
    $citaId = (int)$stmt->fetchColumn();

    if (!$citaId) {
        return "No encontré citas próximas con este número 🤔\n\n"
             . "Si quieres agendar: " . urlPublicaBlue() . "/booking.php\n"
             . "Si crees que hay un error, escribe *5* y te atiende alguien del equipo.";
    }

    $cita      = citaConDetalle($db, $citaId);
    $servicios = serviciosDeCita($db, $citaId);
    $total     = array_sum(array_map(fn($s) => (float)$s['price'], $servicios));
    $resumen   = resumenPagosCita($db, $citaId, $total);
    $estados   = ['pending' => 'Pendiente de confirmación', 'confirmed' => 'Confirmada'];

    $texto = "Tu próxima cita 💙\n\n"
           . '📅 ' . formatDate($cita['date']) . ' · ' . formatTime($cita['time_start']) . "\n"
           . '💅 ' . implode(', ', array_column($servicios, 'name')) . "\n"
           . ($cita['staff_nombre'] ? '👩 Te atiende ' . $cita['staff_nombre'] . "\n" : '')
           . '📌 ' . ($estados[$cita['status']] ?? $cita['status']) . "\n";

    if ($total > 0) {
        $texto .= '💰 Abono pagado ' . formatPrice($resumen['abono'])
                . ' · pendiente ' . formatPrice($resumen['pendiente']) . "\n";
    }

    return $texto . "\nAquí puedes ver o cancelar tu cita:\n" . urlEstadoCita($db, $citaId)
         . "\n\nEscribe *menú* para volver al inicio.";
}

/** Pasa la conversación al equipo: avisa en la campana del panel. */
function pasarAlEquipoWhatsApp(PDO $db, string $telefono, string $nombre, string $ultimoMensaje): string {
    try {
        $admins = $db->query("SELECT id FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        notificar(
            $db, array_map('intval', $admins), 'whatsapp',
            'Un cliente quiere hablar con el equipo',
            trim(($nombre !== '' ? $nombre . ' · ' : '') . '+' . $telefono
                 . ($ultimoMensaje !== '' ? ' · «' . mb_substr($ultimoMensaje, 0, 120) . '»' : ''))
        );
    } catch (Throwable $e) {
        error_log('[Blue/WhatsApp] No se pudo avisar que piden atención humana: ' . $e->getMessage());
    }

    return "Listo, ya le avisé al equipo 🙌 Te escribimos por aquí en cuanto alguien esté libre.\n\n"
         . 'Si es urgente puedes llamarnos al ' . datoNegocio('telefono') . ".\n\n"
         . "Escribe *menú* para volver al inicio.";
}
