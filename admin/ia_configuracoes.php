<?php
declare(strict_types=1);

// Configurações > Agentes de IA: chave (da plataforma ou da empresa), modelo,
// opções do agente de entrada de notas, consumo do mês e últimas execuções.
// Sempre da empresa de login (sem F11).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/ia_entrada_notas.php';

$cid = (int)COMPANY_ID;
$allowed = (function_exists('access_is_company_master') && access_is_company_master())
  || (function_exists('access_has_permission') && access_has_permission($pdo, $cid, 'ia_configuracoes'));
if (!$allowed) {
  if (function_exists('access_render_denied')) access_render_denied('Seu usuário não possui permissão para configurar os agentes de IA.');
  http_response_code(403); exit('Acesso restrito.');
}
function iac_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_iac_csrf'])) $_SESSION['nexa_iac_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_iac_csrf'] ?? '');
$ready = ia_ready($pdo);
$msg = isset($_GET['ok']) ? (string)$_GET['ok'] : '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
    if (!$ready) throw new RuntimeException('Aplique a migração "Agentes de IA" no Atualizador do Banco.');
    $action = (string)($_POST['action'] ?? '');
    $cfg = ia_config($pdo, $cid);
    if ($action === 'salvar') {
      $cfg['enabled'] = !empty($_POST['enabled']);
      $cfg['key_source'] = ($_POST['key_source'] ?? '') === 'company' ? 'company' : 'platform';
      $newKey = trim((string)($_POST['api_key'] ?? ''));
      if (!empty($_POST['api_key_remove'])) $cfg['api_key'] = '';
      elseif ($newKey !== '') {
        if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $newKey)) throw new RuntimeException('A chave informada não parece uma chave da Anthropic (começa com sk-ant-).');
        $cfg['api_key'] = $newKey;
      }
      if ($cfg['key_source'] === 'company' && $cfg['api_key'] === '') throw new RuntimeException('Informe a chave da empresa ou escolha usar a chave da plataforma.');
      $model = (string)($_POST['model'] ?? '');
      $cfg['model'] = isset(ia_models()[$model]) ? $model : '';
      $e = $cfg['entrada'];
      $wasAuto = $e['enabled'] && $e['mode'] === 'auto';
      $e['enabled'] = !empty($_POST['entrada_enabled']);
      $e['mode'] = ($_POST['entrada_mode'] ?? '') === 'auto' ? 'auto' : 'manual';
      $e['auto_ciencia'] = !empty($_POST['entrada_auto_ciencia']);
      $e['create_products'] = !empty($_POST['entrada_create_products']);
      $e['default_category'] = mb_substr(trim((string)($_POST['entrada_default_category'] ?? '')), 0, 60) ?: 'XML / Compras';
      $e['default_margin'] = max(0.0, min(1000.0, (float)str_replace(',', '.', (string)($_POST['entrada_default_margin'] ?? '30'))));
      // No modo automático, só entram as notas que chegarem a partir de agora.
      if ($e['enabled'] && $e['mode'] === 'auto' && (!$wasAuto || $e['since'] === '')) $e['since'] = date('Y-m-d H:i:s');
      $cfg['entrada'] = $e;
      ia_config_save($pdo, $cid, $cfg);
      header('Location: ia_configuracoes.php?ok=' . rawurlencode('Configurações dos agentes de IA salvas.'));
      exit;
    }
    if ($action === 'testar') {
      $reason = ia_unavailable_reason($pdo, $cid, $cfg);
      if ($reason !== '') throw new RuntimeException($reason);
      $res = ia_claude_json($pdo, $cid, 'teste', 'Responda em português do Brasil.', 'Responda com ok=true e uma saudação curta para o Nexa Próspero.', [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['ok', 'mensagem'],
        'properties' => ['ok' => ['type' => 'boolean'], 'mensagem' => ['type' => 'string']],
      ], ['cfg' => $cfg, 'max_tokens' => 300, 'ref' => 'teste']);
      header('Location: ia_configuracoes.php?ok=' . rawurlencode('Conexão com a IA funcionando (' . ia_model($cfg) . '): ' . mb_substr((string)($res['mensagem'] ?? 'ok'), 0, 120)));
      exit;
    }
    if ($action === 'preparar_pendentes') {
      $reason = ia_unavailable_reason($pdo, $cid, $cfg);
      if ($reason !== '') throw new RuntimeException($reason);
      @set_time_limit(300);
      $ids = ia_entrada_pending_radar($pdo, $cid, null, 5);
      $done = 0;
      foreach ($ids as $rid) { ia_entrada_process_radar($pdo, $cid, $rid, 'manual', (int)($_SESSION['user_id'] ?? 0) ?: null); $done++; }
      header('Location: ia_configuracoes.php?ok=' . rawurlencode($done ? $done . ' nota(s) preparada(s) pelo agente. Confira na lista abaixo.' : 'Nenhuma nota do Radar com XML completo esperando preparo.'));
      exit;
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cfg = ia_config($pdo, $cid);
$models = ia_models();
$platformKey = ia_platform_key() !== '';
$reason = $ready ? ia_unavailable_reason($pdo, $cid, $cfg) : 'Aplique a migração "Agentes de IA" no Atualizador do Banco.';
$usage = ia_usage_month($pdo, $cid);
$runs = [];
if ($ready) {
  $st = $pdo->prepare('SELECT * FROM ai_agent_runs WHERE company_id=? ORDER BY COALESCE(finished_at, started_at, created_at) DESC, id DESC LIMIT 30');
  $st->execute([$cid]);
  $runs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$pending = $ready ? count(ia_entrada_pending_radar($pdo, $cid, null, 100)) : 0;
$statusChip = static function (string $s): string {
  [$label, $cls] = match ($s) {
    'PRONTA' => ['Pronta para conferir', 'ok'],
    'REVISAR' => ['Conferir itens', 'warn'],
    'PROCESSANDO' => ['Preparando…', ''],
    'IGNORADA' => ['Já lançada', 'off'],
    default => ['Erro', 'err'],
  };
  return '<span class="iac-chip ' . $cls . '">' . iac_h($label) . '</span>';
};

admin_topbar('Agentes de IA', 'ia_configuracoes');
?>
<style>
.iac-wrap{max-width:1180px;margin:14px auto 0;display:grid;gap:14px}.iac-card{background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.iac-card h2{margin:0 0 6px;color:#0b1f5b;font-size:20px}.iac-muted{color:#64748b;line-height:1.5;font-size:13px}
.iac-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px}.iac-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:4px}.iac-field input[type=text],.iac-field input[type=password],.iac-field input[type=number],.iac-field select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}
.iac-switch{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:14px;border:1px solid #dbe4f0;background:#f8fbff;color:#0b1f5b}.iac-switch input{width:20px;height:20px;flex:0 0 auto;margin-top:1px}.iac-switch b{display:block}.iac-switch span{display:block;font-size:12px;color:#64748b;font-weight:500;margin-top:2px;line-height:1.4}
.iac-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}.iac-head>div:first-child{flex:1;min-width:240px}
.iac-chip{display:inline-block;padding:2px 9px;border-radius:999px;font-weight:800;font-size:11px;background:#eef2ff;color:#3730a3;white-space:nowrap}.iac-chip.ok{background:#dcfce7;color:#166534}.iac-chip.warn{background:#fef3c7;color:#92400e}.iac-chip.err{background:#fee2e2;color:#991b1b}.iac-chip.off{background:#e2e8f0;color:#475569}
.iac-status{display:flex;gap:10px;align-items:center;padding:10px 14px;border-radius:14px;font-weight:700;margin-top:12px}.iac-status.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.iac-status.err{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}
.iac-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}.iac-kpi{border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;background:#f8fafc}.iac-kpi span{display:block;font-size:12px;color:#64748b;font-weight:700}.iac-kpi strong{font-size:20px;color:#0b1f5b}
.iac-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}.iac-table th{text-align:left;color:#475569;font-size:12px;padding:8px;border-bottom:1px solid #e2e8f0}.iac-table td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.iac-foot{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}.iac-key{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.iac-radio{display:grid;gap:8px;margin-top:6px}.iac-radio label{display:flex;gap:8px;align-items:flex-start;font-weight:600;color:#0b1f5b;font-size:14px}.iac-radio small{display:block;color:#64748b;font-weight:500}
.iac-next{border:1px dashed #cbd5e1;border-radius:14px;padding:10px 14px;color:#475569;font-size:13px;margin-top:12px}
@media(max-width:760px){.iac-table thead{display:none}.iac-table tr{display:block;border-bottom:1px solid #e2e8f0;padding:6px 0}.iac-table td{display:block;border:0;padding:3px 0}}
</style>
<div class="iac-wrap">
<?php if ($msg !== ''): ?><div class="msg ok"><?= iac_h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= iac_h($err) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="msg err">Os agentes de IA ainda não foram instalados. Peça ao administrador da plataforma para aplicar "Agentes de IA" no Atualizador do Banco.</div><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= iac_h($csrf) ?>"><input type="hidden" name="action" value="salvar">
<section class="iac-card">
  <div class="iac-head">
    <div><h2>Agentes de IA</h2><div class="iac-muted">Os agentes usam o Claude (Anthropic) para fazer tarefas repetitivas no Nexa e deixar tudo pronto para você conferir.</div></div>
  </div>
  <?php if ($reason === ''): ?><div class="iac-status ok">✓ IA pronta para uso (modelo <?= iac_h($models[ia_model($cfg)]['label'] ?? ia_model($cfg)) ?>).</div>
  <?php else: ?><div class="iac-status err">⚠ <?= iac_h($reason) ?></div><?php endif; ?>
  <div class="iac-grid">
    <label class="iac-switch"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>><div><b>Usar agentes de IA nesta empresa</b><span>Desligado, nenhum agente roda e nenhuma chamada à IA é feita.</span></div></label>
    <div class="iac-field"><label>Modelo</label><select name="model">
      <option value="">Padrão (<?= iac_h($models[ia_model(['model' => ''])]['label'] ?? '') ?>)</option>
      <?php foreach ($models as $id => $m): ?><option value="<?= iac_h($id) ?>" <?= $cfg['model'] === $id ? 'selected' : '' ?>><?= iac_h($m['label']) ?></option><?php endforeach; ?>
    </select></div>
  </div>
  <div class="iac-field" style="margin-top:12px"><label>Chave da IA</label>
    <div class="iac-radio">
      <label><input type="radio" name="key_source" value="platform" <?= $cfg['key_source'] === 'platform' ? 'checked' : '' ?>><div>Usar a chave da plataforma Nexa <small><?= $platformKey ? 'Configurada no servidor. O consumo entra no plano.' : 'Ainda não configurada no servidor.' ?></small></div></label>
      <label><input type="radio" name="key_source" value="company" <?= $cfg['key_source'] === 'company' ? 'checked' : '' ?>><div>Usar a chave própria da empresa <small>Crie em console.anthropic.com; o consumo é cobrado direto na conta da empresa.</small></div></label>
    </div>
    <div class="iac-key" style="margin-top:8px">
      <input type="password" name="api_key" autocomplete="new-password" placeholder="<?= $cfg['api_key'] !== '' ? iac_h(ia_mask_key($cfg['api_key']) . ' (deixe em branco para manter)') : 'sk-ant-...' ?>" style="flex:1;min-width:240px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:10px">
      <?php if ($cfg['api_key'] !== ''): ?><label style="font-size:13px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="api_key_remove" value="1"> Remover a chave da empresa</label><?php endif; ?>
    </div>
  </div>
  <div class="iac-kpis">
    <div class="iac-kpi"><span>Chamadas no mês</span><strong><?= (int)$usage['calls'] ?></strong></div>
    <div class="iac-kpi"><span>Tokens (entrada / saída)</span><strong style="font-size:16px"><?= number_format($usage['input'], 0, ',', '.') ?> / <?= number_format($usage['output'], 0, ',', '.') ?></strong></div>
    <div class="iac-kpi"><span>Custo estimado no mês</span><strong><?= $usage['usd'] > 0 && $usage['usd'] < 0.01 ? 'menos de US$ 0,01' : 'US$ ' . number_format($usage['usd'], 2, ',', '.') ?></strong></div>
  </div>
</section>

<section class="iac-card">
  <div class="iac-head">
    <div><h2>Agente de entrada de notas</h2><div class="iac-muted">Pega as NF-e de compra do Radar, gera a entrada, vincula os produtos (ou cadastra os novos), confere o fator de conversão e o CFOP de entrada e deixa a nota <b>pronta para você conferir</b>. A entrada no estoque e no contas a pagar continua sendo feita por você.</div></div>
  </div>
  <div class="iac-grid">
    <label class="iac-switch"><input type="checkbox" name="entrada_enabled" value="1" <?= $cfg['entrada']['enabled'] ? 'checked' : '' ?>><div><b>Ligar o agente de entrada de notas</b><span>Mostra o botão "Preparar com IA" no Radar.</span></div></label>
    <div class="iac-field"><label>Quando preparar</label><select name="entrada_mode">
      <option value="manual" <?= $cfg['entrada']['mode'] === 'manual' ? 'selected' : '' ?>>Manual: botão no Radar</option>
      <option value="auto" <?= $cfg['entrada']['mode'] === 'auto' ? 'selected' : '' ?>>Automático: ao chegar a nota</option>
    </select><div class="iac-muted" style="margin-top:4px"><?= $cfg['entrada']['mode'] === 'auto' && $cfg['entrada']['since'] !== '' ? 'Automático para as notas que chegaram desde ' . iac_h(date('d/m/Y H:i', strtotime($cfg['entrada']['since']))) . '.' : 'No automático, só as notas que chegarem depois de ligar são preparadas.' ?></div></div>
    <label class="iac-switch"><input type="checkbox" name="entrada_auto_ciencia" value="1" <?= $cfg['entrada']['auto_ciencia'] ? 'checked' : '' ?>><div><b>Registrar a Ciência da Emissão automaticamente</b><span>Só no modo automático. Notas que chegam só com o resumo precisam da ciência para a SEFAZ liberar o XML completo.</span></div></label>
    <label class="iac-switch"><input type="checkbox" name="entrada_create_products" value="1" <?= $cfg['entrada']['create_products'] ? 'checked' : '' ?>><div><b>Cadastrar produtos novos</b><span>Quando o produto da nota não existir no Nexa. O preço de venda usa a margem de produtos parecidos (mesmo NCM, grupo ou fornecedor).</span></div></label>
    <div class="iac-field"><label>Grupo padrão para produtos novos</label><input type="text" name="entrada_default_category" maxlength="60" value="<?= iac_h($cfg['entrada']['default_category']) ?>"><div class="iac-muted" style="margin-top:4px">Usado quando a IA não achar um grupo que combine.</div></div>
    <div class="iac-field"><label>Margem quando não houver produto parecido (%)</label><input type="number" step="0.01" min="0" max="1000" name="entrada_default_margin" value="<?= iac_h($cfg['entrada']['default_margin']) ?>"><div class="iac-muted" style="margin-top:4px">0 = cadastra sem preço para você definir.</div></div>
  </div>
  <div class="iac-next">Próximas etapas: opção de dar entrada direto, sem conferência, e tarefas agendadas (ex.: fechamento de caixa todo dia às 19h por e-mail ou WhatsApp).</div>
  <div class="iac-foot">
    <button class="btn btn-primary" type="submit">Salvar</button>
  </div>
</section>
</form>

<section class="iac-card">
  <div class="iac-head">
    <div><h2>Últimas execuções</h2><div class="iac-muted"><?= $pending ? (int)$pending . ' nota(s) do Radar com XML completo ainda não preparada(s).' : 'Nenhuma nota do Radar esperando preparo.' ?></div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post"><input type="hidden" name="csrf" value="<?= iac_h($csrf) ?>"><input type="hidden" name="action" value="testar"><button class="btn" type="submit">Testar conexão</button></form>
      <?php if ($pending && $reason === '' && $cfg['entrada']['enabled']): ?><form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Preparando…'"><input type="hidden" name="csrf" value="<?= iac_h($csrf) ?>"><input type="hidden" name="action" value="preparar_pendentes"><button class="btn btn-primary" type="submit">Preparar até 5 notas agora</button></form><?php endif; ?>
      <a class="btn" href="fiscal_radar_xml.php">Abrir o Radar</a>
    </div>
  </div>
  <?php if (!$runs): ?><div class="iac-muted" style="margin-top:10px">Nenhuma execução ainda.</div><?php else: ?>
  <table class="iac-table"><thead><tr><th>Quando</th><th>Nota</th><th>Situação</th><th>Resumo</th><th></th></tr></thead><tbody>
  <?php foreach ($runs as $r): $when = (string)($r['finished_at'] ?: $r['started_at'] ?: $r['created_at']); ?>
    <tr>
      <td style="white-space:nowrap"><?= iac_h(date('d/m/Y H:i', strtotime($when))) ?><div class="iac-muted"><?= $r['trigger_type'] === 'auto' ? 'automático' : 'manual' ?></div></td>
      <td><?= iac_h($r['title'] ?? '') ?></td>
      <td><?= $statusChip((string)$r['status']) ?></td>
      <td class="iac-muted"><?= iac_h($r['summary'] ?? '') ?></td>
      <td style="white-space:nowrap"><?php if ((int)($r['xml_import_id'] ?? 0) > 0): ?><a class="btn btn-sm" href="fiscal_xml_entrada.php?id=<?= (int)$r['xml_import_id'] ?>#iaEntrada">Conferir</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
</div>
<?php admin_footer(); ?>
