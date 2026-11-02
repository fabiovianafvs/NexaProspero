<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/brain_profitability.php';

brain_profitability_ensure_schema($pdo, true);

function admin_edit_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return $cache[$key] = (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return $cache[$key] = false;
  }
}

function admin_edit_money(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: pedidos.php'); exit; }

$obsCol = 'observacoes';
foreach (['observacoes','observacao','obs'] as $c) {
  if (admin_edit_has_column($pdo, 'pedidos', $c)) { $obsCol = $c; break; }
}
$itemObsCol = null;
foreach (['complemento','observacao','obs','observacoes'] as $c) {
  if (admin_edit_has_column($pdo, 'pedido_itens', $c)) { $itemObsCol = $c; break; }
}
$hasBairro = admin_edit_has_column($pdo, 'pedidos', 'bairro');

$ok = '';
$erro = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save_order');
    if ($action === 'save_order') {
      $nome = trim((string)($_POST['nome_cliente'] ?? ''));
      $whatsapp = preg_replace('/\D+/', '', (string)($_POST['whatsapp'] ?? ''));
      $endereco = trim((string)($_POST['endereco'] ?? ''));
      $bairro = trim((string)($_POST['bairro'] ?? ''));
      $tipoEntrega = trim((string)($_POST['tipo_entrega'] ?? ''));
      $formaPagamento = trim((string)($_POST['forma_pagamento'] ?? ''));
      $status = trim((string)($_POST['status'] ?? 'aberto'));
      $observacoes = trim((string)($_POST['observacoes'] ?? ''));
      $taxaEntrega = (float)str_replace(',', '.', (string)($_POST['taxa_entrega'] ?? '0'));
      $taxaEntrega = max(0, $taxaEntrega);
      if ($nome === '') throw new RuntimeException('Informe o nome do cliente.');
      if (!in_array($status, ['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'], true)) $status = 'aberto';

      $itemIds = $_POST['item_id'] ?? [];
      $produtoIds = $_POST['produto_id'] ?? [];
      $qtds = $_POST['qtd'] ?? [];
      $precos = $_POST['preco_unit'] ?? [];
      $itemObs = $_POST['item_obs'] ?? [];
      $remove = $_POST['remove_item'] ?? [];

      $updates = [];
      $totalProdutos = 0.0;
      for ($i = 0; $i < count($produtoIds); $i++) {
        $itemId = (int)($itemIds[$i] ?? 0);
        $produtoId = (int)($produtoIds[$i] ?? 0);
        $qtd = (float)str_replace(',', '.', (string)($qtds[$i] ?? '0'));
        $preco = (float)str_replace(',', '.', (string)($precos[$i] ?? '0'));
        $obsItem = trim((string)($itemObs[$i] ?? ''));
        $remover = !empty($remove[$itemId]) && $itemId > 0;

        if ($remover) {
          $updates[] = ['mode'=>'delete','item_id'=>$itemId];
          continue;
        }
        if ($produtoId <= 0 || $qtd <= 0 || $preco < 0) continue;
        $total = $qtd * $preco;
        $totalProdutos += $total;
        $updates[] = [
          'mode' => $itemId > 0 ? 'update' : 'insert',
          'item_id' => $itemId,
          'produto_id' => $produtoId,
          'qtd' => $qtd,
          'preco' => $preco,
          'total' => $total,
          'obs' => $obsItem,
        ];
      }
      if (!$updates) throw new RuntimeException('Informe ao menos um item no pedido.');
      $totalPedido = $totalProdutos + $taxaEntrega;

      $pdo->beginTransaction();
      $set = ['nome_cliente = ?', 'whatsapp = ?', 'endereco = ?', 'tipo_entrega = ?', 'taxa_entrega = ?', 'forma_pagamento = ?', $obsCol . ' = ?', 'total_produtos = ?', 'total = ?', 'status = ?'];
      $vals = [$nome, $whatsapp, $endereco, $tipoEntrega, $taxaEntrega, $formaPagamento, $observacoes, $totalProdutos, $totalPedido, $status];
      if ($hasBairro) { $set[] = 'bairro = ?'; $vals[] = $bairro; }
      $vals[] = $id;
      $vals[] = COMPANY_ID;
      $pdo->prepare('UPDATE pedidos SET ' . implode(', ', $set) . ' WHERE id = ? AND company_id = ?')->execute($vals);

      foreach ($updates as $u) {
        if ($u['mode'] === 'delete') {
          $pdo->prepare('DELETE FROM pedido_itens WHERE id = ? AND pedido_id = ? AND company_id = ?')->execute([(int)$u['item_id'], $id, COMPANY_ID]);
        } elseif ($u['mode'] === 'update') {
          $setItem = ['produto_id = ?', 'qtd = ?', 'preco_unit = ?', 'total = ?'];
          $valsItem = [(int)$u['produto_id'], (float)$u['qtd'], (float)$u['preco'], (float)$u['total']];
          if ($itemObsCol) { $setItem[] = $itemObsCol . ' = ?'; $valsItem[] = (string)$u['obs']; }
          $valsItem[] = (int)$u['item_id'];
          $valsItem[] = $id;
          $valsItem[] = COMPANY_ID;
          $pdo->prepare('UPDATE pedido_itens SET ' . implode(', ', $setItem) . ' WHERE id = ? AND pedido_id = ? AND company_id = ?')->execute($valsItem);
        } elseif ($u['mode'] === 'insert') {
          if ($itemObsCol) {
            $pdo->prepare('INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total, ' . $itemObsCol . ') VALUES (?, ?, ?, ?, ?, ?, ?)')
              ->execute([COMPANY_ID, $id, (int)$u['produto_id'], (float)$u['qtd'], (float)$u['preco'], (float)$u['total'], (string)$u['obs']]);
          } else {
            $pdo->prepare('INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total) VALUES (?, ?, ?, ?, ?, ?)')
              ->execute([COMPANY_ID, $id, (int)$u['produto_id'], (float)$u['qtd'], (float)$u['preco'], (float)$u['total']]);
          }
        }
      }

      brain_profitability_recalculate_order($pdo, (int)COMPANY_ID, $id, false, $status === 'finalizado' ? 'order.finalized' : ($status === 'cancelado' ? 'order.cancelled' : 'order.updated'));

      try {
        $pdo->prepare('INSERT IGNORE INTO pedidos_exportacao (company_id, pedido_id) VALUES (?, ?)')->execute([COMPANY_ID, $id]);
      } catch (Throwable $e) {}

      $pdo->commit();
      header('Location: pedido.php?id=' . $id . '&edit_ok=1');
      exit;
    }
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $erro = $e->getMessage();
}

$st = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? AND company_id = ?');
$st->execute([$id, COMPANY_ID]);
$pedido = $st->fetch(PDO::FETCH_ASSOC);
if (!$pedido) { http_response_code(404); echo 'Pedido não encontrado'; exit; }

$sti = $pdo->prepare('SELECT pi.*, p.nome FROM pedido_itens pi LEFT JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pi.company_id WHERE pi.pedido_id = ? AND pi.company_id = ? ORDER BY pi.id ASC');
$sti->execute([$id, COMPANY_ID]);
$itens = $sti->fetchAll(PDO::FETCH_ASSOC);

$produtos = $pdo->prepare('SELECT id, nome, preco FROM produtos WHERE company_id = ? AND COALESCE(ativo,1) = 1 ORDER BY nome ASC LIMIT 1000');
try { $produtos->execute([COMPANY_ID]); } catch (Throwable $e) { $produtos = $pdo->prepare('SELECT id, nome, preco FROM produtos WHERE company_id = ? ORDER BY nome ASC LIMIT 1000'); $produtos->execute([COMPANY_ID]); }
$produtosRows = $produtos->fetchAll(PDO::FETCH_ASSOC);
$produtosJson = json_encode(array_map(fn($p) => ['id'=>(int)$p['id'], 'nome'=>(string)$p['nome'], 'preco'=>(float)$p['preco']], $produtosRows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

admin_topbar('Editar pedido #' . $id, 'pedidos');
?>
<div style="margin-top:14px;max-width:1200px;display:grid;gap:14px;">
  <div class="actions right" style="margin-top:4px;">
    <a class="btn" href="pedido.php?id=<?= (int)$id ?>">Voltar ao pedido</a>
    <a class="btn" href="pedidos.php">Lista de pedidos</a>
  </div>
  <?php if ($erro): ?><div class="card" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?= h($erro) ?></div><?php endif; ?>

  <form method="post" class="card" id="orderEditForm" style="display:grid;gap:16px;">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="action" value="save_order">

    <h3 style="margin:0;">Dados do cliente</h3>
    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
      <label>Cliente<input name="nome_cliente" required value="<?= h((string)($pedido['nome_cliente'] ?? '')) ?>"></label>
      <label>WhatsApp<input name="whatsapp" value="<?= h((string)($pedido['whatsapp'] ?? '')) ?>"></label>
      <?php if ($hasBairro): ?><label>Bairro<input name="bairro" value="<?= h((string)($pedido['bairro'] ?? '')) ?>"></label><?php endif; ?>
      <label>Tipo entrega<input name="tipo_entrega" value="<?= h((string)($pedido['tipo_entrega'] ?? '')) ?>"></label>
      <label>Forma pagamento<input name="forma_pagamento" value="<?= h((string)($pedido['forma_pagamento'] ?? '')) ?>"></label>
      <label>Status
        <select name="status">
          <?php foreach (['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'] as $s): ?>
            <option value="<?= h($s) ?>" <?= ((string)($pedido['status'] ?? '') === $s) ? 'selected' : '' ?>><?= h(fmt_status($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Endereço<textarea name="endereco" rows="2"><?= h((string)($pedido['endereco'] ?? '')) ?></textarea></label>
    <label>Observações<textarea name="observacoes" rows="3"><?= h((string)($pedido[$obsCol] ?? '')) ?></textarea></label>

    <h3 style="margin:0;">Itens do pedido</h3>
    <div class="table-wrap"><table id="itemsTable">
      <thead><tr><th>Produto</th><th style="width:90px;">Qtd</th><th style="width:130px;">Unitário</th><th style="width:130px;">Total</th><th>Obs/adicionais</th><th style="width:80px;">Remover</th></tr></thead>
      <tbody>
      <?php foreach ($itens as $idx => $it): ?>
        <?php $obsItem = $itemObsCol ? (string)($it[$itemObsCol] ?? '') : ''; ?>
        <tr>
          <td>
            <input type="hidden" name="item_id[]" value="<?= (int)$it['id'] ?>">
            <?php
              // O item gravado no pedido precisa sempre existir no <select>, mesmo quando
              // o produto foi inativado ou ficou fora do LIMIT da lista de produtos ativos.
              // Sem isso, o navegador seleciona silenciosamente o primeiro produto da lista
              // e uma simples abertura/salvamento da edição pode trocar o item do pedido.
              $pedidoProdutoId = (int)($it['produto_id'] ?? 0);
              $pedidoProdutoNome = trim((string)($it['produto_nome_snapshot'] ?? ''));
              if ($pedidoProdutoNome === '') $pedidoProdutoNome = trim((string)($it['nome'] ?? ''));
              if ($pedidoProdutoNome === '') $pedidoProdutoNome = 'Produto #' . $pedidoProdutoId;
            ?>
            <select name="produto_id[]" class="produto-select" required>
              <option value="<?= $pedidoProdutoId ?>" data-preco="<?= h((string)($it['preco_unit'] ?? 0)) ?>" selected><?= h($pedidoProdutoNome) ?></option>
              <?php foreach ($produtosRows as $p): ?>
                <?php if ((int)$p['id'] === $pedidoProdutoId) continue; ?>
                <option value="<?= (int)$p['id'] ?>" data-preco="<?= h((string)$p['preco']) ?>"><?= h((string)$p['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input class="qtd" name="qtd[]" value="<?= h((string)$it['qtd']) ?>"></td>
          <td><input class="preco" name="preco_unit[]" value="<?= number_format((float)$it['preco_unit'], 2, '.', '') ?>"></td>
          <td class="line-total" style="text-align:right;font-weight:800;"><?= admin_edit_money((float)$it['total']) ?></td>
          <td><input name="item_obs[]" value="<?= h($obsItem) ?>"></td>
          <td style="text-align:center;"><input type="checkbox" name="remove_item[<?= (int)$it['id'] ?>]" value="1"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button type="button" class="btn" id="addItemBtn">+ Adicionar item</button>

    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end;">
      <label>Taxa entrega<input class="money-field" name="taxa_entrega" id="taxaEntrega" value="<?= number_format((float)($pedido['taxa_entrega'] ?? 0), 2, '.', '') ?>"></label>
      <div><b>Subtotal:</b><br><span id="subtotalView"><?= admin_edit_money((float)($pedido['total_produtos'] ?? 0)) ?></span></div>
      <div><b>Total:</b><br><span id="totalView"><?= admin_edit_money((float)($pedido['total'] ?? 0)) ?></span></div>
    </div>
    <div class="actions right">
      <button class="btn btn-primary" type="submit">Salvar alterações</button>
    </div>
  </form>
</div>

<script>
(function(){
  const produtos = <?= $produtosJson ?: '[]' ?>;
  const tbody = document.querySelector('#itemsTable tbody');
  function parseMoney(v){ return parseFloat(String(v || '0').replace(',', '.')) || 0; }
  function br(v){ return v.toLocaleString('pt-BR', {style:'currency', currency:'BRL'}); }
  function optionHtml(selected){
    return produtos.map(p => '<option value="'+p.id+'" data-preco="'+p.preco+'" '+(selected==p.id?'selected':'')+'>'+String(p.nome).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))+'</option>').join('');
  }
  function calc(){
    let subtotal = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const remove = tr.querySelector('input[type="checkbox"]');
      const qtd = parseMoney(tr.querySelector('.qtd')?.value);
      const preco = parseMoney(tr.querySelector('.preco')?.value);
      const total = (remove && remove.checked) ? 0 : qtd * preco;
      subtotal += total;
      const cell = tr.querySelector('.line-total');
      if(cell) cell.textContent = br(total);
    });
    const taxa = parseMoney(document.getElementById('taxaEntrega')?.value);
    document.getElementById('subtotalView').textContent = br(subtotal);
    document.getElementById('totalView').textContent = br(subtotal + taxa);
  }
  tbody.addEventListener('input', calc);
  tbody.addEventListener('change', function(e){
    if(e.target.classList.contains('produto-select')){
      const opt = e.target.selectedOptions[0];
      const preco = opt ? opt.getAttribute('data-preco') : '0';
      const tr = e.target.closest('tr');
      if(tr && tr.querySelector('.preco')) tr.querySelector('.preco').value = Number(preco || 0).toFixed(2);
    }
    calc();
  });
  document.getElementById('taxaEntrega').addEventListener('input', calc);
  document.getElementById('addItemBtn').addEventListener('click', function(){
    const tr = document.createElement('tr');
    const first = produtos[0] || {id:0, preco:0};
    tr.innerHTML = '<td><input type="hidden" name="item_id[]" value="0"><select name="produto_id[]" class="produto-select" required>'+optionHtml(first.id)+'</select></td>'+
      '<td><input class="qtd" name="qtd[]" value="1"></td>'+
      '<td><input class="preco" name="preco_unit[]" value="'+Number(first.preco || 0).toFixed(2)+'"></td>'+
      '<td class="line-total" style="text-align:right;font-weight:800;">R$ 0,00</td>'+
      '<td><input name="item_obs[]" value=""></td>'+
      '<td style="text-align:center;">Novo</td>';
    tbody.appendChild(tr);
    calc();
  });
  calc();
})();
</script>
<?php admin_footer(); ?>
