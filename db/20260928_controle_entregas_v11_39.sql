CREATE TABLE IF NOT EXISTS delivery_control_settings (
  company_id INT NOT NULL,
  require_delivery_confirmation TINYINT(1) NOT NULL DEFAULT 1,
  stock_out_on_order TINYINT(1) NOT NULL DEFAULT 1,
  fiscal_queue_enabled TINYINT(1) NOT NULL DEFAULT 1,
  allow_overdelivery TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_quote_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  quote_item_id BIGINT UNSIGNED NOT NULL,
  product_id INT NOT NULL,
  future_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_quote_item (company_id,quote_item_id),
  KEY idx_delivery_quote (company_id,quote_id),
  KEY idx_delivery_quote_product (company_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  quote_id BIGINT UNSIGNED NULL,
  pedido_id BIGINT UNSIGNED NOT NULL,
  person_id INT NULL,
  customer_name VARCHAR(180) NOT NULL,
  customer_document VARCHAR(40) NULL,
  customer_phone VARCHAR(40) NULL,
  delivery_zip_code VARCHAR(20) NULL,
  delivery_address VARCHAR(180) NULL,
  delivery_number VARCHAR(30) NULL,
  delivery_complement VARCHAR(120) NULL,
  delivery_district VARCHAR(100) NULL,
  delivery_city VARCHAR(100) NULL,
  delivery_state VARCHAR(2) NULL,
  delivery_reference VARCHAR(255) NULL,
  expected_date DATE NULL,
  expected_time TIME NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  notes TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_sale_order (company_id,pedido_id),
  KEY idx_delivery_sale_status (company_id,status,expected_date),
  KEY idx_delivery_sale_customer (company_id,person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_sale_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  delivery_sale_id BIGINT UNSIGNED NOT NULL,
  quote_item_id BIGINT UNSIGNED NULL,
  pedido_item_id BIGINT UNSIGNED NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  unit VARCHAR(20) NULL,
  unit_price DECIMAL(15,4) NOT NULL DEFAULT 0,
  sold_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  immediate_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  future_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  ordered_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  delivered_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  canceled_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_delivery_sale_item (company_id,delivery_sale_id,id),
  KEY idx_delivery_sale_product (company_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  delivery_sale_id BIGINT UNSIGNED NOT NULL,
  order_number BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'GERADA',
  scheduled_date DATE NULL,
  scheduled_time TIME NULL,
  delivery_zip_code VARCHAR(20) NULL,
  delivery_address VARCHAR(180) NULL,
  delivery_number VARCHAR(30) NULL,
  delivery_complement VARCHAR(120) NULL,
  delivery_district VARCHAR(100) NULL,
  delivery_city VARCHAR(100) NULL,
  delivery_state VARCHAR(2) NULL,
  delivery_reference VARCHAR(255) NULL,
  notes TEXT NULL,
  stock_out_at DATETIME NULL,
  delivered_at DATETIME NULL,
  canceled_at DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_order_number (company_id,order_number),
  KEY idx_delivery_order_status (company_id,status,scheduled_date),
  KEY idx_delivery_order_sale (company_id,delivery_sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  delivery_order_id BIGINT UNSIGNED NOT NULL,
  delivery_sale_item_id BIGINT UNSIGNED NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  unit VARCHAR(20) NULL,
  quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_order_items (company_id,delivery_order_id,id),
  KEY idx_delivery_order_sale_item (company_id,delivery_sale_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_sequences (
  company_id INT NOT NULL,
  last_order_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_manifest_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_vehicles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  description VARCHAR(120) NOT NULL,
  plate VARCHAR(20) NULL,
  driver_name VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_delivery_vehicle (company_id,active,description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_manifests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  manifest_number BIGINT UNSIGNED NOT NULL,
  manifest_date DATE NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ABERTO',
  carrier_person_id INT NULL,
  carrier_name_snapshot VARCHAR(180) NULL,
  vehicle_id BIGINT UNSIGNED NULL,
  vehicle_description_snapshot VARCHAR(120) NULL,
  vehicle_plate_snapshot VARCHAR(20) NULL,
  driver_name_snapshot VARCHAR(120) NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_manifest_number (company_id,manifest_number),
  KEY idx_delivery_manifest_date (company_id,manifest_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_manifest_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  manifest_id BIGINT UNSIGNED NOT NULL,
  delivery_order_id BIGINT UNSIGNED NOT NULL,
  route_sequence INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_manifest_order (company_id,manifest_id,delivery_order_id),
  KEY idx_delivery_manifest_route (company_id,manifest_id,route_sequence,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_fiscal_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  delivery_sale_id BIGINT UNSIGNED NOT NULL,
  delivery_order_id BIGINT UNSIGNED NULL,
  origin_type VARCHAR(20) NOT NULL,
  document_type VARCHAR(10) NOT NULL DEFAULT 'NFE',
  status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  fiscal_document_id BIGINT UNSIGNED NULL,
  shadow_pedido_id BIGINT UNSIGNED NULL,
  payload_json LONGTEXT NULL,
  last_error TEXT NULL,
  requested_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_delivery_fiscal_status (company_id,status,created_at),
  KEY idx_delivery_fiscal_order (company_id,delivery_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_control_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  user_id INT NULL,
  payload_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_events (company_id,entity_type,entity_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('delivery_control',20260928390,NOW(),'V11.39 - Controle de Entregas')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);
