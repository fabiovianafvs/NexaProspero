-- Nexa Próspero - Ordem de Serviço v1.13
-- Modo Auto Peças / Oficina + mapa de avarias.
-- Preferencial: php workers/migrate_ordem_servico_v1.php

CREATE TABLE IF NOT EXISTS os_vehicle_damage_points (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  position_x DECIMAL(7,4) NOT NULL,
  position_y DECIMAL(7,4) NOT NULL,
  damage_type VARCHAR(30) NOT NULL DEFAULT 'AVARIA',
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_os_vehicle_damage_order (company_id, order_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A coluna os_settings.auto_parts_mode é criada automaticamente pelo worker/schema runtime.
