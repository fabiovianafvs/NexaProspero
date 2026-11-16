-- Nexa Ponto - V1 Fundação: cadastros do controle de jornada.
-- Módulo isolado: nenhuma tabela existente é alterada. As configurações ficam em
-- app_config (chave 'ponto'); o módulo é liberado por empresa em Módulos do SaaS
-- ("Ponto Eletrônico", desligado por padrão). Marcações/ARP/NSR entram nas próximas versões.

CREATE TABLE IF NOT EXISTS ponto_estabelecimentos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  tipo_documento VARCHAR(5) NOT NULL DEFAULT 'CNPJ',
  documento VARCHAR(14) NOT NULL,
  razao_social VARCHAR(150) NOT NULL,
  nome_fantasia VARCHAR(150) NULL,
  caepf VARCHAR(14) NULL,
  cno VARCHAR(12) NULL,
  cep VARCHAR(8) NULL,
  endereco VARCHAR(150) NULL,
  numero VARCHAR(20) NULL,
  complemento VARCHAR(80) NULL,
  bairro VARCHAR(80) NULL,
  cidade VARCHAR(80) NULL,
  uf CHAR(2) NULL,
  timezone VARCHAR(40) NOT NULL DEFAULT 'America/Sao_Paulo',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_estab_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_departamentos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  descricao VARCHAR(80) NOT NULL,
  responsavel_id INT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_dep_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_cargos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  descricao VARCHAR(80) NOT NULL,
  cbo VARCHAR(6) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_cargo_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jornada: horários previstos por dia da semana (1=domingo ... 7=sábado; 0=todos os dias,
-- usado em escalas de ciclo como 12x36). hora_fim menor que hora_inicio = passa da meia-noite.
CREATE TABLE IF NOT EXISTS ponto_jornadas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  descricao VARCHAR(80) NOT NULL,
  carga_diaria_minutos INT NOT NULL DEFAULT 0,
  carga_semanal_minutos INT NOT NULL DEFAULT 0,
  tolerancia_entrada INT NOT NULL DEFAULT 5,
  tolerancia_saida INT NOT NULL DEFAULT 5,
  tolerancia_intervalo INT NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_jor_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_jornada_periodos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  jornada_id INT NOT NULL,
  dia_semana TINYINT NOT NULL,
  ordem TINYINT NOT NULL DEFAULT 1,
  hora_inicio TIME NOT NULL,
  hora_fim TIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_jper (company_id, jornada_id, dia_semana, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Escala: base SEMANA (dia 1..7 = domingo..sábado, ex.: 5x2, 6x1) ou CICLO (dia 1..N
-- contado a partir do início do ciclo do colaborador, ex.: 12x36). jornada_id NULL = folga.
CREATE TABLE IF NOT EXISTS ponto_escalas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  descricao VARCHAR(80) NOT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'PERSONALIZADA',
  base VARCHAR(10) NOT NULL DEFAULT 'SEMANA',
  ciclo_dias INT NOT NULL DEFAULT 7,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_esc_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_escala_dias (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  escala_id INT NOT NULL,
  dia INT NOT NULL,
  jornada_id INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_esc_dia (escala_id, dia),
  KEY idx_ponto_escd_company (company_id, escala_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_locais (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  estabelecimento_id INT NULL,
  descricao VARCHAR(100) NOT NULL,
  endereco VARCHAR(200) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  raio_metros INT NOT NULL DEFAULT 100,
  exigir_localizacao TINYINT(1) NOT NULL DEFAULT 0,
  alertar_fora_area TINYINT(1) NOT NULL DEFAULT 1,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_local_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ponto_feriados (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  descricao VARCHAR(100) NOT NULL,
  data DATE NOT NULL,
  tipo VARCHAR(15) NOT NULL DEFAULT 'NACIONAL',
  uf CHAR(2) NULL,
  cidade VARCHAR(80) NULL,
  estabelecimento_id INT NULL,
  recorrente TINYINT(1) NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_fer_data (company_id, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colaborador: não precisa ter usuário no Nexa (usuario_id opcional). A foto fica fora da
-- pasta pública (foto_path relativo). Cartão RFID guardado só como hash + 4 últimos dígitos.
CREATE TABLE IF NOT EXISTS ponto_colaboradores (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  estabelecimento_id INT NULL,
  usuario_id INT NULL,
  matricula VARCHAR(20) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  cpf CHAR(11) NOT NULL,
  data_nascimento DATE NULL,
  data_admissao DATE NOT NULL,
  data_demissao DATE NULL,
  cargo_id INT NULL,
  departamento_id INT NULL,
  jornada_id INT NULL,
  local_id INT NULL,
  email VARCHAR(150) NULL,
  telefone VARCHAR(20) NULL,
  foto_path VARCHAR(255) NULL,
  foto_hash CHAR(64) NULL,
  foto_mime VARCHAR(40) NULL,
  foto_updated_at DATETIME NULL,
  rfid_hash CHAR(64) NULL,
  rfid_final VARCHAR(4) NULL,
  codigo_interno VARCHAR(30) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'ATIVO',
  controla_ponto TINYINT(1) NOT NULL DEFAULT 1,
  permite_ponto_mobile TINYINT(1) NOT NULL DEFAULT 0,
  permite_ponto_externo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_col_cpf (company_id, cpf),
  UNIQUE KEY uk_ponto_col_matricula (company_id, matricula),
  UNIQUE KEY uk_ponto_col_usuario (company_id, usuario_id),
  UNIQUE KEY uk_ponto_col_rfid (company_id, rfid_hash),
  KEY idx_ponto_col_status (company_id, status),
  KEY idx_ponto_col_dep (company_id, departamento_id),
  KEY idx_ponto_col_estab (company_id, estabelecimento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de escalas do colaborador: trocar de escala encerra a vigência anterior
-- (vigencia_fim) e cria uma nova linha; nada é sobrescrito.
CREATE TABLE IF NOT EXISTS ponto_colaborador_escalas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  escala_id INT NOT NULL,
  vigencia_inicio DATE NOT NULL,
  vigencia_fim DATE NULL,
  ciclo_inicio DATE NULL,
  observacao VARCHAR(200) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ponto_colesc (company_id, colaborador_id, vigencia_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria administrativa do Ponto (não confundir com o registro legal das marcações).
CREATE TABLE IF NOT EXISTS ponto_auditoria (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  usuario_id INT NULL,
  colaborador_id INT NULL,
  acao VARCHAR(30) NOT NULL,
  entidade VARCHAR(40) NOT NULL,
  entidade_id BIGINT NULL,
  dados_anteriores_json MEDIUMTEXT NULL,
  dados_novos_json MEDIUMTEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_aud_data (company_id, created_at),
  KEY idx_ponto_aud_ent (company_id, entidade, entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
