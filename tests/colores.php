<?php
// ============================================================
// Blue Therapy — Pruebas del color de cada profesional
// ------------------------------------------------------------
// Uso, desde la raíz del proyecto y con MySQL encendido:
//
//     php tests/colores.php
//
//   1. Paleta, validación y contraste del texto
//   2. Asignación automática sin repetir
//   3. Guardar un color y mostrarlo (etiquetas HTML)
//   4. La reserva en línea recibe el color de cada profesional
//
// Crea usuarios de prueba y los borra al terminar.
// Solo corre por consola: la carpeta tests/ está bloqueada en .htaccess.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-agendas.php';   // trae h-colores.php

$resultado = ['ok' => 0, 'falla' => 0];
$usuarios  = [];

function seccion(string $titulo): void {
    echo "\n\033[1m{$titulo}\033[0m\n" . str_repeat('─', 60) . "\n";
}

function comprobar(string $descripcion, bool $cumple, string $detalle = ''): bool {
    global $resultado;
    $resultado[$cumple ? 'ok' : 'falla']++;
    $marca = $cumple ? "\033[32m[OK]\033[0m   " : "\033[31m[FALLA]\033[0m";
    echo "  {$marca} {$descripcion}" . ($detalle !== '' ? "  \033[90m({$detalle})\033[0m" : '') . "\n";
    return $cumple;
}

/** Usuario de prueba sin color (para que el sistema se lo asigne). */
function usuarioSinColor(PDO $db, string $nombre): int {
    global $usuarios;
    $db->prepare('INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, 1)')
       ->execute([$nombre, 'prueba.color.' . bin2hex(random_bytes(4)) . '@blue.test',
                  password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'staff']);
    return $usuarios[] = (int)$db->lastInsertId();
}

/** Luminancia relativa (WCAG) de un color #rrggbb. */
function luminancia(string $hex): float {
    $canal = function (string $h): float {
        $x = hexdec($h) / 255;
        return $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $canal(substr($hex, 1, 2)) + 0.7152 * $canal(substr($hex, 3, 2)) + 0.0722 * $canal(substr($hex, 5, 2));
}

register_shutdown_function(function () {
    global $usuarios;
    try {
        $db = getDB();
        foreach ($usuarios as $id) $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        echo "\n\033[31mNo se pudieron borrar los usuarios de prueba: {$e->getMessage()}\033[0m\n";
    }
});

echo "\033[1mBlue Therapy — pruebas del color de cada profesional\033[0m\n";

try {
    $db = getDB();
} catch (Throwable $e) {
    echo "\033[31mNo hay conexión con la base de datos: {$e->getMessage()}\033[0m\n";
    exit(1);
}

$tieneColumna = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'color'"
)->fetchColumn();
if (!$tieneColumna) {
    echo "\033[31mFalta la columna users.color: corre database/migrations/03_agenda.sql\033[0m\n";
    exit(1);
}

// ── 1. Paleta, validación y contraste ─────────────────────
seccion('1. Paleta, validación y contraste');

comprobar('La paleta no repite colores', count(array_unique(PALETA_PROFESIONALES)) === count(PALETA_PROFESIONALES));
comprobar('Todos los colores de la paleta son #rrggbb',
    count(array_filter(PALETA_PROFESIONALES, 'colorProfesionalEsValido')) === count(PALETA_PROFESIONALES));
comprobar('Rechaza colores mal formados',
    !colorProfesionalEsValido('rojo') && !colorProfesionalEsValido('#fff') && !colorProfesionalEsValido('#gggggg')
    && !colorProfesionalEsValido('#12345') && !colorProfesionalEsValido('red;background:url(x)'));
comprobar('Normaliza a minúsculas', normalizarColorProfesional(' #E0708F ') === '#e0708f');
comprobar('Un color inválido se normaliza a null', normalizarColorProfesional('azul') === null);
comprobar('Texto blanco sobre un fondo oscuro', colorTextoSobre('#1a2b4c') === '#ffffff');
comprobar('Texto oscuro sobre un fondo claro', colorTextoSobre('#f3e6a0') === '#1a1a1a');

$peorContraste = 99.0;
foreach (PALETA_PROFESIONALES as $c) {
    $l1 = luminancia($c);
    $l2 = luminancia(colorTextoSobre($c));
    $peorContraste = min($peorContraste, (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05));
}
comprobar('Las iniciales se leen (contraste ≥ 3) sobre todos los colores de la paleta', $peorContraste >= 3.0,
    sprintf('peor caso %.2f', $peorContraste));
comprobar('Fondo suave en rgba', fondoSuaveProfesional('#e0708f', 0.2) === 'rgba(224,112,143,0.2)', fondoSuaveProfesional('#e0708f', 0.2));

// ── 2. Asignación automática ──────────────────────────────
seccion('2. Asignación automática sin repetir');

comprobar('Con nadie usando colores toma el primero de la paleta', elegirColorLibre([]) === PALETA_PROFESIONALES[0]);
comprobar('Salta los colores ya usados',
    elegirColorLibre([PALETA_PROFESIONALES[0], PALETA_PROFESIONALES[1]]) === PALETA_PROFESIONALES[2]);
comprobar('Con la paleta agotada elige el menos repetido',
    elegirColorLibre([...PALETA_PROFESIONALES, ...array_slice(PALETA_PROFESIONALES, 1)]) === PALETA_PROFESIONALES[0]);

$antes = coloresProfesionales($db);
$a = usuarioSinColor($db, 'Prueba Color A');
$b = usuarioSinColor($db, 'Prueba Color B');
$c = usuarioSinColor($db, 'Prueba Color C');
$colores = coloresProfesionales($db);

comprobar('Los usuarios nuevos reciben color al pedirlos', isset($colores[$a], $colores[$b], $colores[$c]));
comprobar('Los tres colores nuevos son distintos entre sí', count(array_unique([$colores[$a], $colores[$b], $colores[$c]])) === 3);
if (count(array_diff(PALETA_PROFESIONALES, array_values($antes))) >= 3) {
    comprobar('No repiten el de nadie del equipo mientras haya libres',
        !array_intersect([$colores[$a], $colores[$b], $colores[$c]], array_values($antes)));
}
comprobar('Quedan guardados en la base (mismo color en la próxima carga)', coloresProfesionales($db)[$a] === $colores[$a]);
comprobar('No cambia el color de quien ya tenía', array_intersect_key($colores, $antes) === $antes);

$nuevo = colorParaNuevoProfesional($db);
comprobar('El color para un usuario nuevo es de la paleta', in_array($nuevo, PALETA_PROFESIONALES, true), $nuevo);

// ── 3. Guardar y mostrar ──────────────────────────────────
seccion('3. Guardar y mostrar');

$r = guardarColorProfesional($db, $a, '#12AB34');
comprobar('Guarda un color válido (en minúsculas)', $r['ok'] && coloresProfesionales($db)[$a] === '#12ab34', $r['msg']);

$r = guardarColorProfesional($db, $a, 'url(javascript:alert(1))');
comprobar('Rechaza un color inválido y no cambia el guardado', !$r['ok'] && coloresProfesionales($db)[$a] === '#12ab34', $r['msg']);

$db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute(['basura', $b]);
$tras = coloresProfesionales($db);
comprobar('Un color corrupto en la base se reemplaza por uno válido', colorProfesionalEsValido($tras[$b]), $tras[$b]);

comprobar('Sin profesional se usa el gris de «sin asignar»', colorDeProfesional($colores, null) === COLOR_SIN_ASIGNAR);
comprobar('Con un id desconocido también', colorDeProfesional($colores, 999999) === COLOR_SIN_ASIGNAR);

$html = etiquetaProfesional('Ana <b>Pérez</b>', '#12ab34');
comprobar('La etiqueta lleva el color y escapa el nombre',
    str_contains($html, 'background:#12ab34') && str_contains($html, 'Ana &lt;b&gt;Pérez&lt;/b&gt;') && !str_contains($html, '<b>'));
comprobar('Sin nombre muestra «Sin asignar»', str_contains(etiquetaProfesional(null, null), 'Sin asignar'));
comprobar('Un color inyectado en el punto cae al gris', !str_contains(puntoColorProfesional('red;x'), 'red;x'));

// ── 4. Reserva en línea ───────────────────────────────────
seccion('4. Reserva en línea');

$db->prepare('INSERT INTO staff_schedules (staff_id, day_of_week, time_start, time_end) VALUES (?, 1, ?, ?)')
   ->execute([$a, '08:00:00', '12:00:00']);
$lunes  = date('Y-m-d', strtotime('monday next week'));
$semana = disponibilidadSemana($db, $lunes, [], 0);
$suya   = array_values(array_filter($semana['profesionales'], fn($p) => $p['id'] === $a))[0] ?? null;
comprobar('La disponibilidad incluye el color de cada profesional', $suya && $suya['color'] === '#12ab34', json_encode($suya));
comprobar('Y el color del texto sobre él', $suya && $suya['texto'] === colorTextoSobre('#12ab34'), $suya['texto'] ?? '');
comprobar('Sin exponer correo, teléfono ni rol', $suya && array_keys($suya) === ['id', 'nombre', 'iniciales', 'color', 'texto']);

echo "\n" . str_repeat('═', 60) . "\n";
printf("Resultado: \033[32m%d correctas\033[0m · \033[31m%d fallas\033[0m\n", $resultado['ok'], $resultado['falla']);
exit($resultado['falla'] > 0 ? 1 : 0);
