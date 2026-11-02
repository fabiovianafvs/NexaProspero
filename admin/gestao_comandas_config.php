<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_comanda_setup')
  : (int)COMPANY_ID;
$setupCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_comanda_setup');
$setupCsrf = $setupCross && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
$ctxUrl = static function(string $url) use ($setupCross): string {
  return $setupCross && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};

if ($setupCross && (!function_exists('multi_company_context_permission_allowed') || !multi_company_context_permission_allowed($pdo, 'gestao_comandas'))) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para configurar Comandas.');
}
saas_require_company_module($pdo, $companyId, 'comandas', 'Cadastro e configuração de Comandas');
comanda_ensure_schema($pdo); // no F11 cruzado é no-op por NEXA_MULTI_COMPANY_NO_RUNTIME_DDL.

$err = '';
$editId = (int)($_GET['edit'] ?? 0);
$isNew = !empty($_GET['new']);

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($setupCross && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirado. Atualize a página e tente novamente.');
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'create_comanda') {
      $codigo = trim((string)($_POST['codigo'] ?? ''));
      if ($codigo === '') throw new RuntimeException('Informe o código da comanda.');
      $nome = trim((string)($_POST['nome'] ?? ''));
      $matricula = trim((string)($_POST['matricula'] ?? ''));
      $status = strtoupper(trim((string)($_POST['status'] ?? 'LIVRE')));
      if (!in_array($status, ['LIVRE','OCUPADA','BLOQUEADA'], true)) $status = 'LIVRE';
      $publicOrderEnabled = !empty($_POST['public_order_enabled']) ? 1 : 0;
      $st = $pdo->prepare("INSERT INTO comandas (company_id, codigo, matricula, nome, status, public_token, public_order_enabled, updated_at) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, NOW())");
      $st->execute([$companyId, $codigo, $matricula, $nome, $status, comanda_random_public_token(), $publicOrderEnabled]);
      header('Location: ' . $ctxUrl('gestao_comandas_config.php?ok=' . rawurlencode('Comanda cadastrada.'))); exit;
    }

    if ($action === 'update_comanda') {
      $id = (int)($_POST['id'] ?? 0);
      $codigo = trim((string)($_POST['codigo'] ?? ''));
      if ($id <= 0) throw new RuntimeException('Comanda inválida.');
      if ($codigo === '') throw new RuntimeException('Informe o código da comanda.');
      if (!comanda_find($pdo, $companyId, $id)) throw new RuntimeException('Comanda não pertence à empresa selecionada.');
      $nome = trim((string)($_POST['nome'] ?? ''));
      $matricula = trim((string)($_POST['matricula'] ?? ''));
      $status = strtoupper(trim((string)($_POST['status'] ?? 'LIVRE')));
      if (!in_array($status, ['LIVRE','OCUPADA','BLOQUEADA'], true)) $status = 'LIVRE';
      $publicOrderEnabled = !empty($_POST['public_order_enabled']) ? 1 : 0;
      $pdo->prepare("UPDATE comandas SET codigo=?, matricula=NULLIF(?, ''), nome=NULLIF(?, ''), status=?, public_order_enabled=?, updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$codigo, $matricula, $nome, $status, $publicOrderEnabled, $companyId, $id]);
      header('Location: ' . $ctxUrl('gestao_comandas_config.php?ok=' . rawurlencode('Comanda atualizada.'))); exit;
    }

    if ($action === 'add_commandas') {
      $start = max(1, (int)($_POST['start'] ?? 1));
      $qty = max(1, min(500, (int)($_POST['qty'] ?? 10)));
      $st = $pdo->prepare("INSERT IGNORE INTO comandas (company_id, codigo, nome, status, public_token, public_order_enabled) VALUES (?, ?, ?, 'LIVRE', ?, 1)");
      for ($i = $start; $i < $start + $qty; $i++) {
        $st->execute([$companyId, str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'Comanda ' . $i, comanda_random_public_token()]);
      }
      header('Location: ' . $ctxUrl('gestao_comandas_config.php?ok=' . rawurlencode($qty . ' comanda(s) gerada(s).'))); exit;
    }

    if ($action === 'save_settings') {
      $pdo->prepare('INSERT IGNORE INTO comanda_settings (company_id) VALUES (?)')->execute([$companyId]);
      $pdo->prepare('UPDATE comanda_settings SET show_only_occupied=?, allow_transfer=?, allow_multi_transfer=?, allow_item_transfer=?, allow_named_comanda=?, prompt_name_first_launch=?, allow_customer=?, auto_print_kitchen=?, direct_print_kitchen=?, inactive_alert_minutes=?, public_qr_enabled=?, public_qr_require_name=?, public_qr_require_phone=?, updated_at=NOW() WHERE company_id=?')
        ->execute([
          !empty($_POST['cmd_show_only_occupied']) ? 1 : 0,
          !empty($_POST['cmd_allow_transfer']) ? 1 : 0,
          !empty($_POST['cmd_allow_multi_transfer']) ? 1 : 0,
          !empty($_POST['cmd_allow_item_transfer']) ? 1 : 0,
          !empty($_POST['cmd_allow_named_comanda']) ? 1 : 0,
          !empty($_POST['cmd_prompt_name_first_launch']) ? 1 : 0,
          !empty($_POST['cmd_allow_customer']) ? 1 : 0,
          !empty($_POST['cmd_auto_print_kitchen']) ? 1 : 0,
          !empty($_POST['cmd_direct_print_kitchen']) ? 1 : 0,
          max(0, min(1440, (int)($_POST['cmd_inactive_alert_minutes'] ?? 10))),
          !empty($_POST['cmd_public_qr_enabled']) ? 1 : 0,
          !empty($_POST['cmd_public_qr_require_name']) ? 1 : 0,
          !empty($_POST['cmd_public_qr_require_phone']) ? 1 : 0,
          $companyId,
        ]);

      $ids = (array)($_POST['cmd_pg_id'] ?? []);
      $names = (array)($_POST['cmd_pg_name'] ?? []);
      $printers = (array)($_POST['cmd_pg_printer'] ?? []);
      $orders = (array)($_POST['cmd_pg_order'] ?? []);
      $active = (array)($_POST['cmd_pg_active'] ?? []);
      $upd = $pdo->prepare("UPDATE comanda_printer_groups SET name=?, printer_name=NULLIF(?, ''), sort_order=?, is_active=?, updated_at=NOW() WHERE company_id=? AND id=?");
      foreach ($ids as $idx => $rawId) {
        $groupId = (int)$rawId;
        if ($groupId <= 0) continue;
        $name = trim((string)($names[$idx] ?? ''));
        if ($name === '') continue;
        $upd->execute([$name, trim((string)($printers[$idx] ?? '')), (int)($orders[$idx] ?? 0), !empty($active[$groupId]) ? 1 : 0, $companyId, $groupId]);
      }

      $newName = trim((string)($_POST['cmd_new_group_name'] ?? ''));
      if ($newName !== '') {
        $pdo->prepare("INSERT INTO comanda_printer_groups (company_id, name, printer_name, sort_order, is_active) VALUES (?, ?, NULLIF(?, ''), ?, 1) ON DUPLICATE KEY UPDATE printer_name=VALUES(printer_name), sort_order=VALUES(sort_order), is_active=1, updated_at=NOW()")
          ->execute([$companyId, $newName, trim((string)($_POST['cmd_new_group_printer'] ?? '')), (int)($_POST['cmd_new_group_order'] ?? 99)]);
      }

      header('Location: ' . $ctxUrl('gestao_comandas_config.php?ok=' . rawurlencode('Configurações de Comandas e impressão salvas.'))); exit;
    }
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

try { comanda_ensure_public_tokens($pdo, $companyId); } catch (Throwable $e) {}
$comandas = comanda_rows($pdo, $companyId, false);
$edit = $editId > 0 ? comanda_find($pdo, $companyId, $editId) : null;
if ($editId > 0 && !$edit) $err = $err ?: 'Comanda não encontrada.';
if ($setupCross) {
  // No F11 não semeia comandas automaticamente. Cria apenas o registro mínimo de configuração,
  // caso a empresa ainda não o possua, preservando os cadastros reais do tenant selecionado.
  $settings = finance_query_one($pdo, 'SELECT * FROM comanda_settings WHERE company_id=? LIMIT 1', [$companyId]);
  if (!$settings) {
    try { $pdo->prepare('INSERT IGNORE INTO comanda_settings (company_id) VALUES (?)')->execute([$companyId]); } catch (Throwable $e) {}
    $settings = finance_query_one($pdo, 'SELECT * FROM comanda_settings WHERE company_id=? LIMIT 1', [$companyId]);
  }
  $settings = $settings ?: ['show_only_occupied'=>0,'allow_transfer'=>1,'allow_multi_transfer'=>1,'allow_item_transfer'=>1,'allow_named_comanda'=>1,'prompt_name_first_launch'=>0,'allow_customer'=>1,'auto_print_kitchen'=>0,'direct_print_kitchen'=>0,'inactive_alert_minutes'=>10,'public_qr_enabled'=>1,'public_qr_require_name'=>1,'public_qr_require_phone'=>1];
} else {
  $settings = comanda_settings($pdo, $companyId);
}
$printerGroups = comanda_printer_groups($pdo, $companyId, false);

$hidden = static function() use ($setupCross, $setupCsrf): void {
  if ($setupCross && $setupCsrf !== '') echo '<input type="hidden" name="mc_csrf" value="' . comanda_h($setupCsrf) . '">';
};

gestao_admin_start('Cadastro de Comandas', 'gestao_comandas_config');
gestao_page_header('Cadastro e Configuração de Comandas', 'Comandas, QR Code, setores de impressão e comportamento operacional sempre vinculados à empresa atual.', [
  ['label'=>'Abrir Comandas', 'href'=>$ctxUrl('gestao_comandas.php'), 'class'=>'btn'],
  ['label'=>'QR Codes das Mesas', 'href'=>$ctxUrl('gestao_comandas_qrcode.php'), 'class'=>'btn'],
  ['label'=>'Agentes de Impressão','href'=>$ctxUrl('gestao_comandas_agentes.php'),'class'=>'btn'],
  ['label'=>'Monitor de Impressão', 'href'=>$ctxUrl('gestao_comandas_impressao.php'), 'class'=>'btn'],
  ['label'=>'Nova Comanda', 'href'=>$ctxUrl('gestao_comandas_config.php?new=1'), 'class'=>'btn btn-primary'],
]);
gestao_tabs('gestao_comandas_config');
gestao_flash();
if ($setupCross) echo '<div class="msg ok"><strong>V11.32 · Configuração Multiempresa</strong> — alterações abaixo pertencem exclusivamente à empresa selecionada pelo F11. Fiscal permanece bloqueado.</div>';
if ($err) echo '<div class="msg err">' . comanda_h($err) . '</div>';
?>
<style>
.cmdcfg-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:14px;align-items:start}.cmdcfg-layout.has-aside{grid-template-columns:minmax(0,1fr) 360px}.cmdcfg-table{width:100%;border-collapse:separate;border-spacing:0 8px}.cmdcfg-table th{text-align:left;color:#64748b;font-size:12px;padding:0 8px}.cmdcfg-table td{background:#fff;border-top:1px solid #e5edf6;border-bottom:1px solid #e5edf6;padding:10px 8px;vertical-align:middle}.cmdcfg-table td:first-child{border-left:1px solid #e5edf6;border-radius:14px 0 0 14px}.cmdcfg-table td:last-child{border-right:1px solid #e5edf6;border-radius:0 14px 14px 0;text-align:right}.cmdcfg-badge{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900;background:#f1f5f9;color:#475569}.cmdcfg-badge.ocupada{background:#dcfce7;color:#15803d}.cmdcfg-badge.bloqueada{background:#fee2e2;color:#b91c1c}.cmdcfg-muted{color:#64748b;font-size:12px}.cmdcfg-total{font-weight:900;color:#047857}.cmdcfg-head-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.cmdcfg-modal{position:fixed;inset:0;z-index:360;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px)}.cmdcfg-modal.open{display:flex}.cmdcfg-modal-dialog{width:min(560px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:25px;padding:18px;box-shadow:0 28px 80px rgba(2,6,23,.35)}.cmdcfg-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.cmdcfg-modal-head h2{margin:0;color:var(--admin-primary)}.cmdcfg-modal-close{width:42px;height:42px;border:1px solid #dbe4f0;border-radius:14px;background:#fff;font-size:22px;cursor:pointer}.cmdcfg-settings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.cmdcfg-settings .checkline{border:1px solid #e2e8f0;border-radius:14px;padding:10px}.cmdcfg-print-table{width:100%;border-collapse:collapse}.cmdcfg-print-table th,.cmdcfg-print-table td{border-bottom:1px solid #e2e8f0;padding:8px;text-align:left}.cmdcfg-new-group{display:grid;grid-template-columns:1fr 110px 1fr;gap:8px;margin-top:12px}.cmdcfg-section{margin-top:14px}@media(max-width:1050px){.cmdcfg-layout.has-aside,.cmdcfg-settings,.cmdcfg-new-group{grid-template-columns:1fr}.cmdcfg-table thead{display:none}.cmdcfg-table tr{display:block;border:1px solid #dbe4f0;border-radius:16px;background:#fff;margin-bottom:8px;padding:8px}.cmdcfg-table td{display:block;border:0!important;padding:6px!important;text-align:left!important}.cmdcfg-table td:before{content:attr(data-label);display:block;color:#64748b;font-weight:850;font-size:12px;margin-bottom:3px}.section-head{align-items:stretch}.cmdcfg-head-actions{justify-content:stretch}.cmdcfg-head-actions .btn{flex:1;justify-content:center}}
</style>

<div class="cmdcfg-layout <?= ($isNew || $edit) ? 'has-aside' : '' ?>">
  <main>
    <div class="mg-card">
      <div class="section-head">
        <div><h2>Comandas cadastradas</h2><p class="cmdcfg-muted">Cada registro abaixo pertence somente à empresa atual.</p></div>
        <div class="cmdcfg-head-actions"><button class="btn" type="button" id="openBatchModal">Gerar em lote</button><a class="btn btn-primary" href="<?= comanda_h($ctxUrl('gestao_comandas_config.php?new=1')) ?>">Nova Comanda</a></div>
      </div>
      <table class="cmdcfg-table">
        <thead><tr><th>Código</th><th>Matrícula/RFID</th><th>Nome</th><th>Status</th><th>Pedido QR</th><th>Itens</th><th>Total</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($comandas as $c): $status = (string)($c['status'] ?? 'LIVRE'); ?>
          <tr>
            <td data-label="Código"><strong><?= comanda_h($c['codigo']) ?></strong></td><td data-label="Matrícula/RFID"><?= comanda_h($c['matricula'] ?: '-') ?></td><td data-label="Nome"><?= comanda_h($c['nome'] ?: '-') ?></td>
            <td data-label="Status"><span class="cmdcfg-badge <?= strtolower($status) ?>"><?= comanda_h($status) ?></span></td><td data-label="Pedido QR"><span class="cmdcfg-badge <?= !empty($c['public_order_enabled']) ? 'ocupada' : 'bloqueada' ?>"><?= !empty($c['public_order_enabled']) ? 'Ativo' : 'Desativado' ?></span></td>
            <td data-label="Itens"><?= (int)($c['itens_abertos'] ?? 0) ?></td><td data-label="Total" class="cmdcfg-total"><?= comanda_money((float)($c['total_aberto'] ?? 0)) ?></td>
            <td data-label="Ações"><a class="btn btn-sm" href="<?= comanda_h($ctxUrl('gestao_comandas_config.php?edit=' . (int)$c['id'])) ?>">Editar</a> <a class="btn btn-sm" href="<?= comanda_h($ctxUrl('gestao_comandas_qrcode.php?id=' . (int)$c['id'])) ?>">QR Code</a> <a class="btn btn-sm" href="<?= comanda_h($ctxUrl('gestao_comandas.php?id=' . (int)$c['id'] . '&step=abrir')) ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$comandas): ?><tr><td colspan="8"><div class="empty-state">Nenhuma comanda cadastrada.</div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <form method="post" class="mg-card cmdcfg-section">
      <input type="hidden" name="action" value="save_settings"><?php $hidden(); ?>
      <div class="section-head"><div><h2>Operação, QR Code e impressão automática</h2><p class="cmdcfg-muted">Estas opções e os setores abaixo são isolados por empresa.</p></div><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
      <div class="cmdcfg-settings">
        <label class="checkline"><input type="checkbox" name="cmd_show_only_occupied" value="1" <?= !empty($settings['show_only_occupied'])?'checked':'' ?>><span>Mostrar somente comandas ocupadas</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_allow_transfer" value="1" <?= !empty($settings['allow_transfer'])?'checked':'' ?>><span>Permitir transferência de comandas</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_allow_multi_transfer" value="1" <?= !empty($settings['allow_multi_transfer'])?'checked':'' ?>><span>Permitir múltiplas transferências</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_allow_item_transfer" value="1" <?= !empty($settings['allow_item_transfer'])?'checked':'' ?>><span>Permitir transferência de item</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_allow_named_comanda" value="1" <?= !empty($settings['allow_named_comanda'])?'checked':'' ?>><span>Permitir nome/apelido na comanda</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_prompt_name_first_launch" value="1" <?= !empty($settings['prompt_name_first_launch'])?'checked':'' ?>><span>Pedir nome no primeiro lançamento</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_allow_customer" value="1" <?= !empty($settings['allow_customer'])?'checked':'' ?>><span>Permitir cliente cadastrado</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_auto_print_kitchen" value="1" <?= !empty($settings['auto_print_kitchen'])?'checked':'' ?>><span>Imprimir automaticamente cozinha/bar</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_direct_print_kitchen" value="1" <?= !empty($settings['direct_print_kitchen'])?'checked':'' ?>><span>Enviar diretamente ao Agente de Impressão</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_public_qr_enabled" value="1" <?= !empty($settings['public_qr_enabled'])?'checked':'' ?>><span>Permitir pedidos pelo QR Code</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_public_qr_require_name" value="1" <?= !empty($settings['public_qr_require_name'])?'checked':'' ?>><span>Exigir nome no QR Code</span></label>
        <label class="checkline"><input type="checkbox" name="cmd_public_qr_require_phone" value="1" <?= !empty($settings['public_qr_require_phone'])?'checked':'' ?>><span>Exigir celular no QR Code</span></label>
      </div>
      <label style="display:block;margin-top:12px"><strong>Alerta de inatividade (minutos)</strong><input class="field" type="number" min="0" max="1440" name="cmd_inactive_alert_minutes" value="<?= (int)($settings['inactive_alert_minutes'] ?? 10) ?>"></label>

      <h3 style="margin-top:18px">Setores / grupos de impressão</h3>
      <div style="overflow:auto"><table class="cmdcfg-print-table"><thead><tr><th>Grupo</th><th>Impressora</th><th>Ordem</th><th>Ativo</th></tr></thead><tbody>
      <?php foreach ($printerGroups as $g): ?>
        <tr><td><input type="hidden" name="cmd_pg_id[]" value="<?= (int)$g['id'] ?>"><input class="field" name="cmd_pg_name[]" value="<?= comanda_h($g['name']) ?>"></td><td><input class="field" name="cmd_pg_printer[]" value="<?= comanda_h($g['printer_name'] ?? '') ?>" placeholder="Nome da impressora"></td><td><input class="field" name="cmd_pg_order[]" value="<?= (int)($g['sort_order'] ?? 0) ?>" inputmode="numeric"></td><td><label class="checkline"><input type="checkbox" name="cmd_pg_active[<?= (int)$g['id'] ?>]" value="1" <?= !empty($g['is_active'])?'checked':'' ?>><span>Sim</span></label></td></tr>
      <?php endforeach; ?>
      <?php if (!$printerGroups): ?><tr><td colspan="4">Nenhum setor cadastrado. Use os campos abaixo.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="cmdcfg-new-group"><label>Novo grupo<input class="field" name="cmd_new_group_name" placeholder="Ex.: Sobremesas"></label><label>Ordem<input class="field" name="cmd_new_group_order" value="99" inputmode="numeric"></label><label>Impressora<input class="field" name="cmd_new_group_printer" placeholder="Nome da impressora"></label></div>
      <div class="mg-form-actions"><a class="btn" href="<?= comanda_h($ctxUrl('gestao_comandas_agentes.php')) ?>">Configurar agentes</a><a class="btn" href="<?= comanda_h($ctxUrl('gestao_comandas_qrcode.php')) ?>">QR Codes</a><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
    </form>
  </main>

  <?php if ($isNew || $edit): ?>
  <aside><div class="mg-card"><h2><?= $edit ? 'Editar comanda' : 'Nova comanda' ?></h2><form method="post" class="mg-form"><input type="hidden" name="action" value="<?= $edit ? 'update_comanda' : 'create_comanda' ?>"><?php $hidden(); ?><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <label>Código<input name="codigo" required value="<?= comanda_h($edit['codigo'] ?? '') ?>" placeholder="Ex.: 01, 25, MESA 4"></label><label>Matrícula/RFID<input name="matricula" value="<?= comanda_h($edit['matricula'] ?? '') ?>" placeholder="Código lido do cartão RFID"></label><label>Nome/descrição<input name="nome" value="<?= comanda_h($edit['nome'] ?? '') ?>" placeholder="Ex.: Comanda 01"></label>
    <label>Status<select name="status"><option value="LIVRE" <?= ($edit['status'] ?? 'LIVRE')==='LIVRE'?'selected':'' ?>>Livre</option><option value="OCUPADA" <?= ($edit['status'] ?? '')==='OCUPADA'?'selected':'' ?>>Ocupada</option><option value="BLOQUEADA" <?= ($edit['status'] ?? '')==='BLOQUEADA'?'selected':'' ?>>Bloqueada</option></select></label>
    <label class="checkline"><input type="checkbox" name="public_order_enabled" value="1" <?= (!$edit || !array_key_exists('public_order_enabled',$edit) || !empty($edit['public_order_enabled'])) ? 'checked' : '' ?>><span>Aceitar pedidos do cliente pelo QR Code</span></label>
    <div class="cmdcfg-muted">Evite alterar para Livre uma comanda que ainda possui itens em aberto.</div><div class="fiscal-form-actions" style="justify-content:flex-end"><a class="btn" href="<?= comanda_h($ctxUrl('gestao_comandas_config.php')) ?>">Cancelar</a><button class="btn btn-primary" type="submit">Salvar</button></div>
  </form></div></aside>
  <?php endif; ?>
</div>

<div class="cmdcfg-modal" id="batchModal" role="dialog" aria-modal="true" aria-labelledby="batchModalTitle"><div class="cmdcfg-modal-dialog"><div class="cmdcfg-modal-head"><div><h2 id="batchModalTitle">Gerar comandas em lote</h2><p class="cmdcfg-muted">Informe o primeiro número e quantas comandas devem ser criadas.</p></div><button type="button" class="cmdcfg-modal-close" id="closeBatchModal" aria-label="Fechar">×</button></div><form method="post" class="mg-form"><input type="hidden" name="action" value="add_commandas"><?php $hidden(); ?><label>Gerar a partir do número<input name="start" value="<?= count($comandas)+1 ?>" inputmode="numeric" min="1" required></label><label>Quantidade<input name="qty" value="10" inputmode="numeric" min="1" max="500" required></label><div class="fiscal-form-actions" style="justify-content:flex-end"><button type="button" class="btn" id="cancelBatchModal">Cancelar</button><button class="btn btn-primary" type="submit">Gerar comandas</button></div></form></div></div>
<script>
(function(){const modal=document.getElementById('batchModal');const open=document.getElementById('openBatchModal');const close=()=>modal?.classList.remove('open');open?.addEventListener('click',()=>{modal?.classList.add('open');setTimeout(()=>modal?.querySelector('input[name="start"]')?.focus(),50)});document.getElementById('closeBatchModal')?.addEventListener('click',close);document.getElementById('cancelBatchModal')?.addEventListener('click',close);modal?.addEventListener('click',e=>{if(e.target===modal)close()});document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});})();
</script>
<?php gestao_admin_end(); ?>
