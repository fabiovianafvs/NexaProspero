<?php
/**
 * Nexa Próspero - helpers do módulo Orçamento.
 */

declare(strict_types=1);

require_once __DIR__ . '/schema_runtime.php';
require_once __DIR__ . '/commercial_rules.php';

if (!function_exists('orc_db')) {
  function orc_db(): PDO {
    foreach (['pdo','db','conn'] as $name) {
      if (($GLOBALS[$name] ?? null) instanceof PDO) return $GLOBALS[$name];
    }
    throw new RuntimeException('Conexão PDO não encontrada.');
  }
}

if (!function_exists('orc_company_id')) {
  function orc_company_id(): int {
    if (defined('COMPANY_ID') && (int)COMPANY_ID > 0) return (int)COMPANY_ID;
    if (function_exists('auth_company_id')) return (int)auth_company_id();
    return (int)($_SESSION['user_company_id'] ?? $_SESSION['company_id'] ?? 0);
  }
}

if (!function_exists('orc_user_id')) {
  function orc_user_id(): ?int {
    $id = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
    return $id > 0 ? $id : null;
  }
}

if (!function_exists('orc_e')) {
  function orc_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('orc_money')) {
  function orc_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
}
if (!function_exists('orc_decimal')) {
  function orc_decimal($value): float {
    if (is_string($value)) {
      $value = trim($value);
      if (strpos($value, ',') !== false) $value = str_replace(',', '.', str_replace('.', '', $value));
    }
    return round((float)$value, 4);
  }
}

if (!function_exists('orc_module_enabled')) {
  function orc_module_enabled(PDO $pdo, int $companyId): bool {
    return $companyId > 0 && function_exists('saas_company_has_module') && saas_company_has_module($pdo, $companyId, 'orcamento');
  }
}
if (!function_exists('orc_require_enabled')) {
  function orc_require_enabled(PDO $pdo, int $companyId): void {
    if (orc_module_enabled($pdo, $companyId)) return;
    if (function_exists('saas_render_module_block_page')) saas_render_module_block_page('orcamento');
    http_response_code(403); exit('Módulo Orçamento não habilitado.');
  }
}

if (!function_exists('orc_schema_version')) {
  function orc_schema_version(): int { return 2026081402; }
}
if (!function_exists('orc_schema_has_column')) {
  function orc_schema_has_column(PDO $pdo, string $table, string $column): bool {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table,$column]); return (int)$st->fetchColumn()>0;
  }
}
if (!function_exists('orc_schema_ready')) {
  function orc_schema_ready(PDO $pdo): bool {
    return nexa_schema_version_ready($pdo, 'orcamento', orc_schema_version());
  }
}
if (!function_exists('orc_require_schema')) {
  function orc_require_schema(PDO $pdo): void {
    if (orc_schema_ready($pdo)) return;
    http_response_code(503);
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Orçamento</title><style>body{font-family:Arial;background:#f5f7fb;color:#0f172a;padding:30px}.box{max-width:720px;margin:auto;background:#fff;border:1px solid #dbe4ee;border-radius:18px;padding:24px}code{background:#eef2f7;padding:3px 6px;border-radius:6px}</style></head><body><div class="box"><h2>Estrutura do Orçamento ainda não instalada</h2><p>Após copiar os arquivos, execute na VPS:</p><p><code>php /var/www/nexa/workers/migrate_orcamento_v1_1.php</code></p><p>Depois, habilite o módulo Orçamento na Central SaaS para a empresa.</p></div></body></html>';
    exit;
  }
}

if (!function_exists('orc_ensure_schema')) {
  function orc_ensure_schema(PDO $pdo): bool {
    if (orc_schema_ready($pdo)) return true;
    $ddl = [
      "CREATE TABLE IF NOT EXISTS orcamento_settings (
        company_id INT NOT NULL,
        default_reserve_product TINYINT(1) NOT NULL DEFAULT 0,
        allow_out_of_stock_reservation TINYINT(1) NOT NULL DEFAULT 0,
        delivery_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY(company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS orcamento_sequences (
        company_id INT NOT NULL,
        last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY(company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS orcamentos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        quote_number BIGINT UNSIGNED NOT NULL,
        quote_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
        person_id INT NULL,
        person_name_snapshot VARCHAR(180) NULL,
        person_document_snapshot VARCHAR(40) NULL,
        person_phone_snapshot VARCHAR(40) NULL,
        seller_person_id INT NULL,
        seller_name_snapshot VARCHAR(180) NULL,
        professional_person_id INT NULL,
        professional_name_snapshot VARCHAR(180) NULL,
        notes TEXT NULL,
        total_products DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_services DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount_mode VARCHAR(10) NOT NULL DEFAULT 'AMOUNT',
        discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        addition_mode VARCHAR(10) NOT NULL DEFAULT 'AMOUNT',
        addition_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        freight_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_general DECIMAL(15,2) NOT NULL DEFAULT 0,
        delivery_date DATE NULL,
        delivery_time TIME NULL,
        carrier_person_id INT NULL,
        carrier_name_snapshot VARCHAR(180) NULL,
        delivery_zip_code VARCHAR(20) NULL,
        delivery_address VARCHAR(180) NULL,
        delivery_number VARCHAR(30) NULL,
        delivery_complement VARCHAR(120) NULL,
        delivery_district VARCHAR(100) NULL,
        delivery_city VARCHAR(100) NULL,
        delivery_state VARCHAR(2) NULL,
        delivery_reference VARCHAR(255) NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY(id),
        UNIQUE KEY uk_orcamento_company_number(company_id,quote_number),
        KEY idx_orcamento_company_date(company_id,quote_date,id),
        KEY idx_orcamento_company_person(company_id,person_id),
        KEY idx_orcamento_company_status(company_id,status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS orcamento_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        quote_id BIGINT UNSIGNED NOT NULL,
        produto_id INT NOT NULL,
        item_type VARCHAR(20) NOT NULL DEFAULT 'PRODUTO',
        description_snapshot VARCHAR(180) NOT NULL,
        unidade_snapshot VARCHAR(20) NULL,
        product_code_snapshot VARCHAR(60) NULL,
        quantity DECIMAL(15,4) NOT NULL DEFAULT 1,
        unit_price DECIMAL(15,4) NOT NULL DEFAULT 0,
        gross_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        addition_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        total DECIMAL(15,2) NOT NULL DEFAULT 0,
        reserve_stock TINYINT(1) NOT NULL DEFAULT 0,
        reserved_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
        reservation_status VARCHAR(20) NOT NULL DEFAULT 'NAO_RESERVADO',
        notes VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY(id),
        KEY idx_orcamento_items_quote(company_id,quote_id,id),
        KEY idx_orcamento_items_product(company_id,produto_id,reservation_status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS finance_payment_methods (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(120) NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'OUTRO',
        default_bank_account_id INT NULL,
        default_chart_account_id INT NULL,
        default_cost_center_id INT NULL,
        generate_receivable TINYINT(1) NOT NULL DEFAULT 0,
        allow_installments TINYINT(1) NOT NULL DEFAULT 0,
        default_installments INT NOT NULL DEFAULT 1,
        first_due_days INT NOT NULL DEFAULT 30,
        keep_due_day TINYINT(1) NOT NULL DEFAULT 1,
        affects_cash TINYINT(1) NOT NULL DEFAULT 1,
        require_open_cash TINYINT(1) NOT NULL DEFAULT 0,
        allow_change TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY(id),
        UNIQUE KEY uk_fin_pay_method_code(company_id,code),
        KEY idx_fin_pay_method_company(company_id,is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS orcamento_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        quote_id BIGINT UNSIGNED NOT NULL,
        payment_method_id INT NULL,
        payment_label VARCHAR(120) NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        due_date DATE NULL,
        notes VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY(id),
        KEY idx_orcamento_payments_quote(company_id,quote_id,id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    try {
      foreach ($ddl as $sql) $pdo->exec($sql);
      if (!orc_schema_has_column($pdo,'finance_people','is_professional')) $pdo->exec("ALTER TABLE finance_people ADD COLUMN is_professional TINYINT(1) NOT NULL DEFAULT 0 AFTER is_employee");
      if (!orc_schema_has_column($pdo,'finance_people','is_carrier')) $pdo->exec("ALTER TABLE finance_people ADD COLUMN is_carrier TINYINT(1) NOT NULL DEFAULT 0 AFTER is_professional");
      if (!orc_schema_has_column($pdo,'orcamento_items','product_code_snapshot')) $pdo->exec("ALTER TABLE orcamento_items ADD COLUMN product_code_snapshot VARCHAR(60) NULL AFTER unidade_snapshot");
      if (!orc_schema_has_column($pdo,'orcamento_items','gross_total')) $pdo->exec("ALTER TABLE orcamento_items ADD COLUMN gross_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER unit_price");
      if (!orc_schema_has_column($pdo,'orcamento_items','addition_percent')) $pdo->exec("ALTER TABLE orcamento_items ADD COLUMN addition_percent DECIMAL(9,4) NOT NULL DEFAULT 0 AFTER gross_total");
      if (!orc_schema_has_column($pdo,'orcamento_items','addition_amount')) $pdo->exec("ALTER TABLE orcamento_items ADD COLUMN addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER addition_percent");
      if (!orc_schema_has_column($pdo,'orcamento_items','discount_percent')) $pdo->exec("ALTER TABLE orcamento_items ADD COLUMN discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0 AFTER addition_amount");
      if (!orc_schema_has_column($pdo,'orcamento_items','discount_amount')) $pdo->exec("ALTER TABLE orcamento_items ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_percent");
      $pdo->exec("UPDATE orcamento_items SET gross_total=total WHERE gross_total=0 AND total<>0");
      try {
        if (orc_schema_has_column($pdo,'produtos','numero_cadastro')) {
          $legacyExpr=orc_schema_has_column($pdo,'produtos','codigo_firebird') ? "NULLIF(TRIM(p.codigo_firebird),'')" : 'NULL';
          $pdo->exec("UPDATE orcamento_items oi JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id SET oi.product_code_snapshot=COALESCE(NULLIF(p.numero_cadastro,0),{$legacyExpr},p.id) WHERE oi.product_code_snapshot IS NULL OR oi.product_code_snapshot=''");
        } elseif (orc_schema_has_column($pdo,'produtos','codigo_firebird')) {
          $pdo->exec("UPDATE orcamento_items oi JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id SET oi.product_code_snapshot=COALESCE(NULLIF(TRIM(p.codigo_firebird),''),CAST(p.id AS CHAR)) WHERE oi.product_code_snapshot IS NULL OR oi.product_code_snapshot=''");
        } else {
          $pdo->exec("UPDATE orcamento_items SET product_code_snapshot=CAST(produto_id AS CHAR) WHERE product_code_snapshot IS NULL OR product_code_snapshot=''");
        }
      } catch (Throwable $e) {
        // O código é apenas snapshot visual; não impede a migração caso uma base antiga não possua a coluna no produto.
      }
      nexa_schema_version_mark($pdo, 'orcamento', orc_schema_version(), 'Orçamento v1.1 - cliente avulso e acréscimos/descontos por item');
      return orc_schema_ready($pdo);
    } catch (Throwable $e) {
      throw new RuntimeException('Não foi possível preparar o banco do Orçamento: '.$e->getMessage(),0,$e);
    }
  }
}

if (!function_exists('orc_ensure_defaults')) {
  function orc_ensure_defaults(PDO $pdo, int $companyId): void {
    $pdo->prepare('INSERT IGNORE INTO orcamento_settings(company_id) VALUES (?)')->execute([$companyId]);
    $pdo->prepare('INSERT IGNORE INTO orcamento_sequences(company_id,last_number) VALUES (?,0)')->execute([$companyId]);

    // O Orçamento pode ser contratado sem o módulo Gestão. Se a empresa ainda
    // não possui formas de pagamento, entrega um conjunto básico compatível
    // com a tabela compartilhada usada pelo restante do Nexa.
    try {
      $st=$pdo->prepare('SELECT COUNT(*) FROM finance_payment_methods WHERE company_id=?');
      $st->execute([$companyId]);
      if((int)$st->fetchColumn()===0){
        $ins=$pdo->prepare('INSERT INTO finance_payment_methods (company_id,code,name,type,generate_receivable,allow_installments,default_installments,first_due_days,keep_due_day,affects_cash,require_open_cash,allow_change,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
        $ins->execute([$companyId,'DINHEIRO','Dinheiro','DINHEIRO',0,0,1,0,1,1,1,1,10]);
        $ins->execute([$companyId,'PIX','Pix','PIX',0,0,1,0,1,1,0,0,20]);
        $ins->execute([$companyId,'CARTAO_DEBITO','Cartão de Débito','CARTAO',0,0,1,0,1,1,0,0,30]);
        $ins->execute([$companyId,'CARTAO_CREDITO','Cartão de Crédito','CARTAO',0,0,1,0,1,1,0,0,40]);
        $ins->execute([$companyId,'A_PRAZO','A Prazo / Conta a receber','PRAZO',1,1,1,30,1,0,0,0,50]);
      }
    } catch(Throwable $e) {
      // A migração valida a tabela. Em bases antigas, não bloqueia o acesso
      // às demais configurações caso a tabela compartilhada precise de ajuste.
    }
  }
}
if (!function_exists('orc_settings')) {
  function orc_settings(PDO $pdo, int $companyId): array {
    orc_ensure_defaults($pdo,$companyId);
    $st=$pdo->prepare('SELECT * FROM orcamento_settings WHERE company_id=? LIMIT 1'); $st->execute([$companyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}
if (!function_exists('orc_next_number')) {
  function orc_next_number(PDO $pdo, int $companyId): int {
    $pdo->prepare('INSERT IGNORE INTO orcamento_sequences(company_id,last_number) VALUES (?,0)')->execute([$companyId]);
    $st=$pdo->prepare('SELECT last_number FROM orcamento_sequences WHERE company_id=? FOR UPDATE'); $st->execute([$companyId]);
    $next=(int)$st->fetchColumn()+1;
    $pdo->prepare('UPDATE orcamento_sequences SET last_number=? WHERE company_id=?')->execute([$next,$companyId]);
    return $next;
  }
}

if (!function_exists('orc_statuses')) {
  function orc_statuses(): array { return ['ABERTO'=>'Aberto','APROVADO'=>'Aprovado','FATURADO'=>'Faturado','RECUSADO'=>'Recusado','CANCELADO'=>'Cancelado']; }
}
if (!function_exists('orc_status_label')) {
  function orc_status_label(string $status): string { $all=orc_statuses(); return $all[$status] ?? $status; }
}
if (!function_exists('orc_status_class')) {
  function orc_status_class(string $status): string {
    return match($status){'APROVADO','FATURADO'=>'green','RECUSADO','CANCELADO'=>'red',default=>'blue'};
  }
}

if (!function_exists('orc_status_color')) {
  function orc_status_color(string $status): string {
    return match(strtoupper(trim($status))){
      'APROVADO'=>'#16a34a',
      'FATURADO'=>'#15803d',
      'RECUSADO'=>'#dc2626',
      'CANCELADO'=>'#64748b',
      default=>'#2563eb',
    };
  }
}

if (!function_exists('orc_role_snapshot')) {
  function orc_role_snapshot(PDO $pdo, int $companyId, int $personId, string $role, bool $required=false): array {
    if ($personId<=0) {
      if ($required) throw new RuntimeException('Selecione a pessoa obrigatória.');
      return [null,null,null,null,null];
    }
    $roleCol = match($role){'customer'=>'is_customer','seller'=>'is_seller','professional'=>'is_professional','carrier'=>'is_carrier',default=>''};
    if ($roleCol==='') throw new RuntimeException('Papel de pessoa inválido.');
    $st=$pdo->prepare("SELECT id,name,document,COALESCE(NULLIF(whatsapp,''),phone) phone,zip_code,address,number,complement,district,city,state FROM finance_people WHERE company_id=? AND id=? AND active=1 AND COALESCE($roleCol,0)=1 LIMIT 1");
    $st->execute([$companyId,$personId]); $r=$st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('Pessoa selecionada não está ativa ou não possui a classificação necessária.');
    return [(int)$r['id'],$r['name'],$r['document'],$r['phone'],$r];
  }
}
if (!function_exists('orc_role_people')) {
  function orc_role_people(PDO $pdo, int $companyId, string $role): array {
    $roleCol=match($role){'seller'=>'is_seller','professional'=>'is_professional','carrier'=>'is_carrier',default=>'is_customer'};
    $st=$pdo->prepare("SELECT id,name,document,phone,whatsapp FROM finance_people WHERE company_id=? AND active=1 AND COALESCE($roleCol,0)=1 ORDER BY name,id");
    $st->execute([$companyId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('orc_fetch')) {
  function orc_fetch(PDO $pdo, int $companyId, int $id): ?array {
    $st=$pdo->prepare('SELECT * FROM orcamentos WHERE company_id=? AND id=? LIMIT 1'); $st->execute([$companyId,$id]);
    $r=$st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
  }
}
if (!function_exists('orc_quote_adjustment_columns_ready')) {
  function orc_quote_adjustment_columns_ready(PDO $pdo): bool {
    return orc_schema_has_column($pdo,'orcamentos','discount_mode')
      && orc_schema_has_column($pdo,'orcamentos','discount_percent')
      && orc_schema_has_column($pdo,'orcamentos','discount_amount')
      && orc_schema_has_column($pdo,'orcamentos','addition_mode')
      && orc_schema_has_column($pdo,'orcamentos','addition_percent')
      && orc_schema_has_column($pdo,'orcamentos','addition_amount')
      && orc_schema_has_column($pdo,'orcamentos','freight_amount');
  }
}
if (!function_exists('orc_quote_adjustments')) {
  function orc_quote_adjustments(array $quote, float $subtotal): array {
    $subtotal=max(0.0,round($subtotal,2));
    $discountMode=strtoupper((string)($quote['discount_mode']??'AMOUNT'))==='PERCENT'?'PERCENT':'AMOUNT';
    $additionMode=strtoupper((string)($quote['addition_mode']??'AMOUNT'))==='PERCENT'?'PERCENT':'AMOUNT';
    $discountPct=max(0.0,min(100.0,(float)($quote['discount_percent']??0)));
    $discountAmount=max(0.0,(float)($quote['discount_amount']??0));
    $additionPct=max(0.0,min(100.0,(float)($quote['addition_percent']??0)));
    $additionAmount=max(0.0,(float)($quote['addition_amount']??0));
    $freight=max(0.0,(float)($quote['freight_amount']??0));
    if($discountMode==='PERCENT')$discountAmount=round($subtotal*$discountPct/100,2);
    else $discountPct=$subtotal>0?round($discountAmount*100/$subtotal,4):0.0;
    if($additionMode==='PERCENT')$additionAmount=round($subtotal*$additionPct/100,2);
    else $additionPct=$subtotal>0?round($additionAmount*100/$subtotal,4):0.0;
    $discountAmount=min($discountAmount,max(0.0,$subtotal+$additionAmount+$freight));
    if($discountMode==='AMOUNT')$discountPct=$subtotal>0?round($discountAmount*100/$subtotal,4):0.0;
    $total=round(max(0.0,$subtotal+$additionAmount+$freight-$discountAmount),2);
    return [
      'discount_mode'=>$discountMode,'discount_percent'=>$discountPct,'discount_amount'=>round($discountAmount,2),
      'addition_mode'=>$additionMode,'addition_percent'=>$additionPct,'addition_amount'=>round($additionAmount,2),
      'freight_amount'=>round($freight,2),'total_general'=>$total,
    ];
  }
}
if (!function_exists('orc_recalculate_totals')) {
  function orc_recalculate_totals(PDO $pdo, int $companyId, int $quoteId): array {
    $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN item_type='PRODUTO' THEN total ELSE 0 END),0) products,COALESCE(SUM(CASE WHEN item_type='SERVICO' THEN total ELSE 0 END),0) services FROM orcamento_items WHERE company_id=? AND quote_id=?");
    $st->execute([$companyId,$quoteId]); $r=$st->fetch(PDO::FETCH_ASSOC) ?: ['products'=>0,'services'=>0];
    $products=round((float)$r['products'],2);$services=round((float)$r['services'],2);$subtotal=round($products+$services,2);
    if(orc_quote_adjustment_columns_ready($pdo)){
      $st=$pdo->prepare('SELECT discount_mode,discount_percent,discount_amount,addition_mode,addition_percent,addition_amount,freight_amount FROM orcamentos WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId,$quoteId]);$q=$st->fetch(PDO::FETCH_ASSOC)?:[];$adj=orc_quote_adjustments($q,$subtotal);
      $pdo->prepare('UPDATE orcamentos SET total_products=?,total_services=?,discount_mode=?,discount_percent=?,discount_amount=?,addition_mode=?,addition_percent=?,addition_amount=?,freight_amount=?,total_general=?,updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$products,$services,$adj['discount_mode'],$adj['discount_percent'],$adj['discount_amount'],$adj['addition_mode'],$adj['addition_percent'],$adj['addition_amount'],$adj['freight_amount'],$adj['total_general'],$companyId,$quoteId]);
      return ['products'=>$products,'services'=>$services,'subtotal'=>$subtotal,'general'=>$adj['total_general']]+$adj;
    }
    $pdo->prepare('UPDATE orcamentos SET total_products=?,total_services=?,total_general=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$products,$services,$subtotal,$companyId,$quoteId]);
    return ['products'=>$products,'services'=>$services,'subtotal'=>$subtotal,'discount_percent'=>0.0,'discount_amount'=>0.0,'addition_percent'=>0.0,'addition_amount'=>0.0,'freight_amount'=>0.0,'general'=>$subtotal,'total_general'=>$subtotal];
  }
}
if (!function_exists('orc_validate_discount_limit')) {
  function orc_validate_discount_limit(PDO $pdo,int $companyId,int $userId,int $quoteId): void {
    if($userId<=0||$quoteId<=0||!function_exists('commercial_validate_discount'))return;
    $st=$pdo->prepare('SELECT COALESCE(SUM(gross_total),0) gross,COALESCE(SUM(discount_amount),0) item_discount FROM orcamento_items WHERE company_id=? AND quote_id=?');
    $st->execute([$companyId,$quoteId]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];
    $quoteDiscount=0.0;if(orc_quote_adjustment_columns_ready($pdo)){
      $st=$pdo->prepare('SELECT COALESCE(discount_amount,0) FROM orcamentos WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$quoteId]);$quoteDiscount=(float)($st->fetchColumn()?:0);
    }
    commercial_validate_discount($pdo,$companyId,$userId,max(0.0,(float)($r['gross']??0)),max(0.0,(float)($r['item_discount']??0)+$quoteDiscount));
  }
}
if (!function_exists('orc_reserve_product')) {
  function orc_reserve_product(PDO $pdo, int $companyId, int $productId, float $quantity, bool $allowOutOfStock=false): void {
    if ($quantity<=0) return;
    $st=$pdo->prepare('SELECT estoque_atual,estoque_reservado,is_service FROM produtos WHERE company_id=? AND id=? FOR UPDATE'); $st->execute([$companyId,$productId]);
    $p=$st->fetch(PDO::FETCH_ASSOC); if(!$p) throw new RuntimeException('Produto não encontrado.');
    if((int)$p['is_service']===1) throw new RuntimeException('Serviços não podem reservar estoque.');
    $available=(float)$p['estoque_atual']-(float)$p['estoque_reservado'];
    if(!$allowOutOfStock && $quantity>$available+0.00001) throw new RuntimeException('Estoque disponível insuficiente para a reserva. Disponível: '.number_format($available,4,',','.').'.');
    $pdo->prepare('UPDATE produtos SET estoque_reservado=estoque_reservado+? WHERE company_id=? AND id=?')->execute([$quantity,$companyId,$productId]);
  }
}
if (!function_exists('orc_unreserve_product')) {
  function orc_unreserve_product(PDO $pdo, int $companyId, int $productId, float $quantity): void {
    if($quantity<=0) return;
    $pdo->prepare('UPDATE produtos SET estoque_reservado=GREATEST(0,estoque_reservado-?) WHERE company_id=? AND id=?')->execute([$quantity,$companyId,$productId]);
  }
}
if (!function_exists('orc_release_reservations')) {
  function orc_release_reservations(PDO $pdo, int $companyId, int $quoteId): void {
    $st=$pdo->prepare("SELECT id,produto_id,reserved_quantity FROM orcamento_items WHERE company_id=? AND quote_id=? AND reservation_status='RESERVADO' AND reserved_quantity>0 FOR UPDATE");
    $st->execute([$companyId,$quoteId]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $i){
      orc_unreserve_product($pdo,$companyId,(int)$i['produto_id'],(float)$i['reserved_quantity']);
      $pdo->prepare("UPDATE orcamento_items SET reserved_quantity=0,reservation_status='LIBERADO',updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,(int)$i['id']]);
    }
  }
}
