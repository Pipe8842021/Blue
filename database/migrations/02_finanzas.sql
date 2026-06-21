-- ============================================================
-- Migración 02 — Finanzas, Reservas y Acceso  ·  Responsable: Jhonatan
-- Módulos: Reserva en línea · Finanzas · Login/Configuración
-- ------------------------------------------------------------
-- Cómo correrla en tu MySQL local (XAMPP):
--   mysql -u root blue_db < database/migrations/02_finanzas.sql
-- o pégala en phpMyAdmin (base de datos: blue_db).
--
-- Reglas:
--   - Aquí van TODOS tus cambios de estructura (ALTER/CREATE/INSERT).
--   - Usa "IF NOT EXISTS" cuando puedas para poder re-ejecutarla sin error.
--   - NO edites database/blue.sql directo; al final consolidamos todo.
-- ============================================================

USE blue_db;

-- Ejemplo (descomenta y ajusta cuando lo necesites):
-- ALTER TABLE finances ADD COLUMN IF NOT EXISTS color VARCHAR(20) NULL;
