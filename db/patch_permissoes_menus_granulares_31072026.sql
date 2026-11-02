-- Nexa Próspero - migração de permissões granulares de menus
-- Data: 31/07/2026
-- Banco principal esperado: nexaprospero
-- Idempotente: pode ser executado novamente sem duplicar permissões.

START TRANSACTION;

-- Compatibilidade: gestao_pdv também passa a liberar gestao_pdv_mobile nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"gestao_pdv_mobile"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"gestao_pdv"%'
  AND permissions_json NOT LIKE '%"gestao_pdv_mobile"%';

-- Compatibilidade: gestao_comandas também passa a liberar gestao_comandas_mobile nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"gestao_comandas_mobile"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"gestao_comandas"%'
  AND permissions_json NOT LIKE '%"gestao_comandas_mobile"%';

-- Compatibilidade: gestao_comandas_config também passa a liberar gestao_comandas_qrcode nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"gestao_comandas_qrcode"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"gestao_comandas_config"%'
  AND permissions_json NOT LIKE '%"gestao_comandas_qrcode"%';

-- Compatibilidade: gestao_comandas_config também passa a liberar gestao_comandas_impressao nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"gestao_comandas_impressao"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"gestao_comandas_config"%'
  AND permissions_json NOT LIKE '%"gestao_comandas_impressao"%';

-- Compatibilidade: gestao_comandas_config também passa a liberar gestao_comandas_agentes nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"gestao_comandas_agentes"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"gestao_comandas_config"%'
  AND permissions_json NOT LIKE '%"gestao_comandas_agentes"%';

-- Compatibilidade: fiscal_importar_xml também passa a liberar fiscal_devolucoes nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_devolucoes"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_importar_xml"%'
  AND permissions_json NOT LIKE '%"fiscal_devolucoes"%';

-- Compatibilidade: fiscal_importar_xml também passa a liberar fiscal_devolucao_cliente nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_devolucao_cliente"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_importar_xml"%'
  AND permissions_json NOT LIKE '%"fiscal_devolucao_cliente"%';

-- Compatibilidade: fiscal_documentos também passa a liberar fiscal_inutilizacao nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_inutilizacao"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_documentos"%'
  AND permissions_json NOT LIKE '%"fiscal_inutilizacao"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_relatorios_documentos nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_relatorios_documentos"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_relatorios_documentos"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_sintegra nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_sintegra"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_sintegra"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_sped_icms nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_sped_icms"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_sped_icms"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_sped_contrib nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_sped_contrib"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_sped_contrib"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_exportar_xml nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_exportar_xml"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_exportar_xml"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_inventario nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_inventario"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_inventario"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_contabilidade nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_contabilidade"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_contabilidade"%';

-- Compatibilidade: fiscal_relatorios também passa a liberar fiscal_contadores nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_contadores"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_relatorios"%'
  AND permissions_json NOT LIKE '%"fiscal_contadores"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_operacoes nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_operacoes"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_operacoes"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_tributacoes nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_tributacoes"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_tributacoes"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_cfops nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_cfops"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_cfops"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_ncms nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_ncms"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_ncms"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_cests nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_cests"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_cests"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_cst nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_cst"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_cst"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_ibscbs nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_ibscbs"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_ibscbs"%';

-- Compatibilidade: fiscal_tabelas também passa a liberar fiscal_importacoes nos perfis existentes.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_importacoes"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_tabelas"%'
  AND permissions_json NOT LIKE '%"fiscal_importacoes"%';

COMMIT;

-- Conferência opcional: lista os perfis e suas permissões após a migração.
SELECT id, company_id, name, permissions_json
FROM user_profiles
ORDER BY company_id, name;
