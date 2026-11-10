<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

// V8.1: o cadastro de Tributações passa a operar explicitamente na empresa
// selecionada pelo F11. Sem contexto válido, retorna naturalmente ao COMPANY_ID.
$taxCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_tax_profiles')
  : (int)COMPANY_ID;
$taxCrossContext = $taxCompanyId > 0 && $taxCompanyId !== (int)COMPANY_ID;
$taxCompanyName = '';
try {
  $stTaxCompany = $pdo->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
  $stTaxCompany->execute([$taxCompanyId]);
  $taxCompanyName = trim((string)($stTaxCompany->fetchColumn() ?: ''));
} catch (Throwable $e) {}
$canManageFiscalOperations = !$taxCrossContext;
if ($taxCrossContext && function_exists('multi_company_context_permission_allowed')) {
  $canManageFiscalOperations = multi_company_context_permission_allowed($pdo, 'fiscal_operacoes');
}

$id = (int)($_GET['id'] ?? 0);
$err = '';
$showModal = isset($_GET['new']) || $id > 0;

$blank = [
  'id' => 0,
  'code' => '',
  'name' => '',
  'is_active' => 1,
  'profile_type' => 'PRODUCT',
  'tax_mode' => 'TRIBUTADO',
  'nbs_code' => '',
  'nbs_description' => '',
  'lc116_service_code' => '',
  'lc116_service_description' => '',
  'iss_rate' => '0',
  'iss_withheld' => 0,
  'pis_cofins_retention_type' => '',
  'irrf_rate' => '0',
  'csll_rate' => '0',
  'inss_rate' => '0',
  'municipal_tax_code' => '',
  'cnae' => '',
  'fiscal_incentive' => 0,
  'nfse_municipal_tax_type' => '',
  'nfse_municipal_tax_nature' => '',
  'nfse_municipal_iss_exigibility' => '',
  'nfse_municipal_special_regime' => '',
  'nfse_national_tax_type' => '',
  'nfse_national_tax_code' => '',
  'nfse_national_operation_indicator' => '',
  'nfse_simples_tax_regime' => '',
  'nfse_operation_payment_type' => '',
  'nfse_rtc_special_regime' => '',
  'municipal_benefit_type' => '',
  'municipal_differentiated_rate' => '0',
  'municipal_reduction_base_percent' => '0',
  'municipal_benefit_number' => '',
  'ibs_cbs_cst' => '',
  'ibs_cbs_classification_code' => '',
  'ibs_cbs_classification_name' => '',
  'icms_rate' => '0',
  'icms_reduction_rate' => '0',
  'icms_cst' => '00',
  'icms_csosn' => '102',
  'icms_st_rate' => '0',
  'ipi_rate' => '0',
  'ipi_cst' => '50',
  'pis_in_rate' => '0',
  'pis_in_cst' => '50',
  'cofins_in_rate' => '0',
  'cofins_in_cst' => '50',
  'pis_out_rate' => '0',
  'pis_out_cst' => '01',
  'cofins_out_rate' => '0',
  'cofins_out_cst' => '01',
  'cbs_rate' => '0',
  'ibs_uf_rate' => '0',
  'ibs_mun_rate' => '0',
  'ibs_cbs_rate_mode' => 'AUTO',
  'cfop_sale_state' => '5102',
  'cfop_sale_state_desc' => 'VENDA DE MERCADORIA ADQUIRIDA',
  'cfop_sale_interstate' => '6102',
  'cfop_sale_interstate_desc' => 'VENDA DE MERCADORIA ADQUIRIDA OU RECEBIDA DE TERCEIROS',
  'cfop_return_state' => '1202',
  'cfop_return_state_desc' => 'DEVOLUÇÃO DE VENDA DE MERCADORIA',
  'cfop_return_interstate' => '2202',
  'cfop_return_interstate_desc' => 'DEVOLUÇÃO DE VENDA DE MERCADORIA',
  'cfop_entry_state' => '1102',
  'cfop_entry_state_desc' => 'COMPRA PARA COMERCIALIZAÇÃO',
  'cfop_entry_interstate' => '2102',
  'cfop_entry_interstate_desc' => 'COMPRA PARA COMERCIALIZAÇÃO',
  'notes' => '',
];
$current = $blank;
require_once __DIR__ . '/../config/fiscal_tax_transfer.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['nexa_tax_transfer_csrf'])) $_SESSION['nexa_tax_transfer_csrf'] = bin2hex(random_bytes(24));
if (($_GET['action'] ?? '') === 'export_tributacoes') nexa_tax_export($pdo, $taxCompanyId);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_tributacoes') {
  try {
    $token = (string)($_POST['transfer_csrf'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['nexa_tax_transfer_csrf'], $token)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    // DDL deve ser executada antes da transação de importação.
    fiscal_ensure_schema($pdo);
    if (function_exists('fiscal_ensure_operation_cfop_schema')) fiscal_ensure_operation_cfop_schema($pdo);
    $result = nexa_tax_import($pdo, $taxCompanyId, (array)($_FILES['tributacoes_json'] ?? []));
    $message = sprintf('Importação concluída: %d nova(s), %d atualizada(s), %d vínculo(s) CFOP gravado(s), %d operação(ões) não localizada(s).', $result['inserted'], $result['updated'], $result['opSaved'], $result['opSkipped']);
    header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_tributacoes.php?ok=' . rawurlencode($message)) : 'fiscal_tributacoes.php?ok=' . rawurlencode($message)));
    exit;
  } catch (Throwable $e) { $err = 'Falha ao importar: ' . $e->getMessage(); }
}


if (!function_exists('fiscal_tributacoes_values_equal')) {
  function fiscal_tributacoes_values_equal(string $field, $expected, $actual): bool {
    $decimalFields = [
      'iss_rate','irrf_rate','csll_rate','inss_rate','municipal_differentiated_rate','municipal_reduction_base_percent',
      'icms_rate','icms_reduction_rate','icms_st_rate','ipi_rate','pis_in_rate','cofins_in_rate','pis_out_rate','cofins_out_rate',
      'cbs_rate','ibs_uf_rate','ibs_mun_rate'
    ];
    $integerFields = ['is_active','iss_withheld','fiscal_incentive'];
    if (in_array($field, $decimalFields, true)) return abs((float)$expected - (float)$actual) < 0.00005;
    if (in_array($field, $integerFields, true)) return (int)$expected === (int)$actual;
    return trim((string)$expected) === trim((string)$actual);
  }
}

if ($id > 0) {
  $profile = fiscal_tax_profile($pdo, $taxCompanyId, $id);
  if ($profile) $current = array_merge($current, $profile);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? 'save') !== 'import_tributacoes') {
  $showModal = true;
  $id = (int)($_POST['id'] ?? 0);
  $data = [
    'code' => trim((string)($_POST['code'] ?? '')),
    'name' => trim((string)($_POST['name'] ?? '')),
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
    'profile_type' => fiscal_normalize_item_type($_POST['profile_type'] ?? 'PRODUCT'),
    'tax_mode' => trim((string)($_POST['tax_mode'] ?? 'TRIBUTADO')),
    'nbs_code' => preg_replace('/\D+/', '', (string)($_POST['nbs_code'] ?? '')),
    'nbs_description' => trim((string)($_POST['nbs_description'] ?? '')),
    'lc116_service_code' => preg_replace('/[^0-9.]+/', '', (string)($_POST['lc116_service_code'] ?? '')),
    'lc116_service_description' => trim((string)($_POST['lc116_service_description'] ?? '')),
    'iss_rate' => fiscal_decimal($_POST['iss_rate'] ?? 0),
    'iss_withheld' => isset($_POST['iss_withheld']) ? 1 : 0,
    'pis_cofins_retention_type' => trim((string)($_POST['pis_cofins_retention_type'] ?? '')),
    'irrf_rate' => fiscal_decimal($_POST['irrf_rate'] ?? 0),
    'csll_rate' => fiscal_decimal($_POST['csll_rate'] ?? 0),
    'inss_rate' => fiscal_decimal($_POST['inss_rate'] ?? 0),
    'municipal_tax_code' => trim((string)($_POST['municipal_tax_code'] ?? '')),
    'cnae' => preg_replace('/\D+/', '', (string)($_POST['cnae'] ?? '')),
    'fiscal_incentive' => isset($_POST['fiscal_incentive']) ? 1 : 0,
    'nfse_municipal_tax_type' => trim((string)($_POST['nfse_municipal_tax_type'] ?? '')),
    'nfse_municipal_tax_nature' => trim((string)($_POST['nfse_municipal_tax_nature'] ?? '')),
    'nfse_municipal_iss_exigibility' => trim((string)($_POST['nfse_municipal_iss_exigibility'] ?? '')),
    'nfse_municipal_special_regime' => trim((string)($_POST['nfse_municipal_special_regime'] ?? '')),
    'nfse_national_tax_type' => trim((string)($_POST['nfse_national_tax_type'] ?? '')),
    'nfse_national_tax_code' => trim((string)($_POST['nfse_national_tax_code'] ?? '')),
    'nfse_national_operation_indicator' => trim((string)($_POST['nfse_national_operation_indicator'] ?? '')),
    'nfse_simples_tax_regime' => trim((string)($_POST['nfse_simples_tax_regime'] ?? '')),
    'nfse_operation_payment_type' => trim((string)($_POST['nfse_operation_payment_type'] ?? '')),
    'nfse_rtc_special_regime' => trim((string)($_POST['nfse_rtc_special_regime'] ?? '')),
    'municipal_benefit_type' => trim((string)($_POST['municipal_benefit_type'] ?? '')),
    'municipal_differentiated_rate' => fiscal_decimal($_POST['municipal_differentiated_rate'] ?? 0),
    'municipal_reduction_base_percent' => fiscal_decimal($_POST['municipal_reduction_base_percent'] ?? 0),
    'municipal_benefit_number' => trim((string)($_POST['municipal_benefit_number'] ?? '')),
    'ibs_cbs_cst' => preg_replace('/\D+/', '', (string)($_POST['ibs_cbs_cst'] ?? '')),
    'ibs_cbs_classification_code' => trim((string)($_POST['ibs_cbs_classification_code'] ?? '')),
    'ibs_cbs_classification_name' => trim((string)($_POST['ibs_cbs_classification_name'] ?? '')),
    'icms_rate' => fiscal_decimal($_POST['icms_rate'] ?? 0),
    'icms_reduction_rate' => fiscal_decimal($_POST['icms_reduction_rate'] ?? 0),
    'icms_cst' => trim((string)($_POST['icms_cst'] ?? '')),
    'icms_csosn' => trim((string)($_POST['icms_csosn'] ?? '')),
    'icms_st_rate' => fiscal_decimal($_POST['icms_st_rate'] ?? 0),
    'ipi_rate' => fiscal_decimal($_POST['ipi_rate'] ?? 0),
    'ipi_cst' => trim((string)($_POST['ipi_cst'] ?? '')),
    'pis_in_rate' => fiscal_decimal($_POST['pis_in_rate'] ?? 0),
    'pis_in_cst' => trim((string)($_POST['pis_in_cst'] ?? '')),
    'cofins_in_rate' => fiscal_decimal($_POST['cofins_in_rate'] ?? 0),
    'cofins_in_cst' => trim((string)($_POST['cofins_in_cst'] ?? '')),
    'pis_out_rate' => fiscal_decimal($_POST['pis_out_rate'] ?? 0),
    'pis_out_cst' => trim((string)($_POST['pis_out_cst'] ?? '')),
    'cofins_out_rate' => fiscal_decimal($_POST['cofins_out_rate'] ?? 0),
    'cofins_out_cst' => trim((string)($_POST['cofins_out_cst'] ?? '')),
    'cbs_rate' => fiscal_decimal($_POST['cbs_rate'] ?? 0),
    'ibs_uf_rate' => fiscal_decimal($_POST['ibs_uf_rate'] ?? 0),
    'ibs_mun_rate' => fiscal_decimal($_POST['ibs_mun_rate'] ?? 0),
    'ibs_cbs_rate_mode' => strtoupper(trim((string)($_POST['ibs_cbs_rate_mode'] ?? ''))),
    'cfop_sale_state' => preg_replace('/\D+/', '', (string)($_POST['cfop_sale_state'] ?? '')),
    'cfop_sale_state_desc' => trim((string)($_POST['cfop_sale_state_desc'] ?? '')),
    'cfop_sale_interstate' => preg_replace('/\D+/', '', (string)($_POST['cfop_sale_interstate'] ?? '')),
    'cfop_sale_interstate_desc' => trim((string)($_POST['cfop_sale_interstate_desc'] ?? '')),
    'cfop_return_state' => preg_replace('/\D+/', '', (string)($_POST['cfop_return_state'] ?? '')),
    'cfop_return_state_desc' => trim((string)($_POST['cfop_return_state_desc'] ?? '')),
    'cfop_return_interstate' => preg_replace('/\D+/', '', (string)($_POST['cfop_return_interstate'] ?? '')),
    'cfop_return_interstate_desc' => trim((string)($_POST['cfop_return_interstate_desc'] ?? '')),
    'cfop_entry_state' => preg_replace('/\D+/', '', (string)($_POST['cfop_entry_state'] ?? '')),
    'cfop_entry_state_desc' => trim((string)($_POST['cfop_entry_state_desc'] ?? '')),
    'cfop_entry_interstate' => preg_replace('/\D+/', '', (string)($_POST['cfop_entry_interstate'] ?? '')),
    'cfop_entry_interstate_desc' => trim((string)($_POST['cfop_entry_interstate_desc'] ?? '')),
    'notes' => trim((string)($_POST['notes'] ?? '')),
  ];

  if (!in_array($data['ibs_cbs_rate_mode'], ['AUTO','MANUAL'], true)) {
    $data['ibs_cbs_rate_mode'] = (($data['cbs_rate'] > 0) || ($data['ibs_uf_rate'] > 0) || ($data['ibs_mun_rate'] > 0)) ? 'MANUAL' : 'AUTO';
  }
  if ($data['ibs_cbs_classification_code'] !== '' && function_exists('fiscal_ibs_cbs_classification_by_code')) {
    try {
      $classRow = fiscal_ibs_cbs_classification_by_code($pdo, $taxCompanyId, $data['ibs_cbs_classification_code']);
      if ($classRow) {
        $data['ibs_cbs_classification_code'] = preg_replace('/\D+/', '', (string)($classRow['classification_code'] ?? $data['ibs_cbs_classification_code']));
        $data['ibs_cbs_classification_name'] = trim((string)($classRow['description'] ?? $data['ibs_cbs_classification_name']));
      }
    } catch (Throwable $e) {}
  }

  $current = array_merge($blank, $data, ['id'=>$id]);
  if ($data['name'] === '') {
    $err = 'Informe o nome da tributação.';
  } else {
    try {
      // Garante a estrutura antes da transação, evitando DDL/commit implícito durante a gravação.
      fiscal_ensure_schema($pdo);
      if (function_exists('fiscal_ensure_operation_cfop_schema')) fiscal_ensure_operation_cfop_schema($pdo);

      $startedTransaction = false;
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
      }
      try {
        if ($id > 0) {
          $assignments = implode(', ', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '` = :' . $column, array_keys($data)));
          $sql = 'UPDATE fiscal_tax_profiles SET ' . $assignments . ', updated_at = NOW() WHERE id = :id AND company_id = :company_id';
          $st = $pdo->prepare($sql);
          $params = $data;
          $params['id'] = $id;
          $params['company_id'] = $taxCompanyId;
          $st->execute($params);
          if ($st->rowCount() === 0) {
            $exists = fiscal_tax_profile($pdo, $taxCompanyId, $id);
            if (!$exists) throw new RuntimeException('A tributação não foi encontrada para esta empresa.');
          }
        } else {
          $cols = array_keys($data);
          $sql = 'INSERT INTO fiscal_tax_profiles (company_id, `' . implode('`,`', $cols) . '`) VALUES (?, ' . implode(',', array_fill(0, count($cols), '?')) . ')';
          $st = $pdo->prepare($sql);
          $st->execute(array_merge([$taxCompanyId], array_values($data)));
          $id = (int)$pdo->lastInsertId();
          if ($id <= 0) throw new RuntimeException('Não foi possível obter o código da tributação gravada.');
        }

        if ($id > 0 && function_exists('fiscal_save_tax_profile_operation_cfops_from_post')) {
          fiscal_save_tax_profile_operation_cfops_from_post($pdo, $taxCompanyId, $id, $_POST);
          fiscal_sync_profile_operation_cfops_to_products($pdo, $taxCompanyId, $id);
        }

        // Confirma no próprio banco cada campo antes de informar sucesso ao usuário.
        $persisted = fiscal_tax_profile($pdo, $taxCompanyId, $id);
        if (!$persisted) throw new RuntimeException('A tributação foi enviada para gravação, mas não foi localizada na conferência final.');
        $mismatches = [];
        foreach ($data as $field => $expected) {
          $actual = $persisted[$field] ?? null;
          if (!fiscal_tributacoes_values_equal($field, $expected, $actual)) $mismatches[] = $field;
        }
        if ($mismatches) {
          throw new RuntimeException('A conferência de gravação detectou divergência nos campos: ' . implode(', ', $mismatches) . '.');
        }

        // Confere também as operações/CFOPs, inclusive linhas desmarcadas.
        if (function_exists('fiscal_tax_profile_operation_cfops')) {
          $postedPresent = (array)($_POST['profile_operation_present'] ?? []);
          if ($postedPresent) {
            $savedOps = fiscal_tax_profile_operation_cfops($pdo, $taxCompanyId, $id, true);
            $savedById = [];
            foreach ($savedOps as $row) $savedById[(int)($row['operation_id'] ?? 0)] = $row;
            foreach (array_keys($postedPresent) as $rawOpId) {
              $opId = (int)$rawOpId;
              if ($opId <= 0) continue;
              $row = $savedById[$opId] ?? null;
              if (!$row) throw new RuntimeException('A operação fiscal #' . $opId . ' não foi localizada após a gravação.');
              $expectedEnabled = !empty($_POST['profile_operation_enabled'][$opId]) ? 1 : 0;
              if ((int)($row['enabled'] ?? 0) !== $expectedEnabled) throw new RuntimeException('O campo Usar da operação fiscal #' . $opId . ' não foi gravado corretamente.');
              foreach ([
                'cfop_in_state' => 'profile_cfop_in_state',
                'cfop_in_interstate' => 'profile_cfop_in_interstate',
                'cfop_out_state' => 'profile_cfop_out_state',
                'cfop_out_interstate' => 'profile_cfop_out_interstate',
              ] as $dbField => $postField) {
                $expectedCfop = fiscal_cfop_digits($_POST[$postField][$opId] ?? '');
                if ((string)($row[$dbField] ?? '') !== $expectedCfop) throw new RuntimeException('O CFOP da operação fiscal #' . $opId . ' não foi gravado corretamente.');
              }
              $expectedNote = trim((string)($_POST['profile_operation_notes'][$opId] ?? ''));
              if (trim((string)($row['notes'] ?? '')) !== $expectedNote) throw new RuntimeException('A observação da operação fiscal #' . $opId . ' não foi gravada corretamente.');
            }
          }
        }

        if ($startedTransaction && $pdo->inTransaction()) $pdo->commit();
      } catch (Throwable $inner) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $inner;
      }

      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_tributacoes.php?ok=' . urlencode('Tributação salva e conferida com sucesso. Operações/CFOPs sincronizadas com os produtos vinculados.')) : ('fiscal_tributacoes.php?ok=' . urlencode('Tributação salva e conferida com sucesso. Operações/CFOPs sincronizadas com os produtos vinculados.'))));
      exit;
    } catch (Throwable $e) { $err = 'Erro ao salvar: ' . $e->getMessage(); }
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$all = fiscal_tax_profiles($pdo, $taxCompanyId, false);
if ($q !== '') {
  $lq = mb_strtolower($q);
  $all = array_values(array_filter($all, fn($p) => str_contains(mb_strtolower((string)($p['name'] ?? '')), $lq) || str_contains(mb_strtolower((string)($p['code'] ?? '')), $lq) || str_contains(mb_strtolower((string)($p['tax_mode'] ?? '')), $lq)));
}
if ($status !== '') $all = array_values(array_filter($all, fn($p) => (int)($p['is_active'] ?? 0) === (int)$status));
$ibsCbsClasses = fiscal_search_ibs_cbs_classifications($pdo, $taxCompanyId, '', 250);
$serviceCodes = fiscal_search_service_codes($pdo, $taxCompanyId, '', 250);
$fiscalOperations = function_exists('fiscal_operations') ? fiscal_operations($pdo, $taxCompanyId, '', true) : [];
$profileOperationCfops = function_exists('fiscal_tax_profile_operation_cfops') ? fiscal_tax_profile_operation_cfops($pdo, $taxCompanyId, (int)($current['id'] ?? 0), true) : [];

fiscal_admin_start('Fiscal - Tributações', 'fiscal_tributacoes');
$taxPageTitle = $taxCrossContext && $taxCompanyName !== '' ? ('Tributações · ' . $taxCompanyName) : 'Tributações';
fiscal_page_header($taxPageTitle, 'Consulte e configure padrões fiscais que serão carregados automaticamente na aba Fiscal do produto.', [
  ['label' => 'Exportar tributações', 'href' => (function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_tributacoes.php?action=export_tributacoes') : 'fiscal_tributacoes.php?action=export_tributacoes'), 'class' => 'btn'],
  ['label' => 'Importar tributações', 'href' => (function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_tributacoes.php?import=1') : 'fiscal_tributacoes.php?import=1'), 'class' => 'btn'],
  ['label' => '+ Nova tributação', 'href' => 'fiscal_tributacoes.php?new=1', 'class' => 'btn btn-primary'],
]);
fiscal_tabs('fiscal_tributacoes');
fiscal_flash();
if ($taxCrossContext): ?>
  <div class="msg ok"><strong>V8.2 · Cadastro Multiempresa:</strong> tributações e operações fiscais cadastrais estão operando em <strong><?= fiscal_h($taxCompanyName !== '' ? $taxCompanyName : ('Empresa #' . $taxCompanyId)) ?></strong>. Emissão, entradas/saídas, Estoque, Financeiro operacional e PDV continuam no fluxo legado.</div>
<?php endif;
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <form method="get" class="fiscal-form fiscal-form-grid" style="align-items:end">
    <div class="row span-2"><label>Pesquisar</label><input class="field" name="q" value="<?= fiscal_h($q) ?>" placeholder="Código, nome ou tipo de tributação"></div>
    <div class="row"><label>Status</label><select class="field" name="status"><option value="">Todos</option><option value="1" <?= $status==='1'?'selected':'' ?>>Ativas</option><option value="0" <?= $status==='0'?'selected':'' ?>>Inativas</option></select></div>
    <div class="row fiscal-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="fiscal_tributacoes.php">Limpar</a></div>
  </form>
</div>
<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Tributações cadastradas</h2><span class="fiscal-list-count"><?= count($all) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Código</th><th>Nome</th><th>ICMS/CSOSN</th><th>ISS/NFS-e</th><th>IBS/CBS</th><th>Status</th><th>Ações</th></tr></thead><tbody>
    <?php if (!$all): ?><tr><td colspan="7" style="text-align:center;color:#64748b">Nenhuma tributação cadastrada.</td></tr><?php endif; ?>
    <?php foreach ($all as $p): ?>
      <tr>
        <td data-label="Código"><?= fiscal_h($p['code'] ?? '') ?></td>
        <td data-label="Nome" class="primary"><strong><?= fiscal_h($p['name'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h(fiscal_normalize_item_type($p['profile_type'] ?? 'PRODUCT') === 'SERVICE' ? 'SERVIÇO · ' . ($p['tax_mode'] ?? '') : 'PRODUTO · ' . ($p['tax_mode'] ?? '')) ?></div></td>
        <td data-label="ICMS/CSOSN">CST <?= fiscal_h($p['icms_cst'] ?? '') ?> · CSOSN <?= fiscal_h($p['icms_csosn'] ?? '') ?> · <?= fiscal_h($p['icms_rate'] ?? '0') ?>%</td>
        <td data-label="ISS/NFS-e"><?= (($p['tax_mode'] ?? '') === 'ISS') ? ('LC ' . fiscal_h($p['lc116_service_code'] ?? '') . ' · ISS ' . fiscal_h($p['iss_rate'] ?? '0') . '%') : ('PIS ' . fiscal_h($p['pis_out_cst'] ?? '') . ' · COFINS ' . fiscal_h($p['cofins_out_cst'] ?? '')) ?></td>
        <td data-label="IBS/CBS"><?= fiscal_h($p['ibs_cbs_classification_code'] ?? '') ?> · CBS <?= fiscal_h($p['cbs_rate'] ?? '0') ?>%</td>
        <td data-label="Status"><span class="fiscal-badge <?= !empty($p['is_active']) ? '' : 'off' ?>"><?= !empty($p['is_active']) ? 'Ativa' : 'Inativa' ?></span></td>
        <td data-label="Ações"><a class="btn btn-sm" href="fiscal_tributacoes.php?id=<?= (int)$p['id'] ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php if (isset($_GET['import'])): ?>
<div class="fiscal-modal" role="dialog" aria-modal="true"><div class="fiscal-modal-dialog">
  <div class="fiscal-modal-head"><div><h2>Importar tributações</h2><p class="fiscal-muted">Arquivo JSON exportado pelo Nexa. Perfis com mesmo código e tipo serão atualizados na empresa atual; os demais serão incluídos.</p></div><a class="fiscal-modal-close" href="fiscal_tributacoes.php">×</a></div>
  <form class="fiscal-form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import_tributacoes">
    <input type="hidden" name="transfer_csrf" value="<?= fiscal_h($_SESSION['nexa_tax_transfer_csrf']) ?>">
    <div class="row"><label>Arquivo de tributações (.json)</label><input class="field" type="file" name="tributacoes_json" accept=".json,application/json" required></div>
    <div class="fiscal-form-actions"><a class="btn" href="fiscal_tributacoes.php">Cancelar</a><button class="btn btn-primary" type="submit" onclick="return confirm('Importar e atualizar regras fiscais com mesmo código? Revise os dados antes de usar em produção.')">Importar tributações</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php if ($showModal): ?>
<div class="fiscal-modal" role="dialog" aria-modal="true"><div class="fiscal-modal-dialog fiscal-modal-wide">
  <div class="fiscal-modal-head"><div><h2><?= ((int)$current['id'] > 0) ? 'Editar tributação' : 'Nova tributação' ?></h2><p class="fiscal-muted">Configure os dados fiscais padrão. O produto poderá herdar esses dados e ainda receber ajustes individuais.</p></div><a class="fiscal-modal-close" href="fiscal_tributacoes.php">×</a></div>
  <form method="post" class="fiscal-form">
    <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
    <div class="fiscal-section">
      <h3>Identificação</h3>
      <div class="fiscal-form-grid">
        <div class="row"><label>Código</label><input class="field" name="code" value="<?= fiscal_h($current['code']) ?>" placeholder="Ex: 1"></div>
        <div class="row span-2"><label>Nome *</label><input class="field" name="name" value="<?= fiscal_h($current['name']) ?>" placeholder="Ex: TRIBUTAÇÃO NORMAL" required></div>
        <label class="fiscal-checkline"><input type="checkbox" name="is_active" <?= ((int)$current['is_active'] === 1) ? 'checked' : '' ?>> Ativa</label>
        <div class="row"><label>Tipo da regra</label><select class="field" name="profile_type" id="fiscalProfileType"><option value="PRODUCT" <?= fiscal_normalize_item_type($current['profile_type'] ?? 'PRODUCT') === 'PRODUCT' ? 'selected' : '' ?>>Produto / mercadoria</option><option value="SERVICE" <?= fiscal_normalize_item_type($current['profile_type'] ?? 'PRODUCT') === 'SERVICE' ? 'selected' : '' ?>>Serviço / NFS-e</option></select></div>
        <div class="row"><label>Tributação</label><select class="field" name="tax_mode" id="fiscalTaxMode"><option value="TRIBUTADO" <?= $current['tax_mode']==='TRIBUTADO'?'selected':'' ?>>Tributado</option><option value="NAO_TRIBUTADO" <?= $current['tax_mode']==='NAO_TRIBUTADO'?'selected':'' ?>>Não tributado</option><option value="SUBSTITUICAO" <?= $current['tax_mode']==='SUBSTITUICAO'?'selected':'' ?>>Substituição</option><option value="ISENTO" <?= $current['tax_mode']==='ISENTO'?'selected':'' ?>>Isento</option><option value="ISS" <?= $current['tax_mode']==='ISS'?'selected':'' ?>>ISS / NFS-e</option></select></div>
        <datalist id="fiscalIbsCbsClassList">
          <?php foreach (($ibsCbsClasses ?? []) as $cl): ?>
            <option value="<?= fiscal_h($cl['classification_code'] ?? '') ?>"><?= fiscal_h(mb_strimwidth((string)($cl['description'] ?? ''), 0, 120, '...')) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
    </div>

    <div class="fiscal-section" id="fiscalIssSection">
      <h3>ISS / NFS-e</h3>
      <p class="fiscal-muted" style="margin-top:-6px">Use esta área para regras de prestação de serviços e futura emissão de NFS-e.</p>
      <div class="fiscal-form-grid">
        <div class="row"><label>Código NBS (9 dígitos)</label><input class="field" name="nbs_code" value="<?= fiscal_h($current['nbs_code']) ?>" placeholder="Ex: 120014000" inputmode="numeric" maxlength="20"></div>
        <div class="row span-2"><label>Descrição NBS</label><input class="field" name="nbs_description" value="<?= fiscal_h($current['nbs_description']) ?>" placeholder="Descrição da NBS"></div>
        <div class="row"><label>Código LC 116/03</label><input class="field" name="lc116_service_code" list="fiscalServiceCodeList" value="<?= fiscal_h($current['lc116_service_code']) ?>" placeholder="Ex: 0107"></div>
        <div class="row span-2"><label>Descrição do serviço</label><input class="field" name="lc116_service_description" value="<?= fiscal_h($current['lc116_service_description']) ?>" placeholder="Descrição da lista de serviços"></div>
        <div class="row"><label>Alíquota ISS %</label><input class="field" name="iss_rate" value="<?= fiscal_h($current['iss_rate']) ?>" inputmode="decimal"></div>
        <label class="fiscal-checkline"><input type="checkbox" name="iss_withheld" <?= !empty($current['iss_withheld']) ? 'checked' : '' ?>> ISS retido pelo tomador</label>
        <div class="row span-2"><label>Retenção PIS/COFINS/CSLL</label><select class="field" name="pis_cofins_retention_type"><option value="" <?= trim((string)$current['pis_cofins_retention_type'])===''?'selected':'' ?>>Não informar</option><?php foreach (['0'=>'PIS/COFINS/CSLL não retidos','1'=>'PIS/COFINS retidos','2'=>'PIS/COFINS não retidos','3'=>'PIS/COFINS/CSLL retidos','4'=>'PIS/COFINS retidos, CSLL não retida','5'=>'PIS retido, COFINS/CSLL não retidos','6'=>'COFINS retido, PIS/CSLL não retidos','7'=>'PIS não retido, COFINS/CSLL retidos','8'=>'PIS/COFINS não retidos, CSLL retida','9'=>'COFINS não retida, PIS/CSLL retidos'] as $retCode=>$retLabel): ?><option value="<?= $retCode ?>" <?= (string)$current['pis_cofins_retention_type']===(string)$retCode?'selected':'' ?>><?= fiscal_h($retCode . ' - ' . $retLabel) ?></option><?php endforeach; ?></select></div>
        <div class="row"><label>Alíquota IRRF %</label><input class="field" name="irrf_rate" value="<?= fiscal_h($current['irrf_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>Alíquota CSLL %</label><input class="field" name="csll_rate" value="<?= fiscal_h($current['csll_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>Alíquota INSS %</label><input class="field" name="inss_rate" value="<?= fiscal_h($current['inss_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>Cód. Tributação no Município</label><input class="field" name="municipal_tax_code" value="<?= fiscal_h($current['municipal_tax_code']) ?>" placeholder="Ex: 002"></div>
        <div class="row"><label>CNAE / cIntContrib Pronim</label><input class="field" name="cnae" value="<?= fiscal_h($current['cnae']) ?>" placeholder="Ex: 6209100"><small style="display:block;margin-top:4px;color:#64748b">Usado como cIntContrib no perfil Itabira quando o código nacional possuir mais de uma atividade municipal.</small></div>
        <label class="fiscal-checkline"><input type="checkbox" name="fiscal_incentive" <?= ((int)($current['fiscal_incentive'] ?? 0) === 1) ? 'checked' : '' ?>> Incentivo fiscal</label>
      </div>
      <datalist id="fiscalServiceCodeList">
        <?php foreach (($serviceCodes ?? []) as $sc): ?>
          <option value="<?= fiscal_h($sc['code'] ?? '') ?>"><?= fiscal_h(mb_strimwidth((string)($sc['description'] ?? ''), 0, 140, '...')) ?></option>
        <?php endforeach; ?>
      </datalist>
      <div class="fiscal-section" style="background:#fff;margin-top:12px">
        <h3>NFS-e Municipal</h3>
        <div class="fiscal-form-grid">
          <div class="row"><label>Tipo de tributação</label><input class="field" name="nfse_municipal_tax_type" value="<?= fiscal_h($current['nfse_municipal_tax_type']) ?>" placeholder="Ex: Tributável dentro do Município"></div>
          <div class="row"><label>Natureza de tributação</label><input class="field" name="nfse_municipal_tax_nature" value="<?= fiscal_h($current['nfse_municipal_tax_nature']) ?>" placeholder="Ex: Simples Nacional"></div>
          <div class="row"><label>Exigibilidade do ISS</label><input class="field" name="nfse_municipal_iss_exigibility" value="<?= fiscal_h($current['nfse_municipal_iss_exigibility']) ?>" placeholder="Ex: Exigível"></div>
          <div class="row"><label>Regime especial de tributação</label><input class="field" name="nfse_municipal_special_regime" value="<?= fiscal_h($current['nfse_municipal_special_regime']) ?>" placeholder="Ex: Tributação Normal (Simples Nacional)"></div>
        </div>
      </div>
      <div class="fiscal-section" style="background:#fff;margin-top:12px">
        <h3>NFS-e Nacional</h3>
        <div class="fiscal-form-grid">
          <div class="row"><label>Tipo de tributação</label><input class="field" name="nfse_national_tax_type" value="<?= fiscal_h($current['nfse_national_tax_type']) ?>" placeholder="Ex: Operação tributável"></div>
          <div class="row"><label>Cód. Trib. Nacional</label><input class="field" name="nfse_national_tax_code" value="<?= fiscal_h($current['nfse_national_tax_code']) ?>" placeholder="Ex: 010701"></div>
          <div class="row"><label>Cód. Indicador Operação</label><input class="field" name="nfse_national_operation_indicator" value="<?= fiscal_h($current['nfse_national_operation_indicator']) ?>" placeholder="Ex: 100301"></div>
          <div class="row"><label>Regime de apuração Simples Nacional</label><input class="field" name="nfse_simples_tax_regime" list="nfse-sn-regimes" value="<?= fiscal_h($current['nfse_simples_tax_regime']) ?>" placeholder="1 - Tributos federais e municipal pelo Simples Nacional"><datalist id="nfse-sn-regimes"><option value="1 - Tributos federais e municipal pelo Simples Nacional"><option value="2 - Tributos federais pelo Simples Nacional e ISSQN fora do Simples"><option value="3 - Tributos federais e municipal fora do Simples Nacional"></datalist><small class="fiscal-muted">ME/EPP que paga o ISS no DAS: use a opção 1 (a mais comum).</small></div>
          <div class="row"><label>Tipo operação fornecimento/recebimento</label><input class="field" name="nfse_operation_payment_type" value="<?= fiscal_h($current['nfse_operation_payment_type']) ?>"></div>
          <div class="row"><label>Regime especial RTC</label><input class="field" name="nfse_rtc_special_regime" value="<?= fiscal_h($current['nfse_rtc_special_regime']) ?>" placeholder="Ex: Nenhum"></div>
        </div>
      </div>
      <div class="fiscal-section" style="background:#fff;margin-top:12px">
        <h3>Benefício municipal</h3>
        <div class="fiscal-form-grid">
          <div class="row"><label>Tipo de benefício</label><input class="field" name="municipal_benefit_type" value="<?= fiscal_h($current['municipal_benefit_type']) ?>"></div>
          <div class="row"><label>Alíquota diferenciada %</label><input class="field" name="municipal_differentiated_rate" value="<?= fiscal_h($current['municipal_differentiated_rate']) ?>" inputmode="decimal"></div>
          <div class="row"><label>Redução base cálculo %</label><input class="field" name="municipal_reduction_base_percent" value="<?= fiscal_h($current['municipal_reduction_base_percent']) ?>" inputmode="decimal"></div>
          <div class="row"><label>Número do benefício</label><input class="field" name="municipal_benefit_number" value="<?= fiscal_h($current['municipal_benefit_number']) ?>"></div>
        </div>
      </div>
    </div>

    <div class="fiscal-section fiscal-tax-section">
      <h3>ICMS / CSOSN</h3>
      <p class="fiscal-muted fiscal-section-note">Dados principais de ICMS carregados automaticamente no produto quando esta tributação for selecionada.</p>
      <div class="fiscal-form-grid">
        <div class="row"><label>CSOSN</label><input class="field" name="icms_csosn" value="<?= fiscal_h($current['icms_csosn']) ?>"></div>
        <div class="row"><label>CST ICMS</label><input class="field" name="icms_cst" value="<?= fiscal_h($current['icms_cst']) ?>"></div>
        <div class="row"><label>Alíquota ICMS %</label><input class="field" name="icms_rate" value="<?= fiscal_h($current['icms_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>Redução ICMS %</label><input class="field" name="icms_reduction_rate" value="<?= fiscal_h($current['icms_reduction_rate']) ?>" inputmode="decimal"></div>
      </div>
    </div>

    <div class="fiscal-section fiscal-tax-section">
      <h3>ICMS-ST - Substituição Tributária</h3>
      <p class="fiscal-muted fiscal-section-note">Campo mantido com o mesmo funcionamento atual, apenas separado para facilitar a conferência.</p>
      <div class="fiscal-form-grid">
        <div class="row"><label>ICMS ST %</label><input class="field" name="icms_st_rate" value="<?= fiscal_h($current['icms_st_rate']) ?>" inputmode="decimal"></div>
      </div>
    </div>

    <div class="fiscal-section fiscal-tax-section">
      <h3>IPI</h3>
      <div class="fiscal-form-grid">
        <div class="row"><label>IPI %</label><input class="field" name="ipi_rate" value="<?= fiscal_h($current['ipi_rate'] ?? '0') ?>" inputmode="decimal"></div>
        <div class="row"><label>CST IPI</label><input class="field" name="ipi_cst" value="<?= fiscal_h($current['ipi_cst'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="fiscal-section fiscal-tax-section">
      <h3>PIS / COFINS - Entrada</h3>
      <div class="fiscal-form-grid">
        <div class="row"><label>Entrada PIS %</label><input class="field" name="pis_in_rate" value="<?= fiscal_h($current['pis_in_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>CST PIS entrada</label><input class="field" name="pis_in_cst" value="<?= fiscal_h($current['pis_in_cst']) ?>"></div>
        <div class="row"><label>Entrada COFINS %</label><input class="field" name="cofins_in_rate" value="<?= fiscal_h($current['cofins_in_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>CST COFINS entrada</label><input class="field" name="cofins_in_cst" value="<?= fiscal_h($current['cofins_in_cst']) ?>"></div>
      </div>
    </div>

    <div class="fiscal-section fiscal-tax-section">
      <h3>PIS / COFINS - Saída</h3>
      <div class="fiscal-form-grid">
        <div class="row"><label>Saída PIS %</label><input class="field" name="pis_out_rate" value="<?= fiscal_h($current['pis_out_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>CST PIS saída</label><input class="field" name="pis_out_cst" value="<?= fiscal_h($current['pis_out_cst']) ?>"></div>
        <div class="row"><label>Saída COFINS %</label><input class="field" name="cofins_out_rate" value="<?= fiscal_h($current['cofins_out_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>CST COFINS saída</label><input class="field" name="cofins_out_cst" value="<?= fiscal_h($current['cofins_out_cst']) ?>"></div>
      </div>
    </div>

    <div class="fiscal-section fiscal-ibs-cbs-last" id="fiscalIbsCbsSection">
      <h3 id="fiscalIbsCbsTitle">IBS / CBS</h3>
      <p class="fiscal-muted fiscal-section-note" id="fiscalIbsCbsNote">Aplicado automaticamente ao produto ou serviço quando esta tributação for selecionada.</p>
      <div class="fiscal-form-grid">
        <div class="row"><label>CST IBS/CBS (3 dígitos)</label><input class="field" name="ibs_cbs_cst" value="<?= fiscal_h($current['ibs_cbs_cst'] ?? '') ?>" maxlength="3" inputmode="numeric" placeholder="Ex: 000"></div>
        <div class="row"><label>Código IBS/CBS</label><input class="field" id="fiscalProfileIbsCbsCode" name="ibs_cbs_classification_code" list="fiscalIbsCbsClassList" value="<?= fiscal_h($current['ibs_cbs_classification_code']) ?>" placeholder="Código oficial da classificação"></div>
        <div class="row span-2"><label>Descrição IBS/CBS</label><input class="field" id="fiscalProfileIbsCbsName" name="ibs_cbs_classification_name" value="<?= fiscal_h($current['ibs_cbs_classification_name']) ?>" placeholder="Preenchida automaticamente" readonly aria-readonly="true"></div>
        <div class="row"><label>Origem das alíquotas</label><select class="field" id="fiscalProfileIbsCbsRateMode" name="ibs_cbs_rate_mode"><option value="AUTO" <?= strtoupper((string)($current['ibs_cbs_rate_mode'] ?? 'AUTO'))==='AUTO'?'selected':'' ?>>Automática por vigência</option><option value="MANUAL" <?= strtoupper((string)($current['ibs_cbs_rate_mode'] ?? 'AUTO'))==='MANUAL'?'selected':'' ?>>Manual nesta tributação</option></select></div>
        <div class="row"><label>CBS %</label><input class="field fiscal-profile-manual-rate" name="cbs_rate" value="<?= fiscal_h($current['cbs_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>IBS UF %</label><input class="field fiscal-profile-manual-rate" name="ibs_uf_rate" value="<?= fiscal_h($current['ibs_uf_rate']) ?>" inputmode="decimal"></div>
        <div class="row"><label>IBS Município %</label><input class="field fiscal-profile-manual-rate" name="ibs_mun_rate" value="<?= fiscal_h($current['ibs_mun_rate']) ?>" inputmode="decimal"></div>
      </div>
    </div>

    <div class="fiscal-section">
      <h3>CFOPs</h3>
      <div class="fiscal-form-grid">
        <div class="row"><label>Venda dentro do Estado</label><input class="field" name="cfop_sale_state" value="<?= fiscal_h($current['cfop_sale_state']) ?>"></div>
        <div class="row span-2"><label>Descrição</label><input class="field" name="cfop_sale_state_desc" value="<?= fiscal_h($current['cfop_sale_state_desc']) ?>"></div>
        <div class="row"><label>Venda fora do Estado</label><input class="field" name="cfop_sale_interstate" value="<?= fiscal_h($current['cfop_sale_interstate']) ?>"></div>
        <div class="row span-2"><label>Descrição</label><input class="field" name="cfop_sale_interstate_desc" value="<?= fiscal_h($current['cfop_sale_interstate_desc']) ?>"></div>
        <div class="row"><label>Devolução dentro do Estado</label><input class="field" name="cfop_return_state" value="<?= fiscal_h($current['cfop_return_state']) ?>"></div>
        <div class="row span-2"><label>Descrição</label><input class="field" name="cfop_return_state_desc" value="<?= fiscal_h($current['cfop_return_state_desc']) ?>"></div>
        <div class="row"><label>Devolução fora do Estado</label><input class="field" name="cfop_return_interstate" value="<?= fiscal_h($current['cfop_return_interstate']) ?>"></div>
        <div class="row span-2"><label>Descrição</label><input class="field" name="cfop_return_interstate_desc" value="<?= fiscal_h($current['cfop_return_interstate_desc']) ?>"></div>
        <div class="row"><label>Entrada dentro do Estado</label><input class="field" name="cfop_entry_state" value="<?= fiscal_h($current['cfop_entry_state']) ?>"></div>
        <div class="row span-2"><label>Descrição</label><input class="field" name="cfop_entry_state_desc" value="<?= fiscal_h($current['cfop_entry_state_desc']) ?>"></div>
        <div class="row"><label>Entrada fora do Estado</label><input class="field" name="cfop_entry_interstate" value="<?= fiscal_h($current['cfop_entry_interstate']) ?>"></div>
        <div class="row span-2"><label>Descrição</label><input class="field" name="cfop_entry_interstate_desc" value="<?= fiscal_h($current['cfop_entry_interstate_desc']) ?>"></div>
      </div>
    </div>


    <div class="fiscal-section">
      <div class="fiscal-list-head">
        <div>
          <h3>Operações fiscais e CFOPs complementares</h3>
          <div class="fiscal-muted">Marque as operações que esta tributação deve disponibilizar nos produtos. Ao salvar, os produtos que usam esta tributação recebem automaticamente estas operações/CFOPs, preservando personalizações individuais.</div>
        </div>
        <?php if ($canManageFiscalOperations): ?><a class="btn btn-sm" href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_operacoes.php?new=1') : 'fiscal_operacoes.php?new=1') ?>" target="_blank">+ Cadastrar operação</a><?php else: ?><span class="fiscal-muted" title="Seu perfil na empresa selecionada não possui permissão para cadastrar operações fiscais.">Sem permissão para cadastrar operação</span><?php endif; ?>
      </div>
      <?php if (!$profileOperationCfops): ?>
        <div class="msg err">Nenhuma operação fiscal ativa cadastrada. Cadastre as operações para alimentar vendas, entradas, SPED e Sintegra.</div>
      <?php else: ?>
        <div class="table-wrap"><table class="fiscal-table fiscal-grid-table fiscal-opcfop-table"><thead><tr><th>Usar</th><th>Operação</th><th>Entrada UF</th><th>Entrada fora UF</th><th>Saída UF</th><th>Saída fora UF</th><th>Observação</th></tr></thead><tbody>
        <?php foreach ($profileOperationCfops as $opMap): $opId = (int)($opMap['operation_id'] ?? 0); if ($opId <= 0) continue; ?>
          <tr>
            <td data-label="Usar"><input type="hidden" name="profile_operation_present[<?= $opId ?>]" value="1"><label class="fiscal-checkline" style="margin:0"><input type="checkbox" name="profile_operation_enabled[<?= $opId ?>]" value="1" <?= !empty($opMap['enabled']) ? 'checked' : '' ?>> Sim</label></td>
            <td data-label="Operação" class="primary"><strong><?= fiscal_h(trim((string)($opMap['operation_code'] ?? '') . ' - ' . (string)($opMap['operation_name'] ?? ''), ' -')) ?></strong><div class="fiscal-muted"><?= fiscal_h(($opMap['direction'] ?? '') . ' · ' . ($opMap['operation_type'] ?? '')) ?></div></td>
            <td data-label="Entrada UF"><input class="field" name="profile_cfop_in_state[<?= $opId ?>]" value="<?= fiscal_h($opMap['cfop_in_state'] ?? '') ?>" maxlength="4" inputmode="numeric"></td>
            <td data-label="Entrada fora UF"><input class="field" name="profile_cfop_in_interstate[<?= $opId ?>]" value="<?= fiscal_h($opMap['cfop_in_interstate'] ?? '') ?>" maxlength="4" inputmode="numeric"></td>
            <td data-label="Saída UF"><input class="field" name="profile_cfop_out_state[<?= $opId ?>]" value="<?= fiscal_h($opMap['cfop_out_state'] ?? '') ?>" maxlength="4" inputmode="numeric"></td>
            <td data-label="Saída fora UF"><input class="field" name="profile_cfop_out_interstate[<?= $opId ?>]" value="<?= fiscal_h($opMap['cfop_out_interstate'] ?? '') ?>" maxlength="4" inputmode="numeric"></td>
            <td data-label="Observação"><input class="field" name="profile_operation_notes[<?= $opId ?>]" value="<?= fiscal_h($opMap['notes'] ?? '') ?>" placeholder="Ex.: brinde, bonificação, remessa..."></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>

    <div class="row"><label>Observações internas</label><textarea class="field" name="notes" rows="3"><?= fiscal_h($current['notes']) ?></textarea></div>
    <div class="fiscal-form-actions"><a class="btn" href="fiscal_tributacoes.php">Cancelar</a><button class="btn btn-primary" type="submit">Salvar tributação</button></div>
  </form>
</div></div>

<style>
.fiscal-opcfop-table input.field{min-width:88px}.fiscal-opcfop-table td{vertical-align:top}@media(max-width:680px){.fiscal-opcfop-table input.field{min-width:0}}
</style>
<?php if ($showModal): ?>
<script>
(function(){
  const mode = document.getElementById('fiscalTaxMode');
  const type = document.getElementById('fiscalProfileType');
  const iss = document.getElementById('fiscalIssSection');
  const productSections = document.querySelectorAll('.fiscal-tax-section');
  const ibsTitle = document.getElementById('fiscalIbsCbsTitle');
  const ibsNote = document.getElementById('fiscalIbsCbsNote');
  function refresh(){
    if(!mode || !iss || !type) return;
    const isService = type.value === 'SERVICE';
    if(isService && mode.value !== 'ISS') mode.value = 'ISS';
    iss.style.display = isService || mode.value === 'ISS' ? '' : 'none';
    productSections.forEach(section => section.style.display = isService ? 'none' : '');
    if (ibsTitle) ibsTitle.textContent = isService ? 'IBS / CBS do Serviço' : 'IBS / CBS';
    if (ibsNote) ibsNote.textContent = isService
      ? 'CST, classificação e alíquotas da Reforma Tributária aplicadas automaticamente ao serviço quando esta tributação for selecionada.'
      : 'CST, classificação e alíquotas da Reforma Tributária aplicadas automaticamente ao produto quando esta tributação for selecionada.';
  }
  mode && mode.addEventListener('change', refresh);
  type && type.addEventListener('change', refresh);
  refresh();

  const ibsCbsClasses = <?= json_encode(array_values($ibsCbsClasses ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const ibsCode = document.getElementById('fiscalProfileIbsCbsCode');
  const ibsName = document.getElementById('fiscalProfileIbsCbsName');
  const rateMode = document.getElementById('fiscalProfileIbsCbsRateMode');
  function syncIbsDescription(clearMissing){
    if(!ibsCode || !ibsName) return;
    const code = String(ibsCode.value || '').replace(/\D+/g,'');
    if(!code){ ibsName.value=''; return; }
    const found = ibsCbsClasses.find(item => String(item.classification_code || '').replace(/\D+/g,'') === code);
    if(found) ibsName.value = String(found.description || '');
    else if(clearMissing) ibsName.value = '';
  }
  if(ibsCode){
    ['input','change','blur'].forEach(eventName => ibsCode.addEventListener(eventName, () => syncIbsDescription(true)));
    syncIbsDescription(false);
  }
  document.querySelectorAll('.fiscal-profile-manual-rate').forEach(input => {
    input.addEventListener('input', function(){ if(rateMode) rateMode.value='MANUAL'; });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php fiscal_admin_end(); ?>
