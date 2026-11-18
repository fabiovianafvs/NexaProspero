-- Nexa Ponto - V9: equipamentos (Control iD e outros por API) e acesso físico (porta/catraca).
-- O equipamento é um "aparelho" (ponto_coletores) de tipo CONTROLID ou API. Cada um tem as funções
-- marcadas pela empresa: registrar o ponto e/ou liberar a porta/catraca. Nada muda nos aparelhos já
-- cadastrados (tablets/computadores continuam iguais).

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ponto_coletores' AND column_name = 'funcao_ponto') = 0,
  'ALTER TABLE ponto_coletores
     ADD COLUMN funcao_ponto TINYINT(1) NOT NULL DEFAULT 1 AFTER permitir_rfid,
     ADD COLUMN funcao_acesso TINYINT(1) NOT NULL DEFAULT 0 AFTER funcao_ponto,
     ADD COLUMN acesso_regra VARCHAR(10) NOT NULL DEFAULT ''JORNADA'' AFTER funcao_acesso,
     ADD COLUMN acesso_sentido VARCHAR(10) NOT NULL DEFAULT ''ENTRADA'' AFTER acesso_regra,
     ADD COLUMN acesso_acao VARCHAR(10) NOT NULL DEFAULT ''door'' AFTER acesso_sentido,
     ADD COLUMN equip_serial VARCHAR(40) NULL AFTER acesso_acao,
     ADD COLUMN dupla_seg INT NOT NULL DEFAULT 120 AFTER equip_serial,
     ADD COLUMN aprender_colaborador_id INT NULL AFTER dupla_seg,
     ADD COLUMN aprender_expira DATETIME NULL AFTER aprender_colaborador_id',
  'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

-- O que aconteceu em cada leitura do equipamento (crachá, rosto, digital, senha).
-- Só INSERT: serve de histórico de acesso físico e para conferir problemas com o aparelho.
CREATE TABLE IF NOT EXISTS ponto_equip_eventos (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  coletor_id INT NOT NULL,
  colaborador_id INT NULL,
  data_hora DATETIME NOT NULL,
  identificacao VARCHAR(10) NOT NULL,
  final_cartao VARCHAR(4) NULL,
  resultado VARCHAR(12) NOT NULL,
  motivo VARCHAR(30) NULL,
  mensagem VARCHAR(160) NULL,
  marcacao_id BIGINT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_equip_ev (company_id, coletor_id, id),
  KEY idx_ponto_equip_ev_col (company_id, colaborador_id, data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
