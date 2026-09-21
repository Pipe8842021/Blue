# Cancelaciones, devolución del abono y avisos al equipo

Responsable: **Felipe**. Código en `includes/h-cancelaciones.php`, `includes/h-notificaciones.php`
e `includes/h-whatsapp.php`.

---

## 1. Poner la base al día

```bash
mysql -u root blue_db < database/migrations/02_finanzas.sql   # devolución del abono en `payments`
mysql -u root blue_db < database/migrations/03_agenda.sql     # quién canceló + tabla `notifications`
```

Ambas son idempotentes. **Sin la 03 la cancelación no funciona** (usa `cancelled_at`, `cancelled_by`,
`cancel_reason`). La campana del panel sí degrada: sin la tabla simplemente no aparece.

## 2. Política de cancelación

Se configura en `config/negocio.php`:

```php
'horas_minimas_cancelacion' => 24,
```

| Quién cancela | Cuándo | Qué pasa con el abono |
|---|---|---|
| **Cliente**, desde su enlace (`cita.php`) | con al menos 24 h de anticipación | queda **por devolver** |
| Cliente | con menos de 24 h | no puede cancelar en línea: se le pide escribir por WhatsApp |
| **Equipo** (Citas, Mis citas o «Editar cita») | a tiempo | queda **por devolver** |
| Equipo | tarde | el centro **conserva** el abono |

- El cliente no puede cancelar mientras su pago en línea está en proceso.
- Un **administrador** puede decidir devolver igual un abono de cancelación tardía
  (botón «Devolverlo de todas formas» en la cita), por ejemplo si canceló el centro.

## 3. Devolver el abono

1. La devolución se hace **en el panel de Wompi** (la referencia aparece en la cita).
2. En la cita, un administrador pulsa **«Ya lo devolví: registrar devolución»**.
3. Eso marca el abono como devuelto y crea el **egreso** «Devolución de abono» en Finanzas.

El cliente ve en su enlace si el abono está por devolver o ya fue devuelto.

## 4. Avisos al equipo (campana)

La campana está en la barra superior del panel de admin y del panel de la profesional.

| Aviso | Lo reciben | También por WhatsApp |
|---|---|---|
| Nueva reserva en línea | administradores + profesional asignada | no |
| Abono aprobado: cita confirmada | administradores + profesional asignada | no |
| Cita cancelada | administradores + profesional asignada (menos quien canceló) | **sí** |
| Novedad del equipo | todo el equipo activo menos quien la publica | **sí** |

- Las **novedades** las envía un administrador desde la campana → «Enviar una novedad al equipo».
- El WhatsApp llega a quien tenga **celular cargado en su usuario** (Configuración → Equipo).
- Qué tipos salen por WhatsApp se decide en `config/whatsapp.php` → `avisos_equipo`.
- Al cliente, cuando se cancela, le llega el correo (si lo pidió) y un WhatsApp (si activó el recordatorio).

## 5. Canal de WhatsApp

`config/whatsapp.php` → `metodo`:

- `log` (por defecto): **no envía nada**. Cada mensaje se guarda en `logs/whatsapp/` para revisar el texto.
- `cloud_api`: envía por la API oficial de Meta. Las credenciales van en `config/whatsapp.local.php`
  (está en `.gitignore`):

```php
<?php return [
    'metodo'          => 'cloud_api',
    'phone_number_id' => '123456789012345',
    'token'           => 'EAAG...',   // token permanente de un usuario del sistema
];
```

### Plantillas para enviar a aprobación en Meta

Categoría **Utilidad**, idioma **Español**. Los nombres y el orden de las variables deben coincidir con
`config/whatsapp.php`. (Meta no acepta que el texto empiece o termine con una variable.)

**`aviso_cita_cancelada`** — al equipo
> Hola, te avisamos que la cita de {{1}} del {{2}} a las {{3}} fue cancelada por {{4}}. Revisa los detalles en el panel de Blue Therapy.

**`aviso_novedad_equipo`** — al equipo
> Novedad del equipo Blue Therapy: {{1}}. {{2}} Revisa el panel para más detalles.

**`cita_cancelada`** — al cliente
> Hola {{1}}, tu cita en Blue Therapy del {{2}} a las {{3}} fue cancelada. Si quieres agendar de nuevo, puedes reservar en nuestra página o responder a este mensaje.

## 6. Pruebas

```bash
php tests/cancelaciones.php
```

43 comprobaciones: canal de WhatsApp, política, regla del abono, devolución, notificaciones, novedades
y la cancelación del cliente por HTTP. Crea y borra sus propios datos y mensajes de prueba.
