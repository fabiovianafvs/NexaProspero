<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/internal_delivery_status.php';

$companyId = (int)COMPANY_ID;
internal_delivery_status_ensure_schema($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = trim((string)($_POST['action'] ?? 'save'));
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'toggle') {
      $row = internal_delivery_status_find($pdo, $companyId, $id);
      if (!$row) throw new RuntimeException('Status interno não encontrado.');
      $newActive = !empty($row['active']) ? 0 : 1;
      $pdo->prepare('UPDATE gestao_internal_delivery_statuses SET active = ? WHERE company_id = ? AND id = ?')->execute([$newActive, $companyId, $id]);
      header('Location: gestao_entrega_interno.php?ok=' . rawurlencode($newActive ? 'Status interno ativado.' : 'Status interno desativado.'));
      exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $code = internal_delivery_status_slug((string)($_POST['code'] ?? ''));
    $color = trim((string)($_POST['color'] ?? '#0B1F5B'));
    $sortOrder = max(0, min(9999, (int)($_POST['sort_order'] ?? 50)));
    $active = !empty($_POST['active']) ? 1 : 0;
    if ($name === '') throw new RuntimeException('Informe o nome do status interno.');
    if ($code === '') $code = internal_delivery_status_slug($name);
    if ($code === '') throw new RuntimeException('Não foi possível gerar o código do status.');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#0B1F5B';

    $dup = $pdo->prepare('SELECT id FROM gestao_internal_delivery_statuses WHERE company_id = ? AND code = ? AND id <> ? LIMIT 1');
    $dup->execute([$companyId, $code, $id]);
    if ($dup->fetchColumn()) throw new RuntimeException('Já existe um status interno com este código.');

    if ($id > 0) {
      if (!internal_delivery_status_find($pdo, $companyId, $id)) throw new RuntimeException('Status interno não encontrado.');
      $pdo->prepare('UPDATE gestao_internal_delivery_statuses SET name=?, code=?, color=?, sort_order=?, active=? WHERE company_id=? AND id=?')
        ->execute([$name, $code, $color, $sortOrder, $active, $companyId, $id]);
      $msg = 'Status interno atualizado com sucesso.';
    } else {
      $pdo->prepare('INSERT INTO gestao_internal_delivery_statuses (company_id,name,code,color,sort_order,active) VALUES (?,?,?,?,?,?)')
        ->execute([$companyId, $name, $code, $color, $sortOrder, $active]);
      $msg = 'Status interno cadastrado com sucesso.';
    }
    header('Location: gestao_entrega_interno.php?ok=' . rawurlencode($msg));
    exit;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? internal_delivery_status_find($pdo, $companyId, $editId) : null;
$rows = internal_delivery_status_options($pdo, $companyId, true);

gestao_admin_start('Entrega Interno', 'gestao_entrega_interno');
gestao_page_header('Entrega Interno', 'Cadastre os status internos usados para identificar a etapa de entrega/processamento do pedido.');
gestao_tabs('gestao_entrega_interno');
gestao_flash();
if ($error !== '') echo '<div class="msg err">' . gestao_h($error) . '</div>';
?>
<div class="mg-grid" style="align-items:start">
  <section class="mg-card">
    <h2><?= $edit ? 'Editar status interno' : 'Cadastrar status interno' ?></h2>
    <form method="post">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="mg-form-grid">
        <label>Nome *<input name="name" required maxlength="120" value="<?= gestao_h((string)($edit['name'] ?? '')) ?>" placeholder="Ex.: Aguardando separação"></label>
        <label>Código<input name="code" maxlength="80" value="<?= gestao_h((string)($edit['code'] ?? '')) ?>" placeholder="automático"></label>
        <label>Cor<input type="color" name="color" value="<?= gestao_h((string)($edit['color'] ?? '#0B1F5B')) ?>"></label>
        <label>Ordem<input type="number" name="sort_order" min="0" max="9999" value="<?= (int)($edit['sort_order'] ?? 50) ?>"></label>
        <label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" name="active" value="1" <?= !isset($edit['active']) || !empty($edit['active']) ? 'checked' : '' ?>> Ativo</label>
      </div>
      <div class="mg-form-actions">
        <?php if ($edit): ?><a class="btn" href="gestao_entrega_interno.php">Cancelar edição</a><?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $edit ? 'Salvar alterações' : 'Adicionar status' ?></button>
      </div>
    </form>
  </section>

  <section class="mg-card">
    <h2>Status cadastrados</h2>
    <div class="table-wrap">
      <table class="mg-table">
        <thead><tr><th>Status</th><th>Código</th><th>Ordem</th><th>Situação</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="mg-empty">Nenhum status interno cadastrado.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="primary" data-label="Status"><span style="display:inline-flex;align-items:center;gap:8px"><i style="width:14px;height:14px;border-radius:999px;background:<?= gestao_h((string)$r['color']) ?>"></i><strong><?= gestao_h((string)$r['name']) ?></strong></span></td>
            <td data-label="Código"><?= gestao_h((string)$r['code']) ?></td>
            <td data-label="Ordem"><?= (int)$r['sort_order'] ?></td>
            <td data-label="Situação"><span class="mg-badge <?= !empty($r['active']) ? 'green' : 'gray' ?>"><?= !empty($r['active']) ? 'Ativo' : 'Inativo' ?></span></td>
            <td data-label="Ações"><div class="mg-inline-actions"><a class="btn" href="gestao_entrega_interno.php?edit=<?= (int)$r['id'] ?>">Editar</a><form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn" type="submit"><?= !empty($r['active']) ? 'Desativar' : 'Ativar' ?></button></form></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php gestao_admin_end(); ?>
