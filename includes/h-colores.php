<?php
/**
 * h-colores.php — Color de identificación de cada profesional.
 * Responsable: Felipe (módulo Agenda · Staff).
 *
 * Cada persona del equipo (`users.color`, formato #rrggbb) tiene un color con el
 * que se reconoce en los calendarios, el listado de citas, la asignación y la
 * reserva en línea. Se asigna solo, de una paleta pensada para convivir con la
 * marca, sin repetir mientras queden colores libres; el administrador puede
 * cambiarlo en Configuración → Equipo.
 *
 * El color es solo identidad: el estado de la cita sigue yendo con el semáforo
 * (pendiente / confirmada / completada / cancelada).
 */

/** Paleta de profesionales: distinguibles entre sí y sin chocar con el semáforo de estados. */
const PALETA_PROFESIONALES = [
    '#e0708f', // rosa
    '#7b8cde', // azul violeta
    '#d9a441', // dorado
    '#5aa9d6', // celeste
    '#9b7bd0', // lila
    '#6bbf8a', // menta
    '#d9795b', // terracota
    '#c06fb0', // orquídea
    '#4fb5b0', // turquesa
    '#a3a15a', // oliva
];

/** Color de quien no tiene profesional asignada. */
const COLOR_SIN_ASIGNAR = '#9aa0a6';

/** ¿Es un color #rrggbb válido? */
function colorProfesionalEsValido(string $color): bool {
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $color);
}

/** Pasa a minúsculas; devuelve null si no es #rrggbb. */
function normalizarColorProfesional(?string $color): ?string {
    $color = strtolower(trim((string)$color));
    return colorProfesionalEsValido($color) ? $color : null;
}

/**
 * Color de texto legible (blanco u oscuro) sobre un fondo de este color,
 * el que dé más contraste según WCAG.
 */
function colorTextoSobre(string $fondo): string {
    $fondo = normalizarColorProfesional($fondo) ?? COLOR_SIN_ASIGNAR;
    $canal = function (string $hex): float {
        $c = hexdec($hex) / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $luz = 0.2126 * $canal(substr($fondo, 1, 2))
         + 0.7152 * $canal(substr($fondo, 3, 2))
         + 0.0722 * $canal(substr($fondo, 5, 2));

    $contrasteBlanco = 1.05 / ($luz + 0.05);
    $contrasteOscuro = ($luz + 0.05) / 0.0585;   // sobre #1a1a1a (luminancia ≈ 0.0103)
    return $contrasteBlanco >= $contrasteOscuro ? '#ffffff' : '#1a1a1a';
}

/** "#e0708f" → "rgba(224,112,143,0.16)": fondo suave para chips y filas. */
function fondoSuaveProfesional(string $color, float $opacidad = 0.16): string {
    $color = normalizarColorProfesional($color) ?? COLOR_SIN_ASIGNAR;
    return sprintf('rgba(%d,%d,%d,%s)',
        hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2)), $opacidad);
}

/**
 * Primer color de la paleta que nadie usa; si ya se usan todos, el menos repetido.
 *
 * @param array $enUso colores (#rrggbb) que ya tiene el equipo
 */
function elegirColorLibre(array $enUso): string {
    $usos = array_fill_keys(PALETA_PROFESIONALES, 0);
    foreach ($enUso as $color) {
        $color = normalizarColorProfesional($color);
        if ($color !== null && isset($usos[$color])) $usos[$color]++;
    }
    $minimo = min($usos);
    foreach ($usos as $color => $cantidad) {
        if ($cantidad === $minimo) return $color;
    }
    return PALETA_PROFESIONALES[0];
}

/**
 * Color de cada persona del equipo: [id => '#rrggbb'].
 * A quien todavía no tiene (o lo tiene inválido) se le asigna y se guarda ahora,
 * así el color es el mismo en todas las pantallas desde la primera vez.
 */
function coloresProfesionales(PDO $db): array {
    // Sin la migración 03_agenda.sql no existe `users.color`: todo se ve gris, pero ninguna pantalla se cae.
    try {
        $filas = $db->query('SELECT id, color FROM users ORDER BY id')->fetchAll();
    } catch (PDOException $e) {
        error_log('[Blue] No se pudieron leer los colores de los profesionales: ' . $e->getMessage());
        return [];
    }

    $colores = [];
    $pendientes = [];
    foreach ($filas as $f) {
        $color = normalizarColorProfesional($f['color']);
        if ($color === null) $pendientes[] = (int)$f['id'];
        else $colores[(int)$f['id']] = $color;
    }

    if ($pendientes) {
        $guardar = $db->prepare('UPDATE users SET color = ? WHERE id = ?');
        foreach ($pendientes as $id) {
            $colores[$id] = elegirColorLibre($colores);
            $guardar->execute([$colores[$id], $id]);
        }
    }
    return $colores;
}

/** Color de una persona del equipo, o el gris de «sin asignar». */
function colorDeProfesional(array $colores, ?int $staffId): string {
    return ($staffId && isset($colores[$staffId])) ? $colores[$staffId] : COLOR_SIN_ASIGNAR;
}

/**
 * Color que se le da a alguien nuevo: uno libre de la paleta.
 * Lo usa Configuración → Equipo al crear un usuario sin elegir color.
 */
function colorParaNuevoProfesional(PDO $db): string {
    return elegirColorLibre(array_column($db->query('SELECT color FROM users WHERE color IS NOT NULL')->fetchAll(), 'color'));
}

/**
 * Cambia el color de una persona del equipo.
 * @return array ['ok' => bool, 'msg' => string]
 */
function guardarColorProfesional(PDO $db, int $staffId, string $color): array {
    $color = normalizarColorProfesional($color);
    if ($color === null) {
        return ['ok' => false, 'msg' => 'El color no es válido. Elige uno del selector.'];
    }
    $db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$color, $staffId]);
    return ['ok' => true, 'msg' => 'Color guardado.'];
}

// ══════════════════════════════════════════════════════════
//   HTML
// ══════════════════════════════════════════════════════════

/** Punto de color (inline: se ve igual en cualquier página del panel, sin depender de una hoja de estilos). */
function puntoColorProfesional(string $color): string {
    $color = normalizarColorProfesional($color) ?? COLOR_SIN_ASIGNAR;
    return '<i class="staff-dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;flex-shrink:0;background:'
         . e($color) . '"></i>';
}

/**
 * Nombre de la profesional con su punto de color, o «Sin asignar».
 * @param string|null $nombre null/vacío = sin profesional asignada
 */
function etiquetaProfesional(?string $nombre, ?string $color): string {
    if ($nombre === null || $nombre === '') {
        return '<span class="pill pill-muted">Sin asignar</span>';
    }
    return '<span class="staff-tag" style="display:inline-flex;align-items:center;gap:7px">'
         . puntoColorProfesional((string)$color) . '<span>' . e($nombre) . '</span></span>';
}
