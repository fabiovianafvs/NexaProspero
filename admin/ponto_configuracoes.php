<?php
declare(strict_types=1);

// Ponto Eletrônico > Configurações da empresa (app_config chave 'ponto').
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';

$cid = ponto_guard($pdo, 'ponto_configuracoes');
ponto_post_handle('ponto_configuracoes.php', function () use ($pdo, $cid) {
  ponto_config_save($pdo, $cid, $_POST);
  return 'Configurações salvas.';
});
$cfg = ponto_config($pdo, $cid);
$ck = static fn(string $k) => !empty($cfg[$k]) ? 'checked' : '';

ponto_start($pdo, 'ponto_configuracoes', 'Configurações do Ponto', 'Regras da empresa para o registro de ponto. Toda alteração fica na Auditoria.');
?>
<form method="post" style="display:grid;gap:12px">
  <?= ponto_csrf_field() ?>
  <section class="pt-card">
    <h2>Geral</h2>
    <div class="pt-grid"><label class="f">Fuso horário padrão<select name="timezone"><?php foreach (ponto_timezones() as $v => $lb): ?><option value="<?= ponto_h($v) ?>" <?= $cfg['timezone'] === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label></div>
    <p class="pt-hint">Cada estabelecimento pode ter seu próprio fuso; este é usado quando não houver.</p>
  </section>
  <section class="pt-card">
    <h2>Registro do ponto</h2>
    <p class="pt-sub">Valem para a tela "Bater Ponto" (o colaborador com o login dele). No terminal da empresa (Aparelhos) a identificação é por matrícula + PIN ou cartão.</p>
    <div style="display:grid;gap:10px">
      <label class="ck"><input type="checkbox" name="permitir_web" value="1" <?= $ck('permitir_web') ?>> Permitir bater o ponto pelo computador (navegador)</label>
      <label class="ck"><input type="checkbox" name="permitir_mobile" value="1" <?= $ck('permitir_mobile') ?>> Permitir bater o ponto pelo celular (só para colaboradores liberados no cadastro)</label>
      <label class="ck"><input type="checkbox" name="permitir_rfid" value="1" <?= $ck('permitir_rfid') ?>> Permitir identificação por cartão RFID</label>
      <label class="ck"><input type="checkbox" name="exigir_localizacao" value="1" <?= $ck('exigir_localizacao') ?>> Exigir a localização do celular/computador para bater o ponto (sem localização, o registro não é feito)</label>
      <label class="ck"><input type="checkbox" name="alertar_fora_area" value="1" <?= $ck('alertar_fora_area') ?>> Sinalizar marcações feitas fora do raio do local de trabalho</label>
    </div>
    <p class="pt-hint">A marcação feita fora do local nunca é bloqueada nem alterada: ela é registrada e fica sinalizada para conferência.</p>
  </section>
  <section class="pt-card">
    <h2>Dados do programa de ponto (REP-P)</h2>
    <p class="pt-sub">Preencha somente depois do registro do programa no INPI. Esses dados aparecerão nos arquivos legais e comprovantes.</p>
    <div class="pt-grid">
      <label class="f">Nº do registro no INPI<input type="text" name="rep_p_registro_inpi" maxlength="40" value="<?= ponto_h($cfg['rep_p_registro_inpi']) ?>"></label>
      <label class="f">Versão do programa<input type="text" name="rep_p_versao" maxlength="20" value="<?= ponto_h($cfg['rep_p_versao']) ?>"></label>
      <label class="f">Desenvolvedor<input type="text" name="rep_p_desenvolvedor" maxlength="150" value="<?= ponto_h($cfg['rep_p_desenvolvedor']) ?>"></label>
      <label class="f">CNPJ do desenvolvedor<input type="text" name="rep_p_cnpj_desenvolvedor" inputmode="numeric" maxlength="18" value="<?= ponto_h(ponto_fmt_doc((string)$cfg['rep_p_cnpj_desenvolvedor'])) ?>"></label>
    </div>
  </section>
  <div style="display:flex;justify-content:flex-end"><button class="pt-btn pri" type="submit">Salvar configurações</button></div>
</form>
<?php ponto_end(); ?>
