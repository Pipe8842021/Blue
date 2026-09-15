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

-- ============================================================
-- Pagos en línea (Wompi) — abono para agendar una cita
-- ------------------------------------------------------------
-- Una fila por intento de pago. La cita se crea en estado
-- 'pending' y su horario queda RESERVADO hasta `expires_at`;
-- si el pago no se aprueba antes, la cita se cancela sola y el
-- horario vuelve a quedar libre (ver liberarReservasVencidas()
-- en includes/h-pagos.php).
--
-- No se guarda ningún dato de tarjeta: Wompi solo nos devuelve
-- el id de la transacción y el medio de pago usado.
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `appointment_id`  INT UNSIGNED  NOT NULL,
    `provider`        VARCHAR(20)   NOT NULL DEFAULT 'wompi',
    `environment`     ENUM('test','prod') NOT NULL DEFAULT 'test',
    `reference`       VARCHAR(64)   NOT NULL COMMENT 'Referencia única enviada a la pasarela',
    `amount`          DECIMAL(10,2) NOT NULL COMMENT 'Abono cobrado',
    `service_total`   DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total de los servicios de la cita',
    `currency`        CHAR(3)       NOT NULL DEFAULT 'COP',
    `status`          ENUM('pending','approved','declined','voided','error','expired')
                      NOT NULL DEFAULT 'pending',
    `transaction_id`  VARCHAR(64)   NULL COMMENT 'Id de la transacción en Wompi',
    `payment_method`  VARCHAR(40)   NULL COMMENT 'CARD, NEQUI, PSE, BANCOLOMBIA_TRANSFER…',
    `expires_at`      DATETIME      NOT NULL COMMENT 'Hasta cuándo se le guarda el horario al cliente',
    `paid_at`         DATETIME      NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payments_reference`   (`reference`),
    UNIQUE KEY `uq_payments_transaction` (`transaction_id`),
    KEY `idx_payments_appointment` (`appointment_id`),
    KEY `idx_payments_status`      (`status`),
    KEY `idx_payments_expires`     (`status`, `expires_at`),
    CONSTRAINT `fk_payments_appointment`
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Los abonos aprobados se registran solos como ingreso. Como no
-- los registra ninguna persona del equipo, `registered_by` pasa
-- a admitir NULL (en el listado se muestran como "Pago en línea").
-- ------------------------------------------------------------
ALTER TABLE `finances` MODIFY COLUMN `registered_by` INT UNSIGNED NULL;

-- ============================================================
-- Vista de estado de la cita + pago del saldo
-- ------------------------------------------------------------
-- `payments` pasa a guardar dos cosas:
--   kind = 'deposit' → abono en línea (Wompi), el que separa la cita
--   kind = 'balance' → saldo cobrado en el centro (efectivo, Nequi…),
--                      registrado a mano por el personal
-- Por eso `expires_at` deja de ser obligatorio (un pago en mostrador no
-- vence) y se agrega `received_by`: a quién del equipo se le pagó.
-- NOTA: "IF NOT EXISTS" en ALTER es sintaxis de MariaDB, igual que en
-- las demás migraciones del proyecto (XAMPP trae MariaDB).
-- ============================================================

ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `kind` ENUM('deposit','balance') NOT NULL DEFAULT 'deposit'
        COMMENT 'deposit = abono en línea · balance = saldo cobrado en el centro' AFTER `provider`;

ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `received_by` INT UNSIGNED NULL
        COMMENT 'Persona del equipo que recibió el pago' AFTER `payment_method`;

ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `note` VARCHAR(255) NULL AFTER `received_by`;

-- Un pago en mostrador no tiene fecha de vencimiento.
ALTER TABLE `payments` MODIFY COLUMN `expires_at` DATETIME NULL
    COMMENT 'Solo para abonos en línea: hasta cuándo se guarda el horario';

-- En MariaDB el "IF NOT EXISTS" de una clave foránea va después de FOREIGN KEY.
ALTER TABLE `payments`
    ADD CONSTRAINT `fk_payments_received_by`
        FOREIGN KEY IF NOT EXISTS (`received_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `payments` ADD INDEX IF NOT EXISTS `idx_payments_kind` (`kind`);

-- ------------------------------------------------------------
-- Enlace privado con el que el cliente consulta su cita.
-- Es una fila aparte (y no una columna en `appointments`) para no
-- tocar la tabla del módulo de Agenda; borrar la fila invalida el
-- enlace sin perder la cita.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `appointment_links` (
    `appointment_id` INT UNSIGNED NOT NULL,
    `token`          CHAR(32)     NOT NULL COMMENT 'Clave aleatoria del enlace público',
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`appointment_id`),
    UNIQUE KEY `uq_appointment_links_token` (`token`),
    CONSTRAINT `fk_appointment_links_appointment`
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Aviso por correo al cliente (estado de la reserva y del pago)
-- ------------------------------------------------------------
-- Igual que `whatsapp_reminder`, pero para correo: el cliente decide en
-- el wizard si quiere que le avisemos por email. Sin este campo en 1,
-- nunca se envía nada aunque haya correo cargado en `clients`.
-- ============================================================
ALTER TABLE `appointments`
    ADD COLUMN IF NOT EXISTS `email_reminder` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = el cliente pidió que le avisemos por correo' AFTER `whatsapp_reminder`;
