<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_billing.php';

$erro = '';
$companies = [];
$plans = [];

/**
 * Mantém a página acessível mesmo quando uma migração automática falha.
 * O detalhe também é gravado no log do PHP para diagnóstico no servidor.
 */
function nexa_pagbank_page_error(Throwable $e): string
{
    error_log('[PagBank nova_cobranca] ' . get_class($e) . ': ' . $e->getMessage()
        . ' em ' . $e->getFile() . ':' . $e->getLine());
    return $e->getMessage();
}

try {
    // Na abertura da tela não executamos toda a migração de cobrança.
    // Isso evita HTTP 500 quando o usuário do banco não possui permissão ALTER/CREATE.
    $companies = $pdo->query('SELECT * FROM companies ORDER BY name')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY name')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $erro = 'Não foi possível carregar empresas e planos: ' . nexa_pagbank_page_error($e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0);
        $method = strtolower(trim((string)($_POST['method'] ?? 'pix')));
        $description = trim((string)($_POST['description'] ?? ''));
        $amountText = trim((string)($_POST['amount'] ?? ''));
        $amountText = str_replace(['.', ','], ['', '.'], $amountText);
        $amount = (float)$amountText;

        if ($companyId <= 0) {
            throw new RuntimeException('Selecione uma empresa.');
        }
        if ($planId <= 0) {
            throw new RuntimeException('Selecione um plano.');
        }
        if ($amount < 1.00) {
            throw new RuntimeException('Informe um valor igual ou superior a R$ 1,00.');
        }
        if (!in_array($method, ['pix', 'card'], true)) {
            throw new RuntimeException('Forma de pagamento inválida.');
        }

        // A criação/atualização das tabelas ocorre somente quando a cobrança é solicitada.
        saas_billing_ensure_schema($pdo);

        $charge = saas_create_manual_pagbank_charge(
            $pdo,
            $companyId,
            $planId,
            $amount,
            $method,
            $description
        );

        header('Location: /saas/cobranca.php?id=' . (int)$charge['id']);
        exit;
    } catch (Throwable $e) {
        $erro = 'Não foi possível gerar a cobrança: ' . nexa_pagbank_page_error($e);
    }
}

saas_layout_start(
    'Nova cobrança',
    'Nova cobrança PagBank',
    'Gere uma cobrança manual para qualquer empresa do SaaS.'
);
?>
<div class="top-actions">
    <a class="btn2 btn2-ghost" href="/saas/cobrancas.php">Voltar</a>
</div>

<?php if ($erro !== ''): ?>
    <div class="alert-err">
        <strong>Erro:</strong> <?= e($erro) ?>
        <div style="margin-top:8px;font-size:12px;opacity:.85">
            O detalhe técnico também foi registrado no log de erros do PHP.
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <h3>Dados da cobrança</h3>

    <?php if (!$companies || !$plans): ?>
        <div class="alert-warn">
            <?php if (!$companies): ?>Nenhuma empresa disponível.<?php endif; ?>
            <?php if (!$companies && !$plans): ?><br><?php endif; ?>
            <?php if (!$plans): ?>Nenhum plano ativo disponível.<?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="form-grid" autocomplete="off">
        <label for="company_id">Empresa</label>
        <select class="field" id="company_id" name="company_id" required>
            <option value="">Selecione</option>
            <?php foreach ($companies as $company): ?>
                <?php
                $companyId = (int)($company['id'] ?? 0);
                $companyName = (string)($company['name'] ?? ('Empresa #' . $companyId));
                $companySlug = (string)($company['slug'] ?? '');
                ?>
                <option value="<?= $companyId ?>" <?= ((int)($_POST['company_id'] ?? 0) === $companyId) ? 'selected' : '' ?>>
                    <?= e($companyName) ?><?= $companySlug !== '' ? ' — ' . e($companySlug) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="plan">Plano de referência</label>
        <select class="field" name="plan_id" id="plan" required>
            <option value="">Selecione</option>
            <?php foreach ($plans as $plan): ?>
                <?php
                $planId = (int)($plan['id'] ?? 0);
                $planName = (string)($plan['name'] ?? ('Plano #' . $planId));
                $planPrice = (float)($plan['price'] ?? 0);
                ?>
                <option value="<?= $planId ?>"
                        data-price="<?= e(number_format($planPrice, 2, '.', '')) ?>"
                    <?= ((int)($_POST['plan_id'] ?? 0) === $planId) ? 'selected' : '' ?>>
                    <?= e($planName) ?> — <?= money($planPrice) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="amount">Valor</label>
        <input class="field" id="amount" name="amount" type="text" inputmode="decimal"
               value="<?= e((string)($_POST['amount'] ?? '')) ?>" placeholder="1,00" required>

        <label for="description">Descrição</label>
        <input class="field" id="description" name="description" maxlength="255"
               value="<?= e((string)($_POST['description'] ?? '')) ?>"
               placeholder="Ex.: Mensalidade Nexa Próspero">

        <label for="method">Forma de pagamento</label>
        <select class="field" id="method" name="method">
            <option value="pix" <?= (($_POST['method'] ?? 'pix') === 'pix') ? 'selected' : '' ?>>Pix</option>
            <option value="card" <?= (($_POST['method'] ?? '') === 'card') ? 'selected' : '' ?>>Cartão pelo checkout PagBank</option>
        </select>

        <div>
            <button class="btn2 btn2-dark" type="submit" <?= (!$companies || !$plans) ? 'disabled' : '' ?>>
                Gerar cobrança
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    var plan = document.getElementById('plan');
    var amount = document.getElementById('amount');
    if (!plan || !amount) return;

    plan.addEventListener('change', function () {
        var option = this.options[this.selectedIndex];
        if (option && option.dataset.price) {
            amount.value = Number(option.dataset.price).toFixed(2).replace('.', ',');
        }
    });
})();
</script>
<?php saas_layout_end(); ?>
