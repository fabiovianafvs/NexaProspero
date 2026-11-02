<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';

$erro = '';
company_sequence_ensure_schema($pdo, true);
$hasNumeroPedido = company_sequence_has_column($pdo, 'pedidos');
$numeroPedidoSelect = $hasNumeroPedido ? ', p.numero_pedido' : ', NULL AS numero_pedido';
$orderPedidoExportacao = $hasNumeroPedido ? 'COALESCE(p.numero_pedido, p.id) DESC, e.pedido_id DESC' : 'e.pedido_id DESC';

// Ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $acao = (string)($_POST['acao'] ?? '');
  $pedidoId = (int)($_POST['pedido_id'] ?? 0);

  try {
    if ($acao === 'marcar_exportado' && $pedidoId > 0) {
      $st = $pdo->prepare("UPDATE pedidos_exportacao
                          SET exportado=1, exportado_em=NOW(), ultimo_erro=NULL
                          WHERE company_id=? AND pedido_id=?");
      $st->execute([COMPANY_ID, $pedidoId]);
    }

    if ($acao === 'reprocessar' && $pedidoId > 0) {
      $st = $pdo->prepare("UPDATE pedidos_exportacao
                          SET ultimo_erro=NULL, tentativas=0
                          WHERE company_id=? AND pedido_id=? AND exportado=0");
      $st->execute([COMPANY_ID, $pedidoId]);
    }

    if ($acao === 'gerar_fila') {
      // Garante que todo pedido tenha registro na fila
      $st = $pdo->prepare("INSERT INTO pedidos_exportacao (company_id, pedido_id)
                 SELECT p.company_id, p.id
                 FROM pedidos p
                 LEFT JOIN pedidos_exportacao e ON e.pedido_id = p.id AND e.company_id = p.company_id
                 WHERE p.company_id = ? AND e.pedido_id IS NULL");
      $st->execute([COMPANY_ID]);
    }

    header('Location: exportacao.php');
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Listagens
$pend = [];
$erros = [];
try {
  $st = $pdo->prepare("SELECT e.pedido_id, e.created_at, e.tentativas, e.ultimo_erro,
                              p.nome_cliente, p.total, p.status, p.created_at AS pedido_criado{$numeroPedidoSelect}
                        FROM pedidos_exportacao e
                        JOIN pedidos p ON p.id = e.pedido_id AND p.company_id = e.company_id
                        WHERE e.company_id = ? AND e.exportado = 0
                        ORDER BY {$orderPedidoExportacao}
                        LIMIT 200");
  $st->execute([COMPANY_ID]);
  $pend = $st->fetchAll(PDO::FETCH_ASSOC);

  $st = $pdo->prepare("SELECT e.pedido_id, e.created_at, e.tentativas, e.ultimo_erro,
                              p.nome_cliente, p.total, p.status, p.created_at AS pedido_criado{$numeroPedidoSelect}
                        FROM pedidos_exportacao e
                        JOIN pedidos p ON p.id = e.pedido_id AND p.company_id = e.company_id
                        WHERE e.company_id = ? AND e.exportado = 0 AND e.ultimo_erro IS NOT NULL AND e.ultimo_erro <> ''
                        ORDER BY {$orderPedidoExportacao}
                        LIMIT 200");
  $st->execute([COMPANY_ID]);
  $erros = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $erro = $erro ?: $e->getMessage();
}

?>
<?php admin_topbar('Central - Exportação','export'); ?>
<div style="padding:22px;max-width:1100px;">

  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="color:#555;">Fila intermediária (MySQL) para exportar pedidos para o Firebird depois.</div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="acao" value="gerar_fila">
      <button type="submit" style="padding:8px 12px;">Gerar fila para pedidos antigos</button>
    </form>
  </div>

  <?php if ($erro): ?>
    <div style="margin-top:12px;padding:10px 12px;background:#ffe3e3;border:1px solid #ffa8a8;border-radius:10px;">
      <b>Erro:</b> <?= h($erro) ?>
      <div style="margin-top:6px;color:#555;font-size:12px;">Se a tabela <code>pedidos_exportacao</code> ainda não existir, execute o SQL de upgrade v6.</div>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:18px;">Pendentes (<?= count($pend) ?>)</h3>
  <div style="overflow:auto;border:1px solid #ddd;border-radius:12px;">
    <table style="border-collapse:collapse;min-width:900px;width:100%;">
      <thead>
        <tr>
          <th style="border:1px solid #ddd;padding:8px;">Pedido</th>
          <th style="border:1px solid #ddd;padding:8px;">Cliente</th>
          <th style="border:1px solid #ddd;padding:8px;">Total</th>
          <th style="border:1px solid #ddd;padding:8px;">Status</th>
          <th style="border:1px solid #ddd;padding:8px;">Criado</th>
          <th style="border:1px solid #ddd;padding:8px;">Tentativas</th>
          <th style="border:1px solid #ddd;padding:8px;">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pend): ?>
        <tr><td colspan="7" style="border:1px solid #ddd;padding:10px;color:#666;">Nenhum pendente 🎉</td></tr>
      <?php else: foreach ($pend as $r): ?>
        <tr>
          <td style="border:1px solid #ddd;padding:8px;"><a href="pedido.php?id=<?= (int)$r['pedido_id'] ?>">#<?= (int)company_sequence_display($r, 'pedidos', 'pedido_id') ?></a></td>
          <td style="border:1px solid #ddd;padding:8px;"><?= h((string)$r['nome_cliente']) ?></td>
          <td style="border:1px solid #ddd;padding:8px;">R$ <?= number_format((float)$r['total'], 2, ',', '.') ?></td>
          <td style="border:1px solid #ddd;padding:8px;"><?= h(fmt_status((string)$r['status'])) ?></td>
          <td style="border:1px solid #ddd;padding:8px;white-space:nowrap;"><?= h(fmt_dt_br((string)$r['pedido_criado'])) ?></td>
          <td style="border:1px solid #ddd;padding:8px;"><?= (int)$r['tentativas'] ?></td>
          <td style="border:1px solid #ddd;padding:8px;white-space:nowrap;">
            <form method="post" style="display:inline;">
              <input type="hidden" name="acao" value="marcar_exportado">
              <input type="hidden" name="pedido_id" value="<?= (int)$r['pedido_id'] ?>">
              <button type="submit">Marcar exportado</button>
            </form>
            <a href="pedido_print.php?id=<?= (int)$r['pedido_id'] ?>" target="_blank" style="margin-left:8px;">Imprimir</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <h3 style="margin-top:18px;">Com erro (<?= count($erros) ?>)</h3>
  <div style="overflow:auto;border:1px solid #ddd;border-radius:12px;">
    <table style="border-collapse:collapse;min-width:900px;width:100%;">
      <thead>
        <tr>
          <th style="border:1px solid #ddd;padding:8px;">Pedido</th>
          <th style="border:1px solid #ddd;padding:8px;">Erro</th>
          <th style="border:1px solid #ddd;padding:8px;">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$erros): ?>
        <tr><td colspan="3" style="border:1px solid #ddd;padding:10px;color:#666;">Nenhum erro registrado.</td></tr>
      <?php else: foreach ($erros as $r): ?>
        <tr>
          <td style="border:1px solid #ddd;padding:8px;"><a href="pedido.php?id=<?= (int)$r['pedido_id'] ?>">#<?= (int)company_sequence_display($r, 'pedidos', 'pedido_id') ?></a></td>
          <td style="border:1px solid #ddd;padding:8px;max-width:650px;">
            <div style="white-space:pre-wrap;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;font-size:12px;">
              <?= h((string)$r['ultimo_erro']) ?>
            </div>
          </td>
          <td style="border:1px solid #ddd;padding:8px;white-space:nowrap;">
            <form method="post" style="display:inline;">
              <input type="hidden" name="acao" value="reprocessar">
              <input type="hidden" name="pedido_id" value="<?= (int)$r['pedido_id'] ?>">
              <button type="submit">Limpar erro</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>

<?php admin_footer(); ?>
