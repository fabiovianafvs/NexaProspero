-- Nexa v2.2 - diagnóstico não destrutivo de comandas
-- Troque o valor abaixo pela empresa que será analisada.
SET @company_id := 1;

SELECT VERSION() AS mysql_mariadb_version, DATABASE() AS banco, NOW() AS executado_em;

SELECT module_name, version_number, applied_at, notes
  FROM nexa_schema_versions
 WHERE module_name IN ('brain_profitability','gestao_runtime','comanda_runtime')
 ORDER BY module_name;

SHOW INDEX FROM comandas;
SHOW INDEX FROM comanda_items;
SHOW INDEX FROM produtos;

-- Plano da consulta otimizada do quadro de comandas.
EXPLAIN
SELECT c.id, c.codigo, c.status,
       COALESCE(ci.total_aberto,0) AS total_aberto,
       COALESCE(ci.itens_abertos,0) AS itens_abertos,
       ci.last_activity_at
  FROM comandas c
  LEFT JOIN (
    SELECT company_id, comanda_id,
           SUM(total) AS total_aberto,
           COUNT(*) AS itens_abertos,
           MAX(created_at) AS last_activity_at
      FROM comanda_items
     WHERE company_id=@company_id AND status='ABERTO'
     GROUP BY company_id, comanda_id
  ) ci ON ci.company_id=c.company_id AND ci.comanda_id=c.id
 WHERE c.company_id=@company_id
 ORDER BY CAST(c.codigo AS UNSIGNED), c.codigo, c.id;

-- Volume que influencia diretamente a abertura do quadro.
SELECT
  (SELECT COUNT(*) FROM comandas WHERE company_id=@company_id) AS comandas,
  (SELECT COUNT(*) FROM comanda_items WHERE company_id=@company_id) AS itens_total,
  (SELECT COUNT(*) FROM comanda_items WHERE company_id=@company_id AND status='ABERTO') AS itens_abertos,
  (SELECT COUNT(*) FROM produtos WHERE company_id=@company_id AND COALESCE(ativo,1)=1) AS produtos_ativos;

-- Locks ou consultas longas presentes no momento da execução.
SHOW FULL PROCESSLIST;
