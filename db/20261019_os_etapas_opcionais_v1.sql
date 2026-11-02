-- Nexa O.S. - controles independentes de etapas por empresa
-- Executar SOMENTE pelo Atualizador do Banco como platform_admin.
ALTER TABLE os_settings
  ADD COLUMN show_vehicle_step TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Exibir etapa de dados do veiculo Desktop e Mobile',
  ADD COLUMN show_damage_step TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Exibir etapa de avarias Desktop e Mobile';
