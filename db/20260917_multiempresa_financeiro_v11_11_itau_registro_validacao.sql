-- Nexa Próspero - Validação V11.11 Registro/Reprocessamento Itaú Multiempresa

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_boleto_charges'
  AND COLUMN_NAME IN (
    'api_attempt_count',
    'api_last_action',
    'api_last_attempt_at',
    'api_last_attempt_by_user_id',
    'api_request_hash'
  )
ORDER BY ORDINAL_POSITION;

SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name='finance_itau_multiempresa';

SELECT id, company_id, status, nosso_numero, external_id, api_http_status,
       api_correlation_id, api_attempt_count, api_last_action,
       api_last_attempt_at, api_last_attempt_by_user_id, api_request_hash
FROM finance_boleto_charges
WHERE provider='ITAU' AND integration_mode='API'
ORDER BY id DESC
LIMIT 20;
