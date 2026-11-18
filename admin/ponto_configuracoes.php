<?php
declare(strict_types=1);

// Ponto Eletrônico > Configurações da empresa (app_config chave 'ponto').
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_ponto.php';
require_once __DIR__ . '/../config/ponto_assinatura.php';

$cid = ponto_guard($pdo, 'ponto_configuracoes');
ponto_post_handle('ponto_configuracoes.php', function () use ($pdo, $cid) {
  ponto_config_save($pdo, $cid, $_POST);
  return 'Configurações salvas.';
});
$cfg = ponto_config($pdo, $cid);
$ck = static fn(string $k) => !empty($cfg[$k]) ? 'checked' : '';
$plat = function_exists('ponto_plataforma') ? ponto_plataforma() : [];
$ph = static fn(string $k): string => ($plat[$k] ?? '') !== '' ? 'placeholder="Padrão da plataforma: ' . ponto_h($k === 'dev_cnpj' ? ponto_fmt_doc((string)$plat[$k]) : (string)$plat[$k]) . '"' : '';
$devCert = null; $devErr = '';
if (function_exists('ponto_cert_desenvolvedor')) { try { $devCert = ponto_cert_desenvolvedor(); } catch (Throwable $e) { $devErr = $e->getMessage(); } }

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
    <h2>Apuração das horas</h2>
    <div class="pt-grid"><label class="f">Tolerância diária (minutos)<input type="number" name="tolerancia_diaria" min="0" max="10" value="<?= (int)($cfg['tolerancia_diaria'] ?? 10) ?>"></label>
      <label class="f">Início do controle de ponto no Nexa<input type="date" name="inicio_controle" value="<?= ponto_h((string)($cfg['inicio_controle'] ?? '')) ?>"></label></div>
    <p class="pt-hint">Antes do início não há falta (o ponto ainda não era usado). Em branco, vale o dia da primeira marcação registrada.</p>
    <p class="pt-hint">CLT, art. 58, § 1º: atrasos e saídas de até 5 minutos por marcação (a tolerância de cada jornada), somando no máximo 10 minutos no dia, não viram atraso nem hora extra. Passou disso, conta tudo.</p>
  </section>
  <section class="pt-card">
    <h2>Horas extras e banco de horas</h2>
    <div class="pt-grid">
      <label class="f">Hora extra em dia útil (%)<input type="number" name="extra_percentual" min="50" max="300" value="<?= (int)($cfg['extra_percentual'] ?? 50) ?>"></label>
      <label class="f">Hora extra em feriado/folga (%)<input type="number" name="extra_folga_percentual" min="50" max="300" value="<?= (int)($cfg['extra_folga_percentual'] ?? 100) ?>"></label>
      <label class="ck s2"><input type="checkbox" name="extra_folga_100" value="1" <?= !empty($cfg['extra_folga_100']) ? 'checked' : '' ?>> Trabalho no dia de folga (descanso semanal) conta como hora extra de feriado/folga</label>
      <label class="ck s2"><input type="checkbox" name="bh_ativo" value="1" <?= !empty($cfg['bh_ativo']) ? 'checked' : '' ?>> Usar banco de horas (as horas extras vão para o banco em vez de serem pagas)</label>
      <label class="f">Validade do banco (meses)<input type="number" name="bh_validade_meses" min="1" max="12" value="<?= (int)($cfg['bh_validade_meses'] ?? 6) ?>"></label>
      <label class="f">Hora em feriado/folga vale no banco<select name="bh_fator_100"><option value="1">1 hora</option><option value="2" <?= (int)($cfg['bh_fator_100'] ?? 1) === 2 ? 'selected' : '' ?>>2 horas (em dobro)</option></select></label>
      <label class="ck s2"><input type="checkbox" name="bh_debitar_faltas" value="1" <?= !empty($cfg['bh_debitar_faltas']) ? 'checked' : '' ?>> Atrasos e faltas não abonados descontam do banco (desmarcado: vão para desconto na folha)</label>
    </div>
    <p class="pt-hint">CLT, art. 59: banco de horas por acordo individual escrito compensa em até 6 meses; por acordo ou convenção coletiva, em até 12. Horas não compensadas no prazo são pagas como extra. Confira estas regras com o contador e a convenção da categoria.</p>
  </section>
  <?php if (function_exists('ponto_v8_ready') && ponto_v8_ready($pdo)): $am = (string)($cfg['acesso_modo'] ?? 'DESLIGADO'); ?>
  <section class="pt-card">
    <h2>Acesso ao Nexa pelo ponto</h2>
    <p class="pt-sub">Liga o uso do Nexa (PDV, pedidos, cadastros...) à jornada de quem tem usuário ligado a um colaborador do ponto. Quem não está ligado a um colaborador não é afetado.</p>
    <div class="pt-grid">
      <label class="f s2">Quando o funcionário usar o Nexa fora da jornada<select name="acesso_modo"><?php foreach (PONTO_ACESSO_MODOS as $v => $lb): ?><option value="<?= ponto_h($v) ?>" <?= $am === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f">Pode entrar antes do início (minutos)<input type="number" name="acesso_antes_min" min="0" max="240" value="<?= (int)($cfg['acesso_antes_min'] ?? 15) ?>"></label>
      <label class="f">Pode ficar depois do fim (minutos)<input type="number" name="acesso_depois_min" min="0" max="240" value="<?= (int)($cfg['acesso_depois_min'] ?? 15) ?>"></label>
      <label class="ck s2"><input type="checkbox" name="acesso_exigir_marcacao" value="1" <?= $ck('acesso_exigir_marcacao') ?>> Exigir que o ponto de entrada esteja registrado</label>
      <label class="ck s2"><input type="checkbox" name="acesso_bloquear_saida" value="1" <?= $ck('acesso_bloquear_saida') ?>> Depois de registrar a saída (intervalo ou fim do dia), não usar o Nexa até registrar a volta</label>
      <label class="ck s2"><input type="checkbox" name="acesso_admins" value="1" <?= $ck('acesso_admins') ?>> Aplicar também aos administradores da empresa (se estiverem ligados a um colaborador)</label>
    </div>
    <p class="pt-hint">No modo "Bloquear", fora da jornada o Nexa mostra uma tela onde o funcionário bate o ponto ou pede liberação; o gestor libera em Ponto &gt; Acesso ao Nexa. Colaborador marcado como "Acesso ao Nexa sem restrição de horário" no cadastro não é bloqueado. Colaborador sem jornada cadastrada só precisa ter batido o ponto. A verificação é refeita a cada minuto de uso.</p>
  </section>
  <?php endif; ?>
  <?php if (function_exists('ponto_v10_ready') && ponto_v10_ready($pdo)): $fm = (string)($cfg['facial_modo'] ?? 'DESLIGADO'); ?>
  <section class="pt-card">
    <h2>Reconhecimento facial</h2>
    <p class="pt-sub">Vale para o Bater Ponto (celular/computador) e para o terminal da empresa. Os aparelhos Control iD com câmera reconhecem o rosto sozinhos (Ponto &gt; Aparelhos).</p>
    <div class="pt-grid">
      <label class="f s2">Ao bater o ponto<select name="facial_modo"><?php foreach (PONTO_FACIAL_MODOS as $v => $lb): ?><option value="<?= ponto_h($v) ?>" <?= $fm === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f s2">Se a foto falhar ou o rosto não conferir<select name="facial_falha"><?php foreach (PONTO_FACIAL_FALHAS as $v => $lb): ?><option value="<?= ponto_h($v) ?>" <?= ($cfg['facial_falha'] ?? 'SINALIZAR') === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f">Rigor da comparação<select name="facial_rigor"><?php foreach (PONTO_FACIAL_RIGOR_LABEL as $v => $lb): ?><option value="<?= ponto_h($v) ?>" <?= ($cfg['facial_rigor'] ?? 'NORMAL') === $v ? 'selected' : '' ?>><?= ponto_h($lb) ?></option><?php endforeach; ?></select></label>
      <label class="f">Guardar a foto das marcações por (dias)<input type="number" name="facial_foto_dias" min="30" max="365" value="<?= (int)($cfg['facial_foto_dias'] ?? 90) ?>"></label>
    </div>
    <p class="pt-hint">O rosto só é comparado para quem tem o rosto cadastrado em Ponto &gt; Biometria facial (com o termo de consentimento assinado). Quem não tem cadastro continua batendo o ponto normalmente, e a marcação fica como "sem rosto cadastrado". A foto e o resultado aparecem em Ponto &gt; Marcações.</p>
  </section>
  <?php endif; ?>
  <section class="pt-card">
    <h2>Dados do programa de ponto (REP-P)</h2>
    <p class="pt-sub">Esses dados aparecem nos arquivos legais e comprovantes. Campo em branco usa o padrão da plataforma (mostrado em cinza), definido pelo desenvolvedor no servidor.</p>
    <p class="pt-hint" style="margin:0 0 10px">Assinatura digital (ICP-Brasil):
      <?php if ($devCert): ?><span class="pt-badge g">Configurada</span> certificado de <?= ponto_h($devCert['titular']) ?>, válido até <?= ponto_h(date('d/m/Y', (int)$devCert['valido_ate'])) ?>. Detalhes em <a href="ponto_arquivos.php">Arquivos legais</a>.
      <?php elseif ($devErr !== ''): ?><span class="pt-badge r">Com problema</span> <?= ponto_h($devErr) ?>
      <?php else: ?><span class="pt-badge y">Não configurada</span> o administrador da plataforma instala o certificado no servidor.<?php endif; ?></p>
    <div class="pt-grid">
      <label class="f">Nº do registro no INPI<input type="text" name="rep_p_registro_inpi" <?= $ph('inpi') ?> maxlength="40" value="<?= ponto_h($cfg['rep_p_registro_inpi']) ?>"></label>
      <label class="f">Versão do programa<input type="text" name="rep_p_versao" <?= $ph('versao') ?> maxlength="20" value="<?= ponto_h($cfg['rep_p_versao']) ?>"></label>
      <label class="f">Desenvolvedor<input type="text" name="rep_p_desenvolvedor" <?= $ph('dev_nome') ?> maxlength="150" value="<?= ponto_h($cfg['rep_p_desenvolvedor']) ?>"></label>
      <label class="f">CNPJ do desenvolvedor<input type="text" name="rep_p_cnpj_desenvolvedor" <?= $ph('dev_cnpj') ?> inputmode="numeric" maxlength="18" value="<?= ponto_h(ponto_fmt_doc((string)$cfg['rep_p_cnpj_desenvolvedor'])) ?>"></label>
      <label class="f">E-mail do desenvolvedor<input type="email" name="rep_p_email_desenvolvedor" <?= $ph('dev_email') ?> maxlength="50" value="<?= ponto_h((string)($cfg['rep_p_email_desenvolvedor'] ?? '')) ?>"></label>
      <label class="f">CPF do responsável pelos cadastros do ponto<input type="text" name="responsavel_cpf" inputmode="numeric" maxlength="14" value="<?= ponto_h(ponto_fmt_doc((string)($cfg['responsavel_cpf'] ?? ''))) ?>" placeholder="Vai no AFD quando empregador ou empregado é incluído/alterado"></label>
    </div>
  </section>
  <div style="display:flex;justify-content:flex-end"><button class="pt-btn pri" type="submit">Salvar configurações</button></div>
</form>
<?php ponto_end(); ?>
