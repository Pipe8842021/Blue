<?php
// ============================================================
// Blue Therapy — Avisos de la campana del panel (admin y profesional)
// ------------------------------------------------------------
//   GET                                     → { no_leidas, avisos: [...] }
//   POST action=marcar_leida  id            → { ok, no_leidas }
//   POST action=marcar_todas                → { ok, no_leidas }
//   POST action=publicar  titulo  mensaje   → { ok, msg }   (solo admin)
//
// Cada persona solo ve y marca sus propios avisos. Los POST llevan el token
// CSRF de la sesión (cabecera X-CSRF-Token o campo csrf_token).
// ============================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-notificaciones.php';

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Inicia sesión para ver tus avisos.'], 401);
}

$usuario = currentUser();
$userId  = (int)($usuario['id'] ?? 0);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $avisos = array_map(fn($a) => [
            'id'     => (int)$a['id'],
            'tipo'   => $a['type'],
            'titulo' => $a['title'],
            'cuerpo' => $a['body'],
            // Solo enlaces internos del panel.
            'url'    => ($a['url'] && str_starts_with($a['url'], '/Blue/')) ? $a['url'] : null,
            'leida'  => $a['read_at'] !== null,
            'hace'   => haceCuantoNotificacion($a['created_at']),
        ], notificacionesDeUsuario($db, $userId, 15));

        jsonResponse(['no_leidas' => contarNotificacionesNoLeidas($db, $userId), 'avisos' => $avisos]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método no permitido.'], 405);
    }
    if (!verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''))) {
        jsonResponse(['error' => 'Sesión inválida. Recarga la página.'], 403);
    }

    switch ($_POST['action'] ?? '') {
        case 'marcar_leida':
            $ok = marcarNotificacionLeida($db, (int)($_POST['id'] ?? 0), $userId);
            jsonResponse(['ok' => $ok, 'no_leidas' => contarNotificacionesNoLeidas($db, $userId)]);

        case 'marcar_todas':
            marcarTodasLeidas($db, $userId);
            jsonResponse(['ok' => true, 'no_leidas' => 0]);

        case 'publicar':
            if (($usuario['role'] ?? '') !== 'admin') {
                jsonResponse(['ok' => false, 'msg' => 'Solo un administrador puede enviar novedades.'], 403);
            }
            $r = publicarNovedad($db, $userId, (string)($_POST['titulo'] ?? ''), (string)($_POST['mensaje'] ?? ''));
            jsonResponse($r, $r['ok'] ? 200 : 422);

        default:
            jsonResponse(['error' => 'Acción no válida.'], 400);
    }

} catch (Exception $e) {
    error_log('[Blue/Avisos] Error en api/notificaciones.php: ' . $e->getMessage());
    jsonResponse(['error' => 'No se pudieron cargar los avisos.'], 500);
}
