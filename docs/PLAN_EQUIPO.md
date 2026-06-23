# Blue Therapy — Plan de Trabajo en Equipo
### Mejora estética y funcional del sistema · Guía para Ana, Jhonatan y Felipe

> Objetivo de esta etapa: **pulir el diseño de todos los módulos**, dejarlos **más completos**
> y **agregar funciones útiles y sencillas** (dentro del alcance), trabajando **los tres a la vez**
> sin que el trabajo de uno dependa del de otro.

---

## 1. Introducción — ¿De qué va el proyecto?

**Blue Therapy** es una aplicación web para administrar un **centro de estética / spa / terapias**.
En una sola plataforma reúne tres cosas:

1. **Reservas en línea** — el cliente entra a la página, elige servicio y horario, y agenda solo (24/7).
2. **Panel administrativo** — el centro confirma citas, gestiona clientes, servicios y galería.
3. **Control financiero** — registra ingresos y egresos y muestra el balance del mes.

**Tecnología:** PHP 8 + MySQL + Apache (XAMPP en local), con HTML, CSS y JavaScript propios.
No usamos frameworks pesados, así que es fácil de entender y modificar.

**Estado actual:** el sistema **ya funciona de punta a punta** (reservar → confirmar → completar →
ingreso registrado). Lo que sigue ahora es **hacerlo más bonito, más completo y con mejores funciones**.

**Cómo está organizado el código (para ubicarse):**
```
Blue/
├── index.php            ← página pública (landing)
├── booking.php          ← reserva en línea (asistente de 4 pasos)
├── login.php            ← inicio de sesión
├── admin/               ← panel administrativo
│   ├── index.php        (dashboard)      ├── clients.php   (clientes)
│   ├── appointments.php (citas)          ├── finances.php  (finanzas)
│   ├── services.php     (servicios)      ├── gallery.php   (galería)
│   └── settings.php     (configuración)
├── staff/index.php      ← panel del profesional (por construir)
├── api/                 ← disponibilidad y registro de reservas
├── assets/css|js|img    ← estilos, scripts e imágenes
├── includes/            ← sesión, funciones y plantilla del panel (COMPARTIDO)
├── config/db.php        ← conexión a la base de datos (COMPARTIDO)
└── database/blue.sql    ← estructura de la base de datos
```

---

## 2. Reglas de oro para trabajar en paralelo (¡importante!)

Para que **nadie dañe el trabajo del otro** y podamos avanzar al mismo tiempo:

1. **Cada quien su rama de Git.** Nadie trabaja en `main`. Cada rama **sale de `main`**:
   - Ana → `rama_ana`
   - Jhonatan → `rama_jhonatan`
   - Felipe → `rama_felipe`
   - (Las tres ya están creadas en GitHub con el contenido de `main`.)

2. **Cada quien edita SOLO sus archivos.** Más abajo cada uno tiene su lista exacta. Si dos
   personas editan el mismo archivo, hay conflictos → eso es lo que queremos evitar.

3. **No tocar los archivos COMPARTIDOS** durante el trabajo individual:
   `config/db.php`, `includes/session.php`, `includes/functions.php`, `includes/auth.php`,
   `includes/admin_layout.php`, `includes/admin_footer.php`, `assets/css/admin.css`,
   `assets/css/global.css`. (Esos se ajustan una sola vez en la Fase 0.)

4. **¿Necesitas un estilo nuevo?** Crea **tu propio archivo CSS** y no toques `admin.css`:
   - Ana → `assets/css/m-catalogo.css`
   - Jhonatan → `assets/css/m-finanzas.css`
   - Felipe → `assets/css/m-agenda.css`
   Solo **agregas** estilos en tu archivo; los estilos base ya existen en `admin.css`.

5. **¿Necesitas una función PHP nueva (helper)?** Créala en un archivo nombrado por **lo que hace**
   (no por la persona), y no en el compartido: `includes/h-agenda.php`, `includes/h-catalogo.php`,
   `includes/h-finanzas.php`. Cada función debe tener un **nombre descriptivo de su tarea**
   (ej. `calcularTotalCita()`, `formatearTelefonoWhatsapp()`, `exportarFinanzasCsv()`).

6. **¿Necesitas cambiar la base de datos?** No edites `blue.sql`. Crea tu propio archivo de
   migración en `database/migrations/` y córrelo en tu MySQL local:
   - Ana → `database/migrations/01_catalogo.sql`
   - Jhonatan → `database/migrations/02_finanzas.sql`
   - Felipe → `database/migrations/03_agenda.sql`
   (Al final juntamos todo en `blue.sql`.)

7. **¿Imágenes o archivos nuevos?** Cada quien en su subcarpeta para no chocar
   (ej. `assets/img/servicios/`, `assets/img/galeria/`).

8. **Commits pequeños y seguido.** Antes de empezar cada día: `git pull` de tu rama.

> Si **todos respetan su lista de archivos**, podemos trabajar los tres al mismo tiempo sin problemas.

---

## 3. Cómo empezar a trabajar (Ana y Jhonatan)

> **La base ya está lista.** Felipe dejó preparado todo lo común: las ramas, la carpeta de
> migraciones, los archivos CSS de cada módulo y el "gancho" para cargarlos. **Ustedes no tienen
> que configurar nada de eso**, solo seguir estos pasos y ponerse a trabajar en su módulo.

### Paso a paso (la primera vez)

1. **Tener XAMPP** con Apache y MySQL encendidos, y el proyecto en `c:\xampp\htdocs\Blue`.
2. **Crear la base de datos:** abrir phpMyAdmin → importar `database/blue.sql` (crea `blue_db`
   con los datos de ejemplo). Usuario MySQL: `root`, sin contraseña.
3. **Traer el proyecto y cambiarse a tu rama:**
   ```bash
   git clone https://github.com/Pipe8842021/Blue.git
   cd Blue
   git checkout rama_ana        # Ana   (Jhonatan usa: git checkout rama_jhonatan)
   ```
4. **Probar que corre:** abrir `http://localhost/Blue/login.php` e ingresar con
   `admin@blue.com` / `admin123`.
5. **Cada día antes de empezar:** `git pull` de tu rama. Al terminar: `git add` + `git commit` + `git push`.

### Lo PRIMERO que debes decirle a Claude Code

Cuando abras Claude Code en el proyecto, **preséntate** para que sepa quién eres y respete tus
archivos. Copia y pega este mensaje (cambia los datos según seas Ana o Jhonatan):

> **Para Ana:**
> "Hola. Soy **Ana**, del equipo de Blue Therapy. Trabajo en la rama **`rama_ana`** y me encargo
> de los módulos **Clientes, Servicios y Galería**. Antes de proponer cambios, lee el `CLAUDE.md`
> y `docs/PLAN_EQUIPO.md`. Edita **solo mis archivos**: `admin/clients.php`, `admin/services.php`,
> `admin/gallery.php`, mi CSS `assets/css/m-catalogo.css` y mi migración
> `database/migrations/01_catalogo.sql`. **No toques** archivos compartidos ni de otros compañeros."

> **Para Jhonatan:**
> "Hola. Soy **Jhonatan**, del equipo de Blue Therapy. Trabajo en la rama **`rama_jhonatan`** y me
> encargo de los módulos **Reserva en línea, Finanzas y Login/Configuración**. Antes de proponer
> cambios, lee el `CLAUDE.md` y `docs/PLAN_EQUIPO.md`. Edita **solo mis archivos**: `booking.php`,
> `assets/js/booking.js`, `assets/css/booking.css`, `admin/finances.php`, `login.php`,
> `admin/settings.php`, mi CSS `assets/css/m-finanzas.css` y mi migración
> `database/migrations/02_finanzas.sql`. **No toques** archivos compartidos ni de otros compañeros."

> Claude Code lee el `CLAUDE.md` automáticamente, pero **necesita que le digas quién eres** para
> saber cuáles son "tus" archivos. Con ese mensaje queda contextualizado y trabaja sin pisar a nadie.

### Para cargar tu CSS en una página del panel
Tu archivo CSS ya existe; solo agrégalo arriba de tu página (antes de incluir el layout):
```php
$extraCss = ['/Blue/assets/css/m-catalogo.css'];   // Ana (Jhonatan: m-finanzas.css)
require_once __DIR__ . '/../includes/admin_layout.php';
```

---

## 4. Reparto de tareas (equitativo, 3 módulos por persona)

Cada persona tiene **3 áreas**, una "pesada", una "media" y una "ligera", para que el esfuerzo sea parejo.

---

### 👤 FELIPE — "Agenda y Tablero" (núcleo operativo)

| Área | De qué trata | Mejoras estéticas | Funciones nuevas (sencillas, en alcance) |
|---|---|---|---|
| **Citas** *(pesada)* | Pantalla donde el centro ve, filtra y administra todas las citas y su estado | Tabla más limpia, chips de filtro, estados con color, detalle enriquecido | Vista de **calendario semanal**, botón **"recordar por WhatsApp"** (enlace prellenado), **imprimir cita** |
| **Dashboard** *(media)* | Pantalla de inicio del panel con los indicadores clave del negocio | Tarjetas rediseñadas, saludo con fecha | **Mini-gráfico** de citas por día (Chart.js por CDN), lista de **próximas citas**, **Top 5 servicios** del mes |
| **Panel Staff** *(ligera)* | Vista del profesional con su agenda y las citas que le asignaron | Construir la vista desde cero con el estilo del panel | **Agenda del día** del profesional con sus citas asignadas |

**Tu CSS y migración:** `assets/css/m-agenda.css` · `database/migrations/03_agenda.sql`.

---

### 👤 ANA — "Catálogo y Clientes"

| Área | De qué trata | Mejoras estéticas | Funciones nuevas (sencillas, en alcance) |
|---|---|---|---|
| **Clientes** *(pesada)* | Listado y ficha de cada cliente con su historial e información de contacto | Ficha de cliente más visual, avatares, tabla pulida | **Ficha detallada** con historial de citas (timeline), **total gastado**, **etiquetas** (VIP / Nuevo / Frecuente) |
| **Servicios** *(media)* | Catálogo de servicios y categorías que ofrece el centro | Tarjetas de servicio con imagen, mejor organización por categoría | **Imagen por servicio**, marcar servicio como **"destacado/popular"**, **filtro por categoría** |
| **Galería** *(ligera)* | Imágenes del centro que se muestran en el sitio público | Grid más bonito, estados de carga | **Lightbox** al hacer clic (ver imagen grande), **agrupar por categoría** |

**Tu CSS y migración:** `assets/css/m-catalogo.css` · `database/migrations/01_catalogo.sql`.

---

### 👤 JHONATAN — "Finanzas, Reservas y Acceso"

| Área | De qué trata | Mejoras estéticas | Funciones nuevas (sencillas, en alcance) |
|---|---|---|---|
| **Reserva en línea** *(pesada)* | Asistente público donde el cliente agenda su cita paso a paso | Pasos más pulidos, resumen "pegado" al lado, confirmación más bonita | **Validación en vivo** de los campos, **selección visual** de servicios, **barra de progreso** mejorada |
| **Finanzas** *(media)* | Registro de ingresos y egresos con el resumen y balance del mes | Tarjetas de resumen más claras, colores por tipo | **Gráfico ingresos vs egresos** (Chart.js por CDN), **filtro por rango de fechas**, **exportar a CSV** |
| **Login + Configuración** *(ligera)* | Acceso al sistema y ajustes de perfil y del equipo | Pulir formularios, validaciones visuales | **Mostrar/ocultar contraseña**, **avatar/foto** de usuario, validación de fortaleza de contraseña |

**Tu CSS y migración:** `assets/css/m-finanzas.css` · `database/migrations/02_finanzas.sql`.

---

### Reglas para nombrar tus archivos nuevos
Las páginas que ya existen se editan tal cual. Si **creas un archivo nuevo**, nómbralo así:

- **Describe lo que hace, no quién lo hizo** (nada de `pagina_ana.php`).
- **Minúsculas, sin tildes ni espacios**; separa palabras con guion bajo (`reporte_servicios.php`).
- **Mantén el estilo de los que ya existen** (`appointments.php`, `clients.php`, `finances.php`…).
- **Helpers de PHP:** `includes/h-<tarea>.php` (ej. `h-agenda.php`) y cada función con nombre
  descriptivo (`calcularTotalCita()`, `exportarFinanzasCsv()`).
- **Imágenes:** en tu subcarpeta propia dentro de `assets/img/` (ej. `assets/img/servicios/`).
- **Tu CSS y tu migración ya están creados** (ver el recuadro de cada persona): solo agregas dentro.

> **Nota:** ninguno de los tres edita la **landing pública** (`index.php`) ni `assets/css/admin.css`
> en esta etapa: la landing y el estilo base los pulimos **juntos en la Fase 0** para que todo el
> sitio mantenga una misma identidad visual.

---

## 5. Convenciones (para que todo combine)

- **Idioma:** todo en español (textos, mensajes, comentarios).
- **Estilo visual:** respetar la paleta y componentes acordados en Fase 0 (no inventar colores nuevos).
- **Mensajes al usuario:** usar el sistema de avisos que ya existe (`setFlash('success'|'error'|'info', ...)`).
- **Seguridad:** mantener el token CSRF en formularios y consultas preparadas (PDO) — ya hay ejemplos en cada página.
- **Commits:** mensajes claros, ej. `feat(citas): vista calendario semanal` / `style(clientes): ficha detallada`.
- **Librerías externas:** solo por **CDN** y solo si es sencillo (ej. Chart.js para gráficos). Nada de instalaciones complejas.

---

## 6. "Terminado" significa (checklist por módulo)

Antes de decir "ya quedó", cada módulo debe cumplir:

- [ ] Se ve bien en **computador y celular** (responsive).
- [ ] **Sin errores de PHP** (`php -l archivo.php` no marca nada).
- [ ] Los formularios **validan** y muestran mensajes claros.
- [ ] **Probado en el navegador** (crear, editar, borrar, filtrar… según el caso).
- [ ] Coherente con el **diseño común** (colores, botones, tarjetas).
- [ ] Si cambiaste la base de datos, está en **tu archivo de migración**.

---

## 7. ¿Cómo juntamos todo al final?

1. Cada quien sube su rama (`git push`) y abre un **Pull Request** hacia `main`.
2. Revisamos entre todos rápidamente y fusionamos una por una (al estar en archivos distintos, casi no hay conflictos).
3. Consolidamos las 3 migraciones dentro de `database/blue.sql`.
4. Probamos el sistema completo en `main` ya integrado y dejamos esa versión como estable.

---

## 8. Resumen del reparto (vista rápida)

| Persona | Módulos | Archivo CSS propio | Migración propia |
|---|---|---|---|
| **Felipe** | Citas · Dashboard · Staff | `m-agenda.css` | `03_agenda.sql` |
| **Ana** | Clientes · Servicios · Galería | `m-catalogo.css` | `01_catalogo.sql` |
| **Jhonatan** | Reservas · Finanzas · Login/Config | `m-finanzas.css` | `02_finanzas.sql` |

**Conjunto (Fase 0):** sistema de diseño + landing + ajustes base compartidos.
