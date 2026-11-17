-- Nexa Ponto - V5: banco de horas e fechamento mensal (competência).
-- Movimentos do banco de horas nunca são apagados: reabrir um fechamento estorna os
-- movimentos dele (status ESTORNADO, com motivo). O fechamento guarda uma fotografia dos
-- totais de cada colaborador (itens), por versão (cada novo fechamento após reabrir = versão nova).

-- Colaborador participa do banco de horas (quando a empresa usa banco de horas).
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ponto_colaboradores' AND column_name = 'banco_horas') = 0,
  'ALTER TABLE ponto_colaboradores ADD COLUMN banco_horas TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ponto_externo', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

-- Tipo de ocorrência que é folga compensada pelo banco de horas (debita o banco ao fechar).
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ponto_tipos_ocorrencia' AND column_name = 'compensa_banco') = 0,
  'ALTER TABLE ponto_tipos_ocorrencia ADD COLUMN compensa_banco TINYINT(1) NOT NULL DEFAULT 0 AFTER abona', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

UPDATE ponto_tipos_ocorrencia SET compensa_banco = 1 WHERE descricao = 'Folga compensatória' AND compensa_banco = 0;

CREATE TABLE IF NOT EXISTS ponto_fechamentos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  estabelecimento_id INT NOT NULL,
  competencia CHAR(7) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'FECHADO',
  versao INT NOT NULL DEFAULT 1,
  resumo_json TEXT NULL,
  fechado_por INT NULL,
  fechado_em DATETIME NULL,
  reaberto_por INT NULL,
  reaberto_em DATETIME NULL,
  motivo_reabertura VARCHAR(300) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_fech (company_id, estabelecimento_id, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_fechamento_itens (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  fechamento_id INT NOT NULL,
  versao INT NOT NULL,
  colaborador_id INT NOT NULL,
  minutos_previstos INT NOT NULL DEFAULT 0,
  minutos_trabalhados INT NOT NULL DEFAULT 0,
  minutos_extra_50 INT NOT NULL DEFAULT 0,
  minutos_extra_100 INT NOT NULL DEFAULT 0,
  minutos_falta INT NOT NULL DEFAULT 0,
  minutos_atraso INT NOT NULL DEFAULT 0,
  minutos_noturnos INT NOT NULL DEFAULT 0,
  minutos_noturnos_reduzidos INT NOT NULL DEFAULT 0,
  minutos_abonados INT NOT NULL DEFAULT 0,
  dias_falta INT NOT NULL DEFAULT 0,
  dias_trabalhados INT NOT NULL DEFAULT 0,
  banco_horas TINYINT(1) NOT NULL DEFAULT 0,
  bh_saldo_anterior INT NOT NULL DEFAULT 0,
  bh_creditos INT NOT NULL DEFAULT 0,
  bh_debitos INT NOT NULL DEFAULT 0,
  bh_vencidos INT NOT NULL DEFAULT 0,
  bh_saldo_final INT NOT NULL DEFAULT 0,
  minutos_pagar_50 INT NOT NULL DEFAULT 0,
  minutos_pagar_100 INT NOT NULL DEFAULT 0,
  minutos_descontar INT NOT NULL DEFAULT 0,
  alertas_json TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_fi_fech (company_id, fechamento_id, versao),
  KEY idx_ponto_fi_col (company_id, colaborador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extrato do banco de horas. minutos com sinal: + crédito, - débito.
-- tipo: CREDITO, DEBITO, COMPENSACAO, VENCIMENTO, PAGAMENTO, AJUSTE.
CREATE TABLE IF NOT EXISTS ponto_bh_movimentos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  `data` DATE NOT NULL,
  competencia CHAR(7) NOT NULL,
  tipo VARCHAR(12) NOT NULL,
  minutos INT NOT NULL,
  origem VARCHAR(12) NOT NULL DEFAULT 'MANUAL',
  fechamento_id INT NULL,
  descricao VARCHAR(300) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'ATIVO',
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  estornado_por INT NULL,
  estornado_em DATETIME NULL,
  motivo_estorno VARCHAR(300) NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_bh_col (company_id, colaborador_id, status, `data`),
  KEY idx_ponto_bh_fech (company_id, fechamento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
