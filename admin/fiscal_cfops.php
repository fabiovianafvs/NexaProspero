<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

fiscal_ensure_schema($pdo);
$err = '';
$id = (int)($_GET['id'] ?? 0);
$showModal = isset($_GET['new']) || $id > 0;

$blank = ['id'=>0,'code'=>'','description'=>'','operation_type'=>'VENDA','direction'=>'SAIDA','is_active'=>1,'notes'=>''];
$current = $blank;

if ($id > 0) {
  $st = $pdo->prepare('SELECT * FROM fiscal_cfops WHERE id = ? AND company_id IN (?,0) ORDER BY company_id DESC LIMIT 1');
  $st->execute([$id, COMPANY_ID]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) $current = array_merge($current, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $showModal = true;
  $pid = (int)($_POST['id'] ?? 0);
  $data = [
    'company_id' => (int)COMPANY_ID,
    'code' => substr(preg_replace('/\D+/', '', (string)($_POST['code'] ?? '')), 0, 4),
    'description' => trim((string)($_POST['description'] ?? '')),
    'operation_type' => trim((string)($_POST['operation_type'] ?? 'VENDA')),
    'direction' => trim((string)($_POST['direction'] ?? 'SAIDA')),
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
    'notes' => trim((string)($_POST['notes'] ?? '')),
  ];
  $current = array_merge($blank, $data, ['id'=>$pid]);
  if ($data['code'] === '' || $data['description'] === '') {
    $err = 'Informe o CFOP e a descrição.';
  } else {
    try {
      $ownerCompanyId = null;
      if ($pid > 0) {
        $own = $pdo->prepare('SELECT company_id FROM fiscal_cfops WHERE id=? LIMIT 1');
        $own->execute([$pid]);
        $ownerCompanyId = $own->fetchColumn();
      }
      if ($pid > 0 && (int)$ownerCompanyId === (int)COMPANY_ID) {
        $st = $pdo->prepare('UPDATE fiscal_cfops SET code=?, description=?, operation_type=?, direction=?, is_active=?, notes=?, updated_at=NOW() WHERE id=? AND company_id=?');
        $st->execute([$data['code'],$data['description'],$data['operation_type'],$data['direction'],$data['is_active'],$data['notes'],$pid,COMPANY_ID]);
      } else {
        $st = $pdo->prepare('INSERT INTO fiscal_cfops (company_id,code,description,operation_type,direction,is_active,notes) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description), operation_type=VALUES(operation_type), direction=VALUES(direction), is_active=VALUES(is_active), notes=VALUES(notes), updated_at=NOW()');
        $st->execute([$data['company_id'],$data['code'],$data['description'],$data['operation_type'],$data['direction'],$data['is_active'],$data['notes']]);
      }
      header('Location: fiscal_cfops.php?ok=' . urlencode('CFOP salvo com sucesso.'));
      exit;
    } catch (Throwable $e) { $err = 'Erro ao salvar CFOP: ' . $e->getMessage(); }
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$items = fiscal_search_cfops($pdo, (int)COMPANY_ID, $q, 500);
if ($tipo !== '') $items = array_values(array_filter($items, fn($r) => ($r['operation_type'] ?? '') === $tipo));

fiscal_admin_start('Fiscal - CFOP', 'fiscal_cfops');
fiscal_page_header('Tabela de CFOP', 'Consulte CFOPs e cadastre novas operações fiscais em modal, seguindo o padrão do sistema.', [
  ['label'=>'+ Novo CFOP','href'=>'fiscal_cfops.php?new=1','class'=>'btn btn-primary']
]);
fiscal_tabs('fiscal_cfops');
fiscal_flash();
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <form method="get" class="fiscal-form fiscal-form-grid" style="align-items:end">
    <div class="row span-2"><label>Pesquisar</label><input class="field" name="q" value="<?= fiscal_h($q) ?>" placeholder="Código, descrição ou tipo de operação"></div>
    <div class="row"><label>Tipo</label><select class="field" name="tipo"><option value="">Todos</option><?php foreach(['VENDA','DEVOLUCAO','COMPRA','TRANSFERENCIA','OUTRO'] as $t): ?><option value="<?= $t ?>" <?= $tipo===$t?'selected':'' ?>><?= fiscal_h($t) ?></option><?php endforeach; ?></select></div>
    <div class="row fiscal-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="fiscal_cfops.php">Limpar</a></div>
  </form>
</div>
<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>CFOPs cadastrados</h2><span class="fiscal-list-count"><?= count($items) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>CFOP</th><th>Descrição</th><th>Tipo</th><th>Status</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($items as $r): ?><tr>
      <td data-label="CFOP"><strong><?= fiscal_h($r['code']) ?></strong></td>
      <td data-label="Descrição" class="primary"><?= fiscal_h($r['description']) ?></td>
      <td data-label="Tipo"><?= fiscal_h(($r['operation_type'] ?? '') . ' / ' . ($r['direction'] ?? '')) ?></td>
      <td data-label="Status"><span class="fiscal-badge <?= !empty($r['is_active'])?'':'off' ?>"><?= !empty($r['is_active'])?'Ativo':'Inativo' ?></span></td>
      <td data-label="Ações"><a class="btn btn-sm" href="fiscal_cfops.php?id=<?= (int)$r['id'] ?>">Editar</a></td>
    </tr><?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="5" style="text-align:center;color:#64748b">Nenhum CFOP encontrado.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($showModal): ?>
<div class="fiscal-modal" role="dialog" aria-modal="true"><div class="fiscal-modal-dialog">
  <div class="fiscal-modal-head"><div><h2><?= ((int)$current['id'] > 0) ? 'Editar CFOP' : 'Novo CFOP' ?></h2><p class="fiscal-muted">Preencha os dados da operação fiscal.</p></div><a class="fiscal-modal-close" href="fiscal_cfops.php">×</a></div>
  <form method="post" class="fiscal-form">
    <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
    <div class="fiscal-form-grid">
      <div class="row"><label>CFOP *</label><input class="field" name="code" value="<?= fiscal_h($current['code']) ?>" maxlength="4" inputmode="numeric" required></div>
      <div class="row"><label>Tipo</label><select class="field" name="operation_type"><?php foreach(['VENDA','DEVOLUCAO','COMPRA','TRANSFERENCIA','OUTRO'] as $t): ?><option value="<?= $t ?>" <?= $current['operation_type']===$t?'selected':'' ?>><?= fiscal_h($t) ?></option><?php endforeach; ?></select></div>
      <div class="row"><label>Direção</label><select class="field" name="direction"><option value="SAIDA" <?= $current['direction']==='SAIDA'?'selected':'' ?>>Saída</option><option value="ENTRADA" <?= $current['direction']==='ENTRADA'?'selected':'' ?>>Entrada</option></select></div>
      <div class="row span-3"><label>Descrição *</label><input class="field" name="description" value="<?= fiscal_h($current['description']) ?>" required></div>
      <label class="fiscal-checkline"><input type="checkbox" name="is_active" <?= !empty($current['is_active'])?'checked':'' ?>> Ativo</label>
      <div class="row span-3"><label>Observações</label><textarea class="field" name="notes" rows="3"><?= fiscal_h($current['notes']) ?></textarea></div>
    </div>
    <div class="fiscal-form-actions"><a class="btn" href="fiscal_cfops.php">Cancelar</a><button class="btn btn-primary" type="submit">Salvar CFOP</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php fiscal_admin_end(); ?>
