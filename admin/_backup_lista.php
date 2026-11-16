<?php
// Layout comum de Backup Geral (backup_banco.php) e Backup da Empresa (backup_empresa.php).
// Espera: $bkpTitle, $bkpIntro (HTML já escapado), $bkpCreateLabel, $bkpConfirm, $bkpPage,
// $bkpTip, $backups (file, created_at, compressed_bytes), $csrf, $msg, $err.
if (!defined('COMPANY_ID')) { http_response_code(403); exit; }
$bkpH = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$bkpSize = static function (int $bytes): string {
  if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
  if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
  return number_format(max(1, $bytes / 1024), 0, ',', '.') . ' KB';
};
?>
<style>
.bkp-wrap{max-width:1100px;margin:14px auto 0;display:grid;gap:14px}
.bkp-head{background:linear-gradient(135deg,#071b55,#2454d6);color:#fff;border-radius:22px;padding:18px 20px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;box-shadow:0 18px 48px rgba(11,31,91,.16)}
.bkp-head h1{margin:0 0 4px;font-size:22px}.bkp-head p{margin:0;color:#dbeafe;font-size:13px;line-height:1.5}.bkp-head p b{color:#fff}
.bkp-go{display:grid;gap:6px;justify-items:end;text-align:right}.bkp-go small{color:#bfdbfe;font-size:12px;font-weight:700}
.bkp-btn{border:0;border-radius:12px;padding:12px 18px;background:#22c55e;color:#052e16;font-weight:900;font-size:15px;cursor:pointer;white-space:nowrap}.bkp-btn:disabled{opacity:.6;cursor:wait}
.bkp-msg{padding:12px 14px;border-radius:14px;font-weight:700}.bkp-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.bkp-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.bkp-card{background:#fff;border:1px solid #dfe7f3;border-radius:20px;padding:16px;box-shadow:0 12px 34px rgba(15,23,42,.05)}.bkp-card h2{margin:0 0 4px;color:#0b1f5b;font-size:18px}.bkp-muted{color:#64748b;font-size:12.5px;line-height:1.5}
.bkp-list{width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed}.bkp-list th{text-align:left;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.03em;padding:8px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.bkp-list td{padding:10px;border-bottom:1px solid #eef2f7;font-size:13px;vertical-align:middle;overflow-wrap:anywhere}.bkp-list tr:last-child td{border-bottom:0}
.bkp-list .c-date{width:150px;white-space:nowrap}.bkp-list .c-size{width:100px;text-align:right;white-space:nowrap}.bkp-list .c-act{width:210px;text-align:right}
.bkp-file{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:#0b1f5b}
.bkp-act{display:inline-flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}.bkp-act form{margin:0}
.bkp-a{display:inline-flex;align-items:center;border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:6px 12px;font-weight:800;font-size:13px;cursor:pointer;color:#0b1f5b;text-decoration:none}.bkp-a:hover{background:#f1f5ff}.bkp-a.dl{background:#0b1f5b;border-color:#0b1f5b;color:#fff}.bkp-a.del{color:#b91c1c;border-color:#fecaca}.bkp-a.del:hover{background:#fef2f2}
.bkp-empty{padding:18px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:14px;margin-top:10px}
@media(max-width:760px){.bkp-head{grid-template-columns:1fr}.bkp-go{justify-items:stretch;text-align:left}.bkp-btn{width:100%}.bkp-list thead{display:none}.bkp-list,.bkp-list tbody,.bkp-list tr,.bkp-list td{display:block;width:auto!important}.bkp-list tr{border-bottom:1px solid #e2e8f0;padding:6px 0}.bkp-list td{border:0;padding:3px 4px;text-align:left!important}.bkp-act{justify-content:flex-start}}
</style>
<div class="bkp-wrap">
  <section class="bkp-head">
    <div><h1><?= $bkpH($bkpTitle) ?></h1><p><?= $bkpIntro ?></p></div>
    <form method="post" class="bkp-go" data-bkp-create data-confirm="<?= $bkpH($bkpConfirm) ?>">
      <input type="hidden" name="csrf" value="<?= $bkpH($csrf) ?>"><input type="hidden" name="action" value="create">
      <button class="bkp-btn" type="submit"><?= $bkpH($bkpCreateLabel) ?></button>
      <small><?= count($backups) ?> backup(s) guardado(s)</small>
    </form>
  </section>
  <?php if ($msg !== ''): ?><div class="bkp-msg ok"><?= $bkpH($msg) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="bkp-msg err"><?= $bkpH($err) ?></div><?php endif; ?>
  <section class="bkp-card">
    <h2>Backups</h2>
    <div class="bkp-muted"><?= $bkpH($bkpTip) ?></div>
    <?php if (!$backups): ?><div class="bkp-empty">Nenhum backup ainda. Clique em "<?= $bkpH($bkpCreateLabel) ?>".</div><?php else: ?>
    <table class="bkp-list">
      <thead><tr><th class="c-date">Data/hora</th><th>Arquivo</th><th class="c-size">Tamanho</th><th class="c-act"></th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b): $file = (string)($b['file'] ?? ''); $t = strtotime((string)($b['created_at'] ?? '')); ?>
        <tr>
          <td class="c-date"><?= $t ? date('d/m/Y H:i', $t) : '—' ?></td>
          <td><span class="bkp-file"><?= $bkpH($file) ?></span></td>
          <td class="c-size"><?= $bkpSize((int)($b['compressed_bytes'] ?? 0)) ?></td>
          <td class="c-act"><div class="bkp-act">
            <a class="bkp-a dl" href="<?= $bkpH($bkpPage) ?>?download=<?= rawurlencode($file) ?>">Download</a>
            <form method="post" onsubmit="return confirm('Excluir definitivamente o backup <?= $bkpH($file) ?>?');"><input type="hidden" name="csrf" value="<?= $bkpH($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= $bkpH($file) ?>"><button class="bkp-a del" type="submit">Excluir</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>
<script>
(function(){
  const f=document.querySelector('[data-bkp-create]');if(!f)return;
  f.addEventListener('submit',e=>{
    if(!confirm(f.dataset.confirm)){e.preventDefault();return;}
    const b=f.querySelector('button');setTimeout(()=>{b.disabled=true;b.textContent='Gerando backup… não feche a página';},0);
  });
})();
</script>
