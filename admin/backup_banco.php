<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/database_backup.php';

if(session_status()!==PHP_SESSION_ACTIVE)@session_start();
if(empty($_SESSION['db_backup_csrf']))$_SESSION['db_backup_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['db_backup_csrf'];$msg='';$err='';

try{
  if(isset($_GET['download'])){
    $file=nexa_db_backup_resolve_file((string)$_GET['download']);
    header('Content-Type: application/gzip');header('Content-Disposition: attachment; filename="'.basename($file).'"');header('Content-Length: '.(string)filesize($file));header('X-Content-Type-Options: nosniff');readfile($file);exit;
  }
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');if($posted===''||!hash_equals($csrf,$posted))throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');$action=(string)($_POST['action']??'');
    if($action==='create'){
      if(strtoupper(trim((string)($_POST['confirmation']??'')))!=='BACKUP')throw new RuntimeException('Digite BACKUP para confirmar a geração.');
      $meta=nexa_db_backup_create($pdo,(int)(auth_user_id()??0));$msg='Backup concluído com sucesso: '.(string)$meta['file'].'. Faça o download antes de iniciar a importação.';
    }elseif($action==='delete'){
      if(strtoupper(trim((string)($_POST['delete_confirmation']??'')))!=='EXCLUIR')throw new RuntimeException('Digite EXCLUIR para remover o backup.');nexa_db_backup_delete((string)($_POST['file']??''));$msg='Backup excluído.';
    }else throw new RuntimeException('Ação inválida.');
  }
}catch(Throwable $e){$err=$e->getMessage();}
$backups=[];try{$backups=nexa_db_backup_list();}catch(Throwable $e){if($err==='')$err=$e->getMessage();}
$dbName='';try{$dbName=(string)($pdo->query('SELECT DATABASE()')->fetchColumn()?:'');}catch(Throwable $ignored){}
admin_topbar('Backup do Banco','backup_banco');
?>
<style>
.bkp-wrap{max-width:1180px;margin:0 auto;padding:22px;display:grid;gap:16px}.bkp-card{background:#fff;border:1px solid #dbe4f0;border-radius:20px;padding:19px;box-shadow:0 10px 28px rgba(15,23,42,.05)}.bkp-card h2{margin:0 0 8px;color:#0b1f5b}.bkp-muted{color:#64748b;line-height:1.5}.bkp-msg{padding:12px 14px;border-radius:13px}.bkp-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.bkp-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.bkp-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 14px;border-radius:13px}.bkp-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}.bkp-stat{border:1px solid #dbe4f0;background:#f8fafc;border-radius:14px;padding:12px}.bkp-stat small{display:block;color:#64748b}.bkp-stat strong{display:block;color:#0b1f5b;font-size:18px;margin-top:4px}.bkp-field{display:flex;flex-direction:column;gap:6px}.bkp-field label{font-weight:800;color:#334155}.bkp-field input{padding:11px;border:1px solid #cbd5e1;border-radius:11px;max-width:340px}.bkp-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}.bkp-btn{border:1px solid #cbd5e1;border-radius:11px;padding:10px 14px;background:#fff;color:#0f172a;font-weight:800;text-decoration:none;cursor:pointer}.bkp-btn.primary{background:#0b1f5b;border-color:#0b1f5b;color:#fff}.bkp-btn.danger{background:#991b1b;border-color:#991b1b;color:#fff}.bkp-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:13px}.bkp-table{width:100%;border-collapse:collapse;min-width:880px}.bkp-table th,.bkp-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;font-size:13px}.bkp-table th{background:#f8fafc;color:#475569}.bkp-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-all}@media(max-width:850px){.bkp-grid{grid-template-columns:1fr}.bkp-wrap{padding:14px}}
</style>
<div class="bkp-wrap">
<?php if($msg!==''):?><div class="bkp-msg ok"><?=nexa_db_backup_h($msg)?></div><?php endif;?><?php if($err!==''):?><div class="bkp-msg err"><?=nexa_db_backup_h($err)?></div><?php endif;?>
<section class="bkp-card"><h2>Backup geral do banco</h2><p class="bkp-muted">Gera um <strong>mysqldump completo</strong> do banco atual, incluindo tabelas, dados, triggers, procedures/functions e eventos. O arquivo é compactado em <code>.sql.gz</code>, protegido no servidor e só pode ser baixado por esta tela autenticada.</p><div class="bkp-grid"><div class="bkp-stat"><small>Banco atual</small><strong><?=nexa_db_backup_h($dbName?:'não identificado')?></strong></div><div class="bkp-stat"><small>Backups disponíveis</small><strong><?=count($backups)?></strong></div><div class="bkp-stat"><small>Acesso</small><strong>Somente platform_admin</strong></div></div><div class="bkp-warn" style="margin-top:12px"><strong>Fluxo recomendado:</strong> gerar backup → baixar o arquivo → conferir que o download terminou → somente então executar migration/importação.</div>
<form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=nexa_db_backup_h($csrf)?>"><input type="hidden" name="action" value="create"><div class="bkp-field"><label>Confirmação</label><input name="confirmation" autocomplete="off" placeholder="Digite BACKUP" required></div><div class="bkp-actions"><button class="bkp-btn primary" type="submit" onclick="return confirm('Gerar agora um backup completo do banco <?=nexa_db_backup_h($dbName)?>?')">Gerar novo backup</button></div></form></section>
<section class="bkp-card"><h2>Backups disponíveis</h2><div class="bkp-scroll"><table class="bkp-table"><thead><tr><th>Arquivo</th><th>Gerado em</th><th>Tamanho</th><th>SHA-256</th><th>Ações</th></tr></thead><tbody><?php if(!$backups):?><tr><td colspan="5">Nenhum backup disponível.</td></tr><?php endif;?><?php foreach($backups as $b):$file=(string)($b['file']??'');?><tr><td><strong><?=nexa_db_backup_h($file)?></strong><br><small><?=nexa_db_backup_h((string)($b['database']??''))?></small></td><td><?=nexa_db_backup_h((string)($b['created_at']??''))?></td><td><?=number_format(((int)($b['compressed_bytes']??0))/1048576,2,',','.')?> MB</td><td class="bkp-code"><?=nexa_db_backup_h((string)($b['sha256']??''))?></td><td><div class="bkp-actions" style="margin-top:0"><a class="bkp-btn primary" href="backup_banco.php?download=<?=rawurlencode($file)?>">Download</a><form method="post" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?=nexa_db_backup_h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?=nexa_db_backup_h($file)?>"><input name="delete_confirmation" placeholder="EXCLUIR" required style="width:95px;padding:9px;border:1px solid #cbd5e1;border-radius:9px"><button class="bkp-btn danger" type="submit" onclick="return confirm('Excluir definitivamente este backup do servidor?')">Excluir</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section>
</div>
<?php admin_footer(); ?>
