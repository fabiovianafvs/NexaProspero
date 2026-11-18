<?php
declare(strict_types=1);

// Cadastro simples do Transporte (lista + janela). Quem inclui define $TRANSP_ENTITY antes do require.
// Usado por: transporte_veiculos.php e transporte_motoristas.php.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_transporte.php';

$ent = transp_entity((string)$TRANSP_ENTITY);
$key = (string)$ent['key'];
$cid = transp_guard($pdo, (string)$ent['perm']);
$page = (string)$ent['page'];
$q = trim((string)($_GET['q'] ?? ''));
$status = in_array((string)($_GET['s'] ?? ''), ['ativos', 'inativos', 'todos'], true) ? (string)$_GET['s'] : 'ativos';
$back = $page . '?' . http_build_query(array_filter(['q' => $q, 's' => $status !== 'ativos' ? $status : null]));

transp_post_handle($back, function () use ($pdo, $cid, $key, $ent) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save') {
    $isNew = (int)($_POST['id'] ?? 0) === 0;
    transp_entity_save($pdo, $cid, $key, $_POST);
    return $ent['label'] . ($isNew ? ' cadastrado.' : ' alterado.');
  }
  if ($action === 'status') {
    $on = (string)($_POST['on'] ?? '') === '1';
    transp_entity_status($pdo, $cid, $key, (int)($_POST['id'] ?? 0), $on);
    return $ent['label'] . ($on ? ' reativado.' : ' inativado.');
  }
  throw new RuntimeException('Ação inválida.');
});

$ready = transp_ready($pdo);
$rows = $ready ? transp_entity_list($pdo, $cid, $key, $q, $status) : [];
$fkOptions = [];
if ($ready) foreach ($ent['fields'] as $col => $f) if ($f['type'] === 'fk') $fkOptions[$col] = transp_options($pdo, $cid, (string)$f['ref'], false);

$cell = static function (array $r, string $col) use ($ent, $fkOptions): string {
  $f = $ent['fields'][$col] ?? ['type' => 'text'];
  $v = $r[$col] ?? null;
  if ($v === null || $v === '') return '<span class="tr-hint">—</span>';
  switch ($f['type']) {
    case 'bool': return (int)$v ? '<span class="tr-badge n">Sim</span>' : '<span class="tr-hint">Não</span>';
    case 'date':
      $txt = transp_h(date('d/m/Y', strtotime((string)$v)));
      return $col === 'cnh_validade' && (string)$v < date('Y-m-d') ? '<span class="tr-badge r">Vencida ' . $txt . '</span>' : $txt;
    case 'enum': return transp_h($f['options'][$v] ?? $v);
    case 'fk': return transp_h($fkOptions[$col][(int)$v] ?? ('#' . $v));
    case 'doc':
      $d = (string)$v;
      return transp_h(strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : (strlen($d) === 14 ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12) : $d));
    case 'placa': return '<span style="font-family:ui-monospace,monospace;letter-spacing:.06em;white-space:nowrap">' . transp_h(substr((string)$v, 0, 3) . '-' . substr((string)$v, 3)) . '</span>';
    default: return transp_h($v);
  }
};

transp_start($pdo, (string)$ent['perm'], (string)$ent['plural'], (string)$ent['hint'], '<button class="tr-btn pri" type="button" onclick="trNew()">+ Cadastrar</button>');
?>
<section class="tr-card">
  <form method="get" class="tr-search">
    <label class="f grow"><span>Pesquisar <span class="tr-kbd" title="Tecla F8 vai direto para a pesquisa">F8</span></span><input type="text" name="q" value="<?= transp_h($q) ?>" data-tr-search placeholder="Digite e tecle Enter"></label>
    <label class="f" style="width:150px">Situação<select name="s" onchange="this.form.submit()"><option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option><option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option><option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option></select></label>
    <button class="tr-btn" type="submit">Pesquisar</button>
    <button class="tr-btn pri" type="button" onclick="trNew()">Cadastrar</button>
  </form>
  <?php if (!$rows): ?>
    <div class="tr-empty"><?= $q !== '' ? 'Nada encontrado para "' . transp_h($q) . '".' : 'Nenhum ' . transp_h(mb_strtolower((string)$ent['label'])) . ' cadastrado ainda.' ?></div>
  <?php else: ?>
  <table class="tr-table cards">
    <thead><tr><?php foreach ($ent['list'] as $col): ?><th><?= transp_h($ent['fields'][$col]['label']) ?></th><?php endforeach; ?><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $on = (int)$r['status'] === 1; ?>
      <tr class="<?= $on ? '' : 'off' ?>">
        <?php foreach ($ent['list'] as $i => $col): ?><td <?= $i > 0 ? 'data-l="' . transp_h($ent['fields'][$col]['label']) . '"' : '' ?>><?= $i === 0 ? '<b>' . $cell($r, $col) . '</b>' : $cell($r, $col) ?></td><?php endforeach; ?>
        <td data-l="Situação"><?= $on ? '<span class="tr-badge g">Ativo</span>' : '<span class="tr-badge n">Inativo</span>' ?></td>
        <td class="act">
          <button class="tr-btn sm" type="button" onclick='trEdit(<?= json_encode((int)$r['id']) ?>)'>Editar</button>
          <form method="post" style="display:inline" data-confirm="<?= $on ? 'Inativar este cadastro? Ele continua nos documentos já emitidos.' : '' ?>"><?= transp_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>"><button class="tr-btn sm <?= $on ? 'red' : '' ?>" type="submit"><?= $on ? 'Inativar' : 'Reativar' ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<dialog class="tr-modal" id="trForm">
  <form method="post" id="trFormEl">
    <div class="tr-mh"><h3 id="trFormTitle"><?= transp_h($ent['label']) ?></h3><button class="tr-x" type="button" onclick="trClose(this)" aria-label="Fechar">×</button></div>
    <div class="tr-mb">
      <?= transp_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
      <div class="tr-grid">
      <?php foreach ($ent['fields'] as $col => $f):
        $span = !empty($f['span']) ? ' s2' : '';
        $owner = !empty($f['owner']) ? ' data-owner="1"' : '';
        if (!empty($f['section'])): ?><div class="sec"<?= $col === 'proprietario' ? '' : $owner ?>><?= transp_h($f['section']) ?></div><?php endif;
        if ($f['type'] === 'bool'): ?>
          <label class="ck s3"<?= $owner ?>><input type="checkbox" name="<?= transp_h($col) ?>" value="1" <?= !empty($f['default']) ? 'data-default="1"' : '' ?>> <?= transp_h($f['label']) ?></label>
        <?php elseif ($f['type'] === 'enum' || $f['type'] === 'fk'):
          $opts = $f['type'] === 'enum' ? $f['options'] : ($fkOptions[$col] ?? []); ?>
          <label class="f<?= $span ?>"<?= $owner ?>><?= transp_h($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?>
            <select name="<?= transp_h($col) ?>" <?= isset($f['default']) ? 'data-default="' . transp_h($f['default']) . '"' : '' ?>>
              <?php if (empty($f['required']) || isset($f['empty'])): ?><option value=""><?= transp_h($f['empty'] ?? '—') ?></option><?php endif; ?>
              <?php foreach ($opts as $v => $lb): ?><option value="<?= transp_h($v) ?>"><?= transp_h($lb) ?></option><?php endforeach; ?>
            </select></label>
        <?php else:
          $type = $f['type'] === 'date' ? 'date' : ($f['type'] === 'int' ? 'number' : 'text');
          $mode = in_array($f['type'], ['digits', 'doc', 'int'], true) ? 'numeric' : 'text'; ?>
          <label class="f<?= $span ?>"<?= $owner ?>><?= transp_h($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?>
            <input type="<?= $type ?>" name="<?= transp_h($col) ?>" inputmode="<?= $mode ?>" <?= isset($f['max']) && $f['type'] === 'text' ? 'maxlength="' . (int)$f['max'] . '"' : '' ?> <?= $f['type'] === 'placa' ? 'maxlength="8" style="text-transform:uppercase;font-family:ui-monospace,monospace;letter-spacing:.06em"' : '' ?> <?= isset($f['default']) ? 'data-default="' . transp_h($f['default']) . '"' : '' ?> <?= isset($f['placeholder']) ? 'placeholder="' . transp_h($f['placeholder']) . '"' : '' ?> <?= $type === 'number' && isset($f['min']) ? 'min="' . (int)$f['min'] . '" max="' . (int)$f['max'] . '"' : '' ?>></label>
        <?php endif; ?>
      <?php endforeach; ?>
      </div>
    </div>
    <div class="tr-mf"><button class="tr-btn" type="button" onclick="trClose(this)">Cancelar</button><button class="tr-btn pri" type="submit">Salvar</button></div>
  </form>
</dialog>

<script>
(function(){
  const rows=<?= json_encode(array_column($rows, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const label=<?= json_encode((string)$ent['label'], JSON_UNESCAPED_UNICODE) ?>;
  const form=document.getElementById('trFormEl');
  function fill(data){
    form.querySelectorAll('[name]').forEach(el=>{
      if(['csrf','action'].includes(el.name))return;
      const v=data[el.name];
      if(el.type==='checkbox'){el.checked=v===undefined?el.dataset.default==='1':String(v)==='1';return;}
      if(el.name==='id'){el.value=v||0;return;}
      if(v===undefined||v===null){el.value=el.dataset.default||'';return;}
      el.value=(el.type==='date')?String(v).slice(0,10):String(v);
    });
    sync();
  }
  // Veículo: dados do proprietário só para veículo de terceiro; reboque não tem rodado nem motorista.
  function sync(){
    const prop=form.querySelector('[name=proprietario]'), tipo=form.querySelector('[name=tipo]');
    if(prop){const terc=prop.value==='TERCEIRO';form.querySelectorAll('[data-owner]').forEach(el=>{el.hidden=!terc;});}
    if(tipo){const reb=tipo.value==='REBOQUE';['tipo_rodado','motorista_id'].forEach(n=>{const el=form.querySelector('[name='+n+']');if(el)el.closest('label').hidden=reb;});}
  }
  form.addEventListener('change',e=>{if(['proprietario','tipo'].includes(e.target.name))sync();});
  const placa=form.querySelector('[name=placa]');
  if(placa)placa.addEventListener('input',()=>{const p=placa.selectionStart;placa.value=placa.value.toUpperCase();try{placa.setSelectionRange(p,p);}catch(e){}});
  window.trNew=function(){fill({});document.getElementById('trFormTitle').textContent='Cadastrar '+label.toLowerCase();trOpen('trForm');};
  window.trEdit=function(id){fill(rows[id]||{});document.getElementById('trFormTitle').textContent='Editar '+label.toLowerCase();trOpen('trForm');};
})();
</script>
<?php transp_end(); ?>
