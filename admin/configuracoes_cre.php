<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = (int)COMPANY_ID;
$err = '';
// V11.63: a integração C/R -> caixa passa a ser configurada somente nesta tela.
// Empresa selecionada pelo F11 não pode alterar a empresa de login.
$creCashCross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $cid;
$creCashCanConfigure = !$creCashCross
  && in_array((string)auth_role(), ['platform_admin','company_admin'], true);
$creCashSchemaReady = function_exists('nexa_cash_cr_schema_ready') && nexa_cash_cr_schema_ready($pdo);
$creCashCsrf = '';
if (session_status() === PHP_SESSION_ACTIVE) {
  if (empty($_SESSION['nexa_cash_cr_csrf'])) {
    $_SESSION['nexa_cash_cr_csrf'] = bin2hex(random_bytes(20));
  }
  $creCashCsrf = (string)$_SESSION['nexa_cash_cr_csrf'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'save_cre');
    if ($action === 'cash_cr_settings') {
      if (!$creCashCanConfigure) throw new RuntimeException('Somente administradores da empresa de login podem alterar a integração do C/R com o Caixa.');
      if (!$creCashSchemaReady) throw new RuntimeException('Atualize a estrutura do banco pelo Atualizador antes de configurar a integração.');
      if ($creCashCsrf === '' || !hash_equals($creCashCsrf, (string)($_POST['cash_cr_csrf'] ?? ''))) {
        throw new RuntimeException('Sessão expirada. Atualize a página antes de alterar a integração.');
      }
      $value = (string)($_POST['enabled'] ?? '');
      if (!in_array($value, ['0','1'], true)) throw new RuntimeException('Opção de integração inválida.');
      $enabled = $value === '1';
      $userId = (int)(auth_user_id() ?? 0);
      nexa_cash_cr_set_enabled($pdo, $cid, $userId, $enabled);
      finance_redirect('configuracoes_cre.php?ok=' . rawurlencode($enabled
        ? 'Recebimentos do Contas a Receber integrados ao Caixa.'
        : 'Integração dos recebimentos do Contas a Receber com o Caixa desabilitada.'));
    } elseif ($action === 'save_cre') {
      // Salva somente as configurações CRE originais, sem alterar o estado da integração do Caixa.
      finance_save_settings($pdo, $cid, $_POST);
      finance_redirect('configuracoes_cre.php?ok=' . rawurlencode('Configurações CRE salvas com sucesso.'));
    } else {
      throw new RuntimeException('Ação de configuração inválida.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
$creCashEnabled = $creCashSchemaReady && nexa_cash_cr_enabled($pdo, $cid);
$settings = finance_settings($pdo, $cid);

finance_admin_start('Configurações CRE', 'configuracoes_cre');
finance_page_header('Configurações CRE', 'Regras do crediário, vencimentos, juros, multa, desconto e comportamento das baixas.');
finance_flash();
if ($err !== '') echo '<div class="msg err">' . finance_h($err) . '</div>';
?>
<style>
.cre-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.cre-option{border:1px solid #dbe4f0;border-radius:18px;background:#f8fbff;padding:14px;min-width:0}.cre-option label{display:block;font-weight:900;color:#0f172a;font-size:13px;margin-bottom:6px}.cre-option input[type="number"],.cre-option input[type="text"]{width:100%}.cre-option small{display:block;color:#64748b;line-height:1.4;margin-top:6px}.cre-check{display:flex!important;gap:10px;align-items:flex-start!important;margin:0!important}.cre-check input{width:auto!important;margin-top:3px}.cre-check span{display:block}.cre-callout{border:1px solid #bfdbfe;background:#eff6ff;border-radius:18px;padding:14px;color:#1e3a8a;line-height:1.45}.cre-preview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}.cre-preview div{border:1px solid #dbe4f0;border-radius:14px;background:#fff;padding:10px;text-align:center}.cre-preview strong{display:block;color:var(--admin-primary);font-size:13px}.cre-preview span{display:block;color:#475569;margin-top:4px;font-weight:850}.cre-section-note{margin-top:-4px;margin-bottom:12px}.cre-disabled{opacity:.6}.finance-check{display:flex;gap:10px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:16px;background:#f8fbff;padding:12px}.finance-check input{width:auto;margin-top:3px}.finance-check span{font-weight:850;color:#334155}@media(max-width:980px){.cre-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cre-preview{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.cre-grid,.cre-preview{grid-template-columns:1fr}}
</style>

<form method="post" class="finance-form" id="creForm">
  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Crediário</h2><div class="finance-muted">Ative para que estas regras prevaleçam sobre os padrões antigos das formas de pagamento a prazo.</div></div></div>
    <label class="finance-check"><input type="checkbox" name="cre_rules_enabled" id="creEnabled" value="1" <?= !empty($settings['cre_rules_enabled']) ? 'checked' : '' ?>><span><strong>Usar as regras CRE abaixo nas vendas a prazo</strong><br><small>Enquanto estiver desmarcado, o Nexa preserva o comportamento já existente das formas de pagamento.</small></span></label>
  </div>

  <div class="finance-card" id="creRulesCard">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Valores sobre as parcelas</h2><div class="finance-muted">Regras usadas no cálculo do crediário e na baixa de contas a receber.</div></div></div>
    <div class="cre-grid">
      <div class="cre-option"><label>Juros diários para contas vencidas (%)</label><input type="text" inputmode="decimal" name="cre_daily_interest_percent" value="<?= finance_h(number_format((float)$settings['cre_daily_interest_percent'], 4, ',', '')) ?>"><small>Juros simples por dia de atraso. Ex.: 0,333% ao dia.</small></div>
      <div class="cre-option"><label>Multa por contas vencidas (%)</label><input type="text" inputmode="decimal" name="cre_late_fee_percent" value="<?= finance_h(number_format((float)$settings['cre_late_fee_percent'], 4, ',', '')) ?>"><small>A multa é cobrada uma única vez por parcela, na primeira baixa após o vencimento.</small></div>
      <div class="cre-option"><label>Desconto até o vencimento (%)</label><input type="text" inputmode="decimal" name="cre_discount_before_due_percent" value="<?= finance_h(number_format((float)$settings['cre_discount_before_due_percent'], 4, ',', '')) ?>"><small>Aplicado quando o recebimento ocorre até a data de vencimento.</small></div>
      <div class="cre-option"><label>Carência / dias</label><input type="number" min="0" max="365" name="cre_grace_days" value="<?= (int)$settings['cre_grace_days'] ?>"><small>Dentro da carência não há juros. Ao ultrapassá-la, os juros são calculados por todo o período em atraso.</small></div>
      <div class="cre-option"><label>Dias para o primeiro vencimento</label><input type="number" min="0" max="365" name="cre_first_due_days" id="creFirstDueDays" value="<?= (int)$settings['cre_first_due_days'] ?>"><small>Ex.: 30 gera o primeiro vencimento 30 dias após a emissão, salvo quando houver dia fixo configurado.</small></div>
      <div class="cre-option"><label>Periodicidade das parcelas / dias</label><input type="number" min="1" max="365" name="cre_installment_period_days" id="crePeriodDays" value="<?= (int)$settings['cre_installment_period_days'] ?>"><small>Ex.: 30 gera as próximas parcelas a cada 30 dias quando o modo “manter dia do mês” estiver desligado.</small></div>
    </div>
  </div>

  <div class="finance-card" id="creCalendarCard">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Calendário dos vencimentos</h2><div class="finance-muted">Escolha entre periodicidade em dias ou vencimentos mensais no mesmo dia.</div></div></div>
    <div class="cre-grid">
      <div class="cre-option span-2"><label class="cre-check"><input type="checkbox" name="cre_keep_month_due_day" id="creKeepDay" value="1" <?= !empty($settings['cre_keep_month_due_day']) ? 'checked' : '' ?>><span><strong>Manter o dia de vencimento do mês</strong><small>As parcelas seguintes ficam no mesmo dia de cada mês. Ex.: dia 10 → 10/09, 10/10, 10/11...</small></span></label></div>
      <div class="cre-option" id="fixedDueWrap"><label>Dia fixo de vencimento (opcional)</label><input type="number" min="1" max="31" name="cre_fixed_due_day" id="creFixedDueDay" value="<?= $settings['cre_fixed_due_day'] ? (int)$settings['cre_fixed_due_day'] : '' ?>" placeholder="Ex.: 10"><small>Se preenchido, prevalece sobre “Dias para o primeiro vencimento”. Compra em 26/08 com dia 10 → 10/09, 10/10...</small></div>
    </div>
    <div class="cre-callout" style="margin-top:14px"><strong>Prévia do calendário</strong><div class="finance-muted">Exemplo com 4 parcelas a partir de hoje. A prévia muda conforme as configurações acima.</div><div class="cre-preview" id="crePreview"></div></div>
  </div>

  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Configurações de baixa</h2><div class="finance-muted">Vale para “Baixar selecionados” no Contas a Receber. O Contas a Pagar tem a sua própria opção em <a href="configuracoes_cpa.php">Configurações CPA</a>.</div></div></div>
    <label class="finance-check"><input type="checkbox" name="settle_titles_individually" value="1" <?= !empty($settings['settle_titles_individually']) ? 'checked' : '' ?>><span><strong>Mostrar/lançar parcelas quitadas separadamente no movimento financeiro</strong><br><small>Desmarcado: a baixa múltipla gera um único movimento com o valor total e identifica as parcelas nas observações.</small></span></label>
  </div>

  <div class="finance-form-actions"><a class="btn" href="financeiro.php">Voltar</a><button class="btn btn-primary" type="submit">Salvar configurações CRE</button></div>
</form>

<div class="finance-card" style="margin-top:14px">
  <div class="finance-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <h2 class="finance-subtitle" style="margin:0">Recebimentos do Contas a Receber no Caixa</h2>
    <span class="<?= $creCashEnabled ? 'finance-badge green' : 'finance-badge red' ?>"><?= $creCashEnabled ? 'Habilitado' : 'Desabilitado' ?></span>
  </div>
  <?php if (!$creCashSchemaReady): ?>
    <div class="msg err">Integração indisponível: atualização do banco pendente.</div>
  <?php elseif ($creCashCanConfigure): ?>
    <form method="post" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:10px">
      <input type="hidden" name="action" value="cash_cr_settings">
      <input type="hidden" name="cash_cr_csrf" value="<?= finance_h($creCashCsrf) ?>">
      <input type="hidden" name="enabled" value="<?= $creCashEnabled ? '0' : '1' ?>">
      <button type="submit" class="btn <?= $creCashEnabled ? '' : 'btn-primary' ?>"
        onclick="return confirm('Confirmar <?= $creCashEnabled ? 'desativação' : 'ativação' ?> da integração de recebimentos do Contas a Receber com o Caixa?')">
        <?= $creCashEnabled ? 'Desabilitar integração' : 'Habilitar integração' ?>
      </button>
    </form>
  <?php endif; ?>
</div>

<script>
(()=>{
  const enabled=document.getElementById('creEnabled'), keep=document.getElementById('creKeepDay'), fixed=document.getElementById('creFixedDueDay'), first=document.getElementById('creFirstDueDays'), period=document.getElementById('crePeriodDays'), preview=document.getElementById('crePreview'), fixedWrap=document.getElementById('fixedDueWrap');
  const iso=d=>{const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');return `${y}-${m}-${day}`};
  const br=s=>{const [y,m,d]=s.split('-');return `${d}/${m}/${y}`};
  const addDays=(base,days)=>{const d=new Date(base+'T12:00:00');d.setDate(d.getDate()+Number(days||0));return iso(d)};
  const addMonthKeep=(base,months,day)=>{const d=new Date(base+'T12:00:00'),x=new Date(d.getFullYear(),d.getMonth()+months,1,12);const last=new Date(x.getFullYear(),x.getMonth()+1,0).getDate();x.setDate(Math.min(Math.max(1,Number(day)),last));return iso(x)};
  const nextFixed=(base,day)=>{const b=new Date(base+'T12:00:00');for(let i=0;i<24;i++){const d=new Date(b.getFullYear(),b.getMonth()+i,1,12),last=new Date(d.getFullYear(),d.getMonth()+1,0).getDate();d.setDate(Math.min(day,last));if(d>b)return iso(d)}return addDays(base,30)};
  function render(){const active=!!enabled.checked;document.getElementById('creRulesCard').classList.toggle('cre-disabled',!active);document.getElementById('creCalendarCard').classList.toggle('cre-disabled',!active);fixedWrap.style.display=keep.checked?'block':'none';const today=iso(new Date()),fd=Math.max(0,Number(first.value||30)),pd=Math.max(1,Number(period.value||30)),fx=Math.max(0,Number(fixed.value||0));let firstDue=(keep.checked&&fx>=1)?nextFixed(today,fx):addDays(today,fd),day=fx>=1?fx:Number(firstDue.slice(8,10));const dates=[];for(let i=0;i<4;i++)dates.push(i===0?firstDue:(keep.checked?addMonthKeep(firstDue,i,day):addDays(firstDue,pd*i)));preview.innerHTML=dates.map((d,i)=>`<div><strong>${i+1}ª parcela</strong><span>${br(d)}</span></div>`).join('')}
  [enabled,keep,fixed,first,period].forEach(el=>el&&el.addEventListener('input',render));render();
})();
</script>
<?php finance_admin_end(); ?>
