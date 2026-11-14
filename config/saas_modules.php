<?php
declare(strict_types=1);

/**
 * Controle de módulos comercializáveis por plano e por empresa.
 *
 * Regras:
 * 1) O plano libera módulos padrão em saas_plan_modules.
 * 2) A empresa pode personalizar em saas_company_modules.
 * 3) A personalização da empresa tem prioridade sobre o plano.
 * 4) Se a estrutura ainda não existir, o sistema mantém Delivery como fallback.
 */

if (!function_exists('saas_sql_identifier_ok')) {
  function saas_sql_identifier_ok(string $name): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
  }
}

if (!function_exists('saas_module_table_exists')) {
  function saas_module_table_exists(PDO $pdo, string $table): bool {
    if (!saas_sql_identifier_ok($table)) return false;

    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . strtolower($table);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    try {
      $st = $pdo->prepare('SHOW TABLES LIKE ?');
      $st->execute([$table]);
      if ($st->fetchColumn() !== false) return $cache[$cacheKey] = true;
    } catch (Throwable $e) {}

    try {
      $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
      return $cache[$cacheKey] = true;
    } catch (Throwable $e) {}

    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
      $st->execute([$table]);
      return $cache[$cacheKey] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return $cache[$cacheKey] = false;
    }
  }
}

if (!function_exists('saas_module_column_exists')) {
  function saas_module_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!saas_sql_identifier_ok($table) || !saas_sql_identifier_ok($column)) return false;

    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . strtolower($table) . ':' . strtolower($column);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    try {
      $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
      $st->execute([$column]);
      if ($st->fetch(PDO::FETCH_ASSOC)) return $cache[$cacheKey] = true;
    } catch (Throwable $e) {}

    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
      $st->execute([$table, $column]);
      return $cache[$cacheKey] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return $cache[$cacheKey] = false;
    }
  }
}

if (!function_exists('saas_add_column_if_missing')) {
  function saas_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition, array &$errors = []): void {
    if (!saas_sql_identifier_ok($table) || !saas_sql_identifier_ok($column)) return;
    if (saas_module_column_exists($pdo, $table, $column)) return;

    try {
      $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    } catch (Throwable $e) {
      $errors[] = 'Não foi possível criar a coluna ' . $table . '.' . $column . ': ' . $e->getMessage();
    }
  }
}


if (!function_exists('saas_company_module_mode')) {
  function saas_company_module_mode(PDO $pdo, int $companyId): string {
    if ($companyId <= 0) return 'plan';
    if (!saas_module_table_exists($pdo, 'companies') || !saas_module_column_exists($pdo, 'companies', 'module_mode')) {
      return 'plan';
    }

    try {
      $st = $pdo->prepare('SELECT module_mode FROM companies WHERE id = ? LIMIT 1');
      $st->execute([$companyId]);
      $mode = strtolower(trim((string)($st->fetchColumn() ?: 'plan')));
      return $mode === 'custom' ? 'custom' : 'plan';
    } catch (Throwable $e) {
      return 'plan';
    }
  }
}

if (!function_exists('saas_set_company_module_mode')) {
  function saas_set_company_module_mode(PDO $pdo, int $companyId, string $mode): void {
    if ($companyId <= 0) return;
    if (!saas_module_table_exists($pdo, 'companies') || !saas_module_column_exists($pdo, 'companies', 'module_mode')) {
      return;
    }

    $mode = strtolower(trim($mode)) === 'custom' ? 'custom' : 'plan';
    try {
      $st = $pdo->prepare('UPDATE companies SET module_mode = ? WHERE id = ?');
      $st->execute([$mode, $companyId]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('saas_try_exec')) {
  function saas_try_exec(PDO $pdo, string $sql, array &$errors = []): void {
    try {
      $pdo->exec($sql);
    } catch (Throwable $e) {
      $errors[] = $e->getMessage();
    }
  }
}

if (!function_exists('saas_modules_available')) {
  function saas_modules_available(PDO $pdo): bool {
    static $cache = [];
    $cacheKey = spl_object_id($pdo);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    return $cache[$cacheKey] = (
      saas_module_table_exists($pdo, 'saas_modules')
      && saas_module_table_exists($pdo, 'saas_plan_modules')
      && saas_module_table_exists($pdo, 'saas_company_modules')
    );
  }
}

if (!function_exists('saas_ensure_modules_schema')) {
  function saas_ensure_modules_schema(PDO $pdo): array {
    // Migração defensiva: no máximo uma execução por processo/requisição.
    // As páginas normais não chamam mais esta função.
    static $results = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($results[$cacheKey])) return $results[$cacheKey];

    // Depois da migração v2.3, chamadas legadas fazem somente esta leitura.
    try {
      $stVersion = $pdo->query("SELECT version_number FROM nexa_schema_versions WHERE module_name='runtime_performance' LIMIT 1");
      $runtimeReady = (int)($stVersion ? $stVersion->fetchColumn() : 0) >= 2026072601;
      $quantityReady = saas_module_column_exists($pdo, 'saas_modules', 'allow_quantity')
        && saas_module_column_exists($pdo, 'saas_modules', 'quantity_label')
        && saas_module_column_exists($pdo, 'saas_modules', 'quantity_min')
        && saas_module_column_exists($pdo, 'saas_modules', 'quantity_max')
        && saas_module_column_exists($pdo, 'saas_modules', 'quantity_default')
        && saas_module_column_exists($pdo, 'saas_company_modules', 'quantity');
      if ($runtimeReady && $quantityReady) {
        return $results[$cacheKey] = ['ok' => true, 'errors' => []];
      }
    } catch (Throwable $e) {}

    $errors = [];

    saas_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_modules (
        id INT NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        item_type VARCHAR(20) NOT NULL DEFAULT 'module',
        monthly_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        setup_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        allow_quantity TINYINT(1) NOT NULL DEFAULT 0,
        quantity_label VARCHAR(60) NULL,
        quantity_min INT UNSIGNED NOT NULL DEFAULT 1,
        quantity_max INT UNSIGNED NOT NULL DEFAULT 99,
        quantity_default INT UNSIGNED NOT NULL DEFAULT 1,
        is_public_sale TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_saas_modules_code (code)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    saas_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_plan_modules (
        plan_id INT NOT NULL,
        module_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (plan_id, module_id),
        KEY idx_saas_plan_modules_module (module_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    saas_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_company_modules (
        company_id INT NOT NULL,
        module_id INT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        expires_at DATETIME NULL,
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (company_id, module_id),
        KEY idx_saas_company_modules_module (module_id),
        KEY idx_saas_company_modules_company (company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    if (saas_module_table_exists($pdo, 'saas_modules')) {
      saas_add_column_if_missing($pdo, 'saas_modules', 'code', 'VARCHAR(50) NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'name', 'VARCHAR(100) NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'description', 'TEXT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'item_type', "VARCHAR(20) NOT NULL DEFAULT 'module'", $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'monthly_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'monthly_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'setup_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'setup_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'allow_quantity', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'quantity_label', 'VARCHAR(60) NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'quantity_min', 'INT UNSIGNED NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'quantity_max', 'INT UNSIGNED NOT NULL DEFAULT 99', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'quantity_default', 'INT UNSIGNED NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'is_public_sale', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'sort_order', 'INT NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
      saas_try_exec($pdo, 'ALTER TABLE saas_modules ADD UNIQUE KEY uk_saas_modules_code (code)', $errors);
    }

    if (saas_module_table_exists($pdo, 'saas_plan_modules')) {
      saas_add_column_if_missing($pdo, 'saas_plan_modules', 'plan_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_plan_modules', 'module_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_plan_modules', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
    }

    if (saas_module_table_exists($pdo, 'saas_company_modules')) {
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'company_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'module_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'quantity', 'DECIMAL(10,2) NOT NULL DEFAULT 1.00', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'expires_at', 'DATETIME NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'note', 'VARCHAR(255) NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'updated_at', 'DATETIME NULL', $errors);
    }

    if (saas_module_table_exists($pdo, 'companies')) {
      saas_add_column_if_missing($pdo, 'companies', 'module_mode', "VARCHAR(20) NOT NULL DEFAULT 'plan'", $errors);
    }

    // A tela de planos mostra os campos de precificação e limite somente quando
    // as respectivas colunas existem. Bancos antigos podem ter a tabela plans,
    // mas não possuir max_orders e outras colunas comerciais. A validação passa
    // a completar essa estrutura automaticamente, sem depender de posição/AFTER.
    if (saas_module_table_exists($pdo, 'plans')) {
      saas_add_column_if_missing($pdo, 'plans', 'slug', 'VARCHAR(80) NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'headline', 'VARCHAR(160) NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'summary', 'TEXT NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'original_price', 'DECIMAL(10,2) NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'monthly_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'setup_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'setup_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'max_orders', 'INT UNSIGNED NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'trial_days', 'INT UNSIGNED NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'billing_days', 'INT UNSIGNED NOT NULL DEFAULT 30', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'badge_text', 'VARCHAR(80) NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'button_label', 'VARCHAR(120) NULL', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'sort_order', 'INT NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'is_public', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'plans', 'internal_note', 'VARCHAR(255) NULL', $errors);

      // Normaliza a identificação das três faixas quando bancos antigos têm
      // apenas o nome do plano. Isso também faz as faixas aparecerem no painel
      // da revenda, que utiliza os slugs essencial/profissional/enterprise.
      try {
        if (saas_module_column_exists($pdo, 'plans', 'slug')) {
          $pdo->exec("UPDATE plans SET slug = 'essencial' WHERE (slug IS NULL OR TRIM(slug) = '') AND LOWER(TRIM(COALESCE(name,''))) IN ('delivery start','essencial')");
          $pdo->exec("UPDATE plans SET slug = 'profissional' WHERE (slug IS NULL OR TRIM(slug) = '') AND LOWER(TRIM(COALESCE(name,''))) IN ('delivery pro','profissional')");
          $pdo->exec("UPDATE plans SET slug = 'enterprise' WHERE (slug IS NULL OR TRIM(slug) = '') AND LOWER(TRIM(COALESCE(name,''))) IN ('delivery premium','premium','enterprise')");
        }
      } catch (Throwable $e) {
        $errors[] = 'Não foi possível normalizar os identificadores dos planos: ' . $e->getMessage();
      }

      // Preenche somente limites vazios dos três planos conhecidos. Valores
      // personalizados pelo administrador não são sobrescritos.
      if (saas_module_column_exists($pdo, 'plans', 'max_orders')) {
        try {
          $pdo->exec("UPDATE plans SET max_orders = 100 WHERE (max_orders IS NULL OR max_orders = 0) AND (LOWER(COALESCE(slug,'')) = 'essencial' OR LOWER(TRIM(COALESCE(name,''))) IN ('delivery start','essencial'))");
          $pdo->exec("UPDATE plans SET max_orders = 500 WHERE (max_orders IS NULL OR max_orders = 0) AND (LOWER(COALESCE(slug,'')) = 'profissional' OR LOWER(TRIM(COALESCE(name,''))) IN ('delivery pro','profissional'))");
          $pdo->exec("UPDATE plans SET max_orders = 1000 WHERE (max_orders IS NULL OR max_orders = 0) AND (LOWER(COALESCE(slug,'')) = 'enterprise' OR LOWER(TRIM(COALESCE(name,''))) IN ('delivery premium','premium','enterprise'))");
        } catch (Throwable $e) {
          $errors[] = 'Não foi possível preencher os limites padrão dos planos: ' . $e->getMessage();
        }
      }
    }

    if (saas_module_table_exists($pdo, 'saas_modules')) {
      try { saas_seed_default_modules($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }

    if (saas_modules_available($pdo) && saas_module_table_exists($pdo, 'plans')) {
      try {
        $deliveryId = saas_module_id_by_code($pdo, 'delivery');
        if ($deliveryId > 0) {
          $count = (int)$pdo->query('SELECT COUNT(*) FROM saas_plan_modules')->fetchColumn();
          if ($count === 0) {
            $st = $pdo->query('SELECT id FROM plans WHERE is_active = 1');
            $ins = $pdo->prepare('INSERT IGNORE INTO saas_plan_modules (plan_id, module_id) VALUES (?, ?)');
            foreach (($st ? $st->fetchAll(PDO::FETCH_COLUMN) : []) as $pid) {
              $ins->execute([(int)$pid, $deliveryId]);
            }
          }
        }
      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }

    // Erros de índice duplicado são esperados quando a estrutura já está ok.
    $errors = array_values(array_filter($errors, function ($msg) {
      return stripos((string)$msg, 'Duplicate key name') === false
        && stripos((string)$msg, 'Multiple primary key') === false
        && stripos((string)$msg, 'Duplicate column name') === false;
    }));

    return $results[$cacheKey] = [
      'ok' => saas_modules_available($pdo),
      'errors' => $errors,
    ];
  }
}

if (!function_exists('saas_seed_default_modules')) {
  function saas_seed_default_modules(PDO $pdo): void {
    if (!saas_module_table_exists($pdo, 'saas_modules')) return;

    $defaults = [
      ['delivery', 'Delivery', 'Catálogo, carrinho, pedidos online, produtos e WhatsApp.', 'module', 0.00, 59.90, 0.00, 350.00, 0, 10],
      ['exportacao', 'Exportação / Firebird', 'Fila de exportação, execução manual e integração Firebird/Próspero.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 15],
      ['importacao_produtos', 'Importação de Produtos', 'Importação temporária de produtos de uma base Firebird para uma empresa específica.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 16],
      ['api', 'API externa', 'Token e endpoints externos para integração com produtos, pedidos e WhatsApp.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 18],
      ['financeiro', 'Financeiro', 'Contas a receber, contas a pagar, caixa, bancos, plano de contas e centro de custo.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 20],
      ['contratos', 'Contratos', 'Motor de recorrência e faturamento com contratos, competências, geração de pedidos e cobranças.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 23],
      ['orcamento', 'Orçamento', 'Orçamentos comerciais com cliente, vendedor, profissional, produtos/serviços, reserva de estoque, condição de pagamento e entrega opcional.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 24],
      ['controle_entregas', 'Controle de Entregas', 'Entregas futuras e parciais com reserva de estoque, ordens de entrega, romaneio, separação, veículos e fila fiscal isolada.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 26],
      ['compras', 'Compras', 'Assistente de compras, listas, cotações, comparativo e pedidos de compra.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 24],
      ['ordem_servico', 'Ordem de Serviço', 'Ordens de serviço com campos personalizados, produtos, serviços, reservas, pagamentos, impressão e faturamento no PDV.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 25],
      ['multiempresa', 'Multiempresa', 'Acesso do mesmo usuário a múltiplas empresas, com suporte a grupos integrados ou isolados e troca segura de contexto.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 27],
      ['crm', 'CRM', 'Atendimentos, próximos contatos, tramitações, responsáveis, status e tipos de atendimento.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 28],
      ['whatsapp_chatbot', 'Chatbot WhatsApp', 'Atendimento automático no WhatsApp: 2ª via de boleto, consulta de pedido, abertura de atendimento no CRM, mensagens automáticas e transferência para atendente.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 29],
      ['ia_agentes', 'Agentes de IA', 'Agentes de inteligência artificial: preparo automático da entrada de notas do Radar (vínculo e cadastro de produtos, fator de conversão, CFOP e preço).', 'module', 0.00, 0.00, 0.00, 0.00, 0, 29],
      ['gestao', 'Gestão Fiscal', 'Fiscal, estoque, compras, vendas, caixa, financeiro e relatórios.', 'module', 0.00, 189.90, 0.00, 375.00, 1, 30],
      ['conferencia_estoque', 'Conferência de Estoque', 'Contagem física, prévia de diferenças e acerto auditado dos saldos de estoque.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 32],
      ['composicao_produtos', 'Composição de Produtos', 'Ficha técnica de produtos compostos, consumo automático de matérias-primas e estoque teórico proporcional.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 33],
      ['ordem_producao', 'Ordem de Produção', 'Ficha técnica de produção, consumo controlado de matérias-primas, variações/perdas e entrada de produtos acabados.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 34],
      ['fiscal', 'Gestão fiscal interna', 'NF-e, NFC-e, entrada por XML, XMLs, Sintegra, SPED e painel do contador.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 40],
      ['comandas', 'Comandas', 'Mesas, pedidos pelo smartphone, transferências, fechamento e impressão de cozinha.', 'module', 0.00, 59.90, 0.00, 150.00, 1, 35],
      ['impressao', 'Agente de Impressão', 'Impressão automática na cozinha, bar, caixa e outros setores.', 'module', 0.00, 29.90, 0.00, 0.00, 1, 36],
    ];

    // Insere somente o que não existe. Dados editados pelo administrador (nome, preços,
    // visibilidade, ordem e status) nunca são sobrescritos durante uma validação de schema.
    $sql = "
      INSERT INTO saas_modules
        (code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price, is_public_sale, sort_order, is_active)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
      ON DUPLICATE KEY UPDATE code = VALUES(code)
    ";
    $st = $pdo->prepare($sql);
    foreach ($defaults as $m) {
      $st->execute($m);
    }
  }
}

if (!function_exists('saas_all_modules')) {
  function saas_all_modules(PDO $pdo, bool $activeOnly = true): array {
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . ($activeOnly ? '1' : '0');
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    if (!saas_module_table_exists($pdo, 'saas_modules')) {
      return $cache[$cacheKey] = [[
        'id' => 0,
        'code' => 'delivery',
        'name' => 'Delivery',
        'description' => 'Catálogo, carrinho, pedidos online e WhatsApp.',
        'is_active' => 1,
        'sort_order' => 10,
      ]];
    }

    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    try {
      $st = $pdo->query("SELECT * FROM saas_modules {$where} ORDER BY sort_order ASC, name ASC, id ASC");
      return $cache[$cacheKey] = ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []);
    } catch (Throwable $e) {
      return $cache[$cacheKey] = [];
    }
  }
}

if (!function_exists('saas_module_id_by_code')) {
  function saas_module_id_by_code(PDO $pdo, string $code): int {
    if (!saas_module_table_exists($pdo, 'saas_modules')) return 0;
    $st = $pdo->prepare('SELECT id FROM saas_modules WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    return (int)($st->fetchColumn() ?: 0);
  }
}

if (!function_exists('saas_latest_subscription_module_plan_id')) {
  function saas_latest_subscription_module_plan_id(PDO $pdo, int $companyId): int {
    try {
      $st = $pdo->prepare('SELECT plan_id FROM subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1');
      $st->execute([$companyId]);
      return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('saas_any_plan_modules_configured')) {
  function saas_any_plan_modules_configured(PDO $pdo): bool {
    if (!saas_module_table_exists($pdo, 'saas_plan_modules')) return false;
    try {
      return (int)$pdo->query('SELECT COUNT(*) FROM saas_plan_modules')->fetchColumn() > 0;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('saas_plan_module_ids')) {
  function saas_plan_module_ids(PDO $pdo, int $planId): array {
    if ($planId <= 0 || !saas_module_table_exists($pdo, 'saas_plan_modules')) return [];
    try {
      $st = $pdo->prepare('SELECT module_id FROM saas_plan_modules WHERE plan_id = ?');
      $st->execute([$planId]);
      return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_plan_module_codes')) {
  function saas_plan_module_codes(PDO $pdo, int $planId): array {
    if ($planId <= 0 || !saas_modules_available($pdo)) return [];
    try {
      $st = $pdo->prepare('
        SELECT m.code
        FROM saas_plan_modules pm
        JOIN saas_modules m ON m.id = pm.module_id
        WHERE pm.plan_id = ? AND m.is_active = 1
        ORDER BY m.sort_order ASC, m.name ASC
      ');
      $st->execute([$planId]);
      return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_company_module_overrides')) {
  function saas_company_module_overrides(PDO $pdo, int $companyId): array {
    if ($companyId <= 0 || !saas_module_table_exists($pdo, 'saas_company_modules')) return [];
    try {
      $st = $pdo->prepare('
        SELECT module_id, enabled, quantity, expires_at, note
        FROM saas_company_modules
        WHERE company_id = ?
      ');
      $st->execute([$companyId]);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $expires = trim((string)($r['expires_at'] ?? ''));
        $expired = $expires !== '' && strtotime($expires) !== false && strtotime($expires) < time();
        if ($expired) continue;
        $out[(int)$r['module_id']] = $r;
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_company_module_state')) {
  function saas_company_module_state(PDO $pdo, int $companyId): array {
    $modules = saas_all_modules($pdo, true);
    if (!saas_modules_available($pdo)) {
      return [[
        'id' => 0,
        'code' => 'delivery',
        'name' => 'Delivery',
        'description' => 'Catálogo, carrinho, pedidos online e WhatsApp.',
        'from_plan' => true,
        'override' => null,
        'enabled' => true,
        'expires_at' => null,
        'note' => null,
      ]];
    }

    $planId = saas_latest_subscription_module_plan_id($pdo, $companyId);
    $planIds = array_fill_keys(saas_plan_module_ids($pdo, $planId), true);

    if (!$planIds && !saas_any_plan_modules_configured($pdo)) {
      $deliveryId = saas_module_id_by_code($pdo, 'delivery');
      if ($deliveryId > 0) $planIds[$deliveryId] = true;
    }

    // Modo PLAN: ignora overrides antigos/temporários e segue exclusivamente o plano.
    // Modo CUSTOM: aplica a personalização da empresa com prioridade sobre o plano.
    $moduleMode = saas_company_module_mode($pdo, $companyId);
    $overrides = $moduleMode === 'custom' ? saas_company_module_overrides($pdo, $companyId) : [];
    $state = [];
    foreach ($modules as $m) {
      $mid = (int)$m['id'];
      $fromPlan = isset($planIds[$mid]);
      $override = array_key_exists($mid, $overrides) ? (int)$overrides[$mid]['enabled'] : null;
      $enabled = $override === null ? $fromPlan : ($override === 1);
      $state[] = [
        'id' => $mid,
        'code' => (string)$m['code'],
        'name' => (string)$m['name'],
        'description' => (string)($m['description'] ?? ''),
        'from_plan' => $fromPlan,
        'override' => $override,
        'enabled' => $enabled,
        'quantity' => max(1.0, (float)($overrides[$mid]['quantity'] ?? 1)),
        'expires_at' => $overrides[$mid]['expires_at'] ?? null,
        'note' => $overrides[$mid]['note'] ?? null,
        'module_mode' => $moduleMode,
      ];
    }
    return $state;
  }
}

if (!function_exists('saas_company_module_codes_direct')) {
  function saas_company_module_codes_direct(PDO $pdo, int $companyId): array {
    if ($companyId <= 0 || !saas_modules_available($pdo)) return [];

    try {
      $st = $pdo->prepare('\n        SELECT DISTINCT m.code\n        FROM saas_company_modules cm\n        INNER JOIN saas_modules m ON m.id = cm.module_id\n        WHERE cm.company_id = ?\n          AND cm.enabled = 1\n          AND m.is_active = 1\n          AND (cm.expires_at IS NULL OR CAST(cm.expires_at AS CHAR) = \'0000-00-00 00:00:00\' OR cm.expires_at >= NOW())\n        ORDER BY m.sort_order ASC, m.name ASC\n      ');
      $st->execute([$companyId]);
      return array_values(array_unique(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)))));
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_company_module_codes')) {
  function saas_company_module_codes(PDO $pdo, int $companyId): array {
    if ($companyId <= 0) return [];

    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $companyId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    // O schema é responsabilidade das migrações. A leitura normal nunca
    // executa CREATE/ALTER/INSERT de estrutura.
    $codes = [];
    foreach (saas_company_module_state($pdo, $companyId) as $m) {
      if (!empty($m['enabled'])) $codes[] = (string)$m['code'];
    }

    // Quando a coluna module_mode existe, o estado calculado acima é a fonte
    // oficial de verdade. Não reanexa registros diretos antigos, pois isso
    // fazia módulos desabilitados (ex.: O.S./Orçamento) reaparecerem no menu.
    // O fallback direto fica somente para bases realmente legadas, sem module_mode.
    $hasModuleMode = saas_module_table_exists($pdo, 'companies')
      && saas_module_column_exists($pdo, 'companies', 'module_mode');
    if (!$hasModuleMode) {
      $direct = saas_company_module_codes_direct($pdo, $companyId);
      if ($direct) $codes = array_merge($codes, $direct);
    }

    return $cache[$cacheKey] = array_values(array_unique(array_filter(
      $codes,
      static fn($v) => (string)$v !== ''
    )));
  }
}

if (!function_exists('saas_company_module_quantities')) {
  function saas_company_module_quantities(PDO $pdo, int $companyId): array {
    if ($companyId <= 0 || !saas_module_table_exists($pdo, 'saas_company_modules')) return [];
    try {
      $hasQuantity = saas_module_column_exists($pdo, 'saas_company_modules', 'quantity');
      $quantitySql = $hasQuantity ? 'cm.quantity' : '1';
      $st = $pdo->prepare("
        SELECT m.code, {$quantitySql} AS quantity
        FROM saas_company_modules cm
        INNER JOIN saas_modules m ON m.id = cm.module_id
        WHERE cm.company_id = ?
          AND cm.enabled = 1
          AND m.is_active = 1
          AND (cm.expires_at IS NULL OR CAST(cm.expires_at AS CHAR) = '0000-00-00 00:00:00' OR cm.expires_at >= NOW())
      ");
      $st->execute([$companyId]);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $code = strtolower(trim((string)($row['code'] ?? '')));
        if ($code !== '') $out[$code] = max(1.0, (float)($row['quantity'] ?? 1));
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_company_has_module')) {
  function saas_company_has_module(PDO $pdo, int $companyId, string $code): bool {
    static $maps = [];
    $cacheKey = spl_object_id($pdo) . ':' . $companyId;
    if (!isset($maps[$cacheKey])) {
      $maps[$cacheKey] = array_fill_keys(saas_company_module_codes($pdo, $companyId), true);
    }
    return isset($maps[$cacheKey][$code]);
  }
}

if (!function_exists('saas_company_has_any_module')) {
  function saas_company_has_any_module(PDO $pdo, int $companyId): bool {
    return count(saas_company_module_codes($pdo, $companyId)) > 0;
  }
}

if (!function_exists('saas_save_plan_modules')) {
  function saas_save_plan_modules(PDO $pdo, int $planId, array $moduleIds): void {
    if ($planId <= 0 || !saas_modules_available($pdo)) return;
    $moduleIds = array_values(array_unique(array_filter(array_map('intval', $moduleIds), fn($v) => $v > 0)));
    $pdo->prepare('DELETE FROM saas_plan_modules WHERE plan_id = ?')->execute([$planId]);
    if (!$moduleIds) return;
    $st = $pdo->prepare('INSERT IGNORE INTO saas_plan_modules (plan_id, module_id) VALUES (?, ?)');
    foreach ($moduleIds as $moduleId) {
      $st->execute([$planId, $moduleId]);
    }
  }
}

if (!function_exists('saas_save_company_modules_custom')) {
  function saas_save_company_modules_custom(PDO $pdo, int $companyId, array $selectedModuleIds, ?string $expiresAt = null, string $note = '', array $quantities = []): void {
    if ($companyId <= 0 || !saas_modules_available($pdo)) return;
    saas_set_company_module_mode($pdo, $companyId, 'custom');
    $selected = array_fill_keys(array_values(array_unique(array_filter(array_map('intval', $selectedModuleIds), fn($v) => $v > 0))), true);
    $expiresSql = null;
    if ($expiresAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
      $expiresSql = $expiresAt . ' 23:59:59';
    }

    $modules = saas_all_modules($pdo, true);
    $st = $pdo->prepare('
      INSERT INTO saas_company_modules (company_id, module_id, enabled, quantity, expires_at, note, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        quantity = VALUES(quantity),
        expires_at = VALUES(expires_at),
        note = VALUES(note),
        updated_at = NOW()
    ');
    foreach ($modules as $m) {
      $mid = (int)$m['id'];
      $code = strtolower(trim((string)($m['code'] ?? '')));
      $allowQuantity = !empty($m['allow_quantity']);
      $minQuantity = max(1, (int)($m['quantity_min'] ?? 1));
      $maxQuantity = max($minQuantity, (int)($m['quantity_max'] ?? 99));
      $defaultQuantity = max($minQuantity, min($maxQuantity, (int)($m['quantity_default'] ?? 1)));
      $quantity = $allowQuantity
        ? max($minQuantity, min($maxQuantity, (int)($quantities[$code] ?? $defaultQuantity)))
        : 1;
      $st->execute([$companyId, $mid, isset($selected[$mid]) ? 1 : 0, $quantity, $expiresSql, $note !== '' ? $note : null]);
    }
  }
}

if (!function_exists('saas_reset_company_modules')) {
  function saas_reset_company_modules(PDO $pdo, int $companyId): void {
    if ($companyId <= 0) return;
    saas_set_company_module_mode($pdo, $companyId, 'plan');
    if (!saas_module_table_exists($pdo, 'saas_company_modules')) return;
    $pdo->prepare('DELETE FROM saas_company_modules WHERE company_id = ?')->execute([$companyId]);
  }
}

if (!function_exists('saas_module_label')) {
  function saas_module_label(string $code): string {
    return match ($code) {
      'delivery' => 'Delivery',
      'exportacao' => 'Exportação / Firebird',
      'importacao_produtos' => 'Importação de Produtos',
      'api' => 'API externa',
      'financeiro' => 'Financeiro',
      'contratos' => 'Contratos',
      'orcamento' => 'Orçamento',
      'controle_entregas' => 'Controle de Entregas',
      'compras' => 'Compras',
      'ordem_servico' => 'Ordem de Serviço',
      'multiempresa' => 'Multiempresa',
      'crm' => 'CRM',
      'whatsapp_chatbot' => 'Chatbot WhatsApp',
      'ia_agentes' => 'Agentes de IA',
      'gestao' => 'Gestão',
      'conferencia_estoque' => 'Conferência de Estoque',
      'composicao_produtos' => 'Composição de Produtos',
      'ordem_producao' => 'Ordem de Produção',
      'fiscal' => 'Fiscal',
      'comandas' => 'Comandas',
      'impressao' => 'Agente de Impressão',
      default => ucfirst(str_replace(['_', '-'], ' ', $code)),
    };
  }
}

if (!function_exists('saas_render_module_block_page')) {
  function saas_render_module_block_page(string $moduleCode, string $message = ''): void {
    http_response_code(403);
    $moduleName = saas_module_label($moduleCode);
    $message = $message !== '' ? $message : 'Este recurso não está liberado para o plano ou para os módulos contratados desta empresa.';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Módulo indisponível</title>
      <style>
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
        .box{width:min(92vw,640px);background:#fff;border:1px solid #e8eef5;border-radius:22px;padding:28px;box-shadow:0 12px 32px rgba(2,6,23,.08)}
        .tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:800;margin-bottom:14px}
        h1{margin:0 0 12px;font-size:30px;color:#0b1f5b}p{margin:0 0 10px;color:#475569;line-height:1.6}.btn{display:inline-flex;margin-top:16px;padding:12px 18px;border-radius:999px;background:#0b1f5b;color:#fff;text-decoration:none;font-weight:800}
      </style>
    </head>
    <body>
      <div class="box">
        <div class="tag">Módulo <?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?></div>
        <h1>Recurso não liberado</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <p>Entre em contato com a equipe Nexa Próspero para liberar este módulo.</p>
        <a class="btn" href="area_trabalho.php">Voltar ao início</a>
      </div>
    </body>
    </html>
    <?php
    exit;
  }
}

if (!function_exists('saas_require_company_module')) {
  function saas_require_company_module(PDO $pdo, int $companyId, string $moduleCode, string $contextLabel = ''): void {
    if (!saas_company_has_module($pdo, $companyId, $moduleCode)) {
      $ctx = $contextLabel !== '' ? $contextLabel : saas_module_label($moduleCode);
      saas_render_module_block_page($moduleCode, 'O acesso a ' . $ctx . ' depende da liberação do módulo ' . saas_module_label($moduleCode) . '.');
    }
  }
}

if (!function_exists('saas_admin_page_required_modules')) {
  function saas_admin_page_required_modules(string $page): array {
    $page = strtolower(basename($page));
    return match ($page) {
      'dashboard.php', 'relatorio_analise_dashboard.php', 'relatorio_estoque.php' => ['*'],
      'compras_assistente.php', 'compras_listas.php', 'compras_lista_editar.php', 'compras_cotacoes.php',
      'compras_cotacoes_matriz.php', 'compras_pedidos.php', 'compras_pedido_enviar.php',
      'compras_pedido_receber.php', 'compras_pedido_conciliacao.php', 'compras_pedido_conciliar_xml.php',
      'compras_painel.php', 'compras_alertas.php', 'compras_fornecedores_avaliacao.php',
      'compras_fornecedores_ranking.php', 'compras_historico_precos.php' => ['compras'],
      'pedidos.php', 'pedido.php', 'pedido_editar.php', 'pedido_regularizacao.php', 'pedido_status.php', 'pedido_print.php', 'pedido_print_conferencia.php', 'pedido_print_producao.php', 'pedido_relatorio_produtos.php', 'pedido_whatsapp.php' => ['delivery','gestao','fiscal','financeiro'],
      'produtos.php', 'produto_form.php', 'produto_gtin_lookup.php', 'estoque_consulta.php' => ['*'],
      'utilitarios_manutencao_produtos.php' => ['*'],
      'whatsapp.php', 'whatsapp_api.php', 'whatsapp_modelos.php', 'whatsapp_fila.php' => ['*'],
      'whatsapp_chatbot.php', 'whatsapp_chatbot_fluxo.php', 'whatsapp_atendimento.php', 'whatsapp_atendimento_api.php', 'whatsapp_midia.php' => ['whatsapp_chatbot'],
      'ia_configuracoes.php' => ['ia_agentes'],
      'gestao_clientes.php', 'gestao_clientes_importar.php' => ['delivery', 'gestao', 'financeiro'],
      'financeiro_pessoas.php' => ['delivery', 'gestao', 'financeiro', 'orcamento'],
      'exportacao.php', 'firebird.php', 'firebird_run.php' => ['exportacao'],
      'importacao.php' => ['importacao_produtos'],
      'estoque_conferencias.php', 'estoque_conferencia_form.php', 'estoque_conferencia_processar.php' => ['conferencia_estoque'],
      'api.php' => ['api'],
      'financeiro.php', 'financeiro_plano_contas.php',
      'financeiro_centros_custo.php', 'financeiro_caixas_bancos.php',
      'financeiro_contas_receber.php', 'financeiro_boletos.php', 'financeiro_boleto_itau.php', 'financeiro_boleto_imprimir.php', 'financeiro_boleto_whatsapp.php', 'financeiro_itau.php', 'financeiro_contas_pagar.php', 'financeiro_despesas_fixas.php',
      'financeiro_movimentos.php', 'financeiro_conciliacao.php', 'financeiro_resultado.php', 'configuracoes_cre.php', 'configuracoes_cpa.php', 'configuracoes_caixa.php', 'relatorio_inadimplencia.php', 'relatorio_fluxo_caixa.php' => ['financeiro'],
      'orcamentos.php', 'orcamento_editar.php', 'orcamento_ajax.php', 'orcamento_configuracoes.php' => ['orcamento'],
      'delivery_control.php', 'delivery_control_view.php', 'delivery_orders.php', 'delivery_manifest.php', 'delivery_manifest_view.php', 'delivery_separation_print.php', 'delivery_vehicles.php', 'delivery_fiscal_queue.php' => ['controle_entregas'],
      'contratos.php', 'contrato_editar.php', 'contratos_ajax.php', 'contratos_gerar.php', 'contratos_reajustes.php' => ['contratos'],
      'crm_atendimentos.php', 'crm_atendimento.php', 'crm_atendimento_mobile.php', 'crm_atendimento_mobile_lista.php', 'crm_tramitar.php', 'crm_ajax.php', 'crm_grid_preferences.php', 'crm_configuracoes.php', 'crm_kanban.php', 'crm_proposta.php', 'crm_proposta_imprimir.php', 'crm_anexo.php' => ['crm'],
      'os_monitor.php', 'os_editar.php', 'os_mobile.php', 'os_mobile_monitor.php', 'os_mobile_view.php', 'os_ajax.php', 'os_imprimir.php', 'os_faturar.php', 'os_configuracoes.php', 'os_layout_modelo.php', 'os_damage_photo.php', 'os_damage_photo_upload.php', 'os_pdv_hook.php', 'os_fiscal_documentos.php', 'os_fiscal_acao.php', 'os_fiscal_processar.php', 'os_fiscal_processar_checkout.php', 'os_fiscal_processar_ajax.php', 'os_faturas.php', 'os_fatura_imprimir.php' => ['ordem_servico'],
      'producao_ficha_tecnica.php', 'producao_monitor.php', 'producao_ordem.php', 'producao_configuracoes.php' => ['ordem_producao'],
      'gestao.php', 'gestao_pdv_resumido.php', 'gestao_pdv_checkout.php', 'gestao_pdv.php', 'gestao_pdv_mobile.php', 'gestao_pedido_detalhado.php', 'pedido_print_evento.php', 'gestao_pedidos.php', 'gestao_caixa.php',
      'gestao_vendedores.php', 'gestao_entrega_interno.php', 'relatorio_movimentos_caixa.php', 'relatorio_comissao_vendedores.php', 'commercial_credit_authorize.php' => ['gestao'],
      'gestao_comandas.php', 'gestao_comandas_checkout.php', 'gestao_comandas_mobile.php', 'gestao_comandas_config.php', 'gestao_comandas_qrcode.php', 'gestao_comandas_impressao.php', 'gestao_comandas_agentes.php', 'gestao_comandas_print_api.php' => ['comandas'],
      'pedido_print_cozinha.php' => ['comandas','gestao'],
      'gestao_formas_pagamento.php' => ['gestao','financeiro'],
      'fiscal.php', 'fiscal_radar_xml.php', 'fiscal_entrada_notas.php', 'fiscal_devolucoes.php', 'fiscal_devolucao_cliente.php', 'fiscal_saidas.php', 'fiscal_inutilizacao.php', 'fiscal_importar_xml.php', 'fiscal_nota_manual.php', 'fiscal_xml_entrada.php', 'fiscal_xml_conversoes.php', 'fiscal_operacoes.php', 'fiscal_tributacoes.php', 'fiscal_cfops.php', 'fiscal_ncms.php', 'fiscal_cests.php', 'fiscal_cst.php', 'fiscal_ibscbs.php', 'fiscal_importacoes.php', 'fiscal_tabelas.php', 'fiscal_lookup.php', 'fiscal_configuracoes.php', 'fiscal_documentos.php', 'fiscal_nfse_cancelar.php', 'fiscal_nfce_pedido.php', 'fiscal_nfse_nacional.php', 'fiscal_nfse_acao.php', 'fiscal_emissao_lote.php', 'fiscal_lote_contingencia.php', 'fiscal_relatorios.php', 'fiscal_relatorio_documentos.php', 'fiscal_relatorio_inutilizacoes.php', 'fiscal_relatorio_sintegra.php', 'fiscal_relatorio_sped_icms.php', 'fiscal_relatorio_sped_contrib.php', 'fiscal_relatorio_inventario.php', 'fiscal_exportar_xml.php', 'fiscal_contabilidade.php', 'fiscal_contabilidade_download.php', 'fiscal_contadores.php' => ['fiscal'],
      default => [],
    };
  }
}

if (!function_exists('saas_company_is_delivery_only')) {
  /**
   * Verdadeiro quando Delivery é o único módulo efetivamente liberado.
   * Usado para manter o painel Delivery enxuto e impedir vazamento de menus
   * administrativos/comerciais pertencentes aos demais módulos.
   */
  function saas_company_is_delivery_only(PDO $pdo, int $companyId): bool {
    if ($companyId <= 0) return false;

    // Usa o estado contratual calculado (plano/personalização), e não a leitura
    // direta de registros legados em saas_company_modules. Assim um override
    // antigo não faz O.S./Orçamento reaparecer em uma empresa cujo plano atual
    // possui somente Delivery.
    $codes = [];
    foreach (saas_company_module_state($pdo, $companyId) as $module) {
      if (empty($module['enabled'])) continue;
      $code = strtolower(trim((string)($module['code'] ?? '')));
      if ($code !== '') $codes[] = $code;
    }
    $codes = array_values(array_unique($codes));
    sort($codes, SORT_STRING);
    return $codes === ['delivery'];
  }
}

if (!function_exists('saas_delivery_only_nav_allowed')) {
  function saas_delivery_only_nav_allowed(string $key): bool {
    return in_array(strtolower(trim($key)), [
      'dashboard', 'relatorios', 'relatorio_analise_dashboard', 'brain', 'pedidos', 'produtos', 'clientes', 'whatsapp', 'whatsapp_modelos', 'whatsapp_fila', 'utilitarios', 'manutencao_produtos', 'usuarios', 'usuarios_perfis', 'params', 'suporte'
    ], true);
  }
}

if (!function_exists('saas_delivery_only_admin_page_allowed')) {
  /**
   * Além de esconder o menu, protege a URL direta. Páginas marcadas com '*'
   * são recursos comuns a qualquer empresa que possua ao menos um módulo ativo
   * (ex.: Produtos). Páginas Delivery permanecem liberadas normalmente.
   * Brain e Configurações Gerais são exceções explícitas do painel Delivery.
   */
  function saas_delivery_only_admin_page_allowed(string $page): bool {
    $page = strtolower(basename($page));
    if (in_array($page, [
      'login.php', 'logout.php', 'area_trabalho.php', 'dashboard.php', 'parametros.php',
      'brain.php', 'brain_api.php', 'brain_live_api.php',
      'brain_alertas.php', 'brain_alert_action.php', 'brain_configuracoes.php',
      'usuarios.php', 'usuario_form.php', 'usuario_email_teste.php', 'usuarios_perfis.php', 'usuario_perfil_form.php'
    ], true)) return true;

    $required = saas_admin_page_required_modules($page);
    return in_array('*', $required, true) || in_array('delivery', $required, true);
  }
}

if (!function_exists('saas_require_admin_page_module')) {
  function saas_require_admin_page_module(PDO $pdo, int $companyId, string $page): void {
    if (saas_company_is_delivery_only($pdo, $companyId) && !saas_delivery_only_admin_page_allowed($page)) {
      saas_render_module_block_page(
        'delivery',
        'Esta empresa possui somente o módulo Delivery. Este recurso não faz parte do painel contratado.'
      );
    }

    $required = saas_admin_page_required_modules($page);
    if (!$required) return;

    if (in_array('*', $required, true)) {
      if (!saas_company_has_any_module($pdo, $companyId)) {
        saas_render_module_block_page('delivery', 'Nenhum módulo está liberado para esta empresa.');
      }
      return;
    }

    foreach ($required as $code) {
      if (saas_company_has_module($pdo, $companyId, $code)) return;
    }

    saas_render_module_block_page((string)$required[0]);
  }
}

if (!function_exists('saas_admin_nav_allowed')) {
  function saas_admin_nav_allowed(PDO $pdo, int $companyId, string $key): bool {
    if (saas_company_is_delivery_only($pdo, $companyId)) {
      return saas_delivery_only_nav_allowed($key);
    }

    return match ($key) {
      'dashboard' => saas_company_has_any_module($pdo, $companyId),
      'pedidos' => (saas_company_has_module($pdo, $companyId, 'delivery') || saas_company_has_module($pdo, $companyId, 'gestao') || saas_company_has_module($pdo, $companyId, 'fiscal') || saas_company_has_module($pdo, $companyId, 'financeiro')),
      'produtos' => saas_company_has_any_module($pdo, $companyId),
      'utilitarios', 'manutencao_produtos' => saas_company_has_any_module($pdo, $companyId),
      'whatsapp', 'whatsapp_modelos', 'whatsapp_fila' => saas_company_has_any_module($pdo, $companyId),
      'whatsapp_chatbot', 'whatsapp_atendimento' => saas_company_has_module($pdo, $companyId, 'whatsapp_chatbot'),
      'ia_configuracoes' => saas_company_has_module($pdo, $companyId, 'ia_agentes'),
      'clientes' => saas_company_has_module($pdo, $companyId, 'delivery'),
      'export' => saas_company_has_module($pdo, $companyId, 'exportacao'),
      'importacao' => saas_company_has_module($pdo, $companyId, 'importacao_produtos'),
      'api' => saas_company_has_module($pdo, $companyId, 'api'),
      'financeiro', 'fin_dashboard', 'fin_receber', 'fin_pagar', 'fin_fixas', 'fin_mov', 'fin_boletos', 'fin_conciliacao',
      'fin_resultado', 'fin_plano', 'fin_centro', 'fin_caixas', 'configuracoes_cre', 'configuracoes_cpa', 'configuracoes_caixa', 'relatorio_inadimplencia', 'relatorio_fluxo_caixa' => saas_company_has_module($pdo, $companyId, 'financeiro'),
      'fin_pessoas' => (saas_company_has_module($pdo, $companyId, 'financeiro') || saas_company_has_module($pdo, $companyId, 'delivery') || saas_company_has_module($pdo, $companyId, 'orcamento')),
      'orcamento', 'orcamentos', 'orcamento_novo', 'orcamento_configuracoes' => saas_company_has_module($pdo, $companyId, 'orcamento'),
      'controle_entregas', 'delivery_control', 'delivery_orders', 'delivery_manifest', 'delivery_vehicles', 'delivery_fiscal_queue' => saas_company_has_module($pdo, $companyId, 'controle_entregas'),
      'contratos', 'contratos_monitor', 'contratos_gerar', 'contratos_reajustes' => saas_company_has_module($pdo, $companyId, 'contratos'),
      'crm', 'crm_atendimentos', 'crm_kanban', 'crm_atendimento_mobile', 'crm_configuracoes' => saas_company_has_module($pdo, $companyId, 'crm'),
      'compras', 'compras_assistente', 'compras_listas', 'compras_cotacoes', 'compras_pedidos',
      'compras_painel', 'compras_alertas', 'compras_fornecedores_avaliacao', 'compras_fornecedores_ranking',
      'compras_historico_precos' => saas_company_has_module($pdo, $companyId, 'compras'),
      'ordem_servico', 'os_monitor', 'os_nova', 'os_mobile', 'os_faturas', 'os_configuracoes', 'os_fiscal_documentos' => saas_company_has_module($pdo, $companyId, 'ordem_servico'),
      'producao', 'producao_ficha', 'producao_monitor', 'producao_configuracoes' => saas_company_has_module($pdo, $companyId, 'ordem_producao'),
      'gestao' => (saas_company_has_module($pdo, $companyId, 'delivery') || saas_company_has_module($pdo, $companyId, 'gestao') || saas_company_has_module($pdo, $companyId, 'fiscal') || saas_company_has_module($pdo, $companyId, 'financeiro')),
      'gestao_dashboard', 'gestao_pdv_resumido', 'gestao_pdv_checkout', 'gestao_pdv', 'gestao_pdv_mobile', 'gestao_pedido_detalhado', 'gestao_caixa',
      'gestao_vendedores', 'gestao_entrega_interno' => saas_company_has_module($pdo, $companyId, 'gestao'),
      'gestao_pedidos' => (saas_company_has_module($pdo, $companyId, 'delivery') || saas_company_has_module($pdo, $companyId, 'gestao') || saas_company_has_module($pdo, $companyId, 'fiscal') || saas_company_has_module($pdo, $companyId, 'financeiro')),
      'gestao_comandas', 'gestao_comandas_checkout', 'gestao_comandas_mobile', 'gestao_comandas_config', 'gestao_comandas_qrcode', 'gestao_comandas_impressao', 'gestao_comandas_agentes' => saas_company_has_module($pdo, $companyId, 'comandas'),
      'gestao_clientes' => (saas_company_has_module($pdo, $companyId, 'gestao') || saas_company_has_module($pdo, $companyId, 'delivery')),
      'gestao_pagamentos' => (saas_company_has_module($pdo, $companyId, 'gestao') || saas_company_has_module($pdo, $companyId, 'financeiro')),
      'tabelas' => (saas_company_has_module($pdo, $companyId, 'fiscal') || saas_company_has_module($pdo, $companyId, 'gestao') || saas_company_has_module($pdo, $companyId, 'comandas') || saas_company_has_module($pdo, $companyId, 'delivery') || saas_company_has_module($pdo, $companyId, 'financeiro')),
      'entradas' => (saas_company_has_module($pdo, $companyId, 'fiscal') || saas_company_has_module($pdo, $companyId, 'conferencia_estoque')),
      'estoque_conferencias' => saas_company_has_module($pdo, $companyId, 'conferencia_estoque'),
      'fiscal', 'fiscal_dashboard', 'fiscal_radar_xml', 'fiscal_entrada_notas', 'fiscal_devolucoes', 'fiscal_devolucao_cliente', 'fiscal_saidas', 'fiscal_importar_xml', 'fiscal_nota_manual', 'fiscal_xml_entrada', 'fiscal_xml_conversoes', 'fiscal_operacoes', 'fiscal_tributacoes', 'fiscal_cfops', 'fiscal_ncms', 'fiscal_cests', 'fiscal_cst', 'fiscal_ibscbs', 'fiscal_importacoes', 'fiscal_tabelas', 'fiscal_produtos', 'fiscal_configuracoes', 'fiscal_documentos', 'fiscal_nfce', 'fiscal_inutilizacao', 'fiscal_relatorios', 'fiscal_relatorios_documentos', 'fiscal_relatorio_inutilizacoes', 'fiscal_sintegra', 'fiscal_sped_icms', 'fiscal_sped_contrib', 'fiscal_inventario', 'fiscal_exportar_xml', 'fiscal_contabilidade', 'fiscal_contadores' => saas_company_has_module($pdo, $companyId, 'fiscal'),
      'usuarios', 'usuarios_perfis' => true,
      'params' => true,
      default => true,
    };
  }
}

if (!function_exists('saas_admin_default_url')) {
  function saas_admin_default_url(PDO $pdo, int $companyId): string {
    // Área de trabalho universal; não depende da configuração dos módulos.
    return 'area_trabalho.php';
  }
}
