# Agendas por profesional

Cada cosmetóloga tiene su propia agenda. En la reserva en línea el cliente elige con quién
quiere su cita, o «Cualquiera disponible», y solo ve los horarios en que esa persona puede atenderlo.
Responsable: **Felipe** (Agenda · Staff).

---

## 1. Poner la base al día

```bash
mysql -u root blue_db < database/migrations/03_agenda.sql
```

Crea la tabla `staff_services` y agrega índices. `staff_schedules` ya existía en `blue.sql`.
Es idempotente: se puede correr varias veces.

## 2. Configurar la agenda de cada profesional

**Panel → Configuración → Equipo → botón «Agenda»** en cada usuario con rol **Staff**.

- **Horario semanal:** días que trabaja y hasta dos turnos por día (ej. 9:00–13:00 y 15:00–18:00).
- **Servicios que realiza:** todos (por defecto) o solo los que se marquen.

Reglas:

| Situación | Qué pasa en la reserva en línea |
|---|---|
| Nadie tiene horario cargado | **Modo general**, como antes: lunes a sábado de 8:00 a 19:00, una cita por horario |
| Al menos una profesional tiene horario | **Modo agendas**: cada profesional tiene su propio cupo |
| Usuario con rol **admin** | Nunca aparece en la reserva, aunque tenga horario |
| Profesional inactiva o sin horario | No aparece |
| Profesional con servicios limitados | Solo aparece si hace **todos** los servicios elegidos |

> Los datos de ejemplo de `blue.sql` le cargan horario al usuario Administrador. No afecta
> nada: los administradores no aparecen en la reserva.

## 3. Cómo se calcula la disponibilidad

Todo vive en `includes/h-agendas.php`. `evaluarHorario()` devuelve el estado de cada hora:

| Estado | Significa | En el calendario |
|---|---|---|
| `libre` | alguien puede atender y la cita completa cabe en su turno | disponible |
| `ocupado` | alguien trabaja a esa hora, pero no queda cupo | «Ocupado» |
| `no_cabe` | alguien trabaja, pero la cita terminaría después de su turno | vacío |
| `fuera` | nadie (o la elegida) atiende a esa hora: antes de abrir, almuerzo, después de cerrar | gris |
| `pasado` | la hora ya pasó | gris |

- **«Cualquiera disponible»** asigna a la profesional con **menos citas ese día**, para repartir el trabajo.
- **Citas sin profesional asignada** (creadas antes de las agendas o desde el panel sin elegir a
  nadie) ocupan un cupo del centro a esa hora, aunque no se sepa de quién.
- La grilla del calendario va de la primera a la última hora de trabajo, en bloques de 60 minutos
  (`AGENDA_PASO_MIN`).

### Seguridad de la reserva

El navegador solo sugiere. Al reservar, `crearReservaPendiente()` vuelve a verificar todo en el
servidor, dentro del candado por fecha:

- que la profesional elegida realice los servicios y tenga cupo,
- la hora de fin, que se calcula con la duración real de los servicios (no se acepta la del navegador).

Cuando un pago en línea llega después de que venció el cupo, `citaCabeEnSuHorario()` revisa el
cupo **de esa profesional** antes de revivir la cita.

## 4. Archivos

| Archivo | Para qué |
|---|---|
| `includes/h-agendas.php` | disponibilidad, asignación de profesional y guardado de la agenda |
| `api/availability.php` | disponibilidad de la semana (`week_start`, `services`, `staff_id`) |
| `admin/agenda_personal.php` | pantalla de horario y servicios de cada profesional |
| `assets/js/booking.js` | selector «¿Con quién quieres tu cita?» y calendario |
| `database/migrations/03_agenda.sql` | tabla `staff_services` e índices |
| `tests/agendas.php` | 60 comprobaciones: reglas, horarios, asignación, reservas y HTTP |

```bash
php tests/agendas.php
```

Crea profesionales, horarios y citas de prueba y los borra al terminar.

## 5. Pendientes conocidos

- **Ausencias puntuales** (vacaciones, incapacidades, un día libre): hoy se manejan quitando ese día
  del horario semanal. No hay bloqueos por fecha.
- **Citas creadas desde el panel:** el admin puede asignar a cualquiera a cualquier hora (no se
  valida la agenda). Es a propósito, para casos especiales.
