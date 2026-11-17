-- Nexa Ponto - V6: registros cadastrais do REP-P no ARP (AFD tipos 2, 5 e 6).
-- A Portaria 671 (Anexo IX, item 6) manda gravar no ARP, além das marcações, a inclusão ou
-- alteração do empregador e a inclusão, alteração ou exclusão de empregados. Eles usam a mesma
-- numeração (NSR) das marcações do estabelecimento e, como elas, nunca são alterados ou apagados.

CREATE TABLE IF NOT EXISTS ponto_arp_eventos (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  estabelecimento_id INT NOT NULL,
  nsr INT NOT NULL,
  tipo CHAR(1) NOT NULL,
  data_hora_gravacao DATETIME NOT NULL,
  fuso CHAR(5) NOT NULL,
  responsavel_cpf CHAR(11) NULL,
  tipo_documento CHAR(1) NULL,
  documento VARCHAR(14) NULL,
  cno_caepf VARCHAR(14) NULL,
  razao_social VARCHAR(150) NULL,
  local_servico VARCHAR(100) NULL,
  operacao CHAR(1) NULL,
  colaborador_id INT NULL,
  cpf CHAR(11) NULL,
  nome VARCHAR(52) NULL,
  evento CHAR(2) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_arp_ev_nsr (company_id, estabelecimento_id, nsr),
  KEY idx_ponto_arp_ev_col (company_id, estabelecimento_id, colaborador_id),
  KEY idx_ponto_arp_ev_data (company_id, estabelecimento_id, data_hora_gravacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
