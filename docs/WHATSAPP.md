# WhatsApp: avisos y bot de consulta

Responsable: **Felipe**. Código en `includes/h-whatsapp.php` (envío),
`includes/h-whatsapp-bot.php` (respuestas) y `api/whatsapp_webhook.php` (lo que entra).

Hoy todo funciona **en modo prueba**: nada se envía y cada mensaje se guarda como archivo
de texto en `logs/whatsapp/`. Falta únicamente la cuenta de WhatsApp Business del cliente.

---

## 1. Qué hace

**Salidas (nosotros escribimos):** cancelación de cita al cliente, aviso de cancelación al
equipo y novedades del equipo. Van con **plantilla aprobada**, que es lo único que Meta
permite para escribirle primero a alguien.

**Entradas (el cliente escribe):** el bot responde un menú y contesta con texto libre, lo
que Meta permite dentro de las 24 h siguientes a un mensaje de la persona.

```
1. Servicios y precios     → categorías → servicios con precio y duración
2. Agendar una cita        → enlace a booking.php
3. Estado de mi cita       → su próxima cita + enlace privado (solo de su propio número)
4. Horario y ubicación     → datos de config/negocio.php
5. Hablar con una persona  → avisa al equipo en la campana del panel
```

El bot **no agenda por chat** a propósito: elegir fecha y hora conversando se equivoca
fácil, y el wizard de la página ya valida horarios, duración, profesional y pago.

## 2. El día que exista la cuenta

1. Poner la base al día: `mysql -u root blue_db < database/migrations/03_agenda.sql`
   (tablas `wa_messages` y `wa_conversations`).
2. En `config/whatsapp.local.php` (está en `.gitignore`):

```php
<?php return [
    'metodo'          => 'cloud_api',
    'phone_number_id' => '123456789012345',
    'token'           => 'EAAG...',              // token permanente del usuario del sistema
    'verify_token'    => 'una-frase-larga-que-inventes',
    'app_secret'      => '...',                  // app de Meta → Configuración → Básica
];
```

3. En Meta → la app → WhatsApp → **Configuración → Webhooks**:
   - URL: `https://TU-DOMINIO/Blue/api/whatsapp_webhook.php`
   - Token de verificación: el mismo `verify_token` de arriba
   - Suscribirse al campo **messages**
4. Crear y hacer aprobar las plantillas (los textos están en `docs/CANCELACIONES_Y_AVISOS.md`).
5. Escribirle al número desde un celular: debe llegar el menú.

> El webhook necesita **HTTPS público**: en `localhost` Meta no puede llamarlo. Para probar
> en local se expone el puerto con ngrok o Cloudflare Tunnel.

## 3. Seguridad

- Cada evento se valida con la firma `X-Hub-Signature-256` (HMAC del cuerpo con `app_secret`).
  **Sin `app_secret` configurado el webhook rechaza todo**, para que nadie pueda falsificar mensajes.
- Los mensajes repetidos no se contestan dos veces: Meta reintenta los eventos y se descartan
  por el id del mensaje.
- El bot responde como máximo 20 mensajes por hora al mismo número
  (`limite_respuestas_hora`), para que una conversación no se dispare sola.
- El estado de la cita solo se muestra a quien escribe desde el teléfono de esa cita.

## 4. Pruebas

```bash
php tests/whatsapp.php
```

24 comprobaciones con mensajes simulados y firmados: firma, verificación de la URL, lectura
de los eventos de Meta, cada opción del menú, mensajes repetidos y el tope por hora. No
necesita cuenta de Meta ni envía nada.

## 5. Pendiente

- **La cuenta de Meta** (la está tramitando el cliente): número de empresa, token permanente,
  `app_secret` y plantillas aprobadas.
- **Hosting con HTTPS** para que Meta pueda llamar al webhook.
- Recordatorio automático el día antes de la cita: queda fácil de agregar con el canal ya hecho,
  pero necesita una tarea programada en el servidor.
