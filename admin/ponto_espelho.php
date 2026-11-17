<?php
declare(strict_types=1);

// Ponto Eletrônico > Espelho de Ponto: impressão mensal (art. 84) de um colaborador ou de todos
// os colaboradores do estabelecimento. Para o próprio colaborador: ponto_meu_espelho.php.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_fechamento.php';
require_once __DIR__ . '/_ponto_espelho_render.php';

$cid = (int)COMPANY_ID;
if (!ponto_can($pdo, 'ponto_espelho')) { http_response_code(403); exit('Sem permissão para o Espelho de Ponto.'); }
if (!ponto_ready($pdo) || !ponto_v4_ready($pdo)) exit('O Ponto Eletrônico ainda não foi instalado (Atualizador do Banco).');
[$de, $ate, $compLabel, $comp] = ponto_competencia((string)($_GET['c'] ?? date('Y-m', strtotime(date('Y-m-01') . ' -1 month'))));
$estabs = ponto_options($pdo, $cid, 'estabelecimentos', false);
$estabId = (int)($_GET['e'] ?? 0);
if (!isset($estabs[$estabId])) $estabId = (int)(array_key_first($estabs) ?? 0);
$colId = (int)($_GET['col'] ?? 0);
$cols = [];
if ($estabId) {
  $st = $pdo->prepare('SELECT id, nome FROM ponto_colaboradores WHERE company_id = ? AND estabelecimento_id = ? AND controla_ponto = 1 AND data_admissao <= ? AND (data_demissao IS NULL OR data_demissao >= ?) ORDER BY nome');
  $st->execute([$cid, $estabId, $ate, $de]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[(int)$r['id']] = (string)$r['nome'];
}
$ids = $colId && isset($cols[$colId]) ? [$colId] : array_keys($cols);
$lista = [];
foreach ($ids as $id) $lista[] = ponto_espelho_dados($pdo, $cid, $id, $de, $ate);
ponto_audit($pdo, $cid, 'emitir_espelho', 'espelho', null, null, ['competencia' => $compLabel, 'colaboradores' => count($lista)]);
ob_start(); ?>
<a href="ponto_fechamento.php?c=<?= ponto_h($comp) ?>">← Voltar</a>
<form method="get">
  <?php if (count($estabs) > 1): ?><select name="e" onchange="this.form.submit()"><?php foreach ($estabs as $k => $v): ?><option value="<?= (int)$k ?>" <?= (int)$k === $estabId ? 'selected' : '' ?>><?= ponto_h($v) ?></option><?php endforeach; ?></select><?php else: ?><input type="hidden" name="e" value="<?= $estabId ?>"><?php endif; ?>
  <input type="month" name="c" value="<?= ponto_h($comp) ?>" onchange="this.form.submit()">
  <select name="col" onchange="this.form.submit()"><option value="">Todos (<?= count($cols) ?>)</option><?php foreach ($cols as $k => $v): ?><option value="<?= $k ?>" <?= $k === $colId ? 'selected' : '' ?>><?= ponto_h($v) ?></option><?php endforeach; ?></select>
</form>
<?php
ponto_espelho_render('Espelho de Ponto · ' . $compLabel, $lista, (string)ob_get_clean(), $compLabel);
