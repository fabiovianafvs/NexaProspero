<?php
declare(strict_types=1);

// Ponto Eletrônico > Acesso ao Nexa (V8): pedidos de liberação dos funcionários bloqueados fora
// da jornada, liberações dadas pelo gestor e quem tentou usar o Nexa fora do horário.
// A regra (desligado / avisar / bloquear) fica em Ponto > Configurações.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_acesso.php';
ponto_acesso_carregar();

$cid = ponto_guard($pdo, 'ponto_acesso');
$uid = (int)(auth_user_id() ?? 0);
$de = ponto_date_safe($_GET['de'] ?? '', date('Y-m-d', strtotime('-6 days')));
$ate = ponto_date_safe($_GET['ate'] ?? '', date('Y-m-d'));
if ($ate < $de) $ate = $de;
$back = 'ponto_acesso.php?' . http_build_query(['de' => $de, 'ate' => $ate]);

ponto_post_handle($back, function () use ($pdo, $cid, $uid) {
  if (!ponto_v8_ready($pdo)) throw new RuntimeException('O controle de acesso ainda não foi instalado no banco.');
  $a = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  if ($a === 'aprovar') { ponto_acesso_decidir($pdo, $cid, $id, true, (string)($_POST['duracao'] ?? ''), (string)($_POST['obs'] ?? ''), $uid); return 'Acesso liberado.'; }
  if ($a === 'negar') { ponto_acesso_decidir($pdo, $cid, $id, false, '', (string)($_POST['obs'] ?? ''), $uid); return 'Pedido negado.'; }
  if ($a === 'liberar') {
    ponto_acesso_liberar($pdo, $cid, (int)($_POST['colaborador_id'] ?? 0), (string)($_POST['inicio'] ?? ''), (string)($_POST['fim'] ?? ''), (string)($_POST['motivo'] ?? ''), $uid);
    return 'Liberação registrada.';
  }
  if ($a === 'cancelar') { ponto_acesso_cancelar($pdo, $cid, $id, $uid); return 'Liberação encerrada (fica no histórico).'; }
  throw new RuntimeException('Ação inválida.');
});

ponto_start($pdo, 'ponto_acesso', 'Acesso ao Nexa', 'Liga o uso do Nexa à jornada de trabalho: pedidos de liberação, liberações dadas e tentativas de uso fora do horário.', '<button class="pt-btn pri" type="button" onclick="ptOpen(\'paLib\')">+ Liberar acesso</button>');
if (!ponto_v8_ready($pdo)) {
  echo '<div class="pt-msg warn">O controle de acesso ainda não foi instalado no banco. O administrador da plataforma precisa aplicar a atualização "Ponto Eletrônico - V8 Acesso ao Nexa" no Atualizador do Banco.</div>';
  ponto_end();
  exit;
}

$cfg = ponto_config($pdo, $cid);
$modo = (string)($cfg['acesso_modo'] ?? 'DESLIGADO');
$pend = ponto_acesso_lista($pdo, $cid, 'PENDENTE');
$libs = array_values(array_filter(ponto_acesso_lista($pdo, $cid, null, 30), static fn($l) => $l['status'] !== 'PENDENTE'));
$evs = ponto_acesso_eventos_lista($pdo, $cid, $de, $ate);
$cols = ponto_options($pdo, $cid, 'colaboradores', false);
$users = ponto_company_users($pdo, $cid);
$agora = ponto_acesso_agora_empresa($pdo, $cid);
$stBadge = ['APROVADA' => 'g', 'NEGADA' => 'r', 'CANCELADA' => 'n'];
$dt = static fn($v) => $v ? date('d/m H:i', strtotime((string)$v)) : '';
?>
<section class="pt-card" style="margin-bottom:12px">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
    <div><b>Regra atual:</b>
      <span class="pt-badge <?= $modo === 'BLOQUEAR' ? 'r' : ($modo === 'AVISAR' ? 'y' : 'n') ?>"><?= ponto_h(PONTO_ACESSO_MODOS[$modo] ?? $modo) ?></span>
      <?php if ($modo !== 'DESLIGADO'): ?><span class="pt-hint">· entrada até <?= (int)$cfg['acesso_antes_min'] ?> min antes e saída até <?= (int)$cfg['acesso_depois_min'] ?> min depois da jornada<?= !empty($cfg['acesso_exigir_marcacao']) ? ' · exige o ponto de entrada' : '' ?><?= !empty($cfg['acesso_bloquear_saida']) ? ' · trava após a saída' : '' ?></span><?php endif; ?>
    </div>
    <?php if (ponto_can($pdo, 'ponto_configuracoes')): ?><a class="pt-btn sm" href="ponto_configuracoes.php">Alterar regra</a><?php endif; ?>
  </div>
  <?php if ($modo === 'DESLIGADO'): ?><p class="pt-hint" style="margin:8px 0 0">Desligado: o ponto não interfere no uso do Nexa. Para ligar, use Ponto &gt; Configurações &gt; "Acesso ao Nexa pelo ponto".</p><?php endif; ?>
</section>

<?php if ($pend): ?>
<section class="pt-card" style="margin-bottom:12px;border-color:#fde68a">
  <h2>Pedidos de liberação aguardando</h2>
  <table class="pt-table cards">
    <thead><tr><th>Colaborador</th><th>Pedido</th><th>Motivo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pend as $l): ?>
      <tr>
        <td data-l="Colaborador"><b><?= ponto_h($l['nome']) ?></b></td>
        <td data-l="Pedido"><?= ponto_h($dt($l['created_at'])) ?></td>
        <td data-l="Motivo"><?= ponto_h($l['motivo']) ?></td>
        <td class="act">
          <form method="post" style="display:inline-flex;gap:6px;flex-wrap:wrap;align-items:center"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="aprovar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <select name="duracao" style="min-height:32px"><option value="1h">por 1 hora</option><option value="2h">por 2 horas</option><option value="4h">por 4 horas</option><option value="dia">até o fim do dia</option></select>
            <button class="pt-btn sm ok" type="submit">Liberar</button></form>
          <details style="display:inline-block"><summary class="pt-btn sm red" style="list-style:none">Negar</summary>
            <form method="post" style="display:flex;gap:6px;margin-top:6px"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="negar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <input type="text" name="obs" maxlength="300" required placeholder="Motivo" style="width:170px;min-height:32px"><button class="pt-btn sm red" type="submit">Confirmar</button></form></details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="pt-card" style="margin-bottom:12px">
  <h2>Liberações dos últimos 30 dias</h2>
  <?php if (!$libs): ?><div class="pt-empty">Nenhuma liberação.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Colaborador</th><th>Período liberado</th><th>Motivo</th><th>Situação</th><th>Por</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($libs as $l): $ativa = $l['status'] === 'APROVADA' && (string)$l['fim'] >= $agora; ?>
      <tr class="<?= $l['status'] !== 'APROVADA' || !$ativa ? 'off' : '' ?>">
        <td data-l="Colaborador"><b><?= ponto_h($l['nome']) ?></b></td>
        <td data-l="Período"><?= $l['inicio'] ? ponto_h($dt($l['inicio']) . ' até ' . $dt($l['fim'])) : '—' ?></td>
        <td data-l="Motivo"><?= ponto_h($l['motivo']) ?><?= $l['obs_decisao'] ? '<br><span class="pt-hint">' . ponto_h($l['obs_decisao']) . '</span>' : '' ?></td>
        <td data-l="Situação"><span class="pt-badge <?= $ativa ? 'g' : ($stBadge[$l['status']] ?? 'n') ?>"><?= $ativa ? 'Em vigor' : ponto_h($l['status'] === 'APROVADA' ? 'Encerrada' : ucfirst(strtolower((string)$l['status']))) ?></span></td>
        <td data-l="Por"><span class="pt-hint"><?= $l['pedido_por'] ? 'Pedido do colaborador · ' : '' ?><?= ponto_h((string)($users[(int)$l['decidido_por']] ?? '')) ?></span></td>
        <td class="act"><?php if ($ativa): ?><form method="post"><?= ponto_csrf_field() ?><input type="hidden" name="action" value="cancelar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="pt-btn sm red" type="submit">Encerrar</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="pt-card">
  <h2>Uso do Nexa fora da jornada</h2>
  <form method="get" class="pt-search" style="margin-bottom:10px">
    <label class="f">De<input type="date" name="de" value="<?= ponto_h($de) ?>"></label>
    <label class="f">Até<input type="date" name="ate" value="<?= ponto_h($ate) ?>"></label>
    <button class="pt-btn" type="submit">Filtrar</button>
  </form>
  <?php if (!$evs): ?><div class="pt-empty">Ninguém tentou usar o Nexa fora da jornada no período.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th>Dia</th><th>Colaborador</th><th>Motivo</th><th>O que aconteceu</th><th>Vezes</th><th>Horário</th></tr></thead>
    <tbody>
    <?php foreach ($evs as $e): ?>
      <tr>
        <td data-l="Dia"><?= ponto_h(date('d/m/Y', strtotime((string)$e['data']))) ?></td>
        <td data-l="Colaborador"><b><?= ponto_h($e['nome']) ?></b></td>
        <td data-l="Motivo"><?= ponto_h(PONTO_ACESSO_MOTIVOS[$e['motivo']] ?? $e['motivo']) ?></td>
        <td data-l="Ação"><span class="pt-badge <?= $e['tipo'] === 'BLOQUEIO' ? 'r' : 'y' ?>"><?= $e['tipo'] === 'BLOQUEIO' ? 'Bloqueado' : 'Usou (aviso)' ?></span></td>
        <td data-l="Vezes"><?= (int)$e['vezes'] ?></td>
        <td data-l="Horário"><span class="pt-hint"><?= ponto_h(substr((string)$e['primeira_em'], 11, 5)) ?> a <?= ponto_h(substr((string)$e['ultima_em'], 11, 5)) ?><?= $e['pagina'] ? ' · ' . ponto_h($e['pagina']) : '' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="pt-hint" style="margin:8px 0 0">"Vezes" conta as verificações (no máximo uma por minuto de uso).</p>
  <?php endif; ?>
</section>

<dialog class="pt-modal" id="paLib">
  <form method="post">
    <?= ponto_csrf_field() ?><input type="hidden" name="action" value="liberar">
    <div class="pt-mh"><h3>Liberar acesso ao Nexa</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
    <div class="pt-mb">
      <div class="pt-grid">
        <label class="f s2">Colaborador<select name="colaborador_id" required><option value="">—</option><?php foreach ($cols as $k => $v): ?><option value="<?= (int)$k ?>"><?= ponto_h($v) ?></option><?php endforeach; ?></select></label>
        <label class="f">De<input type="datetime-local" name="inicio" required value="<?= ponto_h(str_replace(' ', 'T', substr($agora, 0, 16))) ?>"></label>
        <label class="f">Até<input type="datetime-local" name="fim" required value="<?= ponto_h(substr($agora, 0, 10) . 'T23:59') ?>"></label>
        <label class="f s2">Motivo<input type="text" name="motivo" maxlength="300" required placeholder="Ex.: inventário no sábado; hora extra combinada"></label>
      </div>
      <p class="pt-hint" style="margin-top:8px">Durante a liberação, o colaborador usa o Nexa sem a regra do ponto. Para quem nunca deve ser bloqueado, marque "Acesso ao Nexa sem restrição de horário" no cadastro do colaborador.</p>
    </div>
    <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Cancelar</button><button class="pt-btn pri" type="submit">Liberar</button></div>
  </form>
</dialog>
<?php ponto_end(); ?>
