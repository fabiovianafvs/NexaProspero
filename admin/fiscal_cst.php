<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

fiscal_ensure_schema($pdo);
$err = '';
$id = (int)($_GET['id'] ?? 0);
$showModal = isset($_GET['new']) || $id > 0;
$type = trim((string)($_GET['type'] ?? ($_POST['type_filter'] ?? '')));
$blank = ['id'=>0,'tax_type'=>$type ?: 'ICMS_CST','code'=>'','description'=>'','is_active'=>1];
$current = $blank;
if ($id > 0) {
  $st = $pdo->prepare('SELECT * FROM fiscal_cst_codes WHERE id=? AND company_id IN (?,0) ORDER BY company_id DESC LIMIT 1');
  $st->execute([$id, COMPANY_ID]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if ($r) $current = array_merge($current, $r);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $showModal = true;
  $pid = (int)($_POST['id'] ?? 0);
  $data = [
    'tax_type'=>trim((string)($_POST['tax_type'] ?? '')),
    'code'=>trim((string)($_POST['code'] ?? '')),
    'description'=>trim((string)($_POST['description'] ?? '')),
    'is_active'=>isset($_POST['is_active']) ? 1 : 0,
  ];
  $current = array_merge($blank, $data, ['id'=>$pid]);
  if ($data['tax_type']==='' || $data['code']==='' || $data['description']==='') $err = 'Informe tipo, código e descrição.';
  else {
    try {
      $ownerCompanyId = null;
      if ($pid > 0) {
        $own = $pdo->prepare('SELECT company_id FROM fiscal_cst_codes WHERE id=? LIMIT 1');
        $own->execute([$pid]);
        $ownerCompanyId = $own->fetchColumn();
      }
      if ($pid > 0 && (int)$ownerCompanyId === (int)COMPANY_ID) {
        $st = $pdo->prepare('UPDATE fiscal_cst_codes SET tax_type=?, code=?, description=?, is_active=?, updated_at=NOW() WHERE id=? AND company_id=?');
        $st->execute([$data['tax_type'],$data['code'],$data['description'],$data['is_active'],$pid,COMPANY_ID]);
      } else {
        $st = $pdo->prepare('INSERT INTO fiscal_cst_codes (company_id,tax_type,code,description,is_active) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description), is_active=VALUES(is_active), updated_at=NOW()');
        $st->execute([COMPANY_ID,$data['tax_type'],$data['code'],$data['description'],$data['is_active']]);
      }
      $back = 'fiscal_cst.php?ok=' . urlencode('Código salvo com sucesso.');
      if ($data['tax_type'] !== '') $back .= '&type=' . urlencode($data['tax_type']);
      header('Location: ' . $back);
      exit;
    } catch(Throwable $e) { $err = 'Erro ao salvar: ' . $e->getMessage(); }
  }
}
$types = ['ORIGEM','ICMS_CST','CSOSN','IPI_CST','PIS_CST','COFINS_CST'];
$items = fiscal_cst_codes($pdo, (int)COMPANY_ID, $type, false);

fiscal_admin_start('Fiscal - CST/CSOSN','fiscal_cst');
fiscal_page_header('CST, CSOSN e origem','Consulte e cadastre códigos tributários em modal: ICMS, IPI, PIS, COFINS e origem da mercadoria.',[
  ['label'=>'+ Novo código','href'=>'fiscal_cst.php?new=1' . ($type!==''?'&type='.urlencode($type):''),'class'=>'btn btn-primary']
]);
fiscal_tabs('fiscal_cst'); fiscal_flash(); if($err) echo '<div class="msg err">'.fiscal_h($err).'</div>';
?>
<div class="fiscal-card">
  <form method="get" class="fiscal-form fiscal-form-grid" style="align-items:end">
    <div class="row"><label>Tipo</label><select class="field" name="type"><option value="">Todos</option><?php foreach($types as $t): ?><option value="<?= fiscal_h($t) ?>" <?= $type===$t?'selected':'' ?>><?= fiscal_h($t) ?></option><?php endforeach; ?></select></div>
    <div class="row fiscal-filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn" href="fiscal_cst.php">Limpar</a></div>
  </form>
</div>
<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Códigos cadastrados</h2><span class="fiscal-list-count"><?= count($items) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Tipo</th><th>Código</th><th>Descrição</th><th>Status</th><th>Ações</th></tr></thead><tbody>
    <?php foreach($items as $r): ?><tr><td data-label="Tipo"><?= fiscal_h($r['tax_type']) ?></td><td data-label="Código"><strong><?= fiscal_h($r['code']) ?></strong></td><td data-label="Descrição" class="primary"><?= fiscal_h($r['description']) ?></td><td data-label="Status"><span class="fiscal-badge <?= !empty($r['is_active'])?'':'off' ?>"><?= !empty($r['is_active'])?'Ativo':'Inativo' ?></span></td><td data-label="Ações"><a class="btn btn-sm" href="fiscal_cst.php?id=<?= (int)$r['id'] ?>&type=<?= urlencode((string)$type) ?>">Editar</a></td></tr><?php endforeach; ?>
    <?php if(!$items): ?><tr><td colspan="5" style="text-align:center;color:#64748b">Nenhum código encontrado.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($showModal): ?>
<div class="fiscal-modal" role="dialog" aria-modal="true"><div class="fiscal-modal-dialog">
  <div class="fiscal-modal-head"><div><h2><?= ((int)$current['id'] > 0) ? 'Editar código' : 'Novo código' ?></h2><p class="fiscal-muted">Informe o tipo, código e descrição fiscal.</p></div><a class="fiscal-modal-close" href="fiscal_cst.php<?= $type!==''?'?type='.urlencode($type):'' ?>">×</a></div>
  <form method="post" class="fiscal-form"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="type_filter" value="<?= fiscal_h($type) ?>">
    <div class="fiscal-form-grid">
      <div class="row"><label>Tipo</label><select class="field" name="tax_type"><?php foreach($types as $t): ?><option value="<?= fiscal_h($t) ?>" <?= $current['tax_type']===$t?'selected':'' ?>><?= fiscal_h($t) ?></option><?php endforeach; ?></select></div>
      <div class="row"><label>Código *</label><input class="field" name="code" value="<?= fiscal_h($current['code']) ?>" required></div>
      <label class="fiscal-checkline"><input type="checkbox" name="is_active" <?= !empty($current['is_active'])?'checked':'' ?>> Ativo</label>
      <div class="row span-3"><label>Descrição *</label><input class="field" name="description" value="<?= fiscal_h($current['description']) ?>" required></div>
    </div>
    <div class="fiscal-form-actions"><a class="btn" href="fiscal_cst.php<?= $type!==''?'?type='.urlencode($type):'' ?>">Cancelar</a><button class="btn btn-primary">Salvar código</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php fiscal_admin_end(); ?>
