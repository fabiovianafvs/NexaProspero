<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/company_sequences.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
if (function_exists('nexa_people_permission_allowed') && !nexa_people_permission_allowed($pdo, $cid)) {
  if (function_exists('access_render_denied')) access_render_denied('Seu usuário não possui acesso ao cadastro de Pessoas/Clientes.');
  http_response_code(403); exit('Acesso restrito.');
}

function rc_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rc_digits(string $v): string { return preg_replace('/\D+/', '', $v) ?: ''; }
function rc_client_code(array $row): int { $n=(int)($row['numero_cadastro'] ?? 0); return $n>0 ? $n : (int)($row['id'] ?? 0); }

$q = trim((string)($_GET['q'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? 'active')));
if (!in_array($status, ['active','inactive','all'], true)) $status = 'active';
$generate = isset($_GET['gerar']) || (string)($_GET['export'] ?? '') === 'csv';
$rows = [];
$err = '';

if ($generate) {
  try {
    $hasNumber = function_exists('company_sequence_has_column') ? company_sequence_has_column($pdo, 'pessoas') : finance_column_exists($pdo, 'finance_people', 'numero_cadastro');
    $selectNumber = $hasNumber ? 'numero_cadastro' : 'NULL AS numero_cadastro';
    $where = ['company_id=?', 'COALESCE(is_customer,0)=1'];
    $params = [$cid];
    if ($status === 'active') $where[] = 'COALESCE(active,1)=1';
    elseif ($status === 'inactive') $where[] = 'COALESCE(active,1)=0';
    if ($q !== '') {
      $tokens = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
      foreach ($tokens as $token) {
        $like = nexa_search_like($token);
        $parts = ['name LIKE ?','fantasy_name LIKE ?','document LIKE ?','state_registration LIKE ?','email LIKE ?','phone LIKE ?','whatsapp LIKE ?','city LIKE ?'];
        $tokenParams = array_fill(0, count($parts), $like);
        $digits = rc_digits($token);
        if ($hasNumber && $digits !== '') { $parts[] = 'numero_cadastro=?'; $tokenParams[] = (int)$digits; }
        elseif ($hasNumber) { $parts[] = 'CAST(numero_cadastro AS CHAR) LIKE ?'; $tokenParams[] = $like; }
        $where[] = '(' . implode(' OR ', $parts) . ')';
        array_push($params, ...$tokenParams);
      }
    }
    $order = $hasNumber ? 'COALESCE(numero_cadastro,id),id' : 'name,id';
    $sql = "SELECT id,{$selectNumber},name,fantasy_name,document,state_registration,email,phone,whatsapp,city,state,active FROM finance_people WHERE " . implode(' AND ', $where) . " ORDER BY {$order}";
    $rows = finance_query_all($pdo, $sql, $params);
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

if ($generate && $err === '' && (string)($_GET['export'] ?? '') === 'csv') {
  $filename = 'clientes_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store');
  $out = fopen('php://output', 'wb');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Código','Nome / Razão Social','Fantasia','CPF/CNPJ','IE','Telefone','WhatsApp','E-mail','Cidade','UF','Situação'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      rc_client_code($r), (string)($r['name'] ?? ''), (string)($r['fantasy_name'] ?? ''), (string)($r['document'] ?? ''),
      (string)($r['state_registration'] ?? ''), (string)($r['phone'] ?? ''), (string)($r['whatsapp'] ?? ''), (string)($r['email'] ?? ''),
      (string)($r['city'] ?? ''), (string)($r['state'] ?? ''), !empty($r['active']) ? 'Ativo' : 'Inativo'
    ], ';');
  }
  fclose($out);
  exit;
}

admin_topbar('Relatório de Clientes', 'relatorio_clientes');
?>
<style>
.rc-page{display:grid;gap:14px}.rc-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.rc-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.rc-head h1{margin:0;color:var(--admin-primary)}.rc-muted{color:#64748b}.rc-filter{display:grid;grid-template-columns:minmax(260px,1fr) 190px auto;gap:10px;align-items:end;margin-top:14px}.rc-actions{display:flex;gap:8px;flex-wrap:wrap}.rc-table{width:100%;border-collapse:collapse;min-width:1080px}.rc-table th,.rc-table td{padding:10px 11px;border-bottom:1px solid #e5edf6;text-align:left;vertical-align:top}.rc-table th{background:#f8fbff;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.02em;position:sticky;top:0}.rc-code{white-space:nowrap;font-weight:900;color:#0b1f5b}.rc-status{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:850}.rc-status.on{background:#ecfdf5;color:#047857}.rc-status.off{background:#f1f5f9;color:#64748b}.rc-empty{text-align:center;color:#64748b;padding:28px}.rc-count{font-weight:850;color:#0b1f5b}.rc-print-only{display:none}@media(max-width:820px){.rc-filter{grid-template-columns:1fr}.rc-actions .btn{flex:1}}@media print{nav,.admin-nav,.admin-topbar,.rc-filter,.rc-actions,.btn{display:none!important}.rc-card{border:0;box-shadow:none;padding:0}.rc-table{min-width:0;font-size:10px}.rc-print-only{display:block}.rc-page{display:block}}
</style>
<div class="rc-page">
  <div class="rc-card">
    <div class="rc-head"><div><h1>Relatório de Clientes</h1><p class="rc-muted" style="margin:5px 0 0">A tela só consulta a base completa quando você clicar em Gerar relatório. Assim o cadastro de Pessoas continua leve no uso diário.</p></div><?php if($generate && $err===''): ?><div class="rc-count"><?= number_format(count($rows),0,',','.') ?> cliente(s)</div><?php endif; ?></div>
    <?php if($err!==''): ?><div class="msg err" style="margin-top:12px"><?=rc_h($err)?></div><?php endif; ?>
    <form method="get" class="rc-filter">
      <label>Pesquisar<input type="search" name="q" value="<?=rc_h($q)?>" placeholder="Nome, código, CPF/CNPJ, cidade, telefone ou e-mail"></label>
      <label>Situação<select name="status"><option value="active" <?=$status==='active'?'selected':''?>>Ativos</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Inativos</option><option value="all" <?=$status==='all'?'selected':''?>>Todos</option></select></label>
      <div class="rc-actions"><button class="btn btn-primary" name="gerar" value="1">Gerar relatório</button><a class="btn" href="relatorio_clientes.php">Limpar</a></div>
    </form>
    <?php if($generate && $err===''): ?><div class="rc-actions" style="margin-top:12px"><a class="btn" href="?<?=rc_h(http_build_query(['q'=>$q,'status'=>$status,'gerar'=>1,'export'=>'csv']))?>">Exportar CSV</a><button class="btn" type="button" onclick="window.print()">Imprimir</button></div><?php endif; ?>
  </div>

  <div class="rc-card">
    <?php if(!$generate): ?><div class="rc-empty"><strong>Relatório ainda não gerado.</strong><br>Use os filtros acima e clique em <b>Gerar relatório</b>. Para listar todos os clientes ativos, deixe a pesquisa vazia.</div>
    <?php elseif($err===''): ?>
      <div class="rc-print-only"><h2>Relatório de Clientes</h2><p>Gerado em <?=date('d/m/Y H:i')?> · <?=number_format(count($rows),0,',','.')?> cliente(s)</p></div>
      <div class="table-wrap"><table class="rc-table"><thead><tr><th>Código</th><th>Nome / Razão social</th><th>Fantasia</th><th>CPF/CNPJ</th><th>IE</th><th>Contato</th><th>E-mail</th><th>Cidade/UF</th><th>Situação</th></tr></thead><tbody>
      <?php if(!$rows): ?><tr><td colspan="9" class="rc-empty">Nenhum cliente encontrado para os filtros informados.</td></tr><?php endif; ?>
      <?php foreach($rows as $r): ?><tr><td class="rc-code"><?=rc_h(rc_client_code($r))?></td><td><strong><?=rc_h($r['name']??'')?></strong></td><td><?=rc_h($r['fantasy_name']??'')?></td><td><?=rc_h($r['document']??'')?></td><td><?=rc_h($r['state_registration']??'')?></td><td><?=rc_h($r['phone']??'')?><?php if(trim((string)($r['whatsapp']??''))!==''): ?><br><span class="rc-muted">WhatsApp: <?=rc_h($r['whatsapp'])?></span><?php endif; ?></td><td><?=rc_h($r['email']??'')?></td><td><?=rc_h(trim((string)($r['city']??'').' / '.(string)($r['state']??''),' /'))?></td><td><span class="rc-status <?=!empty($r['active'])?'on':'off'?>"><?=!empty($r['active'])?'Ativo':'Inativo'?></span></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer(); ?>
