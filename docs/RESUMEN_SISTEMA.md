# Blue Therapy — Sistema de Gestión para Centro de Estética
### Documento de resumen funcional y técnico (base para propuesta comercial)

> Versión del documento: 1.0 · Fecha: junio 2026
> Estado del producto: MVP funcional verificado de extremo a extremo

---

## 1. Descripción general

**Blue Therapy** es una aplicación web a la medida para la administración integral de un
centro de estética, spa y terapias biológicas. Unifica en una sola plataforma la **captación
de citas en línea**, la **gestión operativa interna** (agenda, clientes, servicios) y el
**control financiero** del negocio.

El sistema reemplaza procesos manuales (agendas en papel, mensajes sueltos de WhatsApp,
cuentas en cuadernos o Excel) por un flujo digital centralizado, accesible desde computador
o celular, con datos siempre disponibles y respaldados.

### Problema que resuelve
- Pérdida de reservas por no responder a tiempo o por agenda desordenada.
- Falta de visibilidad de ingresos y egresos reales del negocio.
- Sobre-agendamiento o cruces de horario.
- Información de clientes dispersa, sin historial de visitas.
- Dependencia de una sola persona que "lleva todo en la cabeza".

### Propuesta de valor
- El cliente final **reserva solo, 24/7**, sin llamadas ni esperas.
- El centro **confirma, asigna y cobra** desde un panel ordenado.
- El dueño ve en tiempo real **cuánto entra, cuánto sale y cuánto trabaja** el negocio.

---

## 2. Actores y roles del sistema

| Actor | Acceso | Qué puede hacer |
|---|---|---|
| **Cliente / Visitante** | Público, sin cuenta | Ver el sitio, explorar servicios y **reservar una cita en línea**. |
| **Administrador** | Panel privado | Control total: citas, servicios, clientes, finanzas, galería, equipo y configuración. |
| **Staff (profesional)** | Panel privado (rol limitado) | Ver su agenda y sus citas asignadas. *(Panel en hoja de ruta — ver §8.)* |

---

## 3. Arquitectura y stack tecnológico

| Capa | Tecnología |
|---|---|
| **Frontend** | HTML5, CSS3 (diseño propio, responsive), JavaScript (vanilla) |
| **Backend** | PHP 8 (sin framework, código propio y ligero) |
| **Base de datos** | MySQL / MariaDB |
| **Servidor** | Apache (compatible con XAMPP en desarrollo y hosting LAMP en producción) |
| **Seguridad** | Sesiones PHP, hash de contraseñas Bcrypt, tokens CSRF, consultas preparadas (PDO) |

**Características de la arquitectura:**
- Aplicación monolítica liviana, sin dependencias externas pesadas → fácil de desplegar y mantener.
- API interna en JSON para disponibilidad de horarios y registro de reservas.
- Separación de responsabilidades: configuración, sesión, funciones y vistas en módulos reutilizables.
- Base de datos relacional normalizada con integridad referencial (llaves foráneas).

---

## 4. Módulos funcionales

### 4.1 Sitio público / Landing
Página de presentación del centro: identidad de marca, servicios destacados, galería e
invitación a reservar. Punto de entrada del cliente final.

### 4.2 Reserva en línea (asistente de 4 pasos)
Flujo guiado para que el cliente agende sin fricción:
1. **Servicio** — selección de uno o varios servicios; cálculo automático de duración y total.
2. **Horario** — calendario semanal con disponibilidad real (muestra ocupado / cerrado / libre).
3. **Datos** — nombre, teléfono/WhatsApp, correo (opcional) y nota.
4. **Confirmación** — resumen y envío. La cita queda **pendiente de confirmación**.

> La reserva crea automáticamente el registro del cliente y la cita en el sistema.

### 4.3 Autenticación y seguridad de acceso
Inicio de sesión por correo y contraseña, con sesiones seguras, protección CSRF y
redirección por rol. Cierre de sesión y control de acceso en cada página privada.

### 4.4 Panel — Dashboard
Vista de inicio con **indicadores en tiempo real**: citas de hoy, pendientes por confirmar,
citas de la semana e ingresos del mes, más una tabla de **solicitudes recientes** con
acciones rápidas.

### 4.5 Gestión de citas
Corazón operativo del sistema:
- Listado con **filtros** por estado, fecha y búsqueda por cliente/teléfono, con paginación.
- Ciclo de vida de la cita: **Pendiente → Confirmada → Completada / Cancelada**.
- **Confirmar** y **asignar profesional**; **completar** (cierra la cita) y **cancelar**.
- Al **completar**, el sistema **registra automáticamente el ingreso** en finanzas.
- Vista de detalle con servicios, horario, profesional, total y notas.

### 4.6 Servicios y categorías
CRUD completo:
- Categorías de servicios (corporales, faciales, láser, spa, terapias…).
- Servicios con nombre, descripción, **duración** y **precio**.
- Activar / desactivar y eliminar (con protección si están en uso).

### 4.7 Clientes (mini-CRM)
- Listado con **historial**: número de citas, citas completadas y última visita.
- Alta y edición manual; búsqueda por nombre, teléfono o correo.
- Notas por cliente (alergias, preferencias) y **enlace directo a WhatsApp**.

### 4.8 Finanzas
- Registro de **ingresos y egresos** por categoría, con fecha y descripción.
- **Resumen mensual**: total de ingresos, egresos y **balance**.
- Filtros por mes y por tipo de movimiento.
- Integración automática con las citas completadas.

### 4.9 Galería
Carga y eliminación de imágenes del centro (tratamientos, instalaciones) que se muestran
en el sitio público. Validación de tipo y tamaño de archivo.

### 4.10 Configuración y equipo
- **Perfil**: editar nombre, correo, teléfono.
- **Seguridad**: cambio de contraseña con verificación.
- **Equipo**: crear, editar, activar/desactivar usuarios y asignar rol (admin / staff).

---

## 5. Requisitos funcionales (RF)

| ID | Requisito |
|---|---|
| RF-01 | El visitante puede consultar servicios, precios y duración sin iniciar sesión. |
| RF-02 | El visitante puede reservar una cita seleccionando uno o varios servicios. |
| RF-03 | El sistema calcula automáticamente la duración y el precio total de la reserva. |
| RF-04 | El sistema muestra la disponibilidad real de horarios y bloquea los ocupados. |
| RF-05 | Al reservar, el sistema crea/asocia el cliente y registra la cita como *pendiente*. |
| RF-06 | El administrador inicia sesión de forma segura y accede a un panel privado. |
| RF-07 | El sistema restringe el acceso a las páginas privadas según el rol del usuario. |
| RF-08 | El administrador visualiza indicadores del negocio en un dashboard. |
| RF-09 | El administrador filtra, busca y pagina el listado de citas. |
| RF-10 | El administrador confirma, asigna profesional, completa o cancela una cita. |
| RF-11 | Al completar una cita, el sistema registra el ingreso correspondiente. |
| RF-12 | El administrador gestiona (CRUD) servicios y categorías. |
| RF-13 | El administrador gestiona (CRUD) clientes y consulta su historial. |
| RF-14 | El administrador registra y consulta ingresos y egresos con resumen mensual. |
| RF-15 | El administrador sube y elimina imágenes de la galería pública. |
| RF-16 | El administrador edita su perfil y cambia su contraseña. |
| RF-17 | El administrador crea y administra usuarios del equipo y sus roles. |
| RF-18 | El sistema permite contactar al cliente por WhatsApp con un clic. |
| RF-19 | El staff accede a su agenda y sus citas asignadas. *(roadmap)* |

---

## 6. Requisitos no funcionales (RNF)

### Seguridad
- RNF-01 — Contraseñas almacenadas con hash **Bcrypt** (nunca en texto plano).
- RNF-02 — Protección contra **CSRF** en todas las acciones que modifican datos.
- RNF-03 — Prevención de **inyección SQL** mediante consultas preparadas (PDO).
- RNF-04 — Escape de salida para prevenir **XSS**.
- RNF-05 — Control de acceso por rol y sesiones con cookies `HttpOnly`.

### Usabilidad
- RNF-06 — Interfaz **responsive** (computador, tablet y celular).
- RNF-07 — Flujo de reserva claro, en máximo 4 pasos.
- RNF-08 — Mensajes de confirmación y error comprensibles en español.

### Rendimiento
- RNF-09 — Tiempos de carga rápidos (sin frameworks pesados; consultas indexadas).
- RNF-10 — Paginación en listados para soportar grandes volúmenes de citas.

### Compatibilidad
- RNF-11 — Funciona en navegadores modernos (Chrome, Edge, Firefox, Safari).
- RNF-12 — Desplegable en cualquier hosting LAMP estándar (PHP + MySQL).

### Mantenibilidad y escalabilidad
- RNF-13 — Código modular y reutilizable, fácil de extender.
- RNF-14 — Base de datos normalizada con integridad referencial.
- RNF-15 — Configuración centralizada (un único punto para credenciales de BD).

### Disponibilidad y datos
- RNF-16 — Datos persistentes y respaldables mediante exportación de la BD.
- RNF-17 — Operación 24/7 para la reserva en línea (según disponibilidad del hosting).

---

## 7. Modelo de datos (resumen)

8 tablas relacionales:
`users` (administradores/staff), `categories`, `services`, `clients`,
`appointments`, `appointment_services` (relación cita↔servicios),
`finances` (ingresos/egresos), `staff_schedules` (horarios del profesional).

---

## 8. Estado actual del producto y hoja de ruta

### ✅ Construido y verificado (MVP — Fase 1)
Sitio público, reserva en línea, autenticación, dashboard, gestión de citas con registro
automático de ingresos, servicios, clientes, finanzas, galería y configuración de equipo.
**Probado de extremo a extremo:** reserva pública → cita pendiente → confirmar → completar →
ingreso reflejado en finanzas.

### 🚧 Próximas fases (oportunidades de ampliación)
- **Panel completo del Staff** (agenda personal, sus citas, su desempeño).
- **Recordatorios automáticos por WhatsApp** (hoy es un enlace manual + bandera de recordatorio).
- **Notificaciones por correo** al confirmar o cancelar.
- **Reportes avanzados** y exportación a Excel/PDF (ingresos por servicio, por profesional, etc.).
- **Pagos en línea / anticipos** (pasarela de pago).
- **Gestión de horarios y bloqueos** del personal desde el panel.
- **Programa de fidelización** (paquetes, bonos, descuentos).
- **Multi-sede** y soporte multi-usuario concurrente avanzado.

---

## 9. Beneficios para el cliente (resumen comercial)

- ⏱️ **Ahorra tiempo**: menos llamadas, menos agenda manual.
- 📈 **Vende más**: reservas 24/7, sin perder clientes por no contestar.
- 💵 **Controla el dinero**: ingresos, egresos y balance siempre a la vista.
- 🤝 **Fideliza**: historial de cada cliente y contacto directo por WhatsApp.
- 📱 **Profesionaliza la marca**: presencia digital propia y ordenada.
- 🔒 **Protege la información**: datos centralizados, seguros y respaldables.
