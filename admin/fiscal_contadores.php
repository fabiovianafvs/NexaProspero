<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/accounting_module.php';

$cid=(int)COMPANY_ID;
$err='';
try { accounting_ensure_schema($pdo); } catch (Throwable $e) { $err=$e->getMessage(); }

if ($_SERVER['REQUEST_METHOD']==='POST' && $err==='') {
  try {
    accounting_csrf_check((string)($_POST['csrf'] ?? ''),'admin_accountants');
    $action=(string)($_POST['action'] ?? 'save');
    if ($action==='save') {
      $id=(int)($_POST['id'] ?? 0);
      $name=trim((string)($_POST['name'] ?? ''));
      $email=strtolower(trim((string)($_POST['email'] ?? '')));
      $document=preg_replace('/\D+/','',(string)($_POST['document'] ?? '')) ?: '';
      $phone=trim((string)($_POST['phone'] ?? ''));
      $password=(string)($_POST['password'] ?? '');
      if ($name==='') throw new RuntimeException('Informe o nome do contador.');
      if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');

      $accountant=null;
      if ($id>0) {
        $st=$pdo->prepare('SELECT a.* FROM accountants a JOIN accountant_companies ac ON ac.accountant_id=a.id WHERE a.id=? AND ac.company_id=? LIMIT 1');
        $st->execute([$id,$cid]);
        $accountant=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$accountant) throw new RuntimeException('Contador não encontrado para esta empresa.');
      } else {
        $st=$pdo->prepare('SELECT * FROM accountants WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $accountant=$st->fetch(PDO::FETCH_ASSOC) ?: null;
      }

      if ($accountant) {
        $accountantId=(int)$accountant['id'];
        $sets=['name=?','email=?','document=?','phone=?','is_active=1','updated_at=NOW()'];
        $params=[$name,$email,$document,$phone];
        if ($password!=='') {
          if (strlen($password)<6) throw new RuntimeException('A senha deve possuir pelo menos 6 caracteres.');
          $sets[]='password_hash=?';$params[]=password_hash($password,PASSWORD_DEFAULT);
        }
        $params[]=$accountantId;
        $pdo->prepare('UPDATE accountants SET ' . implode(',',$sets) . ' WHERE id=?')->execute($params);
      } else {
        if (strlen($password)<6) throw new RuntimeException('Informe uma senha inicial com pelo menos 6 caracteres.');
        $pdo->prepare('INSERT INTO accountants (name,email,password_hash,document,phone,is_active,created_at) VALUES (?,?,?,?,?,1,NOW())')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$document,$phone]);
        $accountantId=(int)$pdo->lastInsertId();
      }

      $pdo->prepare("INSERT INTO accountant_companies (accountant_id,company_id,is_active,created_by_user_id,created_at) VALUES (?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE is_active=1,updated_at=NOW()")
        ->execute([$accountantId,$cid,function_exists('auth_user_id') ? auth_user_id() : null]);
      header('Location: fiscal_contadores.php?ok=' . rawurlencode($id>0?'Cadastro do contador atualizado.':'Contador cadastrado e vinculado à empresa.'));
      exit;
    }
    if ($action==='toggle') {
      $id=(int)($_POST['id'] ?? 0);$active=(int)($_POST['active'] ?? 0)===1?1:0;
      $pdo->prepare('UPDATE accountant_companies SET is_active=?,updated_at=NOW() WHERE accountant_id=? AND company_id=?')->execute([$active,$id,$cid]);
      header('Location: fiscal_contadores.php?ok=' . rawurlencode($active?'Acesso do contador ativado.':'Acesso do contador suspenso.'));
      exit;
    }
    if ($action==='review_request') {
      $requestId=(int)($_POST['request_id'] ?? 0);
      $decision=(string)($_POST['decision'] ?? '');
      if ($requestId<=0 || !in_array($decision,['approve','deny'],true)) throw new RuntimeException('Informe uma decisão válida para a solicitação.');
      $request=accounting_review_request($pdo,$cid,$requestId,$decision==='approve',function_exists('auth_user_id') ? auth_user_id() : null,(string)($_POST['review_note'] ?? ''));
      $message=$decision==='approve'
        ? 'Acesso de ' . (string)($request['accountant_name'] ?? 'contador') . ' aprovado.'
        : 'Solicitação de acesso negada.';
      header('Location: fiscal_contadores.php?ok=' . rawurlencode($message));
      exit;
    }
  } catch (Throwable $e) { $err=$e->getMessage(); }
}

$edit=null;$editId=(int)($_GET['edit'] ?? 0);
if ($editId>0) {
  try { $st=$pdo->prepare('SELECT a.*,ac.is_active AS link_active FROM accountants a JOIN accountant_companies ac ON ac.accountant_id=a.id WHERE ac.company_id=? AND a.id=? LIMIT 1');$st->execute([$cid,$editId]);$edit=$st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
}
$rows=[];
try {
  $st=$pdo->prepare('SELECT a.*,ac.is_active AS link_active,ac.created_at AS linked_at,(SELECT COUNT(*) FROM accountant_companies x WHERE x.accountant_id=a.id AND x.is_active=1) AS companies_count FROM accountants a JOIN accountant_companies ac ON ac.accountant_id=a.id WHERE ac.company_id=? ORDER BY ac.is_active DESC,a.name');
  $st->execute([$cid]);$rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $err=$err ?: $e->getMessage(); }

$accessRequests=[];
try { $accessRequests=accounting_company_requests($pdo,$cid); } catch (Throwable $e) { $err=$err ?: $e->getMessage(); }
$pendingAccessRequests=array_values(array_filter($accessRequests,static fn(array $r): bool => strtoupper((string)($r['status'] ?? ''))==='PENDENTE'));

$centralUrl=accounting_public_central_url();

fiscal_admin_start('Contadores','fiscal_contadores');
fiscal_page_header('Cadastro de Contadores','Cadastre o acesso do contador e vincule-o à empresa para consultar os fechamentos publicados.',[
  ['label'=>'Documentos para Contabilidade','href'=>'fiscal_contabilidade.php','class'=>'btn btn-primary'],
  ['label'=>'Abrir Central do Contador','href'=>$centralUrl,'class'=>'btn'],
]);
fiscal_tabs('fiscal_contadores');
fiscal_flash();
if ($err!=='') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<?php if($pendingAccessRequests): ?>
<div class="fiscal-card accountant-request-card" style="margin-bottom:14px">
  <div class="fiscal-list-head"><div><h2>Solicitações de acesso pendentes</h2><p class="fiscal-muted">Contadores que localizaram esta empresa pelo CNPJ e aguardam sua autorização.</p></div><span class="fiscal-list-count"><?= count($pendingAccessRequests) ?> pendente(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Contador</th><th>CPF/CNPJ</th><th>Contato</th><th>Solicitado em</th><th>Mensagem</th><th>Ação</th></tr></thead><tbody>
  <?php foreach($pendingAccessRequests as $request): ?>
    <tr><td class="primary"><strong><?= fiscal_h((string)$request['accountant_name']) ?></strong></td><td><?= fiscal_h((string)$request['accountant_document']) ?></td><td><?= fiscal_h((string)$request['accountant_email']) ?><br><small><?= fiscal_h((string)$request['accountant_phone']) ?></small></td><td><?= !empty($request['requested_at'])?date('d/m/Y H:i',strtotime((string)$request['requested_at'])):'—' ?></td><td><?= fiscal_h((string)($request['request_message'] ?: 'Sem mensagem.')) ?></td><td><div class="request-review-actions"><form method="post"><input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_accountants')) ?>"><input type="hidden" name="action" value="review_request"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn btn-primary btn-sm" type="submit">Autorizar</button></form><form method="post" onsubmit="return confirm('Negar esta solicitação de acesso?')"><input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_accountants')) ?>"><input type="hidden" name="action" value="review_request"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="decision" value="deny"><button class="btn btn-sm" type="submit">Negar</button></form></div></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>
<div class="fiscal-grid" style="align-items:start">
  <div class="fiscal-card">
    <h2><?= $edit?'Editar contador':'Novo contador' ?></h2>
    <form method="post" class="fiscal-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_accountants')) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="fiscal-form-grid">
        <div class="span-2"><label>Nome</label><input name="name" value="<?= fiscal_h((string)($edit['name'] ?? '')) ?>" required></div>
        <div class="span-2"><label>E-mail de acesso</label><input type="email" name="email" value="<?= fiscal_h((string)($edit['email'] ?? '')) ?>" required></div>
        <div><label>CPF/CNPJ</label><input name="document" value="<?= fiscal_h((string)($edit['document'] ?? '')) ?>"></div>
        <div><label>Telefone</label><input name="phone" value="<?= fiscal_h((string)($edit['phone'] ?? '')) ?>"></div>
        <div class="span-2"><label><?= $edit?'Nova senha (deixe em branco para manter)':'Senha inicial' ?></label><input type="password" name="password" minlength="6" <?= $edit?'':'required' ?> autocomplete="new-password"></div>
        <div class="span-2 fiscal-form-actions"><button class="btn btn-primary" type="submit"><?= $edit?'Salvar alterações':'Cadastrar e vincular' ?></button><?php if($edit): ?><a class="btn" href="fiscal_contadores.php">Cancelar</a><?php endif; ?></div>
      </div>
    </form>
  </div>
  <div class="fiscal-card">
    <h2>Endereço da Central</h2>
    <p class="fiscal-muted">Envie este endereço ao contador juntamente com o e-mail e a senha cadastrados.</p>
    <div class="accountant-url"><code><?= fiscal_h($centralUrl) ?></code><button type="button" class="btn btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($centralUrl),ENT_QUOTES,'UTF-8') ?>)">Copiar</button></div>
    <div class="msg" style="margin-top:14px">O mesmo contador pode ser vinculado a várias empresas. Ao entrar, ele escolhe qual empresa deseja consultar.</div>
  </div>
</div>
<div class="fiscal-card" style="margin-top:14px">
  <div class="fiscal-list-head"><h2>Contadores vinculados</h2><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Contador</th><th>Contato</th><th>Empresas</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
  <?php if(!$rows): ?><tr><td colspan="5" class="fiscal-muted">Nenhum contador vinculado.</td></tr><?php endif; ?>
  <?php foreach($rows as $row): $active=(int)$row['link_active']===1; ?>
    <tr><td class="primary"><strong><?= fiscal_h((string)$row['name']) ?></strong><br><small><?= fiscal_h((string)$row['document']) ?></small></td><td><?= fiscal_h((string)$row['email']) ?><br><small><?= fiscal_h((string)$row['phone']) ?></small></td><td><?= (int)$row['companies_count'] ?> empresa(s)</td><td><span class="fiscal-badge <?= $active?'ok':'off' ?>"><?= $active?'Ativo':'Suspenso' ?></span></td><td><div class="fiscal-inline-actions"><a class="btn btn-sm" href="?edit=<?= (int)$row['id'] ?>">Editar</a><form method="post"><input type="hidden" name="csrf" value="<?= fiscal_h(accounting_csrf_token('admin_accountants')) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="active" value="<?= $active?0:1 ?>"><button class="btn btn-sm" type="submit"><?= $active?'Suspender':'Ativar' ?></button></form></div></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php if($accessRequests): ?>
<div class="fiscal-card" style="margin-top:14px">
  <div class="fiscal-list-head"><h2>Histórico de solicitações</h2><span class="fiscal-list-count"><?= count($accessRequests) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Contador</th><th>Data</th><th>Situação</th><th>Observação</th></tr></thead><tbody><?php foreach($accessRequests as $request): $requestStatus=strtoupper((string)$request['status']); ?><tr><td class="primary"><strong><?= fiscal_h((string)$request['accountant_name']) ?></strong><br><small><?= fiscal_h((string)$request['accountant_email']) ?></small></td><td><?= !empty($request['requested_at'])?date('d/m/Y H:i',strtotime((string)$request['requested_at'])):'—' ?></td><td><span class="request-status <?= strtolower(fiscal_h($requestStatus)) ?>"><?= fiscal_h($requestStatus) ?></span></td><td><?= fiscal_h((string)($request['review_note'] ?: $request['request_message'] ?: '—')) ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>
<style>.accountant-url{display:flex;gap:8px;align-items:center}.accountant-url code{flex:1;padding:12px;border-radius:13px;background:#0f172a;color:#e2e8f0;word-break:break-all}.fiscal-badge.ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.request-review-actions{display:flex;gap:6px;flex-wrap:wrap}.request-status{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid #cbd5e1;background:#f8fafc;color:#475569}.request-status.pendente{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.request-status.aprovado{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.request-status.negado{background:#fef2f2;color:#b91c1c;border-color:#fecaca}</style>
<?php fiscal_admin_end(); ?>
