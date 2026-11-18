-- Transporte (CT-e/MDF-e) - V2: emissão do CT-e rodoviário (modelo 57).
-- Só cria tabelas novas. Aplicar depois da V1 Fundação.

-- Um registro por CT-e. Rascunho não tem número; o número e o código numérico (cct)
-- são definidos na 1ª transmissão e não mudam mais. Os dados do formulário ficam em
-- dados_json; as colunas de resumo servem para a lista e a pesquisa. xml = XML assinado
-- (antes da autorização) ou cteProc (depois de autorizado).
CREATE TABLE IF NOT EXISTS cte_documentos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  serie INT NOT NULL DEFAULT 1,
  numero INT NULL,
  cct VARCHAR(8) NULL,
  chave VARCHAR(44) NULL,
  ambiente VARCHAR(12) NOT NULL DEFAULT 'HOMOLOGACAO',
  status VARCHAR(15) NOT NULL DEFAULT 'RASCUNHO',
  tp_serv CHAR(1) NOT NULL DEFAULT '0',
  cfop VARCHAR(4) NULL,
  natureza VARCHAR(60) NULL,
  tomador CHAR(1) NOT NULL DEFAULT '0',
  dh_emissao DATETIME NULL,
  uf_ini CHAR(2) NULL,
  mun_ini_ibge VARCHAR(7) NULL,
  mun_ini_nome VARCHAR(60) NULL,
  uf_fim CHAR(2) NULL,
  mun_fim_ibge VARCHAR(7) NULL,
  mun_fim_nome VARCHAR(60) NULL,
  tomador_documento VARCHAR(14) NULL,
  tomador_nome VARCHAR(60) NULL,
  tomador_pessoa_id INT NULL,
  remetente_nome VARCHAR(60) NULL,
  destinatario_nome VARCHAR(60) NULL,
  valor_prestacao DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_receber DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_carga DECIMAL(15,2) NOT NULL DEFAULT 0,
  dados_json MEDIUMTEXT NULL,
  cstat VARCHAR(4) NULL,
  xmotivo VARCHAR(255) NULL,
  protocolo VARCHAR(20) NULL,
  dh_autorizacao DATETIME NULL,
  xml LONGTEXT NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_cte_numero (company_id, ambiente, serie, numero),
  KEY idx_cte_company_status (company_id, status),
  KEY idx_cte_company_data (company_id, dh_emissao),
  KEY idx_cte_chave (company_id, chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de cada comunicação com a SEFAZ (envio, consulta, validação local).
CREATE TABLE IF NOT EXISTS cte_transmissoes (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  cte_id INT NOT NULL,
  tipo VARCHAR(12) NOT NULL,
  cstat VARCHAR(4) NULL,
  xmotivo TEXT NULL,
  xml_envio LONGTEXT NULL,
  xml_retorno MEDIUMTEXT NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cte_transm (company_id, cte_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
