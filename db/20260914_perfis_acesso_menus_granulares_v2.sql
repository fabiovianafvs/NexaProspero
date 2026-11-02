-- Nexa Próspero - Perfis de acesso: granularidade para todos os menus/submenus
-- Data: 14/09/2026
-- Objetivo: preservar o acesso dos perfis existentes após a separação de permissões
-- que antes controlavam mais de um item de menu.

START TRANSACTION;

-- WhatsApp -> Modelo de Mensagens
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"whatsapp_modelos"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"whatsapp"%'
  AND permissions_json NOT LIKE '%"whatsapp_modelos"%';

-- Orçamentos -> Novo Orçamento
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"orcamento_novo"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"orcamentos"%'
  AND permissions_json NOT LIKE '%"orcamento_novo"%';

-- Monitor de O.S. -> O.S. Mobile
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"os_mobile"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"os_monitor"%'
  AND permissions_json NOT LIKE '%"os_mobile"%';

-- Monitor de O.S. -> Documentos fiscais da O.S.
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"os_fiscal_documentos"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"os_monitor"%'
  AND permissions_json NOT LIKE '%"os_fiscal_documentos"%';

-- Contas a receber -> Cobranças/Boletos
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"financeiro_boletos"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"financeiro_receber"%'
  AND permissions_json NOT LIKE '%"financeiro_boletos"%';

-- RADAR NF-e -> Entrada de Notas
UPDATE user_profiles
SET permissions_json = CONCAT(
  LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
  ',"fiscal_entrada_notas"]'
)
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"fiscal_radar"%'
  AND permissions_json NOT LIKE '%"fiscal_entrada_notas"%';

COMMIT;

SELECT id, company_id, name, permissions_json
FROM user_profiles
ORDER BY company_id, name;
