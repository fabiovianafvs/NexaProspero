-- Nexa Próspero - Mapas de avarias dinâmicos por empresa.
-- Instalação controlada pelo Atualizador do Banco (platform_admin).
CREATE TABLE IF NOT EXISTS os_damage_templates (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'Outros',
  image_ref VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_damage_template_code (company_id, code),
  KEY idx_os_damage_templates_company (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilidade com pacote anterior: acrescenta categoria apenas se ausente.
SET @nexa_os_col_exists = (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='os_damage_templates' AND COLUMN_NAME='category'
);
SET @nexa_os_stmt = IF(@nexa_os_col_exists=0,
  'ALTER TABLE os_damage_templates ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT ''Outros'' AFTER name',
  'SELECT 1');
PREPARE nexa_os_m1 FROM @nexa_os_stmt;
EXECUTE nexa_os_m1;
DEALLOCATE PREPARE nexa_os_m1;

SET @nexa_os_col_exists = (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='os_settings' AND COLUMN_NAME='damage_map_type'
);
SET @nexa_os_stmt = IF(@nexa_os_col_exists=0,
  'ALTER TABLE os_settings ADD COLUMN damage_map_type VARCHAR(80) NOT NULL DEFAULT ''none'' AFTER auto_parts_mode',
  'ALTER TABLE os_settings MODIFY COLUMN damage_map_type VARCHAR(80) NOT NULL DEFAULT ''none''');
PREPARE nexa_os_m2 FROM @nexa_os_stmt;
EXECUTE nexa_os_m2;
DEALLOCATE PREPARE nexa_os_m2;

-- Converte automaticamente empresas com mapa de carro ativado no modo legado.
UPDATE os_settings
 SET damage_map_type='car'
 WHERE auto_parts_mode=1 AND (damage_map_type IS NULL OR damage_map_type='none' OR damage_map_type='');
