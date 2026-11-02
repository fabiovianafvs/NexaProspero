-- Diagnóstico somente leitura: não altera dados.
-- Execute no banco nexaprospero.

SELECT 'PERFIS_POR_EMPRESA' AS bloco;
SELECT company_id,
       COUNT(*) AS total_perfis,
       SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS perfis_ativos,
       SUM(CASE WHEN profile_type='PRODUCT' THEN 1 ELSE 0 END) AS perfis_produto,
       SUM(CASE WHEN profile_type='SERVICE' THEN 1 ELSE 0 END) AS perfis_servico
FROM fiscal_tax_profiles
GROUP BY company_id
ORDER BY company_id;

SELECT 'TRIBUTACAO_DOS_PRODUTOS_POR_EMPRESA' AS bloco;
SELECT p.company_id,
       COUNT(*) AS total_produtos,
       SUM(CASE WHEN fpt.product_id IS NOT NULL THEN 1 ELSE 0 END) AS com_registro_fiscal,
       SUM(CASE WHEN fpt.tax_profile_id IS NOT NULL AND fpt.tax_profile_id>0 THEN 1 ELSE 0 END) AS com_regra_vinculada,
       SUM(CASE WHEN fpt.product_id IS NOT NULL AND (fpt.tax_profile_id IS NULL OR fpt.tax_profile_id=0) THEN 1 ELSE 0 END) AS registro_fiscal_sem_regra
FROM produtos p
LEFT JOIN fiscal_product_tax fpt
  ON fpt.company_id=p.company_id AND fpt.product_id=p.id
GROUP BY p.company_id
ORDER BY p.company_id;

SELECT 'VINCULOS_INVALIDOS' AS bloco;
SELECT fpt.company_id,
       COUNT(*) AS total_vinculos_invalidos
FROM fiscal_product_tax fpt
LEFT JOIN fiscal_tax_profiles ftp
  ON ftp.company_id=fpt.company_id AND ftp.id=fpt.tax_profile_id
WHERE fpt.tax_profile_id IS NOT NULL
  AND fpt.tax_profile_id>0
  AND ftp.id IS NULL
GROUP BY fpt.company_id
ORDER BY fpt.company_id;

SELECT 'MAPEAMENTOS_DA_IMPORTACAO' AS bloco;
SELECT m.company_id,
       m.source_type,
       m.source_tax_code,
       m.target_tax_profile_id,
       ftp.code AS regra_codigo,
       ftp.name AS regra_nome,
       CASE WHEN ftp.id IS NULL THEN 'INVALIDO' ELSE 'OK' END AS status_vinculo
FROM product_import_tax_map_sources m
LEFT JOIN fiscal_tax_profiles ftp
  ON ftp.company_id=m.company_id AND ftp.id=m.target_tax_profile_id
ORDER BY m.company_id, m.source_type, m.source_tax_code;

SELECT 'ULTIMAS_IMPORTACOES' AS bloco;
SELECT id, company_id, source_type, status, source_file_name,
       total_source, inserted_count, updated_count, skipped_count, error_count,
       started_at, finished_at
FROM product_import_runs
ORDER BY id DESC
LIMIT 20;
