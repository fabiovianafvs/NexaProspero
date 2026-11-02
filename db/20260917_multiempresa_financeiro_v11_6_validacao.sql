-- Nexa Próspero - Validação Multiempresa Financeiro V11.6
SELECT module_name,version_number,applied_at,notes FROM nexa_schema_versions WHERE module_name='multiempresa_contexto';
SHOW COLUMNS FROM finance_payment_method_defaults;
SELECT fd.company_id,fd.payment_method_id,pm.name,fd.bank_account_id
FROM finance_payment_method_defaults fd
LEFT JOIN finance_payment_methods pm ON pm.company_id=fd.company_id AND pm.id=fd.payment_method_id
ORDER BY fd.company_id,pm.name;
-- Esperado ZERO LINHAS: sobreposição órfã de forma operacional.
SELECT fd.* FROM finance_payment_method_defaults fd LEFT JOIN finance_payment_methods pm ON pm.company_id=fd.company_id AND pm.id=fd.payment_method_id WHERE pm.id IS NULL;
-- Esperado ZERO LINHAS: Caixa/Banco de outra empresa.
SELECT fd.* FROM finance_payment_method_defaults fd JOIN finance_bank_accounts b ON b.id=fd.bank_account_id WHERE fd.bank_account_id IS NOT NULL AND b.company_id<>fd.company_id;
