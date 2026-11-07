# Mejoras de la revisión 2 del cliente

Tres mejoras pedidas por el cliente. Todo el cambio de base de datos está en
`database/migrations/03_agenda.sql` (se puede volver a correr sin error):

```
mysql -u root blue_db < database/migrations/03_agenda.sql
```

Pruebas automáticas (desde la raíz, con Apache y MySQL encendidos):

```
php tests/otro_servicio.php   # 1. Otro servicio
php tests/colores.php         # 2. Color por profesional
php tests/tienda.php          # 3. Productos y pedidos
```

---

## 1. «Otro servicio» en la reserva

El cliente puede describir un servicio que no está en el catálogo.

- **Wizard (paso 1):** tarjeta «Otro servicio» con un campo de texto (máx. 300 caracteres).
  Al marcarla se desmarca y bloquea el catálogo, y al revés: **va siempre sola**, igual que
  la cita de valoración.
- **Sin precio y sin pago en línea:** queda «Por cotizar». No pasa por Wompi aunque el abono
  sea obligatorio; el equipo lo cotiza y confirma.
- **Duración:** `otro_servicio_minutos` en `config/negocio.php` (por defecto 30 min). Es lo que
  ocupa en la agenda y en la disponibilidad.
- **Dónde se guarda:** `appointments.other_service` (NULL = cita normal).
- **Dónde se ve:** calendario, lista de citas, dashboard, ficha del cliente, impresión, vista
  de la cita del cliente (`cita.php`), correos y avisos. El total dice «Por cotizar», no «Sin costo».
- **Cotizarlo:** el equipo edita la cita, borra el texto y elige servicios del catálogo; entonces
  ya tiene precio y se cobra como cualquier cita. En el formulario de cita, escribir un «Otro
  servicio» desmarca el catálogo y viceversa.

Código: `includes/h-reservas.php` (`validarDatosReserva`, `crearReservaPendiente`,
`reservaEsSinCosto`, `serviciosDeCita`), `includes/h-agenda.php` (`guardarCita`,
`sqlServiciosDeCita`), `booking.php` + `assets/js/booking.js`.

## 2. Color por profesional

Cada persona del equipo tiene un color (`users.color`, `#rrggbb`) para reconocerla de un vistazo.

- **Asignación:** automática, de una paleta de 10 colores (`PALETA_PROFESIONALES` en
  `includes/h-colores.php`), sin repetir mientras haya libres. A quien ya existía se le asigna
  la primera vez que se pide.
- **Cambiarlo:** Configuración → Equipo → Editar → «Color en el calendario» (selector o paleta).
- **Dónde se ve:** calendario del administrador (chip de cada cita con el color de la profesional
  y leyenda), calendario del profesional, lista de citas (columna Profesional y detalle),
  selectores de asignación, dashboard, cabecera de «Agenda de…», vista de la cita y las chips
  de «¿Con quién quieres tu cita?» de la reserva en línea.
- **El estado de la cita** sigue con el semáforo (pendiente / confirmada / completada / cancelada):
  en el calendario va en el puntito al final de cada chip.
- Las iniciales sobre el color usan blanco u oscuro según el contraste (WCAG).

## 3. Tienda de productos

### Para el cliente
- `tienda.php`: catálogo, carrito (se conserva en el navegador) y pedido. La página de inicio
  muestra los productos marcados «Mostrar en inicio» y un enlace «Productos» en el menú.
- **Entrega:** solo recoger en el centro. El envío a domicilio queda **pendiente de definir**:
  la base ya tiene `orders.delivery_type` y el servidor siempre guarda `pickup`.
- `pedido.php?id=…&t=…`: seguimiento con enlace privado (Recibido → Pagado → En preparación →
  Listo → Entregado).

### Para el equipo (panel, solo administrador)
- **Productos** (`admin/products.php`): nombre, precio, foto, descripción, **stock opcional**
  (vacío = sin control), activo y «en inicio». Avisa productos agotados o con poco stock (≤ 3).
- **Pedidos** (`admin/orders.php`): pestañas por estado y detalle con el siguiente paso:
  *Registrar pago* (si lo cobró el equipo) → *Empezar a preparar* → *Marcar listo y avisar al
  cliente* → *Marcar entregado*. También *Reenviar el aviso* y *Cancelar*. El menú lateral muestra
  cuántos pedidos están por atender (pagados o en preparación).

### Pago
- Con la pasarela configurada (no en modo demostración) el cliente paga el **total** en el
  checkout de Wompi. Misma pasarela de las citas; la referencia empieza por `PED-` y el webhook
  (`api/wompi_webhook.php`) y `pago_resultado.php` la encaminan al pedido.
- Sin pasarela (hoy, modo demostración) el pedido queda «Por pagar» y el equipo lo cobra por
  WhatsApp y lo marca pagado. Todo pago queda como ingreso «Venta de productos» en Finanzas.

### Stock
- Se descuenta **al crear el pedido** (con bloqueo, nadie se lleva la misma última unidad).
- Vuelve si el pedido se cancela, se rechaza el pago o vence el tiempo de pago
  (`minutos_reserva` de `config/wompi.php`). Si un pago aprobado llega después de vencer y ya no
  hay stock, el pedido queda pagado con la nota «SIN STOCK» para que el equipo contacte al cliente.
- Un pedido «Por pagar» que se cobra a mano y nadie paga se anula solo a las
  `horas_pedido_sin_pago` (48 por defecto, `config/negocio.php`; 0 = nunca), para que pedidos
  abandonados no dejen un producto agotado para siempre.
- Quien pide desde el sitio no puede pisar los datos de un cliente que ya existe (solo se completa
  su correo si no tenía): la tienda no exige iniciar sesión.

### Aviso «ya está listo»
- Al marcarlo **Listo** se avisa por los canales que el cliente aceptó: **correo** (si dejó correo
  y el sitio puede enviarlos) y **WhatsApp** (plantilla `pedido_listo`).
- Plantilla de WhatsApp a crear y aprobar en Meta (categoría Utilidad), igual que las demás:
  `pedido_listo` con `{{1}}` nombre del cliente y `{{2}}` número de pedido (ej. `#12`).
  Mientras `config/whatsapp.php` esté en modo `log`, el mensaje queda en `logs/whatsapp/`.
- Si el cliente no dejó ningún canal, el panel lo dice para que el equipo le avise.

### Pendiente / por decidir con el cliente
- **Envío a domicilio** (costo, zonas, cobro).
- **Reembolsos:** cancelar un pedido ya pagado devuelve el stock, pero el dinero se devuelve
  a mano; el panel lo recuerda.
- **Cuenta real de Wompi:** el pago de pedidos se probó con pagos simulados; falta una prueba
  de punta a punta (checkout y webhook) con la cuenta de pruebas de Wompi cuando esté conectada.

---

## Archivos de otras personas / compartidos que se tocaron
Con autorización del equipo (como en la primera revisión): `booking.php`,
`assets/js/booking.js`, `assets/css/booking.css`, `api/book.php`, `api/pago_iniciar.php`,
`api/availability.php`, `api/wompi_webhook.php`, `pago_resultado.php`, `cita.php`, `index.php`
(landing), `includes/admin_layout.php` (dos ítems de menú y un contador),
`includes/h-notificaciones.php` (`notificar()` acepta un pedido), `config/negocio.php`,
`config/whatsapp.php`, `admin/settings.php` (color), `admin/clients.php`.

## Hallazgo para el equipo (no se tocó)
`verifyCsrf()` en `includes/functions.php` devuelve `true` cuando la sesión todavía no tiene token
y el formulario llega con el token vacío (`hash_equals('', '')`). Afecta a todos los formularios.
Conviene exigir `$_SESSION['csrf_token'] !== ''`. Es un archivo compartido: se cambia en acuerdo de equipo.
