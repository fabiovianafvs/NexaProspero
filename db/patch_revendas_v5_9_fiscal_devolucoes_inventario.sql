-- Nexa Próspero v5.9
-- Ajustes fiscais: código IBGE em pessoas/endereços, município IBGE e impostos detalhados nas entradas XML.
-- Seguro para reexecução. Não remove dados existentes.

DELIMITER $$

DROP PROCEDURE IF EXISTS nexa_v59_add_column_if_missing$$
CREATE PROCEDURE nexa_v59_add_column_if_missing(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition VARCHAR(255)
)
BEGIN
  DECLARE v_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO v_exists
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = p_table
     AND COLUMN_NAME = p_column;

  IF v_exists = 0 THEN
    SET @nexa_v59_sql = CONCAT(
      'ALTER TABLE `', REPLACE(p_table, '`', ''),
      '` ADD COLUMN `', REPLACE(p_column, '`', ''),
      '` ', p_definition
    );
    PREPARE nexa_v59_stmt FROM @nexa_v59_sql;
    EXECUTE nexa_v59_stmt;
    DEALLOCATE PREPARE nexa_v59_stmt;
  END IF;
END$$

CALL nexa_v59_add_column_if_missing('finance_people', 'city_ibge', 'VARCHAR(7) NULL');
CALL nexa_v59_add_column_if_missing('gestao_customer_addresses', 'city_ibge', 'VARCHAR(7) NULL');
CALL nexa_v59_add_column_if_missing('fiscal_xml_imports', 'supplier_city_ibge', 'VARCHAR(7) NULL');
CALL nexa_v59_add_column_if_missing('fiscal_xml_items', 'tax_json', 'MEDIUMTEXT NULL');

DROP PROCEDURE IF EXISTS nexa_v59_add_column_if_missing$$
DELIMITER ;

-- Aproveita o código IBGE já registrado no endereço padrão, quando existir.
UPDATE finance_people fp
JOIN gestao_customer_addresses ga
  ON ga.company_id = fp.company_id
 AND ga.person_id = fp.id
 AND ga.active = 1
 AND ga.is_default = 1
SET fp.city_ibge = ga.city_ibge
WHERE (fp.city_ibge IS NULL OR fp.city_ibge = '')
  AND ga.city_ibge IS NOT NULL
  AND ga.city_ibge <> '';
