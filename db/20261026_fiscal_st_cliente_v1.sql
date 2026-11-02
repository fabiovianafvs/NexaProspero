-- Nexa Próspero | ICMS-ST por destinatário | 26/10/2026
-- Execução controlada: Atualizador do Banco (platform_admin).
-- NÃO configura alíquotas presumidas. Novos registros começam sem percentual.
ALTER TABLE finance_people
  ADD COLUMN tax_regime VARCHAR(25) NULL DEFAULT NULL AFTER person_type;

CREATE TABLE fiscal_st_state_rates (
  uf CHAR(2) NOT NULL,
  state_name VARCHAR(80) NOT NULL,
  own_icms_rate DECIMAL(9,4) NULL DEFAULT NULL,
  valid_from DATE NOT NULL DEFAULT '2026-01-01',
  valid_to DATE NULL,
  legal_basis VARCHAR(500) NULL,
  updated_at DATETIME NULL,
  updated_by BIGINT NULL,
  PRIMARY KEY (uf, valid_from),
  KEY idx_fiscal_st_state_active (uf, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fiscal_st_ncm_mva (
  id BIGINT NOT NULL AUTO_INCREMENT,
  uf CHAR(2) NOT NULL,
  ncm CHAR(8) NOT NULL,
  cest VARCHAR(12) NOT NULL DEFAULT '',
  mva_rate DECIMAL(9,4) NOT NULL,
  valid_from DATE NOT NULL,
  valid_to DATE NULL,
  legal_basis VARCHAR(500) NULL,
  updated_at DATETIME NULL,
  updated_by BIGINT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_st_mva (uf, ncm, cest, valid_from),
  KEY idx_fiscal_st_mva_lookup (uf, ncm, cest, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fiscal_st_state_rates (uf, state_name, own_icms_rate, valid_from) VALUES
 ('AC','Acre',NULL,'2026-01-01'),('AL','Alagoas',NULL,'2026-01-01'),
 ('AP','Amapá',NULL,'2026-01-01'),('AM','Amazonas',NULL,'2026-01-01'),
 ('BA','Bahia',NULL,'2026-01-01'),('CE','Ceará',NULL,'2026-01-01'),
 ('DF','Distrito Federal',NULL,'2026-01-01'),('ES','Espírito Santo',NULL,'2026-01-01'),
 ('GO','Goiás',NULL,'2026-01-01'),('MA','Maranhão',NULL,'2026-01-01'),
 ('MT','Mato Grosso',NULL,'2026-01-01'),('MS','Mato Grosso do Sul',NULL,'2026-01-01'),
 ('MG','Minas Gerais',NULL,'2026-01-01'),('PA','Pará',NULL,'2026-01-01'),
 ('PB','Paraíba',NULL,'2026-01-01'),('PR','Paraná',NULL,'2026-01-01'),
 ('PE','Pernambuco',NULL,'2026-01-01'),('PI','Piauí',NULL,'2026-01-01'),
 ('RJ','Rio de Janeiro',NULL,'2026-01-01'),('RN','Rio Grande do Norte',NULL,'2026-01-01'),
 ('RS','Rio Grande do Sul',NULL,'2026-01-01'),('RO','Rondônia',NULL,'2026-01-01'),
 ('RR','Roraima',NULL,'2026-01-01'),('SC','Santa Catarina',NULL,'2026-01-01'),
 ('SP','São Paulo',NULL,'2026-01-01'),('SE','Sergipe',NULL,'2026-01-01'),
 ('TO','Tocantins',NULL,'2026-01-01');
