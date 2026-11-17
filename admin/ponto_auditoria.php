<?php
declare(strict_types=1);

// Ponto Eletrônico > Auditoria: quem alterou o quê nos cadastros e configurações do Ponto.
// Só leitura. (Não é o registro legal das marcações: esse fica em Marcações, com NSR e hash.)
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$cid = ponto_guard($pdo, 'ponto_auditoria');
$de = ponto_date_safe($_GET['de'] ?? '', date('Y-m-d', strtotime('-30 days')));
$ate = ponto_date_safe($_GET['ate'] ?? '', date('Y-m-d'));
$ent = (string)($_GET['ent'] ?? '');
$entidades = ['colaboradores' => 'Colaboradores', 'departamentos' => 'Departamentos', 'cargos' => 'Cargos', 'estabelecimentos' => 'Estabelecimentos',
  'jornadas' => 'Jornadas', 'escalas' => 'Escalas', 'locais' => 'Locais', 'feriados' => 'Feriados', 'configuracoes' => 'Configurações'];
if (!isset($entidades[$ent])) $ent = '';
$acoes = ['criar' => 'Cadastrou', 'alterar' => 'Alterou', 'inativar' => 'Inativou', 'reativar' => 'Reativou', 'trocar_escala' => 'Trocou a escala',
  'encerrar_escala' => 'Encerrou a escala', 'incluir_foto' => 'Incluiu a foto', 'trocar_foto' => 'Trocou a foto', 'remover_foto' => 'Removeu a foto'];

$where = 'a.company_id = ? AND a.created_at >= ? AND a.created_at < ?';
$params = [$cid, $de . ' 00:00:00', date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00'];
if ($ent !== '') { $where .= ' AND a.entidade = ?'; $params[] = $ent; }
$st = $pdo->prepare("SELECT a.*, c.nome AS colaborador FROM ponto_auditoria a LEFT JOIN ponto_colaboradores c ON c.company_id = a.company_id AND c.id = a.colaborador_id WHERE $where ORDER BY a.id DESC LIMIT 300");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$users = ponto_company_users($pdo, $cid);
// Nome do registro alterado (quando ainda existe), para a lista ficar legível.
$names = [];
foreach (['departamentos', 'cargos', 'estabelecimentos', 'jornadas', 'escalas', 'locais'] as $r) $names[$r] = ponto_options($pdo, $cid, $r, false);
$st2 = $pdo->prepare('SELECT id, descricao FROM ponto_feriados WHERE company_id = ?');
$st2->execute([$cid]);
$names['feriados'] = array_column($st2->fetchAll(PDO::FETCH_ASSOC), 'descricao', 'id');

ponto_start($pdo, 'ponto_auditoria', 'Auditoria do Ponto', 'Registro de todas as alterações feitas nos cadastros e configurações do Ponto: quem fez, quando, de qual computador e o que mudou. Nada aqui pode ser editado ou apagado.');
?>
<section class="pt-card">
  <form method="get" class="pt-search">
    <label class="f" style="width:160px">De<input type="date" name="de" value="<?= ponto_h($de) ?>"></label>
    <label class="f" style="width:160px">Até<input type="date" name="ate" value="<?= ponto_h($ate) ?>"></label>
    <label class="f" style="width:190px">Cadastro<select name="ent"><option value="">Todos</option><?php foreach ($entidades as $k => $lb): ?><option value="<?= $k ?>" <?= $ent === $k ? 'selected' : '' ?>><?= $lb ?></option><?php endforeach; ?></select></label>
    <button class="pt-btn pri" type="submit">Filtrar</button>
  </form>
  <?php if (!$rows): ?><div class="pt-empty">Nenhuma alteração no período.</div><?php else: ?>
  <table class="pt-table cards">
    <thead><tr><th style="width:140px">Data/hora</th><th>Usuário</th><th>O que foi feito</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $alvo = $r['entidade'] === 'colaboradores' ? ($r['colaborador'] ?? '') : ($names[$r['entidade']][(int)$r['entidade_id']] ?? ''); ?>
      <tr>
        <td><?= ponto_h(date('d/m/Y H:i:s', strtotime((string)$r['created_at']))) ?></td>
        <td data-l="Usuário"><?= ponto_h($users[(int)$r['usuario_id']] ?? ($r['usuario_id'] ? 'Usuário #' . $r['usuario_id'] : 'Sistema')) ?></td>
        <td data-l="Ação"><b><?= ponto_h($acoes[$r['acao']] ?? $r['acao']) ?></b> · <?= ponto_h($entidades[$r['entidade']] ?? $r['entidade']) ?><?= $alvo !== '' ? ': ' . ponto_h($alvo) : ($r['entidade_id'] ? ' #' . (int)$r['entidade_id'] : '') ?></td>
        <td class="act"><button class="pt-btn sm" type="button" onclick="paShow(<?= (int)$r['id'] ?>)">Detalhes</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($rows) === 300): ?><p class="pt-hint">Mostrando as 300 mais recentes. Diminua o período para ver as anteriores.</p><?php endif; ?>
  <?php endif; ?>
</section>
<dialog class="pt-modal" id="paModal">
  <div class="pt-mh"><h3>Detalhes da alteração</h3><button class="pt-x" type="button" onclick="ptClose(this)">×</button></div>
  <div class="pt-mb" id="paBody"></div>
  <div class="pt-mf"><button class="pt-btn" type="button" onclick="ptClose(this)">Fechar</button></div>
</dialog>
<script>
(function(){
  const rows=<?= json_encode(array_column(array_map(static fn($r) => ['id' => (int)$r['id'], 'antes' => json_decode((string)$r['dados_anteriores_json'], true), 'depois' => json_decode((string)$r['dados_novos_json'], true), 'ip' => $r['ip'], 'ua' => $r['user_agent']], $rows), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const val=v=>v===null||v===undefined||v===''?'<span class="pt-hint">—</span>':(typeof v==='object'?'<code style="font-size:11.5px;white-space:pre-wrap">'+esc(JSON.stringify(v))+'</code>':esc(v));
  window.paShow=function(id){const r=rows[id];if(!r)return;const a=r.antes||{}, d=r.depois||{};const keys=[...new Set([...Object.keys(a),...Object.keys(d)])];
    document.getElementById('paBody').innerHTML=(keys.length?'<table class="pt-table"><thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead><tbody>'+keys.map(k=>'<tr><td><b>'+esc(k)+'</b></td><td>'+val(a[k])+'</td><td>'+val(d[k])+'</td></tr>').join('')+'</tbody></table>':'<p class="pt-hint">Sem detalhes de campos.</p>')+
      '<p class="pt-hint" style="margin-top:10px">IP: '+esc(r.ip||'—')+'<br>Navegador: '+esc(r.ua||'—')+'</p>';ptOpen('paModal');};
})();
</script>
<?php ponto_end(); ?>
