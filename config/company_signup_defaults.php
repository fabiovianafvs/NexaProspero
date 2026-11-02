<?php
declare(strict_types=1);

/**
 * Instalação inicial por empresa; somente INSERT em estruturas já homologadas.
 * Chamado na mesma transação da criação da empresa, sem executar DDL.
 */
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/fiscal_tax_transfer.php';
require_once __DIR__ . '/payment_methods_transfer.php';

function nexa_signup_assert_defaults_schema(PDO $pdo): void {
  foreach (['user_profiles', 'finance_chart_accounts', 'finance_payment_methods', 'fiscal_tax_profiles', 'fiscal_operations', 'fiscal_tax_profile_operation_cfops'] as $table) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('O banco ainda não tem a tabela ' . $table . '. Atualize o banco antes de cadastrar novas empresas.');
  }
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='profile_id'");
  $st->execute();
  if (!(int)$st->fetchColumn()) throw new RuntimeException('A coluna users.profile_id não está instalada. Execute a atualização do banco antes de novos cadastros.');

  // As seis formas de pagamento também precisam caber no schema já homologado.
  // Falhar antes de iniciar o cadastro evita criar empresa incompleta.
  $paymentDoc = nexa_signup_payment_seed_document();
  $paymentCols = array_fill_keys(nexa_payment_transfer_db_fields($pdo), true);
  foreach ($paymentDoc['payments'] as $item) {
    foreach ($item['fields'] as $field => $value) {
      if (!isset($paymentCols[$field])) {
        throw new RuntimeException('O banco não suporta a coluna de formas de pagamento ' . $field . '. Atualize o banco antes do cadastro.');
      }
    }
  }

  // Pré-validação das colunas fiscais (sem CREATE/ALTER no cadastro SaaS).
  $doc = nexa_signup_tax_seed_document();
  $columnsQuery = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fiscal_tax_profiles'");
  $columnsQuery->execute();
  $existingCols = array_fill_keys(array_map('strval', $columnsQuery->fetchAll(PDO::FETCH_COLUMN)), true);
  $allowed = array_fill_keys(nexa_tax_allowed_fields(), true);
  foreach ($doc['profiles'] as $item) {
    if (!is_array($item) || !is_array($item['fields'] ?? null)) throw new RuntimeException('Modelo fiscal inválido.');
    foreach ($item['fields'] as $field => $value) {
      if (!isset($allowed[$field]) || !isset($existingCols[$field])) {
        throw new RuntimeException('O banco não suporta o campo fiscal ' . $field . '. Atualize o banco antes do cadastro.');
      }
      if ($value !== null && !is_scalar($value)) throw new RuntimeException('Valor fiscal inválido: ' . $field);
    }
  }
}

function nexa_signup_create_general_profile(PDO $pdo, int $companyId): int {
  if ($companyId <= 0 || !$pdo->inTransaction()) throw new RuntimeException('A criação do perfil deve ocorrer dentro da transação da empresa.');
  $permissions = [];
  foreach (access_permissions_catalog() as $key => $permission) {
    // São restrições, e não autorizações; não devem ser concedidas ao administrador.
    if (in_array($key, ['nao_finalizar_venda', 'desativa_preco_custo', 'desativa_margem_orcamento'], true)) continue;
    if (access_module_enabled($pdo, $companyId, $permission['module'] ?? '*')) $permissions[] = $key;
  }
  if (!$permissions) throw new RuntimeException('Nenhuma permissão disponível para os módulos da nova empresa.');
  $st = $pdo->prepare('INSERT INTO user_profiles (company_id, name, description, permissions_json, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
  $st->execute([
    $companyId,
    'ADMINISTRAÇÃO GERAL',
    'Perfil inicial da empresa com os acessos dos módulos habilitados, sem acesso master à plataforma.',
    json_encode(array_values(array_unique($permissions)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
  ]);
  return (int)$pdo->lastInsertId();
}

/** Não sobrescreve planos existentes; importa integralmente o modelo homologado apenas na criação. */
function nexa_signup_seed_chart_of_accounts(PDO $pdo, int $companyId): int {
  if ($companyId <= 0 || !$pdo->inTransaction()) throw new RuntimeException('A instalação das contas precisa participar da transação de cadastro.');
  $st = $pdo->prepare('SELECT COUNT(*) FROM finance_chart_accounts WHERE company_id=?');
  $st->execute([$companyId]);
  if ((int)$st->fetchColumn() > 0) return 0;

  $path = __DIR__ . '/../resources/plano_contas_padrao.csv';
  $fh = @fopen($path, 'rb');
  if (!$fh) throw new RuntimeException('Modelo de plano de contas padrão não encontrado na instalação.');
  try {
    $headers = fgetcsv($fh, 0, ';', '"', '\\');
    if (!is_array($headers)) throw new RuntimeException('Modelo de contas vazio.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    if ($headers !== ['classificacao','codigo','nome','tipo','movimento','analitica','ativa','ordem']) {
      throw new RuntimeException('Cabeçalho do plano de contas padrão inválido.');
    }
    $rows = [];
    $codes = [];
    while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
      if (count($row) === 1 && trim((string)$row[0]) === '') continue;
      if (count($row) !== 8) throw new RuntimeException('Quantidade de campos inválida no plano de contas.');
      [$classification,$code,$name,$type,$movement,$analytic,$active,$order] = array_map('trim', $row);
      if (!preg_match('/^[0-9]+(?:\.[0-9]+)*$/', $classification) || $name === '' || strlen($code) > 40 || $code === '') throw new RuntimeException('Classificação, nome ou código inválido no plano de contas.');
      if (isset($rows[$classification]) || isset($codes[$code])) throw new RuntimeException('Código ou classificação duplicada no plano padrão.');
      if (!in_array($type, ['RESULTADO','RECEITA','DESPESA','OUTRA'], true) || !in_array($movement, ['AMBOS','DEBITO','CREDITO'], true)) throw new RuntimeException('Tipo de conta ou movimento inválido.');
      if (!in_array($analytic, ['0','1'], true) || !in_array($active, ['0','1'], true) || !preg_match('/^\d+$/', $order)) throw new RuntimeException('Propriedades de conta inválidas.');
      $rows[$classification] = compact('classification','code','name','type','movement','analytic','active','order');
      $codes[$code] = true;
    }
    if (count($rows) !== 121) throw new RuntimeException('O modelo de plano de contas não contém as 121 contas homologadas.');
    // Em PHP, chaves numéricas como "1" viram inteiros nos arrays.
    // Com strict_types=1, explode() não aceita esses inteiros como string.
    uksort($rows, static function($a, $b): int {
      $a = (string)$a;
      $b = (string)$b;
      $pa = explode('.', $a); $pb = explode('.', $b);
      if (count($pa) !== count($pb)) return count($pa) <=> count($pb);
      return strnatcmp($a, $b);
    });
    $ids = [];
    $insert = $pdo->prepare('INSERT INTO finance_chart_accounts (company_id, parent_id, code, classification, name, account_type, movement_type, level, is_analytic, active, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $class => $r) {
      $class = (string)$class;
      $parts = explode('.', $class);
      $parent = count($parts) > 1 ? implode('.', array_slice($parts, 0, -1)) : '';
      if ($parent !== '' && !isset($ids[$parent])) throw new RuntimeException('A conta pai ' . $parent . ' não foi localizada no modelo.');
      $insert->execute([$companyId, $parent === '' ? null : $ids[$parent], $r['code'], $class, $r['name'], $r['type'], $r['movement'], count($parts), (int)$r['analytic'], (int)$r['active'], (int)$r['order']]);
      $ids[$class] = (int)$pdo->lastInsertId();
    }
    return count($ids);
  } finally { fclose($fh); }
}


/** Modelo homologado das Formas de Pagamento exportadas pelo próprio Nexa. */
function nexa_signup_payment_seed_document(): array {
  $path = __DIR__ . '/../resources/formas_pagamento_padrao.json';
  $contents = @file_get_contents($path);
  if ($contents === false) throw new RuntimeException('Modelo padrão de Formas de Pagamento não encontrado.');
  $doc = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
  if (!is_array($doc)
      || ($doc['format'] ?? '') !== 'nexa_payment_methods'
      || ($doc['version'] ?? null) !== 1
      || !is_array($doc['payments'] ?? null)
      || !array_is_list($doc['payments'])
      || count($doc['payments']) !== 6) {
    throw new RuntimeException('Modelo de Formas de Pagamento incompatível: esperadas seis formas.');
  }
  $seen = [];
  foreach ($doc['payments'] as $item) {
    if (!is_array($item) || !is_array($item['fields'] ?? null)) {
      throw new RuntimeException('Registro inválido no modelo de Formas de Pagamento.');
    }
    $fields = $item['fields'];
    $code = (string)($fields['code'] ?? '');
    if (isset($seen[$code])) throw new RuntimeException('Forma de pagamento duplicada: ' . $code);
    $seen[$code] = true;
  }
  $expected = ['DINHEIRO','PIX','CARTAO_DEBITO','CARTAO_CREDITO','A_PRAZO','VALE'];
  sort($expected);
  $actual = array_keys($seen);
  sort($actual);
  if ($actual !== $expected) throw new RuntimeException('Códigos do modelo padrão de Formas de Pagamento diferentes dos homologados.');
  return $doc;
}

/** Instala somente na empresa nova, na mesma transação de cadastro. */
function nexa_signup_seed_payment_methods(PDO $pdo, int $companyId): int {
  if ($companyId <= 0 || !$pdo->inTransaction()) {
    throw new RuntimeException('A instalação das Formas de Pagamento exige transação de cadastro ativa.');
  }
  $q = $pdo->prepare('SELECT COUNT(*) FROM finance_payment_methods WHERE company_id=?');
  $q->execute([$companyId]);
  if ((int)$q->fetchColumn() > 0) return 0;  // Protege dados existentes.
  $result = nexa_payment_transfer_import($pdo, $companyId, nexa_signup_payment_seed_document());
  if ((int)$result['created'] !== 6 || (int)$result['updated'] !== 0) {
    throw new RuntimeException('Não foi possível instalar integralmente as seis Formas de Pagamento padrão.');
  }
  return 6;
}

/** Lê somente o modelo instalado junto com os fontes, jamais entrada do navegador. */
function nexa_signup_tax_seed_document(): array {
  $path = __DIR__ . '/../resources/tributacoes_padrao.json';
  $contents = @file_get_contents($path);
  if ($contents === false) throw new RuntimeException('Modelo padrão de tributações não encontrado.');
  $doc = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
  if (!is_array($doc)
      || ($doc['format'] ?? '') !== 'nexa_fiscal_tributacoes'
      || ($doc['version'] ?? null) !== 1
      || !is_array($doc['profiles'] ?? null)
      || count($doc['profiles']) !== 5) {
    throw new RuntimeException('Modelo padrão fiscal incompatível: esperados cinco perfis tributários.');
  }
  return $doc;
}

/**
 * Instala exclusivamente para empresa nova, dentro da mesma transação da empresa.
 * Não cria tabelas, não altera empresas existentes e não invoca ensure_schema/DDL.
 * Preserva os valores do JSON homologado, inclusive mapeamentos CFOP desabilitados.
 */
function nexa_signup_seed_tax_profiles(PDO $pdo, int $companyId): int {
  if ($companyId <= 0 || !$pdo->inTransaction()) throw new RuntimeException('A configuração fiscal inicial exige transação ativa.');
  $q = $pdo->prepare('SELECT COUNT(*) FROM fiscal_tax_profiles WHERE company_id=?');
  $q->execute([$companyId]);
  if ((int)$q->fetchColumn() > 0) return 0;
  $doc = nexa_signup_tax_seed_document();

  // Operações base utilizadas pelo JSON. Evita mapeamentos órfãos de CFOP.
  // Não habilita novas operações fiscais para empresas preexistentes.
  $existing = $pdo->prepare('SELECT id, code, direction FROM fiscal_operations WHERE company_id=?');
  $existing->execute([$companyId]);
  $opRows = $existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$opRows) {
    $insertOp = $pdo->prepare('INSERT INTO fiscal_operations (company_id, code, name, direction, operation_type, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, create_finance, finance_movement, update_stock, is_active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $defaults = [
      ['COMPRA','Compra / entrada de mercadoria','ENTRADA','COMPRA','1102','2102','','',1,'PAGAR',1,1,'Operação padrão para entrada de mercadorias.'],
      ['VENDA','Venda de mercadoria','SAIDA','VENDA','','','5102','6102',1,'RECEBER',1,1,'Operação padrão para venda.'],
      ['DEV_CLIENTE','Devolução de cliente','ENTRADA','DEVOLUCAO_CLIENTE','1202','2202','','',0,'NENHUM',1,1,'Operação padrão para devolução de cliente.'],
      ['DEV_FORNECEDOR','Devolução para fornecedor','SAIDA','DEVOLUCAO_FORNECEDOR','','','5202','6202',0,'NENHUM',1,1,'Operação padrão para devolução a fornecedor.'],
    ];
    foreach ($defaults as $r) $insertOp->execute(array_merge([$companyId], $r));
    $existing->execute([$companyId]);
    $opRows = $existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  $opMap = [];
  foreach ($opRows as $o) {
    $opMap[strtoupper(trim((string)$o['direction'])).'|'.strtoupper(trim((string)$o['code']))] = (int)$o['id'];
  }

  $allowed = array_fill_keys(nexa_tax_allowed_fields(), true);
  $used = [];
  $insMap = $pdo->prepare('INSERT INTO fiscal_tax_profile_operation_cfops (company_id, tax_profile_id, operation_id, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, enabled, notes, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
  $count = 0;
  foreach ($doc['profiles'] as $item) {
    if (!is_array($item) || !is_array($item['fields'] ?? null)) throw new RuntimeException('Perfil tributário padrão inválido.');
    $f = $item['fields'];
    if (array_diff_key($f, $allowed)) throw new RuntimeException('O modelo contém campos fiscais não suportados.');
    foreach ($f as $k => $value) if ($value !== null && !is_scalar($value)) throw new RuntimeException('Valor tributário inválido: ' . $k);
    $code = trim((string)($f['code'] ?? ''));
    $type = (string)($f['profile_type'] ?? 'PRODUCT');
    $name = trim((string)($f['name'] ?? ''));
    if ($code === '' || strlen($code) > 30 || $name === '' || strlen($name) > 150 || !in_array($type, ['PRODUCT','SERVICE'], true)) {
      throw new RuntimeException('Código, nome ou tipo tributário inválido no modelo padrão.');
    }
    $key = $type.'|'.$code;
    if (isset($used[$key])) throw new RuntimeException('Perfil fiscal duplicado no modelo: ' . $code);
    $used[$key] = true;
    $cols = array_keys($f);  // nomes de campos limitados à lista fixa nexa_tax_allowed_fields()
    $sql = 'INSERT INTO fiscal_tax_profiles (`company_id`,`' . implode('`,`', $cols) . '`) VALUES (?' . str_repeat(',?', count($cols)) . ')';
    $pdo->prepare($sql)->execute(array_merge([$companyId], array_values($f)));
    $profileId = (int)$pdo->lastInsertId();
    if ($profileId <= 0) throw new RuntimeException('Falha ao criar a tributação padrão.');
    $count++;
    foreach (($item['operation_cfops'] ?? []) as $op) {
      if (!is_array($op)) throw new RuntimeException('CFOP padrão inválido.');
      $opKey = strtoupper(trim((string)($op['operation_direction'] ?? ''))).'|'.strtoupper(trim((string)($op['operation_code'] ?? '')));
      // Operações auxiliares de teste presentes no arquivo, porém não cadastradas,
      // permanecem ignoradas como no importador fiscal existente.
      $opId = (int)($opMap[$opKey] ?? 0);
      if ($opId <= 0) continue;
      $cfops=[];
      foreach (['cfop_in_state','cfop_in_interstate','cfop_out_state','cfop_out_interstate'] as $c) {
        $v = trim((string)($op[$c] ?? ''));
        if ($v !== '' && !preg_match('/^[0-9]{4}$/', $v)) throw new RuntimeException('CFOP inválido no modelo padrão fiscal.');
        $cfops[] = $v;
      }
      $insMap->execute(array_merge([$companyId, $profileId, $opId], $cfops, [(int)!empty($op['enabled']), $op['notes'] ?? null]));
    }
  }
  return $count;
}
