# CLAUDE.md — Blue Therapy

Guía obligatoria para Claude Code en este proyecto. Se aplica a todo el equipo (Ana, Jhonatan, Felipe).
Documentos ampliados: `docs/RESUMEN_SISTEMA.md` (qué hace el sistema) y `docs/PLAN_EQUIPO.md` (reparto de tareas).

## Qué es el proyecto
App web para administrar un centro de estética/spa. **Stack:** PHP 8 + MySQL + Apache (XAMPP),
con HTML/CSS/JS propios (sin frameworks). Idioma del producto y del código: **español**.

## Cómo trabajamos (reglas para no pisarnos)
Trabajamos los tres en paralelo, cada uno en su rama y **solo en sus archivos**.

| Persona | Rama (sale de `main`) | Módulos | CSS propio | Migración propia |
|---|---|---|---|---|
| **Felipe** | `rama_felipe` | Citas · Dashboard · Staff | `assets/css/m-agenda.css` | `database/migrations/03_agenda.sql` |
| **Ana** | `rama_ana` | Clientes · Servicios · Galería | `assets/css/m-catalogo.css` | `database/migrations/01_catalogo.sql` |
| **Jhonatan** | `rama_jhonatan` | Reservas · Finanzas · Login/Config | `assets/css/m-finanzas.css` | `database/migrations/02_finanzas.sql` |

- **NO editar archivos compartidos:** `config/db.php`, `includes/session.php`, `includes/functions.php`,
  `includes/auth.php`, `includes/admin_layout.php`, `includes/admin_footer.php`,
  `assets/css/admin.css`, `assets/css/global.css`. (Solo se cambian en acuerdo de equipo.)
- Estilos nuevos → **tu** archivo `m-*.css` (solo agregar; nunca editar `admin.css`).
- Funciones PHP nuevas → un helper nombrado por **lo que hace** (no por la persona):
  `includes/h-agenda.php`, `includes/h-catalogo.php`, `includes/h-finanzas.php`, etc.
  Cada función con **nombre descriptivo** de su tarea (ej. `calcularTotalCita()`, `formatearTelefonoWhatsapp()`).
  Nunca tocar el `functions.php` compartido.
- Cambios de BD → tu archivo en `database/migrations/`, **nunca** editar `blue.sql` directo.
- Antes de proponer un cambio, verifica que el archivo pertenezca a la persona de esta sesión.

## Sistema de diseño (NO inventar colores ni fuentes)
Usar **siempre las variables CSS existentes**, no escribir hex sueltos.

**Fuentes:** títulos en `'Playfair Display', serif`; texto en `'DM Sans', system-ui, sans-serif`.

**Color de marca (turquesa del logo):**
- Público (`global.css`): `--cyan-300: #5bc4d0` (color exacto del logo), escala `--cyan-50…950`.
- Panel (`admin.css`): `--teal: #5bc4b8`, `--teal-dark: #3aa89e`, `--teal-light`.

**Acento dorado (premium):** `--gold` (público `#c9a84c`, panel `#c8a96e`) — usar con moderación.

**Oscuros / sidebar:** `--dark-900: #0d1214` (público), sidebar del panel `#0d1117`.

**Estados (semáforo):** pendiente `--pending #f59e0b` · confirmado/éxito `--confirmed #10b981` ·
completado `--completed #6366f1` · cancelado/error `--cancelled #ef4444`.

**Tokens de forma:** `--radius: 12px`, `--shadow`, `--shadow-sm`, transición `--t: .22s ease`.

**Componentes ya disponibles** (reutilizar, no recrear): `.btn`/`.btn-primary`/`.btn-danger`/`.btn-ghost`,
`.card`/`.card-header`/`.card-body`, `.data-table`, `.badge badge-{estado}`, `.modal-overlay`/`.modal`,
`.form-grid`/`.form-control`, `.tabs`/`.tab`, `.pagination`, `.summary-card`, `.empty-state`,
`.flash flash-{success|error|info}`, `.pill`. Ver `assets/css/admin.css`.

## Estética: qué buscamos
- Limpio, profesional y femenino/premium (estética spa), con mucho aire (espaciado generoso).
- Responsive siempre (probar en celular y computador).
- Consistencia entre los 3 módulos: mismos botones, tarjetas, tipografía y radios.
- Microinteracciones sutiles (hover, transiciones suaves). Sin animaciones exageradas.
- Iconos: SVG en línea con `stroke="currentColor"` (como los del sidebar), no librerías de iconos.
- Librerías externas solo por **CDN** y solo si son simples (ej. Chart.js para gráficos).

## Prácticas de código (obligatorias)
- **Seguridad:** consultas con **PDO preparado** (nunca concatenar SQL). Escapar toda salida con `e()`.
  Formularios que modifican datos llevan token CSRF: `csrfToken()` al imprimir, `verifyCsrf()` al recibir.
- **Mensajes al usuario:** `setFlash('success'|'error'|'info', '...')` + redirect tras un POST (patrón PRG).
- **Acceso:** páginas de admin empiezan con `requireRole('admin', '/Blue/login.php')`.
- **Rutas:** el proyecto vive en `/Blue/...` (incluir ese prefijo en links y `header('Location: ...')`).
- **Dinero:** mostrar con `formatPrice()`; fechas/horas con `formatDate()` / `formatTime()`.
- **Verificar antes de cerrar:** `php -l archivo.php` sin errores y prueba real en el navegador.
- Seguir el estilo del archivo que se edita (nombres, indentación, densidad de comentarios en español).

## Credenciales de desarrollo
Admin local: `admin@blue.com` / `admin123`. BD: `blue_db` en MySQL local (XAMPP, usuario `root` sin clave).

## Definición de "terminado"
Responsive ✓ · sin errores PHP ✓ · validaciones y flash ✓ · probado en navegador ✓ ·
coherente con el sistema de diseño ✓ · cambios de BD en tu migración ✓.
