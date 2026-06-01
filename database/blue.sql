-- ============================================================
-- Base de datos: Blue Therapy
-- ============================================================

CREATE DATABASE IF NOT EXISTS blue_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE blue_db;

-- ------------------------------------------------------------
-- Usuarios (admin y staff)
-- ------------------------------------------------------------
CREATE TABLE users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)          NOT NULL,
    email       VARCHAR(150)          NOT NULL UNIQUE,
    password    VARCHAR(255)          NOT NULL,
    role        ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    phone       VARCHAR(20),
    photo       VARCHAR(255),
    active      TINYINT(1)            NOT NULL DEFAULT 1,
    created_at  DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Categorías de servicios
-- ------------------------------------------------------------
CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    icon        VARCHAR(100),
    active      TINYINT(1)   NOT NULL DEFAULT 1
);

-- ------------------------------------------------------------
-- Servicios
-- ------------------------------------------------------------
CREATE TABLE services (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id   INT UNSIGNED      NOT NULL,
    name          VARCHAR(150)      NOT NULL,
    description   TEXT,
    duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Duración en minutos',
    price         DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
    active        TINYINT(1)        NOT NULL DEFAULT 1,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

-- ------------------------------------------------------------
-- Clientes (sin cuenta — solo datos de contacto)
-- ------------------------------------------------------------
CREATE TABLE clients (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150),
    phone       VARCHAR(20)  NOT NULL,
    notes       TEXT,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Citas
-- staff_id es NULL mientras la cita está pendiente de asignación
-- ------------------------------------------------------------
CREATE TABLE appointments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id        INT UNSIGNED  NOT NULL,
    staff_id         INT UNSIGNED  NULL COMMENT 'Se asigna al confirmar',
    date             DATE          NOT NULL,
    time_start       TIME          NOT NULL,
    time_end         TIME          NOT NULL,
    total_duration   SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Minutos totales de todos los servicios',
    status           ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    whatsapp_reminder TINYINT(1)   NOT NULL DEFAULT 0,
    notes            TEXT,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)  ON DELETE RESTRICT,
    FOREIGN KEY (staff_id)  REFERENCES users(id)     ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Servicios por cita (permite múltiples servicios por reserva)
-- ------------------------------------------------------------
CREATE TABLE appointment_services (
    appointment_id INT UNSIGNED NOT NULL,
    service_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (appointment_id, service_id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id)     REFERENCES services(id)     ON DELETE RESTRICT
);

-- ------------------------------------------------------------
-- Finanzas (ingresos y egresos)
-- ------------------------------------------------------------
CREATE TABLE finances (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type           ENUM('income','expense') NOT NULL,
    category       VARCHAR(100)             NOT NULL,
    description    VARCHAR(255),
    amount         DECIMAL(10,2)            NOT NULL,
    date           DATE                     NOT NULL,
    appointment_id INT UNSIGNED,
    registered_by  INT UNSIGNED             NOT NULL,
    created_at     DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (registered_by)  REFERENCES users(id)        ON DELETE RESTRICT
);

-- ------------------------------------------------------------
-- Horarios disponibles del staff
-- ------------------------------------------------------------
CREATE TABLE staff_schedules (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT UNSIGNED     NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Dom, 1=Lun, ..., 6=Sab',
    time_start  TIME             NOT NULL,
    time_end    TIME             NOT NULL,
    active      TINYINT(1)       NOT NULL DEFAULT 1,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Datos iniciales
-- ------------------------------------------------------------

-- Admin por defecto  →  email: admin@blue.com  /  password: admin123
INSERT INTO users (name, email, password, role) VALUES
('Administrador', 'admin@blue.com', '$2y$12$yE65XijGsSohQMzIb2u23O8NcTzZXOlIBEBMLVib5C41uiXesICWK', 'admin');

-- Categorías
INSERT INTO categories (name, description, icon) VALUES
('Tratamientos Corporales', 'Tratamientos para el cuerpo y silueta', 'body'),
('Tratamientos Faciales',   'Cuidado y estética del rostro',         'face'),
('Depilación Láser',        'Depilación con tecnología láser',       'laser'),
('Spa',                     'Servicios de relajación y bienestar',   'spa'),
('Terapias Biológicas',     'Terapias naturales y biológicas',       'therapy');

-- Servicios
INSERT INTO services (category_id, name, description, duration_min, price) VALUES
(1, 'Reducción de medidas',              'Tratamiento reductor corporal con tecnología avanzada', 60,  80000),
(1, 'Cavitación',                        'Reducción de grasa localizada',                         45,  70000),
(2, 'Limpieza facial profunda',          'Limpieza y purificación del rostro',                    60,  60000),
(2, 'Hidratación facial',                'Hidratación intensiva con activos',                     45,  55000),
(3, 'Depilación láser zonas pequeñas',   'Axilas, bikini, etc.',                                  30,  90000),
(3, 'Depilación láser piernas completas','Piernas completas',                                      60, 160000),
(4, 'Masaje relajante',                  'Masaje corporal de relajación',                         60,  80000),
(4, 'Masaje con piedras',                'Masaje con piedras calientes',                          75, 100000),
(5, 'Terapia neural',                    'Terapia de regulación biológica',                       45,  90000),
(5, 'Biomagnetismo',                     'Par biomagnético terapéutico',                          60,  95000);

-- Horario de ejemplo para el admin (Lun–Sáb 8:00–19:00)
INSERT INTO staff_schedules (staff_id, day_of_week, time_start, time_end) VALUES
(1, 1, '08:00:00', '19:00:00'),
(1, 2, '08:00:00', '19:00:00'),
(1, 3, '08:00:00', '19:00:00'),
(1, 4, '08:00:00', '19:00:00'),
(1, 5, '08:00:00', '19:00:00'),
(1, 6, '08:00:00', '19:00:00');
