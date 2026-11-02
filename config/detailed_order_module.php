<?php
declare(strict_types=1);

require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/fiscal_module.php';

if (!function_exists('detailed_order_ensure_schema')) {
  function detailed_order_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    // V11.30: no contexto F11 cruzado, a estrutura global nunca é criada/alterada
    // durante a operação. Apenas confirma que as tabelas já existem.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
      try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)');
        $st->execute(['gestao_order_fiscal_meta', 'gestao_order_item_tax_overrides']);
        if ((int)$st->fetchColumn() < 2) {
          throw new RuntimeException('Estrutura do Pedido Detalhado ainda não está preparada no banco. Acesse diretamente a empresa principal/Atualizador do Banco antes de usar esta função pelo F11.');
        }
        $done = true;
        return;
      } catch (RuntimeException $e) {
        throw $e;
      } catch (Throwable $e) {
        throw new RuntimeException('Não foi possível validar a estrutura do Pedido Detalhado no contexto Multiempresa.');
      }
    }

    $done = true;
    $errors = [];
    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_order_fiscal_meta (
        id BIGINT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        order_id INT NOT NULL,
        default_cfop VARCHAR(4) NULL,
        freight_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        freight_mode VARCHAR(1) NOT NULL DEFAULT '9',
        carrier_person_id INT NULL,
        carrier_name VARCHAR(180) NULL,
        carrier_document VARCHAR(30) NULL,
        carrier_ie VARCHAR(30) NULL,
        carrier_address VARCHAR(180) NULL,
        carrier_city VARCHAR(120) NULL,
        carrier_state VARCHAR(2) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_gofm_order (company_id, order_id),
        KEY idx_gofm_carrier (company_id, carrier_person_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);
    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_order_item_tax_overrides (
        id BIGINT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        order_id INT NOT NULL,
        order_item_id INT NOT NULL,
        product_id INT NULL,
        ncm VARCHAR(12) NULL,
        cest VARCHAR(12) NULL,
        cfop VARCHAR(4) NULL,
        origem VARCHAR(2) NULL,
        icms_cst VARCHAR(5) NULL,
        icms_csosn VARCHAR(5) NULL,
        icms_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        icms_st_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        ipi_cst VARCHAR(5) NULL,
        ipi_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        pis_cst VARCHAR(5) NULL,
        pis_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        cofins_cst VARCHAR(5) NULL,
        cofins_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        ibs_cbs_cst VARCHAR(5) NULL,
        ibs_cbs_classification_code VARCHAR(20) NULL,
        cbs_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        ibs_uf_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        ibs_mun_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_goito_item (company_id, order_item_id),
        KEY idx_goito_order (company_id, order_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);
    if ($errors) {
      throw new RuntimeException('Não foi possível preparar o Pedido Detalhado: ' . implode(' | ', array_unique($errors)));
    }
  }
}

if (!function_exists('detailed_order_clean_digits')) {
  function detailed_order_clean_digits($v, int $max = 20): string {
    return substr(preg_replace('/\D+/', '', (string)$v) ?: '', 0, $max);
  }
}

if (!function_exists('detailed_order_decimal')) {
  function detailed_order_decimal($v, int $decimals = 4): float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    if (str_contains($s, ',')) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }
    $s = preg_replace('/[^0-9.\-]+/', '', $s) ?: '0';
    return round((float)$s, $decimals);
  }
}

if (!function_exists('detailed_order_meta')) {
  function detailed_order_meta(PDO $pdo, int $companyId, int $orderId): array {
    detailed_order_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM gestao_order_fiscal_meta WHERE company_id=? AND order_id=? LIMIT 1');
    $st->execute([$companyId, $orderId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('detailed_order_item_overrides')) {
  function detailed_order_item_overrides(PDO $pdo, int $companyId, int $orderId): array {
    detailed_order_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM gestao_order_item_tax_overrides WHERE company_id=? AND order_id=? ORDER BY order_item_id');
    $st->execute([$companyId, $orderId]);
    $out = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $out[(int)$r['order_item_id']] = $r;
    return $out;
  }
}

if (!function_exists('detailed_order_item_override')) {
  function detailed_order_item_override(PDO $pdo, int $companyId, int $orderId, int $orderItemId): array {
    detailed_order_ensure_schema($pdo);
    if ($orderItemId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM gestao_order_item_tax_overrides WHERE company_id=? AND order_id=? AND order_item_id=? LIMIT 1');
    $st->execute([$companyId, $orderId, $orderItemId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('detailed_order_merge_tax')) {
  function detailed_order_merge_tax(array $base, array $override, string $defaultCfop = ''): array {
    if (!$override && $defaultCfop === '') return $base;
    $map = [
      'ncm'=>'ncm','cest'=>'cest','origem'=>'origem','icms_cst'=>'icms_cst','icms_csosn'=>'icms_csosn',
      'icms_rate'=>'icms_rate','icms_st_rate'=>'icms_st_rate','ipi_cst'=>'ipi_cst','ipi_rate'=>'ipi_rate',
      'pis_cst'=>'pis_cst','pis_rate'=>'pis_rate','cofins_cst'=>'cofins_cst','cofins_rate'=>'cofins_rate',
      'ibs_cbs_cst'=>'ibs_cbs_cst','ibs_cbs_classification_code'=>'ibs_cbs_classification_code',
      'cbs_rate'=>'cbs_rate','ibs_uf_rate'=>'ibs_uf_rate','ibs_mun_rate'=>'ibs_mun_rate'
    ];
    // Um snapshot do Pedido Detalhado pode conter NULLs e aliquotas zero
    // preenchidas pelo DEFAULT do banco. Ausencia de informacao no pedido
    // nao deve apagar a tributacao valida do cadastro fiscal do produto.
    $hasExplicitIbsCbs = false;
    foreach (['ibs_cbs_cst', 'ibs_cbs_classification_code'] as $ibsField) {
      if (isset($override[$ibsField]) && trim((string)$override[$ibsField]) !== '') {
        $hasExplicitIbsCbs = true;
        break;
      }
    }
    foreach ($map as $src => $dst) {
      if (!array_key_exists($src, $override)) continue;
      $v = $override[$src];
      if ($v === null) continue;
      if (is_string($v) && trim($v) === '' && !str_ends_with($src, '_rate')) continue;
      // Sem CST/cClassTrib definidos no pedido, zero nas aliquotas IBS/CBS
      // pode ser apenas o DEFAULT do snapshot, e nao um override intencional.
      if (!$hasExplicitIbsCbs
          && in_array($src, ['cbs_rate', 'ibs_uf_rate', 'ibs_mun_rate'], true)
          && is_numeric($v) && (float)$v === 0.0) continue;
      $base[$dst] = $v;
    }
    $cfop = detailed_order_clean_digits($override['cfop'] ?? '', 4);
    if ($cfop === '') $cfop = detailed_order_clean_digits($defaultCfop, 4);
    if ($cfop !== '') {
      $base['cfop_sale_state'] = $cfop;
      $base['cfop_sale_interstate'] = $cfop;
    }
    return $base;
  }
}

if (!function_exists('detailed_order_save_snapshot')) {
  function detailed_order_save_snapshot(PDO $pdo, int $companyId, int $orderId, array $post): void {
    detailed_order_ensure_schema($pdo);
    $defaultCfop = detailed_order_clean_digits($post['default_cfop'] ?? '', 4);
    $freight = max(0.0, detailed_order_decimal($post['delivery_fee'] ?? $post['freight_amount'] ?? 0, 2));
    $freightMode = trim((string)($post['freight_mode'] ?? '9'));
    if (!in_array($freightMode, ['0','1','2','3','4','9'], true)) $freightMode = '9';
    $carrierPersonId = (int)($post['carrier_person_id'] ?? 0);
    $carrierName = trim((string)($post['carrier_name'] ?? ''));
    $carrierDocument = detailed_order_clean_digits($post['carrier_document'] ?? '', 20);
    $carrierIe = trim((string)($post['carrier_ie'] ?? ''));
    $carrierAddress = trim((string)($post['carrier_address'] ?? ''));
    $carrierCity = trim((string)($post['carrier_city'] ?? ''));
    $carrierState = strtoupper(substr(trim((string)($post['carrier_state'] ?? '')), 0, 2));

    if ($carrierPersonId > 0 && ($carrierName === '' || $carrierDocument === '')) {
      try {
        $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([$companyId, $carrierPersonId]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $carrierName = $carrierName ?: trim((string)($p['name'] ?? ''));
        $carrierDocument = $carrierDocument ?: detailed_order_clean_digits($p['document'] ?? '', 20);
        $carrierIe = $carrierIe ?: trim((string)($p['state_registration'] ?? ''));
        $carrierAddress = $carrierAddress ?: trim((string)($p['address'] ?? ''));
        $carrierCity = $carrierCity ?: trim((string)($p['city'] ?? ''));
        $carrierState = $carrierState ?: strtoupper(substr(trim((string)($p['state'] ?? '')), 0, 2));
      } catch (Throwable $e) {}
    }

    $pdo->prepare("INSERT INTO gestao_order_fiscal_meta
      (company_id,order_id,default_cfop,freight_amount,freight_mode,carrier_person_id,carrier_name,carrier_document,carrier_ie,carrier_address,carrier_city,carrier_state,notes,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE default_cfop=VALUES(default_cfop),freight_amount=VALUES(freight_amount),freight_mode=VALUES(freight_mode),carrier_person_id=VALUES(carrier_person_id),carrier_name=VALUES(carrier_name),carrier_document=VALUES(carrier_document),carrier_ie=VALUES(carrier_ie),carrier_address=VALUES(carrier_address),carrier_city=VALUES(carrier_city),carrier_state=VALUES(carrier_state),notes=VALUES(notes),updated_at=NOW()")
      ->execute([$companyId,$orderId,$defaultCfop ?: null,$freight,$freightMode,$carrierPersonId ?: null,$carrierName ?: null,$carrierDocument ?: null,$carrierIe ?: null,$carrierAddress ?: null,$carrierCity ?: null,$carrierState ?: null,trim((string)($post['fiscal_notes'] ?? '')) ?: null]);

    $orderItems = [];
    $stItems = $pdo->prepare('SELECT id, produto_id FROM pedido_itens WHERE company_id=? AND pedido_id=? ORDER BY id ASC');
    $stItems->execute([$companyId,$orderId]);
    $orderItems = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $postedProducts = array_values((array)($post['item_product_id'] ?? []));
    $taxFields = [
      'ncm','cest','cfop','origem','icms_cst','icms_csosn','icms_rate','icms_st_rate','ipi_cst','ipi_rate',
      'pis_cst','pis_rate','cofins_cst','cofins_rate','ibs_cbs_cst','ibs_cbs_classification_code','cbs_rate','ibs_uf_rate','ibs_mun_rate','tax_notes'
    ];
    $pdo->prepare('DELETE FROM gestao_order_item_tax_overrides WHERE company_id=? AND order_id=?')->execute([$companyId,$orderId]);
    $sql = "INSERT INTO gestao_order_item_tax_overrides
      (company_id,order_id,order_item_id,product_id,ncm,cest,cfop,origem,icms_cst,icms_csosn,icms_rate,icms_st_rate,ipi_cst,ipi_rate,pis_cst,pis_rate,cofins_cst,cofins_rate,ibs_cbs_cst,ibs_cbs_classification_code,cbs_rate,ibs_uf_rate,ibs_mun_rate,notes,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
    $ins = $pdo->prepare($sql);
    foreach ($orderItems as $i => $oi) {
      if (!isset($postedProducts[$i])) continue;
      $get = static function(string $name, int $index) use ($post) {
        $arr = $post['tax_' . $name] ?? [];
        return is_array($arr) ? ($arr[$index] ?? '') : '';
      };
      $ins->execute([
        $companyId,$orderId,(int)$oi['id'],(int)($oi['produto_id'] ?? 0) ?: null,
        detailed_order_clean_digits($get('ncm',$i),12) ?: null,
        detailed_order_clean_digits($get('cest',$i),12) ?: null,
        detailed_order_clean_digits($get('cfop',$i),4) ?: ($defaultCfop ?: null),
        detailed_order_clean_digits($get('origem',$i),2) ?: '0',
        detailed_order_clean_digits($get('icms_cst',$i),5) ?: null,
        detailed_order_clean_digits($get('icms_csosn',$i),5) ?: null,
        detailed_order_decimal($get('icms_rate',$i)), detailed_order_decimal($get('icms_st_rate',$i)),
        detailed_order_clean_digits($get('ipi_cst',$i),5) ?: null, detailed_order_decimal($get('ipi_rate',$i)),
        detailed_order_clean_digits($get('pis_cst',$i),5) ?: null, detailed_order_decimal($get('pis_rate',$i)),
        detailed_order_clean_digits($get('cofins_cst',$i),5) ?: null, detailed_order_decimal($get('cofins_rate',$i)),
        detailed_order_clean_digits($get('ibs_cbs_cst',$i),5) ?: null,
        trim((string)$get('ibs_cbs_classification_code',$i)) ?: null,
        detailed_order_decimal($get('cbs_rate',$i)), detailed_order_decimal($get('ibs_uf_rate',$i)), detailed_order_decimal($get('ibs_mun_rate',$i)),
        trim((string)$get('tax_notes',$i)) ?: null,
      ]);
    }
  }
}
