<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/multi_company.php';

auth_start();
if (empty($_SESSION['saas_business_groups_csrf'])) $_SESSION['saas_business_groups_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['saas_business_groups_csrf'];
$msg = trim((string)($_GET['ok'] ?? ''));
$erro = '';
$schemaReady = multi_company_schema_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!$schemaReady) throw new RuntimeException('A fundação Multiempresa ainda não foi instalada no banco. Execute o patch 20260916_multiempresa_fundacao_v1.sql.');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      $mode = (string)($_POST['operation_mode'] ?? 'integrated');
      $notes = trim((string)($_POST['notes'] ?? ''));
      if ($name === '') throw new RuntimeException('Informe o nome do Grupo Empresarial.');
      if (!in_array($mode, ['integrated','isolated'], true)) throw new RuntimeException('Modo de operação inválido.');

      $st = $pdo->prepare('INSERT INTO business_groups (name,operation_mode,notes,is_active,created_at) VALUES (?,?,?,?,NOW())');
      $st->execute([$name, $mode, ($notes !== '' ? $notes : null), 1]);
      $id = (int)$pdo->lastInsertId();
      header('Location: /saas/grupo_empresarial.php?id=' . $id . '&ok=' . rawurlencode('Grupo Empresarial criado. Agora vincule as empresas.'));
      exit;
    }

    if ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Grupo inválido.');
      $pdo->prepare('UPDATE business_groups SET is_active = IF(is_active=1,0,1), updated_at=NOW() WHERE id = ?')->execute([$id]);
      $msg = 'Situação do Grupo Empresarial atualizada.';
    }

    $_SESSION['saas_business_groups_csrf'] = bin2hex(random_bytes(24));
    $csrf = (string)$_SESSION['saas_business_groups_csrf'];
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$rows = [];
if ($schemaReady) {
  $rows = $pdo->query("\n    SELECT g.id, g.name, g.operation_mode, g.notes, g.is_active, g.created_at, g.updated_at,\n           COUNT(gc.company_id) AS company_count,\n           MAX(CASE WHEN gc.is_primary = 1 THEN c.name ELSE NULL END) AS primary_company_name\n    FROM business_groups g\n    LEFT JOIN business_group_companies gc ON gc.business_group_id = g.id AND gc.is_active = 1\n    LEFT JOIN companies c ON c.id = gc.company_id\n    GROUP BY g.id, g.name, g.operation_mode, g.notes, g.is_active, g.created_at, g.updated_at\n    ORDER BY g.is_active DESC, g.name ASC, g.id ASC\n  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

saas_layout_start('Grupos Empresariais • Central SaaS', 'Grupos Empresariais', 'Estrutura Multiempresa administrada pela Central SaaS, sem misturar dados operacionais entre empresas.');
?>
<style>
.bg-grid{display:grid;grid-template-columns:minmax(320px,.8fr) minmax(0,1.6fr);gap:16px}.bg-form{display:grid;gap:12px}.bg-form label{font-weight:850}.bg-note{background:#effaff;border:1px solid #bae6fd;color:#075985;border-radius:16px;padding:14px 16px;margin-bottom:16px;font-weight:700;line-height:1.5}.bg-mode{display:grid;gap:8px}.bg-radio{display:flex;gap:9px;align-items:flex-start;padding:11px;border:1px solid #dbe7f6;border-radius:14px;background:#f8fbff}.bg-radio input{width:auto;margin-top:3px}.bg-radio small{display:block;color:#64748b;margin-top:3px;line-height:1.35}.bg-table td{vertical-align:middle}.bg-name{font-weight:900;color:#0b1f5b}.bg-sub{display:block;color:#64748b;font-size:12px;margin-top:4px}.bg-status{display:inline-flex;padding:6px 9px;border-radius:999px;font-weight:900;font-size:12px;background:#ecfdf5;color:#166534}.bg-status.off{background:#fef2f2;color:#991b1b}.bg-mode-pill{display:inline-flex;padding:6px 9px;border-radius:999px;font-weight:900;font-size:12px;background:#eef2ff;color:#3730a3}.bg-mode-pill.iso{background:#fff7ed;color:#9a3412}@media(max-width:1000px){.bg-grid{grid-template-columns:1fr}}
</style>
<?php if (!$schemaReady): ?>
  <div class="warn"><strong>Fundação ainda não instalada.</strong> Execute primeiro <code>db/20260916_multiempresa_fundacao_v1.sql</code>. Esta tela não altera nenhuma operação do ERP até o patch ser aplicado.</div>
<?php endif; ?>
<?php if ($msg !== ''): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<div class="bg-note"><strong>Regra adotada:</strong> o Grupo Empresarial nasce aqui no SaaS. No modo <strong>Integrado</strong>, empresas da mesma atividade poderão compartilhar perfil e, nas próximas etapas, cadastros explicitamente permitidos. No modo <strong>Isolado</strong>, o grupo serve apenas para acesso/troca de empresa, mantendo os dados independentes.</div>
<div class="bg-grid">
  <section class="panel">
    <h3>Novo Grupo Empresarial</h3>
    <form method="post" class="bg-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <label>Nome
        <input name="name" maxlength="150" required placeholder="Ex.: Grupo Silva">
      </label>
      <div>
        <strong>Modo de operação</strong>
        <div class="bg-mode" style="margin-top:7px">
          <label class="bg-radio"><input type="radio" name="operation_mode" value="integrated" checked><span><b>Integrado</b><small>Empresas da mesma atividade. Perfil integrado e base preparada para compartilhamentos controlados.</small></span></label>
          <label class="bg-radio"><input type="radio" name="operation_mode" value="isolated"><span><b>Isolado</b><small>Empresas/ramos distintos. Mesmo usuário pode trocar de empresa, sem compartilhar dados operacionais.</small></span></label>
        </div>
      </div>
      <label>Observações
        <textarea name="notes" rows="4" placeholder="Informações internas da Central SaaS"></textarea>
      </label>
      <button class="btn2 btn2-dark" type="submit" <?= !$schemaReady ? 'disabled' : '' ?>>Criar grupo</button>
    </form>
  </section>

  <section class="panel">
    <h3>Grupos cadastrados</h3>
    <?php if (!$rows): ?>
      <p class="muted">Nenhum Grupo Empresarial cadastrado.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="bg-table">
          <thead><tr><th>Grupo</th><th>Modo</th><th>Empresas</th><th>Situação</th><th>Ações</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $integrated = (string)$row['operation_mode'] === 'integrated'; ?>
            <tr>
              <td><span class="bg-name"><?= e((string)$row['name']) ?></span><?php if (!empty($row['primary_company_name'])): ?><span class="bg-sub">Principal: <?= e((string)$row['primary_company_name']) ?></span><?php endif; ?></td>
              <td><span class="bg-mode-pill <?= $integrated ? '' : 'iso' ?>"><?= $integrated ? 'Integrado' : 'Isolado' ?></span></td>
              <td><strong><?= (int)$row['company_count'] ?></strong></td>
              <td><span class="bg-status <?= !empty($row['is_active']) ? '' : 'off' ?>"><?= !empty($row['is_active']) ? 'Ativo' : 'Inativo' ?></span></td>
              <td><div class="row-actions"><a class="mini mini-primary" href="/saas/grupo_empresarial.php?id=<?= (int)$row['id'] ?>">Administrar</a><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="mini" type="submit"><?= !empty($row['is_active']) ? 'Desativar' : 'Ativar' ?></button></form></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php saas_layout_end(); ?>
