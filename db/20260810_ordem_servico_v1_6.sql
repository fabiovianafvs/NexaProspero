-- Nexa Próspero - Ordem de Serviço v1.6
-- A aplicação executa esta evolução automaticamente via config/os_module.php.
-- Este arquivo serve como referência/execução manual de contingência.

ALTER TABLE os_settings
  ADD COLUMN allow_out_of_stock_reservation TINYINT(1) NOT NULL DEFAULT 0 AFTER default_reserve_product;

ALTER TABLE os_order_items
  ADD COLUMN seller_person_id INT NULL AFTER reservation_status,
  ADD COLUMN seller_name_snapshot VARCHAR(180) NULL AFTER seller_person_id,
  ADD COLUMN technician_person_id INT NULL AFTER seller_name_snapshot,
  ADD COLUMN technician_name_snapshot VARCHAR(180) NULL AFTER technician_person_id;

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('ordem_servico', 2026081003, NOW(), 'O.S. v1.6 - edição de campos/itens, vendedor/técnico e reserva sem estoque')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);
