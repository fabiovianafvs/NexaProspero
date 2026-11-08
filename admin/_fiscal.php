<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/fiscal_module.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/saas_modules.php';

if (empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) { try { fiscal_ensure_schema($pdo); } catch (Throwable $e) {} }

// V10.3: Tributações, Operações, Entradas, Devolução de Cliente e Devolução a Fornecedor
// foram homologadas para operar no contexto F11. As demais páginas fiscais continuam usando COMPANY_ID.
$fiscalModuleCompanyId = (int)COMPANY_ID;
$fiscalBootstrapPage = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
if (function_exists('multi_company_operational_company_id')) {
  if ($fiscalBootstrapPage === 'fiscal_tributacoes.php') {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'fiscal_tax_profiles');
  } elseif ($fiscalBootstrapPage === 'fiscal_operacoes.php') {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'fiscal_operations');
  } elseif ($fiscalBootstrapPage === 'fiscal_nota_manual.php') {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'goods_entries_manual');
  } elseif (in_array($fiscalBootstrapPage, ['fiscal_devolucoes.php','fiscal_devolucao_cliente.php'], true)) {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'customer_returns');
  } elseif ($fiscalBootstrapPage === 'fiscal_saidas.php') {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'supplier_returns');
  } elseif ($fiscalBootstrapPage === 'fiscal_documentos.php') {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'fiscal_documents_read');
  } elseif ($fiscalBootstrapPage === 'fiscal_configuracoes.php') {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'fiscal_settings_write');
  } elseif (in_array($fiscalBootstrapPage, ['fiscal_nfce_pedido.php','fiscal_nfce_acao.php','fiscal_nfce_imprimir.php'], true)) {
    // V11.35: primeira emissão fiscal cruzada, somente NFC-e em HOMOLOGAÇÃO.
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'fiscal_nfce_homologation');
  } elseif (in_array($fiscalBootstrapPage, ['fiscal_radar_xml.php','fiscal_entrada_notas.php','fiscal_importar_xml.php','fiscal_xml_entrada.php'], true)) {
    $fiscalModuleCompanyId = (int)multi_company_operational_company_id($pdo, 'goods_entries');
  }
}
saas_require_company_module($pdo, $fiscalModuleCompanyId, 'fiscal', 'Módulo Fiscal');

// V10 - helpers restritos ao fluxo de Entrada de Mercadorias por XML.
if (!function_exists('fiscal_v10_entry_company_id')) {
  function fiscal_v10_entry_company_id(PDO $pdo): int {
    if (function_exists('multi_company_operational_company_id')) {
      return (int)multi_company_operational_company_id($pdo, 'goods_entries');
    }
    return defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
  }
}
if (!function_exists('fiscal_v10_entry_is_cross_context')) {
  function fiscal_v10_entry_is_cross_context(PDO $pdo): bool {
    $legacy = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    $cid = fiscal_v10_entry_company_id($pdo);
    return $legacy > 0 && $cid > 0 && $cid !== $legacy;
  }
}
if (!function_exists('fiscal_v10_entry_context_url')) {
  function fiscal_v10_entry_context_url(string $url): string {
    return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
  }
}
if (!function_exists('fiscal_v10_entry_company_name')) {
  function fiscal_v10_entry_company_name(PDO $pdo, int $companyId): string {
    try {
      $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
      $st->execute([$companyId]);
      $name = trim((string)($st->fetchColumn() ?: ''));
      if ($name !== '') return $name;
    } catch (Throwable $e) {}
    return 'Empresa #' . $companyId;
  }
}
// V10.1 - helpers restritos à Entrada Manual no contexto F11.
if (!function_exists('fiscal_v10_1_manual_company_id')) {
  function fiscal_v10_1_manual_company_id(PDO $pdo): int {
    if (function_exists('multi_company_operational_company_id')) {
      return (int)multi_company_operational_company_id($pdo, 'goods_entries_manual');
    }
    return defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
  }
}
if (!function_exists('fiscal_v10_1_manual_is_cross_context')) {
  function fiscal_v10_1_manual_is_cross_context(PDO $pdo): bool {
    $legacy = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    $cid = fiscal_v10_1_manual_company_id($pdo);
    return $legacy > 0 && $cid > 0 && $cid !== $legacy;
  }
}
if (!function_exists('fiscal_v10_1_manual_context_url')) {
  function fiscal_v10_1_manual_context_url(string $url): string {
    return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
  }
}

// V10.2 - helpers restritos à Devolução de Cliente / Outras Entradas no contexto F11.
if (!function_exists('fiscal_v10_2_return_company_id')) {
  function fiscal_v10_2_return_company_id(PDO $pdo): int {
    if (function_exists('multi_company_operational_company_id')) {
      return (int)multi_company_operational_company_id($pdo, 'customer_returns');
    }
    return defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
  }
}
if (!function_exists('fiscal_v10_2_return_is_cross_context')) {
  function fiscal_v10_2_return_is_cross_context(PDO $pdo): bool {
    $legacy = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    $cid = fiscal_v10_2_return_company_id($pdo);
    return $legacy > 0 && $cid > 0 && $cid !== $legacy;
  }
}
if (!function_exists('fiscal_v10_2_return_context_url')) {
  function fiscal_v10_2_return_context_url(string $url): string {
    return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
  }
}
if (!function_exists('fiscal_v10_2_assert_customer_person')) {
  function fiscal_v10_2_assert_customer_person(PDO $pdo, int $companyId, int $personId): void {
    if ($personId <= 0) return;
    $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_customer=1 LIMIT 1');
    $st->execute([$companyId, $personId]);
    if (!(int)($st->fetchColumn() ?: 0)) {
      throw new RuntimeException('V10.2 Multiempresa: o cliente informado não pertence à empresa selecionada pelo F11.');
    }
  }
}


// V10.3 - helpers restritos à Devolução a Fornecedor / Saída de Estoque no contexto F11.
if (!function_exists('fiscal_v10_3_supplier_return_company_id')) {
  function fiscal_v10_3_supplier_return_company_id(PDO $pdo): int {
    if (function_exists('multi_company_operational_company_id')) {
      return (int)multi_company_operational_company_id($pdo, 'supplier_returns');
    }
    return defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
  }
}
if (!function_exists('fiscal_v10_3_supplier_return_is_cross_context')) {
  function fiscal_v10_3_supplier_return_is_cross_context(PDO $pdo): bool {
    $legacy = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    $cid = fiscal_v10_3_supplier_return_company_id($pdo);
    return $legacy > 0 && $cid > 0 && $cid !== $legacy;
  }
}
if (!function_exists('fiscal_v10_3_supplier_return_context_url')) {
  function fiscal_v10_3_supplier_return_context_url(string $url): string {
    return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
  }
}
if (!function_exists('fiscal_v10_3_assert_supplier_person')) {
  function fiscal_v10_3_assert_supplier_person(PDO $pdo, int $companyId, int $personId): void {
    if ($personId <= 0) return;
    $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_supplier=1 LIMIT 1');
    $st->execute([$companyId, $personId]);
    if (!(int)($st->fetchColumn() ?: 0)) {
      throw new RuntimeException('V10.3 Multiempresa: o fornecedor informado não pertence à empresa selecionada pelo F11.');
    }
  }
}
if (!function_exists('fiscal_v10_3_assert_supplier_return_operation')) {
  function fiscal_v10_3_assert_supplier_return_operation(PDO $pdo, int $companyId, int $operationId): array {
    $operation = $operationId > 0 ? fiscal_operation($pdo, $companyId, $operationId) : null;
    if (!$operation || strtoupper((string)($operation['direction'] ?? '')) !== 'SAIDA') {
      throw new RuntimeException('V10.3 Multiempresa: selecione uma operação de saída válida da empresa do F11.');
    }
    $type = strtoupper(trim((string)($operation['operation_type'] ?? '')));
    $name = strtoupper(trim((string)($operation['name'] ?? '')));
    $isSupplierReturn = $type === 'DEVOLUCAO_FORNECEDOR'
      || (str_contains($type, 'DEVOL') && str_contains($type, 'FORNEC'))
      || (str_contains($name, 'DEVOL') && str_contains($name, 'FORNEC'));
    if (!$isSupplierReturn) {
      throw new RuntimeException('V10.3 Multiempresa: no contexto F11 esta tela aceita somente operação de devolução a fornecedor.');
    }
    if ((int)($operation['update_stock'] ?? 0) !== 1) {
      throw new RuntimeException('V10.3 Multiempresa: a operação de devolução a fornecedor precisa estar configurada para movimentar estoque.');
    }
    if ((int)($operation['create_finance'] ?? 0) === 1) {
      throw new RuntimeException('V10.3 Multiempresa: operações que geram financeiro permanecem bloqueadas no contexto F11. Desative o financeiro desta operação ou acesse diretamente a empresa.');
    }
    return $operation;
  }
}
if (!function_exists('fiscal_v10_3_assert_source_entries_and_items')) {
  function fiscal_v10_3_assert_source_entries_and_items(PDO $pdo, int $companyId, array $entryIds, array $post): void {
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn($v) => $v > 0)));
    $sourceItemIds = array_values(array_unique(array_filter(array_map('intval', (array)($post['source_item_id'] ?? [])), static fn($v) => $v > 0)));
    if (!$entryIds) {
      if ($sourceItemIds) throw new RuntimeException('V10.3 Multiempresa: item de entrada informado sem documento de origem válido.');
      return;
    }

    $ph = implode(',', array_fill(0, count($entryIds), '?'));
    $st = $pdo->prepare("SELECT id FROM fiscal_xml_imports WHERE company_id=? AND id IN ($ph)");
    $st->execute(array_merge([$companyId], $entryIds));
    $validEntries = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    sort($validEntries); $expected = $entryIds; sort($expected);
    if ($validEntries !== $expected) {
      throw new RuntimeException('V10.3 Multiempresa: uma das entradas selecionadas não pertence à empresa do F11.');
    }
    if (!$sourceItemIds) return;

    $iph = implode(',', array_fill(0, count($sourceItemIds), '?'));
    $st = $pdo->prepare("SELECT id, xml_import_id, product_id, quantity FROM fiscal_xml_items WHERE company_id=? AND xml_import_id IN ($ph) AND id IN ($iph)");
    $st->execute(array_merge([$companyId], $entryIds, $sourceItemIds));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) $map[(int)$row['id']] = $row;
    if (count($map) !== count($sourceItemIds)) {
      throw new RuntimeException('V10.3 Multiempresa: um dos itens de origem não pertence às entradas selecionadas da empresa do F11.');
    }

    $postedSource = (array)($post['source_item_id'] ?? []);
    $postedProducts = (array)($post['product_id'] ?? []);
    $postedQtys = (array)($post['quantity'] ?? []);
    foreach ($postedSource as $idx => $sourceIdRaw) {
      $sourceId = (int)$sourceIdRaw;
      if ($sourceId <= 0) continue;
      $row = $map[$sourceId] ?? null;
      if (!$row) throw new RuntimeException('V10.3 Multiempresa: item de origem inválido.');
      $sourceProductId = (int)($row['product_id'] ?? 0);
      $postedProductId = (int)($postedProducts[$idx] ?? 0);
      if ($sourceProductId > 0 && $postedProductId !== $sourceProductId) {
        throw new RuntimeException('V10.3 Multiempresa: o produto informado não corresponde ao item da entrada original.');
      }
      $sourceQty = (float)($row['quantity'] ?? 0);
      $postedQty = fiscal_xml_parse_decimal($postedQtys[$idx] ?? 0, 0);
      if ($sourceQty > 0 && $postedQty > ($sourceQty + 0.0001)) {
        throw new RuntimeException('V10.3 Multiempresa: a quantidade devolvida não pode superar a quantidade recebida no item de origem.');
      }
    }
  }
}

if (!function_exists('fiscal_v10_assert_xml_recipient')) {
  function fiscal_v10_assert_xml_recipient(PDO $pdo, int $companyId, array $data): void {
    // A validação adicional só é obrigatória quando o F11 está operando outra empresa.
    if (!fiscal_v10_entry_is_cross_context($pdo)) return;
    $buyerDoc = function_exists('fiscal_xml_digits') ? fiscal_xml_digits($data['buyer_doc'] ?? '') : preg_replace('/\\D+/', '', (string)($data['buyer_doc'] ?? ''));
    $identity = function_exists('fiscal_company_report_identity') ? fiscal_company_report_identity($pdo, $companyId) : [];
    $companyDoc = function_exists('fiscal_xml_digits') ? fiscal_xml_digits($identity['document'] ?? '') : preg_replace('/\\D+/', '', (string)($identity['document'] ?? ''));
    if ($companyDoc === '') {
      throw new RuntimeException('V10 Multiempresa: configure o CNPJ fiscal da empresa selecionada pelo F11 antes de importar uma NF-e de entrada.');
    }
    if ($buyerDoc === '') {
      throw new RuntimeException('V10 Multiempresa: o XML não informa o CNPJ/CPF do destinatário. A importação foi bloqueada por segurança.');
    }
    if ($buyerDoc !== $companyDoc) {
      throw new RuntimeException('V10 Multiempresa: esta NF-e não pertence à empresa selecionada pelo F11. Destinatário do XML e CNPJ fiscal da empresa são diferentes.');
    }
  }
}

function fiscal_admin_start(string $title, string $active = 'fiscal'): void {
  $entradaKeys = ['fiscal_radar_xml','fiscal_entrada_notas','fiscal_devolucoes','fiscal_devolucao_cliente','fiscal_importar_xml','fiscal_nota_manual','fiscal_xml_entrada'];
  $tabelaKeys = ['fiscal_operacoes','fiscal_tributacoes','fiscal_cfops','fiscal_ncms','fiscal_cests','fiscal_cst','fiscal_ibscbs','fiscal_importacoes','fiscal_tabelas','fiscal_st_uf','fiscal_produtos'];
  if (strpos($active, 'fiscal_relatorios') === 0 || in_array($active, ['fiscal_relatorio_inutilizacoes','fiscal_sintegra','fiscal_sped_icms','fiscal_sped_contrib','fiscal_inventario','fiscal_exportar_xml','fiscal_contabilidade'], true)) $topActive = 'fiscal_relatorios';
  elseif (in_array($active, $entradaKeys, true)) $topActive = 'entradas';
  elseif (in_array($active, $tabelaKeys, true)) $topActive = 'tabelas';
  elseif ($active === 'fiscal_saidas') $topActive = 'fiscal';
  else $topActive = 'fiscal';
  admin_topbar($title, $topActive);
  ?>
  <style>
    .fiscal-page{--fis-border:#dbe4f0;--fis-radius:22px;--fis-muted:#64748b;}
    .fiscal-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:8px 0 14px}.fiscal-hero h1{margin:0;font-size:clamp(25px,3.2vw,35px);letter-spacing:-.04em;color:var(--admin-primary)}.fiscal-hero p{margin:7px 0 0;color:#64748b;line-height:1.45;max-width:880px}.fiscal-actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-left:auto}
    .fiscal-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px;align-items:center}.fiscal-tabs a{display:inline-flex;align-items:center;padding:9px 12px;border:1px solid #dbe4f0;border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:850;font-size:13px;min-height:38px}.fiscal-tabs a.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:var(--admin-text);border-color:transparent}
    .fiscal-card{background:#fff;border:1px solid var(--fis-border);border-radius:var(--fis-radius);box-shadow:0 14px 34px rgba(2,6,23,.06);padding:18px;margin-bottom:14px;min-width:0}.fiscal-card h2,.fiscal-card h3{margin:0 0 12px;color:var(--admin-primary)}
    .fiscal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.fiscal-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.fiscal-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.fiscal-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.fiscal-form-grid .span-2{grid-column:span 2}.fiscal-form-grid .span-3{grid-column:1/-1}.fiscal-section{border:1px solid #eef2f7;border-radius:18px;padding:14px;background:#fbfdff;margin-bottom:14px}.fiscal-section h3{font-size:15px;margin:0 0 12px}.fiscal-muted{color:#64748b}.fiscal-kpi{background:#fff;border:1px solid var(--fis-border);border-radius:14px;padding:8px 14px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;min-width:0}.fiscal-kpi span{display:block;color:#64748b;font-weight:850;font-size:12px}.fiscal-kpi strong{display:block;margin-top:0;font-size:clamp(17px,1.7vw,21px);color:#047857}.fiscal-table{width:100%;border-collapse:separate;border-spacing:0}.fiscal-table th{background:#f8fbff;color:#334155;font-size:13px;text-align:left;padding:11px 12px;border-top:1px solid var(--fis-border);border-bottom:1px solid var(--fis-border)}.fiscal-table th:first-child{border-left:1px solid var(--fis-border);border-radius:14px 0 0 0}.fiscal-table th:last-child{border-right:1px solid var(--fis-border);border-radius:0 14px 0 0}.fiscal-table td{padding:12px;border-bottom:1px solid #e5edf6;vertical-align:middle}.fiscal-grid-table{table-layout:fixed}.fiscal-grid-table th,.fiscal-grid-table td{min-width:0;overflow:hidden}.fiscal-grid-table .fiscal-cell-clip{display:block;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.fiscal-grid-table .fiscal-actions-head,.fiscal-grid-table .fiscal-actions-col{width:96px;text-align:right;white-space:nowrap;overflow:visible}.fiscal-grid-table .fiscal-actions-col{position:sticky;right:0;z-index:2;background:#fff;box-shadow:-12px 0 18px rgba(255,255,255,.92)}.fiscal-grid-table thead .fiscal-actions-head{position:sticky;right:0;z-index:3;background:#f8fbff}.fiscal-grid-table tbody tr:hover .fiscal-actions-col{background:#f8fbff}.fiscal-grid-table .fiscal-actions-col .btn{white-space:nowrap}.fiscal-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:12px;font-weight:900}.fiscal-badge.off{background:#f8fafc;color:#64748b;border-color:#e2e8f0}.fiscal-inline-actions{display:flex;gap:8px;flex-wrap:wrap}.fiscal-form-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;border-top:1px solid #eef2f7;margin-top:14px;padding-top:14px}

    .fiscal-modal{position:fixed;inset:0;z-index:250;display:flex;align-items:flex-start;justify-content:center;padding:22px;background:rgba(15,23,42,.56);backdrop-filter:blur(3px);overflow:auto}.fiscal-modal-dialog{width:min(980px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 26px 80px rgba(2,6,23,.34);padding:18px;animation:fiscalModalIn .16s ease-out}.fiscal-modal-wide{width:min(1180px,100%)}.fiscal-modal-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:14px}.fiscal-modal-head h2{margin:0;color:var(--admin-primary);font-size:21px}.fiscal-modal-close{display:inline-flex;width:42px;height:42px;align-items:center;justify-content:center;border-radius:14px;border:1px solid #dbe4f0;background:#fff;text-decoration:none;color:#0f172a;font-size:22px;font-weight:900}.fiscal-filter-actions{display:flex;align-items:end;gap:8px;flex-wrap:wrap}.fiscal-list-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.fiscal-list-count{color:#64748b;font-weight:800;font-size:13px}.fiscal-form label{font-weight:850;color:#0f172a;font-size:12px}.fiscal-form input,.fiscal-form select,.fiscal-form textarea,.fiscal-form .field{width:100%;min-height:44px;border:1px solid #dbe4f0;border-radius:14px;background:#fff;padding:10px 12px;font:inherit;color:#0f172a;box-sizing:border-box}.fiscal-form textarea{min-height:80px;resize:vertical}.fiscal-checkline{display:flex;align-items:center;gap:8px;margin-top:16px;font-weight:850}.fiscal-checkline input{width:auto;min-height:0}.fiscal-page .btn-sm{min-height:34px;padding:7px 10px;font-size:12px}
    @keyframes fiscalModalIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
    @media(max-width:980px){.fiscal-grid,.fiscal-grid-3,.fiscal-grid-4,.fiscal-form-grid{grid-template-columns:1fr}.fiscal-form-grid .span-2,.fiscal-form-grid .span-3{grid-column:auto}.fiscal-actions{width:100%;justify-content:stretch}.fiscal-actions .btn{flex:1}.fiscal-tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px}.fiscal-tabs a{white-space:nowrap}.fiscal-modal{padding:0;align-items:stretch}.fiscal-modal-dialog{width:100%;min-height:100vh;border-radius:0;border:0;padding:15px}}
    @media(max-width:680px){.table-wrap{overflow:visible}.fiscal-grid-table .fiscal-actions-col,.fiscal-grid-table thead .fiscal-actions-head{position:static;box-shadow:none;text-align:right}.fiscal-table thead{display:none}.fiscal-table tr{display:block;border:1px solid #dbe4f0;border-radius:18px;background:#fff;margin-bottom:10px;padding:10px}.fiscal-table td{display:flex;justify-content:space-between;gap:12px;border:0!important;padding:7px 4px!important;text-align:right}.fiscal-table td:before{content:attr(data-label);font-weight:900;color:#64748b;text-align:left}.fiscal-table td.primary{display:block;text-align:left}.fiscal-table td.primary:before{display:none}}
  </style>
  <div class="fiscal-page">
  <?php
}

function fiscal_admin_end(): void {
  $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
  if ($page === 'fiscal_lote_contingencia.php') {
    ?>
    <style>
      .nexa-lote-edit-cell{white-space:nowrap;text-align:right}.nexa-lote-edit-btn{min-height:34px;padding:7px 12px;font-size:12px;font-weight:900}
    </style>
    <script>
    (function(){
      function installBatchEditButtons(){
        var checks = document.querySelectorAll('input[type="checkbox"][name="doc_ids[]"]');
        if (!checks.length) return;
        var returnTo = 'fiscal_lote_contingencia.php' + (window.location.search || '');
        checks.forEach(function(chk){
          var row = chk.closest('tr');
          if (!row || row.querySelector('.nexa-lote-edit-cell')) return;
          var id = parseInt(chk.value || '0', 10);
          if (!id) return;
          var cell = document.createElement('td');
          cell.className = 'nexa-lote-edit-cell';
          cell.setAttribute('data-label','Ações');
          var link = document.createElement('a');
          link.className = 'btn nexa-lote-edit-btn';
          link.textContent = 'Editar';
          link.href = 'fiscal_nfce_editar.php?doc_id=' + encodeURIComponent(String(id)) + '&return_to=' + encodeURIComponent(returnTo);
          cell.appendChild(link);
          row.appendChild(cell);
        });
        var headRow = document.querySelector('input[type="checkbox"][name="doc_ids[]"]')?.closest('table')?.querySelector('thead tr');
        if (headRow && !headRow.querySelector('.nexa-lote-edit-head')) {
          var th = document.createElement('th');
          th.className = 'nexa-lote-edit-head';
          th.textContent = 'Ações';
          th.style.textAlign = 'right';
          headRow.appendChild(th);
        }
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installBatchEditButtons);
      else installBatchEditButtons();
    })();
    </script>
    <?php
  }
  echo '</div>';
  admin_footer();
}

function fiscal_tabs(string $active): void {
  $entryKeys = ['fiscal_radar_xml','fiscal_entrada_notas','fiscal_devolucoes','fiscal_devolucao_cliente','fiscal_importar_xml','fiscal_nota_manual','fiscal_xml_entrada'];
  if (in_array($active, $entryKeys, true)) {
    $isCrossEntry = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
      && defined('MULTI_COMPANY_CONTEXT_ID') && defined('COMPANY_ID')
      && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
    $items = $isCrossEntry ? [
      'fiscal_radar_xml' => ['RADAR NF-e (consulta)', 'fiscal_radar_xml.php'],
      'fiscal_entrada_notas' => ['Entrada de Notas', 'fiscal_entrada_notas.php'],
      'fiscal_importar_xml' => ['Importar XML', 'fiscal_importar_xml.php'],
      'fiscal_nota_manual' => ['Entrada manual', 'fiscal_nota_manual.php'],
    ] : [
      'fiscal_radar_xml' => ['RADAR NF-e', 'fiscal_radar_xml.php'],
      'fiscal_entrada_notas' => ['Entrada de Notas', 'fiscal_entrada_notas.php'],
      'fiscal_devolucoes' => ['Devolução / Outras Entradas', 'fiscal_devolucoes.php'],
      'fiscal_devolucao_cliente' => ['Nova devolução', 'fiscal_devolucao_cliente.php'],
      'fiscal_importar_xml' => ['Importar XML', 'fiscal_importar_xml.php'],
      'fiscal_nota_manual' => ['Entrada manual', 'fiscal_nota_manual.php'],
    ];
  } else {
    $isCrossFiscal = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
      && defined('MULTI_COMPANY_CONTEXT_ID') && defined('COMPANY_ID')
      && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
    $items = $isCrossFiscal ? [
      'fiscal_documentos' => ['Documentos fiscais (consulta)', 'fiscal_documentos.php'],
      'fiscal_configuracoes' => ['Configurações (consulta)', 'fiscal_configuracoes.php'],
    ] : [
      'fiscal_documentos' => ['Documentos fiscais', 'fiscal_documentos.php'],
      'fiscal_nfse_nacional' => ['NFS-e', 'fiscal_nfse_nacional.php'],
      'fiscal_emissao_lote' => ['Emissão em lote', 'fiscal_emissao_lote.php'],
      'fiscal_lote_contingencia' => ['Reprocessamento', 'fiscal_lote_contingencia.php'],
      'fiscal_inutilizacao' => ['Inutilização', 'fiscal_inutilizacao.php'],
      'fiscal_saidas' => ['Saídas fiscais', 'fiscal_saidas.php'],
      'fiscal_contadores' => ['Cadastro de contadores', 'fiscal_contadores.php'],
      'fiscal_configuracoes' => ['Configurações', 'fiscal_configuracoes.php'],
      'fiscal_xml_conversoes' => ['Conversões XML', 'fiscal_xml_conversoes.php'],
    ];
  }
  echo '<div class="fiscal-tabs">';
  foreach ($items as $key => $it) {
    $href = (string)$it[1];
    if (function_exists('multi_company_context_url')) $href = multi_company_context_url($href);
    echo '<a class="' . ($key===$active?'active':'') . '" href="' . fiscal_h($href) . '">' . fiscal_h($it[0]) . '</a>';
  }
  echo '</div>';
}



function fiscal_report_tabs(string $active): void {
  $items = [
    'fiscal_relatorios' => ['Visão Geral', 'fiscal_relatorios.php'],
    'fiscal_relatorios_documentos' => ['Documentos Fiscais', 'fiscal_relatorio_documentos.php'],
    'fiscal_sintegra' => ['Gerar Sintegra', 'fiscal_relatorio_sintegra.php'],
    'fiscal_sped_icms' => ['Gerar SPED ICMS/IPI', 'fiscal_relatorio_sped_icms.php'],
    'fiscal_sped_contrib' => ['Gerar SPED Contribuições', 'fiscal_relatorio_sped_contrib.php'],
    'fiscal_exportar_xml' => ['Exportar XMLs', 'fiscal_exportar_xml.php'],
    'fiscal_inventario' => ['Inventário', 'fiscal_relatorio_inventario.php'],
    'fiscal_contabilidade' => ['Documentos para Contabilidade', 'fiscal_contabilidade.php'],
  ];
  echo '<div class="fiscal-tabs">';
  foreach ($items as $key => $it) echo '<a class="' . ($key===$active?'active':'') . '" href="' . fiscal_h($it[1]) . '">' . fiscal_h($it[0]) . '</a>';
  echo '</div>';
}

function fiscal_page_header(string $title, string $description, array $actions = []): void {
  echo '<div class="fiscal-hero"><div><h1>' . fiscal_h($title) . '</h1>' . (trim($description) !== '' ? '<p>' . fiscal_h($description) . '</p>' : '') . '</div>';
  if ($actions) { echo '<div class="fiscal-actions">'; foreach ($actions as $a) echo '<a class="' . fiscal_h($a['class'] ?? 'btn') . '" href="' . fiscal_h($a['href'] ?? '#') . '">' . fiscal_h($a['label'] ?? 'Ação') . '</a>'; echo '</div>'; }
  echo '</div>';
}

function fiscal_flash(): void {
  if (!empty($_GET['ok'])) echo '<div class="msg ok">' . fiscal_h($_GET['ok']) . '</div>';
  if (!empty($_GET['err'])) echo '<div class="msg err">' . fiscal_h($_GET['err']) . '</div>';
}
