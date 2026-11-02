-- Nexa Próspero - Validação V11.12 Acompanhamento Itaú Multiempresa protegido

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_bank_billing_configs'
  AND COLUMN_NAME IN ('itau_cash_client_id','itau_cash_client_secret')
ORDER BY ORDINAL_POSITION;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_boleto_charges'
  AND COLUMN_NAME IN (
    'api_last_check_at','api_last_check_by_user_id','api_last_check_http_status',
    'api_last_check_result','api_last_check_correlation_id','api_last_check_message','api_last_check_response'
  )
ORDER BY ORDINAL_POSITION;

SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name='finance_itau_multiempresa';

SELECT id, company_id, bank_account_id, status, nosso_numero,
       api_last_check_at, api_last_check_http_status, api_last_check_result,
       api_last_check_correlation_id, api_last_check_message
FROM finance_boleto_charges
WHERE provider='ITAU' AND integration_mode='API'
  AND status IN ('ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API','REGISTRADO','VERIFICAR_PAGAMENTO')
ORDER BY id DESC
LIMIT 30;
