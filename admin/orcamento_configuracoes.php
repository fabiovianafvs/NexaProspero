<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/orcamento_module.php';
require_once __DIR__ . '/_orcamento_ui.php';

$pdo = orc_db();
$companyId = orc_company_id();
orc_require_enabled($pdo, $companyId);
orc_require_schema($pdo);
orc_ensure_defaults($pdo, $companyId);

$ok = trim((string)($_GET['ok'] ?? ''));
$error = '';
$marginSettingsReady = orc_schema_has_column($pdo, 'orcamento_settings', 'minimum_sale_margin_percent')
  && orc_schema_has_column($pdo, 'orcamento_settings', 'margin_warning_band_percent')
  && orc_schema_has_column($pdo, 'orcamento_items', 'cost_snapshot');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_sequence') {
      if (empty($_SESSION['nexa_orc_seq_csrf']) || !hash_equals((string)$_SESSION['nexa_orc_seq_csrf'], (string)($_POST['seq_csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
      $nextNumber = (int)preg_replace('/\D+/', '', (string)($_POST['next_number'] ?? ''));
      orc_sequence_set_next($pdo, $companyId, $nextNumber);
      header('Location: orcamento_configuracoes.php?ok=' . rawurlencode('Numeração atualizada: o próximo orçamento será o nº ' . $nextNumber . '.'));
      exit;
    }
    if ($action !== 'save_settings') throw new RuntimeException('Ação inválida.');

    $defaultReserve = isset($_POST['default_reserve_product']) ? 1 : 0;
    $allowOut = isset($_POST['allow_out_of_stock_reservation']) ? 1 : 0;
    $delivery = isset($_POST['delivery_enabled']) ? 1 : 0;

    if ($marginSettingsReady) {
      $minimumRaw = trim((string)($_POST['minimum_sale_margin_percent'] ?? ''));
      $minimum = $minimumRaw === '' ? null : orc_decimal($minimumRaw);
      $warningBand = max(0.0, orc_decimal($_POST['margin_warning_band_percent'] ?? 5));
      if ($minimum !== null && ($minimum < -100 || $minimum >= 100)) {
        throw new RuntimeException('A margem mínima sobre venda deve ser menor que 100%.');
      }
      if ($warningBand > 50) throw new RuntimeException('A faixa de atenção deve ser de no máximo 50 pontos percentuais.');

      $st = $pdo->prepare('UPDATE orcamento_settings SET default_reserve_product=?,allow_out_of_stock_reservation=?,delivery_enabled=?,minimum_sale_margin_percent=?,margin_warning_band_percent=?,updated_at=NOW() WHERE company_id=?');
      $st->execute([$defaultReserve, $allowOut, $delivery, $minimum, $warningBand, $companyId]);
    } else {
      $st = $pdo->prepare('UPDATE orcamento_settings SET default_reserve_product=?,allow_out_of_stock_reservation=?,delivery_enabled=?,updated_at=NOW() WHERE company_id=?');
      $st->execute([$defaultReserve, $allowOut, $delivery, $companyId]);
    }
    require_once __DIR__ . '/../config/config_store.php';
    require_once __DIR__ . '/../config/orcamento_proposta.php';
    $printModel = (string)($_POST['print_default_model'] ?? 'a4');
    $printCfg = orc_print_config($pdo, $companyId);
    $printCfg['default_model'] = in_array($printModel, ['a4', '72mm', 'proposta'], true) ? $printModel : 'a4';
    $printCfg['proposta_validity_days'] = max(0, min(365, (int)($_POST['proposta_validity_days'] ?? 0)));
    foreach (['proposta_intro' => 300, 'proposta_other' => 600, 'proposta_terms' => 600] as $k => $max) {
      $printCfg[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max, 'UTF-8');
    }
    config_store_set($pdo, $companyId, 'orcamento_print', $printCfg);
    header('Location: orcamento_configuracoes.php?ok=' . rawurlencode('Configurações do Orçamento salvas.'));
    exit;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$settings = orc_settings($pdo, $companyId);
if (empty($_SESSION['nexa_orc_seq_csrf'])) $_SESSION['nexa_orc_seq_csrf'] = bin2hex(random_bytes(20));
$orcSeq = orc_sequence_info($pdo, $companyId);
require_once __DIR__ . '/../config/orcamento_proposta.php';
$orcPrintCfg = orc_print_config($pdo, $companyId);
$orcPrintDefault = $orcPrintCfg['default_model'];
orc_page_start('Configurações do Orçamento', 'orcamento_configuracoes');
?>
<div class="orc-head">
  <h1>Configurações do Orçamento</h1>
  <div class="orc-spacer"></div>
  <a class="orc-btn" href="orcamentos.php">← Orçamentos</a>
</div>
<?php if ($ok !== ''): ?><div class="orc-alert ok"><?= orc_e($ok) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="orc-alert err"><?= orc_e($error) ?></div><?php endif; ?>
<?php if (!$marginSettingsReady): ?><div class="orc-alert err"><b>Margens ainda não preparadas.</b> Aplique a migration <b>V11.45.17</b> em Configurações → Atualizador do Banco para liberar margem mínima, snapshot de custo e painel de negociação.</div><?php endif; ?>

<div class="orc-card">
  <div class="orc-card-head">Comportamento do lançamento</div>
  <div class="orc-card-body">
    <form method="post">
      <input type="hidden" name="action" value="save_settings">
      <div class="orc-grid">
        <div class="orc-c12">
          <label style="display:block;font-weight:800;margin-bottom:4px">Layout padrão de impressão</label>
          <select name="print_default_model" style="max-width:260px"><option value="a4" <?= $orcPrintDefault === 'a4' ? 'selected' : '' ?>>A4</option><option value="72mm" <?= $orcPrintDefault === '72mm' ? 'selected' : '' ?>>Bobina 70 mm</option><option value="proposta" <?= $orcPrintDefault === 'proposta' ? 'selected' : '' ?>>Proposta/Orçamento (A4 com quadros)</option></select>
          <div class="orc-hint">Formato que abre ao clicar em Imprimir. Na tela de impressão ainda dá para trocar entre 70 mm, A4 e Proposta. O PDF enviado por WhatsApp/E-mail segue o modelo escolhido.</div>
        </div>
        <div class="orc-c4">
          <label>Validade da proposta (dias)</label>
          <input type="number" name="proposta_validity_days" min="0" max="365" step="1" value="<?= (int)$orcPrintCfg['proposta_validity_days'] ?>">
          <div class="orc-hint">Modelo Proposta. Use 0 para não mostrar a validade.</div>
        </div>
        <div class="orc-c8">
          <label>Frase de abertura (modelo Proposta)</label>
          <input type="text" name="proposta_intro" maxlength="300" value="<?= orc_e($orcPrintCfg['proposta_intro']) ?>">
        </div>
        <div class="orc-c6">
          <label>Outros dados (modelo Proposta)</label>
          <textarea name="proposta_other" rows="2" maxlength="600"><?= orc_e($orcPrintCfg['proposta_other']) ?></textarea>
          <div class="orc-hint">Aparece abaixo do Representante (vendedor). Ex.: * Todos os impostos inclusos.</div>
        </div>
        <div class="orc-c6">
          <label>Texto de aceite (modelo Proposta)</label>
          <textarea name="proposta_terms" rows="2" maxlength="600"><?= orc_e($orcPrintCfg['proposta_terms']) ?></textarea>
          <div class="orc-hint">Aparece acima das assinaturas. Os dados da empresa (razão social, CNPJ, I.E., endereço) podem ser personalizados em Configurações fiscais &gt; Duplicata.</div>
        </div>
        <div class="orc-c12">
          <label class="orc-check"><input type="checkbox" name="default_reserve_product" value="1" <?= !empty($settings['default_reserve_product']) ? 'checked' : '' ?>> Marcar “Reservar” por padrão ao adicionar produtos</label>
          <div class="orc-hint">O usuário ainda poderá desmarcar a reserva em cada produto do orçamento.</div>
        </div>
        <div class="orc-c12">
          <label class="orc-check"><input type="checkbox" name="allow_out_of_stock_reservation" value="1" <?= !empty($settings['allow_out_of_stock_reservation']) ? 'checked' : '' ?>> Permitir reserva acima do saldo disponível</label>
          <div class="orc-hint">Quando desabilitado, o sistema bloqueia a reserva quando estoque atual menos estoque reservado não for suficiente.</div>
        </div>
        <div class="orc-c12">
          <label class="orc-check"><input type="checkbox" name="delivery_enabled" value="1" <?= !empty($settings['delivery_enabled']) ? 'checked' : '' ?>> Habilitar etapa de Entrega no Orçamento</label>
          <div class="orc-hint">Ao habilitar, o lançamento recebe uma 4ª etapa com data, hora, transportadora e endereço de entrega. O endereço principal do cliente é sugerido automaticamente e pode ser alterado.</div>
        </div>
        <?php if ($marginSettingsReady): ?>
        <div class="orc-c4">
          <label>Margem mínima sobre a venda (%)</label>
          <input type="number" step="0.01" min="-100" max="99.99" name="minimum_sale_margin_percent" value="<?= isset($settings['minimum_sale_margin_percent']) && $settings['minimum_sale_margin_percent'] !== null ? orc_e(number_format((float)$settings['minimum_sale_margin_percent'],2,'.','')) : '' ?>" placeholder="Ex.: 30,00">
          <div class="orc-hint">Deixe em branco para não aplicar limite visual. A margem sobre venda é calculada por (Venda − Custo) ÷ Venda.</div>
        </div>
        <div class="orc-c4">
          <label>Faixa amarela acima do mínimo (p.p.)</label>
          <input type="number" step="0.01" min="0" max="50" name="margin_warning_band_percent" value="<?= orc_e(number_format((float)($settings['margin_warning_band_percent'] ?? 5),2,'.','')) ?>">
          <div class="orc-hint">Ex.: mínimo 30% + faixa 5 p.p. → vermelho até 30%, amarelo de 30,01% a 35% e verde acima de 35%.</div>
        </div>
        <div class="orc-c4">
          <label>Regra de negociação</label>
          <div class="orc-section-note" style="margin:0">O painel calcula a margem total do orçamento e quanto ainda pode ser negociado sem ultrapassar a margem mínima configurada.</div>
        </div>
        <?php endif; ?>
      </div>
      <div class="orc-actions"><button class="orc-btn primary" type="submit">Salvar configurações</button></div>
    </form>
  </div>
</div>
<div class="orc-card">
  <div class="orc-card-head">Numeração do orçamento</div>
  <div class="orc-card-body">
    <form method="post" onsubmit="return confirm('Alterar a numeração? Os próximos orçamentos seguirão a partir do número informado.')">
      <input type="hidden" name="action" value="save_sequence">
      <input type="hidden" name="seq_csrf" value="<?= orc_e((string)$_SESSION['nexa_orc_seq_csrf']) ?>">
      <div class="orc-grid">
        <div class="orc-c4">
          <label>Próximo número do orçamento</label>
          <input type="number" name="next_number" min="<?= (int)$orcSeq['max_used'] + 1 ?>" max="999999999" step="1" required value="<?= (int)$orcSeq['next'] ?>">
          <div class="orc-hint">Use para continuar a sequência de outro sistema. Maior orçamento já lançado: nº <?= (int)$orcSeq['max_used'] ?>.</div>
        </div>
      </div>
      <div class="orc-actions"><button class="orc-btn primary" type="submit">Salvar numeração</button></div>
    </form>
  </div>
</div>

<div class="orc-card">
  <div class="orc-card-head">Mensagens para envio do orçamento</div>
  <div class="orc-card-body">
    <div class="orc-section-note">Cadastre o texto padrão que acompanhará o PDF do orçamento. O mesmo cadastro de modelos do Nexa é usado para WhatsApp e E-mail.</div>
    <div class="orc-actions" style="justify-content:flex-start"><a class="orc-btn success" href="whatsapp_modelos.php?new=1&channel=whatsapp&context=orcamento">Mensagem WhatsApp</a><a class="orc-btn" href="whatsapp_modelos.php?new=1&channel=email&context=orcamento">Mensagem E-mail</a><a class="orc-btn" href="whatsapp_modelos.php">Ver todos os modelos</a></div>
  </div>
</div>

<div class="orc-card">
  <div class="orc-card-head">Classificações de pessoas</div>
  <div class="orc-card-body">
    <div class="orc-section-note">O cadastro de Pessoas agora possui também as classificações <b>Profissional</b> e <b>Transportadora</b>. O orçamento usa essas marcações para filtrar cada seleção.</div>
    <div class="orc-actions" style="justify-content:flex-start"><a class="orc-btn" href="financeiro_pessoas.php">Abrir cadastro de Pessoas</a></div>
  </div>
</div>
<?php orc_page_end(); ?>
