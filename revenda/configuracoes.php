<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
$msg='';$erro='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $reseller = reseller_save_theme($pdo, RESELLER_COMPANY_ID, $_POST, $_FILES['logo'] ?? []);
    reseller_save_contact_whatsapp($pdo, RESELLER_COMPANY_ID, (string)($_POST['reseller_whatsapp'] ?? ($reseller['reseller_whatsapp'] ?? '')));
    $reseller = reseller_company_by_id($pdo, RESELLER_COMPANY_ID) ?: $reseller;
    $msg='Configurações atualizadas. A identidade visual e o WhatsApp de atendimento já serão usados no cadastro e nos canais da revenda.';
  } catch (Throwable $e) { $erro=$e->getMessage(); }
}
$theme=reseller_theme_config($reseller);
revenda_layout_start('Configurações da revenda','configuracoes',$reseller);
?>
<?php if($msg):?><div class="ok"><?=rev_h($msg)?></div><?php endif;?>
<?php if($erro):?><div class="err"><?=rev_h($erro)?></div><?php endif;?>
<div class="grid2">
  <div class="panel">
    <h2>Identidade visual</h2>
    <p class="muted">Personalize o painel e a página Monte sua proposta. A logomarca e as cores serão aplicadas automaticamente à landing da revenda.</p>
    <form method="post" enctype="multipart/form-data">
      <label>Nome comercial<input name="brand_name" value="<?=rev_h((string)$theme['brand_name'])?>" required></label>
      <label>WhatsApp de atendimento da revenda<input name="reseller_whatsapp" inputmode="tel" value="<?=rev_h((string)($reseller['reseller_whatsapp']??''))?>" placeholder="(31) 99999-9999"></label>
      <p class="muted">Este número identifica o atendimento comercial da revenda, aparece no cadastro dos clientes e também no Monitor de WhatsApp do suporte.</p>
      <div class="grid3">
        <label>Cor principal<input type="color" name="primary_color" value="<?=rev_h((string)$theme['primary'])?>"></label>
        <label>Cor secundária<input type="color" name="secondary_color" value="<?=rev_h((string)$theme['secondary'])?>"></label>
        <label>Cor do texto do menu<input type="color" name="menu_text_color" value="<?=rev_h((string)$theme['menu_text'])?>"></label>
      </div>
      <label>Logomarca<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
      <p class="muted">Formatos aceitos: PNG, JPG ou WEBP, até 3 MB.</p>
      <?php if(!empty($theme['logo'])):?><label style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="remove_logo" value="1"> Remover a logomarca atual</label><?php endif;?>
      <div class="actions"><button class="btn btn-primary" type="submit">Salvar configurações</button><a class="btn btn-ghost" target="_blank" href="/montesuaproposta">Visualizar Monte sua proposta</a></div>
    </form>
  </div>
  <div class="panel">
    <h2>Pré-visualização</h2>
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:15px"><img src="<?=rev_h(reseller_theme_logo_url($reseller))?>" alt="Logo" style="width:78px;height:68px;object-fit:contain;border:1px solid var(--line);border-radius:17px;padding:7px;background:#fff"><div><strong style="font-size:20px"><?=rev_h((string)$theme['brand_name'])?></strong><div class="muted">Painel da revenda</div></div></div>
    <div class="preview-menu"><strong>Menu lateral</strong><div>Dashboard</div><div>Clientes</div><div>Cobranças</div><div>Configurações</div></div>
  </div>
</div>
<div class="panel" style="margin-top:16px"><h2>Integrações da revenda</h2><p class="muted">O WhatsApp e o PagBank são configurados separadamente da conta principal da Nexa. Assim, mensagens e recebimentos podem sair diretamente da revenda.</p><div class="actions"><a class="btn btn-primary" href="/revenda/whatsapp.php">Configurar WhatsApp</a><a class="btn btn-ghost" href="/revenda/pagbank.php">Configurar PagBank</a></div></div>
<?php revenda_layout_end(); ?>
