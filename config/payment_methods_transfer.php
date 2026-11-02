<?php
declare(strict_types=1);

/**
 * Transferência portátil das Formas de Pagamento operacionais (PDV).
 * Não exporta IDs de empresa, credenciais Pix nem identificadores locais de contas.
 * Sem qualquer alteração de schema/DDL.
 */
if (!function_exists('nexa_payment_transfer_fields')) {
  function nexa_payment_transfer_fields(): array {
    return [
      'code', 'name', 'type', 'is_active', 'sort_order', 'notes',
      'generate_receivable', 'allow_installments', 'default_installments',
      'first_due_days', 'keep_due_day', 'affects_cash', 'require_open_cash', 'allow_change',
      'nfce_payment_type', 'nfce_payment_indicator', 'nfce_card_integration',
      'nfce_card_brand', 'nfce_card_cnpj',
      // auto_register_boleto e campos bancários NÃO são transferidos automaticamente.
    ];
  }
}

if (!function_exists('nexa_payment_transfer_db_fields')) {
  function nexa_payment_transfer_db_fields(PDO $pdo): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance_payment_methods'");
    $st->execute();
    $exists = array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    $fields = array_values(array_filter(nexa_payment_transfer_fields(), static fn($x) => isset($exists[$x])));
    foreach (['code','name','type'] as $required) {
      if (!in_array($required, $fields, true)) {
        throw new RuntimeException('A estrutura das Formas de Pagamento precisa ser atualizada antes da transferência.');
      }
    }
    return $fields;
  }
}

if (!function_exists('nexa_payment_transfer_export')) {
  function nexa_payment_transfer_export(PDO $pdo, int $companyId): array {
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
    $fields = nexa_payment_transfer_db_fields($pdo);
    $sql = 'SELECT ' . implode(', ', array_map(static fn($f) => '`'.$f.'`', $fields)) .
      ' FROM finance_payment_methods WHERE company_id=? ORDER BY sort_order,name,id';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);
    return [
      'format' => 'nexa_payment_methods', 'version' => 1,
      'exported_at' => date('c'),
      'payments' => array_map(static fn($row) => ['fields'=>$row], $st->fetchAll(PDO::FETCH_ASSOC)),
    ];
  }
}

if (!function_exists('nexa_payment_transfer_import')) {
  function nexa_payment_transfer_import(PDO $pdo, int $companyId, array $doc): array {
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
    if (($doc['format'] ?? '') !== 'nexa_payment_methods' || (int)($doc['version'] ?? 0) !== 1) {
      throw new RuntimeException('Arquivo não é uma exportação válida de Formas de Pagamento do Nexa (v1).');
    }
    $list = $doc['payments'] ?? null;
    if (!is_array($list) || !array_is_list($list) || count($list) > 200) {
      throw new RuntimeException('Arquivo inválido ou excede 200 formas de pagamento.');
    }
    $allowed = nexa_payment_transfer_db_fields($pdo);
    $ints = array_fill_keys(['is_active','sort_order','generate_receivable','allow_installments',
      'default_installments','first_due_days','keep_due_day','affects_cash','require_open_cash','allow_change'], true);
    $bools = array_fill_keys(['is_active','generate_receivable','allow_installments','keep_due_day',
      'affects_cash','require_open_cash','allow_change'], true);
    $types = ['DINHEIRO','PIX','CARTAO','BOLETO','PRAZO','VALE','OUTRO'];
    $seen = [];
    $normalized = [];
    foreach ($list as $i => $element) {
      if (!is_array($element) || !isset($element['fields']) || !is_array($element['fields'])) {
        throw new RuntimeException('Registro inválido na linha '.($i+1).'.');
      }
      $input = $element['fields'];
      $code = strtoupper(trim((string)($input['code'] ?? '')));
      $name = trim((string)($input['name'] ?? ''));
      $type = strtoupper(trim((string)($input['type'] ?? '')));
      if (!preg_match('/^[A-Z0-9_]{1,50}$/D', $code) || $name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 150 || !in_array($type, $types, true)) {
        throw new RuntimeException('Código, nome ou tipo inválido na linha '.($i+1).'.');
      }
      if (isset($seen[$code])) throw new RuntimeException('Código repetido no arquivo: '.$code);
      $seen[$code] = true;
      $record = ['code'=>$code, 'name'=>$name, 'type'=>$type];
      foreach ($allowed as $f) {
        if (isset($record[$f]) || !array_key_exists($f, $input)) continue;
        $val = $input[$f];
        if (!is_scalar($val) && $val !== null) throw new RuntimeException('Campo inválido: '.$f);
        if (isset($ints[$f])) {
          if ($val !== null && !preg_match('/^-?\d+$/D', (string)$val)) throw new RuntimeException('Número inválido: '.$f);
          $val = (int)$val;
          if (isset($bools[$f])) $val = ($val !== 0) ? 1 : 0;
          if ($f === 'default_installments') $val = max(1, min(120,$val));
          if ($f === 'first_due_days') $val = max(0, min(365,$val));
          if ($f === 'sort_order') $val = max(-9999, min(9999,$val));
        } else {
          $val = $val === null ? null : trim((string)$val);
          if ($val !== null && (function_exists('mb_strlen') ? mb_strlen($val, 'UTF-8') : strlen($val)) > ($f === 'notes' ? 1000 : 190)) {
            throw new RuntimeException('Campo muito longo: '.$f);
          }
        }
        $record[$f] = $val;
      }
      // Invariantes fiscais e operacionais já exigidas pela tela atual.
      if ($type === 'VALE') {
        foreach (['generate_receivable','allow_installments','affects_cash','require_open_cash','allow_change'] as $f) {
          if (in_array($f,$allowed,true)) $record[$f] = 0;
        }
        if (in_array('nfce_payment_type',$allowed,true)) $record['nfce_payment_type'] = '21';
      }
      $normalized[] = $record;
    }
    $created = 0; $updated = 0;
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id,type FROM finance_payment_methods WHERE company_id=? AND code=? LIMIT 1 FOR UPDATE');
      foreach ($normalized as $record) {
        $find->execute([$companyId,$record['code']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing && (string)$existing['type'] !== $record['type']) {
          throw new RuntimeException('O código '.$record['code'].' já existe com outro tipo. Corrija manualmente antes de importar.');
        }
        if ($existing) {
          // Atualiza apenas dados portáteis: mantém banco, conta, Pix e credenciadora locais.
          $values = array_filter(array_keys($record), static fn($key) => $key !== 'code');
          $sql = 'UPDATE finance_payment_methods SET ' . implode(', ', array_map(static fn($key) => '`'.$key.'`=?', $values)) .
            ', updated_at=NOW() WHERE company_id=? AND id=?';
          $params = array_map(static fn($key) => $record[$key], $values);
          array_push($params,$companyId,(int)$existing['id']);
          $pdo->prepare($sql)->execute($params);
          $updated++;
        } else {
          $cols = array_keys($record);
          // Conta bancária, chave Pix, integrações e controle de cartão são deixados desligados/padrão.
          $sql = 'INSERT INTO finance_payment_methods (company_id, ' . implode(', ', array_map(static fn($key) => '`'.$key.'`', $cols)) .
            ') VALUES (' . implode(',', array_fill(0, count($cols)+1, '?')) . ')';
          $pdo->prepare($sql)->execute(array_merge([$companyId], array_values($record)));
          $created++;
        }
      }
      if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
      if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return ['created'=>$created,'updated'=>$updated];
  }
}
