<?php
/**
 * h-negocio.php — Datos y políticas del negocio (config/negocio.php).
 * Se usan en el sitio público, el wizard, la vista de la cita y los correos.
 */

/** Toda la configuración del negocio, leída una sola vez por petición. */
function configNegocio(): array {
    static $datos = null;
    $datos ??= require __DIR__ . '/../config/negocio.php';
    return $datos;
}

/** Un dato de contacto del negocio: 'nombre' · 'telefono' · 'correo' · 'direccion' · 'horario'. */
function datoNegocio(string $clave): string {
    return (string)(configNegocio()[$clave] ?? '');
}

/** Teléfono del negocio solo con dígitos, para enlaces wa.me y tel:. */
function telefonoNegocioDigitos(): string {
    return preg_replace('/\D+/', '', datoNegocio('telefono'));
}

/** Horas mínimas de anticipación para que el cliente cancele su cita (política del negocio). */
function horasMinimasCancelacion(): int {
    return max(0, (int)(configNegocio()['horas_minimas_cancelacion'] ?? 24));
}
