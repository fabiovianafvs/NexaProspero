-- Nexa Ponto - V8: acesso ao Nexa ligado ao ponto.
-- Só tem efeito quando a empresa liga a opção em Ponto > Configurações (padrão: desligado).
-- Liberações e tentativas fora da jornada nunca são apagadas (cancelar = status CANCELADA).

-- Colaborador com acesso ao Nexa sem restrição de horário (ex.: gerente, sócio).
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ponto_colaboradores' AND column_name = 'acesso_livre') = 0,
  'ALTER TABLE ponto_colaboradores ADD COLUMN acesso_livre TINYINT(1) NOT NULL DEFAULT 0 AFTER controla_ponto', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

-- Liberação de acesso fora da jornada: pedida pelo colaborador (PENDENTE) ou dada pelo gestor.
-- inicio/fim no horário local do estabelecimento do colaborador.
CREATE TABLE IF NOT EXISTS ponto_acesso_liberacoes (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  usuario_id INT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'PENDENTE',
  motivo VARCHAR(300) NOT NULL,
  inicio DATETIME NULL,
  fim DATETIME NULL,
  pedido_por INT NULL,
  pedido_em DATETIME NULL,
  decidido_por INT NULL,
  decidido_em DATETIME NULL,
  obs_decisao VARCHAR(300) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_lib_col (company_id, colaborador_id, status, fim),
  KEY idx_ponto_lib_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de usar o Nexa fora da jornada: uma linha por usuário, dia, tipo e motivo.
CREATE TABLE IF NOT EXISTS ponto_acesso_eventos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  usuario_id INT NOT NULL,
  `data` DATE NOT NULL,
  tipo VARCHAR(10) NOT NULL,
  motivo VARCHAR(30) NOT NULL,
  pagina VARCHAR(120) NULL,
  ip VARCHAR(45) NULL,
  vezes INT NOT NULL DEFAULT 1,
  primeira_em DATETIME NOT NULL,
  ultima_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_acesso_ev (company_id, usuario_id, `data`, tipo, motivo),
  KEY idx_ponto_acesso_ev_data (company_id, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
