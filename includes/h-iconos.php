<?php
/**
 * Íconos SVG en línea (trazo, currentColor) para reemplazar emojis en las vistas públicas.
 * Mismo estilo que los íconos del sidebar: 24x24, stroke 2, sin relleno.
 */

/** Trazos de cada ícono, por nombre. */
function trazosDeIconos(): array
{
    return [
        'calendario' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'reloj'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'mensaje'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'pin'        => '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'telefono'   => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'alerta'     => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'tarjeta'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'corazon'    => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
        'brillo'     => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/>',
        'rayo'       => '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>',
        'hoja'       => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10z"/><path d="M2 21c0-3 1.9-5.4 5-6"/>',
        'pulso'      => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'bolsa'      => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'nota'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
    ];
}

/** Devuelve el SVG del ícono `$nombre` con tamaño fijo `$px` (nunca crece). Vacío si no existe. */
function iconoSvg(string $nombre, int $px = 18): string
{
    $trazos = trazosDeIconos()[$nombre] ?? '';
    if ($trazos === '') {
        return '';
    }
    return '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $trazos . '</svg>';
}
