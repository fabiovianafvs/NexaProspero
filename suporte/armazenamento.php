<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';
require_once __DIR__ . '/../config/support_storage.php';
$user=require_support_user($pdo);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
function nss_e(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function nss_date(string $s): string {
    try { return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s'); }
    catch(Throwable $e) { return '—'; }
}
$queryText=trim((string)($_GET['q']??''));
$q=function_exists('mb_substr') ? mb_substr($queryText,0,90,'UTF-8') : substr($queryText,0,90);
$companyId=max(0,(int)($_GET['company_id']??$_POST['company_id']??0));
$notice='';$error='';
if (!isset($_SESSION['nexa_storage_reports']) || !is_array($_SESSION['nexa_storage_reports'])) $_SESSION['nexa_storage_reports']=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!support_verify_csrf((string)($_POST['csrf']??''))) {
        http_response_code(403);$error='Não foi possível confirmar a sessão de suporte.';
    } elseif ((time()-(int)($_SESSION['nexa_storage_last_run']??0))<20) {
        $error='Aguarde pelo menos 20 segundos entre diagnósticos manuais.';
    } elseif ($companyId<1) {
        $error='Escolha uma empresa para consultar.';
    } else {
        $st=$pdo->prepare('SELECT id FROM companies WHERE id=? LIMIT 1');
        $st->execute([$companyId]);
        if (!$st->fetchColumn()) $error='Empresa não encontrada.';
        else {
            $_SESSION['nexa_storage_last_run']=time();
            try {
                // Sem cron, sem escrita de arquivo, sem atualizar schema ou executar limpeza.
                $report=nss_report($pdo,$companyId);
                $_SESSION['nexa_storage_reports'][$companyId]=$report;
                $_SESSION['nexa_storage_reports']=array_slice($_SESSION['nexa_storage_reports'],-5,null,true);
                $_SESSION['nexa_storage_notice']='Consulta concluída. Valores do banco são estimados.';
                header('Location: /suporte/armazenamento.php?company_id='.$companyId,true,303);
                exit;
            } catch (Throwable $ex) {
                error_log('Suporte armazenamento (somente leitura): '.get_class($ex).': '.$ex->getMessage());
                $error='Falha ao calcular parte do armazenamento. Consulte o log PHP; nenhuma alteração foi realizada.';
            }
        }
    }
}
if (isset($_SESSION['nexa_storage_notice'])) {
    $notice=(string)$_SESSION['nexa_storage_notice'];unset($_SESSION['nexa_storage_notice']);
}
$rows=[];$selected=null;$summary=[];$hasAttachments=false;
try {
    $parts=[];$params=[];
    if ($q!=='') {
        $parts=['(c.name LIKE ? OR c.slug LIKE ?)'];
        $params=['%'.$q.'%','%'.$q.'%'];
    }
    $sql='SELECT c.id,c.name,c.slug FROM companies c'.($parts?' WHERE '.implode(' AND ',$parts):'').' ORDER BY c.name LIMIT 100';
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    if ($companyId>0) {
        $st=$pdo->prepare('SELECT id,name,slug FROM companies WHERE id=? LIMIT 1');
        $st->execute([$companyId]);$selected=$st->fetch(PDO::FETCH_ASSOC)?:null;
    }
    $hasAttachments=nss_table_exists($pdo,'nexa_person_attachments');
    if ($hasAttachments && $rows) {
        $ids=array_map('intval',array_column($rows,'id'));
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $st=$pdo->prepare("SELECT company_id,COUNT(*) unique_files,SUM(stored_size) total_stored FROM
         (SELECT company_id,storage_sha256,MAX(size_stored) stored_size
          FROM nexa_person_attachments WHERE company_id IN ($placeholders)
          GROUP BY company_id,storage_sha256) sub GROUP BY company_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $summary[(int)$a['company_id']]=$a;
    }
} catch (Throwable $ex) {
    error_log('Suporte armazenamento listagem: '.$ex->getMessage());
    $error='Não foi possível carregar a listagem de empresas ou os anexos.';
}
$report=$selected ? ($_SESSION['nexa_storage_reports'][$companyId]??null) : null;
$attachments=$report['attachments']??null;
$db=$report['db']??null;
$allFiles=$report['files']??[];
?><!doctype html>
<html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Armazenamento — Portal do Suporte | Nexa Próspero</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0b1f5b;--muted:#5b6475;--line:#dce7f5;--primary:#2454d6;--accent:#00b8ff;--shadow:0 18px 44px rgba(11,31,91,.09)}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 12% 10%,rgba(0,184,255,.1),transparent 28%),var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif}a{color:var(--primary)}.shell{max-width:1370px;margin:0 auto;padding:22px}.topbar{display:flex;justify-content:space-between;gap:16px;align-items:center;background:linear-gradient(135deg,#0b1f5b,#2454d6 70%,#00b8ff 140%);color:#fff;padding:17px 21px;border-radius:23px;box-shadow:0 20px 50px rgba(11,31,91,.18)}.brand{display:flex;gap:13px;align-items:center}.brand img{width:50px;height:50px;object-fit:contain;padding:5px;background:#fff;border-radius:13px}.brand strong{font-size:20px;display:block}.brand small{display:block;color:#dff6ff;font-size:12px;margin-top:2px}.nav{display:flex;gap:7px;flex-wrap:wrap;margin-top:7px}.nav a{color:#eaf8ff;padding:7px 10px;text-decoration:none;font-weight:850;font-size:13px;border-radius:999px}.nav a:hover,.nav a.active{background:rgba(255,255,255,.16);color:#fff}.user{font-size:12px;text-align:right;color:#eaf8ff}.user b{display:block;color:#fff;font-size:14px}.user a{color:#fff;font-weight:800}.hero{margin:23px 2px 16px}.hero h1{font-size:32px;letter-spacing:-.04em;margin:0 0 7px}.hero p{color:var(--muted);line-height:1.55;margin:0}.panel{background:#fff;border:1px solid rgba(36,84,214,.12);border-radius:20px;box-shadow:var(--shadow);padding:18px;margin-bottom:15px}.panel h2{font-size:19px;margin:0 0 12px}.filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.filters label{font-size:13px;font-weight:850;flex:1;min-width:230px}.filters input{display:block;height:42px;padding:0 12px;border-radius:10px;border:1px solid #b7c8dd;width:100%;margin-top:5px;font:inherit}.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;height:42px;min-width:110px;border:0;border-radius:11px;background:#2454d6;padding:0 16px;color:#fff;font-weight:850;font-size:14px;cursor:pointer}.btn.secondary{background:#e9f0fa;color:#173c8d}.btn:focus-visible,a:focus-visible{outline:3px solid var(--accent);outline-offset:2px}.hint{font-size:12px;color:var(--muted);line-height:1.6;margin-top:8px}.notice{padding:12px 14px;border-radius:12px;background:#eff6ff;border:1px solid #bfd9ff;font-weight:700;margin-bottom:14px}.notice.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:11px 9px;border-bottom:1px solid #e1e9f3;vertical-align:middle}th{background:#f5f9ff;color:#52627c;font-size:12px;white-space:nowrap}td small{display:block;margin-top:3px;color:var(--muted)}tr.active{background:#ecf7ff}.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.action{text-align:right}.metricgrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}.metric{background:#fff;border:1px solid #dbe7f7;border-radius:17px;padding:17px;box-shadow:0 8px 25px rgba(11,31,91,.05)}.metric .label{color:var(--muted);font-size:12px;font-weight:850}.metric strong{display:block;font-size:25px;letter-spacing:-.04em;margin:10px 0 4px;overflow-wrap:anywhere}.metric small{font-size:12px;color:var(--muted)}.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.mono{font-variant-numeric:tabular-nums}.tag{background:#f0f5ff;border-radius:7px;padding:3px 7px;font-size:11px;font-weight:800}.subtle{background:#f7fbff;border:1px solid #dce7f5;border-radius:12px;padding:11px 13px;color:#425675;font-size:12px;line-height:1.55}.statinfo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between}.empty{padding:15px 0;color:var(--muted)}@media(max-width:1050px){.metricgrid{grid-template-columns:repeat(2,minmax(0,1fr))}.two{grid-template-columns:1fr}}@media(max-width:620px){.shell{padding:10px}.topbar{align-items:flex-start}.brand img{width:42px;height:42px}.metricgrid{grid-template-columns:1fr}.hero h1{font-size:27px}.metric strong{font-size:22px}.user{max-width:110px;overflow-wrap:anywhere}.btn{width:100%}}
</style></head><body><main class="shell">
<header class="topbar"><div class="brand"><img src="/assets/logo-nexa-v6-2.png" alt="Nexa Próspero"><div><strong>Portal do Suporte</strong><small>Nexa Próspero</small><nav class="nav" aria-label="Menu do suporte"><a href="/suporte/index.php">Empresas</a><a href="/suporte/monitor_fiscal.php">Monitor Fiscal</a><a href="/suporte/whatsapp.php">WhatsApp</a><a href="/suporte/monitor_saude.php">Saúde do Sistema</a><a class="active" href="/suporte/armazenamento.php">Armazenamento</a></nav></div></div><div class="user"><b><?=nss_e($user['name'])?></b><?=nss_e($user['email'])?><div><a href="/suporte/logout.php">Sair</a></div></div></header>
<section class="hero"><h1>Armazenamento por empresa</h1><p>Consulte dados do ERP, anexos de pessoas e outros arquivos da aplicação, com separação por tipo. Diagnósticos completos são sempre manuais.</p></section>
<?php if($error!==''): ?><div class="notice err"><?=nss_e($error)?></div><?php endif; ?>
<?php if($notice!==''): ?><div class="notice"><?=nss_e($notice)?></div><?php endif; ?>
<section class="panel"><h2>Pesquisar empresas</h2><form action="/suporte/armazenamento.php" method="get" class="filters"><label for="q">Empresa ou subdomínio<input id="q" name="q" value="<?=nss_e($q)?>" placeholder="Digite para filtrar" autocomplete="off"></label><button class="btn" type="submit">Filtrar</button><a class="btn secondary" href="/suporte/armazenamento.php">Limpar</a></form><p class="hint">São exibidas até 100 empresas. A coluna de anexos considera arquivos únicos pelo identificador de conteúdo, evitando duplicidades dentro de cada empresa.</p>
<div class="table-wrap"><table><thead><tr><th>Empresa</th><th class="num">Anexos de pessoas</th><th class="num">Espaço armazenado (catálogo)</th><th class="num">Diagnóstico completo</th><th class="action">Ação</th></tr></thead><tbody>
<?php foreach($rows as $c): $id=(int)$c['id'];$a=$summary[$id]??null;$snap=$_SESSION['nexa_storage_reports'][$id]??null; ?>
<tr class="<?=$id===$companyId?'active':''?>"><td><b><?=nss_e($c['name'])?></b><small>#<?=$id?> · <?=nss_e($c['slug'])?></small></td><td class="num"><?=$hasAttachments?(int)($a['unique_files']??0):'—'?></td><td class="num"><?=$hasAttachments?nss_human((int)($a['total_stored']??0)):'—'?></td><td class="num"><?=is_array($snap)?nss_date((string)$snap['collected_at']):'Ainda não consultado'?></td><td class="action"><a href="/suporte/armazenamento.php?company_id=<?=$id?>">Detalhar</a></td></tr>
<?php endforeach;?>
<?php if(!$rows): ?><tr><td colspan="5">Nenhuma empresa encontrada.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if($selected): ?>
<section class="panel"><div class="statinfo"><div><h2 style="margin:0">Empresa #<?=$companyId?> · <?=nss_e($selected['name'])?></h2><div class="hint">Os valores são específicos da empresa selecionada; não representam o espaço total da VPS.</div></div><form method="post" action="/suporte/armazenamento.php?company_id=<?=$companyId?>"><input type="hidden" name="company_id" value="<?=$companyId?>"><input type="hidden" name="csrf" value="<?=nss_e(support_csrf_token())?>"><button class="btn" type="submit">Atualizar agora</button></form></div>
<?php if($report): ?><p class="hint">Última consulta manual: <strong><?=nss_e(nss_date((string)$report['collected_at']))?></strong>. Nenhuma leitura automática é executada ao entrar na página.</p><?php else: ?><div class="empty">Clique em <strong>Atualizar agora</strong> para calcular os dados do banco e os arquivos vinculados a esta empresa.</div><?php endif; ?></section>
<?php if($report): ?>
<div class="metricgrid">
<div class="metric"><span class="label">Dados do ERP • banco estimado</span><strong><?=nss_human((int)$db['bytes'])?></strong><small><?=number_format((int)$db['rows'],0,',','.')?> registros em <?=$db['tables']?> tabelas indexadas</small></div>
<div class="metric"><span class="label">Anexos de pessoas • disco privado</span><strong><?=nss_human($attachments['physical'])?></strong><small><?=number_format((int)$attachments['files'],0,',','.')?> arquivos únicos · <?=$attachments['references']?> vínculos</small></div>
<div class="metric"><span class="label">Outros arquivos identificados</span><strong><?=nss_human((int)$report['other_bytes'])?></strong><small>Fiscais, contábeis, imagens, O.S. e certificados</small></div>
<div class="metric"><span class="label">Total identificado • aproximado</span><strong><?=nss_human($report['total_estimate'])?></strong><small>Estimativa do banco + arquivos conferidos; não é cota contratada</small></div>
</div>
<div class="two">
<section class="panel"><h2>Anexos de pessoas</h2><table><tbody><tr><td>Arquivos distintos (deduplicados)</td><td class="num"><?=$attachments['files']?></td></tr><tr><td>Vínculos de anexos nos cadastros</td><td class="num"><?=$attachments['references']?></td></tr><tr><td>Tamanho original lógico dos anexos</td><td class="num"><?=nss_human((int)$attachments['original'])?></td></tr><tr><td>Tamanho armazenado (metadados)</td><td class="num"><?=nss_human((int)$attachments['stored'])?></td></tr><tr><td>Economia estimada (compressão + deduplicação)</td><td class="num"><?=nss_human(max(0,(int)$attachments['original']-(int)$attachments['stored']))?></td></tr><tr><td>Arquivos ausentes ou divergentes</td><td class="num"><?=$attachments['missing']?></td></tr></tbody></table>
<?php if($attachments['issue']!==''):?><p class="subtle"><?=nss_e($attachments['issue'])?></p><?php endif; ?></section>
<section class="panel"><h2>Arquivos de outros módulos</h2><div class="table-wrap"><table><thead><tr><th>Categoria</th><th class="num">Arquivos</th><th class="num">Espaço</th></tr></thead><tbody>
<?php foreach($allFiles as $label=>$v):?><tr><td><?=nss_e($label)?><?php if(!$v['available']||$v['partial']):?> <span class="tag">Parcial / indisponível</span><?php endif; ?><?php if(($v['unmapped']??0)>0):?><small class="hint">Não encontrados ou não atribuídos: <?=(int)$v['unmapped']?></small><?php endif; ?></td><td class="num"><?=$v['files']?></td><td class="num"><?=nss_human((int)$v['bytes'])?></td></tr><?php endforeach;?></tbody></table></div></section>
</div>
<section class="panel"><h2>Dados do ERP no MySQL — estimativa por empresa</h2><div class="subtle">O Nexa utiliza um banco compartilhado. O MySQL não informa diretamente o espaço físico ocupado pelas linhas de uma empresa. Esta estimativa combina registros contados por <code>company_id</code> com o tamanho médio das tabelas, incluindo índices. Não substitui uma medição de cota física.</div><p class="hint">Tabelas com índice e calculadas: <?=$db['tables']?> · Tabelas não atribuídas/indisponíveis: <?=$db['excluded']?><?php if($db['partial']):?> · <strong>Leitura parcial por limite de segurança.</strong><?php endif;?></p><div class="table-wrap"><table><thead><tr><th>Tabela do sistema</th><th class="num">Registros</th><th class="num">Espaço estimado</th></tr></thead><tbody><?php foreach(array_slice($db['detail'],0,35) as $t):?><tr><td><?=nss_e($t['table'])?></td><td class="num"><?=number_format($t['rows'],0,',','.')?></td><td class="num"><?=nss_human($t['bytes'])?></td></tr><?php endforeach;?><?php if(!$db['detail']):?><tr><td colspan="3">Nenhuma tabela indexada com registros atribuíveis nesta consulta.</td></tr><?php endif;?></tbody></table></div><?php if(count($db['detail'])>35):?><div class="hint">Exibindo as 35 maiores tabelas. Os indicadores consideram todas as tabelas calculadas.</div><?php endif;?></section>
<section class="panel"><h2>Sobre a cobertura</h2><div class="subtle">Contabilizamos somente arquivos cuja empresa é identificável pela pasta ou por um vínculo de cadastro: XMLs fiscais, NFS-e, pacotes contábeis, fotos de O.S., certificados digitais, imagens de produtos referenciadas e anexos privados de pessoas. Arquivos compartilhados ou sem atribuição segura (como backups gerais, logs, cache, alguns logos/capas e uploads órfãos) ficam fora dos totais. Uma mesma imagem física de produto pode estar vinculada a mais de uma empresa; os totais individuais não devem ser somados como consumo físico exclusivo da VPS. Nenhum arquivo foi aberto, removido ou alterado. Se houver itens parciais ou tabela ausente, o total deve ser interpretado como cobertura parcial, não consumo completo.</div></section>
<?php endif; ?>
<?php elseif($companyId>0):?><div class="notice err">Empresa selecionada não encontrada.</div><?php endif; ?>
</main></body></html>
