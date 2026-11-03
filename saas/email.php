<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_email.php';

if (empty($_SESSION['saas_email_csrf'])) $_SESSION['saas_email_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['saas_email_csrf'];

$msg = '';
$erro = '';
$cfg = saas_email_load($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $action = (string)($_POST['action'] ?? 'save');
    $cfg = saas_email_save($pdo, $_POST);
    $msg = 'Configurações de e-mail salvas.';

    if ($action === 'test') {
      $to = (string)($cfg['test_email'] ?: $cfg['from_email']);
      if (!saas_email_ready($cfg)) throw new RuntimeException('Ative o envio e preencha servidor e remetente antes de testar.');
      $body = "Olá!\n\nEste é um e-mail de teste da Central SaaS Nexa Próspero.\n\nSe você recebeu esta mensagem, o envio de e-mails da plataforma está funcionando.";
      $res = saas_email_send($pdo, $to, 'Teste de e-mail - Nexa Próspero', $body, 'saas_email_test', null, $cfg);
      if (!empty($res['ok'])) {
        $msg = 'Configurações salvas e e-mail de teste enviado para ' . $to . '. Confira a caixa de entrada (e o spam).';
      } else {
        $erro = 'Configurações salvas, mas o teste falhou: ' . (string)($res['error'] ?? 'erro desconhecido');
      }
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$recentLogs = [];
try {
  $recentLogs = $pdo->query("SELECT l.created_at, l.context, l.recipient, l.subject, l.status, l.error_message, c.name AS company_name
    FROM email_send_log l LEFT JOIN companies c ON c.id = l.company_id
    WHERE l.context IN ('saas_signup','saas_password_reset','saas_email_test')
    ORDER BY l.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$contextLabels = ['saas_signup' => 'Cadastro', 'saas_password_reset' => 'Esqueci a senha', 'saas_email_test' => 'Teste'];
$ready = saas_email_ready($cfg);

saas_layout_start('E-mail • Central SaaS', 'E-mail da plataforma', 'Servidor de e-mail usado para enviar os acessos de novas empresas e os links de "Esqueci minha senha".');
?>
<style>
.em-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:18px;align-items:start}
.em-card{background:#fff;border:1px solid #d9e5fb;border-radius:22px;padding:18px;box-shadow:0 18px 48px rgba(9,31,91,.08);color:#0b1f5b}
.em-title{font-size:18px;margin:0 0 12px;color:#0b1f5b}.em-muted{color:#64748b;font-size:13px;line-height:1.5}
.em-form label{font-weight:800;color:#0b1f5b;display:block;margin:13px 0 7px}
.em-form input,.em-form select{width:100%;border:1px solid #bfd2f4;border-radius:14px;padding:11px 12px;font:inherit;background:#fff;color:#0b1f5b}
.em-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.em-row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px}
.em-check{display:flex!important;align-items:flex-start;gap:10px;padding:12px;border:1px solid #d9e5fb;border-radius:16px;background:#f8fbff;margin-top:10px;font-weight:400!important}
.em-check input{width:auto;margin-top:3px}.em-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.em-status{display:inline-flex;border-radius:999px;padding:8px 11px;font-size:12px;font-weight:900}
.em-status.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.em-status.warn{background:#fff7ed;color:#c2410c;border:1px solid #fdba74}
.em-tips li{margin:6px 0;color:#334155;font-size:13px;line-height:1.5}
.em-log td{vertical-align:top;font-size:12px}
@media(max-width:1100px){.em-grid{grid-template-columns:1fr}.em-row,.em-row3{grid-template-columns:1fr}}
</style>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="em-grid">
  <div class="em-card">
    <h2 class="em-title">Servidor de e-mail (SMTP)</h2>
    <p><?= $ready ? '<span class="em-status ok">Ativo</span>' : '<span class="em-status warn">Desativado ou incompleto</span>' ?></p>
    <form method="post" class="em-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label class="em-check"><input type="checkbox" name="enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>><span><b>Ativar e-mail da plataforma</b><br><span class="em-muted">Sem isso, nenhum e-mail é enviado pela Central SaaS.</span></span></label>
      <label class="em-check"><input type="checkbox" name="send_signup" value="1" <?= !empty($cfg['send_signup']) ? 'checked' : '' ?>><span><b>Enviar os acessos ao cadastrar nova empresa</b><br><span class="em-muted">Além do WhatsApp e da tela final, envia os links e o link de criar senha para o e-mail informado no cadastro.</span></span></label>
      <label class="em-check"><input type="checkbox" name="send_password_reset" value="1" <?= !empty($cfg['send_password_reset']) ? 'checked' : '' ?>><span><b>Enviar o link de "Esqueci minha senha"</b><br><span class="em-muted">Usado quando o usuário tem um e-mail real cadastrado.</span></span></label>

      <div class="em-row3">
        <div><label>Servidor SMTP</label><input name="smtp_host" value="<?= e((string)$cfg['smtp_host']) ?>" placeholder="smtp.hostinger.com"></div>
        <div><label>Porta</label><input type="number" name="smtp_port" min="1" max="65535" value="<?= (int)$cfg['smtp_port'] ?>"></div>
        <div><label>Segurança</label><select name="smtp_security">
          <?php foreach (['tls' => 'TLS (porta 587)', 'ssl' => 'SSL (porta 465)', 'none' => 'Nenhuma'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= (string)$cfg['smtp_security'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      </div>
      <div class="em-row">
        <div><label>Usuário (login do e-mail)</label><input name="smtp_username" value="<?= e((string)$cfg['smtp_username']) ?>" placeholder="nao-responda@nexaprospero.com.br" autocomplete="off"></div>
        <div><label>Senha <span class="em-muted">(em branco mantém)</span></label><input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= !empty($cfg['smtp_password_secret']) ? 'Senha já configurada' : 'Senha do e-mail' ?>"></div>
      </div>
      <div class="em-row">
        <div><label>E-mail remetente</label><input name="from_email" value="<?= e((string)$cfg['from_email']) ?>" placeholder="nao-responda@nexaprospero.com.br"></div>
        <div><label>Nome do remetente</label><input name="from_name" value="<?= e((string)$cfg['from_name']) ?>" placeholder="Nexa Próspero"></div>
      </div>
      <div class="em-row">
        <div><label>Responder para <span class="em-muted">(opcional)</span></label><input name="reply_to" value="<?= e((string)$cfg['reply_to']) ?>" placeholder="suporte@nexaprospero.com.br"></div>
        <div><label>E-mail para teste</label><input name="test_email" value="<?= e((string)$cfg['test_email']) ?>" placeholder="seu-email@gmail.com"></div>
      </div>
      <input type="hidden" name="timeout_seconds" value="<?= (int)$cfg['timeout_seconds'] ?>">

      <div class="em-actions">
        <button class="btn2 btn2-dark" type="submit" name="action" value="save">Salvar</button>
        <button class="btn2 btn2-primary" type="submit" name="action" value="test">Salvar e enviar teste</button>
      </div>
    </form>
  </div>

  <div class="em-card">
    <h2 class="em-title">Como preencher</h2>
    <ul class="em-tips">
      <li><b>E-mail da Hostinger:</b> servidor <code>smtp.hostinger.com</code>, porta <b>465</b> com SSL (ou 587 com TLS). Usuário e senha são os da caixa de e-mail.</li>
      <li><b>Gmail / Google Workspace:</b> servidor <code>smtp.gmail.com</code>, porta 587 com TLS. Use uma <b>senha de app</b>, não a senha normal da conta.</li>
      <li><b>Microsoft 365 / Outlook:</b> servidor <code>smtp.office365.com</code>, porta 587 com TLS.</li>
      <li>Use um remetente do seu domínio (ex.: <code>nao-responda@nexaprospero.com.br</code>) para os e-mails não caírem no spam.</li>
      <li>Depois de salvar, use <b>Salvar e enviar teste</b> e confira a caixa de entrada.</li>
    </ul>
  </div>
</div>

<div class="em-card" style="margin-top:18px">
  <h2 class="em-title">Últimos envios</h2>
  <?php if (!$recentLogs): ?>
    <p class="em-muted">Nenhum e-mail enviado pela plataforma ainda.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="em-log">
      <thead><tr><th>Data</th><th>Tipo</th><th>Empresa</th><th>Para</th><th>Situação</th></tr></thead>
      <tbody>
      <?php foreach ($recentLogs as $log): ?>
        <tr>
          <td><?= e(date('d/m/Y H:i', strtotime((string)$log['created_at']) ?: time())) ?></td>
          <td><?= e($contextLabels[(string)$log['context']] ?? (string)$log['context']) ?></td>
          <td><?= e((string)($log['company_name'] ?? '—')) ?></td>
          <td><?= e((string)$log['recipient']) ?></td>
          <td><?= (string)$log['status'] === 'sent' ? '<span class="em-status ok">Enviado</span>' : '<span class="em-status warn">Erro</span><div class="em-muted">' . e((string)($log['error_message'] ?? '')) . '</div>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php saas_layout_end(); ?>
