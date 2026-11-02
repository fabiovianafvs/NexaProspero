<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/maintenance_bulk.php';
require __DIR__ . '/_chrome.php';

// Restringe também o acesso direto à URL, não apenas a exibição do menu.
$canRecoverSellers = (function_exists('access_is_company_master') && access_is_company_master())
  || ((function_exists('access_has_permission') && access_has_permission($pdo, (int)COMPANY_ID, 'manutencao_produtos'))
    && (!function_exists('saas_admin_nav_allowed') || saas_admin_nav_allowed($pdo, (int)COMPANY_ID, 'utilitarios')));
if (!$canRecoverSellers) {
  http_response_code(403);
  exit('Acesso negado: permissão de manutenção de produtos necessária.');
}

// Manutenção por empresa: ignora deliberadamente qualquer contexto F11 de outra empresa.
$companyId = (int)COMPANY_ID;
$error = '';
$success = '';
$changed = null;
$rows = [];
$totals = ['elegiveis'=>0,'pendentes'=>0,'ambiguos'=>0];

$sql = <<<'SQL'
SELECT p.id AS pedido_id, p.numero_pedido AS numero_pedido,
       GROUP_CONCAT(DISTINCT CONCAT(COALESCE(o.quote_number, o.id), ' · ', COALESCE(o.seller_name_snapshot, 'sem nome')) ORDER BY o.id SEPARATOR ' / ') AS orcamentos,
       COUNT(DISTINCT o.id) AS quantidade_orcamentos,
       COUNT(DISTINCT CASE WHEN o.seller_person_id > 0 THEN o.seller_person_id END) AS vendedores_distintos,
       SUM(CASE WHEN o.seller_person_id IS NULL OR o.seller_person_id = 0 THEN 1 ELSE 0 END) AS sem_vendedor,
       MIN(NULLIF(o.seller_person_id, 0)) AS vendedor_sugerido,
       MAX(fp.name) AS vendedor_nome,
       CASE WHEN COUNT(*) = COUNT(NULLIF(o.seller_person_id, 0))
             AND COUNT(DISTINCT o.seller_person_id) = 1
             AND COUNT(fp.id) = COUNT(*)
            THEN 1 ELSE 0 END AS elegivel
FROM pedidos p
INNER JOIN orcamentos o ON o.company_id = p.company_id AND o.converted_order_id = p.id
LEFT JOIN finance_people fp ON fp.company_id = p.company_id AND fp.id = o.seller_person_id
WHERE p.company_id = :cid AND (p.seller_id IS NULL OR p.seller_id = 0)
GROUP BY p.id
ORDER BY p.numero_pedido DESC, p.id DESC
SQL;

try {
  if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nexa_bulk_csrf_check((string)($_POST['csrf'] ?? ''));
    if ((string)($_POST['action'] ?? '') !== 'apply' || (int)($_POST['company_id'] ?? 0) !== $companyId ||
        strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'RECUPERAR') {
      throw new RuntimeException('Confirmação inválida. Revise a empresa e digite RECUPERAR.');
    }
    $pdo->beginTransaction();
    try {
      // Atualiza somente pedidos ainda sem vendedor; em caso de concorrência, revalida
      // a unanimidade dos orçamentos e a existência da pessoa vinculada à mesma empresa.
      $updateSql = <<<'SQL'
UPDATE pedidos p
JOIN (
 SELECT o.company_id, o.converted_order_id AS pedido_id, MIN(o.seller_person_id) AS vendedor_id
 FROM orcamentos o
 WHERE o.company_id = :company_id AND o.converted_order_id > 0
 GROUP BY o.company_id, o.converted_order_id
 HAVING COUNT(*) = COUNT(NULLIF(o.seller_person_id, 0))
    AND COUNT(DISTINCT o.seller_person_id) = 1
) q ON q.company_id = p.company_id AND q.pedido_id = p.id
JOIN finance_people fp ON fp.company_id = p.company_id AND fp.id = q.vendedor_id
SET p.seller_id = q.vendedor_id
WHERE p.company_id = :company_filter AND (p.seller_id IS NULL OR p.seller_id = 0)
SQL;
      $stmt = $pdo->prepare($updateSql);
      $stmt->execute([':company_id'=>$companyId, ':company_filter'=>$companyId]);
      $changed = $stmt->rowCount();
      $pdo->commit();
      $success = $changed . ' venda(s) atualizada(s) na empresa #' . $companyId . '. Nenhuma comissão foi recalculada.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':cid'=>$companyId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    ++$totals['pendentes'];
    if ((int)$r['elegivel'] === 1) ++$totals['elegiveis'];
    else ++$totals['ambiguos'];
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
}
function nexa_rv_e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
admin_topbar('Utilitários - Recuperação de Vendedores', 'recuperar_vendedores');
?>
<style>
.rv-panel{background:#fff;border:1px solid #dbe3ee;border-radius:14px;padding:20px;margin:12px 0;box-shadow:0 3px 16px #162b4310}
.rv-stats{display:flex;gap:12px;flex-wrap:wrap}.rv-stat{flex:1;min-width:170px;padding:14px;border:1px solid #e2e8f0;border-radius:12px}.rv-stat strong{font-size:26px;display:block;color:#123964}
.rv-message{padding:12px;border-radius:10px;margin:12px 0}.rv-error{background:#fef2f2;color:#991b1b}.rv-ok{background:#f0fdf4;color:#166534}
.rv-table{width:100%;border-collapse:collapse;font-size:13px}.rv-table th,.rv-table td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0}.rv-table th{background:#f1f5f9}.rv-table-wrap{overflow-x:auto;max-height:440px;overflow-y:auto}
.rv-tag{font-weight:700}.rv-wait{color:#9a3412}.rv-good{color:#166534}.rv-input{border:1px solid #94a3b8;border-radius:8px;padding:9px 10px;max-width:240px}
</style>
<section class="rv-panel">
<h1>Recuperação histórica de vendedores</h1>
<p>Empresa atual: <strong>#<?= $companyId ?></strong>. A análise considera exclusivamente pedidos desta empresa que estão sem vendedor e possuem vínculo com orçamento convertido. Não utiliza empresas selecionadas via F11.</p>
<p><strong>Atenção:</strong> recomenda-se backup do banco antes da correção. Não há recálculo de comissões, nem alterações de financeiro, estoque ou documentos fiscais.</p>
<?php if ($error !== ''): ?><div class="rv-message rv-error"><?= nexa_rv_e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="rv-message rv-ok"><?= nexa_rv_e($success) ?></div><?php endif; ?>
<div class="rv-stats">
  <div class="rv-stat">Pedidos vinculados sem vendedor<strong><?= $totals['pendentes'] ?></strong></div>
  <div class="rv-stat">Elegíveis para correção<strong><?= $totals['elegiveis'] ?></strong></div>
  <div class="rv-stat">Requerem análise manual<strong><?= $totals['ambiguos'] ?></strong></div>
</div>
</section>
<section class="rv-panel">
<h2>Prévia por pedido</h2>
<p>Somente as linhas <strong>Elegível</strong> serão corrigidas. Quando faltar vendedor em algum orçamento, houver vendedores diferentes ou o cadastro correspondente não existir nesta empresa, o registro será mantido como está.</p>
<div class="rv-table-wrap"><table class="rv-table"><thead><tr><th>Pedido</th><th>Orçamentos de origem</th><th>Qtd.</th><th>Vendedor proposto</th><th>Condição</th></tr></thead><tbody>
<?php foreach (array_slice($rows, 0, 500) as $r): ?>
<tr><td>#<?= (int)($r['numero_pedido'] ?: $r['pedido_id']) ?></td><td><?= nexa_rv_e($r['orcamentos']) ?></td><td><?= (int)$r['quantidade_orcamentos'] ?></td><td><?= nexa_rv_e($r['vendedor_nome'] ?: $r['vendedor_sugerido'] ?: '—') ?></td>
<td><span class="rv-tag <?= (int)$r['elegivel'] === 1 ? 'rv-good' : 'rv-wait' ?>"><?= (int)$r['elegivel'] === 1 ? 'Elegível' : 'Conferir manualmente' ?></span></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">Nenhum pedido pendente encontrado nesta empresa.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (count($rows) > 500): ?><p>Exibindo os primeiros 500 registros. O total acima considera todos os registros analisados.</p><?php endif; ?>
</section>
<?php if ($totals['elegiveis'] > 0 && $error === ''): ?>
<section class="rv-panel">
<h2>Executar correção desta empresa</h2>
<form method="post" onsubmit="return confirm('Confirma a atualização dos vendedores das vendas elegíveis desta empresa?');">
<input type="hidden" name="csrf" value="<?= nexa_rv_e(nexa_bulk_csrf_token()) ?>">
<input type="hidden" name="action" value="apply"><input type="hidden" name="company_id" value="<?= $companyId ?>">
<p>Digite <strong>RECUPERAR</strong> para confirmar a atualização exclusivamente do campo vendedor:</p>
<input class="rv-input" name="confirm_text" autocomplete="off" required placeholder="RECUPERAR" pattern="RECUPERAR">
<button class="btn btn-primary" type="submit">Atualizar vendedores elegíveis</button>
</form>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
