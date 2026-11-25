<?php
declare(strict_types=1);
/**
 * Nexa Próspero — Área de Trabalho
 * Página inicial universal após login. Visual de boas-vindas em tela cheia,
 * sem alterar menus, permissões ou páginas dos outros módulos.
 */
require __DIR__ . '/_bootstrap.php';
// Respeitar a configuração da empresa: Configurações > Habilita novo Menu.
// admin_topbar() decide entre menu lateral e menu superior em _chrome.php.
// Não forçar a barra lateral na página de início.
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/saas_trial_cycle.php';
require_once __DIR__ . '/../config/saas_user_notice.php';
$workspaceName='Usuário';
$workspaceCompany=(string)($company['name']??'Empresa');
try {
  $st=$pdo->prepare('SELECT name FROM users WHERE id=? AND company_id=? AND is_active=1 LIMIT 1');
  $st->execute([(int)(auth_user_id()??0),(int)(function_exists('auth_login_company_id') ? (auth_login_company_id() ?? COMPANY_ID) : COMPANY_ID)]);
  $workspaceName=(string)($st->fetchColumn()?:$workspaceName);
} catch(Throwable $e) {error_log('[Nexa][Workspace] '.$e->getMessage());}
$workspaceCycle=[];
if (nexa_user_contract_notice_enabled($pdo,(int)COMPANY_ID,(int)(auth_user_id()??0))) {
  try {
    $sub=tenant_latest_subscription($pdo,(int)COMPANY_ID);
    if ($sub) $workspaceCycle=nexa_saas_cycle_notice_for_contract($pdo,(int)COMPANY_ID,nexa_saas_cycle_state($sub));
  } catch(Throwable $e) {error_log('[Nexa][Workspace aviso] '.$e->getMessage());}
}
$workspaceWarn=in_array((string)($workspaceCycle['phase']??''),['warning','last_day','grace','blocked'],true);
$workspaceLate=in_array((string)($workspaceCycle['phase']??''),['grace','blocked'],true);
// Primeira exibição do aviso: já gera a cobrança da renovação (uma vez por sessão/dia).
if ($workspaceWarn) {
  try { require_once __DIR__ . '/../config/saas_renewal_portal.php'; saas_renewal_auto_charge($pdo,(int)COMPANY_ID,auth_user_id()); }
  catch (Throwable $e) { error_log('[Nexa][Workspace cobrança automática] '.$e->getMessage()); }
}
// Ativar/Desativar os catálogos públicos (botões ao lado de "Minhas faturas").
require_once __DIR__ . '/../config/catalogo_status.php';
$catalogoPodeAlterar = false;
try {
  $catalogoOutraEmpresa = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== (int)COMPANY_ID;
  $catalogoPodeAlterar = !$catalogoOutraEmpresa && access_has_permission($pdo, (int)COMPANY_ID, 'catalogo_status');
} catch (Throwable $e) { error_log('[Nexa][Workspace catálogos] ' . $e->getMessage()); }
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if ($catalogoPodeAlterar && empty($_SESSION['catalogo_status_csrf'])) $_SESSION['catalogo_status_csrf'] = bin2hex(random_bytes(24));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['catalogo_tipo'])) {
  $tipo = (string)$_POST['catalogo_tipo'];
  $ativar = (string)($_POST['catalogo_ativar'] ?? '') === '1';
  if (!$catalogoPodeAlterar) {
    $_SESSION['catalogo_status_flash'] = ['err', 'Seu usuário não tem permissão para ativar/desativar os catálogos.'];
  } elseif (!hash_equals((string)($_SESSION['catalogo_status_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['catalogo_status_flash'] = ['err', 'A página expirou. Tente de novo.'];
  } elseif (!isset(catalogo_status_tipos()[$tipo])) {
    $_SESSION['catalogo_status_flash'] = ['err', 'Catálogo inválido.'];
  } else {
    try {
      catalogo_status_set($pdo, (int)COMPANY_ID, $tipo, $ativar, (int)(auth_user_id() ?? 0));
      $_SESSION['catalogo_status_flash'] = ['ok', catalogo_status_tipos()[$tipo] . ($ativar ? ' ativado: os clientes já podem acessar.' : ' desativado: os clientes verão o aviso "Catálogo em manutenção".')];
    } catch (Throwable $e) {
      error_log('[Nexa][Workspace catálogos] ' . $e->getMessage());
      $_SESSION['catalogo_status_flash'] = ['err', 'Não foi possível alterar o catálogo. Tente de novo.'];
    }
  }
  header('Location: area_trabalho.php');
  exit;
}
$catalogoStatus = $catalogoPodeAlterar ? catalogo_status_get($pdo, (int)COMPANY_ID) : [];
$catalogoFlash = $_SESSION['catalogo_status_flash'] ?? null;
unset($_SESSION['catalogo_status_flash']);
admin_topbar('Nexa Próspero', 'inicio');
?>
<style>
  /*
   * HOME: a imagem passa a compor a própria área útil, e não um cartão.
   * O espaço é calculado pelo layout flex: altura disponível = janela - topbar.
   * Evita margens, scroll residual e cálculos de zoom/altura fixos.
   */
  body.nexa-workspace-home {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100vh;
    height: 100dvh;
    min-height: 0;
    margin: 0 !important;
    overflow: hidden;
    background: #fff !important;
  }
  body.nexa-workspace-home > .topbar {
    flex: 0 0 auto;
  }
  /*
   * Em notebooks, admin.css (v2.7) aplica html { zoom: .80 } entre
   * 1024–1500px de largura e até 820px de altura. O CSS zoom também
   * reduz visualmente 100dvh para 80% da tela, deixando uma faixa branca.
   * Compensamos SOMENTE na home, sem mudar a escala dos menus do cliente.
   * 100 / .80 = 125: preenche exatamente a altura visível da janela.
   * Fora dessa faixa permanece a altura normal de 100dvh.
   */
  @media (min-width: 1024px) and (max-width: 1500px) and (max-height: 820px) {
    body.nexa-workspace-home {
      height: 125vh;
      height: 125dvh;
    }
  }
  body.nexa-workspace-home > .container {
    box-sizing: border-box;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: none !important;
    min-width: 0;
    min-height: 0;
    padding: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    overflow: hidden;
    background: #fff;
  }
  /* Mantém intacta a área da barra lateral no desktop. */
  @media (min-width: 921px) {
    body.nexa-workspace-home.admin-side-menu-enabled:not(.admin-sidebar-collapsed) > .container {
      width: calc(100% - 292px) !important;
      margin-left: 292px !important;
      margin-right: 0 !important;
    }
    body.nexa-workspace-home.admin-side-menu-enabled.admin-sidebar-collapsed > .container {
      width: 100% !important;
      margin-left: 0 !important;
      margin-right: 0 !important;
    }
  }
  /* A imagem ocupa TODA a região útil: sem bordas, cartão, sombra ou margem. */
  body.nexa-workspace-home .nexa-workspace-art {
    position: relative;
    box-sizing: border-box;
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
    min-height: 0;
    margin: 0;
    padding: 0;
    overflow: hidden;
    background: #f3f8ff;
  }
  body.nexa-workspace-home .nexa-workspace-art picture,
  body.nexa-workspace-home .nexa-workspace-art img {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    box-shadow: none;
  }
  body.nexa-workspace-home .nexa-workspace-art img {
    object-fit: cover;
    object-position: 50% 50%;
  }
  /* No celular a barra lateral é retrátil. Prioriza logo/pessoa no centro. */
  @media (max-width: 920px) {
    body.nexa-workspace-home > .container {
      margin-left: 0 !important;
      margin-right: 0 !important;
    }
    body.nexa-workspace-home .nexa-workspace-art img {
      object-position: 52% center;
    }
  }
  /*
   * Portrait estreito: o enquadramento cover cortaria o nome da marca.
   * Mantemos a foto em tela cheia, suavizamos só o topo e aplicamos a
   * logo original, centralizada e completa, sem criar um cartão.
   */
  @media (max-width: 580px) and (orientation: portrait) {
    body.nexa-workspace-home .nexa-workspace-art::after {
      content: "";
      position: absolute;
      inset: 0 0 auto;
      height: 46%;
      z-index: 1;
      pointer-events: none;
      background: #fff;
    }
    body.nexa-workspace-home .nexa-workspace-art::before {
      content: "";
      position: absolute;
      left: 11%;
      width: 78%;
      top: clamp(28px, 7dvh, 68px);
      height: clamp(100px, 18dvh, 145px);
      z-index: 2;
      pointer-events: none;
      background: center / contain no-repeat url('/assets/logo-nexa-v6-1.png');
    }
  }
  body.nexa-workspace-home .admin-footer { display: none !important; }
  .nexa-ws-panel{position:absolute;z-index:6;right:18px;top:18px;max-width:min(620px,calc(100% - 36px));display:grid;justify-items:end;gap:8px;font:600 13px/1.5 Inter,Segoe UI,Arial,sans-serif}
  .nexa-ws-identity{background:rgba(255,255,255,.96);border:1px solid #d9e4f5;border-radius:13px;padding:8px 13px;box-shadow:0 4px 18px #061f3b20;color:#19375f;text-align:right}
  .nexa-ws-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}
  .nexa-ws-actions a{display:inline-block;background:#fff;color:#1d4ed8;text-decoration:none;border:1px solid #b9cce6;border-radius:9px;padding:5px 9px;font-size:12px;font-weight:800}
  .nexa-ws-cat{display:inline;margin:0}
  .nexa-ws-cat button{display:inline-flex;align-items:center;gap:6px;cursor:pointer;font:inherit;font-size:12px;font-weight:800;border-radius:9px;padding:5px 9px;border:1px solid #b9cce6;background:#fff;color:#1d4ed8}
  .nexa-ws-cat button .dot{width:8px;height:8px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 2px #dcfce7}
  .nexa-ws-cat button.off{background:#fef2f2;border-color:#fca5a5;color:#b91c1c}
  .nexa-ws-cat button.off .dot{background:#dc2626;box-shadow:0 0 0 2px #fee2e2}
  .nexa-ws-flash{border-radius:11px;padding:7px 12px;font-size:12px;box-shadow:0 4px 18px #061f3b17;max-width:580px}
  .nexa-ws-flash.ok{background:#ecfdf5;border:1px solid #86efac;color:#166534}
  .nexa-ws-flash.err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
  .nexa-ws-alert{border:1px solid #f2c04d;border-left:5px solid #d97706;border-radius:13px;background:#fffbeb;color:#78350f;max-width:580px;padding:10px 13px;box-shadow:0 7px 22px #061f3b17}
  .nexa-ws-alert.overdue{background:#fef2f2;border-color:#fca5a5;border-left-color:#dc2626;color:#991b1b}
  @media(max-width:650px){.nexa-ws-panel{top:9px;right:9px;max-width:calc(100% - 18px)}.nexa-ws-identity{font-size:11px}.nexa-ws-alert{font-size:12px}}
</style>
<script>document.body.classList.add('nexa-workspace-home');</script>
<main class="nexa-workspace-art" aria-label="Bem-vindo ao Nexa Próspero">
  <div class="nexa-ws-panel">
    <div class="nexa-ws-identity">Usuário: <strong><?=htmlspecialchars($workspaceName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></strong> · Empresa: <strong><?=htmlspecialchars($workspaceCompany,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></strong></div>
    <div class="nexa-ws-actions"><a href="minhas_faturas.php">Minhas faturas</a><a href="assinatura_regularizar.php">Meu contrato</a>
      <?php if ($catalogoPodeAlterar): foreach (catalogo_status_tipos() as $catTipo => $catNome): $catAtivo = !empty($catalogoStatus[$catTipo]); ?>
        <form method="post" class="nexa-ws-cat" onsubmit="return confirm(<?=htmlspecialchars(json_encode($catAtivo ? ('Desativar o ' . $catNome . '? Os clientes verão o aviso "Catálogo em manutenção" até você ativar de novo.') : ('Ativar o ' . $catNome . '? Os clientes voltarão a ver os produtos.'), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')?>)">
          <input type="hidden" name="csrf" value="<?=htmlspecialchars((string)($_SESSION['catalogo_status_csrf'] ?? ''), ENT_QUOTES, 'UTF-8')?>">
          <input type="hidden" name="catalogo_tipo" value="<?=htmlspecialchars($catTipo, ENT_QUOTES, 'UTF-8')?>">
          <input type="hidden" name="catalogo_ativar" value="<?=$catAtivo ? '0' : '1'?>">
          <button type="submit" class="<?=$catAtivo ? 'on' : 'off'?>" title="<?=htmlspecialchars($catNome . ($catAtivo ? ': ativo (no ar). Clique para desativar.' : ': desativado (em manutenção). Clique para ativar.'), ENT_QUOTES, 'UTF-8')?>"><span class="dot" aria-hidden="true"></span><?=htmlspecialchars(($catAtivo ? 'Desativar ' : 'Ativar ') . $catNome, ENT_QUOTES, 'UTF-8')?></button>
        </form>
      <?php endforeach; endif; ?>
    </div>
    <?php if (is_array($catalogoFlash)): ?><div class="nexa-ws-flash <?=($catalogoFlash[0] ?? '') === 'ok' ? 'ok' : 'err'?>" role="status"><?=htmlspecialchars((string)($catalogoFlash[1] ?? ''), ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if($workspaceWarn):?><div class="nexa-ws-alert <?=$workspaceLate?'overdue':''?>" role="status"><strong>Assinatura Nexa:</strong> <?=htmlspecialchars((string)$workspaceCycle['message'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?> <a href="assinatura_regularizar.php" style="font-weight:900;color:inherit">Conferir e pagar</a></div><?php endif; ?>
  </div>
  <picture>
    <source srcset="/assets/nexa-area-trabalho-produtividade.webp?v=20261028-2" type="image/webp">
    <img src="/assets/nexa-area-trabalho-produtividade.png?v=20261028-2"
         alt="Nexa Próspero: profissional feliz utilizando o sistema de gestão"
         width="1672" height="941" decoding="async" fetchpriority="high">
  </picture>
</main>
<?php admin_footer(); ?>
