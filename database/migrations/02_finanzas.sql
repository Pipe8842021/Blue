-- ============================================================
-- Migración 02 — Módulo Finanzas (Jhonatan)
-- Ejecutar UNA sola vez sobre blue_db
-- ============================================================

USE blue_db;

CREATE TABLE IF NOT EXISTS `finances` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `type`          ENUM('income','expense') NOT NULL DEFAULT 'income',
    `category`      VARCHAR(100)    NOT NULL,
    `description`   TEXT            NULL,
    `amount`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `date`          DATE            NOT NULL,
    `registered_by` INT UNSIGNED    NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_finances_date`          (`date`),
    KEY `idx_finances_type`          (`type`),
    KEY `idx_finances_registered_by` (`registered_by`),
    CONSTRAINT `fk_finances_user`
        FOREIGN KEY (`registered_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
