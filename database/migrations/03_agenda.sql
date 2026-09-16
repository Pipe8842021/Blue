-- ============================================================
-- Migración 03 — Agenda y Tablero  ·  Responsable: Felipe
-- Módulos: Citas · Dashboard · Panel Staff
-- ------------------------------------------------------------
-- Cómo correrla en tu MySQL local (XAMPP):
--   mysql -u root blue_db < database/migrations/03_agenda.sql
-- o pégala en phpMyAdmin (base de datos: blue_db).
--
-- Reglas:
--   - Aquí van TODOS tus cambios de estructura (ALTER/CREATE/INSERT).
--   - Usa "IF NOT EXISTS" cuando puedas para poder re-ejecutarla sin error.
--   - NO edites database/blue.sql directo; al final consolidamos todo.
-- ============================================================

USE blue_db;

-- Ejemplo (descomenta y ajusta cuando lo necesites):
-- ALTER TABLE appointments ADD COLUMN IF NOT EXISTS reminded_at DATETIME NULL;

-- ============================================================
-- Agendas por profesional (tarea 5)
-- ------------------------------------------------------------
-- Cada profesional (rol staff, activo) tiene su horario semanal en
-- `staff_schedules` (ya existía en blue.sql) y puede limitar qué
-- servicios realiza en `staff_services`:
--   · sin filas para esa profesional → hace TODOS los servicios
--   · con filas → solo los servicios listados
--
-- Mientras ninguna profesional staff tenga horario, la reserva en
-- línea funciona en "modo general" (un solo cupo, Lun–Sáb 8:00–19:00).
-- Los administradores no aparecen en la reserva en línea.
-- ============================================================
CREATE TABLE IF NOT EXISTS staff_schedules (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT UNSIGNED     NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Dom, 1=Lun, ..., 6=Sab',
    time_start  TIME             NOT NULL,
    time_end    TIME             NOT NULL,
    active      TINYINT(1)       NOT NULL DEFAULT 1,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE staff_schedules ADD INDEX IF NOT EXISTS idx_staff_schedules_dia (staff_id, day_of_week);

CREATE TABLE IF NOT EXISTS staff_services (
    staff_id   INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    KEY idx_staff_services_service (service_id),
    CONSTRAINT fk_staff_services_staff
        FOREIGN KEY (staff_id)   REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_staff_services_service
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La disponibilidad consulta las citas de un día por profesional.
ALTER TABLE appointments ADD INDEX IF NOT EXISTS idx_appointments_fecha_staff (date, staff_id);
