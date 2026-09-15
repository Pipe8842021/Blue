<?php
/**
 * h-negocio.php — Datos de contacto del negocio (config/negocio.php).
 * Se usan en el sitio público, el wizard, la vista de la cita y los correos.
 */

/**
 * Un dato de contacto del negocio: 'nombre' · 'telefono' · 'correo' · 'direccion' · 'horario'.
 * El archivo de configuración se lee una sola vez por petición.
 */
function datoNegocio(string $clave): string {
    static $datos = null;
    $datos ??= require __DIR__ . '/../config/negocio.php';
    return (string)($datos[$clave] ?? '');
}

/** Teléfono del negocio solo con dígitos, para enlaces wa.me y tel:. */
function telefonoNegocioDigitos(): string {
    return preg_replace('/\D+/', '', datoNegocio('telefono'));
}
