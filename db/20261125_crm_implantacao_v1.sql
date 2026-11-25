-- CRM - Trilha de Implantação v1 (menu CRM): cadastros de etapas, status (com cor), tarefas
-- e trilhas; implantação por cliente (Pessoas) com as tarefas copiadas da trilha; relatórios
-- de atendimento técnico (participantes, processos, assinatura do cliente) e fotos dos
-- equipamentos instalados para o relatório de conclusão.
-- Idempotente: só cria tabelas novas (CREATE TABLE IF NOT EXISTS); não altera as existentes.

-- Etapas (colunas da trilha), com cor e ordem.
CREATE TABLE IF NOT EXISTS impl_etapas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  nome VARCHAR(80) NOT NULL,
  cor VARCHAR(7) NOT NULL DEFAULT '#2563eb',
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_impl_etapas_company (company_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status do cliente, com cor. tipo AGENDADO / ANDAMENTO / CONCLUIDO (CONCLUIDO libera o
-- relatório de conclusão na Documentação).
CREATE TABLE IF NOT EXISTS impl_status (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  nome VARCHAR(60) NOT NULL,
  cor VARCHAR(7) NOT NULL DEFAULT '#2563eb',
  tipo VARCHAR(12) NOT NULL DEFAULT 'ANDAMENTO',
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_impl_status_company (company_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarefas (processos a implantar), cada uma numa etapa.
CREATE TABLE IF NOT EXISTS impl_tarefas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  etapa_id INT NOT NULL,
  nome VARCHAR(160) NOT NULL,
  descricao TEXT NULL,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_impl_tarefas_company (company_id, etapa_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trilhas (ex.: "Novo cliente | Nova base de dados") e as tarefas de cada uma.
CREATE TABLE IF NOT EXISTS impl_trilhas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  descricao TEXT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_impl_trilhas_company (company_id, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS impl_trilha_tarefas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  trilha_id INT NOT NULL,
  tarefa_id INT NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY ux_impl_trilha_tarefa (company_id, trilha_id, tarefa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Implantação de um cliente (Pessoas) seguindo uma trilha.
CREATE TABLE IF NOT EXISTS impl_implantacoes (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  person_id BIGINT NOT NULL,
  trilha_id INT NULL,
  trilha_nome VARCHAR(120) NULL,
  status_id INT NULL,
  implantador_user_id INT NULL,
  data_inicio DATE NOT NULL,
  data_conclusao DATE NULL,
  observacoes TEXT NULL,
  aviso_equipamentos TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_impl_impl_company (company_id, status_id),
  KEY idx_impl_impl_person (company_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarefas da implantação: cópia da trilha no momento da criação (mudar a trilha depois não
-- mexe nas implantações já abertas). done_at = executada; relatorio_id = relatório que a marcou.
CREATE TABLE IF NOT EXISTS impl_impl_tarefas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  implantacao_id INT NOT NULL,
  tarefa_id INT NULL,
  etapa_id INT NULL,
  etapa_nome VARCHAR(80) NOT NULL,
  etapa_cor VARCHAR(7) NOT NULL DEFAULT '#2563eb',
  etapa_ordem INT NOT NULL DEFAULT 0,
  nome VARCHAR(160) NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  done_at DATETIME NULL,
  done_by INT NULL,
  relatorio_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_impl_it_impl (company_id, implantacao_id, etapa_ordem, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relatórios de atendimento técnico local. status RASCUNHO / CONFIRMADO (ao confirmar, as
-- tarefas marcadas ficam executadas). Assinatura do cliente: imagem PNG fora da pasta
-- pública; pelo celular do técnico (LOCAL) ou por link (LINK, só o hash do código fica gravado).
CREATE TABLE IF NOT EXISTS impl_relatorios (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  implantacao_id INT NOT NULL,
  numero INT NOT NULL DEFAULT 0,
  data DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fim TIME NOT NULL,
  implantador_user_id INT NULL,
  participantes TEXT NULL,
  observacoes TEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'RASCUNHO',
  confirmado_em DATETIME NULL,
  confirmado_por INT NULL,
  assinatura_arquivo VARCHAR(120) NULL,
  assinatura_nome VARCHAR(120) NULL,
  assinatura_em DATETIME NULL,
  assinatura_ip VARCHAR(45) NULL,
  assinatura_origem VARCHAR(8) NULL,
  link_hash CHAR(64) NULL,
  link_expira DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_impl_rel_company (company_id, data),
  KEY idx_impl_rel_impl (company_id, implantacao_id),
  KEY idx_impl_rel_link (link_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS impl_relatorio_tarefas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  relatorio_id INT NOT NULL,
  impl_tarefa_id INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_impl_rel_tarefa (company_id, relatorio_id, impl_tarefa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos dos equipamentos instalados (impressoras, hardware, periféricos), fora da pasta pública.
CREATE TABLE IF NOT EXISTS impl_fotos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  implantacao_id INT NOT NULL,
  arquivo VARCHAR(120) NOT NULL,
  legenda VARCHAR(200) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_impl_fotos_impl (company_id, implantacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
