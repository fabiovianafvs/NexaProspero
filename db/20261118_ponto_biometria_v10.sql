-- Nexa Ponto - V10: biometria facial.
-- Rosto do colaborador (só com consentimento, LGPD art. 11), foto/conferência do rosto em cada
-- marcação feita pelo Nexa (tablet/celular) e envio dos rostos aos aparelhos Control iD.
-- Nada muda até a empresa ligar em Ponto > Configurações (padrão: desligado).

-- Consentimento e "modelo" do rosto (vetor numérico de 128 posições; a foto em si fica no cadastro).
CREATE TABLE IF NOT EXISTS ponto_biometria (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  consentimento_em DATETIME NULL,
  consentimento_por INT NULL,
  termo_versao VARCHAR(10) NULL,
  descritor TEXT NULL,
  descritor_em DATETIME NULL,
  descritor_origem VARCHAR(15) NULL,
  revogado_em DATETIME NULL,
  revogado_por INT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_biometria_col (company_id, colaborador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foto e resultado da conferência do rosto em cada marcação (o ARP não muda; isto fica ao lado).
CREATE TABLE IF NOT EXISTS ponto_marcacao_faciais (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  marcacao_id BIGINT NOT NULL,
  colaborador_id INT NOT NULL,
  modo VARCHAR(10) NOT NULL,
  resultado VARCHAR(12) NOT NULL,
  distancia DECIMAL(5,3) NULL,
  arquivo VARCHAR(190) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_mfac_marc (company_id, marcacao_id),
  KEY idx_ponto_mfac_data (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aparelho Control iD que recebe os rostos cadastrados no Nexa.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ponto_coletores' AND column_name = 'enviar_rostos') = 0,
  'ALTER TABLE ponto_coletores ADD COLUMN enviar_rostos TINYINT(1) NOT NULL DEFAULT 0 AFTER dupla_seg', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

-- Fila de comandos para os aparelhos (o aparelho busca pelo modo "push" da Control iD).
CREATE TABLE IF NOT EXISTS ponto_equip_comandos (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  coletor_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  tipo VARCHAR(10) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'PENDENTE',
  tentativas INT NOT NULL DEFAULT 0,
  push_uuid VARCHAR(64) NULL,
  mensagem VARCHAR(200) NULL,
  created_at DATETIME NOT NULL,
  enviado_em DATETIME NULL,
  concluido_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_cmd_fila (company_id, coletor_id, status, id),
  KEY idx_ponto_cmd_uuid (push_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
