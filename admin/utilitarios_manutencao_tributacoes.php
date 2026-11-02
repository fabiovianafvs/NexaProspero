<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$isCross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
// Esta rotina nunca prepara schema em runtime. Se a estrutura estiver incompleta, falha de forma explícita.
$GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
require_once __DIR__ . '/../config/fiscal_module.php';
require_once __DIR__ . '/../config/maintenance_bulk.php';
require __DIR__ . '/_chrome.php';

$productCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'products_write') : (int)COMPANY_ID;
$fiscalCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'product_fiscal') : (int)COMPANY_ID;
if ($productCompanyId !== $fiscalCompanyId) {
  throw new RuntimeException('O contexto de Produtos e o contexto Fiscal não apontam para a mesma empresa. Selecione novamente a empresa pelo F11.');
}
$companyId = $productCompanyId;
$mcCsrf = $isCross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$mcToken = $isCross ? trim((string)(($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? ''))) : '';
$error = '';
$flash = nexa_bulk_flash_get();
$filters = nexa_bulk_tax_filters($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);

try {
  if (!nexa_bulk_table_exists($pdo, 'fiscal_product_tax') || !nexa_bulk_table_exists($pdo, 'fiscal_tax_profiles')) {
    throw new RuntimeException('A estrutura fiscal necessária não está instalada. Execute as migrations oficiais antes de usar esta manutenção.');
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nexa_bulk_csrf_check((string)($_POST['csrf'] ?? ''));
    if ($isCross && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('O contexto F11 expirou. Selecione novamente a empresa e repita a operação.');
    }
    if (strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'ALTERAR') {
      throw new RuntimeException('Digite ALTERAR no campo de confirmação.');
    }
    if ((string)($_POST['action'] ?? '') !== 'apply_tax') throw new RuntimeException('Operação inválida.');

    $scope = (string)($_POST['apply_scope'] ?? 'selected');
    if ($scope === 'filtered') {
      $ids = nexa_bulk_tax_filtered_ids($pdo, $companyId, $filters);
    } else {
      $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['product_ids'] ?? [])), static fn(int $v): bool => $v > 0)));
    }
    if (!$ids) throw new RuntimeException('Nenhum produto ou serviço foi selecionado para alteração.');

    $result = nexa_bulk_apply_tax_changes($pdo, $companyId, $ids, [
      'change_ncm' => !empty($_POST['change_ncm']),
      'ncm' => (string)($_POST['new_ncm'] ?? ''),
      'change_cest' => !empty($_POST['change_cest']),
      'cest' => (string)($_POST['new_cest'] ?? ''),
      'change_profile' => !empty($_POST['change_profile']),
      'tax_profile_id' => (int)($_POST['new_tax_profile_id'] ?? 0),
    ]);
    nexa_bulk_flash_set('ok', (int)($result['updated'] ?? 0) . ' item(ns) atualizado(s). A tributação foi reaplicada com os campos fiscais correlacionados e, nos produtos, os vínculos herdados de Operações/CFOPs foram sincronizados.');
    $query = http_build_query(array_filter($filters, static fn($v): bool => $v !== '' && $v !== 'ALL' && $v !== 0));
    $dest = 'utilitarios_manutencao_tributacoes.php' . ($query !== '' ? '?' . $query : '');
    header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url($dest) : $dest));
    exit;
  }

  $profiles = fiscal_tax_profiles($pdo, $companyId, true, '');
  $categories = nexa_bulk_categories($pdo, $companyId);
  $search = nexa_bulk_tax_search($pdo, $companyId, $filters, 500);
  $rows = $search['rows'];
  $total = (int)$search['total'];
} catch (Throwable $e) {
  $error = $e->getMessage();
  try { $profiles = fiscal_tax_profiles($pdo, $companyId, true, ''); } catch (Throwable $ignored) { $profiles = $profiles ?? []; }
  try { $categories = nexa_bulk_categories($pdo, $companyId); } catch (Throwable $ignored) { $categories = $categories ?? []; }
  try { $search = nexa_bulk_tax_search($pdo, $companyId, $filters, 500); $rows = $search['rows']; $total = (int)$search['total']; }
  catch (Throwable $ignored) { $rows = $rows ?? []; $total = $total ?? 0; }
}

admin_topbar('Utilitários - Manutenção de Tributações', 'manutencao_tributacoes');
?>
<style>
.taxb-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin:4px 0 18px}.taxb-head h1{margin:0 0 6px;font-size:28px}.taxb-head p{margin:0;color:#64748b;line-height:1.5}.taxb-badge{padding:7px 10px;border-radius:999px;background:#ecfdf5;color:#166534;font-size:12px;font-weight:800;white-space:nowrap}
.taxb-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:0 8px 22px rgba(15,23,42,.05)}.taxb-card h2{margin:0 0 5px;font-size:20px}.taxb-muted{color:#64748b;font-size:13px;line-height:1.5}.taxb-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:14px}.taxb-row label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:5px}.taxb-field{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:9px 10px;background:#fff}.taxb-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}.taxb-msg-ok,.taxb-msg-err{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:750}.taxb-msg-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.taxb-msg-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.taxb-info{padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;color:#1e3a8a;font-size:13px;line-height:1.5;margin:12px 0}.taxb-warning{padding:12px 14px;border:1px solid #fde68a;background:#fffbeb;border-radius:14px;color:#92400e;font-size:13px;line-height:1.5;margin:12px 0}.taxb-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;margin-top:12px}.taxb-table{width:100%;border-collapse:collapse;min-width:1050px}.taxb-table th,.taxb-table td{padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:12px;text-align:left;vertical-align:top}.taxb-table th{background:#f8fafc;color:#475569;position:sticky;top:0}.taxb-table tr:last-child td{border-bottom:0}.taxb-type{display:inline-flex;padding:3px 7px;border-radius:999px;background:#f1f5f9;font-weight:800;font-size:10px}.taxb-change-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}.taxb-change{border:1px solid #e2e8f0;border-radius:14px;padding:12px}.taxb-change>label{display:flex;gap:8px;align-items:center;font-weight:850;color:#0f172a;margin-bottom:9px}.taxb-scope{display:flex;gap:16px;flex-wrap:wrap;margin:12px 0}.taxb-scope label{font-size:13px;font-weight:750;color:#334155}.taxb-confirm{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:12px;color:#9a3412;margin-top:14px}.taxb-confirm .taxb-field{margin-top:8px;max-width:220px}.taxb-count{font-weight:850;color:#0f172a}
@media(max-width:1050px){.taxb-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.taxb-change-grid{grid-template-columns:1fr}}@media(max-width:600px){.taxb-grid{grid-template-columns:1fr}.taxb-head{display:block}.taxb-badge{display:inline-block;margin-top:8px}}
</style>
<div class="taxb-head"><div><h1>Manutenção de Tributações</h1><p>Pesquise, selecione e substitua NCM, CEST e/ou tributação em lote, usando as mesmas regras fiscais do cadastro individual.</p></div><?php if ($isCross): ?><span class="taxb-badge">F11 · Empresa #<?= $companyId ?></span><?php endif; ?></div>
<?php if (($flash['type'] ?? '') === 'ok'): ?><div class="taxb-msg-ok"><?= nexa_bulk_h($flash['message'] ?? '') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="taxb-msg-err"><?= nl2br(nexa_bulk_h($error)) ?></div><?php endif; ?>

<div class="taxb-card"><h2>Filtros</h2><form method="get"><?php if ($isCross): ?><input type="hidden" name="mc_ctx" value="<?= nexa_bulk_h($mcToken) ?>"><?php endif; ?><div class="taxb-grid">
  <div class="taxb-row"><label>Produto / serviço</label><input class="taxb-field" name="q" value="<?= nexa_bulk_h($filters['q']) ?>" placeholder="Nome ou código"></div>
  <div class="taxb-row"><label>Categoria</label><select class="taxb-field" name="categoria"><option value="">Todas</option><?php foreach ($categories as $cat): ?><option value="<?= nexa_bulk_h($cat['categoria']) ?>" <?= $filters['categoria']===(string)$cat['categoria']?'selected':'' ?>><?= nexa_bulk_h($cat['categoria']) ?></option><?php endforeach; ?></select></div>
  <div class="taxb-row"><label>NCM</label><input class="taxb-field" name="ncm" value="<?= nexa_bulk_h($filters['ncm']) ?>" inputmode="numeric" placeholder="Ex.: 22011000"></div>
  <div class="taxb-row"><label>Tipo</label><select class="taxb-field" name="tipo"><option value="ALL" <?= $filters['tipo']==='ALL'?'selected':'' ?>>Produtos e serviços</option><option value="PRODUCT" <?= $filters['tipo']==='PRODUCT'?'selected':'' ?>>Produtos</option><option value="SERVICE" <?= $filters['tipo']==='SERVICE'?'selected':'' ?>>Serviços</option></select></div>
  <div class="taxb-row"><label>Tributação atual</label><select class="taxb-field" name="tax_profile_id"><option value="0">Todas</option><?php foreach ($profiles as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$filters['tax_profile_id']===(int)$p['id']?'selected':'' ?>>[<?= nexa_bulk_h($p['profile_type'] ?? 'PRODUCT') ?>] <?= nexa_bulk_h(trim((string)($p['code'] ?? '') . ' - ' . (string)($p['name'] ?? ''), ' -')) ?></option><?php endforeach; ?></select></div>
</div><div class="taxb-actions"><a class="btn" href="<?= nexa_bulk_h(function_exists('multi_company_context_url') ? multi_company_context_url('utilitarios_manutencao_tributacoes.php') : 'utilitarios_manutencao_tributacoes.php') ?>">Limpar</a><button class="btn btn-primary" type="submit">Pesquisar</button></div></form></div>

<form method="post" onsubmit="return confirm('Confirma a manutenção fiscal em lote? Esta operação será executada em transação única.');">
<input type="hidden" name="csrf" value="<?= nexa_bulk_h(nexa_bulk_csrf_token()) ?>"><?php if ($isCross): ?><input type="hidden" name="mc_csrf" value="<?= nexa_bulk_h($mcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= nexa_bulk_h($mcToken) ?>"><?php endif; ?><input type="hidden" name="action" value="apply_tax">
<?php foreach ($filters as $k=>$v): ?><input type="hidden" name="<?= nexa_bulk_h($k) ?>" value="<?= nexa_bulk_h($v) ?>"><?php endforeach; ?>
<div class="taxb-card"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h2>Resultados</h2><div class="taxb-muted"><span class="taxb-count"><?= (int)$total ?></span> item(ns) encontrado(s). A grade exibe até 500 para seleção visual.</div></div><button class="btn" type="button" id="taxbToggleAll">Marcar/desmarcar grade</button></div>
<?php if ($total > 500): ?><div class="taxb-warning">A pesquisa encontrou mais de 500 itens. Você pode refinar os filtros ou escolher abaixo <strong>Todos os resultados do filtro (<?= (int)$total ?>)</strong>; nesse modo, a alteração alcança todos, inclusive os que não aparecem na grade.</div><?php endif; ?>
<div class="taxb-table-wrap"><table class="taxb-table"><thead><tr><th style="width:36px">Sel.</th><th>Produto / serviço</th><th>Categoria</th><th>Tipo</th><th>NCM</th><th>CEST</th><th>Tributação atual</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">Nenhum item encontrado com os filtros atuais.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><input class="taxb-select" type="checkbox" name="product_ids[]" value="<?= (int)$r['id'] ?>"></td><td><strong>#<?= (int)($r['numero_cadastro'] ?: $r['id']) ?> — <?= nexa_bulk_h($r['nome']) ?></strong></td><td><?= nexa_bulk_h($r['categoria']) ?></td><td><span class="taxb-type"><?= !empty($r['is_service'])?'SERVIÇO':'PRODUTO' ?></span></td><td><?= nexa_bulk_h($r['ncm']) ?></td><td><?= nexa_bulk_h($r['cest']) ?></td><td><?= nexa_bulk_h(trim((string)$r['tax_profile_code'] . ' - ' . (string)$r['tax_profile_name'], ' -')) ?: '<span class="taxb-muted">Sem tributação</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="taxb-scope"><label><input type="radio" name="apply_scope" value="selected" checked> Somente os itens marcados na grade</label><label><input type="radio" name="apply_scope" value="filtered"> Todos os <?= (int)$total ?> resultados do filtro atual</label></div>
</div>

<div class="taxb-card"><h2>Substituir dados fiscais</h2><div class="taxb-info"><strong>Tributação completa:</strong> ao substituir a Tributação, o sistema reaplica ISS/NBS/LC116 nos serviços, ICMS/IPI/PIS/COFINS nos produtos, IBS/CBS, CFOPs e os demais campos correlacionados que pertencem ao perfil. Não é apenas uma troca de código.</div>
<div class="taxb-change-grid">
  <div class="taxb-change"><label><input type="checkbox" name="change_ncm" value="1"> Alterar NCM</label><input class="taxb-field" name="new_ncm" maxlength="10" inputmode="numeric" placeholder="8 dígitos; vazio limpa"><div class="taxb-muted" style="margin-top:6px">Disponível somente para Produtos.</div></div>
  <div class="taxb-change"><label><input type="checkbox" name="change_cest" value="1"> Alterar CEST</label><input class="taxb-field" name="new_cest" maxlength="10" inputmode="numeric" placeholder="7 dígitos; vazio limpa"><div class="taxb-muted" style="margin-top:6px">Disponível somente para Produtos.</div></div>
  <div class="taxb-change"><label><input type="checkbox" name="change_profile" value="1"> Alterar Tributação</label><select class="taxb-field" name="new_tax_profile_id"><option value="0">Selecione...</option><?php foreach ($profiles as $p): ?><option value="<?= (int)$p['id'] ?>">[<?= nexa_bulk_h($p['profile_type'] ?? 'PRODUCT') ?>] <?= nexa_bulk_h(trim((string)($p['code'] ?? '') . ' - ' . (string)($p['name'] ?? ''), ' -')) ?></option><?php endforeach; ?></select><div class="taxb-muted" style="margin-top:6px">O perfil deve ser do mesmo tipo do item selecionado.</div></div>
</div>
<div class="taxb-confirm"><strong>Confirmação obrigatória:</strong> revise filtros, seleção e campos marcados. Digite <strong>ALTERAR</strong> antes de executar.<input class="taxb-field" name="confirm_text" autocomplete="off" required></div>
<div class="taxb-actions"><button class="btn btn-primary" type="submit" <?= $total<=0?'disabled':'' ?>>Aplicar manutenção fiscal</button></div></div>
</form>
<script>
(function(){const b=document.getElementById('taxbToggleAll');if(!b)return;b.addEventListener('click',function(){const items=[...document.querySelectorAll('.taxb-select')];const should=items.some(x=>!x.checked);items.forEach(x=>x.checked=should);});})();
</script>
<?php admin_footer(); ?>
