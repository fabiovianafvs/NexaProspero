<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/producao_module.php';
require_once __DIR__ . '/_producao_ui.php';

$companyId=(int)COMPANY_ID;
if(!production_enabled($pdo,$companyId)) saas_require_company_module($pdo,$companyId,'ordem_producao','Configurações de O.P.');
if(!production_ensure_schema($pdo)) throw new RuntimeException('Não foi possível preparar a estrutura da Ordem de Produção.');
$error='';$ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    production_save_settings($pdo,$companyId,!empty($_POST['order_by_sector']));
    $ok='Configurações da Ordem de Produção salvas com sucesso.';
  }catch(Throwable $e){$error=$e->getMessage();}
}
$settings=production_settings($pdo,$companyId);

production_page_start('Configurações de O.P.','producao_configuracoes');
production_page_header('Configurações de O.P.','Defina como o estoque será movimentado quando uma nova Ordem de Produção for gerada.');
if($error!=='')echo '<div class="msg err">'.production_h($error).'</div>';
if($ok!=='')echo '<div class="msg ok">'.production_h($ok).'</div>';
?>
<form method="post">
  <div class="pr-card">
    <div class="pr-card-head"><div><h2>Fluxo da Ordem de Produção</h2><div class="pr-muted">A configuração vale para as novas O.P.s. Cada ordem grava o modo utilizado no momento da geração.</div></div></div>
    <label class="pr-check">
      <input type="checkbox" name="order_by_sector" value="1" <?= !empty($settings['order_by_sector'])?'checked':'' ?>>
      <span><strong>Ordem por setor</strong><span>Ao gerar a O.P., as matérias-primas são baixadas e a ordem fica <b>Em processo</b>. Os produtos acabados entram no estoque somente quando a produção for finalizada no Monitor de Produção.</span></span>
    </label>
    <div class="pr-info" style="margin-top:12px;margin-bottom:0"><b>Sem Ordem por setor:</b> ao gerar a O.P., o Nexa baixa as matérias-primas, lança os produtos acabados no estoque e finaliza a ordem automaticamente.</div>
    <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
  </div>
</form>
<?php production_page_end(); ?>
