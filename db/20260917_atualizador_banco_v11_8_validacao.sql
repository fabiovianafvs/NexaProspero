-- Nexa Próspero - Validação opcional V11.8
SELECT DATABASE() AS banco_em_uso, VERSION() AS mysql_mariadb_version;

SELECT module_name,version_number,applied_at,notes
FROM nexa_schema_versions
WHERE module_name='schema_updater';

SELECT TABLE_NAME
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name='nexa_schema_migration_log';

SELECT id,migration_id,module_name,version_number,status,started_at,finished_at,executed_by_user_id,database_name,error_message
FROM nexa_schema_migration_log
ORDER BY id DESC
LIMIT 20;
