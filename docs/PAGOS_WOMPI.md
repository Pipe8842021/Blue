# Pagos en línea con Wompi — abono para agendar

Guía de configuración y pruebas del módulo de pagos. Responsable: **Jhonatan** (Reservas · Finanzas).

El cliente ya no solo *solicita* una cita: paga un abono y la cita **se confirma sola**
cuando Wompi aprueba el pago. El saldo se cobra en el centro el día de la cita.

---

## 1. Cómo funciona

```
booking.php  ──1──►  api/pago_iniciar.php  ──2──►  checkout.wompi.co
   (wizard)          crea la cita 'pending'          (paga el cliente)
                     + fila en `payments`                   │
                     y APARTA el horario                    │
                                                    ┌───────┴────────┐
                                                    ▼                ▼
                                       pago_resultado.php   api/wompi_webhook.php
                                       (vuelve el cliente)  (avisa Wompi, es el
                                                             camino confiable)
                                                    └───────┬────────┘
                                                            ▼
                                              aplicarResultadoPago()
                                        APROBADO → cita 'confirmed'
                                                 + ingreso en Finanzas
                                        RECHAZADO → cita 'cancelled'
```

Puntos importantes del diseño:

- **El monto nunca llega del navegador.** `api/pago_iniciar.php` suma los precios de la BD
  y calcula el abono ahí mismo; el checkout va firmado con el secreto de integridad.
- **El horario se aparta mientras el cliente paga.** La cita nace en `pending`, así que
  desaparece de la disponibilidad. Si no paga en el tiempo configurado (20 min por
  defecto), `liberarReservasVencidas()` la cancela y devuelve el cupo. Esa limpieza corre
  sola al consultar disponibilidad o al iniciar otra reserva: **no hace falta un cron**.
- **Confirmar es idempotente.** El cliente que vuelve del checkout y el webhook llegan casi
  al mismo tiempo; solo uno confirma la cita y registra el ingreso (candado con un
  `UPDATE ... WHERE status = 'pending'`).
- **Sin llaves, todo sigue igual.** Si `config/wompi.php` no tiene llaves, el wizard vuelve
  al flujo de siempre (solicitud + confirmación manual por WhatsApp). Nada se rompe.

---

## El día que se crea la cuenta de Wompi

Todo lo demás ya está construido y probado. Son cinco pasos:

1. **Crear la cuenta** en <https://comercios.wompi.co> (el ambiente de pruebas es gratis).
2. **Copiar las 4 llaves de pruebas** (Desarrolladores → Llaves de API) en
   `config/wompi.local.php`, reemplazando los valores de demostración.
3. **Pegar la URL de eventos** en Desarrolladores → URL de eventos. Está en
   *Configuración → Pagos en línea*, con botón para copiarla.
4. **Pulsar «Probar conexión con Wompi»** en esa misma pantalla. Consulta la API real:
   verifica que Wompi reconozca la llave pública y acepte la privada, que los prefijos
   correspondan al ambiente, la URL del sitio, cURL y las tablas. Lo que salga en rojo
   impide cobrar; lo amarillo funciona pero conviene revisarlo.
5. **Correr la suite** y hacer un pago de prueba desde el sitio:

   ```bash
   php tests/pagos_wompi.php
   ```

   Son 47 comprobaciones: diagnóstico, cálculo del abono, firma del checkout, firma del
   webhook, flujo completo en la base (apartar, aprobar, rechazar, vencer, saldo, enlace
   del cliente) y los endpoints por HTTP. Crea citas de prueba y las borra al terminar;
   no cobra nada. Sale con código 1 si algo falla.

Lo único que ninguna prueba automática puede confirmar son los **secretos de integridad y
de eventos**: solo firman datos, así que su prueba final es ese pago real en sandbox.

### Modo demostración

Mientras `config/wompi.local.php` tenga las llaves de relleno (`…000000000000DEMO`), el
sistema lo detecta y:

- muestra el paso de pago completo, con un aviso *Modo demostración* y el botón inactivo;
- **no aparta horarios** (el checkout de Wompi las rechazaría y el cupo quedaría bloqueado);
- deja el abono como **opcional**, para que el sitio siga recibiendo reservas: aparece
  *«Prefiero que me contacten para coordinar el pago»*.

Al pegar las llaves reales todo vuelve al comportamiento normal, sin tocar código.

---

## 2. Configuración

### 2.1 Poner la base de datos al día (una vez)

Las tres migraciones del equipo, en orden. Todas son idempotentes: se pueden volver a
correr sin romper nada.

```bash
mysql -u root blue_db < database/migrations/01_catalogo.sql   # Ana
mysql -u root blue_db < database/migrations/02_finanzas.sql   # Jhonatan
mysql -u root blue_db < database/migrations/03_agenda.sql     # Felipe
```

La 02 crea las tablas `payments` (abonos y saldos) y `appointment_links` (el enlace
privado del cliente), y hace `registered_by` opcional en `finances` — los abonos
automáticos no los registra ninguna persona.

> **Ojo:** si te saltas la 01, el wizard de reservas se ve vacío ("No hay servicios
> disponibles"), porque `booking.php` consulta columnas que esa migración agrega.

### 2.2 Catálogo de prueba (opcional)

Para tener con qué probar — precios variados, duraciones distintas y destacados:

```bash
mysql -u root --default-character-set=utf8mb4 blue_db < database/seeds/demo_servicios.sql
```

Deja **23 servicios activos en 5 categorías**, entre $35.000 y $320.000. No es una
migración: son datos de demostración, y el propio archivo trae al final el `DELETE`
para quitarlos antes de producción.

### 2.3 Sacar las llaves de Wompi

1. Crear cuenta en <https://comercios.wompi.co> (el ambiente de pruebas es gratis).
2. Ir a **Desarrolladores → Llaves de API** y copiar las cuatro de *pruebas*:
   - Llave pública `pub_test_…`
   - Llave privada `prv_test_…`
   - Secreto de integridad
   - Secreto de eventos
3. En **Desarrolladores → URL de eventos** pegar:
   `https://TU-DOMINIO/Blue/api/wompi_webhook.php`
4. Verificar con **«Probar conexión con Wompi»** (Configuración → Pagos en línea).

> La URL de eventos tiene que ser pública. En `localhost` Wompi no puede llamarla: para
> probar el webhook en local hay que exponer el puerto con algo tipo ngrok, o simplemente
> confiar en el retorno del cliente (`pago_resultado.php`), que también confirma la cita.

### 2.4 Cargar las llaves

Para desarrollo, se editan directo en `config/wompi.php`.

Para producción **nunca** se ponen ahí (ese archivo sí se versiona). Se crea
`config/wompi.local.php`, que está en `.gitignore` y sobrescribe lo que haga falta:

```php
<?php
return [
    'entorno'            => 'prod',
    'llave_publica'      => 'pub_prod_...',
    'llave_privada'      => 'prv_prod_...',
    'secreto_integridad' => '...',
    'secreto_eventos'    => '...',
    'url_base'           => 'https://midominio.com/Blue',
];
```

### 2.5 Ajustar la regla del abono

En `config/wompi.php`, clave `abono`:

| Opción | Qué hace | Por defecto |
|---|---|---|
| `porcentaje` | % del total de los servicios | `30` |
| `minimo` | nunca cobra menos que esto | `20000` |
| `maximo` | tope (`0` = sin tope) | `0` |
| `redondear_a` | redondea hacia arriba a múltiplos | `1000` |
| `minutos_reserva` | cuánto se le guarda el horario al cliente | `20` |
| `abono_obligatorio` | `true` = no se agenda sin pagar | `true` |

Ejemplo con los valores por defecto: servicio de $80.000 → abono de **$24.000**,
saldo de $56.000 el día de la cita.

Todo esto se ve (en solo lectura) en **Panel → Configuración → Pagos en línea**,
junto con el estado de la conexión y los últimos abonos recibidos.

---

## 3. Probar

Primero la suite automática (`php tests/pagos_wompi.php`), después los casos con
tarjetas de prueba, que Wompi publica en su documentación. En sandbox:

| Qué se quiere probar | Cómo |
|---|---|
| Pago aprobado | tarjeta de prueba `APPROVED` de Wompi |
| Pago rechazado | tarjeta de prueba `DECLINED` |
| Cupo vencido | iniciar el pago, no pagar, esperar los minutos configurados y recargar la agenda |
| Doble confirmación | pagar y, además, reenviar el evento desde el panel de Wompi: el ingreso no se duplica |

Después de un pago aprobado hay que verificar tres cosas:

1. La cita quedó en **Confirmada** (Panel → Citas).
2. Apareció un ingreso **"Abono en línea"** en Finanzas, marcado como *Pago en línea*.
3. En las notas de la cita quedó la referencia de Wompi.

---

## 4. Vista de estado de la cita

`cita.php` es **una sola página para dos públicos**. Lo que se ve depende de quién entra:

| | Cliente (con enlace) | Personal (con sesión) |
|---|---|---|
| Estado, línea de tiempo, fecha, servicios, profesional | ✅ | ✅ |
| Abono, saldo pagado, pendiente y **a quién se le pagó** | ✅ | ✅ |
| Notas internas de la cita | ❌ | ✅ |
| Referencias de pago y pagos no aprobados | ❌ | ✅ |
| Registrar el pago del saldo | ❌ | ✅ |
| Anular un pago registrado | ❌ | solo admin |

### Cómo llega el cliente

Con un enlace privado del tipo `…/cita.php?id=12&t=<token>`. El token es una clave
aleatoria guardada en `appointment_links`; sin él la página responde 404. El cliente
lo recibe automáticamente:

- al terminar de reservar (botón **"Ver el estado de mi cita"**),
- al volver del pago en Wompi (`pago_resultado.php`),
- o cuando el equipo se lo comparte: en la vista del personal hay un botón para
  copiarlo y otro para enviarlo por WhatsApp ya redactado.

Borrar la fila de `appointment_links` invalida el enlace sin tocar la cita.

### Cómo llega el personal

Entrando logueado a `…/cita.php?id=12` — sin token. Hay accesos directos desde:

- **Citas** (admin) y **Mis citas** (profesional): opción *Ver estado y pagos* en el
  menú «⋯» de cada fila.
- **Finanzas**: enlace *ver cita #N* en cada movimiento.
- **Configuración → Pagos en línea**.

Además, en ambos listados la columna **Total** muestra el estado de cobro: una insignia
verde *Pagada* cuando no queda nada, o *Falta $X* cuando hay saldo. Las citas sin ningún
pago registrado se ven igual que siempre.

### Registrar el saldo

El formulario pide **monto**, **medio de pago** (efectivo, Nequi, tarjeta,
transferencia…), **a quién del equipo se le pagó** y una observación opcional.
Al guardar se crean dos cosas:

1. una fila en `payments` con `kind = 'balance'`, `provider = 'manual'` y `received_by`,
2. el ingreso correspondiente en **Finanzas**, categoría *Saldo de cita*.

Reglas que aplica el servidor (no el formulario):

- El pendiente se recalcula siempre desde la base: `total servicios − pagos aprobados`.
- No deja cobrar más que el pendiente; sí admite **pagos parciales** (se puede registrar
  varias veces hasta saldar).
- Un **administrador** puede registrar el pago de cualquier cita; un **profesional**,
  solo el de las citas que tiene asignadas.
- Anular un pago borra también su ingreso en Finanzas, y es exclusivo del admin.
  Los abonos cobrados por Wompi no se anulan desde aquí: eso se hace en el panel de Wompi.

### Ojo con el doble conteo

Al marcar una cita como *completada*, el módulo de Agenda registra el total del servicio
como ingreso — pero solo si la cita **no tenía ya ningún ingreso**. Como el abono en línea
sí cuenta como ingreso, en esas citas el saldo nunca entraba a Finanzas: justamente el
hueco que tapa esta vista.

El caso contrario (completar primero y registrar el saldo después) sí duplicaría el
dinero, así que la página muestra una advertencia en amarillo cuando la cita ya tiene
ingresos que no salieron de un pago registrado aquí.

---

## Aspecto visual del apartado

Todas las pantallas de pago (paso 4, resultado, estado de la cita y la pestaña del
panel) comparten tokens de color definidos al inicio de su bloque en `booking.css` y
`m-finanzas.css`. No hay colores sueltos: salen del sistema de diseño.

| Uso | Valor | Origen |
|---|---|---|
| Estados (fondo / texto) | `#d1fae5/#065f46`, `#fef3c7/#92400e`, `#fee2e2/#991b1b`, `#e0e7ff/#3730a3` | badges de `admin.css` |
| Texto secundario, bordes, superficies | `#4a6570`, `#c8d9de`, `#edf6f8` | `--gray-600`, `--gray-200`, `--light` de `global.css` |
| Texto teal y botones | `#1c7068`, `#227e75 → #1a6b64` | tintas del teal del logo |
| Acentos (anillos, líneas) | `#5bc4b8` | `--teal` |

Los pares texto/fondo se midieron con la fórmula de contraste de WCAG 2.1: **los 18
cumplen AA** (antes cumplían 5). Si se cambia un color, conviene volver a medirlo:
por debajo de 4.5:1 el texto pequeño deja de leerse bien.

---

## 5. Archivos

| Archivo | Para qué |
|---|---|
| `config/wompi.php` | llaves y reglas del abono (`wompi.local.php` las sobrescribe) |
| `includes/h-pagos.php` | firma, consulta a la API, webhook, confirmación y liberación de cupos |
| `includes/h-reservas.php` | validación y creación de la cita, enlace privado del cliente |
| `api/pago_iniciar.php` | aparta el horario y devuelve el checkout firmado |
| `api/wompi_webhook.php` | recibe los eventos de Wompi (camino confiable) |
| `pago_resultado.php` | página a la que vuelve el cliente tras pagar |
| `cita.php` | vista de estado de la cita (cliente y personal) |
| `database/seeds/demo_servicios.sql` | catálogo de prueba (23 servicios) |
| `tests/pagos_wompi.php` | suite de pruebas por consola (bloqueada para el navegador) |
| `docs/AVISOS_CORREO.md` | guía del aviso por correo al cliente (evento hermano de este módulo) |
| `database/migrations/02_finanzas.sql` | tablas `payments` y `appointment_links` |

---

## 6. Pendientes conocidos

- **Falta cargar las llaves de Wompi.** Es lo único que impide cobrar: ver
  *El día que se crea la cuenta de Wompi*, al inicio de esta guía. Mientras tanto el
  sitio queda en modo demostración y el cobro del saldo en el centro funciona igual.

- **Cambios en archivos del módulo de Agenda (Felipe).** Con su visto bueno se tocaron
  `admin/appointments.php` y `staff/citas.php` para agregar el enlace *Ver estado y
  pagos* y la insignia de cobro. Son cuatro líneas por archivo y toda la lógica vive en
  `includes/h-pagos.php` (`pagosPorCita()` e `insigniaPagoCita()`), que además degrada
  sola: si alguien no ha corrido la migración 02, esas pantallas siguen funcionando
  sin la información de pagos en vez de romperse.

- **Devoluciones:** si un pago se aprueba justo después de que venció el cupo y alguien más
  lo tomó, el sistema no revive la cita: deja una nota en mayúsculas pidiendo contactar al
  cliente para reprogramar o devolver el dinero. La devolución se hace a mano desde el
  panel de Wompi.

- **Recordatorio por WhatsApp:** sigue siendo manual; el pago no dispara ningún mensaje.
