# Avisos por correo al cliente

Guía de configuración y pruebas del aviso por correo. Responsable: **Jhonatan** (Reservas · Finanzas).
Relacionado: [`PAGOS_WOMPI.md`](PAGOS_WOMPI.md) (los pagos son uno de los eventos que avisan por correo).

El cliente decide en el paso de datos del wizard si quiere que le escribamos cuando cambie el
estado de su cita o de su pago. Como con Wompi, funciona **sin cuenta real** desde el primer
momento: en modo de prueba cada correo se guarda como archivo de texto en `logs/correos/` en
vez de enviarse, así se puede revisar y ajustar el mensaje antes de conectar un servidor real.

---

## 1. Cómo funciona

```
booking.php ──► checkbox "Avisos por correo" (solo si hay correo escrito)
                        │
                        ▼
        api/book.php · api/pago_iniciar.php · aplicarResultadoPago()
        registrarPagoSaldo() · liberarReservasVencidas() · acciones del
        personal (confirmar/cancelar/completar en admin/ y staff/)
                        │
                        ▼
          avisarPorCorreoSiCorresponde($db, $citaId, $evento)
                        │
              ¿tiene correo Y marcó el checkbox?
                    │sí          │no
                    ▼            ▼
            enviarCorreo()   no hace nada
                    │
        ┌───────────┼────────────┐
        ▼           ▼            ▼
     'log'       'smtp'        'mail'
   (prueba)   (servidor real)  (mail() de PHP)
```

Eventos que disparan un correo (función `avisarPorCorreoEstadoCita()` en `includes/h-correo.php`):

| Evento | Cuándo | Desde |
|---|---|---|
| `solicitud` | Reserva creada sin pago (el equipo la confirma a mano) | `api/book.php` |
| `confirmada` | Abono aprobado por Wompi, o el personal confirma la cita | `aplicarResultadoPago()`, acciones `confirm` |
| `rechazada` | Pago rechazado/anulado, o se venció el tiempo para pagar | `aplicarResultadoPago()`, `liberarReservasVencidas()` |
| `cancelada` | El personal cancela la cita | acciones `cancel` |
| `completada` | El personal marca la cita como atendida | acciones `complete` |
| `saldada` | Se terminó de pagar el saldo en el centro | `registrarPagoSaldo()` |

Puntos importantes del diseño:

- **Nunca bloquea el flujo que lo dispara.** `avisarPorCorreoSiCorresponde()` nunca lanza una
  excepción hacia afuera; si el correo falla, la reserva o el cambio de estado ya quedaron
  guardados igual, y el problema solo se registra con `error_log()`.
- **El cliente decide.** Sin el checkbox marcado (o sin correo) no se envía nada, sin importar
  cuántas veces cambie el estado de la cita.
- **Sin servidor configurado, todo sigue funcionando.** Con `metodo => 'log'` (el que trae por
  defecto) el checkbox del wizard ni siquiera se muestra — igual que el paso de pago cuando
  Wompi no tiene llaves — así que nunca se le promete al cliente un aviso que no va a llegar.
- **Sin librerías externas.** `includes/h-correo.php` trae su propio cliente SMTP mínimo
  (sockets + STARTTLS/SSL + AUTH LOGIN), consistente con el resto del proyecto.

---

## 2. Configuración

### 2.1 Modo de prueba (por defecto, no requiere nada)

Con `config/mail.php` tal como viene (`metodo => 'log'`), cada correo que se dispararía se
guarda en `logs/correos/<fecha>_<algo>_<destinatario>.txt` con el asunto, el texto plano y el
HTML completo. Se revisan en **Configuración → Correo**, que lista los últimos 8.

### 2.2 Conectar un correo real

Crear `config/mail.local.php` — está en `.gitignore`, nunca se sube — con algo así:

```php
<?php
return [
    'metodo' => 'smtp',
    'smtp' => [
        'host'      => 'smtp.gmail.com',
        'puerto'    => 587,
        'seguridad' => 'tls',
        'usuario'   => 'reservas@tudominio.com',
        'clave'     => 'xxxx xxxx xxxx xxxx',   // contraseña de aplicación, no la normal
    ],
    'remitente_email'  => 'reservas@tudominio.com',
    'remitente_nombre' => 'Blue Therapy',
];
```

Con Gmail, Outlook y la mayoría de proveedores hace falta una **contraseña de aplicación**
(no la contraseña normal de la cuenta) — se genera desde la configuración de seguridad de esa
cuenta de correo.

Si el servidor sí tiene `sendmail` configurado (típico en hosting compartido), se puede usar
`'metodo' => 'mail'` en vez de `'smtp'` y saltarse toda la configuración del servidor.

### 2.3 Verificar

En **Configuración → Correo**, botón **"Enviar correo de prueba"**: se manda a la cuenta del
admin que lo pulsa. En modo de prueba queda guardado en `logs/correos/` igual que cualquier otro.

### 2.4 Suite automática

```bash
php tests/correo.php
```

39 comprobaciones: plantillas, el motor de envío, y cada uno de los seis eventos disparándose
(o no) según corresponda — incluidas las acciones del personal por HTTP. Crea y borra sus
propias citas, clientes y archivos de log; no manda ningún correo real salvo que
`config/mail.php` ya esté en `'smtp'` o `'mail'`, en cuyo caso avisa y omite esa parte.

---

## 3. El checkbox en el wizard

Vive junto al de WhatsApp, en el paso 3 (datos de contacto), con el mismo estilo. Reglas:

- Solo se imprime en el HTML si `correoActivo()` es verdadero (hay `smtp` con host+usuario+clave,
  o `metodo => 'mail'`). En modo de prueba no existe.
- Aparece y desaparece con `booking.js` según si el campo de correo tiene algo escrito —
  sin correo no hay a dónde avisar.
- Viene marcado por defecto (igual que el de WhatsApp), pero el servidor lo vuelve a apagar si
  llega sin un correo válido, así que no hay forma de forzarlo desde el navegador.

---

## 4. Archivos

| Archivo | Para qué |
|---|---|
| `config/mail.php` | configuración de correo (`mail.local.php` la sobrescribe) |
| `includes/h-correo.php` | motor de envío (log/smtp/mail), plantillas, disparador por evento |
| `tests/correo.php` | suite de pruebas por consola (bloqueada para el navegador) |
| `logs/correos/` | correos guardados en modo de prueba (bloqueada para el navegador, no se versiona) |

Este archivo modifica, además de sus propios archivos, dos puntos pequeños de
`admin/appointments.php` y `staff/citas.php` (una línea por acción: confirmar/cancelar/completar),
del módulo de Agenda — mismo criterio que el enlace "Ver estado y pagos" agregado antes: una
llamada de una sola línea a una función que nunca lanza excepciones.

---

## 5. Pendientes conocidos

- **Falta conectar un correo real.** Es lo único que impide el envío de verdad — ver 2.2.
  Mientras tanto todo funciona en modo de prueba, incluido el botón de "Enviar correo de prueba".
- **No hay reintentos.** Si el envío falla (servidor caído, credenciales vencidas…) el correo
  simplemente no llega; no hay cola ni reintento automático. Para un negocio pequeño el volumen
  no lo justifica, pero si hace falta, `enviarCorreo()` es el único punto por donde pasan todos
  los envíos y ahí se podría enganchar una cola.
- **La suite reutiliza `pagos_wompi.php` como referencia de estilo** pero es un archivo aparte:
  si se agrega un evento nuevo, hay que sumar su prueba a mano en `tests/correo.php`.
