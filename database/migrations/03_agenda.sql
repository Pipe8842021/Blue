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

-- ============================================================
-- Cancelaciones y notificaciones internas (tarea 6)
-- ------------------------------------------------------------
-- Quién canceló la cita, cuándo y por qué. El cliente puede cancelar
-- desde su enlace con la anticipación mínima de config/negocio.php.
--   cancelled_by: cliente · equipo · sistema (pago vencido o rechazado)
-- ============================================================
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER notes;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancelled_by ENUM('cliente','equipo','sistema') NULL AFTER cancelled_at;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL AFTER cancelled_by;

-- Una fila por aviso y por persona del equipo (campana del panel).
-- `read_at` en NULL = sin leer. Si se borra la cita, se borran sus avisos.
CREATE TABLE IF NOT EXISTS notifications (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    type           VARCHAR(40)  NOT NULL COMMENT 'reserva_nueva · pago_aprobado · cita_cancelada · novedad',
    title          VARCHAR(150) NOT NULL,
    body           VARCHAR(500) NULL,
    url            VARCHAR(255) NULL,
    appointment_id INT UNSIGNED NULL,
    read_at        DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_usuario (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id)        REFERENCES users(id)        ON DELETE CASCADE,
    CONSTRAINT fk_notifications_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- WhatsApp: mensajes y conversaciones (tarea 1)
-- ------------------------------------------------------------
-- `wa_messages` guarda lo que entra y lo que sale, con el id que
-- asigna Meta para no procesar dos veces el mismo mensaje (Meta
-- reintenta los eventos del webhook).
-- `wa_conversations` recuerda en qué punto del menú va cada número.
-- ============================================================
CREATE TABLE IF NOT EXISTS wa_messages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction     ENUM('in','out') NOT NULL,
    phone         VARCHAR(20)   NOT NULL COMMENT 'Solo dígitos, con código de país',
    wa_message_id VARCHAR(64)   NULL COMMENT 'Id del mensaje en Meta (solo los entrantes)',
    body          VARCHAR(1000) NULL,
    template      VARCHAR(60)   NULL COMMENT 'Plantilla usada, si el saliente fue con plantilla',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wa_messages_meta (wa_message_id),
    KEY idx_wa_messages_phone (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wa_conversations (
    phone        VARCHAR(20) NOT NULL PRIMARY KEY COMMENT 'Solo dígitos, con código de país',
    state        VARCHAR(30) NOT NULL DEFAULT 'menu' COMMENT 'En qué paso del menú va',
    data         TEXT        NULL COMMENT 'Contexto del paso, en JSON',
    last_in_at   DATETIME    NULL,
    last_out_at  DATETIME    NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- «Otro servicio» en la reserva en línea (mejora 1 de la revisión 2)
-- ------------------------------------------------------------
-- El centro ofrece servicios pequeños que no están en el catálogo. El
-- cliente los describe con sus palabras y el texto queda en la cita:
--   · NULL  → la cita es de servicios del catálogo (lo normal)
--   · texto → la cita es solo «Otro servicio» (no lleva filas en
--             appointment_services, no tiene precio y no pasa por Wompi:
--             el equipo cotiza y confirma)
-- La duración que ocupa en la agenda sale de config/negocio.php.
-- ============================================================
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS other_service VARCHAR(300) NULL
    COMMENT 'Servicio fuera del catálogo descrito por el cliente' AFTER notes;

-- ============================================================
-- Color de cada profesional (mejora 2 de la revisión 2)
-- ------------------------------------------------------------
-- Cada persona del equipo tiene un color con el que se reconoce en los
-- calendarios, la lista de citas, la asignación y la reserva en línea.
-- Formato #rrggbb. Si queda en NULL, includes/h-colores.php le asigna uno
-- de la paleta (sin repetir mientras haya libres) la primera vez que se pide.
-- ============================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS color CHAR(7) NULL
    COMMENT 'Color de identificación en agendas y calendarios (#rrggbb)' AFTER photo;

-- ============================================================
-- Tienda de productos (mejora 3 de la revisión 2)
-- ------------------------------------------------------------
-- El centro vende productos además de servicios. El cliente arma su pedido
-- en tienda.php y lo paga con Wompi (mismo flujo que el abono de una cita);
-- el equipo lo prepara y, al marcarlo «Listo», el cliente recibe el aviso.
--
-- Estados del pedido (orders.status):
--   pending_payment → creado, falta el pago (o lo cobra el equipo a mano)
--   paid            → pagado, por preparar
--   preparing       → el equipo lo está alistando
--   ready           → listo para recoger  ← aquí se avisa al cliente
--   delivered       → entregado
--   cancelled       → cancelado (se devuelve el stock)
--
-- `delivery_type`: por ahora solo 'pickup' (recoger en el centro). El envío
-- a domicilio queda previsto en la BD y apagado hasta que el cliente lo defina.
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150)  NOT NULL,
    description TEXT          NULL,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    image       VARCHAR(255)  NULL COMMENT 'Archivo en assets/img/productos/',
    stock       INT           NULL COMMENT 'NULL = sin control de inventario; si no, unidades disponibles',
    active      TINYINT(1)    NOT NULL DEFAULT 1,
    featured    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = se muestra en la página de inicio',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_products_activo (active, featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id          INT UNSIGNED NOT NULL,
    status             ENUM('pending_payment','paid','preparing','ready','delivered','cancelled')
                       NOT NULL DEFAULT 'pending_payment',
    delivery_type      ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
    total              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes              VARCHAR(500)  NULL,
    token              CHAR(32)      NOT NULL COMMENT 'Clave del enlace privado con el que el cliente sigue su pedido',
    email_reminder     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = el cliente pidió avisos por correo',
    whatsapp_reminder  TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = el cliente pidió avisos por WhatsApp',
    paid_at            DATETIME NULL,
    preparing_at       DATETIME NULL,
    ready_at           DATETIME NULL,
    ready_notified_at  DATETIME NULL COMMENT 'Cuándo se le avisó al cliente que está listo',
    delivered_at       DATETIME NULL,
    cancelled_at       DATETIME NULL,
    cancel_reason      VARCHAR(255) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orders_token (token),
    KEY idx_orders_estado (status, created_at),
    CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada línea guarda nombre y precio del momento de la compra: si el producto
-- cambia de precio o se borra, el pedido conserva lo que el cliente pagó.
CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED  NOT NULL,
    product_id  INT UNSIGNED  NULL,
    name        VARCHAR(150)  NOT NULL,
    unit_price  DECIMAL(10,2) NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_order_items_pedido (order_id),
    CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de pago de un pedido (misma forma que `payments`, que es de las citas).
-- kind = 'online' (Wompi) o 'manual' (el equipo lo cobró por otro medio).
CREATE TABLE IF NOT EXISTS order_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED  NOT NULL,
    kind            ENUM('online','manual') NOT NULL DEFAULT 'online',
    environment     ENUM('test','prod') NOT NULL DEFAULT 'test',
    reference       VARCHAR(64)   NOT NULL COMMENT 'Referencia única enviada a la pasarela (PED-…)',
    amount          DECIMAL(10,2) NOT NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'COP',
    status          ENUM('pending','approved','declined','voided','error','expired') NOT NULL DEFAULT 'pending',
    transaction_id  VARCHAR(64)   NULL,
    payment_method  VARCHAR(40)   NULL,
    received_by     INT UNSIGNED  NULL COMMENT 'Quien del equipo cobró (pagos manuales)',
    expires_at      DATETIME      NULL COMMENT 'Hasta cuándo se le guarda el stock al cliente',
    paid_at         DATETIME      NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_payments_reference   (reference),
    UNIQUE KEY uq_order_payments_transaction (transaction_id),
    KEY idx_order_payments_pedido (order_id),
    KEY idx_order_payments_vence  (status, expires_at),
    CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id)    REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_payments_user  FOREIGN KEY (received_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los avisos de la campana pueden apuntar a un pedido (y no solo a una cita).
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS order_id INT UNSIGNED NULL AFTER appointment_id;
