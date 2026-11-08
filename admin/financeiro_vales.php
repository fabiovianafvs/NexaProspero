<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/voucher_module.php';

$cid = function_exists('finance_operational_company_id') ? (int)finance_operational_company_id($pdo) : (int)COMPANY_ID;

// V11.52 - emissão manual isolada: sem alteração da utilização no PDV.
$voucherCrossContext = function_exists('finance_multi_company_cross_context')
  && finance_multi_company_cross_context($pdo);
$voucherUserId = (int)(auth_user_id() ?? 0);
$voucherCanCreate = !$voucherCrossContext && $voucherUserId > 0
  && (!function_exists('access_has_permission')
      || access_has_permission($pdo, $cid, 'financeiro_movimento_manual'));
$voucherCanCredit = $voucherCanCreate
  && (!function_exists('access_has_permission')
      || access_has_permission($pdo, $cid, 'gestao_caixa'));
if (session_status() === PHP_SESSION_ACTIVE) {
  if (empty($_SESSION['nexa_manual_voucher_csrf'])) $_SESSION['nexa_manual_voucher_csrf'] = bin2hex(random_bytes(24));
  if (empty($_SESSION['nexa_manual_voucher_nonce'])) $_SESSION['nexa_manual_voucher_nonce'] = bin2hex(random_bytes(16));
}
$voucherCsrf = (string)($_SESSION['nexa_manual_voucher_csrf'] ?? '');
$voucherNonce = (string)($_SESSION['nexa_manual_voucher_nonce'] ?? '');
$voucherNew = isset($_GET['new'])
  || ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_manual_voucher');
$voucherError = '';
$voucherPeople = [];
$voucherCreditCharts = [];
$voucherCashSession = null;
try {
  if ($voucherCanCreate && $voucherNew) {
    $voucherPeople = finance_people_options($pdo, $cid, 'customer');
    if ($voucherCanCredit) {
      $voucherCreditCharts = finance_chart_options($pdo, $cid, 'CREDITO');
      $voucherCashSession = gestao_open_cash_session($pdo, $cid, $voucherUserId);
    }
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_manual_voucher') {
    $voucherNew = true;
    if (!$voucherCanCreate) throw new RuntimeException('Seu perfil não está autorizado a emitir vales manuais na empresa ativa. A criação via F11 permanece desabilitada.');
    if ($voucherCsrf === '' || !hash_equals($voucherCsrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão de segurança expirada. Atualize a página.');
    if ($voucherNonce === '' || !hash_equals($voucherNonce, (string)($_POST['nonce'] ?? ''))) throw new RuntimeException('Este formulário já foi processado ou expirou. Atualize a página antes de tentar novamente.');
    voucher_assert_ready($pdo);
    $mode = (string)($_POST['mode'] ?? '');
    if (!in_array($mode, ['AVULSO','CREDITO'], true)) throw new RuntimeException('Escolha Vale Avulso ou Vale (Crédito).');
    if ($mode === 'CREDITO' && !$voucherCanCredit) throw new RuntimeException('É necessária a permissão de Caixa para registrar o Vale (Crédito).');
    $personId = (int)($_POST['person_id'] ?? 0);
    if (!finance_validate_person_role($pdo, $cid, $personId, 'customer')) throw new RuntimeException('Selecione um cliente ativo da empresa.');
    $amount = round(finance_money_in($_POST['amount'] ?? 0), 2);
    if ($amount <= 0 || $amount > 99999999) throw new RuntimeException('Informe um valor maior que zero e dentro do limite permitido.');
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (strlen($notes) < 8) throw new RuntimeException('Informe uma justificativa de pelo menos oito caracteres.');
    $chartId = (int)($_POST['chart_account_id'] ?? 0);
    $sourceId = null;
    $voucherSource = $mode === 'CREDITO' ? 'MANUAL_CREDITO' : 'MANUAL_AVULSO';
    $pdo->beginTransaction();
    try {
      if ($mode === 'CREDITO') {
        $stSession = $pdo->prepare("SELECT * FROM gestao_cash_sessions WHERE company_id=? AND user_id=? AND status='ABERTO' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $stSession->execute([$cid,$voucherUserId]);
        $session = $stSession->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$session) throw new RuntimeException('Para Vale (Crédito), abra primeiro o caixa deste usuário.');
        $bankId = (int)($session['bank_account_id'] ?? 0);
        if ($bankId <= 0 || !finance_validate_bank_account($pdo, $cid, $bankId)) throw new RuntimeException('O caixa aberto precisa estar vinculado a uma conta ativa.');
        if (function_exists('gestao_pdv_assert_cash_account')) gestao_pdv_assert_cash_account($pdo,$cid,$bankId,'Sem permissão para este caixa.');
        $stChart = $pdo->prepare("SELECT id FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 AND is_analytic=1 AND movement_type IN ('CREDITO','AMBOS') LIMIT 1");
        $stChart->execute([$cid,$chartId]);
        if (!$stChart->fetchColumn()) throw new RuntimeException('Selecione um plano de contas analítico de crédito para adiantamento de cliente.');
        $desc = 'Adiantamento de cliente - Vale (Crédito)';
        $operationNotes = 'Recebimento em dinheiro para vale do cliente #'.$personId.' · '.$notes;
        // SUPRIMENTO soma uma única vez no saldo do caixa. A utilização do vale no PDV
        // continua sem afetar o caixa (forma de pagamento VALE: affects_cash=0).
        $sourceId = gestao_create_cash_operation($pdo,$cid,$voucherUserId,(int)$session['id'],'SUPRIMENTO',$amount,$bankId,$chartId,null,$desc,$operationNotes);
        if ($sourceId <= 0) throw new RuntimeException('Não foi possível registrar a entrada no caixa.');
        // Vínculo opcional do movimento gerado à pessoa do adiantamento.
        $pdo->prepare("UPDATE finance_movements m JOIN gestao_cash_operations o ON o.movement_id=m.id AND o.company_id=m.company_id SET m.person_id=? WHERE m.company_id=? AND o.id=? AND o.cash_session_id=?")
          ->execute([$personId,$cid,$sourceId,(int)$session['id']]);
      }
      $voucherId = voucher_issue($pdo,$cid,$personId,$amount,$voucherSource,$sourceId,$voucherUserId,'',$notes);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    // Gira o nonce após o commit: impede reenvio acidental do mesmo formulário.
    $_SESSION['nexa_manual_voucher_nonce'] = bin2hex(random_bytes(16));
    finance_redirect('financeiro_vales.php?ok='.rawurlencode('Vale '.voucher_code($voucherId).' emitido com sucesso'.($mode==='CREDITO'?' e recebido no caixa.':'.')));
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $voucherError = $e->getMessage();
}

// V11.53 - preferências de grid separadas por usuário e empresa, no padrão de Produtos.
$voucherGridColumns = [
  'vale'=>'Vale', 'cliente'=>'Cliente', 'origem'=>'Origem', 'emissao'=>'Emissão',
  'original'=>'Original', 'utilizado'=>'Utilizado', 'saldo'=>'Saldo', 'status'=>'Status',
];
$voucherGridUser = (int)(function_exists('auth_user_id') ? (auth_user_id() ?? 0) : 0);
$voucherGridKey = 'ui_vouchers_grid_user_'.$voucherGridUser;
$voucherGridCsrf = (string)($_SESSION['nexa_voucher_grid_csrf'] ?? '');
if ($voucherGridCsrf === '') {
  $voucherGridCsrf = bin2hex(random_bytes(24));
  $_SESSION['nexa_voucher_grid_csrf'] = $voucherGridCsrf;
}
function voucher_grid_normalize($value): array {
  $allowed = ['vale','cliente','origem','emissao','original','utilizado','saldo','status'];
  if (!is_array($value)) return ['visible'=>$allowed,'order'=>$allowed,'widths'=>[]];
  $clean = static function ($arr) use ($allowed): array {
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $col) if (is_string($col) && in_array($col,$allowed,true) && !in_array($col,$out,true)) $out[]=$col;
    return $out;
  };
  $visible = $clean($value['visible'] ?? $allowed);
  if (!$visible) $visible = $allowed;
  $order = $clean($value['order'] ?? $allowed);
  $order = array_merge($order,array_values(array_diff($allowed,$order)));
  $widths = [];
  foreach ((array)($value['widths'] ?? []) as $col=>$n) {
    if (in_array($col,$allowed,true) && is_numeric($n) && (int)$n>=75 && (int)$n<=600) $widths[$col]=(int)$n;
  }
  return ['visible'=>$visible,'order'=>$order,'widths'=>$widths];
}
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='save_voucher_grid_preferences') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($voucherGridUser<=0 || $cid<=0) throw new RuntimeException('Usuário ou empresa inválidos.');
    if (!hash_equals($voucherGridCsrf,(string)($_POST['csrf_token']??''))) throw new RuntimeException('Token de segurança inválido.');
    $decoded = json_decode((string)($_POST['preferences']??''),true);
    if (!is_array($decoded)) throw new RuntimeException('Configuração inválida.');
    $json = json_encode(voucher_grid_normalize($decoded),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false) throw new RuntimeException('Falha ao serializar preferência.');
    $st=$pdo->prepare("INSERT INTO app_config(company_id,cfg_key,cfg_json,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()");
    $st->execute([$cid,$voucherGridKey,$json]);
    echo json_encode(['ok'=>true]);
  } catch (Throwable $e) {
    http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
  }
  exit;
}
$voucherGridPrefs = voucher_grid_normalize(null);
$voucherGridHasServerPrefs = false;
try {
  if ($voucherGridUser>0 && $cid>0) {
    $st=$pdo->prepare("SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1");
    $st->execute([$cid,$voucherGridKey]);
    $decoded=json_decode((string)($st->fetchColumn()?:''),true);
    if (is_array($decoded)) { $voucherGridPrefs=voucher_grid_normalize($decoded); $voucherGridHasServerPrefs = true; }
  }
} catch(Throwable $e) { /* A consulta deve funcionar mesmo sem tabela de preferências. */ }

function voucher_grid_filter_menu(string $column,string $label,string $value): string {
  $param = 'f_'.$column;
  $str = '<details class="voucher-grid-filter'.($value!==''?' is-active':'').'" data-voucher-filter-menu><summary title="Filtrar '.finance_h($label).'" aria-label="Filtrar '.finance_h($label).'">⌕</summary>';
  $str .= '<form method="get" class="voucher-grid-popover"><strong>Filtrar '.finance_h($label).'</strong>';
  foreach ($_GET as $name=>$item) {
    if ($name===$param || $name==='new' || !is_string($name) || !preg_match('/^(q|status|ini|fim|sort|dir|f_(vale|cliente|origem|emissao|original|utilizado|saldo|status))$/',$name) || !is_scalar($item)) continue;
    $str .= '<input type="hidden" name="'.finance_h($name).'" value="'.finance_h((string)$item).'">';
  }
  $str .= '<input name="'.finance_h($param).'" value="'.finance_h($value).'" placeholder="Contém..." autocomplete="off"><div class="voucher-grid-popover-actions"><button type="submit" class="btn btn-primary">Filtrar</button><a class="btn" href="'.finance_h(voucher_grid_url([$param=>''])).'">Limpar</a></div></form></details>';
  return $str;
}
function voucher_grid_url(array $changes = []): string {
  $allowed = ['q','status','ini','fim','sort','dir','f_vale','f_cliente','f_origem','f_emissao','f_original','f_utilizado','f_saldo','f_status'];
  $params = [];
  foreach ($allowed as $key) {
    if (isset($_GET[$key]) && is_scalar($_GET[$key]) && (string)$_GET[$key]!=='') $params[$key]=(string)$_GET[$key];
  }
  foreach ($changes as $k=>$v) {
    if (!in_array($k,$allowed,true)) continue;
    if ($v===null || $v==='') unset($params[$k]); else $params[$k]=(string)$v;
  }
  return 'financeiro_vales.php'.($params?'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986):'');
}

$q = trim((string)($_GET['q'] ?? ''));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$ini = trim((string)($_GET['ini'] ?? ''));
$fim = trim((string)($_GET['fim'] ?? ''));
$rows = []; $totals = ['original'=>0.0,'used'=>0.0,'balance'=>0.0]; $err='';
$voucherGridFilters = array_fill_keys(array_keys($voucherGridColumns), '');
$voucherSort='emissao'; $voucherDir='DESC';
try {
  voucher_assert_ready($pdo);
  $where=['v.company_id=?']; $params=[$cid];
  if ($q !== '') {
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $where[]='(v.code LIKE ? OR p.name LIKE ? OR p.document LIKE ? OR v.source_document_number LIKE ?)';
    array_push($params,$like,$like,$like,$like);
  }
  if (in_array($status,['ABERTO','PARCIAL','UTILIZADO','CANCELADO'],true)) { $where[]='v.status=?'; $params[]=$status; }
  if ($ini !== '') { $where[]='v.issue_date>=?'; $params[]=$ini; }
  if ($fim !== '') { $where[]='v.issue_date<=?'; $params[]=$fim; }
  $voucherGridFilterColumns = [
    'vale'=>'v.code','cliente'=>'p.name','origem'=>'v.source_type',
    'emissao'=>"DATE_FORMAT(v.issue_date, '%d/%m/%Y')",
    'original'=>"REPLACE(FORMAT(v.original_amount,2,'de_DE'),'.','')",
    'utilizado'=>"REPLACE(FORMAT(v.used_amount,2,'de_DE'),'.','')",
    'saldo'=>"REPLACE(FORMAT(v.balance_amount,2,'de_DE'),'.','')",
    'status'=>'v.status',
  ];
  $voucherGridFilters = [];
  foreach ($voucherGridFilterColumns as $k=>$expr) {
    $v = trim((string)($_GET['f_'.$k] ?? ''));
    $voucherGridFilters[$k] = $v;
    if ($v!=='') {
      if ($k==='emissao' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$v)) {
        $where[]='v.issue_date=?';$params[]=$v;
      } elseif (in_array($k,['original','utilizado','saldo'],true)) {
        $where[]="CAST(".(['original'=>'v.original_amount','utilizado'=>'v.used_amount','saldo'=>'v.balance_amount'][$k])." AS CHAR) LIKE ?";
        $params[]='%'.str_replace(['.',','],['', '.'],$v).'%';
      } else {
        $where[]=$expr.' LIKE ?';
        $params[]='%'.addcslashes($v, '%_').'%';
      }
    }
  }
  $voucherSortMap = [
    'vale'=>'v.code','cliente'=>'p.name','origem'=>'v.source_type','emissao'=>'v.issue_date',
    'original'=>'v.original_amount','utilizado'=>'v.used_amount','saldo'=>'v.balance_amount','status'=>'v.status'
  ];
  $voucherSort=(string)($_GET['sort']??'emissao');
  if (!isset($voucherSortMap[$voucherSort])) $voucherSort='emissao';
  $voucherDir=strtoupper((string)($_GET['dir']??'DESC'))==='ASC'?'ASC':'DESC';
  $sql="SELECT v.*,p.name person_name,p.document person_document,
              (SELECT COUNT(*) FROM finance_voucher_usages u WHERE u.company_id=v.company_id AND u.voucher_id=v.id AND u.status='ATIVO') usage_count
         FROM finance_vouchers v
         LEFT JOIN finance_people p ON p.company_id=v.company_id AND p.id=v.person_id
        WHERE ".implode(' AND ',$where)." ORDER BY ".$voucherSortMap[$voucherSort]." ".$voucherDir.",v.id DESC LIMIT 500";
  $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
  foreach($rows as $r){$totals['original']+=(float)$r['original_amount'];$totals['used']+=(float)$r['used_amount'];$totals['balance']+=(float)$r['balance_amount'];}
} catch(Throwable $e){$err=$e->getMessage();}

finance_admin_start('Financeiro - Vales','fin_vales');
finance_flash();
if($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?><?php if($voucherError !== ''): ?><div class="msg err"><?= finance_h($voucherError) ?></div><?php endif; ?>
<div class="finance-hero voucher-hero">
  <div><h1>Vales - Crédito de cliente</h1></div>
  <?php if($voucherCanCreate): ?><a class="btn btn-primary" href="financeiro_vales.php?new=1">+ Novo Vale</a><?php endif; ?>
</div>
<div class="finance-kpis voucher-kpis">
  <div class="finance-kpi"><span>Valor original</span><strong>R$ <?= finance_money_br($totals['original']) ?></strong></div>
  <div class="finance-kpi"><span>Já utilizado</span><strong>R$ <?= finance_money_br($totals['used']) ?></strong></div>
  <div class="finance-kpi"><span>Saldo em vales</span><strong>R$ <?= finance_money_br($totals['balance']) ?></strong></div>
</div>
<div class="finance-filter-card voucher-filter-card"><form method="get" class="voucher-filters" id="voucherFilters">
  <div class="voucher-filter-query"><label for="voucherQ">Pesquisar</label><input id="voucherQ" name="q" value="<?= finance_h($q) ?>" placeholder="Vale, cliente, CPF/CNPJ ou origem"></div>
  <div><label for="voucherStatus">Status</label><select id="voucherStatus" name="status"><option value="">Todos</option><?php foreach(['ABERTO'=>'Aberto','PARCIAL'=>'Parcial','UTILIZADO'=>'Utilizado','CANCELADO'=>'Cancelado'] as $k=>$v): ?><option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div><label for="voucherIni">Emissão inicial</label><input id="voucherIni" type="date" name="ini" value="<?= finance_h($ini) ?>"></div>
  <div><label for="voucherFim">Emissão final</label><input id="voucherFim" type="date" name="fim" value="<?= finance_h($fim) ?>"></div>
  <div class="voucher-filter-actions"><button type="submit" class="btn btn-primary">Buscar</button><a class="btn" href="financeiro_vales.php">Limpar</a></div>
  <?php foreach ($voucherGridFilters as $col=>$filter): if ($filter!==''): ?><input type="hidden" name="f_<?= finance_h($col) ?>" value="<?= finance_h($filter) ?>"><?php endif; endforeach; ?>
  <input type="hidden" name="sort" value="<?= finance_h($voucherSort) ?>"><input type="hidden" name="dir" value="<?= finance_h($voucherDir) ?>">
</form></div>
<div class="finance-card voucher-grid-card">
  <div class="finance-card-head voucher-grid-toolbar">
    <div><h2 class="finance-subtitle">Controle de vales</h2><span class="finance-muted"><?= count($rows) ?> registro(s) <?= count($rows)>=500?'(limite de 500 por consulta)':'' ?></span></div>
    <div class="voucher-grid-tools">
      <span class="voucher-grid-save-status" id="voucherGridSaved" aria-live="polite"></span>
      <details class="voucher-column-picker" id="voucherColumnPicker">
        <summary class="btn" aria-label="Escolher colunas do grid">Colunas <span aria-hidden="true">▾</span></summary>
        <div class="voucher-column-menu">
          <strong>Exibir colunas</strong>
          <?php foreach ($voucherGridColumns as $key=>$label): ?>
            <label><input type="checkbox" data-voucher-column-toggle value="<?= finance_h($key) ?>" checked> <?= finance_h($label) ?></label>
          <?php endforeach; ?>
          <p>Arraste o marcador no cabeçalho para mover as colunas e puxe a borda para alterar a largura. O layout fica salvo para seu usuário e empresa.</p>
        </div>
      </details>
    </div>
  </div>
  <div class="voucher-grid-scroll">
    <table class="voucher-products-grid" id="voucherProductsGrid">
      <thead><tr>
        <?php foreach ($voucherGridColumns as $col=>$label):
          $nextDir = $voucherSort===$col && $voucherDir==='ASC' ? 'DESC' : 'ASC';
          $sortArrow = $voucherSort===$col ? ($voucherDir==='ASC'?'▲':'▼') : '↕';
        ?>
          <th data-col="<?= finance_h($col) ?>">
            <div class="voucher-grid-th-inner">
              <a class="voucher-sort-link" href="<?= finance_h(voucher_grid_url(['sort'=>$col,'dir'=>$nextDir])) ?>" title="Ordenar por <?= finance_h($label) ?>"><?= finance_h($label) ?> <span><?= $sortArrow ?></span></a>
              <?= voucher_grid_filter_menu($col,$label,$voucherGridFilters[$col]) ?>
            </div>
          </th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="voucher-grid-empty">Nenhum vale encontrado para os filtros informados.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $st=(string)$r['status']; ?>
          <tr>
            <td data-col="vale" class="voucher-grid-primary"><strong><?= finance_h($r['code']) ?></strong><small><?= (int)$r['usage_count'] ?> utilização(ões)</small></td>
            <td data-col="cliente"><strong><?= finance_h($r['person_name'] ?? '') ?></strong><small><?= finance_h($r['person_document'] ?? '') ?></small></td>
            <td data-col="origem"><?= finance_h($r['source_type'] ?? '') ?><small><?= finance_h($r['source_document_number'] ?? '') ?></small></td>
            <td data-col="emissao" class="voucher-grid-date"><?= finance_date_br($r['issue_date'] ?? '') ?></td>
            <td data-col="original" class="voucher-grid-value">R$ <?= finance_money_br($r['original_amount']) ?></td>
            <td data-col="utilizado" class="voucher-grid-value">R$ <?= finance_money_br($r['used_amount']) ?></td>
            <td data-col="saldo" class="voucher-grid-value voucher-grid-balance"><strong>R$ <?= finance_money_br($r['balance_amount']) ?></strong></td>
            <td data-col="status"><span class="finance-status <?= finance_h($st) ?>"><?= finance_h($st) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($voucherNew && $voucherCanCreate): ?>
<div class="finance-modal" role="dialog" aria-modal="true">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head"><div><h2>Incluir Vale manualmente</h2><p class="finance-muted">Escolha se o crédito é avulso ou se o cliente entregou dinheiro no caixa aberto.</p></div><a class="finance-modal-close" href="financeiro_vales.php">×</a></div>
    <form method="post" class="finance-form" autocomplete="off">
      <input type="hidden" name="action" value="create_manual_voucher">
      <input type="hidden" name="csrf" value="<?= finance_h($voucherCsrf) ?>">
      <input type="hidden" name="nonce" value="<?= finance_h($voucherNonce) ?>">
      <div class="finance-form-section">
        <div class="finance-form-grid three">
          <div><label>Modalidade *</label><select name="mode" id="nexaVoucherMode" required>
            <option value="AVULSO" <?= ($_POST['mode'] ?? '')==='AVULSO'?'selected':'' ?>>Vale Avulso — não entra no caixa</option>
            <?php if($voucherCanCredit): ?><option value="CREDITO" <?= ($_POST['mode'] ?? '')==='CREDITO'?'selected':'' ?>>Vale (Crédito) — entra no caixa</option><?php endif; ?>
          </select></div>
          <div><label>Cliente *</label><select name="person_id" id="nexaVoucherPerson" required><option value="">Selecione o cliente...</option><?php foreach($voucherPeople as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= (int)($_POST['person_id']??0)===(int)$vp['id']?'selected':'' ?>><?= finance_h((string)$vp['name'].((string)($vp['document']??'')!==''?' · '.(string)$vp['document']:'')) ?></option><?php endforeach; ?></select></div>
          <div><label>Valor *</label><input name="amount" inputmode="decimal" value="<?= finance_h((string)($_POST['amount'] ?? '')) ?>" placeholder="1.000,00" required></div>
          <div class="span-3" id="nexaVoucherCashFields">
            <?php if($voucherCanCredit): ?>
              <p class="finance-muted">Vale (Crédito): registra suprimento no caixa aberto deste usuário. Forma de recebimento nesta versão: dinheiro.</p>
              <p class="finance-muted">Caixa: <strong><?= $voucherCashSession ? '#'.(int)$voucherCashSession['id'].' — aberto' : 'não existe caixa aberto' ?></strong>.</p>
              <label>Plano de contas de crédito (adiantamento de cliente) *</label>
              <select name="chart_account_id" id="nexaVoucherChart"><option value="">Selecione a classificação adequada...</option><?php foreach($voucherCreditCharts as $vc): ?><option value="<?= (int)$vc['id'] ?>" <?= (int)($_POST['chart_account_id']??0)===(int)$vc['id']?'selected':'' ?>><?= finance_h((string)($vc['code']??'').' · '.(string)($vc['name']??'')) ?></option><?php endforeach; ?></select>
            <?php endif; ?>
          </div>
          <div class="span-3"><label>Justificativa / origem do crédito *</label><textarea name="notes" rows="3" required minlength="8" maxlength="2000" placeholder="Explique a origem do vale e o motivo da inclusão"><?= finance_h((string)($_POST['notes'] ?? '')) ?></textarea></div>
        </div>
      </div>
      <div class="finance-form-actions"><a class="btn" href="financeiro_vales.php">Cancelar</a><button class="btn btn-primary" type="submit">Gravar Vale</button></div>
    </form>
  </div>
</div>
<script>
(()=>{const mode=document.getElementById('nexaVoucherMode'),box=document.getElementById('nexaVoucherCashFields');if(!mode||!box)return;
const refresh=()=>{const credit=mode.value==='CREDITO';box.style.display=credit?'':'none';const chart=box.querySelector('[name=chart_account_id]');if(chart)chart.required=credit;};
mode.addEventListener('change',refresh);refresh();})();
</script>
<?php endif; ?>
<style>
/* V11.53 - Tela Vales: espaçamento e grade no padrão Cadastro de Produtos */
.voucher-hero{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.finance-page .voucher-kpis{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:10px!important;align-items:stretch}
.finance-page .voucher-kpis .finance-kpi{min-width:0;margin:0;padding:8px 14px;border-radius:14px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}.finance-page .voucher-kpis .finance-kpi span{margin:0;font-size:12px}.finance-page .voucher-kpis .finance-kpi strong{margin:0;font-size:clamp(17px,1.7vw,21px)}
.finance-page .voucher-kpis .finance-kpi strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.voucher-filters{display:grid;grid-template-columns:minmax(260px,2.2fr) minmax(155px,1fr) minmax(155px,1fr) minmax(155px,1fr) auto;gap:10px;align-items:end}
.voucher-filters>div{min-width:0}
.voucher-filters label{display:block;white-space:nowrap}
.voucher-filters input,.voucher-filters select{min-width:0;width:100%}
.voucher-filter-actions{display:flex;align-items:center;gap:8px;white-space:nowrap;justify-content:flex-end}
.voucher-filter-actions .btn{min-width:78px;min-height:40px;justify-content:center}
.voucher-grid-card{padding:14px!important}
.voucher-grid-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:9px;flex-wrap:wrap}
.voucher-grid-toolbar .finance-subtitle{margin-bottom:2px}
.voucher-grid-tools{display:flex;align-items:center;gap:9px}
.voucher-grid-save-status{font-size:11px;color:#64748b;font-weight:700}
.voucher-column-picker{position:relative}
.voucher-column-picker>summary{list-style:none;cursor:pointer}
.voucher-column-picker>summary::-webkit-details-marker{display:none}
.voucher-column-menu{position:absolute;right:0;top:calc(100% + 8px);z-index:100;min-width:230px;max-height:330px;overflow:auto;background:#fff;border:1px solid #dbeafe;border-radius:12px;box-shadow:0 18px 42px rgba(12,30,65,.2);padding:12px}
.voucher-column-menu>strong{display:block;color:#0b1f5b;margin-bottom:8px}
.voucher-column-menu label{display:flex;align-items:center;gap:8px;padding:5px 1px;font-size:12px;font-weight:750;color:#334155}
.voucher-column-menu p{font-size:11px;line-height:1.5;color:#64748b;border-top:1px solid #e2e8f0;margin:8px 0 0;padding-top:9px}
.voucher-grid-scroll{overflow-x:auto;overflow-y:visible;border-top:1px solid #e4ebf6;max-width:100%}
.finance-page .voucher-products-grid{max-width:none}.voucher-products-grid{width:100%;min-width:930px;border-collapse:separate;border-spacing:0;table-layout:fixed;font-size:12px}
.voucher-products-grid th,.voucher-products-grid td{text-align:left;padding:10px 9px;border-bottom:1px solid #e4ebf5;border-right:1px solid #eef3f9;vertical-align:middle;overflow-wrap:anywhere}
.voucher-products-grid th:last-child,.voucher-products-grid td:last-child{border-right:0}
.voucher-products-grid thead th{position:relative;background:#f7faff;color:#243b70;font-size:11px;font-weight:900;text-transform:uppercase;z-index:2;letter-spacing:.01em}
.voucher-products-grid thead th.filter-open{z-index:100}
.voucher-products-grid tbody tr:nth-child(odd) td{background:#fff}
.voucher-products-grid tbody tr:nth-child(even) td{background:#dfe8f4}
.voucher-products-grid tbody tr:hover td{background:#cbdaf0}
.voucher-products-grid td small{display:block;color:#64748b;font-size:11px;margin-top:3px}
.voucher-grid-primary strong{color:#0b1f5b}
.voucher-grid-value,.voucher-grid-date{white-space:nowrap!important}
.voucher-grid-balance{color:#047857;font-weight:900}
.voucher-grid-empty{text-align:center!important;padding:32px!important;color:#64748b;background:#f8fbff!important}
.voucher-grid-th-inner{display:flex;align-items:center;gap:5px;min-height:25px}
.voucher-sort-link{display:flex;flex:1;min-width:0;gap:5px;align-items:center;justify-content:space-between;color:inherit!important;text-decoration:none!important;white-space:nowrap}
.voucher-sort-link span{color:#64748b;font-size:12px}
.voucher-col-handle{cursor:grab;color:#94a3b8;line-height:1;font-weight:900;font-size:12px;letter-spacing:-2px;user-select:none}
.voucher-col-handle:active{cursor:grabbing}
.voucher-col-resize{position:absolute;right:-3px;top:0;bottom:0;width:7px;cursor:col-resize;z-index:4;touch-action:none}
.voucher-col-resize:after{content:"";position:absolute;top:20%;bottom:20%;left:3px;background:#c8d4e8;width:1px}
.voucher-col-resize:hover:after{width:2px;background:#3558c9}
.voucher-products-grid.dragging-resize,.voucher-products-grid.dragging-resize *{cursor:col-resize!important}
.voucher-products-grid th.voucher-drop-before{box-shadow:inset 3px 0 0 #3558c9}
.voucher-products-grid th.voucher-drop-after{box-shadow:inset -3px 0 0 #3558c9}
.voucher-products-grid th.voucher-dragging{opacity:.55}
.voucher-products-grid .voucher-col-hidden{display:none!important}
.voucher-grid-filter{position:relative;flex:0 0 auto;font-weight:normal;text-transform:none}
.voucher-grid-filter>summary{list-style:none;display:flex;width:23px;height:23px;justify-content:center;align-items:center;cursor:pointer;color:#64748b;border-radius:6px;font-size:18px;font-weight:900;border:1px solid transparent}
.voucher-grid-filter>summary::-webkit-details-marker{display:none}
.voucher-grid-filter>summary:hover,.voucher-grid-filter.is-active>summary,.voucher-grid-filter[open]>summary{background:#e8efff;color:#2448b8;border-color:#c8d7fa}
.voucher-grid-popover{position:absolute;z-index:110;top:calc(100% + 8px);right:0;width:240px;padding:12px;background:#fff;border:1px solid #dbe4f0;box-shadow:0 18px 38px rgba(15,35,80,.24);border-radius:12px;text-transform:none;letter-spacing:normal}
.voucher-grid-popover strong{display:block;margin-bottom:8px;color:#18336f;font-size:12px}
.voucher-grid-popover input{height:38px;width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px}
.voucher-grid-popover-actions{display:flex;gap:7px;margin-top:8px}
.voucher-grid-popover-actions .btn{flex:1;justify-content:center;min-height:35px;padding:6px;font-size:11px}
@media(max-width:1200px){.voucher-filters{grid-template-columns:minmax(220px,2fr) repeat(3,minmax(130px,1fr));}.voucher-filter-actions{grid-column:1/-1;justify-content:flex-end}}
@media(max-width:700px){.finance-page .voucher-kpis{grid-template-columns:repeat(3,minmax(175px,1fr))!important;overflow-x:auto}.voucher-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.voucher-filter-query{grid-column:1/-1}.voucher-filter-actions{grid-column:1/-1}.voucher-products-grid{min-width:890px}}
</style>
<?php if ($voucherNew && $voucherCanCreate):
  require_once __DIR__ . '/_buscar_f8.php';
  nexa_buscar_f8_assets();
?>
<script>
(function(){
  if (!window.NexaBuscarF8) return;
  const form = document.querySelector('.finance-modal form.finance-form');
  if (!form) return;
  const cliente = form.querySelector('#nexaVoucherPerson');
  const plano = form.querySelector('#nexaVoucherChart');
  if (cliente) window.NexaBuscarF8.enhanceSelect(cliente,{
    title:'Buscar cliente', placeholder:'Selecione o cliente',
    registerUrl:'financeiro_pessoas.php?new=1&role=customer', registerLabel:'+ Cadastrar'
  });
  if (plano) window.NexaBuscarF8.enhanceSelect(plano,{
    title:'Buscar plano de contas',placeholder:'Selecione o plano de contas',
    registerUrl:'financeiro_plano_contas.php?new=1',registerLabel:'+ Cadastrar'
  });
  // Ao começar a digitar com o campo de exibição em foco, abre a pesquisa
  // no modal existente e aproveita a primeira tecla, sem alterar o POST legado.
  [cliente,plano].filter(Boolean).forEach(select=>{
    const instance=window.NexaBuscarF8.instances.find(x=>x.select===select);
    if(!instance)return;
    instance.display.addEventListener('keydown',e=>{
      if(e.key.length!==1 || e.ctrlKey || e.altKey || e.metaKey)return;
      e.preventDefault();
      window.NexaBuscarF8.open(instance);
      requestAnimationFrame(()=>{
        const q=document.getElementById('nexaF8Query');
        if(q){q.value=e.key;q.focus();}
      });
    });
  });
})();
</script>
<?php endif; ?>
<script>
(function(){
  const grid = document.getElementById('voucherProductsGrid');
  if (!grid) return;
  const allowed = ['vale','cliente','origem','emissao','original','utilizado','saldo','status'];
  const defaultWidths = {vale:155,cliente:235,origem:175,emissao:112,original:120,utilizado:120,saldo:120,status:112};
  const prefs = <?= json_encode($voucherGridPrefs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const csrf = <?= json_encode($voucherGridCsrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const company = <?= (int)$cid ?>, user = <?= (int)$voucherGridUser ?>;
  const hasServerPrefs = <?= $voucherGridHasServerPrefs ? 'true' : 'false' ?>;
  const key = 'nexa.vales.grid.company.' + company + '.user.' + user;
  const status = document.getElementById('voucherGridSaved');
  const wrap = grid.closest('.voucher-grid-scroll');
  const toggles = Array.from(document.querySelectorAll('[data-voucher-column-toggle]'));
  let local = null;
  try { local = JSON.parse(localStorage.getItem(key) || 'null'); } catch(e){}
  const valid = (items) => Array.isArray(items)?items.filter((c,i,a)=>allowed.includes(c)&&a.indexOf(c)===i):[];
  // Preferências do usuário no servidor, se disponíveis; o navegador é contingência.
  const stored = hasServerPrefs ? prefs : (local || prefs || {});
  let visible = valid(stored.visible);
  if (!visible.length) visible=allowed.slice();
  let order = valid(stored.order);
  order = order.concat(allowed.filter(c=>!order.includes(c)));
  let widths = (stored.widths && typeof stored.widths==='object') ? stored.widths : {};
  const widthFor = (col) => {
    const n=Number(widths[col]);
    return Number.isFinite(n) && n>=75 && n<=600 ? n : defaultWidths[col];
  };
  let saveTimer=null;
  function save() {
    const normalized={visible,order,widths};
    try{ localStorage.setItem(key,JSON.stringify(normalized)); }catch(e){}
    if (!user) return;
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer=setTimeout(async function(){
      if(status) status.textContent='Salvando layout…';
      try {
        const body=new URLSearchParams();
        body.set('action','save_voucher_grid_preferences');
        body.set('csrf_token',csrf);
        body.set('preferences',JSON.stringify(normalized));
        const r=await fetch('financeiro_vales.php',{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
          body:body.toString()
        });
        const result=await r.json();
        if(!r.ok || !result.ok)throw new Error(result.message||'Falha');
        if(status)status.textContent='Layout salvo no usuário';
      } catch (e) {
        if(status)status.textContent='Layout salvo somente neste navegador';
      }
    },200);
  }
  function applyOrder() {
    grid.querySelectorAll('tr').forEach(row=>{
      const byCol={};
      Array.from(row.children).forEach(cell=>{
        if(cell.dataset && cell.dataset.col) byCol[cell.dataset.col]=cell;
      });
      order.forEach(col=>{if(byCol[col])row.appendChild(byCol[col]);});
    });
  }
  function applyWidths(){
    let total=0;
    allowed.forEach(col=>{
      const width=widthFor(col);
      grid.querySelectorAll('[data-col="'+col+'"]').forEach(el=>{
        el.style.width=width+'px';
        el.style.minWidth=width+'px';
        el.style.maxWidth=width+'px';
      });
      if(visible.includes(col))total+=width;
    });
    if(wrap)grid.style.width=Math.max(wrap.clientWidth,total)+'px';
  }
  function applyColumns(){
    toggles.forEach(box=>{box.checked=visible.includes(box.value);});
    allowed.forEach(col=>{
      grid.querySelectorAll('[data-col="'+col+'"]').forEach(el=>{
        el.classList.toggle('voucher-col-hidden',!visible.includes(col));
      });
    });
    applyWidths();
  }
  toggles.forEach(box=>box.addEventListener('change',function(){
    if(this.checked && !visible.includes(this.value))visible.push(this.value);
    if(!this.checked)visible=visible.filter(c=>c!==this.value);
    if(!visible.length){visible=[this.value];this.checked=true;}
    applyColumns();save();
  }));
  let dragging=null;
  grid.querySelectorAll('thead th[data-col]').forEach(th=>{
    const col=th.dataset.col;
    const holder=th.querySelector('.voucher-grid-th-inner');
    if(!holder)return;
    const handle=document.createElement('span');
    handle.className='voucher-col-handle';
    handle.textContent='⋮⋮';
    handle.title='Arrastar coluna';
    handle.setAttribute('aria-label','Arrastar coluna');
    handle.draggable=true;
    holder.prepend(handle);
    handle.addEventListener('dragstart',ev=>{
      dragging=col;th.classList.add('voucher-dragging');
      if(ev.dataTransfer){ev.dataTransfer.effectAllowed='move';ev.dataTransfer.setData('text/plain',col);}
    });
    handle.addEventListener('dragend',()=>{
      dragging=null;
      grid.querySelectorAll('thead th').forEach(el=>el.classList.remove('voucher-dragging','voucher-drop-before','voucher-drop-after'));
    });
    th.addEventListener('dragover',ev=>{
      if(!dragging || dragging===col)return;
      ev.preventDefault();
      const r=th.getBoundingClientRect();
      th.dataset.dropAfter=ev.clientX>r.left+r.width/2?'1':'0';
      grid.querySelectorAll('thead th').forEach(el=>el.classList.remove('voucher-drop-before','voucher-drop-after'));
      th.classList.add(th.dataset.dropAfter==='1'?'voucher-drop-after':'voucher-drop-before');
    });
    th.addEventListener('drop',ev=>{
      if(!dragging || dragging===col)return;
      ev.preventDefault();
      order=order.filter(c=>c!==dragging);
      let at=order.indexOf(col);
      if(at<0)at=order.length;
      if(th.dataset.dropAfter==='1')at++;
      order.splice(at,0,dragging);
      dragging=null;
      grid.querySelectorAll('thead th').forEach(el=>el.classList.remove('voucher-dragging','voucher-drop-before','voucher-drop-after'));
      applyOrder();applyWidths();save();
    });
    const grip=document.createElement('span');
    grip.className='voucher-col-resize';
    grip.title='Arraste para alterar a largura da coluna';
    grip.setAttribute('aria-hidden','true');
    th.appendChild(grip);
    grip.addEventListener('pointerdown',ev=>{
      if(ev.button!==0)return;
      ev.preventDefault();ev.stopPropagation();
      const startX=ev.clientX,startW=th.getBoundingClientRect().width || widthFor(col);
      grid.classList.add('dragging-resize');
      document.body.style.userSelect='none';
      const move=(e)=>{
        const n=Math.max(75,Math.min(600,Math.round(startW+e.clientX-startX)));
        widths[col]=n;
        applyWidths();
      };
      const up=()=>{
        document.removeEventListener('pointermove',move);
        document.removeEventListener('pointerup',up);
        document.body.style.userSelect='';
        grid.classList.remove('dragging-resize');
        save();
      };
      document.addEventListener('pointermove',move);
      document.addEventListener('pointerup',up,{once:true});
    });
  });
  applyOrder();applyColumns();
  window.addEventListener('resize',()=>{applyWidths();menuEls.forEach(d=>d.open=false);});
  const menuEls=Array.from(grid.querySelectorAll('[data-voucher-filter-menu]'));
  menuEls.forEach(d=>{
    d.addEventListener('toggle',()=>{
      const th=d.closest('th');if(th)th.classList.toggle('filter-open',d.open);
      if(d.open){
        menuEls.forEach(other=>{if(other!==d)other.open=false;});
        const form=d.querySelector('.voucher-grid-popover');
        const rect=d.querySelector('summary').getBoundingClientRect();
        if(form){
          const width=240;
          form.style.position='fixed';
          form.style.left=Math.max(8,Math.min(window.innerWidth-width-8,rect.right-width))+'px';
          form.style.top=Math.min(window.innerHeight-170,rect.bottom+8)+'px';
          form.style.right='auto';
          form.style.zIndex='12000';
        }
        const input=d.querySelector('input:not([type=hidden])');if(input)input.focus();
      }
    });
    d.querySelector('form')?.addEventListener('click',e=>e.stopPropagation());
  });
  document.addEventListener('click',e=>{
    menuEls.forEach(d=>{if(d.open&&!d.contains(e.target))d.open=false;});
    const picker=document.getElementById('voucherColumnPicker');
    if(picker && picker.open && !picker.contains(e.target))picker.open=false;
  });
  window.addEventListener('scroll',()=>menuEls.forEach(d=>d.open=false),true);
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape')menuEls.forEach(d=>d.open=false);
  });
})();
</script>
<?php finance_admin_end(); ?>
