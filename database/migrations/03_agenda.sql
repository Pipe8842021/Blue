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
