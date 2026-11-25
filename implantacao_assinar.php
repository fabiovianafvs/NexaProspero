<?php
declare(strict_types=1);

// Trilha de Implantação: o cliente confere o relatório de atendimento técnico e assina no
// próprio celular. Abre sem login pelo link gerado no relatório (vale 7 dias; no banco fica
// só o hash do código). Depois de assinado o link deixa de funcionar.
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
$company = tenant_resolve_company($pdo, (string)($_GET['company'] ?? ''), true);
$cid = tenant_company_id($company);
require_once __DIR__ . '/config/implantacao_module.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
$h = 'impl_h';
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$rel = null;
try { if (impl_ready($pdo)) $rel = impl_relatorio_por_link($pdo, $cid, $token); } catch (Throwable $e) { $rel = null; }
$msg = ''; $err = ''; $feito = false;
if ($rel && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    impl_relatorio_assinar($pdo, $cid, (int)$rel['id'], (string)($_POST['assinatura'] ?? ''), (string)($_POST['nome'] ?? ''), 'LINK', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $feito = true;
  } catch (RuntimeException $e) { $err = $e->getMessage(); }
  catch (Throwable $e) { $err = 'Não foi possível salvar a assinatura. Tente de novo.'; }
}
$empresa = '';
try { $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1'); $st->execute([$cid]); $empresa = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Assinatura do relatório de atendimento</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#eef3fb;color:#0f172a}
.w{max-width:640px;margin:0 auto;padding:16px}
.c{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:16px;margin-bottom:12px;box-shadow:0 10px 30px rgba(11,31,91,.06)}
h1{font-size:20px;margin:0 0 4px;color:#0b1f5b}h2{font-size:15px;margin:0 0 8px;color:#0b1f5b}.m{color:#64748b;font-size:13px}
dl{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:0;font-size:14px}dt{color:#64748b}dd{margin:0;font-weight:600}
ul{margin:4px 0 0;padding-left:18px;font-size:14px}
canvas{border:2px dashed #94a3b8;border-radius:14px;background:#fff;touch-action:none;width:100%;height:220px;display:block}
input{width:100%;min-height:44px;border:1px solid #cbd5e1;border-radius:12px;padding:0 12px;font:inherit;font-size:16px}
.b{display:flex;gap:8px;margin-top:10px}.btn{flex:1;min-height:48px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;font-weight:800;font-size:15px;cursor:pointer}
.btn.p{background:#2454d6;border-color:#2454d6;color:#fff}.e{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:12px;margin-bottom:10px;font-weight:700}
.ok{text-align:center;padding:28px 16px}.ok b{display:block;font-size:44px;color:#16a34a}
</style></head><body><div class="w">
<?php if ($feito): ?>
  <div class="c ok"><b>✓</b><h1>Assinatura registrada</h1><p class="m">Obrigado! O relatório de atendimento foi assinado. Você já pode fechar esta página.</p></div>
<?php elseif (!$rel): ?>
  <div class="c ok"><h1>Link indisponível</h1><p class="m">Este link de assinatura não existe, venceu ou o relatório já foi assinado. Peça um novo link a quem fez o atendimento.</p></div>
<?php else: $tarefas = impl_relatorio_tarefas_nomes($pdo, $cid, (int)$rel['id']); $part = impl_participantes((string)$rel['participantes']); ?>
  <div class="c">
    <h1>Relatório de atendimento técnico nº <?= (int)$rel['numero'] ?></h1>
    <p class="m"><?= $h($empresa) ?></p>
    <dl>
      <dt>Cliente</dt><dd><?= $h($rel['cliente']) ?></dd>
      <dt>Data</dt><dd><?= impl_data_br($rel['data']) ?></dd>
      <dt>Horário</dt><dd><?= $h(substr((string)$rel['hora_inicio'], 0, 5) . ' às ' . substr((string)$rel['hora_fim'], 0, 5)) ?></dd>
      <dt>Técnico</dt><dd><?= $h($rel['implantador'] ?: '-') ?></dd>
    </dl>
  </div>
  <div class="c"><h2>Participantes do treinamento</h2><?php if ($part): ?><ul><?php foreach ($part as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?></ul><?php else: ?><p class="m">—</p><?php endif; ?></div>
  <div class="c"><h2>Processos implantados</h2><?php if ($tarefas): ?><ul><?php foreach ($tarefas as $t): ?><li><?= $h($t['nome']) ?> <span class="m">(<?= $h($t['etapa_nome']) ?>)</span></li><?php endforeach; ?></ul><?php else: ?><p class="m">—</p><?php endif; ?>
    <?php if (trim((string)$rel['observacoes']) !== ''): ?><h2 style="margin-top:12px">Observações</h2><p style="white-space:pre-wrap;margin:0;font-size:14px"><?= $h($rel['observacoes']) ?></p><?php endif; ?>
  </div>
  <form class="c" method="post" id="f">
    <h2>Sua assinatura</h2>
    <?php if ($err !== ''): ?><div class="e"><?= $h($err) ?></div><?php endif; ?>
    <p class="m">Confira os dados acima, escreva seu nome e assine com o dedo no quadro.</p>
    <input type="hidden" name="t" value="<?= $h($token) ?>"><input type="hidden" name="assinatura" id="a">
    <input type="text" name="nome" id="n" placeholder="Seu nome completo" autocomplete="name" required style="margin-bottom:10px">
    <canvas id="cv"></canvas>
    <div class="b"><button class="btn" type="button" id="l">Limpar</button><button class="btn p" type="submit">Assinar</button></div>
  </form>
  <script src="/assets/implantacao/assinatura.js?v=1"></script>
  <script>
    const pad=NexaAssinatura(document.getElementById('cv'));
    document.getElementById('l').addEventListener('click',()=>pad.clear());
    document.getElementById('f').addEventListener('submit',e=>{
      if(document.getElementById('n').value.trim().length<3){e.preventDefault();alert('Escreva seu nome completo.');return;}
      if(pad.isEmpty()){e.preventDefault();alert('Assine no quadro antes de enviar.');return;}
      document.getElementById('a').value=pad.toDataURL();
    });
  </script>
<?php endif; ?>
</div></body></html>
