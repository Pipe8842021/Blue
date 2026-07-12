-- ============================================================
-- Migración 02 — Módulo Finanzas (Jhonatan)
-- Ejecutar UNA sola vez sobre blue_db
--
-- Corregida para que coincida con la tabla `finances` consolidada en
-- database/blue.sql (columna appointment_id, registered_by NOT NULL con
-- ON DELETE RESTRICT y DECIMAL(10,2)). La versión anterior de este archivo
-- había quedado desincronizada del esquema real.
-- ============================================================

USE blue_db;

CREATE TABLE IF NOT EXISTS `finances` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `type`           ENUM('income','expense') NOT NULL DEFAULT 'income',
    `category`       VARCHAR(100)    NOT NULL,
    `description`    VARCHAR(255)    NULL,
    `amount`         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    `date`           DATE            NOT NULL,
    `appointment_id` INT UNSIGNED    NULL,
    `registered_by`  INT UNSIGNED    NOT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_finances_date`          (`date`),
    KEY `idx_finances_type`          (`type`),
    KEY `idx_finances_registered_by` (`registered_by`),
    CONSTRAINT `fk_finances_appointment`
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_finances_user`
        FOREIGN KEY (`registered_by`) REFERENCES `users`(`id`)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
