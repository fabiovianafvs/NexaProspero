-- Nexa Próspero - Ordem de Serviço v1.7
-- A aplicação executa esta evolução automaticamente via config/os_module.php.
-- Esta migração unifica Vendedor/Técnico em um único responsável por item.
-- As colunas technician_* são mantidas apenas para compatibilidade com históricos v1.6.

UPDATE os_order_items
   SET seller_person_id = COALESCE(seller_person_id, technician_person_id),
       seller_name_snapshot = COALESCE(NULLIF(seller_name_snapshot,''), technician_name_snapshot)
 WHERE (seller_person_id IS NULL OR seller_person_id = 0 OR seller_name_snapshot IS NULL OR seller_name_snapshot = '')
   AND (technician_person_id IS NOT NULL OR technician_name_snapshot IS NOT NULL);

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('ordem_servico', 2026081004, NOW(), 'O.S. v1.7 - responsável único Vendedor/Técnico e O.S. Mobile')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);
