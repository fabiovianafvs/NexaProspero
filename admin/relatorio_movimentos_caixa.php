<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/cash_financial_report.php';

// V11.62: relatório em leitura e exclusivo da empresa de entrada.
// Não amplia permissões de F11, nem altera o motor de movimentação do caixa.
$cid = (int) COMPANY_ID;
if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && (int) MULTI_COMPANY_CONTEXT_ID !== $cid) {
    access_render_denied('O relatório de movimentações de caixa não está homologado no contexto F11. Entre diretamente na empresa.');
    exit;
}
if (!function_exists('access_has_permission') || !access_has_permission($pdo, $cid, 'relatorio_movimentos_caixa')) {
    access_render_denied('Seu perfil não possui autorização para visualizar movimentações de todos os operadores.');
    exit;
}
function rmc_h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function rmc_money($v): string {
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}
function rmc_date_time($v): string {
    if (!$v) return '—';
    $ts = strtotime((string) $v);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}
function rmc_valid_date($v): ?string {
    $v = (string) $v;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

// Impressão sob demanda: reutiliza o mesmo resumo do fechamento, sem gravar dados.
// Esta rota continua protegida pela sessão e pela permissão do relatório.
$printSessionId = max(0, (int) ($_GET['print72'] ?? 0));
if ($printSessionId > 0) {
    try {
        $st = $pdo->prepare('SELECT s.*, u.name AS operator_name, b.name AS bank_name
            FROM gestao_cash_sessions s
            LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id
            LEFT JOIN finance_bank_accounts b ON b.company_id=s.company_id AND b.id=s.bank_account_id
            WHERE s.company_id=? AND s.id=? LIMIT 1');
        $st->execute([$cid, $printSessionId]);
        $printSession = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$printSession) { http_response_code(404); exit('Sessão de caixa não encontrada nesta empresa.'); }
        $printSummary = gestao_cash_summary($pdo, $cid, $printSessionId);
        $stCompany = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
        $stCompany->execute([$cid]);
        $companyName = trim((string) $stCompany->fetchColumn()) ?: 'Nexa Próspero';
    } catch (Throwable $e) {
        error_log('Nexa Relatório Caixa: falha no resumo térmico: ' . $e->getMessage());
        http_response_code(500);
        exit('Não foi possível preparar o resumo para impressão.');
    }
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Resumo Financeiro - Caixa #<?= $printSessionId ?></title>
<?= nexa_cash_financial_report_css() ?>
<style>
@page { size:72mm auto; margin:2mm; }
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#fff;color:#000}
body{font-family:Arial,sans-serif;font-size:9pt}
.rmc-thermal{width:68mm;max-width:68mm;margin:0 auto;padding:1mm 0}
.rmc-thermal-company{text-align:center;font-weight:bold;font-size:10pt;margin-bottom:2mm;overflow-wrap:anywhere}
.rmc-thermal .nexa-financial-receipt{padding:0;border:0;border-radius:0;background:#fff;color:#000;font:600 8.4pt/1.25 monospace}
.rmc-thermal .nexa-financial-receipt .nr-title{font-size:10pt}
.rmc-thermal .nexa-financial-receipt .nr-item{gap:2mm;padding:1.1mm 0}
.rmc-thermal .nexa-financial-receipt .nr-item span{flex:1 1 auto;overflow-wrap:anywhere}
.rmc-thermal .nexa-financial-receipt .nr-item strong{flex:0 1 45%;white-space:normal;overflow-wrap:anywhere}
.rmc-thermal .nexa-financial-receipt .nr-box{margin-top:2mm;border-radius:0}
.rmc-thermal .nexa-financial-receipt .nr-box h3{font:800 8.5pt/1.3 monospace;padding:1.5mm}
.rmc-thermal .nexa-financial-receipt .nr-box .nr-item{padding:1mm}
.rmc-thermal .nexa-financial-receipt .nr-final{font-size:9pt;padding:2mm}
.rmc-thermal .nexa-financial-receipt .nr-note{font-size:7.5pt;color:#333}
.rmc-thermal-footer{font-size:8pt;text-align:center;margin-top:3mm}
.rmc-thermal-actions{margin:5mm auto;display:flex;justify-content:center;gap:8px}
.rmc-thermal-actions button{cursor:pointer;padding:8px 12px;background:#13327a;color:#fff;border:0;border-radius:6px}
@media print{.rmc-thermal-actions{display:none!important}.rmc-thermal{width:68mm;max-width:68mm;margin:0}.rmc-thermal .nexa-financial-receipt{border:0;padding:0}}
</style>
</head>
<body>
<div class="rmc-thermal">
  <div class="rmc-thermal-company"><?= rmc_h($companyName) ?></div>
  <?= nexa_cash_financial_report_html((array) $printSummary, (array) $printSession, (string) ($printSession['operator_name'] ?? 'Não identificado'), (string) ($printSession['bank_name'] ?? '')) ?>
  <div class="rmc-thermal-footer">Impresso em <?= rmc_h(date('d/m/Y H:i')) ?></div>
</div>
<div class="rmc-thermal-actions"><button type="button" onclick="window.print()">Imprimir 72 mm</button><button type="button" onclick="window.close()">Fechar</button></div>
<?php if (!empty($_GET['autoprint'])): ?>
<script>window.addEventListener('load',function(){window.print();});</script>
<?php endif; ?>
</body>
</html>
<?php
    exit;
}

$today = date('Y-m-d');
$ini = rmc_valid_date($_GET['ini'] ?? '') ?: $today;
$fim = rmc_valid_date($_GET['fim'] ?? '') ?: $today;
$bankId = max(0, (int) ($_GET['bank_id'] ?? 0));
$operatorId = max(0, (int) ($_GET['operator_id'] ?? 0));
$selectedId = max(0, (int) ($_GET['sessao'] ?? 0));
$tab = (($_GET['aba'] ?? 'resumo') === 'movimentacoes') ? 'movimentacoes' : 'resumo';
$page = max(1, min(1000, (int) ($_GET['page'] ?? 1)));
$opsPage = max(1, min(1000, (int) ($_GET['ops_page'] ?? 1)));
$sessionPageSize = 30;
$opsPageSize = 150;
$error = '';
$sessions = [];
$selectedSession = null;
$selectedSummary = null;
$ops = [];
$sessionsCount = 0;
$opsCount = 0;

$url = static function (array $overrides = []) use ($ini, $fim, $bankId, $operatorId, $page, $selectedId, $tab, $opsPage): string {
    $params = array_merge([
        'ini' => $ini, 'fim' => $fim, 'bank_id' => $bankId,
        'operator_id' => $operatorId, 'page' => $page,
        'sessao' => $selectedId ?: null, 'aba' => $tab, 'ops_page' => $opsPage,
    ], $overrides);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return 'relatorio_movimentos_caixa.php?' . http_build_query($params);
};
try {
    if ($ini > $fim) throw new RuntimeException('A data inicial não pode ser posterior à data final.');
    if ((new DateTimeImmutable($ini))->diff(new DateTimeImmutable($fim))->days > 31) {
        throw new RuntimeException('Para proteger a VPS, pesquise no máximo 32 dias por vez.');
    }
    $conditions = ['s.company_id=?', 's.opened_at < DATE_ADD(?, INTERVAL 1 DAY)', '(s.closed_at IS NULL OR s.closed_at>=?)'];
    $pars = [$cid, $fim, $ini];
    if ($bankId) { $conditions[] = 's.bank_account_id=?'; $pars[] = $bankId; }
    if ($operatorId) { $conditions[] = 's.user_id=?'; $pars[] = $operatorId; }
    $where = implode(' AND ', $conditions);
    $st = $pdo->prepare('SELECT COUNT(*) FROM gestao_cash_sessions s WHERE ' . $where);
    $st->execute($pars);
    $sessionsCount = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT s.*, u.name AS operator_name, b.name AS bank_name
        FROM gestao_cash_sessions s
        LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id
        LEFT JOIN finance_bank_accounts b ON b.company_id=s.company_id AND b.id=s.bank_account_id
        WHERE ' . $where . ' ORDER BY s.opened_at DESC, s.id DESC LIMIT ' . $sessionPageSize . ' OFFSET ' . (($page - 1) * $sessionPageSize));
    $st->execute($pars);
    $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($selectedId > 0) {
        // Não permite abrir um ID de outra empresa nem fora do período/caixa/usuário filtrados.
        $st = $pdo->prepare('SELECT s.*, u.name AS operator_name, b.name AS bank_name
            FROM gestao_cash_sessions s
            LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id
            LEFT JOIN finance_bank_accounts b ON b.company_id=s.company_id AND b.id=s.bank_account_id
            WHERE ' . $where . ' AND s.id=? LIMIT 1');
        $st->execute(array_merge($pars, [$selectedId]));
        $selectedSession = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$selectedSession) throw new RuntimeException('Sessão não encontrada nos filtros informados.');
        if ($tab === 'resumo') {
            // Reaproveita o motor já utilizado na abertura e no fechamento.
            $selectedSummary = gestao_cash_summary($pdo, $cid, $selectedId);
        } else {
            // Esta aba detalha somente operações desta sessão dentro do período pesquisado.
            $opsPars = [$cid, $selectedId, $ini, $fim];
            $opsWhere = ' WHERE o.company_id=? AND o.cash_session_id=? AND o.created_at>=? AND o.created_at < DATE_ADD(?,INTERVAL 1 DAY)';
            $st = $pdo->prepare('SELECT COUNT(*) FROM gestao_cash_operations o' . $opsWhere);
            $st->execute($opsPars);
            $opsCount = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT o.id,o.cash_session_id,o.created_at,o.type,o.description,o.amount,o.order_id,o.movement_id,
                COALESCE(u.name,\'Não identificado\') AS operator_name, COALESCE(b.name,\'—\') AS bank_name
                FROM gestao_cash_operations o
                LEFT JOIN users u ON u.company_id=o.company_id AND u.id=o.user_id
                LEFT JOIN finance_bank_accounts b ON b.company_id=o.company_id AND b.id=o.bank_account_id'
                . $opsWhere . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $opsPageSize . ' OFFSET ' . (($opsPage - 1) * $opsPageSize));
            $st->execute($opsPars);
            $ops = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $selectedSession = null;
    $selectedSummary = null;
    $ops = [];
}
$banks = finance_query_all($pdo, 'SELECT id,name FROM finance_bank_accounts WHERE company_id=? ORDER BY name', [$cid]);
$users = finance_query_all($pdo, 'SELECT id,name FROM users WHERE company_id=? ORDER BY name', [$cid]);
$activeUserId = function_exists('auth_user_id') ? (int) (auth_user_id() ?? 0) : 0;
admin_topbar('Relatórios · Movimentações de Caixa', 'relatorio_movimentos_caixa');
?>
<style>
.rmc-wrap{max-width:1600px;margin:18px auto;padding:0 12px;color:#10265c}
.rmc-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.rmc-head h1{margin:0;font-size:25px;color:#132967}.rmc-sub{font-size:12px;color:#64748b;margin:4px 0 12px}
.rmc-filter{display:grid;grid-template-columns:145px 145px minmax(160px,1fr) minmax(180px,1fr) auto;gap:10px;align-items:end;background:#fff;border:1px solid #dbe4f0;padding:13px;border-radius:16px}
.rmc-filter label{font-size:12px;font-weight:800;display:block;margin-bottom:4px}.rmc-filter input,.rmc-filter select{min-width:0;width:100%;min-height:39px;border:1px solid #cbd5e1;border-radius:9px;padding:7px;background:#fff;color:#0f255f}
.rmc-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;white-space:nowrap;text-decoration:none;padding:8px 12px;min-height:39px;border-radius:9px;font-weight:800;font-size:12px;background:white;border:1px solid #cbd5e1;color:#0f255f;cursor:pointer}
.rmc-btn.primary{background:#2848b8;color:#fff;border-color:#2848b8}.rmc-btn.small{min-height:30px;padding:4px 10px;font-size:11px}.rmc-btn:hover{filter:brightness(.96)}
.rmc-actions{display:flex;gap:8px}.rmc-box{background:#fff;border:1px solid #dbe4f0;border-radius:17px;padding:14px;margin-top:13px;box-shadow:0 6px 28px rgba(30,50,100,.05)}
.rmc-box h2{margin:0 0 8px;font-size:18px;color:#152a67}.rmc-note{font-size:12px;color:#64748b;line-height:1.5}.rmc-warn{padding:12px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:10px;margin-top:9px}
.rmc-table-wrap{overflow:auto;max-width:100%;border:1px solid #dbe4f0;border-radius:12px}
.rmc-grid{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:1150px;font-size:12px}
.rmc-grid th,.rmc-grid td{padding:9px;border-bottom:1px solid #e2e8f0;border-right:1px solid #f1f5f9;text-align:left;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
.rmc-grid th{background:#f7faff;position:relative;color:#1e3a8a;white-space:nowrap;user-select:none}.rmc-grid th .rmc-sort{border:0;background:transparent;font:inherit;color:inherit;cursor:pointer}
.rmc-grid th .rmc-resize{position:absolute;right:0;top:0;bottom:0;width:7px;cursor:col-resize}
.rmc-grid tbody tr:nth-child(even) td{background:#edf3fb}.rmc-grid tbody tr:hover td{background:#dae6fb}.rmc-grid .rmc-col-filter{width:100%;min-width:20px;border:1px solid #cbd5e1;border-radius:5px;padding:5px;font-size:11px;margin-top:6px;background:#fff}
.rmc-grid .rmc-hidden{display:none}.rmc-grid tbody tr[hidden]{display:none}
.rmc-grid .rmc-action-col{width:95px;text-align:center}.rmc-grid td.rmc-action-col{white-space:nowrap}
.rmc-config{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px}
.rmc-config details{position:relative}.rmc-config summary{cursor:pointer}.rmc-config .rmc-choices{position:absolute;z-index:5;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 8px 24px #0002;padding:10px;min-width:180px;max-height:300px;overflow:auto}
.rmc-choices label{display:block;padding:4px}.rmc-choices input{vertical-align:middle}
.rmc-pages{display:flex;gap:9px;justify-content:flex-end;align-items:center;margin:10px 0}
.rmc-tabs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid #dbe4f0;padding-bottom:9px;margin:12px 0}
.rmc-tabs .rmc-btn.active{background:#233c9f;color:white;border-color:#233c9f}
.rmc-detail-meta{display:flex;flex-wrap:wrap;gap:7px 16px;margin:8px 0;color:#475569;font-size:12px}
.rmc-detail-meta strong{color:#0f255f}.rmc-status{font-size:10px;font-weight:800;border-radius:12px;padding:4px 8px;background:#e8f0ff;color:#1e40af}
.rmc-status.closed{background:#eaf8ed;color:#166534}.rmc-summary-wrap{max-width:850px;margin:10px auto}
@media(max-width:1100px){.rmc-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.rmc-actions{grid-column:span 2}.rmc-grid{min-width:1000px}}
@media(max-width:650px){.rmc-filter{grid-template-columns:1fr}.rmc-actions{grid-column:auto}.rmc-actions>*{flex:1}.rmc-head h1{font-size:21px}.rmc-wrap{padding:0 8px}.rmc-tabs>*{flex:1}}
</style>
<div class="rmc-wrap">
  <div class="rmc-head"><div><h1>Movimentações de Caixa</h1><p class="rmc-sub">Abertura e fechamento por operador, com resumo e operações detalhadas.</p></div></div>
  <form method="get" class="rmc-filter">
    <div><label for="rmcIni">Data inicial</label><input id="rmcIni" type="date" name="ini" value="<?= rmc_h($ini) ?>" required></div>
    <div><label for="rmcFim">Data final</label><input id="rmcFim" type="date" name="fim" value="<?= rmc_h($fim) ?>" required></div>
    <div><label for="rmcBank">Caixa</label><select id="rmcBank" name="bank_id"><option value="0">Todos os caixas</option><?php foreach ($banks as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $bankId === (int) $b['id'] ? 'selected' : '' ?>><?= rmc_h($b['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="rmcOperator">Usuário</label><select id="rmcOperator" name="operator_id"><option value="0">Todos os usuários</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $operatorId === (int) $u['id'] ? 'selected' : '' ?>><?= rmc_h($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="rmc-actions"><button type="submit" class="rmc-btn primary">Filtrar</button><a class="rmc-btn" href="relatorio_movimentos_caixa.php">Limpar</a></div>
  </form>
  <?php if ($error !== ''): ?><div class="rmc-warn" role="alert"><?= rmc_h($error) ?></div><?php endif; ?>
  <div class="rmc-box">
    <div class="rmc-head"><h2>Resumo de abertura / fechamento por caixa</h2><span class="rmc-note"><?= $sessionsCount ?> sessão(ões) no período</span></div>
    <p class="rmc-note">Uma linha por abertura de caixa. Selecione <strong>Abrir</strong> para consultar o demonstrativo financeiro e as movimentações. Sessões que atravessam o período são incluídas.</p>
    <div class="rmc-config"><details><summary class="rmc-btn small">Colunas ▾</summary><div class="rmc-choices" id="rmcSessionChooser"></div></details><span class="rmc-note">Colunas ajustáveis, ordenáveis, reorganizáveis e com pesquisa individual.</span></div>
    <div class="rmc-table-wrap">
      <table class="rmc-grid" id="rmcSessionGrid"><thead><tr>
      <?php $cols = [
          'sessao'=>['Sessão','number',95], 'caixa'=>['Caixa','text',165], 'usuario'=>['Usuário','text',165],
          'abertura'=>['Abertura','text',140], 'fechamento'=>['Fechamento','text',140],
          'vl_abertura'=>['Valor abertura','number',135], 'previsto'=>['Saldo previsto','number',135],
          'informado'=>['Fechamento informado','number',160], 'diferenca'=>['Diferença','number',115], 'situacao'=>['Situação','text',110],
      ]; foreach ($cols as $k=>$def): ?>
        <th data-col="<?= rmc_h($k) ?>" data-type="<?= rmc_h($def[1]) ?>" draggable="true" style="width:<?= (int) $def[2] ?>px"><button class="rmc-sort" type="button"><?= rmc_h($def[0]) ?> ⇵</button><div class="rmc-resize"></div><input class="rmc-col-filter" aria-label="Filtrar <?= rmc_h($def[0]) ?>" placeholder="Filtrar..."></th>
      <?php endforeach; ?><th class="rmc-action-col" data-fixed="true">Ações</th></tr></thead><tbody>
      <?php foreach ($sessions as $s):
        $openStatus = strtoupper((string)($s['status'] ?? 'ABERTO')) === 'ABERTO';
        $canShowClosing = !$openStatus && $s['closing_amount'] !== null;
        $canShowExpected = !$openStatus && $s['expected_amount'] !== null;
        $canShowDifference = !$openStatus && $s['difference_amount'] !== null;
      ?>
        <tr>
          <td data-col="sessao" data-sort="<?= (int)$s['id'] ?>">#<?= (int)$s['id'] ?></td>
          <td data-col="caixa"><?= rmc_h($s['bank_name'] ?? 'Sem caixa') ?></td>
          <td data-col="usuario"><?= rmc_h($s['operator_name'] ?? 'Não identificado') ?></td>
          <td data-col="abertura" data-sort="<?= rmc_h($s['opened_at'] ?? '') ?>"><?= rmc_date_time($s['opened_at'] ?? '') ?></td>
          <td data-col="fechamento" data-sort="<?= rmc_h($s['closed_at'] ?? '') ?>"><?= rmc_date_time($s['closed_at'] ?? '') ?></td>
          <td data-col="vl_abertura" data-sort="<?= rmc_h($s['opening_amount'] ?? 0) ?>"><?= rmc_money($s['opening_amount'] ?? 0) ?></td>
          <td data-col="previsto" data-sort="<?= $canShowExpected ? rmc_h($s['expected_amount']) : '' ?>"><?= $canShowExpected ? rmc_money($s['expected_amount']) : '—' ?></td>
          <td data-col="informado" data-sort="<?= $canShowClosing ? rmc_h($s['closing_amount']) : '' ?>"><?= $canShowClosing ? rmc_money($s['closing_amount']) : '—' ?></td>
          <td data-col="diferenca" data-sort="<?= $canShowDifference ? rmc_h($s['difference_amount']) : '' ?>"><?= $canShowDifference ? rmc_money($s['difference_amount']) : '—' ?></td>
          <td data-col="situacao"><span class="rmc-status <?= $openStatus?'':'closed' ?>"><?= $openStatus?'ABERTO':'FECHADO' ?></span></td>
          <td class="rmc-action-col" data-fixed="true"><a class="rmc-btn small <?= $selectedId===(int)$s['id']?'primary':'' ?>" href="<?= rmc_h($url(['sessao'=>(int)$s['id'],'aba'=>'resumo','ops_page'=>1])) ?>#rmc-detalhe">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$sessions): ?><tr class="rmc-empty"><td colspan="11" style="text-align:center;padding:16px">Nenhuma sessão encontrada para o período e os filtros selecionados.</td></tr><?php endif; ?>
      </tbody></table>
    </div>
    <div class="rmc-pages">
      <?php if ($page>1): ?><a class="rmc-btn small" href="<?=rmc_h($url(['page'=>$page-1,'sessao'=>null,'aba'=>'resumo','ops_page'=>1]))?>">Anterior</a><?php endif; ?>
      <span class="rmc-note">Página <?= $page ?> · <?= count($sessions) ?> de <?= $sessionsCount ?> sessões</span>
      <?php if ($page*$sessionPageSize<$sessionsCount): ?><a class="rmc-btn small" href="<?=rmc_h($url(['page'=>$page+1,'sessao'=>null,'aba'=>'resumo','ops_page'=>1]))?>">Próxima</a><?php endif; ?>
    </div>
  </div>
  <?php if ($selectedSession): ?>
  <section id="rmc-detalhe" class="rmc-box" aria-label="Detalhes da sessão selecionada">
    <div class="rmc-head"><h2>Caixa #<?= (int)$selectedSession['id'] ?> — <?= rmc_h($selectedSession['bank_name'] ?? 'Sem caixa') ?></h2>
      <a class="rmc-btn small" href="<?= rmc_h($url(['sessao'=>null,'aba'=>'resumo','ops_page'=>1])) ?>">Fechar detalhes</a>
    </div>
    <div class="rmc-detail-meta">
      <span><strong>Operador:</strong> <?= rmc_h($selectedSession['operator_name'] ?? 'Não identificado') ?></span>
      <span><strong>Abertura:</strong> <?= rmc_date_time($selectedSession['opened_at'] ?? '') ?></span>
      <span><strong>Fechamento:</strong> <?= rmc_date_time($selectedSession['closed_at'] ?? '') ?></span>
      <span><strong>Situação:</strong> <?= rmc_h($selectedSession['status'] ?? 'ABERTO') ?></span>
    </div>
    <nav class="rmc-tabs" aria-label="Abas de detalhamento">
      <a class="rmc-btn <?= $tab==='resumo'?'active':'' ?>" href="<?= rmc_h($url(['aba'=>'resumo','ops_page'=>1])) ?>#rmc-detalhe" <?= $tab==='resumo'?'aria-current="page"':'' ?>>Resumo Financeiro</a>
      <a class="rmc-btn <?= $tab==='movimentacoes'?'active':'' ?>" href="<?= rmc_h($url(['aba'=>'movimentacoes','ops_page'=>1])) ?>#rmc-detalhe" <?= $tab==='movimentacoes'?'aria-current="page"':'' ?>>Movimentações no período</a>
      <?php if ($tab==='resumo'): ?><a class="rmc-btn" href="<?= rmc_h('relatorio_movimentos_caixa.php?'.http_build_query(['print72'=>(int)$selectedSession['id'],'autoprint'=>1])) ?>" target="_blank" rel="noopener"><span aria-hidden="true">🖨</span> Imprimir resumo 72 mm</a><?php endif; ?>
    </nav>
    <?php if ($tab==='resumo' && $selectedSummary !== null): ?>
      <p class="rmc-note">Demonstrativo completo da abertura ao fechamento, com os mesmos cálculos do Caixa do Operador. Caixa aberto: valores provisórios.</p>
      <?= nexa_cash_financial_report_css() ?>
      <div class="rmc-summary-wrap"><?= nexa_cash_financial_report_html((array)$selectedSummary,(array)$selectedSession,(string)($selectedSession['operator_name']??'Não identificado'),(string)($selectedSession['bank_name']??'')) ?></div>
    <?php elseif ($tab==='movimentacoes'): ?>
      <div class="rmc-head"><h2>Movimentações da sessão <span class="rmc-note">(<?= $opsCount ?> registros no período)</span></h2></div>
      <p class="rmc-note">Esta grade respeita as datas informadas nos filtros. O Resumo Financeiro considera a sessão inteira, inclusive operações fora dessas datas.</p>
      <div class="rmc-config"><details><summary class="rmc-btn small">Colunas ▾</summary><div class="rmc-choices" id="rmcOperationChooser"></div></details><span class="rmc-note">Ordene, filtre e ajuste as colunas como no Cadastro de Produtos.</span></div>
      <div class="rmc-table-wrap"><table class="rmc-grid" id="rmcOperationGrid"><thead><tr>
      <?php $opCols=[
          'data'=>['Data/Hora','text',145], 'caixa'=>['Caixa','text',140], 'sessao'=>['Sessão','number',90],
          'usuario'=>['Usuário','text',155], 'tipo'=>['Operação','text',120], 'descricao'=>['Histórico','text',210],
          'pedido'=>['Pedido','number',100], 'movimento'=>['Mov. financeiro','number',145], 'valor'=>['Valor','number',130],
      ]; foreach ($opCols as $k=>$def): ?>
        <th data-col="<?=rmc_h($k)?>" data-type="<?=rmc_h($def[1])?>" draggable="true" style="width:<?=(int)$def[2]?>px"><button class="rmc-sort" type="button"><?=rmc_h($def[0])?> ⇵</button><div class="rmc-resize"></div><input class="rmc-col-filter" aria-label="Filtrar <?=rmc_h($def[0])?>" placeholder="Filtrar..."></th>
      <?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($ops as $o): ?><tr>
        <td data-col="data" data-sort="<?=rmc_h($o['created_at'])?>"><?=rmc_date_time($o['created_at'])?></td>
        <td data-col="caixa"><?=rmc_h($o['bank_name'])?></td>
        <td data-col="sessao" data-sort="<?=(int)$o['cash_session_id']?>">#<?=(int)$o['cash_session_id']?></td>
        <td data-col="usuario"><?=rmc_h($o['operator_name'])?></td>
        <td data-col="tipo"><?=rmc_h($o['type'])?></td>
        <td data-col="descricao"><?=rmc_h($o['description'])?></td>
        <td data-col="pedido" data-sort="<?=(int)$o['order_id']?>"><?= (int)$o['order_id']>0?'#'.(int)$o['order_id']:'—' ?></td>
        <td data-col="movimento" data-sort="<?=(int)$o['movement_id']?>"><?= (int)$o['movement_id']>0?'#'.(int)$o['movement_id']:'—' ?></td>
        <td data-col="valor" data-sort="<?=rmc_h($o['amount'])?>"><?=rmc_money($o['amount'])?></td>
      </tr><?php endforeach; ?>
      <?php if (!$ops): ?><tr class="rmc-empty"><td colspan="9" style="text-align:center;padding:16px">Nenhuma movimentação encontrada para essa sessão e período.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="rmc-pages">
        <?php if ($opsPage>1): ?><a class="rmc-btn small" href="<?=rmc_h($url(['ops_page'=>$opsPage-1]))?>#rmc-detalhe">Anterior</a><?php endif; ?>
        <span class="rmc-note">Página <?= $opsPage ?> · <?= count($ops) ?> de <?= $opsCount ?> movimentos</span>
        <?php if ($opsPage*$opsPageSize<$opsCount): ?><a class="rmc-btn small" href="<?=rmc_h($url(['ops_page'=>$opsPage+1]))?>#rmc-detalhe">Próxima</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<script>
(function(){
  'use strict';
  // Comportamento compartilhado de grid: preferência local por empresa/usuário e tipo de grade.
  function setupGrid(gridId, chooserId, key){
    const table=document.getElementById(gridId),chooser=document.getElementById(chooserId);
    if(!table||!chooser)return;
    const thead=table.tHead.rows[0],body=table.tBodies[0];
    const headers=()=>Array.from(thead.querySelectorAll('th[data-col]'));
    const keys=headers().map(h=>h.dataset.col), fixedHead=thead.querySelector('th[data-fixed]');
    let saved={};try{saved=JSON.parse(localStorage.getItem(key)||'{}')||{}}catch(e){}
    const state={order:Array.isArray(saved.order)?saved.order.filter(k=>keys.includes(k)):keys.slice(),hidden:Array.isArray(saved.hidden)?saved.hidden.filter(k=>keys.includes(k)):[],widths:(saved.widths&&typeof saved.widths==='object')?saved.widths:{}};
    keys.forEach(k=>{if(!state.order.includes(k))state.order.push(k)});
    function save(){try{localStorage.setItem(key,JSON.stringify(state))}catch(e){}}
    function applyColumns(){
      const hm=new Map(headers().map(h=>[h.dataset.col,h]));
      state.order.forEach(k=>{const h=hm.get(k);if(h)thead.appendChild(h)});if(fixedHead)thead.appendChild(fixedHead);
      Array.from(body.rows).filter(tr=>!tr.classList.contains('rmc-empty')).forEach(tr=>{
        const cm=new Map(Array.from(tr.querySelectorAll('td[data-col]')).map(td=>[td.dataset.col,td]));
        state.order.forEach(k=>{if(cm.has(k))tr.appendChild(cm.get(k))});
        const fixed=tr.querySelector('td[data-fixed]');if(fixed)tr.appendChild(fixed);
      });
      headers().forEach(h=>{const k=h.dataset.col;h.classList.toggle('rmc-hidden',state.hidden.includes(k));
        const width=Number(state.widths[k]);if(Number.isFinite(width)&&width>=68&&width<=650)h.style.width=width+'px';
      });
      body.querySelectorAll('td[data-col]').forEach(td=>td.classList.toggle('rmc-hidden',state.hidden.includes(td.dataset.col)));
    }
    function renderChooser(){chooser.replaceChildren();state.order.forEach(k=>{
      const h=headers().find(x=>x.dataset.col===k),label=(h?.querySelector('.rmc-sort')?.textContent||k).replace('⇵','').trim();
      const item=document.createElement('label'),check=document.createElement('input');check.type='checkbox';check.checked=!state.hidden.includes(k);
      check.addEventListener('change',()=>{state.hidden=check.checked?state.hidden.filter(x=>x!==k):[...state.hidden,k];save();applyColumns()});
      item.append(check,document.createTextNode(' '+label));chooser.appendChild(item);
    })}
    let dragging=null;
    headers().forEach(h=>{
      const k=h.dataset.col;
      h.addEventListener('dragstart',e=>{if(e.target.closest('input,button')){e.preventDefault();return;}dragging=k;e.dataTransfer.effectAllowed='move'});
      h.addEventListener('dragover',e=>e.preventDefault());
      h.addEventListener('drop',e=>{e.preventDefault();if(!dragging||dragging===k)return;state.order=state.order.filter(x=>x!==dragging);state.order.splice(state.order.indexOf(k),0,dragging);dragging=null;save();applyColumns();renderChooser()});
      const resize=h.querySelector('.rmc-resize');
      resize?.addEventListener('pointerdown',e=>{e.preventDefault();e.stopPropagation();const start=e.clientX,begin=h.offsetWidth;
        function move(ev){const val=Math.max(68,Math.min(650,begin+ev.clientX-start));h.style.width=val+'px';state.widths[k]=val;}
        function stop(){window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',stop);save()}
        window.addEventListener('pointermove',move);window.addEventListener('pointerup',stop);
      });
      h.querySelector('.rmc-sort')?.addEventListener('click',()=>{
        const ascending=h.dataset.direction!=='asc';headers().forEach(x=>delete x.dataset.direction);h.dataset.direction=ascending?'asc':'desc';
        const rows=Array.from(body.rows).filter(tr=>!tr.classList.contains('rmc-empty'));
        rows.sort((a,b)=>{const x=a.querySelector('td[data-col="'+k+'"]'),y=b.querySelector('td[data-col="'+k+'"]');
          const va=(x?.dataset.sort??x?.textContent??'').trim(),vb=(y?.dataset.sort??y?.textContent??'').trim();
          if(h.dataset.type==='number'&&va!==''&&vb!=='')return (ascending?1:-1)*(Number(va)-Number(vb));
          return (ascending?1:-1)*va.localeCompare(vb,'pt-BR',{numeric:true});
        });rows.forEach(tr=>body.appendChild(tr));
      });
    });
    function applyFilters(){const filters=headers().map(h=>[h.dataset.col,(h.querySelector('input')?.value||'').toLocaleLowerCase('pt-BR').trim()]).filter(x=>x[1]);
      Array.from(body.rows).filter(tr=>!tr.classList.contains('rmc-empty')).forEach(tr=>{tr.hidden=filters.some(([k,value])=>!(tr.querySelector('td[data-col="'+k+'"]')?.textContent||'').toLocaleLowerCase('pt-BR').includes(value))});
    }
    headers().forEach(h=>h.querySelector('input')?.addEventListener('input',applyFilters));
    applyColumns();renderChooser();
  }
  setupGrid('rmcSessionGrid','rmcSessionChooser','nexa.cash.report.sessions.v1162.<?= $cid ?>.<?= $activeUserId ?>');
  setupGrid('rmcOperationGrid','rmcOperationChooser','nexa.cash.report.movements.v1162.<?= $cid ?>.<?= $activeUserId ?>');
})();
</script>
<?php admin_footer(); ?>
