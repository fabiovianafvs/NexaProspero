-- Nexa Próspero - Validação da Fundação Multiempresa v1
-- Executar após 20260916_multiempresa_fundacao_v1.sql

SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_fundacao';

SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'business_groups',
    'business_group_companies',
    'business_group_profiles',
    'business_group_users',
    'user_company_access'
  )
ORDER BY table_name;

SELECT
  (SELECT COUNT(*) FROM business_groups) AS grupos,
  (SELECT COUNT(*) FROM business_group_companies) AS empresas_vinculadas,
  (SELECT COUNT(*) FROM business_group_profiles) AS perfis_integrados,
  (SELECT COUNT(*) FROM business_group_users) AS usuarios_grupo,
  (SELECT COUNT(*) FROM user_company_access) AS acessos_usuario_empresa;
