<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/fiscal_operational.php';
require_once __DIR__ . '/fiscal_xml_export.php';
require_once __DIR__ . '/fiscal_document_reports.php';
require_once __DIR__ . '/fiscal_pdf_reports.php';
require_once __DIR__ . '/inventory_module.php';

if (!function_exists('accounting_ensure_schema')) {
  function accounting_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS accountants (
      id BIGINT NOT NULL AUTO_INCREMENT,
      name VARCHAR(160) NOT NULL,
      email VARCHAR(190) NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      document VARCHAR(30) NULL,
      phone VARCHAR(40) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      last_login_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_accountants_email (email),
      KEY idx_accountants_active (is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS accountant_companies (
      id BIGINT NOT NULL AUTO_INCREMENT,
      accountant_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_by_user_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_accountant_company (accountant_id, company_id),
      KEY idx_accountant_companies_company (company_id, is_active),
      KEY idx_accountant_companies_accountant (accountant_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS accountant_company_requests (
      id BIGINT NOT NULL AUTO_INCREMENT,
      accountant_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      requested_cnpj VARCHAR(14) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
      request_message VARCHAR(500) NULL,
      review_note VARCHAR(500) NULL,
      requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      reviewed_at DATETIME NULL,
      reviewed_by_user_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_accountant_company_request (accountant_id, company_id),
      KEY idx_accountant_requests_company (company_id, status, requested_at),
      KEY idx_accountant_requests_accountant (accountant_id, status, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_closures (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      period_start DATE NOT NULL,
      period_end DATE NOT NULL,
      document_model VARCHAR(10) NOT NULL DEFAULT '',
      series VARCHAR(20) NOT NULL DEFAULT '',
      status VARCHAR(20) NOT NULL DEFAULT 'PROCESSANDO',
      notes TEXT NULL,
      error_message TEXT NULL,
      documents_count INT NOT NULL DEFAULT 0,
      documents_total DECIMAL(15,2) NOT NULL DEFAULT 0,
      xml_files_count INT NOT NULL DEFAULT 0,
      inventory_items_count INT NOT NULL DEFAULT 0,
      generated_by_user_id INT NULL,
      generated_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_accounting_closures_company (company_id, period_start, period_end),
      KEY idx_accounting_closures_status (company_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_closure_files (
      id BIGINT NOT NULL AUTO_INCREMENT,
      closure_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      file_type VARCHAR(40) NOT NULL,
      display_name VARCHAR(180) NOT NULL,
      file_name VARCHAR(180) NOT NULL,
      relative_path VARCHAR(255) NOT NULL,
      mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
      file_size BIGINT NOT NULL DEFAULT 0,
      checksum_sha256 CHAR(64) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY ux_accounting_closure_file_type_name (closure_id, file_type, file_name),
      KEY idx_accounting_files_company (company_id, closure_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try { $pdo->exec("ALTER TABLE accounting_closures ADD COLUMN include_inventory TINYINT(1) NOT NULL DEFAULT 0 AFTER series"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE accounting_closures ADD COLUMN inventory_id BIGINT NULL AFTER inventory_items_count"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE accounting_closure_files DROP INDEX ux_accounting_closure_file_type"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE accounting_closure_files ADD UNIQUE KEY ux_accounting_closure_file_type_name (closure_id,file_type,file_name)"); } catch (Throwable $e) {}
    inventory_ensure_schema($pdo);
    accounting_storage_root();
  }
}

if (!function_exists('accounting_public_central_url')) {
  function accounting_public_central_url(): string {
    $configured = trim((string)(getenv('ACCOUNTING_CENTRAL_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/') . '/';
    return 'https://www.nexaprospero.com.br/contadorprospero/';
  }
}

if (!function_exists('accounting_storage_root')) {
  function accounting_storage_root(): string {
    $root = dirname(__DIR__) . '/storage/accounting';
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
      $last = error_get_last();
      $detail = trim((string)($last['message'] ?? ''));
      throw new RuntimeException('Não foi possível criar a pasta de fechamentos contábeis: ' . $root . ($detail !== '' ? ' - ' . $detail : ''));
    }
    if (!is_writable($root)) throw new RuntimeException('A pasta de fechamentos contábeis não possui permissão de escrita para o PHP: ' . $root);
    $deny = $root . '/.htaccess';
    if (!is_file($deny)) {
      @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
    return $root;
  }
}

if (!function_exists('accounting_closure_dir')) {
  function accounting_closure_dir(int $companyId, int $closureId): string {
    if ($companyId <= 0 || $closureId <= 0) throw new InvalidArgumentException('Fechamento inválido.');
    $dir = accounting_storage_root() . '/empresa_' . $companyId . '/fechamento_' . $closureId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      $last = error_get_last();
      $detail = trim((string)($last['message'] ?? ''));
      throw new RuntimeException('Não foi possível preparar a pasta do fechamento: ' . $dir . ($detail !== '' ? ' - ' . $detail : ''));
    }
    if (!is_writable($dir)) throw new RuntimeException('A pasta do fechamento não possui permissão de escrita para o PHP: ' . $dir);
    return $dir;
  }
}

if (!function_exists('accounting_safe_part')) {
  function accounting_safe_part(string $value, string $fallback = 'arquivo'): string {
    $value = trim($value);
    if ($value === '') $value = $fallback;
    $value = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $value) ?? $fallback;
    $value = trim($value, '._-');
    return $value !== '' ? $value : $fallback;
  }
}

if (!function_exists('accounting_validate_date')) {
  function accounting_validate_date(string $value, string $fallback = ''): string {
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
      if ($dt && $dt->format('Y-m-d') === $value) return $value;
    }
    if ($fallback !== '') return $fallback;
    throw new RuntimeException('Informe um período válido.');
  }
}

if (!function_exists('accounting_csv')) {
  function accounting_csv(array $headers, array $rows): string {
    $fp = fopen('php://temp', 'w+b');
    if (!$fp) throw new RuntimeException('Não foi possível preparar o arquivo CSV.');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $headers, ';', '"', '\\');
    foreach ($rows as $row) fputcsv($fp, array_values($row), ';', '"', '\\');
    rewind($fp);
    $content = stream_get_contents($fp);
    fclose($fp);
    return is_string($content) ? $content : '';
  }
}

if (!function_exists('accounting_company_name')) {
  function accounting_company_name(PDO $pdo, int $companyId): string {
    try {
      $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
      $st->execute([$companyId]);
      $name = trim((string)($st->fetchColumn() ?: ''));
      return $name !== '' ? $name : 'Empresa ' . $companyId;
    } catch (Throwable $e) {
      return 'Empresa ' . $companyId;
    }
  }
}


/**
 * Regra obrigatoria para a Central do Contador: documentos de rejeicao
 * descartada sao apenas historico operacional, nunca documentos fiscais
 * exportaveis. O teste por evento cobre inclusive status alterado por
 * versoes antigas da inutilizacao. Sem migrations.
 */
if (!function_exists('accounting_exclude_locally_discarded_sql')) {
  function accounting_exclude_locally_discarded_sql(string $alias = 'fd'): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
      throw new InvalidArgumentException('Alias SQL invalido.');
    }
    return "UPPER(TRIM(COALESCE({$alias}.status,''))) <> 'DESCARTADA'"
      . " AND NOT EXISTS (SELECT 1 FROM fiscal_document_events discard_event"
      . " WHERE discard_event.company_id={$alias}.company_id"
      . " AND discard_event.fiscal_document_id={$alias}.id"
      . " AND discard_event.event_type='DESCARTE_REJEICAO_LOCAL')";
  }
}

if (!function_exists('accounting_collect_documents')) {
  function accounting_collect_documents(PDO $pdo, int $companyId, string $ini, string $fim, string $model = '', string $series = ''): array {
    fiscal_nfce_ensure_schema($pdo);
    $where = [
      'fd.company_id=?',
      "fd.model IN ('55','65')",
      'COALESCE(fd.number,0)>0',
      "UPPER(COALESCE(fd.status,'')) NOT IN ('DIGITACAO','PROCESSANDO','ERRO')",
      accounting_exclude_locally_discarded_sql('fd'),
      'COALESCE(fd.issue_datetime,fd.created_at)>=?',
      'COALESCE(fd.issue_datetime,fd.created_at)<?',
    ];
    $params = [$companyId, $ini . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($fim . ' +1 day'))];
    if (in_array($model, ['55','65'], true)) { $where[] = 'fd.model=?'; $params[] = $model; }
    if ($series !== '') { $where[] = "COALESCE(fd.series,'')=?"; $params[] = $series; }
    // Neste ponto o fechamento precisa apenas da quantidade, situação e total dos documentos.
    // Evita SELECT fd.* porque fiscal_documents.xml_raw é LONGTEXT e pode consumir muita memória
    // em empresas com grande volume de NFC-e.
    $sql = "SELECT fd.id,fd.status,fd.total_document
      FROM fiscal_documents fd WHERE " . implode(' AND ', $where) . "
      ORDER BY COALESCE(fd.issue_datetime,fd.created_at),fd.model,CAST(fd.series AS UNSIGNED),fd.number,fd.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_document_devolution_sql')) {
  function accounting_document_devolution_sql(string $alias = 'fd'): string {
    $alias = preg_replace('/[^a-z0-9_]/i', '', $alias) ?: 'fd';
    return "(UPPER(COALESCE({$alias}.source_type,'')) LIKE '%DEVOLU%' OR UPPER(COALESCE({$alias}.operation_nature,'')) LIKE '%DEVOLU%')";
  }
}

if (!function_exists('accounting_xml_devolution_sql')) {
  /**
   * Identifica devoluções nos XMLs de entrada pela operação vinculada, observação
   * ou pelos CFOPs de devolução mais usuais. O alias da operação deve existir no SELECT.
   */
  function accounting_xml_devolution_sql(string $xmlAlias = 'x', string $operationAlias = 'op'): string {
    $xmlAlias = preg_replace('/[^a-z0-9_]/i', '', $xmlAlias) ?: 'x';
    $operationAlias = preg_replace('/[^a-z0-9_]/i', '', $operationAlias) ?: 'op';
    $returnCfops = "'1201','1202','1208','1209','1410','1411','1414','1415','1503','1504','1660','1661','1662',"
      . "'2201','2202','2208','2209','2410','2411','2414','2415','2503','2504','2660','2661','2662',"
      . "'3201','3202','3211','3503','3504'";
    return "(UPPER(COALESCE({$operationAlias}.operation_type,'')) LIKE '%DEVOLU%'"
      . " OR UPPER(COALESCE({$operationAlias}.name,'')) LIKE '%DEVOLU%'"
      . " OR UPPER(COALESCE({$xmlAlias}.notes,'')) LIKE '%DEVOLU%'"
      . " OR EXISTS (SELECT 1 FROM fiscal_xml_items xi_dev"
      . " WHERE xi_dev.company_id={$xmlAlias}.company_id AND xi_dev.xml_import_id={$xmlAlias}.id"
      . " AND COALESCE(NULLIF(TRIM(xi_dev.entry_cfop),''),NULLIF(TRIM(xi_dev.cfop),''),'') IN ({$returnCfops})))";
  }
}

if (!function_exists('accounting_document_movement_options')) {
  /** @return array<string,string> */
  function accounting_document_movement_options(): array {
    return [
      'TODOS'=>'Todos (geral)',
      'ENTRADAS'=>'Entradas',
      'SAIDAS'=>'Saídas',
      'DEVOLUCOES'=>'Devoluções',
      'TODAS_ENTRADAS'=>'Todas as entradas',
      'TODAS_SAIDAS'=>'Todas as saídas',
    ];
  }
}

if (!function_exists('accounting_document_movement_label')) {
  function accounting_document_movement_label(string $movement): string {
    $movement = strtoupper(trim($movement));
    $options = accounting_document_movement_options();
    return $options[$movement] ?? $options['TODOS'];
  }
}

if (!function_exists('accounting_document_search_parts')) {
  /**
   * Monta os filtros para documentos gravados em fiscal_documents.
   *
   * @return array{where:array<int,string>,params:array<int,mixed>}
   */
  function accounting_document_search_parts(int $companyId, array $filters, bool $onlyFiscalTotals = false): array {
    $ini = accounting_validate_date((string)($filters['ini'] ?? ''), date('Y-m-01'));
    $fim = accounting_validate_date((string)($filters['fim'] ?? ''), date('Y-m-d'));
    if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }

    $where = [
      'fd.company_id=?',
      "fd.model IN ('55','65')",
      'COALESCE(fd.number,0)>0',
      accounting_exclude_locally_discarded_sql('fd'),
      'COALESCE(fd.issue_datetime,fd.created_at)>=?',
      'COALESCE(fd.issue_datetime,fd.created_at)<?',
    ];
    $params = [$companyId, $ini . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($fim . ' +1 day'))];

    $model = trim((string)($filters['model'] ?? ''));
    if (in_array($model, ['55','65'], true)) { $where[] = 'fd.model=?'; $params[] = $model; }

    $series = trim((string)($filters['series'] ?? ''));
    if ($series !== '') { $where[] = "COALESCE(fd.series,'')=?"; $params[] = $series; }

    $accessKey = preg_replace('/\D+/', '', (string)($filters['access_key'] ?? '')) ?: '';
    if ($accessKey !== '') { $where[] = "COALESCE(fd.access_key,'') LIKE ?"; $params[] = nexa_search_like($accessKey); }

    $numberRaw = trim((string)($filters['number'] ?? ''));
    if ($numberRaw !== '' && preg_match('/^\d+$/', $numberRaw)) { $where[] = 'fd.number=?'; $params[] = (int)$numberRaw; }

    $status = strtoupper(trim((string)($filters['status'] ?? '')));
    if ($status !== '') { $where[] = "UPPER(COALESCE(fd.status,''))=?"; $params[] = $status; }

    $movement = strtoupper(trim((string)($filters['movement'] ?? 'TODOS')));
    $devolutionSql = accounting_document_devolution_sql('fd');
    if ($movement === 'ENTRADAS') {
      $where[] = "UPPER(COALESCE(fd.direction,''))='ENTRADA'";
      $where[] = "NOT {$devolutionSql}";
    } elseif ($movement === 'SAIDAS') {
      $where[] = "UPPER(COALESCE(fd.direction,''))='SAIDA'";
      $where[] = "NOT {$devolutionSql}";
    } elseif ($movement === 'DEVOLUCOES') {
      $where[] = $devolutionSql;
    } elseif ($movement === 'TODAS_ENTRADAS') {
      $where[] = "UPPER(COALESCE(fd.direction,''))='ENTRADA'";
    } elseif ($movement === 'TODAS_SAIDAS') {
      $where[] = "UPPER(COALESCE(fd.direction,''))='SAIDA'";
    }

    if ($onlyFiscalTotals) {
      $where[] = "(UPPER(COALESCE(fd.status,'')) LIKE 'AUTORIZ%' OR UPPER(COALESCE(fd.status,'')) LIKE 'IMPORTAD%')";
    }

    return ['where'=>$where,'params'=>$params];
  }
}

if (!function_exists('accounting_xml_document_search_parts')) {
  /**
   * Monta os filtros para NF-e de entrada armazenadas em fiscal_xml_imports.
   *
   * @return array{enabled:bool,where:array<int,string>,params:array<int,mixed>}
   */
  function accounting_xml_document_search_parts(int $companyId, array $filters, bool $onlyFiscalTotals = false): array {
    $movement = strtoupper(trim((string)($filters['movement'] ?? 'TODOS')));
    if (in_array($movement, ['SAIDAS','TODAS_SAIDAS'], true)) {
      return ['enabled'=>false,'where'=>['1=0'],'params'=>[]];
    }

    $ini = accounting_validate_date((string)($filters['ini'] ?? ''), date('Y-m-01'));
    $fim = accounting_validate_date((string)($filters['fim'] ?? ''), date('Y-m-d'));
    if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }

    $where = [
      'x.company_id=?',
      "x.model IN ('55','65')",
      "COALESCE(NULLIF(TRIM(x.number),''),'0')<>'0'",
      'COALESCE(x.include_fiscal_reports,1)=1',
      'COALESCE(x.entry_date,x.issue_date,DATE(x.created_at))>=?',
      'COALESCE(x.entry_date,x.issue_date,DATE(x.created_at))<=?',
      "(COALESCE(NULLIF(TRIM(x.access_key),''),'')='' OR NOT EXISTS (SELECT 1 FROM fiscal_documents fd_dup WHERE fd_dup.company_id=x.company_id AND fd_dup.access_key=x.access_key))",
    ];
    $params = [$companyId, $ini, $fim];

    $model = trim((string)($filters['model'] ?? ''));
    if (in_array($model, ['55','65'], true)) { $where[] = 'x.model=?'; $params[] = $model; }

    $series = trim((string)($filters['series'] ?? ''));
    if ($series !== '') { $where[] = "COALESCE(x.series,'')=?"; $params[] = $series; }

    $accessKey = preg_replace('/\D+/', '', (string)($filters['access_key'] ?? '')) ?: '';
    if ($accessKey !== '') { $where[] = "COALESCE(x.access_key,'') LIKE ?"; $params[] = nexa_search_like($accessKey); }

    $numberRaw = trim((string)($filters['number'] ?? ''));
    if ($numberRaw !== '' && preg_match('/^\d+$/', $numberRaw)) { $where[] = 'CAST(x.number AS UNSIGNED)=?'; $params[] = (int)$numberRaw; }

    $status = strtoupper(trim((string)($filters['status'] ?? '')));
    if ($status !== '') { $where[] = "UPPER(COALESCE(x.status,''))=?"; $params[] = $status; }

    $devolutionSql = accounting_xml_devolution_sql('x', 'op');
    if ($movement === 'ENTRADAS') $where[] = "NOT {$devolutionSql}";
    elseif ($movement === 'DEVOLUCOES') $where[] = $devolutionSql;
    // TODAS_ENTRADAS e TODOS incluem entradas normais e devoluções de entrada.

    if ($onlyFiscalTotals) {
      $where[] = "(UPPER(COALESCE(x.status,'')) LIKE 'IMPORTAD%' OR UPPER(COALESCE(x.status,''))='ENTRADA_GERADA')";
    }

    return ['enabled'=>true,'where'=>$where,'params'=>$params];
  }
}

if (!function_exists('accounting_document_fd_select')) {
  /** @return array{sql:string,params:array<int,mixed>} */
  function accounting_document_fd_select(int $companyId, array $filters, bool $onlyFiscalTotals = false): array {
    $parts = accounting_document_search_parts($companyId, $filters, $onlyFiscalTotals);
    $whereSql = implode(' AND ', $parts['where']);
    $devolutionSql = accounting_document_devolution_sql('fd');
    $supplierName = "(SELECT fp.name FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role) IN ('EMITENTE','FORNECEDOR') ORDER BY fp.id DESC LIMIT 1)";
    $supplierDoc = "(SELECT fp.document FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role) IN ('EMITENTE','FORNECEDOR') ORDER BY fp.id DESC LIMIT 1)";
    $recipientName = "(SELECT fp.name FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1)";
    $recipientDoc = "(SELECT fp.document FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1)";
    $sql = "SELECT fd.id,fd.company_id,UPPER(COALESCE(fd.direction,'')) AS direction,fd.model,COALESCE(fd.series,'') AS series,fd.number,"
      . "COALESCE(fd.access_key,'') AS access_key,COALESCE(fd.status,'') AS status,COALESCE(fd.operation_nature,'') AS operation_nature,"
      . "COALESCE(fd.issue_datetime,fd.created_at) AS issue_datetime,fd.created_at,"
      . "COALESCE(fd.total_products,0) AS total_products,COALESCE(fd.total_discount,0) AS total_discount,"
      . "COALESCE(fd.total_freight,0) AS total_freight,COALESCE(fd.total_other,0) AS total_other,"
      . "COALESCE(fd.total_icms,0) AS total_icms,COALESCE(fd.total_pis,0) AS total_pis,COALESCE(fd.total_cofins,0) AS total_cofins,"
      . "COALESCE(fd.total_document,0) AS total_document,COALESCE(fd.source_type,'') AS source_type,"
      . "CASE WHEN {$devolutionSql} THEN 1 ELSE 0 END AS _is_devolution,"
      . "CASE WHEN UPPER(COALESCE(fd.direction,''))='ENTRADA' THEN COALESCE({$supplierName},{$recipientName}) ELSE COALESCE({$recipientName},{$supplierName}) END AS recipient_name,"
      . "CASE WHEN UPPER(COALESCE(fd.direction,''))='ENTRADA' THEN COALESCE({$supplierDoc},{$recipientDoc}) ELSE COALESCE({$recipientDoc},{$supplierDoc}) END AS recipient_document,"
      . "'FISCAL_DOCUMENT' AS _source FROM fiscal_documents fd WHERE {$whereSql}";
    return ['sql'=>$sql,'params'=>$parts['params']];
  }
}

if (!function_exists('accounting_document_xml_select')) {
  /** @return array{enabled:bool,sql:string,params:array<int,mixed>} */
  function accounting_document_xml_select(int $companyId, array $filters, bool $onlyFiscalTotals = false): array {
    $parts = accounting_xml_document_search_parts($companyId, $filters, $onlyFiscalTotals);
    if (!$parts['enabled']) return ['enabled'=>false,'sql'=>'','params'=>[]];
    $whereSql = implode(' AND ', $parts['where']);
    $devolutionSql = accounting_xml_devolution_sql('x', 'op');
    $sql = "SELECT x.id,x.company_id,'ENTRADA' AS direction,COALESCE(x.model,'55') AS model,COALESCE(x.series,'') AS series,x.number,"
      . "COALESCE(x.access_key,'') AS access_key,COALESCE(x.status,'IMPORTADO') AS status,COALESCE(op.name,'Entrada por XML') AS operation_nature,"
      . "CAST(CONCAT(COALESCE(x.entry_date,x.issue_date,DATE(x.created_at)),' 00:00:00') AS DATETIME) AS issue_datetime,x.created_at,"
      . "COALESCE(x.total_products,0) AS total_products,COALESCE(x.discount_value,0) AS total_discount,"
      . "COALESCE(x.freight_value,0) AS total_freight,COALESCE(x.other_value,0) AS total_other,"
      . "0 AS total_icms,0 AS total_pis,0 AS total_cofins,COALESCE(x.total_invoice,0) AS total_document,"
      . "'XML_IMPORT' AS source_type,CASE WHEN {$devolutionSql} THEN 1 ELSE 0 END AS _is_devolution,"
      . "COALESCE(x.supplier_name,'FORNECEDOR NÃO INFORMADO') AS recipient_name,COALESCE(x.supplier_doc,'') AS recipient_document,"
      . "'XML_ENTRADA' AS _source FROM fiscal_xml_imports x LEFT JOIN fiscal_operations op ON op.company_id=x.company_id AND op.id=x.operation_id WHERE {$whereSql}";
    return ['enabled'=>true,'sql'=>$sql,'params'=>$parts['params']];
  }
}

if (!function_exists('accounting_document_union_sql')) {
  /** @return array{sql:string,params:array<int,mixed>} */
  function accounting_document_union_sql(PDO $pdo, int $companyId, array $filters, bool $onlyFiscalTotals = false): array {
    $fd = accounting_document_fd_select($companyId, $filters, $onlyFiscalTotals);
    $selects = [$fd['sql']];
    $params = $fd['params'];
    if (fiscal_table_exists($pdo, 'fiscal_xml_imports')) {
      $xml = accounting_document_xml_select($companyId, $filters, $onlyFiscalTotals);
      if ($xml['enabled']) {
        $selects[] = $xml['sql'];
        $params = array_merge($params, $xml['params']);
      }
    }
    return ['sql'=>implode(' UNION ALL ', $selects),'params'=>$params];
  }
}

if (!function_exists('accounting_search_documents')) {
  /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
  function accounting_search_documents(PDO $pdo, int $companyId, array $filters, int $page = 1, int $perPage = 100): array {
    fiscal_nfce_ensure_schema($pdo);
    fiscal_xml_ensure_schema($pdo);
    $page = max(1, $page);
    $perPage = max(20, min(200, $perPage));
    $union = accounting_document_union_sql($pdo, $companyId, $filters, false);

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM (' . $union['sql'] . ') documents_union');
    $countSt->execute($union['params']);
    $total = (int)$countSt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT * FROM (' . $union['sql'] . ') documents_union '
      . 'ORDER BY issue_datetime DESC,model,CAST(series AS UNSIGNED) DESC,CAST(number AS UNSIGNED) DESC,id DESC '
      . "LIMIT {$perPage} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute($union['params']);

    return [
      'rows'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: [],
      'total'=>$total,
      'page'=>$page,
      'per_page'=>$perPage,
      'pages'=>$pages,
    ];
  }
}

if (!function_exists('accounting_export_documents')) {
  /** @return array<int,array<string,mixed>> */
  function accounting_export_documents(PDO $pdo, int $companyId, array $filters, int $limit = 50000): array {
    fiscal_nfce_ensure_schema($pdo);
    fiscal_xml_ensure_schema($pdo);
    $limit = max(1, min(50000, $limit));
    $union = accounting_document_union_sql($pdo, $companyId, $filters, false);
    $sql = 'SELECT * FROM (' . $union['sql'] . ') documents_union '
      . 'ORDER BY issue_datetime,model,CAST(series AS UNSIGNED),CAST(number AS UNSIGNED),id LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($union['params']);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_document_filter_options')) {
  /** @return array{series:array<int,string>,statuses:array<int,string>} */
  function accounting_document_filter_options(PDO $pdo, int $companyId): array {
    fiscal_nfce_ensure_schema($pdo);
    fiscal_xml_ensure_schema($pdo);
    $series=[];$statuses=[];
    $st=$pdo->prepare("SELECT DISTINCT series FROM fiscal_documents fd WHERE fd.company_id=? AND fd.model IN ('55','65') AND COALESCE(fd.series,'')<>'' AND " . accounting_exclude_locally_discarded_sql('fd') . " ORDER BY CAST(series AS UNSIGNED),series");
    $st->execute([$companyId]);
    $series=array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    $st=$pdo->prepare("SELECT DISTINCT UPPER(status) AS status FROM fiscal_documents fd WHERE fd.company_id=? AND fd.model IN ('55','65') AND COALESCE(fd.status,'')<>'' AND " . accounting_exclude_locally_discarded_sql('fd') . " ORDER BY status");
    $st->execute([$companyId]);
    $statuses=array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN) ?: []));

    if (fiscal_table_exists($pdo, 'fiscal_xml_imports')) {
      $st=$pdo->prepare("SELECT DISTINCT series FROM fiscal_xml_imports WHERE company_id=? AND model IN ('55','65') AND COALESCE(series,'')<>'' ORDER BY CAST(series AS UNSIGNED),series");
      $st->execute([$companyId]);
      $series=array_merge($series,array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
      $st=$pdo->prepare("SELECT DISTINCT UPPER(status) AS status FROM fiscal_xml_imports WHERE company_id=? AND model IN ('55','65') AND COALESCE(status,'')<>'' ORDER BY status");
      $st->execute([$companyId]);
      $statuses=array_merge($statuses,array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    }
    $series=array_values(array_unique(array_filter($series,static fn(string $v): bool=>$v!=='')));
    $statuses=array_values(array_unique(array_filter($statuses,static fn(string $v): bool=>$v!=='')));
    usort($series,static fn(string $a,string $b): int=>strnatcasecmp($a,$b));
    usort($statuses,static fn(string $a,string $b): int=>strnatcasecmp($a,$b));
    return ['series'=>$series,'statuses'=>$statuses];
  }
}

if (!function_exists('accounting_merge_total_groups')) {
  /** @return array<int,array<string,mixed>> */
  function accounting_merge_total_groups(array $first, array $second): array {
    $map=[];
    foreach (array_merge($first,$second) as $row) {
      $code=(string)($row['code'] ?? '');
      if ($code==='') $code='SEM CÓDIGO';
      if (!isset($map[$code])) $map[$code]=['code'=>$code,'documents_count'=>0,'items_count'=>0,'total_value'=>0.0,'base_calc'=>0.0,'tax_amount'=>0.0,'fcp_st_value'=>0.0];
      foreach (['documents_count','items_count'] as $field) $map[$code][$field]+=(int)($row[$field] ?? 0);
      foreach (['total_value','base_calc','tax_amount','fcp_st_value'] as $field) $map[$code][$field]+=(float)($row[$field] ?? 0);
    }
    uksort($map,static function(string $a,string $b): int {
      $aSem=str_starts_with($a,'SEM '); $bSem=str_starts_with($b,'SEM ');
      if ($aSem!==$bSem) return $aSem?1:-1;
      return strnatcasecmp($a,$b);
    });
    return array_values($map);
  }
}

// NEXA_PATCH_FCP_ST_AGREGADO_XML_V3_20260805
if (!function_exists('accounting_document_totals')) {
  /**
   * Retorna os totais combinados de documentos próprios e XMLs de entrada.
   * Cancelamentos, rejeições, inutilizações e pré-notas não compõem os valores.
   *
   * @return array<string,mixed>
   */
  function accounting_document_totals(PDO $pdo, int $companyId, array $filters): array {
    fiscal_nfce_ensure_schema($pdo);
    fiscal_xml_ensure_schema($pdo);

    $parts = accounting_document_search_parts($companyId, $filters, true);
    $whereSql = implode(' AND ', $parts['where']);
    $st=$pdo->prepare("SELECT COUNT(*) AS documents_count,
      COALESCE(SUM(fd.total_document),0) AS total_document,
      COALESCE(SUM(fd.total_products),0) AS total_products,
      COALESCE(SUM(fd.total_icms),0) AS total_icms,
      COALESCE(SUM(fd.total_pis),0) AS total_pis,
      COALESCE(SUM(fd.total_cofins),0) AS total_cofins,
      COALESCE(SUM(fd.total_cbs),0) AS total_cbs,
      COALESCE(SUM(fd.total_ibs_uf),0) AS total_ibs_uf,
      COALESCE(SUM(fd.total_ibs_mun),0) AS total_ibs_mun
      FROM fiscal_documents fd WHERE {$whereSql}");
    $st->execute($parts['params']);
    $headline=$st->fetch(PDO::FETCH_ASSOC) ?: [];

    $itemValue = '(COALESCE(di.total_value,0)-COALESCE(di.discount_value,0)+COALESCE(di.freight_value,0)+COALESCE(di.insurance_value,0)+COALESCE(di.other_value,0))';
    $st=$pdo->prepare("SELECT COALESCE(NULLIF(TRIM(di.cfop),''),'SEM CFOP') AS code,
      COUNT(DISTINCT fd.id) AS documents_count,COUNT(di.id) AS items_count,
      COALESCE(SUM({$itemValue}),0) AS total_value,0 AS base_calc,0 AS tax_amount,0 AS fcp_st_value
      FROM fiscal_documents fd
      JOIN fiscal_document_items di ON di.company_id=fd.company_id AND di.fiscal_document_id=fd.id
      WHERE {$whereSql}
      GROUP BY COALESCE(NULLIF(TRIM(di.cfop),''),'SEM CFOP')");
    $st->execute($parts['params']);
    $cfop=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $taxGroups=[];
    foreach (['ICMS','PIS','COFINS'] as $taxGroup) {
      $codeExpr = $taxGroup === 'ICMS'
        ? "COALESCE(NULLIF(TRIM(tx.cst),''),NULLIF(TRIM(tx.csosn),''),'SEM CST')"
        : "COALESCE(NULLIF(TRIM(tx.cst),''),'SEM CST')";
      $sql="SELECT {$codeExpr} AS code,
        COUNT(DISTINCT fd.id) AS documents_count,COUNT(di.id) AS items_count,
        COALESCE(SUM({$itemValue}),0) AS total_value,
        COALESCE(SUM(tx.base_calc),0) AS base_calc,
        COALESCE(SUM(tx.tax_amount),0) AS tax_amount
        FROM fiscal_documents fd
        JOIN fiscal_document_items di ON di.company_id=fd.company_id AND di.fiscal_document_id=fd.id
        LEFT JOIN (
          SELECT fiscal_document_item_id,MAX(NULLIF(cst,'')) AS cst,MAX(NULLIF(csosn,'')) AS csosn,
            SUM(COALESCE(base_calc,0)) AS base_calc,SUM(COALESCE(amount,0)) AS tax_amount
          FROM fiscal_document_item_taxes
          WHERE company_id=? AND UPPER(tax_group)=?
          GROUP BY fiscal_document_item_id
        ) tx ON tx.fiscal_document_item_id=di.id
        WHERE {$whereSql}
        GROUP BY {$codeExpr}";
      $taxSt=$pdo->prepare($sql);
      $taxSt->execute(array_merge([$companyId,$taxGroup],$parts['params']));
      $taxGroups[strtolower($taxGroup)]=$taxSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $xmlHeadline=['documents_count'=>0,'total_document'=>0.0,'total_products'=>0.0];
    $xmlCfop=[];$xmlTax=['icms'=>[],'pis'=>[],'cofins'=>[]];$xmlIbsUf=0.0;$xmlIbsMun=0.0;$xmlCbs=0.0;
    $xmlParts=accounting_xml_document_search_parts($companyId,$filters,true);
    if ($xmlParts['enabled'] && fiscal_table_exists($pdo,'fiscal_xml_imports')) {
      $xmlWhere=implode(' AND ',$xmlParts['where']);
      $xmlJoin=' LEFT JOIN fiscal_operations op ON op.company_id=x.company_id AND op.id=x.operation_id ';
      $st=$pdo->prepare("SELECT COUNT(*) AS documents_count,COALESCE(SUM(x.total_invoice),0) AS total_document,COALESCE(SUM(x.total_products),0) AS total_products FROM fiscal_xml_imports x {$xmlJoin} WHERE {$xmlWhere}");
      $st->execute($xmlParts['params']);
      $xmlHeadline=$st->fetch(PDO::FETCH_ASSOC) ?: $xmlHeadline;

      $jsonNumber=static function(string $field): string {
        $path=preg_replace('/[^a-z0-9_]/i','',$field) ?: 'value';
        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(COALESCE(xi.tax_json,'')),xi.tax_json,'{}'),'$.{$path}')),'0') AS DECIMAL(18,4))";
      };
      // O XML importado pode armazenar o FCP-ST como fcp_st_amount ou como a tag original vFCPST.
      // O detalhamento já reconhece as duas formas; a consulta agregada deve usar a mesma regra.
      $validTaxJson = "IF(JSON_VALID(COALESCE(xi.tax_json,'')),xi.tax_json,'{}')";
      $fcpStExpr = "CAST(COALESCE("
        . "NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$validTaxJson},'$.fcp_st_amount')),''),"
        . "NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$validTaxJson},'$.vFCPST')),''),"
        . "NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$validTaxJson},'$.fcp_st_value')),''),"
        . "'0') AS DECIMAL(18,4))";
      // O valor-base dos agrupamentos é apurado sem FCP-ST. O FCP-ST é lido depois
      // diretamente do XML autorizado/importado e acrescentado ao CFOP e aos CSTs.
      // Isso evita depender de tax_json, que pode estar vazio em XMLs importados antigos.
      $xmlItemValue='(COALESCE(xi.total_value,0)-COALESCE(xi.discount_value,0)+COALESCE(xi.freight_value,0)+COALESCE(xi.insurance_value,0)+COALESCE(xi.other_value,0)+COALESCE(xi.ipi_value,0)+COALESCE(xi.icms_st_value,0))';
      $xmlCfopExpr="COALESCE(NULLIF(TRIM(xi.entry_cfop),''),NULLIF(TRIM(xi.cfop),''),'SEM CFOP')";
      $st=$pdo->prepare("SELECT {$xmlCfopExpr} AS code,COUNT(DISTINCT x.id) AS documents_count,COUNT(xi.id) AS items_count,COALESCE(SUM({$xmlItemValue}),0) AS total_value,0 AS base_calc,0 AS tax_amount,0 AS fcp_st_value FROM fiscal_xml_imports x {$xmlJoin} JOIN fiscal_xml_items xi ON xi.company_id=x.company_id AND xi.xml_import_id=x.id WHERE {$xmlWhere} GROUP BY {$xmlCfopExpr}");
      $st->execute($xmlParts['params']);
      $xmlCfop=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $taxDefinitions=[
        'icms'=>[
          "COALESCE(NULLIF(TRIM(xi.entry_icms_cst),''),NULLIF(TRIM(xi.entry_icms_csosn),''),NULLIF(TRIM(xi.icms_cst),''),NULLIF(TRIM(xi.icms_csosn),''),'SEM CST')",
          $jsonNumber('icms_base'),$jsonNumber('icms_amount')
        ],
        'pis'=>[
          "COALESCE(NULLIF(TRIM(xi.entry_pis_cst),''),NULLIF(TRIM(xi.pis_cst),''),'SEM CST')",
          $jsonNumber('pis_base'),$jsonNumber('pis_amount')
        ],
        'cofins'=>[
          "COALESCE(NULLIF(TRIM(xi.entry_cofins_cst),''),NULLIF(TRIM(xi.cofins_cst),''),'SEM CST')",
          $jsonNumber('cofins_base'),$jsonNumber('cofins_amount')
        ],
      ];
      foreach ($taxDefinitions as $key=>$definition) {
        [$codeExpr,$baseExpr,$amountExpr]=$definition;
        $sql="SELECT {$codeExpr} AS code,COUNT(DISTINCT x.id) AS documents_count,COUNT(xi.id) AS items_count,COALESCE(SUM({$xmlItemValue}),0) AS total_value,COALESCE(SUM({$baseExpr}),0) AS base_calc,COALESCE(SUM({$amountExpr}),0) AS tax_amount,0 AS fcp_st_value FROM fiscal_xml_imports x {$xmlJoin} JOIN fiscal_xml_items xi ON xi.company_id=x.company_id AND xi.xml_import_id=x.id WHERE {$xmlWhere} GROUP BY {$codeExpr}";
        $st=$pdo->prepare($sql); $st->execute($xmlParts['params']);
        $xmlTax[$key]=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }

      // O detalhamento da nota lê vFCPST diretamente do XML. A tela de resumo deve
      // seguir a mesma fonte para funcionar também com importações antigas, nas quais
      // tax_json não contém fcp_st_amount/vFCPST.
      $fcpAdjust=['cfop'=>[],'icms'=>[],'pis'=>[],'cofins'=>[]];
      $rawSt=$pdo->prepare("SELECT x.id AS xml_id,x.xml_content,xi.item_number,xi.tax_json,
        COALESCE(NULLIF(TRIM(xi.entry_cfop),''),NULLIF(TRIM(xi.cfop),''),'SEM CFOP') AS cfop_code,
        COALESCE(NULLIF(TRIM(xi.entry_icms_cst),''),NULLIF(TRIM(xi.entry_icms_csosn),''),NULLIF(TRIM(xi.icms_cst),''),NULLIF(TRIM(xi.icms_csosn),''),'SEM CST') AS icms_code,
        COALESCE(NULLIF(TRIM(xi.entry_pis_cst),''),NULLIF(TRIM(xi.pis_cst),''),'SEM CST') AS pis_code,
        COALESCE(NULLIF(TRIM(xi.entry_cofins_cst),''),NULLIF(TRIM(xi.cofins_cst),''),'SEM CST') AS cofins_code
        FROM fiscal_xml_imports x {$xmlJoin}
        JOIN fiscal_xml_items xi ON xi.company_id=x.company_id AND xi.xml_import_id=x.id
        WHERE {$xmlWhere}
        ORDER BY x.id,xi.item_number,xi.id");
      $rawSt->execute($xmlParts['params']);
      $rawRows=$rawSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $xmlItemMaps=[];$xmlTotalFcp=[];$xmlAllocatedFcp=[];$xmlFirstCodes=[];
      $jsonFindFcp=static function($value) use (&$jsonFindFcp): float {
        if (!is_array($value)) return 0.0;
        foreach (['fcp_st_amount','vFCPST','fcp_st_value'] as $key) {
          if (array_key_exists($key,$value) && is_numeric($value[$key])) return max(0.0,(float)$value[$key]);
        }
        foreach ($value as $child) {
          if (!is_array($child)) continue;
          $found=$jsonFindFcp($child);
          if ($found>0) return $found;
        }
        return 0.0;
      };
      foreach ($rawRows as $row) {
        $xmlId=(int)($row['xml_id'] ?? 0);
        if ($xmlId<=0) continue;
        if (!isset($xmlItemMaps[$xmlId])) {
          $entry=['xml_content'=>(string)($row['xml_content'] ?? '')];
          $xmlItemMaps[$xmlId]=function_exists('fiscal_report_entry_xml_item_st_map')
            ? fiscal_report_entry_xml_item_st_map($entry) : [];
          $totals=function_exists('fiscal_report_entry_xml_totals')
            ? fiscal_report_entry_xml_totals($entry) : [];
          $xmlTotalFcp[$xmlId]=(float)($totals['fcp_st_value'] ?? 0);
          $xmlIbsUf+=(float)($totals['ibs_uf_value'] ?? 0);
          $xmlIbsMun+=(float)($totals['ibs_mun_value'] ?? 0);
          $xmlCbs+=(float)($totals['cbs_value'] ?? 0);
          $xmlAllocatedFcp[$xmlId]=0.0;
        }
        $codes=[
          'cfop'=>(string)($row['cfop_code'] ?? 'SEM CFOP'),
          'icms'=>(string)($row['icms_code'] ?? 'SEM CST'),
          'pis'=>(string)($row['pis_code'] ?? 'SEM CST'),
          'cofins'=>(string)($row['cofins_code'] ?? 'SEM CST'),
        ];
        if (!isset($xmlFirstCodes[$xmlId])) $xmlFirstCodes[$xmlId]=$codes;
        $itemNo=max(1,(int)($row['item_number'] ?? 0));
        $fcp=(float)($xmlItemMaps[$xmlId][$itemNo]['fcp_st_value'] ?? 0);
        if ($fcp<=0) {
          $taxJson=json_decode((string)($row['tax_json'] ?? ''),true);
          if (is_array($taxJson)) $fcp=$jsonFindFcp($taxJson);
        }
        $fcp=round(max(0.0,$fcp),2);
        if ($fcp<=0) continue;
        foreach ($codes as $group=>$code) {
          if ($code==='') $code=$group==='cfop'?'SEM CFOP':'SEM CST';
          $fcpAdjust[$group][$code]=($fcpAdjust[$group][$code] ?? 0.0)+$fcp;
        }
        $xmlAllocatedFcp[$xmlId]+=$fcp;
      }
      // Caso o total do XML possua vFCPST, mas algum item não o traga, aloca-se a
      // diferença no primeiro CFOP/CST do documento para manter a soma da nota.
      foreach ($xmlTotalFcp as $xmlId=>$docFcp) {
        $difference=round(max(0.0,(float)$docFcp-(float)($xmlAllocatedFcp[$xmlId] ?? 0)),2);
        if ($difference<=0 || empty($xmlFirstCodes[$xmlId])) continue;
        foreach ($xmlFirstCodes[$xmlId] as $group=>$code) {
          if ($code==='') $code=$group==='cfop'?'SEM CFOP':'SEM CST';
          $fcpAdjust[$group][$code]=($fcpAdjust[$group][$code] ?? 0.0)+$difference;
        }
      }
      $applyFcp=static function(array &$groups,array $values,bool $showFcpColumn=false): void {
        foreach ($values as $code=>$amount) {
          $amount=round((float)$amount,2);
          if ($amount<=0) continue;
          $found=false;
          foreach ($groups as &$row) {
            if ((string)($row['code'] ?? '')!==(string)$code) continue;
            $row['total_value']=(float)($row['total_value'] ?? 0)+$amount;
            if ($showFcpColumn) $row['fcp_st_value']=(float)($row['fcp_st_value'] ?? 0)+$amount;
            $found=true; break;
          }
          unset($row);
          if (!$found) {
            $groups[]=['code'=>(string)$code,'documents_count'=>0,'items_count'=>0,
              'total_value'=>$amount,'base_calc'=>0.0,'tax_amount'=>0.0,
              'fcp_st_value'=>$showFcpColumn?$amount:0.0];
          }
        }
      };
      $applyFcp($xmlCfop,$fcpAdjust['cfop'],true);
      $applyFcp($xmlTax['icms'],$fcpAdjust['icms']);
      $applyFcp($xmlTax['pis'],$fcpAdjust['pis']);
      $applyFcp($xmlTax['cofins'],$fcpAdjust['cofins']);
    }

    $cfop=accounting_merge_total_groups($cfop,$xmlCfop);
    $icms=accounting_merge_total_groups($taxGroups['icms'] ?? [],$xmlTax['icms']);
    $pis=accounting_merge_total_groups($taxGroups['pis'] ?? [],$xmlTax['pis']);
    $cofins=accounting_merge_total_groups($taxGroups['cofins'] ?? [],$xmlTax['cofins']);
    $xmlIcms=array_sum(array_map(static fn(array $r): float=>(float)($r['tax_amount']??0),$xmlTax['icms']));
    $xmlPis=array_sum(array_map(static fn(array $r): float=>(float)($r['tax_amount']??0),$xmlTax['pis']));
    $xmlCofins=array_sum(array_map(static fn(array $r): float=>(float)($r['tax_amount']??0),$xmlTax['cofins']));
    $xmlFcpSt=array_sum(array_map(static fn(array $r): float=>(float)($r['fcp_st_value']??0),$xmlCfop));

    $totalIcms=(float)($headline['total_icms'] ?? 0)+$xmlIcms;
    $totalPis=(float)($headline['total_pis'] ?? 0)+$xmlPis;
    $totalCofins=(float)($headline['total_cofins'] ?? 0)+$xmlCofins;
    $totalCbs=(float)($headline['total_cbs'] ?? 0)+$xmlCbs;
    $totalIbsUf=(float)($headline['total_ibs_uf'] ?? 0)+$xmlIbsUf;
    $totalIbsMun=(float)($headline['total_ibs_mun'] ?? 0)+$xmlIbsMun;
    $totalTributacao=$totalIcms+$xmlFcpSt+$totalPis+$totalCofins+$totalCbs+$totalIbsUf+$totalIbsMun;

    return [
      'documents_count'=>(int)($headline['documents_count'] ?? 0)+(int)($xmlHeadline['documents_count'] ?? 0),
      'total_document'=>(float)($headline['total_document'] ?? 0)+(float)($xmlHeadline['total_document'] ?? 0),
      'total_products'=>(float)($headline['total_products'] ?? 0)+(float)($xmlHeadline['total_products'] ?? 0),
      'total_icms'=>$totalIcms,
      'total_pis'=>$totalPis,
      'total_cofins'=>$totalCofins,
      'total_fcp_st'=>$xmlFcpSt,
      'total_cbs'=>$totalCbs,
      'total_ibs_uf'=>$totalIbsUf,
      'total_ibs_mun'=>$totalIbsMun,
      'total_tributacao'=>$totalTributacao,
      'cfop'=>$cfop,'icms'=>$icms,'pis'=>$pis,'cofins'=>$cofins,
    ];
  }
}

if (!function_exists('accounting_document_detail')) {
  /**
   * Carrega o documento fiscal completo para conferência na Central do Contador.
   * O vínculo do contador com a empresa deve ser validado pela página chamadora.
   *
   * @return array<string,mixed>|null
   */
  function accounting_document_detail(PDO $pdo, int $companyId, int $documentId): ?array {
    fiscal_nfce_ensure_schema($pdo);
    if ($companyId <= 0 || $documentId <= 0) return null;

    $st = $pdo->prepare("SELECT * FROM fiscal_documents fd WHERE fd.company_id=? AND fd.id=? AND fd.model IN ('55','65') AND "
      . accounting_exclude_locally_discarded_sql('fd') . ' LIMIT 1');
    $st->execute([$companyId, $documentId]);
    $document = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$document) return null;

    $participants = [];
    if (fiscal_table_exists($pdo, 'fiscal_document_participants')) {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_participants WHERE company_id=? AND fiscal_document_id=? ORDER BY CASE UPPER(role) WHEN \'EMITENTE\' THEN 0 WHEN \'DESTINATARIO\' THEN 1 WHEN \'FORNECEDOR\' THEN 2 ELSE 9 END,id');
      $st->execute([$companyId, $documentId]);
      $participants = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $items = [];
    if (fiscal_table_exists($pdo, 'fiscal_document_items')) {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id=? ORDER BY item_number,id');
      $st->execute([$companyId, $documentId]);
      $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $taxesByItem = [];
    if ($items && fiscal_table_exists($pdo, 'fiscal_document_item_taxes')) {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? ORDER BY fiscal_document_item_id,CASE UPPER(tax_group) WHEN \'ICMS\' THEN 0 WHEN \'ICMSST\' THEN 1 WHEN \'FCP\' THEN 2 WHEN \'IPI\' THEN 3 WHEN \'PIS\' THEN 4 WHEN \'COFINS\' THEN 5 WHEN \'IBSCBS\' THEN 6 ELSE 9 END,id');
      $st->execute([$companyId, $documentId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $tax) {
        $taxesByItem[(int)$tax['fiscal_document_item_id']][] = $tax;
      }
    }
    foreach ($items as &$item) $item['taxes'] = $taxesByItem[(int)$item['id']] ?? [];
    unset($item);

    $payments = [];
    if (fiscal_table_exists($pdo, 'fiscal_document_payments')) {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_payments WHERE company_id=? AND fiscal_document_id=? ORDER BY id');
      $st->execute([$companyId, $documentId]);
      $payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $references = [];
    if (fiscal_table_exists($pdo, 'fiscal_document_references')) {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_references WHERE company_id=? AND fiscal_document_id=? ORDER BY id');
      $st->execute([$companyId, $documentId]);
      $references = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $events = [];
    if (fiscal_table_exists($pdo, 'fiscal_document_events')) {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_events WHERE company_id=? AND fiscal_document_id=? ORDER BY event_datetime DESC,id DESC');
      $st->execute([$companyId, $documentId]);
      $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return [
      'document'=>$document,
      'participants'=>$participants,
      'items'=>$items,
      'payments'=>$payments,
      'references'=>$references,
      'events'=>$events,
    ];
  }
}

if (!function_exists('accounting_product_search_parts')) {
  /** @return array{where:array<int,string>,params:array<int,mixed>} */
  function accounting_product_search_parts(int $companyId, array $filters): array {
    $ini = accounting_validate_date((string)($filters['ini'] ?? ''), date('Y-m-01'));
    $fim = accounting_validate_date((string)($filters['fim'] ?? ''), date('Y-m-d'));
    if ($ini > $fim) [$ini, $fim] = [$fim, $ini];

    $where = [
      'fd.company_id=?',
      "fd.model IN ('55','65')",
      '(UPPER(COALESCE(fd.status,\'\')) LIKE \'AUTORIZ%\' OR UPPER(COALESCE(fd.status,\'\')) LIKE \'IMPORTAD%\')',
      'COALESCE(fd.issue_datetime,fd.created_at)>=?',
      'COALESCE(fd.issue_datetime,fd.created_at)<?',
    ];
    $params = [$companyId, $ini . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($fim . ' +1 day'))];

    $model = trim((string)($filters['model'] ?? ''));
    if (in_array($model, ['55','65'], true)) { $where[]='fd.model=?'; $params[]=$model; }

    $direction = strtoupper(trim((string)($filters['direction'] ?? '')));
    if (in_array($direction, ['ENTRADA','SAIDA'], true)) { $where[]='UPPER(fd.direction)=?'; $params[]=$direction; }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
      $like = nexa_search_like($q);
      $where[] = '(COALESCE(di.product_code,\'\') LIKE ? OR COALESCE(di.supplier_product_code,\'\') LIKE ? OR COALESCE(di.ean,\'\') LIKE ? OR COALESCE(di.description,\'\') LIKE ?)';
      array_push($params, $like, $like, $like, $like);
    }

    foreach (['ncm'=>'di.ncm','cest'=>'di.cest','cfop'=>'di.cfop'] as $key=>$column) {
      $value = trim((string)($filters[$key] ?? ''));
      if ($value !== '') { $where[]="COALESCE({$column},'')=?"; $params[]=$value; }
    }

    $icmsCst = trim((string)($filters['icms_cst'] ?? ''));
    if ($icmsCst !== '') { $where[]="COALESCE(NULLIF(icms.cst,''),NULLIF(icms.csosn,''),'')=?"; $params[]=$icmsCst; }
    $pisCst = trim((string)($filters['pis_cst'] ?? ''));
    if ($pisCst !== '') { $where[]="COALESCE(pis.cst,'')=?"; $params[]=$pisCst; }
    $cofinsCst = trim((string)($filters['cofins_cst'] ?? ''));
    if ($cofinsCst !== '') { $where[]="COALESCE(cofins.cst,'')=?"; $params[]=$cofinsCst; }
    $ibsCbsCst = trim((string)($filters['ibscbs_cst'] ?? ''));
    if ($ibsCbsCst !== '') { $where[]="COALESCE(ibscbs.cst,'')=?"; $params[]=$ibsCbsCst; }

    return ['where'=>$where,'params'=>$params];
  }
}

if (!function_exists('accounting_product_tax_joins')) {
  /** @return array{sql:string,params:array<int,mixed>} */
  function accounting_product_tax_joins(int $companyId): array {
    $sql = "
      LEFT JOIN (
        SELECT fiscal_document_item_id,MAX(NULLIF(cst,'')) AS cst,MAX(NULLIF(csosn,'')) AS csosn,
          SUM(COALESCE(base_calc,0)) AS base_calc,SUM(COALESCE(amount,0)) AS amount
        FROM fiscal_document_item_taxes WHERE company_id=? AND UPPER(tax_group)='ICMS' GROUP BY fiscal_document_item_id
      ) icms ON icms.fiscal_document_item_id=di.id
      LEFT JOIN (
        SELECT fiscal_document_item_id,MAX(NULLIF(cst,'')) AS cst,
          SUM(COALESCE(base_calc,0)) AS base_calc,SUM(COALESCE(amount,0)) AS amount
        FROM fiscal_document_item_taxes WHERE company_id=? AND UPPER(tax_group)='PIS' GROUP BY fiscal_document_item_id
      ) pis ON pis.fiscal_document_item_id=di.id
      LEFT JOIN (
        SELECT fiscal_document_item_id,MAX(NULLIF(cst,'')) AS cst,
          SUM(COALESCE(base_calc,0)) AS base_calc,SUM(COALESCE(amount,0)) AS amount
        FROM fiscal_document_item_taxes WHERE company_id=? AND UPPER(tax_group)='COFINS' GROUP BY fiscal_document_item_id
      ) cofins ON cofins.fiscal_document_item_id=di.id
      LEFT JOIN (
        SELECT fiscal_document_item_id,MAX(NULLIF(cst,'')) AS cst,MAX(NULLIF(class_code,'')) AS class_code,
          SUM(COALESCE(base_calc,0)) AS base_calc,SUM(COALESCE(amount,0)) AS amount
        FROM fiscal_document_item_taxes WHERE company_id=? AND UPPER(tax_group) IN ('IBSCBS','IBS','CBS') GROUP BY fiscal_document_item_id
      ) ibscbs ON ibscbs.fiscal_document_item_id=di.id";
    return ['sql'=>$sql,'params'=>[$companyId,$companyId,$companyId,$companyId]];
  }
}

if (!function_exists('accounting_search_product_items')) {
  /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
  function accounting_search_product_items(PDO $pdo, int $companyId, array $filters, int $page=1, int $perPage=150): array {
    fiscal_nfce_ensure_schema($pdo);
    $page=max(1,$page); $perPage=max(20,min(300,$perPage));
    $parts=accounting_product_search_parts($companyId,$filters);
    $joins=accounting_product_tax_joins($companyId);
    $whereSql=implode(' AND ',$parts['where']);
    $params=array_merge($joins['params'],$parts['params']);

    $countSql="SELECT COUNT(*) FROM fiscal_documents fd JOIN fiscal_document_items di ON di.company_id=fd.company_id AND di.fiscal_document_id=fd.id {$joins['sql']} WHERE {$whereSql}";
    $st=$pdo->prepare($countSql); $st->execute($params); $total=(int)$st->fetchColumn();
    $pages=max(1,(int)ceil($total/$perPage)); if($page>$pages)$page=$pages; $offset=($page-1)*$perPage;
    $lineTotal='(COALESCE(di.total_value,0)-COALESCE(di.discount_value,0)+COALESCE(di.freight_value,0)+COALESCE(di.insurance_value,0)+COALESCE(di.other_value,0))';
    $sql="SELECT fd.id AS fiscal_document_id,fd.issue_datetime,fd.created_at,fd.direction,fd.model,fd.series,fd.number,fd.access_key,
      di.id AS item_id,di.item_number,di.product_id,di.product_code,di.supplier_product_code,di.ean,di.description,di.ncm,di.cest,di.cfop,di.unidade,
      di.quantity,di.unit_value,di.total_value,di.discount_value,di.freight_value,di.insurance_value,di.other_value,{$lineTotal} AS line_total,
      COALESCE(NULLIF(icms.cst,''),NULLIF(icms.csosn,''),'') AS icms_cst,COALESCE(icms.base_calc,0) AS icms_base,COALESCE(icms.amount,0) AS icms_amount,
      COALESCE(pis.cst,'') AS pis_cst,COALESCE(pis.base_calc,0) AS pis_base,COALESCE(pis.amount,0) AS pis_amount,
      COALESCE(cofins.cst,'') AS cofins_cst,COALESCE(cofins.base_calc,0) AS cofins_base,COALESCE(cofins.amount,0) AS cofins_amount,
      COALESCE(ibscbs.cst,'') AS ibscbs_cst,COALESCE(ibscbs.class_code,'') AS ibscbs_class,COALESCE(ibscbs.base_calc,0) AS ibscbs_base,COALESCE(ibscbs.amount,0) AS ibscbs_amount
      FROM fiscal_documents fd
      JOIN fiscal_document_items di ON di.company_id=fd.company_id AND di.fiscal_document_id=fd.id
      {$joins['sql']}
      WHERE {$whereSql}
      ORDER BY COALESCE(fd.issue_datetime,fd.created_at) DESC,fd.model,CAST(fd.series AS UNSIGNED) DESC,fd.number DESC,di.item_number,di.id
      LIMIT {$perPage} OFFSET {$offset}";
    $st=$pdo->prepare($sql); $st->execute($params);
    return ['rows'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: [],'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>$pages];
  }
}


if (!function_exists('accounting_export_product_items')) {
  /** @return array<int,array<string,mixed>> */
  function accounting_export_product_items(PDO $pdo, int $companyId, array $filters, int $limit=50000): array {
    fiscal_nfce_ensure_schema($pdo);
    $limit=max(1,min(50000,$limit));
    $parts=accounting_product_search_parts($companyId,$filters);
    $joins=accounting_product_tax_joins($companyId);
    $whereSql=implode(' AND ',$parts['where']);
    $lineTotal='(COALESCE(di.total_value,0)-COALESCE(di.discount_value,0)+COALESCE(di.freight_value,0)+COALESCE(di.insurance_value,0)+COALESCE(di.other_value,0))';
    $sql="SELECT fd.id AS fiscal_document_id,fd.issue_datetime,fd.created_at,fd.direction,fd.model,fd.series,fd.number,fd.access_key,
      di.id AS item_id,di.item_number,di.product_id,di.product_code,di.supplier_product_code,di.ean,di.description,di.ncm,di.cest,di.cfop,di.unidade,
      di.quantity,di.unit_value,di.total_value,di.discount_value,di.freight_value,di.insurance_value,di.other_value,{$lineTotal} AS line_total,
      COALESCE(NULLIF(icms.cst,''),NULLIF(icms.csosn,''),'') AS icms_cst,COALESCE(icms.base_calc,0) AS icms_base,COALESCE(icms.amount,0) AS icms_amount,
      COALESCE(pis.cst,'') AS pis_cst,COALESCE(pis.base_calc,0) AS pis_base,COALESCE(pis.amount,0) AS pis_amount,
      COALESCE(cofins.cst,'') AS cofins_cst,COALESCE(cofins.base_calc,0) AS cofins_base,COALESCE(cofins.amount,0) AS cofins_amount,
      COALESCE(ibscbs.cst,'') AS ibscbs_cst,COALESCE(ibscbs.class_code,'') AS ibscbs_class,COALESCE(ibscbs.base_calc,0) AS ibscbs_base,COALESCE(ibscbs.amount,0) AS ibscbs_amount
      FROM fiscal_documents fd JOIN fiscal_document_items di ON di.company_id=fd.company_id AND di.fiscal_document_id=fd.id
      {$joins['sql']} WHERE {$whereSql}
      ORDER BY COALESCE(fd.issue_datetime,fd.created_at) DESC,fd.model,CAST(fd.series AS UNSIGNED) DESC,fd.number DESC,di.item_number,di.id
      LIMIT {$limit}";
    $st=$pdo->prepare($sql);
    $st->execute(array_merge($joins['params'],$parts['params']));
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_product_totals')) {
  /** @return array<string,float|int> */
  function accounting_product_totals(PDO $pdo, int $companyId, array $filters): array {
    fiscal_nfce_ensure_schema($pdo);
    $parts=accounting_product_search_parts($companyId,$filters);
    $joins=accounting_product_tax_joins($companyId);
    $whereSql=implode(' AND ',$parts['where']);
    $lineTotal='(COALESCE(di.total_value,0)-COALESCE(di.discount_value,0)+COALESCE(di.freight_value,0)+COALESCE(di.insurance_value,0)+COALESCE(di.other_value,0))';
    $sql="SELECT COUNT(*) AS items_count,COUNT(DISTINCT COALESCE(NULLIF(di.product_code,''),CONCAT('ID:',COALESCE(di.product_id,0),':',di.description))) AS products_count,
      COALESCE(SUM(di.quantity),0) AS quantity_total,COALESCE(SUM({$lineTotal}),0) AS total_value,
      COALESCE(SUM(icms.amount),0) AS total_icms,COALESCE(SUM(pis.amount),0) AS total_pis,COALESCE(SUM(cofins.amount),0) AS total_cofins,COALESCE(SUM(ibscbs.amount),0) AS total_ibscbs
      FROM fiscal_documents fd JOIN fiscal_document_items di ON di.company_id=fd.company_id AND di.fiscal_document_id=fd.id
      {$joins['sql']} WHERE {$whereSql}";
    $st=$pdo->prepare($sql); $st->execute(array_merge($joins['params'],$parts['params']));
    $row=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
      'items_count'=>(int)($row['items_count']??0),'products_count'=>(int)($row['products_count']??0),
      'quantity_total'=>(float)($row['quantity_total']??0),'total_value'=>(float)($row['total_value']??0),
      'total_icms'=>(float)($row['total_icms']??0),'total_pis'=>(float)($row['total_pis']??0),
      'total_cofins'=>(float)($row['total_cofins']??0),'total_ibscbs'=>(float)($row['total_ibscbs']??0),
    ];
  }
}

if (!function_exists('accounting_product_filter_options')) {
  /** @return array{icms:array<int,string>,pis:array<int,string>,cofins:array<int,string>,ibscbs:array<int,string>} */
  function accounting_product_filter_options(PDO $pdo, int $companyId): array {
    fiscal_nfce_ensure_schema($pdo);
    $out=['icms'=>[],'pis'=>[],'cofins'=>[],'ibscbs'=>[]];
    $groups=['icms'=>['ICMS'],'pis'=>['PIS'],'cofins'=>['COFINS'],'ibscbs'=>['IBSCBS','IBS','CBS']];
    foreach($groups as $key=>$names){
      $ph=implode(',',array_fill(0,count($names),'?'));
      $code=$key==='icms'?"COALESCE(NULLIF(cst,''),NULLIF(csosn,''),'')":"COALESCE(cst,'')";
      $st=$pdo->prepare("SELECT DISTINCT {$code} AS code FROM fiscal_document_item_taxes WHERE company_id=? AND UPPER(tax_group) IN ({$ph}) AND {$code}<>'' ORDER BY code");
      $st->execute(array_merge([$companyId],$names));
      $out[$key]=array_values(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }
    return $out;
  }
}

if (!function_exists('accounting_product_rows_csv')) {
  function accounting_product_rows_csv(array $rows): string {
    $csv=[];
    foreach($rows as $row){
      $dt=(string)($row['issue_datetime']??$row['created_at']??'');
      $csv[]=[
        $dt!==''?date('d/m/Y H:i:s',strtotime($dt)):'',
        strtoupper((string)($row['direction']??''))==='ENTRADA'?'Entrada':'Saída',
        (string)($row['model']??'')==='55'?'NF-e':'NFC-e',(string)($row['number']??''),(string)($row['series']??''),(string)($row['access_key']??''),
        (string)($row['product_code']?:$row['supplier_product_code']),(string)($row['ean']??''),(string)($row['ncm']??''),(string)($row['cest']??''),(string)($row['cfop']??''),(string)($row['description']??''),(string)($row['unidade']??''),
        number_format((float)($row['quantity']??0),4,',',''),number_format((float)($row['unit_value']??0),4,',',''),number_format((float)($row['line_total']??0),2,',',''),
        (string)($row['icms_cst']??''),(string)($row['pis_cst']??''),(string)($row['cofins_cst']??''),(string)($row['ibscbs_cst']??''),(string)($row['ibscbs_class']??''),
        number_format((float)($row['icms_amount']??0),2,',',''),number_format((float)($row['pis_amount']??0),2,',',''),number_format((float)($row['cofins_amount']??0),2,',',''),number_format((float)($row['ibscbs_amount']??0),2,',',''),
      ];
    }
    return accounting_csv(['Data/Hora','Movimento','Documento','Número','Série','Chave','Código','EAN','NCM','CEST','CFOP','Produto','Unidade','Quantidade','Valor unitário','Total','CST/CSOSN ICMS','CST PIS','CST COFINS','CST IBS/CBS','Classificação IBS/CBS','ICMS','PIS','COFINS','IBS/CBS'],$csv);
  }
}

if (!function_exists('accounting_documents_csv')) {
  function accounting_documents_csv(array $docs): string {
    $rows = [];
    foreach ($docs as $doc) {
      $dt = (string)($doc['issue_datetime'] ?? $doc['created_at'] ?? '');
      $rows[] = [
        $dt !== '' ? date('d/m/Y H:i:s', strtotime($dt)) : '',
        (string)($doc['direction'] ?? '') === 'ENTRADA' ? 'Entrada' : 'Saída',
        (string)($doc['model'] ?? '') === '55' ? 'NF-e' : 'NFC-e',
        (string)($doc['number'] ?? ''),
        (string)($doc['model'] ?? ''),
        (string)($doc['series'] ?? ''),
        (string)($doc['access_key'] ?? ''),
        (string)($doc['recipient_name'] ?? 'CONSUMIDOR FINAL'),
        (string)($doc['recipient_document'] ?? ''),
        (string)($doc['status'] ?? ''),
        (string)($doc['operation_nature'] ?? ''),
        number_format((float)($doc['total_products'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_discount'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_freight'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_icms'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_icms_st'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_ipi'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_pis'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_cofins'] ?? 0), 2, ',', ''),
        number_format((float)($doc['total_document'] ?? 0), 2, ',', ''),
      ];
    }
    return accounting_csv([
      'Data/Hora','Movimento','Tipo','Número','Modelo','Série','Chave de acesso','Participante','CPF/CNPJ','Situação','Natureza da operação',
      'Total produtos','Desconto','Frete','ICMS','ICMS ST','IPI','PIS','COFINS','Total documento'
    ], $rows);
  }
}

if (!function_exists('accounting_inventory_rows')) {
  function accounting_inventory_rows(PDO $pdo, int $companyId): array {
    $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasStock = fiscal_column_exists($pdo, 'produtos', 'estoque_atual');
    $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
    $hasUnit = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
    $hasTax = fiscal_table_exists($pdo, 'fiscal_product_tax');
    $codeSelect = $hasCode ? 'p.codigo_firebird' : 'CAST(p.id AS CHAR)';
    $stockSelect = $hasStock ? 'COALESCE(p.estoque_atual,0)' : '0';
    $costSelect = $hasCost ? 'COALESCE(p.custo,0)' : 'COALESCE(p.preco,0)';
    $unitSelect = $hasUnit ? "COALESCE(p.unidade_estoque,'UN')" : "'UN'";
    $taxSelect = $hasTax ? 'pt.ncm,pt.cest,pt.icms_cst,pt.icms_csosn,pt.pis_cst,pt.cofins_cst' : "'' AS ncm,'' AS cest,'' AS icms_cst,'' AS icms_csosn,'' AS pis_cst,'' AS cofins_cst";
    $join = $hasTax ? ' LEFT JOIN fiscal_product_tax pt ON pt.company_id=p.company_id AND pt.product_id=p.id' : '';
    $sql = "SELECT p.id,{$codeSelect} AS code,p.nome,p.categoria,{$unitSelect} AS unidade,{$stockSelect} AS estoque,{$costSelect} AS custo,{$taxSelect}
      FROM produtos p{$join} WHERE p.company_id=? ORDER BY p.nome,p.id";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) $row['_total'] = round((float)($row['estoque'] ?? 0) * (float)($row['custo'] ?? 0), 2);
    unset($row);
    return $rows;
  }
}

if (!function_exists('accounting_inventory_csv')) {
  function accounting_inventory_csv(array $rows, string $snapshotAt): string {
    $csvRows = [];
    foreach ($rows as $row) {
      $csvRows[] = [
        $snapshotAt,
        (string)($row['code'] ?? ''),
        (string)($row['nome'] ?? ''),
        (string)($row['categoria'] ?? ''),
        (string)($row['unidade'] ?? 'UN'),
        number_format((float)($row['estoque'] ?? 0), 4, ',', ''),
        number_format((float)($row['custo'] ?? 0), 4, ',', ''),
        number_format((float)($row['_total'] ?? 0), 2, ',', ''),
        (string)($row['ncm'] ?? ''),
        (string)($row['cest'] ?? ''),
        (string)($row['icms_cst'] ?? ''),
        (string)($row['icms_csosn'] ?? ''),
        (string)($row['pis_cst'] ?? ''),
        (string)($row['cofins_cst'] ?? ''),
      ];
    }
    return accounting_csv(['Data do inventário','Código','Produto','Categoria','Unidade','Estoque','Custo','Total','NCM','CEST','CST ICMS','CSOSN','CST PIS','CST COFINS'], $csvRows);
  }
}

if (!function_exists('accounting_register_file')) {
  function accounting_register_file(PDO $pdo, int $closureId, int $companyId, string $type, string $displayName, string $absolutePath, string $mime): array {
    $root = realpath(accounting_storage_root());
    $real = realpath($absolutePath);
    if (!$root || !$real || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Arquivo do fechamento fora da pasta permitida.');
    $relative = str_replace('\\', '/', substr($real, strlen($root) + 1));
    $size = (int)(filesize($real) ?: 0);
    $hash = hash_file('sha256', $real) ?: null;
    $st = $pdo->prepare("INSERT INTO accounting_closure_files (closure_id,company_id,file_type,display_name,file_name,relative_path,mime_type,file_size,checksum_sha256,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),file_name=VALUES(file_name),relative_path=VALUES(relative_path),mime_type=VALUES(mime_type),file_size=VALUES(file_size),checksum_sha256=VALUES(checksum_sha256),created_at=NOW()");
    $st->execute([$closureId,$companyId,$type,$displayName,basename($real),$relative,$mime,$size,$hash]);
    return ['type'=>$type,'display_name'=>$displayName,'file_name'=>basename($real),'absolute_path'=>$real,'relative_path'=>$relative,'mime_type'=>$mime,'file_size'=>$size];
  }
}

if (!function_exists('accounting_write_artifact')) {
  function accounting_write_artifact(PDO $pdo, int $closureId, int $companyId, string $type, string $displayName, string $fileName, string $content, string $mime): array {
    $fileName = accounting_safe_part($fileName, 'arquivo');
    $path = accounting_closure_dir($companyId, $closureId) . '/' . $fileName;
    if (@file_put_contents($path, $content, LOCK_EX) === false) {
      $last = error_get_last();
      $detail = trim((string)($last['message'] ?? ''));
      throw new RuntimeException('Não foi possível gravar ' . $displayName . ' em ' . $path . ($detail !== '' ? ' - ' . $detail : ''));
    }
    return accounting_register_file($pdo,$closureId,$companyId,$type,$displayName,$path,$mime);
  }
}

if (!function_exists('accounting_model_label')) {
  function accounting_model_label(string $model): string {
    return $model === '55' ? 'Somente NF-e' : ($model === '65' ? 'Somente NFC-e' : 'NF-e e NFC-e');
  }
}

if (!function_exists('accounting_xml_types')) {
  function accounting_xml_types(string $model): array {
    if ($model === '55') return ['nfe','nfe_cancelada','nfe_inutilizada'];
    if ($model === '65') return ['nfce','nfce_cancelada','nfce_inutilizada'];
    return ['nfce','nfe','nfce_cancelada','nfe_cancelada','nfce_inutilizada','nfe_inutilizada'];
  }
}

/**
 * Fonte fiscal das faixas efetivamente homologadas pela SEFAZ.
 * Independente do status de fiscal_documents, inclusive quando a NF-e
 * original foi DESCARTADA. So consulta registros; nao transmite nem altera.
 * Os mesmos criterios de homologacao sao usados pelo exportador de XML.
 */
if (!function_exists('accounting_closure_inutilizations')) {
  function accounting_closure_inutilizations(PDO $pdo, int $companyId, string $ini, string $fim, string $model = '', string $series = ''): array {
    if ($companyId <= 0 || !fiscal_table_exists($pdo, 'fiscal_inutilizations')) return [];
    $where = [
      'company_id=?',
      "model IN ('55','65')",
      "UPPER(TRIM(COALESCE(status,'')))='HOMOLOGADA'",
      "TRIM(COALESCE(status_code,''))='102'",
      "NULLIF(TRIM(COALESCE(protocol_number,'')),'') IS NOT NULL",
      'COALESCE(event_datetime,created_at)>=?',
      'COALESCE(event_datetime,created_at)<?',
    ];
    $params = [$companyId, $ini . ' 00:00:00', date('Y-m-d 00:00:00',strtotime($fim . ' +1 day'))];
    if (in_array($model,['55','65'],true)) { $where[]='model=?'; $params[]=$model; }
    if ($series !== '') {
      // fiscal_inutilizations.series e INT: compara numericamente e preserva
      // a serie da configuracao da nota na montagem do grupo do fechamento.
      if (!ctype_digit($series)) return [];
      $where[]='series=?'; $params[]=(int)$series;
    }
    $st = $pdo->prepare('SELECT model,series,start_number,end_number,fiscal_year,event_datetime,protocol_number'
      . ' FROM fiscal_inutilizations WHERE ' . implode(' AND ',$where)
      . ' ORDER BY model,series,start_number,id');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_closure_inutilizations_summary')) {
  function accounting_closure_inutilizations_summary(array $rows): string {
    $faixas=count($rows);
    $numeros=0;
    foreach ($rows as $r) $numeros += max(0, (int)($r['end_number'] ?? 0)-(int)($r['start_number'] ?? 0)+1);
    $s = "\r\nINUTILIZACOES DE NUMERACAO HOMOLOGADAS NA SEFAZ\r\n";
    $s .= "Faixas inutilizadas: {$faixas} | Numeros inutilizados: {$numeros}\r\n";
    if ($faixas === 0) return $s;
    foreach ($rows as $r) {
      $mod=(string)($r['model'] ?? '');
      $label=$mod==='55'?'NF-e':($mod==='65'?'NFC-e':'Documento fiscal');
      $serie=(string)($r['series'] ?? '0');
      $ini=(int)($r['start_number'] ?? 0);
      $fim=(int)($r['end_number'] ?? 0);
      $faixa=$ini===$fim?('numero ' . $ini):('numeros ' . $ini . ' a ' . $fim);
      $protocolo=(string)($r['protocol_number'] ?? '');
      $data=(string)($r['event_datetime'] ?? '');
      $ano=(string)($r['fiscal_year'] ?? '');
      $s .= " - {$label} / Serie {$serie} / {$faixa} / Ano {$ano} / Protocolo {$protocolo}";
      if ($data !== '') $s .= ' / Homologacao ' . date('d/m/Y',strtotime($data));
      $s .= "\r\n";
    }
    return $s;
  }
}

if (!function_exists('accounting_closure_series_groups')) {
  function accounting_closure_series_groups(PDO $pdo, int $companyId, string $ini, string $fim, string $model = '', string $series = ''): array {
    $groups=[];
    $register=static function(array $rows) use (&$groups): void {
      foreach ($rows as $row) {
        $m=trim((string)($row['model'] ?? ''));
        $sr=trim((string)($row['series'] ?? ''));
        if (!in_array($m,['55','65'],true)) continue;
        $groups[$m . '|' . $sr]=['model'=>$m,'series'=>$sr];
      }
    };

    $where=[
      'company_id=?',"model IN ('55','65')",'COALESCE(number,0)>0',
      "UPPER(COALESCE(status,'')) NOT IN ('DIGITACAO','PROCESSANDO','ERRO','DESCARTADA')",
      "NOT EXISTS (SELECT 1 FROM fiscal_document_events d WHERE d.company_id=fiscal_documents.company_id AND d.fiscal_document_id=fiscal_documents.id AND d.event_type='DESCARTE_REJEICAO_LOCAL')",
      'COALESCE(issue_datetime,created_at)>=?','COALESCE(issue_datetime,created_at)<?',
    ];
    $params=[$companyId,$ini . ' 00:00:00',date('Y-m-d 00:00:00',strtotime($fim . ' +1 day'))];
    if (in_array($model,['55','65'],true)) { $where[]='model=?'; $params[]=$model; }
    if ($series !== '') { $where[]="COALESCE(series,'')=?"; $params[]=$series; }
    try {
      $st=$pdo->prepare("SELECT DISTINCT model,COALESCE(series,'') AS series FROM fiscal_documents WHERE " . implode(' AND ',$where));
      $st->execute($params); $register($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {}

    try {
      if (fiscal_table_exists($pdo,'fiscal_xml_imports')) {
        $xmlWhere=[
          'company_id=?',"model IN ('55','65')","COALESCE(NULLIF(TRIM(number),''),'0')<>'0'",
          'COALESCE(include_fiscal_reports,1)=1','COALESCE(entry_date,issue_date,DATE(created_at))>=?',
          'COALESCE(entry_date,issue_date,DATE(created_at))<=?',
        ];
        $xmlParams=[$companyId,$ini,$fim];
        if (in_array($model,['55','65'],true)) { $xmlWhere[]='model=?'; $xmlParams[]=$model; }
        if ($series !== '') { $xmlWhere[]="COALESCE(series,'')=?"; $xmlParams[]=$series; }
        $st=$pdo->prepare("SELECT DISTINCT model,COALESCE(series,'') AS series FROM fiscal_xml_imports WHERE " . implode(' AND ',$xmlWhere));
        $st->execute($xmlParams); $register($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
      }
    } catch (Throwable $e) {}

    // A NF-e descartada nao participa de fiscal_documents para fins contabeis.
    // Isso NAO pode ocultar o XML de inutilizacao homologada: adiciona os
    // grupos de modelo/serie mesmo que nao exista nenhuma nota ativa na serie.
    // Usa a tabela fiscal_inutilizations, que e independente do descarte.
    $inutilRows = accounting_closure_inutilizations($pdo,$companyId,$ini,$fim,$model,$series);
    $inutGroups=[];
    foreach ($inutilRows as $inut) {
      $m=(string)$inut['model'];
      $sr=(string)$inut['series'];
      $inutGroups[$m . '|' . $sr]=['model'=>$m,'series'=>$sr];
    }
    $register(array_values($inutGroups));

    if (!$groups) $groups[(in_array($model,['55','65'],true)?$model:'') . '|' . $series]=['model'=>in_array($model,['55','65'],true)?$model:'','series'=>$series];
    uasort($groups,static function(array $a,array $b): int {
      $cmp=strnatcasecmp((string)$a['model'],(string)$b['model']);
      return $cmp!==0?$cmp:strnatcasecmp((string)$a['series'],(string)$b['series']);
    });
    return array_values($groups);
  }
}

if (!function_exists('accounting_series_file_key')) {
  function accounting_series_file_key(string $model, string $series): string {
    $type = $model === '55' ? 'NFE' : ($model === '65' ? 'NFCE' : 'FISCAL');
    return $type . '_SERIE_' . accounting_safe_part($series !== '' ? $series : 'SEM_SERIE','SEM_SERIE');
  }
}

/** Cria registro para acompanhamento antes de iniciar processo demorado. */
if (!function_exists('accounting_reserve_closure')) {
  function accounting_reserve_closure(PDO $pdo, int $companyId, string $ini, string $fim, string $model, string $series, string $notes, ?int $userId, bool $includeInventory): int {
    accounting_ensure_schema($pdo);
    $ini=accounting_validate_date($ini);
    $fim=accounting_validate_date($fim);
    if ($ini>$fim) throw new RuntimeException('A data inicial não pode ser maior que a final.');
    if (!in_array($model,['','55','65'],true)) throw new RuntimeException('Modelo fiscal inválido.');
    $st=$pdo->prepare("INSERT INTO accounting_closures (company_id,period_start,period_end,document_model,series,include_inventory,status,notes,generated_by_user_id,created_at,updated_at)"
      . " VALUES (?,?,?,?,?,?,'AGUARDANDO',?,?,NOW(),NOW())");
    $st->execute([$companyId,$ini,$fim,$model,trim($series),$includeInventory?1:0,trim($notes),$userId]);
    return (int)$pdo->lastInsertId();
  }
}

/** Somente mensagem de andamento; não interfere nos dados fiscais. */
if (!function_exists('accounting_closure_stage')) {
  function accounting_closure_stage(PDO $pdo, int $companyId, int $closureId, string $description): void {
    $st=$pdo->prepare("UPDATE accounting_closures SET error_message=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='PROCESSANDO'");
    $st->execute([$description,$companyId,$closureId]);
  }
}

if (!function_exists('accounting_generate_closure')) {
  function accounting_generate_closure(PDO $pdo, int $companyId, string $ini, string $fim, string $model = '', string $series = '', string $notes = '', ?int $userId = null, array $options = []): int {
    accounting_ensure_schema($pdo);
    $ini = accounting_validate_date($ini);
    $fim = accounting_validate_date($fim);
    if ($ini > $fim) throw new RuntimeException('A data inicial não pode ser maior que a data final.');
    if (!in_array($model, ['', '55', '65'], true)) $model = '';
    $series = trim($series);
    $includeInventory = !empty($options['include_inventory']);

    $reservedId = (int)($options['reserved_closure_id'] ?? 0);
    if ($reservedId > 0) {
      // A atualização atômica impede execução duplicada do mesmo fechamento.
      $st = $pdo->prepare("UPDATE accounting_closures SET status='PROCESSANDO',error_message='Preparando fechamento...',updated_at=NOW()"
        . " WHERE id=? AND company_id=? AND status='AGUARDANDO' AND generated_by_user_id <=> ?"
        . " AND period_start=? AND period_end=? AND document_model=? AND series=?");
      $st->execute([$reservedId,$companyId,$userId,$ini,$fim,$model,$series]);
      if ($st->rowCount() !== 1) throw new RuntimeException('Fechamento já iniciado, concluído ou sem permissão.');
      $closureId = $reservedId;
    } else {
      // Chamadas antigas seguem produzindo exatamente o mesmo fechamento.
      $st = $pdo->prepare("INSERT INTO accounting_closures (company_id,period_start,period_end,document_model,series,include_inventory,status,notes,generated_by_user_id,created_at)
        VALUES (?,?,?,?,?,?,'PROCESSANDO',?,?,NOW())");
      $st->execute([$companyId,$ini,$fim,$model,$series,$includeInventory?1:0,trim($notes),$userId]);
      $closureId = (int)$pdo->lastInsertId();
    }
    $inventoryId = null;

    try {
      $periodKey = str_replace('-', '', $ini) . '_' . str_replace('-', '', $fim);
      $docs = accounting_collect_documents($pdo,$companyId,$ini,$fim,$model,$series);
      $documentsCount = count($docs);
      $docsTotal = 0.0;
      foreach ($docs as $doc) {
        $status = strtoupper((string)($doc['status'] ?? ''));
        if (!in_array($status, ['CANCELADA','CANCELADO','INUTILIZADA','INUTILIZADO'], true)) $docsTotal += (float)($doc['total_document'] ?? 0);
      }
      unset($docs);

      $artifacts = [];
      $xmlFilesTotal = 0;
      accounting_closure_stage($pdo,$companyId,$closureId,'Separando documentos por modelo e série...');
      $seriesGroups = accounting_closure_series_groups($pdo,$companyId,$ini,$fim,$model,$series);
      foreach ($seriesGroups as $group) {
        $groupModel=(string)$group['model'];
        $groupSeries=(string)$group['series'];
        $fileKey=accounting_series_file_key($groupModel,$groupSeries);
        $label=accounting_model_label($groupModel) . ' - Série ' . ($groupSeries !== '' ? $groupSeries : 'sem série');
        accounting_closure_stage($pdo,$companyId,$closureId,'Gerando PDFs: ' . $label . '...');
        $reportData=fiscal_doc_report_build($pdo,$companyId,$ini,$fim,$groupModel,$groupSeries);

        $artifacts[] = accounting_write_artifact($pdo,$closureId,$companyId,'REL_DOCUMENTOS','Relação de documentos fiscais - ' . $label,
          'RELACAO_DOCUMENTOS_' . $fileKey . '_' . $periodKey . '.pdf',fiscal_pdf_documents($reportData),'application/pdf');
        $artifacts[] = accounting_write_artifact($pdo,$closureId,$companyId,'REL_CFOP','Documentos agrupados por CFOP - ' . $label,
          'DOCUMENTOS_CFOP_' . $fileKey . '_' . $periodKey . '.pdf',fiscal_pdf_cfop($reportData),'application/pdf');
        $artifacts[] = accounting_write_artifact($pdo,$closureId,$companyId,'REL_CFOP_PAGAMENTO','CFOP por forma de pagamento - ' . $label,
          'CFOP_PAGAMENTO_' . $fileKey . '_' . $periodKey . '.pdf',fiscal_pdf_cfop_payment($reportData),'application/pdf');
        unset($reportData);

        // Gera o ZIP diretamente no disco, lendo um XML por vez.
        // Isso evita manter todos os XMLs da empresa simultaneamente na memória do PHP.
        accounting_closure_stage($pdo,$companyId,$closureId,'Gerando ZIP de XML: ' . $label . '...');
        $xmlOptions = ['types'=>accounting_xml_types($groupModel),'ini'=>$ini,'fim'=>$fim,'series'=>$groupSeries];
        $xmlPath = accounting_closure_dir($companyId,$closureId) . '/XML_' . $fileKey . '_' . $periodKey . '.zip';
        $xmlStats = fiscal_xml_export_stream_to_zip($pdo,$companyId,$xmlOptions,$xmlPath,[
          'empty_message'=>"Nenhum XML foi localizado.\r\nPeríodo: {$ini} a {$fim}\r\nTipo/série: {$label}\r\n",
        ]);
        $artifacts[] = accounting_register_file($pdo,$closureId,$companyId,'XML','XMLs fiscais - ' . $label,$xmlPath,'application/zip');
        $xmlFilesTotal += (int)($xmlStats['files'] ?? 0);
        unset($xmlStats);
      }

      // O SINTEGRA é um arquivo fiscal único para todo o período do fechamento.
      // Os relatórios em PDF e os XMLs continuam separados por modelo e série.
      $sintegraOptions = [
        'include_nfce' => $model === '55' ? '0' : '1',
        'finalidade' => '1',
        'natureza' => '3',
        'estrutura' => '3',
        'model' => $model,
        'series' => $series,
      ];
      accounting_closure_stage($pdo,$companyId,$closureId,'Gerando SINTEGRA...');
      $sintegra = fiscal_sintegra_generate_content($pdo,$companyId,$ini,$fim,$sintegraOptions);
      $artifacts[] = accounting_write_artifact(
        $pdo,
        $closureId,
        $companyId,
        'SINTEGRA',
        'SINTEGRA único do período',
        'SINTEGRA_' . $periodKey . '.txt',
        $sintegra,
        'text/plain; charset=ISO-8859-1'
      );

      $inventoryCount = 0;
      if ($includeInventory) {
        $inventoryOptions = [
          'inventory_date' => (string)($options['inventory_date'] ?? inventory_month_end($fim)),
          'stock_filter' => (string)($options['inventory_stock_filter'] ?? 'positivos'),
          'cost_method' => (string)($options['inventory_cost_method'] ?? 'average'),
          'book_number' => (int)($options['inventory_book_number'] ?? 1),
          'initial_page' => (int)($options['inventory_initial_page'] ?? 1),
          'excluded_groups' => (array)($options['inventory_excluded_groups'] ?? []),
          'notes' => 'Gerado no fechamento contábil #' . $closureId,
        ];
        accounting_closure_stage($pdo,$companyId,$closureId,'Gerando inventário...');
        $inventoryId = inventory_generate($pdo,$companyId,$inventoryOptions,$userId);
        $inventory = inventory_get($pdo,$companyId,$inventoryId);
        $inventoryCount = (int)($inventory['item_count'] ?? 0);
        $inventoryPdf = inventory_pdf_content($pdo,$companyId,$inventoryId);
        $invKey = str_replace('-','',(string)$inventory['inventory_date']);
        $artifacts[] = accounting_write_artifact($pdo,$closureId,$companyId,'INVENTARIO','Inventário de estoque em ' . date('d/m/Y',strtotime((string)$inventory['inventory_date'])),
          'INVENTARIO_' . $invKey . '.pdf',$inventoryPdf,'application/pdf');
      }

      $companyName = accounting_company_name($pdo,$companyId);
      $summary = "FECHAMENTO CONTÁBIL E FISCAL\r\n";
      $summary .= "Empresa: {$companyName}\r\n";
      $summary .= "Período: " . date('d/m/Y',strtotime($ini)) . ' a ' . date('d/m/Y',strtotime($fim)) . "\r\n";
      $summary .= "Filtro: " . accounting_model_label($model) . ' / Série ' . ($series !== '' ? $series : 'todas') . "\r\n";
      $summary .= "Relatórios e XMLs separados por modelo/série: " . count($seriesGroups) . " grupo(s)\r\n";
      $summary .= "SINTEGRA único do período: sim\r\n";
      foreach ($seriesGroups as $group) $summary .= " - " . accounting_model_label((string)$group['model']) . ' / Série ' . ((string)$group['series'] !== '' ? (string)$group['series'] : 'sem série') . "\r\n";
      $summary .= "Documentos localizados: " . $documentsCount . "\r\n";
      $summary .= "Total dos documentos: R$ " . number_format($docsTotal,2,',','.') . "\r\n";
      $summary .= "Arquivos XML: {$xmlFilesTotal}\r\n";
      // Resume inutilizacoes como eventos fiscais autonomos; nao contabiliza
      // a NF-e descartada como documento emitido nem exporta seu XML.
      $summary .= accounting_closure_inutilizations_summary(
        accounting_closure_inutilizations($pdo,$companyId,$ini,$fim,$model,$series)
      );
      $summary .= $includeInventory ? "Inventário incluído: sim ({$inventoryCount} itens)\r\n" : "Inventário incluído: não\r\n";
      if (trim($notes) !== '') $summary .= "\r\nObservações da empresa:\r\n" . trim($notes) . "\r\n";
      $artifacts[] = accounting_write_artifact($pdo,$closureId,$companyId,'RESUMO','Resumo do fechamento','RESUMO_' . $periodKey . '.txt',$summary,'text/plain; charset=UTF-8');

      $packageFiles = [];
      foreach ($artifacts as $artifact) {
        $path = (string)($artifact['absolute_path'] ?? '');
        if ($path !== '' && is_file($path)) $packageFiles[(string)$artifact['file_name']] = $path;
      }
      $packagePath = accounting_closure_dir($companyId,$closureId) . '/PACOTE_CONTABIL_' . $periodKey . '.zip';
      accounting_closure_stage($pdo,$companyId,$closureId,'Montando pacote final...');
      fiscal_xml_export_build_zip_from_files($packageFiles,$packagePath);
      accounting_register_file($pdo,$closureId,$companyId,'PACOTE','Pacote contábil completo',$packagePath,'application/zip');
      unset($packageFiles,$artifacts);

      $upd = $pdo->prepare("UPDATE accounting_closures SET status='CONCLUIDO',error_message=NULL,documents_count=?,documents_total=?,xml_files_count=?,inventory_items_count=?,inventory_id=?,generated_at=NOW(),updated_at=NOW() WHERE id=? AND company_id=?");
      $upd->execute([$documentsCount,round($docsTotal,2),$xmlFilesTotal,$inventoryCount,$inventoryId,$closureId,$companyId]);
      return $closureId;
    } catch (Throwable $e) {
      if ($inventoryId !== null) {
        try { inventory_delete($pdo,$companyId,(int)$inventoryId); } catch (Throwable $ignored) {}
      }
      try {
        $upd = $pdo->prepare("UPDATE accounting_closures SET status='ERRO',error_message=?,updated_at=NOW() WHERE id=? AND company_id=?");
        $message=$e->getMessage(); if (function_exists('mb_substr')) $message=mb_substr($message,0,4000,'UTF-8'); else $message=substr($message,0,4000);
        $upd->execute([$message,$closureId,$companyId]);
      } catch (Throwable $ignored) {}
      throw $e;
    }
  }
}

if (!function_exists('accounting_closures')) {
  function accounting_closures(PDO $pdo, int $companyId, int $limit = 50): array {
    accounting_ensure_schema($pdo);
    $limit = max(1,min(200,$limit));
    $st = $pdo->prepare("SELECT ac.*,(SELECT COUNT(*) FROM accounting_closure_files f WHERE f.closure_id=ac.id AND f.company_id=ac.company_id) AS files_count FROM accounting_closures ac WHERE ac.company_id=? ORDER BY ac.created_at DESC,ac.id DESC LIMIT {$limit}");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_files_by_closure')) {
  function accounting_files_by_closure(PDO $pdo, int $companyId, array $closureIds): array {
    $closureIds = array_values(array_unique(array_filter(array_map('intval',$closureIds),static fn(int $id): bool=>$id>0)));
    if (!$closureIds) return [];
    $ph = implode(',',array_fill(0,count($closureIds),'?'));
    $st = $pdo->prepare("SELECT * FROM accounting_closure_files WHERE company_id=? AND closure_id IN ({$ph}) ORDER BY closure_id,CASE file_type WHEN 'PACOTE' THEN 0 WHEN 'RESUMO' THEN 1 WHEN 'REL_DOCUMENTOS' THEN 2 WHEN 'REL_CFOP' THEN 3 WHEN 'REL_CFOP_PAGAMENTO' THEN 4 WHEN 'SINTEGRA' THEN 5 WHEN 'XML' THEN 6 WHEN 'INVENTARIO' THEN 7 ELSE 9 END,file_name,id");
    $st->execute(array_merge([$companyId],$closureIds));
    $out=[];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $out[(int)$row['closure_id']][]=$row;
    return $out;
  }
}

if (!function_exists('accounting_file_absolute_path')) {
  function accounting_file_absolute_path(string $relative): string {
    $root = realpath(accounting_storage_root());
    $candidate = accounting_storage_root() . '/' . ltrim(str_replace('\\','/',$relative),'/');
    $real = realpath($candidate);
    if (!$root || !$real || !is_file($real) || !str_starts_with($real,$root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Arquivo não encontrado.');
    return $real;
  }
}

if (!function_exists('accounting_get_company_file')) {
  function accounting_get_company_file(PDO $pdo, int $companyId, int $fileId): ?array {
    $st=$pdo->prepare('SELECT f.* FROM accounting_closure_files f JOIN accounting_closures c ON c.id=f.closure_id AND c.company_id=f.company_id WHERE f.id=? AND f.company_id=? LIMIT 1');
    $st->execute([$fileId,$companyId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('accounting_get_accountant_file')) {
  function accounting_get_accountant_file(PDO $pdo, int $accountantId, int $fileId): ?array {
    $st=$pdo->prepare("SELECT f.* FROM accounting_closure_files f JOIN accounting_closures c ON c.id=f.closure_id AND c.company_id=f.company_id JOIN accountant_companies ac ON ac.company_id=f.company_id AND ac.accountant_id=? AND ac.is_active=1 WHERE f.id=? AND c.status='CONCLUIDO' LIMIT 1");
    $st->execute([$accountantId,$fileId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('accounting_delete_closure')) {
  function accounting_delete_closure(PDO $pdo, int $companyId, int $closureId): void {
    $st=$pdo->prepare('SELECT relative_path FROM accounting_closure_files WHERE company_id=? AND closure_id=?');
    $st->execute([$companyId,$closureId]);
    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $relative) {
      try { $path=accounting_file_absolute_path((string)$relative); @unlink($path); } catch (Throwable $e) {}
    }
    $pdo->prepare('DELETE FROM accounting_closure_files WHERE company_id=? AND closure_id=?')->execute([$companyId,$closureId]);
    $pdo->prepare('DELETE FROM accounting_closures WHERE company_id=? AND id=?')->execute([$companyId,$closureId]);
    $dir=accounting_storage_root() . '/empresa_' . $companyId . '/fechamento_' . $closureId;
    if (is_dir($dir)) @rmdir($dir);
  }
}


if (!function_exists('accounting_digits')) {
  function accounting_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?: '';
  }
}

if (!function_exists('accounting_valid_cnpj')) {
  function accounting_valid_cnpj(string $value): bool {
    $cnpj = accounting_digits($value);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
    $calc = static function(string $base, array $weights): int {
      $sum = 0;
      foreach ($weights as $i => $weight) $sum += ((int)$base[$i]) * $weight;
      $rest = $sum % 11;
      return $rest < 2 ? 0 : 11 - $rest;
    };
    $d1 = $calc(substr($cnpj, 0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
    $d2 = $calc(substr($cnpj, 0, 12) . $d1, [6,5,4,3,2,9,8,7,6,5,4,3,2]);
    return $cnpj[12] === (string)$d1 && $cnpj[13] === (string)$d2;
  }
}

if (!function_exists('accounting_find_company_by_cnpj')) {
  function accounting_find_company_by_cnpj(PDO $pdo, string $cnpj): ?array {
    accounting_ensure_schema($pdo);
    $cnpj = accounting_digits($cnpj);
    if (strlen($cnpj) !== 14) return null;
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare("SELECT c.id,c.name,c.slug,c.status,fc.company_cnpj,fc.company_name,fc.company_trade_name
        FROM companies c
        JOIN fiscal_company_config fc ON fc.company_id=c.id
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(fc.company_cnpj,''),'.',''),'/',''),'-',''),' ','')=?
          AND COALESCE(c.status,'active')='active'
        LIMIT 1");
      $st->execute([$cnpj]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$row) return null;
      $display = trim((string)($row['company_trade_name'] ?? ''));
      if ($display === '') $display = trim((string)($row['company_name'] ?? ''));
      if ($display === '') $display = trim((string)($row['name'] ?? ''));
      $row['display_name'] = $display !== '' ? $display : ('Empresa ' . (int)$row['id']);
      return $row;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('accounting_request_access')) {
  function accounting_request_access(PDO $pdo, int $accountantId, string $cnpj, string $message = ''): array {
    accounting_ensure_schema($pdo);
    if ($accountantId <= 0) throw new RuntimeException('Usuário do contador inválido.');
    $cnpj = accounting_digits($cnpj);
    if (!accounting_valid_cnpj($cnpj)) throw new RuntimeException('Informe um CNPJ válido com 14 dígitos.');
    $company = accounting_find_company_by_cnpj($pdo, $cnpj);
    if (!$company) throw new RuntimeException('Não localizamos uma empresa ativa com esse CNPJ. Confirme se o CNPJ está cadastrado nas configurações fiscais da empresa.');
    $companyId = (int)$company['id'];
    $st = $pdo->prepare('SELECT is_active FROM accountant_companies WHERE accountant_id=? AND company_id=? LIMIT 1');
    $st->execute([$accountantId, $companyId]);
    $linked = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($linked && (int)$linked['is_active'] === 1) throw new RuntimeException('Você já possui acesso ativo a esta empresa.');
    $message = trim($message);
    if (function_exists('mb_substr')) $message = mb_substr($message, 0, 500, 'UTF-8'); else $message = substr($message, 0, 500);
    $pdo->prepare("INSERT INTO accountant_company_requests
      (accountant_id,company_id,requested_cnpj,status,request_message,requested_at,created_at)
      VALUES (?,?,?,'PENDENTE',?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE requested_cnpj=VALUES(requested_cnpj),status='PENDENTE',request_message=VALUES(request_message),
        review_note=NULL,requested_at=NOW(),reviewed_at=NULL,reviewed_by_user_id=NULL,updated_at=NOW()")
      ->execute([$accountantId, $companyId, $cnpj, $message]);
    return $company;
  }
}

if (!function_exists('accounting_accountant_requests')) {
  function accounting_accountant_requests(PDO $pdo, int $accountantId): array {
    accounting_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT r.*,c.name AS company_name,c.slug AS company_slug,c.name AS company_display_name
      FROM accountant_company_requests r
      JOIN companies c ON c.id=r.company_id
      WHERE r.accountant_id=? ORDER BY r.requested_at DESC,r.id DESC");
    $st->execute([$accountantId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_company_requests')) {
  function accounting_company_requests(PDO $pdo, int $companyId, string $status = ''): array {
    accounting_ensure_schema($pdo);
    $where = 'r.company_id=?'; $params = [$companyId];
    $status = strtoupper(trim($status));
    if ($status !== '') { $where .= ' AND r.status=?'; $params[] = $status; }
    $st = $pdo->prepare("SELECT r.*,a.name AS accountant_name,a.email AS accountant_email,a.document AS accountant_document,a.phone AS accountant_phone
      FROM accountant_company_requests r JOIN accountants a ON a.id=r.accountant_id
      WHERE {$where} ORDER BY CASE r.status WHEN 'PENDENTE' THEN 0 WHEN 'APROVADO' THEN 1 ELSE 2 END,r.requested_at DESC,r.id DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_review_request')) {
  function accounting_review_request(PDO $pdo, int $companyId, int $requestId, bool $approve, ?int $userId = null, string $note = ''): array {
    accounting_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT r.*,a.name AS accountant_name,a.email AS accountant_email FROM accountant_company_requests r JOIN accountants a ON a.id=r.accountant_id WHERE r.id=? AND r.company_id=? LIMIT 1");
    $st->execute([$requestId, $companyId]);
    $request = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$request) throw new RuntimeException('Solicitação de acesso não encontrada.');
    $accountantId = (int)$request['accountant_id'];
    $note = trim($note);
    if (function_exists('mb_substr')) $note = mb_substr($note, 0, 500, 'UTF-8'); else $note = substr($note, 0, 500);
    $pdo->beginTransaction();
    try {
      if ($approve) {
        $pdo->prepare("INSERT INTO accountant_companies (accountant_id,company_id,is_active,created_by_user_id,created_at)
          VALUES (?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE is_active=1,created_by_user_id=VALUES(created_by_user_id),updated_at=NOW()")
          ->execute([$accountantId, $companyId, $userId]);
      }
      $pdo->prepare("UPDATE accountant_company_requests SET status=?,review_note=?,reviewed_at=NOW(),reviewed_by_user_id=?,updated_at=NOW() WHERE id=? AND company_id=?")
        ->execute([$approve ? 'APROVADO' : 'NEGADO', $note, $userId, $requestId, $companyId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return $request;
  }
}

if (!function_exists('accounting_linked_companies')) {
  function accounting_linked_companies(PDO $pdo, int $accountantId): array {
    accounting_ensure_schema($pdo);
    $st=$pdo->prepare("SELECT c.id,c.name,c.slug,c.status FROM accountant_companies ac JOIN companies c ON c.id=ac.company_id WHERE ac.accountant_id=? AND ac.is_active=1 AND COALESCE(c.status,'active')='active' ORDER BY c.name");
    $st->execute([$accountantId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('accounting_human_size')) {
  function accounting_human_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes/1024,1,',','.') . ' KB';
    if ($bytes < 1073741824) return number_format($bytes/1048576,1,',','.') . ' MB';
    return number_format($bytes/1073741824,1,',','.') . ' GB';
  }
}

if (!function_exists('accounting_csrf_token')) {
  function accounting_csrf_token(string $scope='accounting'): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $key='csrf_' . preg_replace('/[^a-z0-9_]/i','',$scope);
    if (empty($_SESSION[$key])) $_SESSION[$key]=bin2hex(random_bytes(24));
    return (string)$_SESSION[$key];
  }
}

if (!function_exists('accounting_csrf_check')) {
  function accounting_csrf_check(string $token,string $scope='accounting'): void {
    $expected=accounting_csrf_token($scope);
    if ($token==='' || !hash_equals($expected,$token)) throw new RuntimeException('A sessão expirou. Atualize a página e tente novamente.');
  }
}
