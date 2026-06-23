-- ============================================================
-- Migración 01 — Catálogo y Clientes  ·  Responsable: Ana
-- Módulos: Clientes · Servicios · Galería
-- ------------------------------------------------------------
-- Cómo correrla en tu MySQL local (XAMPP):
--   mysql -u root blue_db < database/migrations/01_catalogo.sql
-- o pégala en phpMyAdmin (base de datos: blue_db).
--
-- Reglas:
--   - Aquí van TODOS tus cambios de estructura (ALTER/CREATE/INSERT).
--   - Usa "IF NOT EXISTS" cuando puedas para poder re-ejecutarla sin error.
--   - NO edites database/blue.sql directo; al final consolidamos todo.
-- ============================================================

USE blue_db;

-- Ejemplo (descomenta y ajusta cuando lo necesites):
-- ALTER TABLE services ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL AFTER description;
-- ALTER TABLE services ADD COLUMN IF NOT EXISTS featured TINYINT(1) NOT NULL DEFAULT 0;
