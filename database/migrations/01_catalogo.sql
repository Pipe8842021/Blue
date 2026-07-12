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

-- ------------------------------------------------------------
-- Servicios: imagen y destacado
--   image    → nombre del archivo guardado en assets/img/servicios/
--              (solo el nombre, ej. svc_20260712_1a2b3c4d.jpg)
--   featured → 1 = servicio destacado/popular (badge en el listado)
-- ------------------------------------------------------------
ALTER TABLE services ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL AFTER description;
ALTER TABLE services ADD COLUMN IF NOT EXISTS featured TINYINT(1) NOT NULL DEFAULT 0 AFTER active;

-- ------------------------------------------------------------
-- Galería: una fila por imagen, con su categoría
--   file     → nombre del archivo en assets/img/gallery/
--   category → categoría libre de la galería (ej. Instalaciones,
--              Tratamientos, Antes y después). No es la categoría
--              de servicios: aquí también van fotos del local.
-- Las imágenes que ya estaban en disco se registran solas al abrir
-- la galería (quedan en "General" y se pueden recategorizar).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gallery (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file       VARCHAR(255) NOT NULL UNIQUE,
    category   VARCHAR(60)  NOT NULL DEFAULT 'General',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
