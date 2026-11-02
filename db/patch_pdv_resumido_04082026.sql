-- Nexa Próspero - liberação do PDV Resumido para perfis que já usam Pedido PDV
-- Data: 04/08/2026
-- Banco esperado: nexaprospero
-- Idempotente: pode ser executado novamente sem duplicar a permissão.

START TRANSACTION;

UPDATE user_profiles
SET permissions_json = CASE
  WHEN TRIM(permissions_json) = '[]' THEN '["gestao_pdv_resumido"]'
  ELSE CONCAT(
    LEFT(TRIM(permissions_json), CHAR_LENGTH(TRIM(permissions_json)) - 1),
    ',"gestao_pdv_resumido"]'
  )
END
WHERE permissions_json IS NOT NULL
  AND LEFT(TRIM(permissions_json), 1) = '['
  AND RIGHT(TRIM(permissions_json), 1) = ']'
  AND permissions_json LIKE '%"gestao_pdv"%'
  AND permissions_json NOT LIKE '%"gestao_pdv_resumido"%';

COMMIT;
