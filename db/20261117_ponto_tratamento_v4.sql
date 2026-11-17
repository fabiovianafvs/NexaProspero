-- Nexa Ponto - V4: tratamento do ponto (PTRP) e apuração diária.
-- O registro original (ponto_arp_marcacoes) NUNCA é alterado: o tratamento guarda à parte
-- as inclusões, desconsiderações e reclassificações, com motivo, responsável e data.
-- A apuração diária é derivada (pode ser recalculada a qualquer momento).

-- Tipos de ocorrência (Esquecimento, Atestado, Falta, Treinamento...). "abona" = a ausência
-- justificada por este tipo não vira débito de horas.
CREATE TABLE IF NOT EXISTS ponto_tipos_ocorrencia (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  descricao VARCHAR(80) NOT NULL,
  abona TINYINT(1) NOT NULL DEFAULT 0,
  exige_documento TINYINT(1) NOT NULL DEFAULT 0,
  visivel_colaborador TINYINT(1) NOT NULL DEFAULT 1,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_tpo_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ocorrências / justificativas (atestado, falta justificada, serviço externo...), por dia
-- inteiro ou por horário. Pedidas pelo colaborador (Meu Ponto) ficam PENDENTE até aprovar.
CREATE TABLE IF NOT EXISTS ponto_ocorrencias (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  tipo_ocorrencia_id INT NOT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  hora_inicio TIME NULL,
  hora_fim TIME NULL,
  descricao VARCHAR(500) NULL,
  documento_path VARCHAR(255) NULL,
  documento_nome VARCHAR(150) NULL,
  documento_mime VARCHAR(60) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'PENDENTE',
  solicitado_por INT NULL,
  pelo_colaborador TINYINT(1) NOT NULL DEFAULT 0,
  decidido_por INT NULL,
  decidido_em DATETIME NULL,
  motivo_decisao VARCHAR(300) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_oco_col (company_id, colaborador_id, data_inicio),
  KEY idx_ponto_oco_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tratamento: INCLUSAO (marcação esquecida), DESCONSIDERACAO (marcação original que não vale,
-- ex.: duplicada) e AJUSTE_CLASSIFICACAO (muda só o tipo: entrada/intervalo/saída).
-- Nunca altera o ARP. Cancelar um tratamento também fica registrado (status CANCELADO).
CREATE TABLE IF NOT EXISTS ponto_tratamentos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  `data` DATE NOT NULL,
  tipo VARCHAR(25) NOT NULL,
  marcacao_id BIGINT NULL,
  data_hora DATETIME NULL,
  fuso CHAR(5) NULL,
  intencao VARCHAR(20) NULL,
  tipo_ocorrencia_id INT NULL,
  motivo VARCHAR(300) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'ATIVO',
  pelo_colaborador TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  decidido_por INT NULL,
  decidido_em DATETIME NULL,
  motivo_decisao VARCHAR(300) NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_trat_dia (company_id, colaborador_id, `data`),
  KEY idx_ponto_trat_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Apuração diária (derivada; recalculável). Minutos por dia e colaborador.
CREATE TABLE IF NOT EXISTS ponto_apuracoes_diarias (
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  `data` DATE NOT NULL,
  situacao VARCHAR(25) NOT NULL,
  minutos_previstos INT NOT NULL DEFAULT 0,
  minutos_trabalhados INT NOT NULL DEFAULT 0,
  minutos_intervalo INT NOT NULL DEFAULT 0,
  minutos_atraso INT NOT NULL DEFAULT 0,
  minutos_saida_antecipada INT NOT NULL DEFAULT 0,
  minutos_falta INT NOT NULL DEFAULT 0,
  minutos_extra INT NOT NULL DEFAULT 0,
  minutos_noturnos INT NOT NULL DEFAULT 0,
  minutos_abonados INT NOT NULL DEFAULT 0,
  marcacoes_qtd INT NOT NULL DEFAULT 0,
  inconsistente TINYINT(1) NOT NULL DEFAULT 0,
  detalhe_json TEXT NULL,
  calculado_em DATETIME NOT NULL,
  PRIMARY KEY (company_id, colaborador_id, `data`),
  KEY idx_ponto_apu_dia (company_id, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
