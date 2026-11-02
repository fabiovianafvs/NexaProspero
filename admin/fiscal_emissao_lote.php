<?php
declare(strict_types=1);

/*
 * V11.43 R4 - Emissão fiscal em lote.
 *
 * A página foi deliberadamente tornada autocontida no GET. Ela usa somente o
 * bootstrap fiscal já homologado do Nexa e não carrega o helper novo
 * fiscal_batch_emission.php na abertura. Isso evita que uma incompatibilidade
 * secundária derrube a tela inteira com HTTP 500.
 */

// Se houver um fatal inesperado neste endpoint, mostre algo útil ao administrador
// em vez da página branca/HTTP 500 genérica do navegador.
$GLOBALS['NEXA_BATCH_PAGE_FINISHED'] = false;
register_shutdown_function(function (): void {
  if (!empty($GLOBALS['NEXA_BATCH_PAGE_FINISHED'])) return;
  $e = error_get_last();
  if (!$e) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array((int)($e['type'] ?? 0), $fatalTypes, true)) return;
  if (!headers_sent()) http_response_code(500);
  $msg = htmlspecialchars((string)($e['message'] ?? 'Erro fatal desconhecido.'), ENT_QUOTES, 'UTF-8');
  $file = htmlspecialchars(basename((string)($e['file'] ?? '')), ENT_QUOTES, 'UTF-8');
  $line = (int)($e['line'] ?? 0);
  echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico - Emissão em lote</title>';
  echo '<style>body{font-family:Arial,sans-serif;background:#f5f7fb;color:#172554;padding:28px}.box{max-width:900px;margin:40px auto;background:#fff;border:1px solid #fecaca;border-radius:20px;padding:24px;box-shadow:0 18px 45px rgba(15,23,42,.08)}h1{margin-top:0;color:#991b1b}.detail{background:#fff1f2;border:1px solid #fecdd3;padding:14px;border-radius:14px;color:#9f1239;white-space:pre-wrap}.hint{margin-top:14px;color:#475569}</style></head><body><div class="box"><h1>Falha técnica na Emissão em lote</h1><div class="detail">' . $msg . '<br>Arquivo: ' . $file . ' · linha ' . $line . '</div><div class="hint">Envie esta mensagem para a manutenção do Nexa. Nenhum documento fiscal foi emitido apenas por abrir esta tela.</div></div></body></html>';
});

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

if (!function_exists('fbel_csrf_token')) {
  function fbel_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $key = 'csrf_fiscal_batch_emission';
    if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(24));
    return (string)$_SESSION[$key];
  }
}

if (!function_exists('fbel_provider_code')) {
  function fbel_provider_code(PDO $pdo, int $companyId): string {
    try {
      $cfg = fiscal_company_config($pdo, $companyId);
      $v = strtoupper(trim((string)($cfg['nfse_provider'] ?? 'NACIONAL')));
      if (in_array($v, ['PRONIM','GOVBR','ISS_DIGITAL','PRONIM_GOVBR','GOVBR_ISS_DIGITAL'], true)) return 'PRONIM';
    } catch (Throwable $e) {}
    return 'NACIONAL';
  }
}

if (!function_exists('fbel_latest_documents')) {
  function fbel_latest_documents(PDO $pdo, int $companyId, array $orderIds): array {
    $ids = [];
    foreach ($orderIds as $v) { $v = (int)$v; if ($v > 0) $ids[$v] = $v; }
    $ids = array_values($ids);
    if (!$ids || !fiscal_table_exists($pdo, 'fiscal_documents')) return [];
    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $hasNfse = fiscal_table_exists($pdo, 'fiscal_nfse_details');
      $select = "d.id,d.source_id AS pedido_id,d.model,d.status,d.series,d.number,d.access_key,d.sefaz_status_code,d.sefaz_status_message";
      $join = '';
      if ($hasNfse) {
        $select .= ",nd.status AS nfse_status,nd.nfse_number,nd.dps_series,nd.dps_number";
        $join = " LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id ";
      } else {
        $select .= ",NULL AS nfse_status,NULL AS nfse_number,NULL AS dps_series,NULL AS dps_number";
      }
      $sql = "SELECT $select FROM fiscal_documents d $join
              WHERE d.company_id=? AND d.direction='SAIDA' AND d.source_type='PEDIDO'
                AND d.source_id IN ($ph) AND d.model IN ('55','65','NFSE')
              ORDER BY d.id DESC";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$companyId], $ids));
      $out = [];
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $oid = (int)($r['pedido_id'] ?? 0);
        $model = strtoupper(trim((string)($r['model'] ?? '')));
        if ($oid <= 0 || $model === '' || isset($out[$oid][$model])) continue;
        if ($model === 'NFSE' && trim((string)($r['nfse_status'] ?? '')) !== '') $r['status'] = (string)$r['nfse_status'];
        $out[$oid][$model] = $r;
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('fbel_contract_map')) {
  function fbel_contract_map(PDO $pdo, int $companyId, array $orderIds): array {
    $ids = [];
    foreach ($orderIds as $v) { $v = (int)$v; if ($v > 0) $ids[$v] = $v; }
    $ids = array_values($ids);
    if (!$ids || !fiscal_table_exists($pdo, 'contract_generations')) return [];
    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("SELECT order_id,contract_id,id AS generation_id FROM contract_generations WHERE company_id=? AND order_id IN ($ph)");
      $st->execute(array_merge([$companyId], $ids));
      $out = [];
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $oid = (int)($r['order_id'] ?? 0);
        if ($oid > 0) $out[$oid] = $r;
      }
      return $out;
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('fbel_composition_map')) {
  function fbel_composition_map(PDO $pdo, int $companyId, array $orderIds): array {
    if (function_exists('fiscal_order_composition_map')) {
      try { return fiscal_order_composition_map($pdo, $companyId, $orderIds); } catch (Throwable $e) {}
    }
    $ids = [];
    foreach ($orderIds as $v) { $v = (int)$v; if ($v > 0) $ids[$v] = $v; }
    $ids = array_values($ids);
    if (!$ids) return [];
    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $hasService = fiscal_column_exists($pdo, 'produtos', 'is_service');
      $serviceExpr = $hasService ? 'COALESCE(p.is_service,0)' : '0';
      $sql = "SELECT pi.pedido_id,
                     COUNT(*) AS item_count,
                     SUM(CASE WHEN $serviceExpr=1 THEN 1 ELSE 0 END) AS service_count,
                     SUM(CASE WHEN $serviceExpr=1 THEN 0 ELSE 1 END) AS product_count
                FROM pedido_itens pi
                LEFT JOIN produtos p ON p.company_id=pi.company_id AND p.id=pi.produto_id
               WHERE pi.company_id=? AND pi.pedido_id IN ($ph)
               GROUP BY pi.pedido_id";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$companyId], $ids));
      $out = [];
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $oid = (int)($r['pedido_id'] ?? 0);
        if ($oid > 0) $out[$oid] = [
          'item_count'=>(int)($r['item_count'] ?? 0),
          'service_count'=>(int)($r['service_count'] ?? 0),
          'product_count'=>(int)($r['product_count'] ?? 0),
        ];
      }
      foreach ($ids as $oid) if (!isset($out[$oid])) $out[$oid] = ['item_count'=>0,'service_count'=>0,'product_count'=>0];
      return $out;
    } catch (Throwable $e) {
      $out = [];
      foreach ($ids as $oid) $out[$oid] = ['item_count'=>0,'service_count'=>0,'product_count'=>0];
      return $out;
    }
  }
}

if (!function_exists('fbel_state')) {
  function fbel_state(array $row, string $type): array {
    $type = strtoupper($type);
    if ($type === 'NFSE') {
      if ((int)($row['service_count'] ?? 0) <= 0) return ['eligible'=>false,'label'=>'Sem serviço','message'=>'Pedido não possui item classificado como serviço.'];
      $doc = $row['documents']['NFSE'] ?? null;
      $status = strtoupper(trim((string)($doc['status'] ?? '')));
      if ($status === 'AUTORIZADA') return ['eligible'=>false,'label'=>'Autorizada','message'=>'NFS-e já autorizada.'];
      if ($status === 'CANCELADA') return ['eligible'=>false,'label'=>'Cancelada','message'=>'NFS-e já cancelada.'];
      if (in_array($status, ['TRANSMITINDO','ERRO_COMUNICACAO','PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) return ['eligible'=>false,'label'=>'Consultar','message'=>'Há retorno fiscal pendente/inconclusivo. Consulte antes de reenviar.'];
      if ($status === 'REJEITADA') return ['eligible'=>true,'label'=>'Rejeitada','message'=>'Será reconstruída com o cadastro fiscal atual e retransmitida.'];
      if ($status === 'ASSINADA') return ['eligible'=>true,'label'=>'Assinada','message'=>'DPS assinada pronta para transmissão.'];
      return ['eligible'=>true,'label'=>'Pendente','message'=>'Será gerada, assinada e transmitida.'];
    }

    if ((int)($row['product_count'] ?? 0) <= 0) return ['eligible'=>false,'label'=>'Sem mercadoria','message'=>'Pedido não possui mercadoria para NFC-e.'];
    $nfe = $row['documents']['55'] ?? null;
    $nfeStatus = strtoupper(trim((string)($nfe['status'] ?? '')));
    if (in_array($nfeStatus, ['AUTORIZADA','CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) return ['eligible'=>false,'label'=>'NF-e existente','message'=>'Pedido já possui NF-e emitida ou pendente.'];
    $doc = $row['documents']['65'] ?? null;
    $status = strtoupper(trim((string)($doc['status'] ?? '')));
    if ($status === 'AUTORIZADA') return ['eligible'=>false,'label'=>'Autorizada','message'=>'NFC-e já autorizada.'];
    if ($status === 'CANCELADA') return ['eligible'=>false,'label'=>'Cancelada','message'=>'NFC-e já cancelada.'];
    if (in_array($status, ['PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) return ['eligible'=>false,'label'=>'Consultar','message'=>'Retorno SEFAZ pendente/inconclusivo.'];
    if (in_array($status, ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE'], true)) return ['eligible'=>true,'label'=>'Contingência','message'=>'Documento existente será transmitido pelo fluxo de contingência.'];
    if ($status === 'REJEITADA') return ['eligible'=>true,'label'=>'Rejeitada','message'=>'Será regenerada com as regras fiscais atuais e retransmitida.'];
    return ['eligible'=>true,'label'=>'Pendente','message'=>'Será gerada e transmitida.'];
  }
}

if (!function_exists('fbel_candidate_orders')) {
  function fbel_candidate_orders(PDO $pdo, int $companyId, string $dateIni, string $dateFim, string $origin, string $q, int $limit = 250): array {
    $hasNumero = fiscal_column_exists($pdo, 'pedidos', 'numero_pedido');
    $numberExpr = $hasNumero ? 'COALESCE(numero_pedido,id)' : 'id';
    $where = ['company_id=?', 'DATE(created_at)>=?', 'DATE(created_at)<=?', "LOWER(COALESCE(status,'')) NOT IN ('cancelado','cancelada','canceled')"];
    $params = [$companyId, $dateIni, $dateFim];
    $hasContracts = fiscal_table_exists($pdo, 'contract_generations');
    if ($origin === 'CONTRATOS' && $hasContracts) $where[] = 'EXISTS (SELECT 1 FROM contract_generations cg WHERE cg.company_id=pedidos.company_id AND cg.order_id=pedidos.id)';
    if ($origin === 'VENDAS' && $hasContracts) $where[] = 'NOT EXISTS (SELECT 1 FROM contract_generations cg WHERE cg.company_id=pedidos.company_id AND cg.order_id=pedidos.id)';
    if ($q !== '') {
      $where[] = "(CAST($numberExpr AS CHAR) LIKE ? OR CAST(id AS CHAR) LIKE ? OR COALESCE(nome_cliente,'') LIKE ?)";
      $like = nexa_search_like($q);
      $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $limit = max(1, min(500, $limit));
    $sql = "SELECT *, $numberExpr AS numero_pedido_lote FROM pedidos WHERE " . implode(' AND ', $where) . " ORDER BY $numberExpr DESC,id DESC LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];
    $ids = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    $comp = fbel_composition_map($pdo, $companyId, $ids);
    $contracts = fbel_contract_map($pdo, $companyId, $ids);
    $docs = fbel_latest_documents($pdo, $companyId, $ids);
    foreach ($rows as &$r) {
      $oid = (int)$r['id'];
      $c = $comp[$oid] ?? ['item_count'=>0,'service_count'=>0,'product_count'=>0];
      $r['numero_pedido'] = (int)($r['numero_pedido_lote'] ?? $oid);
      $r['item_count'] = (int)($c['item_count'] ?? 0);
      $r['service_count'] = (int)($c['service_count'] ?? 0);
      $r['product_count'] = (int)($c['product_count'] ?? 0);
      $r['contract_id'] = (int)($contracts[$oid]['contract_id'] ?? 0);
      $r['documents'] = $docs[$oid] ?? [];
    }
    unset($r);
    return $rows;
  }
}

if (!function_exists('fbel_record_contract_nfse')) {
  function fbel_record_contract_nfse(PDO $pdo, int $companyId, int $orderId, int $docId, string $nfseNumber): void {
    try {
      if (!fiscal_table_exists($pdo, 'contract_generations') || !fiscal_table_exists($pdo, 'contract_billing_history')) return;
      $st = $pdo->prepare("SELECT id generation_id,contract_id FROM contract_generations WHERE company_id=? AND order_id=? LIMIT 1");
      $st->execute([$companyId,$orderId]);
      $g = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$g) return;
      $check = $pdo->prepare("SELECT id FROM contract_billing_history WHERE company_id=? AND generation_id=? AND origin_type='NFSE' AND origin_id=? AND event_type='NFSE_AUTORIZADA' LIMIT 1");
      $check->execute([$companyId,(int)$g['generation_id'],$docId]);
      if ((int)($check->fetchColumn() ?: 0) > 0) return;
      $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      $desc = 'NFS-e' . ($nfseNumber !== '' ? ' #' . $nfseNumber : '') . ' autorizada para o Pedido #' . $orderId;
      $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,event_at,created_by) VALUES(?,?,?,'NFSE',?,'NFSE_AUTORIZADA',?,NOW(),?)")
          ->execute([$companyId,(int)$g['contract_id'],(int)$g['generation_id'],$docId,$desc,$uid ?: null]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('fbel_process_orders')) {
  function fbel_process_orders(PDO $pdo, int $companyId, array $orderIds, string $type): array {
    $ids = [];
    foreach ($orderIds as $v) { $v=(int)$v; if ($v>0) $ids[$v]=$v; }
    $ids = array_values($ids);
    if (!$ids) throw new RuntimeException('Selecione pelo menos um Pedido.');
    if (count($ids) > 100) throw new RuntimeException('Por segurança, processe no máximo 100 Pedidos por lote.');
    $cfg = fiscal_company_config($pdo, $companyId);
    $env = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
    $provider = fbel_provider_code($pdo, $companyId);
    if ($type === 'NFSE' && $provider === 'PRONIM' && $env === 'PRODUCAO' && !defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED', true);

    $ok=0; $fail=0; $skip=0; $messages=[];
    foreach ($ids as $orderId) {
      try {
        $rows = fbel_candidate_orders($pdo, $companyId, '2000-01-01', '2099-12-31', 'TODOS', (string)$orderId, 20);
        $row = null;
        foreach ($rows as $rr) if ((int)$rr['id'] === $orderId) { $row=$rr; break; }
        if (!$row) throw new RuntimeException('Pedido não encontrado ou cancelado.');
        $state = fbel_state($row, $type);
        if (empty($state['eligible'])) { $skip++; $messages[]='Pedido #' . $row['numero_pedido'] . ': ignorado — ' . ($state['message'] ?? 'não elegível') . '.'; continue; }
        if ($type === 'NFSE') {
          if (!function_exists('nfse_provider_authorize_order')) {
            $providerFile = __DIR__ . '/../config/nfse_provider_router.php';
            if (!is_file($providerFile)) throw new RuntimeException('Provider NFS-e não encontrado.');
            require_once $providerFile;
          }
          if (!function_exists('nfse_provider_authorize_order')) throw new RuntimeException('Provider NFS-e não pôde ser carregado.');
          $res = nfse_provider_authorize_order($pdo, $companyId, $orderId);
          if (empty($res['ok'])) throw new RuntimeException((string)($res['message'] ?? 'NFS-e não autorizada.'));
          $number = trim((string)($res['nfse_number'] ?? ($res['document']['nfse_number'] ?? '')));
          $docId = (int)($res['document']['id'] ?? 0);
          if ($docId > 0) fbel_record_contract_nfse($pdo, $companyId, $orderId, $docId, $number);
          $ok++; $messages[]='Pedido #' . $row['numero_pedido'] . ': NFS-e' . ($number!==''?' #'.$number:'') . ' autorizada.';
        } else {
          $res = fiscal_nfce_authorize_order($pdo, $companyId, $orderId);
          if (empty($res['ok'])) {
            $code = trim((string)($res['code'] ?? ''));
            $msg = trim((string)($res['message'] ?? 'NFC-e não autorizada.'));
            throw new RuntimeException(($code!==''?$code.' - ':'') . $msg);
          }
          $docId = (int)($res['doc_id'] ?? 0);
          $number = '';
          if ($docId > 0 && function_exists('fiscal_nfce_document_by_id')) {
            $doc = fiscal_nfce_document_by_id($pdo, $companyId, $docId);
            $number = trim((string)($doc['number'] ?? ''));
          }
          $ok++; $messages[]='Pedido #' . $row['numero_pedido'] . ': NFC-e' . ($number!==''?' #'.$number:'') . ' autorizada.';
        }
      } catch (Throwable $e) {
        $fail++; $messages[]='Pedido ID #' . $orderId . ': ' . $e->getMessage();
      }
    }
    return ['type'=>$type,'ok_count'=>$ok,'fail_count'=>$fail,'skip_count'=>$skip,'messages'=>$messages];
  }
}

if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && defined('COMPANY_ID')
    && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID) {
  access_render_denied('Emissão fiscal em lote permanece bloqueada no contexto F11. Acesse diretamente a empresa que emitirá os documentos.');
}

$cid = (int)COMPANY_ID;
$today = date('Y-m-d');
$type = strtoupper(trim((string)($_REQUEST['tipo'] ?? 'NFSE')));
if (!in_array($type, ['NFSE','NFCE'], true)) $type='NFSE';
$origin = strtoupper(trim((string)($_REQUEST['origem'] ?? 'TODOS')));
if (!in_array($origin, ['TODOS','CONTRATOS','VENDAS'], true)) $origin='TODOS';
$dateIni = trim((string)($_REQUEST['data_ini'] ?? $today));
$dateFim = trim((string)($_REQUEST['data_fim'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIni)) $dateIni=$today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFim)) $dateFim=$dateIni;
if ($dateFim < $dateIni) { $tmp=$dateIni; $dateIni=$dateFim; $dateFim=$tmp; }
$q = trim((string)($_REQUEST['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    $posted=(string)($_POST['csrf_token'] ?? '');
    if ($posted==='' || !hash_equals(fbel_csrf_token(), $posted)) throw new RuntimeException('Sessão expirada ou formulário inválido. Atualize a tela e tente novamente.');
    $ids=$_POST['order_ids'] ?? [];
    if (!is_array($ids)) $ids=[$ids];
    $_SESSION['fiscal_batch_emission_result']=fbel_process_orders($pdo,$cid,$ids,$type);
  } catch (Throwable $e) {
    $_SESSION['fiscal_batch_emission_result']=['type'=>$type,'ok_count'=>0,'fail_count'=>1,'skip_count'=>0,'messages'=>[$e->getMessage()]];
  }
  $qs=http_build_query(['tipo'=>$type,'origem'=>$origin,'data_ini'=>$dateIni,'data_fim'=>$dateFim,'q'=>$q]);
  header('Location: fiscal_emissao_lote.php?' . $qs);
  exit;
}

$result=$_SESSION['fiscal_batch_emission_result'] ?? null;
unset($_SESSION['fiscal_batch_emission_result']);
$pageLoadError=''; $rows=[]; $eligibleCount=0;
try {
  $cfg=fiscal_company_config($pdo,$cid);
  $environment=strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
  if (!in_array($environment,['HOMOLOGACAO','PRODUCAO'],true)) $environment='HOMOLOGACAO';
  $provider=fbel_provider_code($pdo,$cid);
  $providerLabel=$provider==='PRONIM'?'Pronim / GOVBR - ISS Digital':'Ambiente Nacional';
  $rows=fbel_candidate_orders($pdo,$cid,$dateIni,$dateFim,$origin,$q,250);
  foreach($rows as &$r){$r['batch_state']=fbel_state($r,$type);if(!empty($r['batch_state']['eligible']))$eligibleCount++;} unset($r);
} catch(Throwable $e){
  $cfg=[];$environment='HOMOLOGACAO';$provider='NACIONAL';$providerLabel='Nacional';$pageLoadError=$e->getMessage();
}

fiscal_admin_start('Emissão fiscal em lote','fiscal_documentos');
fiscal_page_header('Emissão fiscal em lote','Selecione pedidos e gere um documento fiscal por pedido, mantendo cada cliente e cada numeração independentes.',[
  ['label'=>'Central de Pedidos','href'=>'pedidos.php','class'=>'btn'],
  ['label'=>'Gerar contratos','href'=>'contratos_gerar.php','class'=>'btn'],
]);
fiscal_tabs('fiscal_emissao_lote');
?>
<style>
.batch-card{background:#fff;border:1px solid #dbe4f0;border-radius:20px;padding:16px;margin-bottom:14px}.batch-grid{display:grid;grid-template-columns:160px 160px 160px minmax(220px,1fr) auto;gap:10px;align-items:end}.batch-type{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.batch-type a{padding:10px 15px;border:1px solid #cbd5e1;border-radius:999px;text-decoration:none;font-weight:900;color:#0f172a;background:#fff}.batch-type a.active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.batch-table{width:100%;border-collapse:collapse;min-width:1050px}.batch-table th,.batch-table td{padding:10px;border-bottom:1px solid #e5edf6;text-align:left;vertical-align:middle}.batch-table th{background:#f8fbff;color:#334155;font-size:12px}.batch-status{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900;border:1px solid #dbe4f0}.batch-status.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}.batch-status.block{background:#f8fafc;color:#64748b}.batch-origin{display:inline-flex;border-radius:999px;padding:3px 8px;background:#eef4ff;color:#1557d5;font-size:10px;font-weight:900}.batch-muted{font-size:12px;color:#64748b;line-height:1.4}.batch-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.batch-check{width:19px;height:19px}.batch-summary{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;margin-bottom:14px}.batch-kpi{border:1px solid #dbe4f0;border-radius:16px;background:#fff;padding:14px}.batch-kpi span{display:block;color:#64748b;font-size:12px;font-weight:800}.batch-kpi strong{display:block;font-size:22px;margin-top:4px;color:#0f2a6d}.batch-result{border-radius:14px;padding:13px 15px;margin-bottom:14px}.batch-result.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.batch-result.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.batch-result ul{margin:8px 0 0 20px;max-height:260px;overflow:auto}.batch-prod{border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:16px;padding:14px;margin:12px 0}.batch-prod input{max-width:220px;margin-top:8px}@media(max-width:900px){.batch-grid{grid-template-columns:1fr 1fr}.batch-summary{grid-template-columns:1fr 1fr}}
</style>
<?php if($pageLoadError!==''):?><div class="batch-result err"><strong>Não foi possível carregar a emissão em lote.</strong><br><?=fiscal_h($pageLoadError)?></div><?php endif;?>
<div class="batch-type"><a class="<?=$type==='NFSE'?'active':''?>" href="?<?=fiscal_h(http_build_query(['tipo'=>'NFSE','origem'=>$origin,'data_ini'=>$dateIni,'data_fim'=>$dateFim,'q'=>$q]))?>">NFS-e em lote</a><a class="<?=$type==='NFCE'?'active':''?>" href="?<?=fiscal_h(http_build_query(['tipo'=>'NFCE','origem'=>$origin,'data_ini'=>$dateIni,'data_fim'=>$dateFim,'q'=>$q]))?>">NFC-e em lote</a></div>
<?php if(is_array($result)): $fail=(int)($result['fail_count']??0);?><div class="batch-result <?=$fail>0?'err':'ok'?>"><strong>Lote concluído.</strong> Autorizadas: <?=(int)($result['ok_count']??0)?> · Ignoradas: <?=(int)($result['skip_count']??0)?> · Com erro: <?=$fail?>.<?php if(!empty($result['messages'])):?><ul><?php foreach((array)$result['messages'] as $m):?><li><?=fiscal_h($m)?></li><?php endforeach;?></ul><?php endif;?></div><?php endif;?>
<div class="batch-summary"><div class="batch-kpi"><span>Ambiente</span><strong><?=fiscal_h($environment)?></strong></div><div class="batch-kpi"><span>Tipo</span><strong><?=$type==='NFSE'?'NFS-e':'NFC-e'?></strong></div><div class="batch-kpi"><span>Pedidos encontrados</span><strong><?=count($rows)?></strong></div><div class="batch-kpi"><span>Aptos para selecionar</span><strong><?=$eligibleCount?></strong></div></div>
<div class="batch-card"><?php if($type==='NFSE'):?><div class="msg"><b>NFS-e:</b> cada pedido gera sua própria DPS/NFS-e. Para contratos, use <b>Origem = Contratos</b>. Provider atual: <b><?=fiscal_h($providerLabel)?></b>.</div><?php else:?><div class="msg"><b>NFC-e:</b> cada pedido gera uma NFC-e individual. Pedidos mistos levam somente as mercadorias para a NFC-e; serviços permanecem para NFS-e.</div><?php endif;?>
<form method="get" class="batch-grid"><input type="hidden" name="tipo" value="<?=fiscal_h($type)?>"><label>Data inicial<input type="date" name="data_ini" value="<?=fiscal_h($dateIni)?>"></label><label>Data final<input type="date" name="data_fim" value="<?=fiscal_h($dateFim)?>"></label><label>Origem<select name="origem"><option value="TODOS" <?=$origin==='TODOS'?'selected':''?>>Todos</option><option value="CONTRATOS" <?=$origin==='CONTRATOS'?'selected':''?>>Contratos</option><option value="VENDAS" <?=$origin==='VENDAS'?'selected':''?>>Vendas/Pedidos</option></select></label><label>Pesquisar<input type="text" name="q" value="<?=fiscal_h($q)?>" placeholder="Nº pedido ou cliente"></label><div class="batch-actions"><button class="btn btn-primary" type="submit">Pesquisar</button><a class="btn" href="fiscal_emissao_lote.php?tipo=<?=fiscal_h($type)?>">Hoje</a></div></form></div>
<form method="post" id="batchEmissionForm" class="batch-card"><input type="hidden" name="csrf_token" value="<?=fiscal_h(fbel_csrf_token())?>"><input type="hidden" name="tipo" value="<?=fiscal_h($type)?>"><input type="hidden" name="origem" value="<?=fiscal_h($origin)?>"><input type="hidden" name="data_ini" value="<?=fiscal_h($dateIni)?>"><input type="hidden" name="data_fim" value="<?=fiscal_h($dateFim)?>"><input type="hidden" name="q" value="<?=fiscal_h($q)?>"><div class="batch-actions" style="margin-bottom:12px"><button class="btn" type="button" id="markAll">Marcar aptos</button><button class="btn" type="button" id="unmarkAll">Desmarcar</button><span class="batch-muted" id="selectedCount">0 selecionado(s)</span></div>
<div style="overflow:auto"><table class="batch-table"><thead><tr><th></th><th>Pedido</th><th>Data</th><th>Cliente</th><th>Origem</th><th>Composição</th><th>Total</th><th>Situação fiscal</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="8" class="batch-muted">Nenhum pedido encontrado no período/filtro informado.</td></tr><?php endif;?><?php foreach($rows as $r):$s=(array)$r['batch_state'];$eligible=!empty($s['eligible']);$contract=(int)($r['contract_id']??0)>0;?><tr><td><?php if($eligible):?><input class="batch-check" type="checkbox" name="order_ids[]" value="<?=(int)$r['id']?>"><?php endif;?></td><td><b>#<?=(int)($r['numero_pedido']??$r['id'])?></b><div class="batch-muted">ID <?=(int)$r['id']?></div></td><td><?=fiscal_h(date('d/m/Y H:i',strtotime((string)$r['created_at'])?:time()))?></td><td><?=fiscal_h((string)($r['nome_cliente']??''))?></td><td><span class="batch-origin"><?=$contract?'CONTRATO':'VENDA'?></span></td><td><b><?=(int)($r['product_count']??0)?></b> merc. · <b><?=(int)($r['service_count']??0)?></b> serv.</td><td><b>R$ <?=number_format((float)($r['total']??0),2,',','.')?></b></td><td><span class="batch-status <?=$eligible?'ok':'block'?>"><?=fiscal_h((string)($s['label']??'-'))?></span><div class="batch-muted"><?=fiscal_h((string)($s['message']??''))?></div></td></tr><?php endforeach;?></tbody></table></div><div class="batch-actions" style="justify-content:flex-end;margin-top:14px"><button class="btn btn-primary" type="submit" <?=$eligibleCount<=0?'disabled':''?>><?=$type==='NFSE'?'Emitir NFS-e dos selecionados':'Emitir NFC-e dos selecionados'?></button></div></form>
<script>(function(){function checks(){return Array.prototype.slice.call(document.querySelectorAll('.batch-check'));}var c=document.getElementById('selectedCount');function refresh(){var n=checks().filter(function(x){return x.checked;}).length;if(c)c.textContent=n+' selecionado(s)';}var a=document.getElementById('markAll');if(a)a.addEventListener('click',function(){checks().forEach(function(x){x.checked=true;});refresh();});var u=document.getElementById('unmarkAll');if(u)u.addEventListener('click',function(){checks().forEach(function(x){x.checked=false;});refresh();});checks().forEach(function(x){x.addEventListener('change',refresh);});var f=document.getElementById('batchEmissionForm');if(f)f.addEventListener('submit',function(ev){var n=checks().filter(function(x){return x.checked;}).length;if(!n){ev.preventDefault();alert('Selecione pelo menos um Pedido apto.');return;}if(!confirm('Processar '+n+' pedido(s)? Cada pedido terá seu documento fiscal individual e o lote continuará mesmo se um deles for rejeitado.'))ev.preventDefault();});refresh();})();</script>
<?php
$GLOBALS['NEXA_BATCH_PAGE_FINISHED'] = true;
fiscal_admin_end();
