<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/external_api.php';

$msg = '';
$erro = '';
$cfg = external_api_load_config($pdo, COMPANY_ID);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'save');
    $token = trim((string)($_POST['token'] ?? $cfg['token'] ?? ''));

    if ($action === 'generate') {
      $token = external_api_generate_token();
    }

    $cfg = [
      'enabled' => isset($_POST['enabled']),
      'token' => $token,
      'allow_whatsapp' => isset($_POST['allow_whatsapp']),
      'allow_products' => isset($_POST['allow_products']),
      'allow_orders' => isset($_POST['allow_orders']),
    ];

    external_api_save_config($pdo, COMPANY_ID, $cfg);
    $cfg = external_api_load_config($pdo, COMPANY_ID);
    $msg = $action === 'generate' ? 'Novo token gerado e salvo.' : 'Configurações da API salvas.';
  } catch (Throwable $e) {
    $erro = 'Falha ao salvar: ' . $e->getMessage();
  }
}

$host = current_scheme() . '://' . current_host_name();
$baseApi = $host . '/api';
$tokenPreview = (string)($cfg['token'] ?? '');

admin_topbar('Central - API Próspero Plus', 'api');
?>

<style>
.api-page{max-width:1180px;margin:14px auto 0;display:grid;grid-template-columns:390px minmax(0,1fr);gap:18px;align-items:start}
.api-card{min-width:0;background:#fff;border:1px solid #dbeafe;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(11,31,91,.07)}
.api-title{margin:0 0 6px;color:#0b1f5b;font-size:24px;letter-spacing:-.03em;line-height:1.15}
.api-muted{color:#64748b;line-height:1.55;font-size:14px;overflow-wrap:anywhere}
.api-form{display:grid;gap:12px;margin-top:14px}
.api-form label{display:block;font-weight:850;color:#0f172a;margin-bottom:6px}
.api-form .field{width:100%;max-width:100%;min-width:0}
.api-check{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fbff}
.api-check input{margin-top:4px}
.api-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.api-code{font-family:ui-monospace,Consolas,monospace;background:#0b1f5b;color:#eaf7ff;border-radius:16px;padding:14px;white-space:pre-wrap;overflow:auto;font-size:12px;line-height:1.55}
.api-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-size:13px;font-weight:850;background:#ecfdf5;color:#047857}
.api-pill.off{background:#fef2f2;color:#b91c1c}
.api-grid{display:grid;gap:14px}
@media(max-width:980px){.api-page{grid-template-columns:1fr}}
</style>

<?php if ($msg): ?><div class="msg ok" style="margin-top:12px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="msg err" style="margin-top:12px;"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="api-page">
  <div class="api-card">
    <h2 class="api-title">API externa</h2>
    <div class="api-muted">
      Use esta API para integrar o <b>Próspero Plus</b> com a Nexa Próspero: envio de WhatsApp, cadastro/sincronização de produtos e consulta/criação de pedidos.
    </div>

    <div style="margin-top:12px;">
      <?php if (!empty($cfg['enabled'])): ?>
        <span class="api-pill">API ativa</span>
      <?php else: ?>
        <span class="api-pill off">API desativada</span>
      <?php endif; ?>
    </div>

    <form method="post" class="api-form">
      <label class="api-check">
        <input type="checkbox" name="enabled" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
        <span><b>Ativar API externa</b><br><span class="api-muted">Desative quando não estiver usando a integração.</span></span>
      </label>

      <label>Token da API</label>
      <input class="field" name="token" value="<?= htmlspecialchars($tokenPreview) ?>" placeholder="Clique em gerar token">
      <div class="api-muted">Envie este token no cabeçalho <b>Authorization: Bearer SEU_TOKEN</b> ou <b>X-API-Token</b>.</div>

      <label class="api-check">
        <input type="checkbox" name="allow_whatsapp" <?= !empty($cfg['allow_whatsapp']) ? 'checked' : '' ?>>
        <span><b>Permitir envio de WhatsApp</b><br><span class="api-muted">Permite ao Próspero Plus enviar mensagem pela conexão QR Code do Admin.</span></span>
      </label>

      <label class="api-check">
        <input type="checkbox" name="allow_products" <?= !empty($cfg['allow_products']) ? 'checked' : '' ?>>
        <span><b>Permitir produtos</b><br><span class="api-muted">Permite listar, criar, atualizar e desativar produtos.</span></span>
      </label>

      <label class="api-check">
        <input type="checkbox" name="allow_orders" <?= !empty($cfg['allow_orders']) ? 'checked' : '' ?>>
        <span><b>Permitir pedidos</b><br><span class="api-muted">Permite consultar, criar pedidos e atualizar status.</span></span>
      </label>

      <div class="api-actions">
        <button class="btn btn-primary" type="submit" name="action" value="save">Salvar</button>
        <button class="btn" type="submit" name="action" value="generate" onclick="return confirm('Gerar um novo token? O token anterior deixará de funcionar.');">Gerar novo token</button>
      </div>
    </form>
  </div>

  <div class="api-grid">
    <div class="api-card">
      <h2 class="api-title">Endpoints</h2>
      <div class="api-muted">Base da API para esta empresa:</div>
      <div class="api-code"><?= htmlspecialchars($baseApi) ?></div>

      <div class="api-muted" style="margin-top:12px;">Cabeçalhos recomendados:</div>
      <div class="api-code">Authorization: Bearer <?= htmlspecialchars($tokenPreview ?: 'SEU_TOKEN') . "\n" ?>Content-Type: application/json</div>
    </div>

    <div class="api-card">
      <h2 class="api-title">Enviar WhatsApp pelo Próspero Plus</h2>
      <div class="api-code">POST <?= htmlspecialchars($baseApi) ?>/whatsapp.php

{
  "to": "31999999999",
  "message": "Olá! Mensagem enviada pelo Próspero Plus."
}</div>
    </div>

    <div class="api-card">
      <h2 class="api-title">Produtos</h2>
      <div class="api-code">GET <?= htmlspecialchars($baseApi) ?>/produtos.php?limit=100

POST <?= htmlspecialchars($baseApi) ?>/produtos.php
{
  "codigo_firebird": "1234",
  "nome": "Produto Exemplo",
  "descricao": "Descrição do produto",
  "categoria": "Categoria",
  "preco": 19.90,
  "ativo": true
}

DELETE <?= htmlspecialchars($baseApi) ?>/produtos.php?codigo_firebird=1234</div>
    </div>

    <div class="api-card">
      <h2 class="api-title">Pedidos</h2>
      <div class="api-code">GET <?= htmlspecialchars($baseApi) ?>/pedidos.php?status=aberto&limit=50

GET <?= htmlspecialchars($baseApi) ?>/pedidos.php?id=10

PATCH <?= htmlspecialchars($baseApi) ?>/pedidos.php
{
  "id": 10,
  "status": "confirmado"
}

POST <?= htmlspecialchars($baseApi) ?>/pedidos.php
{
  "cliente": {
    "nome": "Cliente Exemplo",
    "whatsapp": "31999999999"
  },
  "tipo_entrega": "retirada",
  "forma_pagamento": "pix",
  "items": [
    {
      "codigo_firebird": "1234",
      "qtd": 1,
      "preco_unit": 19.90,
      "complemento": "Sem cebola"
    }
  ]
}</div>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
