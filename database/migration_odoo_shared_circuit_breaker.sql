-- Migration: Shared Odoo circuit breaker state table
-- ใช้ร่วมกันระหว่าง PHP stack และ Node.js backend
-- ทั้งสองฝั่งอ่าน/เขียน table นี้ เพื่อให้ circuit breaker เห็นสถานะเดียวกัน
--
-- Storage engine: InnoDB (row-level lock สำหรับ ON DUPLICATE KEY UPDATE)
-- TTL: ไม่มี — row มีแค่ 1 row ต่อ service_name, ON DUPLICATE KEY UPDATE

CREATE TABLE IF NOT EXISTS `odoo_circuit_breaker_state` (
  `service_name`         VARCHAR(64)                            NOT NULL,
  `status`               ENUM('closed','open','half_open')      NOT NULL DEFAULT 'closed',
  `consecutive_failures` SMALLINT UNSIGNED                      NOT NULL DEFAULT 0,
  `opened_at`            INT UNSIGNED                           NULL     COMMENT 'unix timestamp ที่ circuit เปิด',
  `half_open_attempts`   TINYINT UNSIGNED                       NOT NULL DEFAULT 0,
  `last_failure_at`      INT UNSIGNED                           NULL,
  `last_success_at`      INT UNSIGNED                           NULL,
  `last_error`           VARCHAR(200)                           NULL,
  `updated_at`           TIMESTAMP                              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shared Odoo circuit breaker state — PHP + Node.js stacks';

-- Seed default row so reads always return a result without INSERT overhead
INSERT IGNORE INTO `odoo_circuit_breaker_state`
  (`service_name`, `status`, `consecutive_failures`, `last_success_at`)
VALUES
  ('odoo_api', 'closed', 0, UNIX_TIMESTAMP());
