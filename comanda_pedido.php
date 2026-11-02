<?php
declare(strict_types=1);

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/load_config.php';
require_once __DIR__ . '/config/saas_modules.php';
require_once __DIR__ . '/saas/saas_guard.php';
require_once __DIR__ . '/config/comanda_public_module.php';

$slug = trim((string)($_GET['empresa'] ?? $_GET['company'] ?? ''));
$company = tenant_resolve_company($pdo, $slug);
saas_enforce_company_access($pdo, $company, 'catalog');
if (!saas_company_has_module($pdo, (int)$company['id'], 'comandas') && !saas_company_has_module($pdo, (int)$company['id'], 'gestao')) {
  http_response_code(404); echo 'Pedido por QR Code indisponível.'; exit;
}
define('COMPANY_ID', (int)$company['id']);
define('COMPANY_SLUG', (string)$company['slug']);
define('COMPANY_SUBDOMAIN', (string)($company['subdomain'] ?? ''));
define('COMPANY_CUSTOM_DOMAIN', (string)($company['custom_domain'] ?? ''));

$app = load_app_config(COMPANY_ID);
$biz = load_business_config(COMPANY_ID);
app_apply_company_timezone($pdo, (string)($biz['timezone'] ?? 'America/Sao_Paulo'));
comanda_ensure_schema($pdo);

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$comandaId = (int)($_GET['c'] ?? $_POST['comanda_id'] ?? 0);
$comanda = null;

if ($token !== '') {
  $comanda = comanda_find_by_public_token($pdo, COMPANY_ID, $token);
  if (!$comanda) { http_response_code(404); echo 'QR Code inválido ou desativado. Solicite ajuda a um atendente.'; exit; }
  if (strtoupper((string)($comanda['status'] ?? '')) === 'BLOQUEADA') { http_response_code(403); echo 'Esta mesa/comanda está bloqueada. Solicite ajuda a um atendente.'; exit; }
  comanda_public_issue_access_cookie($comanda, COMPANY_ID);
  $cleanUrl = 'comanda_pedido.php?empresa=' . rawurlencode(COMPANY_SLUG) . '&c=' . (int)$comanda['id'];
  header('Location: ' . $cleanUrl, true, 303);
  exit;
}

if ($comandaId > 0) $comanda = comanda_find($pdo, COMPANY_ID, $comandaId);
if (!$comanda || empty($comanda['public_order_enabled'])) { http_response_code(404); echo 'Mesa/comanda não encontrada ou desativada.'; exit; }
if (strtoupper((string)($comanda['status'] ?? '')) === 'BLOQUEADA') { http_response_code(403); echo 'Esta mesa/comanda está bloqueada. Solicite ajuda a um atendente.'; exit; }
$settings = comanda_settings($pdo, COMPANY_ID);
if (empty($settings['public_qr_enabled'])) { http_response_code(403); echo 'Os pedidos por QR Code estão temporariamente desativados.'; exit; }
$publicAccessValid = comanda_public_has_valid_access($comanda, COMPANY_ID);

if (isset($_GET['session_status'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => $publicAccessValid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$error = '';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_public_order') {
  try {
    if (!$publicAccessValid) throw new RuntimeException('Esta sessão da mesa foi encerrada. Leia novamente o QR Code da mesa para fazer um novo pedido.');
    $result = comanda_public_create_order(
      $pdo,
      COMPANY_ID,
      $comanda,
      $_POST,
      (string)($_SERVER['REMOTE_ADDR'] ?? ''),
      (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
  } catch (Throwable $e) { $error = $e->getMessage(); }
}

$catalog = comanda_public_catalog_data($pdo, COMPANY_ID);
$products = (array)$catalog['products'];
$optionsByProduct = (array)$catalog['options'];
$brand = (array)($biz['brand'] ?? []);
$primary = trim((string)($brand['primary_color'] ?? '#0B1F5B')) ?: '#0B1F5B';
$secondary = trim((string)($brand['secondary_color'] ?? '#2454D6')) ?: '#2454D6';
$accent = trim((string)($brand['accent_color'] ?? '#00B8FF')) ?: '#00B8FF';
if (!preg_match('/^#[0-9a-f]{6}$/i', $primary)) $primary = '#0B1F5B';
if (!preg_match('/^#[0-9a-f]{6}$/i', $secondary)) $secondary = '#2454D6';
if (!preg_match('/^#[0-9a-f]{6}$/i', $accent)) $accent = '#00B8FF';
$companyName = trim((string)($brand['catalog_title'] ?? '')) ?: trim((string)($company['name'] ?? 'Cardápio'));
$logoFile = trim((string)($biz['logo']['file'] ?? ''));
$logoUrl = ($logoFile !== '' && !empty($biz['logo']['enabled'])) ? '/uploads/logos/' . rawurlencode(basename($logoFile)) : '';
$requestToken = comanda_random_public_token();

$productPayload = [];
$categories = [];
foreach ($products as $p) {
  $id = (int)($p['id'] ?? 0);
  if ($id <= 0) continue;
  $cat = trim((string)($p['categoria'] ?? '')) ?: 'Outros';
  $categories[$cat] = true;
  $groups = [];
  foreach ((array)($optionsByProduct[$id] ?? []) as $g) {
    $groups[] = [
      'id'=>(int)$g['id'], 'title'=>(string)$g['titulo'], 'type'=>(string)($g['tipo'] ?? 'single'),
      'required'=>(int)($g['obrigatorio'] ?? 0), 'min'=>(int)($g['min_escolhas'] ?? 0), 'max'=>(int)($g['max_escolhas'] ?? 1),
      'items'=>array_map(static fn(array $it): array => ['id'=>(int)$it['id'],'name'=>(string)$it['nome'],'price'=>(float)($it['preco'] ?? 0)], (array)($g['items'] ?? [])),
    ];
  }
  $img = trim((string)($p['imagem'] ?? ''));
  $productPayload[] = [
    'id'=>$id, 'name'=>(string)$p['nome'], 'category'=>$cat, 'price'=>(float)($p['preco'] ?? 0),
    'image'=>$img !== '' ? '/uploads/products/' . rawurlencode(basename($img)) : '/assets/no-image.svg',
    'options'=>$groups,
  ];
}
$productsJson = json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$storageKey = 'nexa_qr_order_' . COMPANY_ID . '_' . (int)$comanda['id'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= comanda_public_h($companyName) ?> — Mesa/Comanda <?= comanda_public_h($comanda['codigo']) ?></title>
<style>
:root{--primary:<?= comanda_public_h($primary) ?>;--secondary:<?= comanda_public_h($secondary) ?>;--accent:<?= comanda_public_h($accent) ?>;--ink:#0f172a;--muted:#64748b;--line:#dbe5f2;--bg:#f4f7fb;--surface:#fff;--ok:#047857;--danger:#b91c1c}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;padding-bottom:106px}.top{position:sticky;top:0;z-index:30;background:rgba(255,255,255,.94);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}.top-in{max-width:1180px;margin:auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{display:flex;align-items:center;gap:10px;min-width:0}.logo{width:46px;height:46px;border-radius:15px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;display:grid;place-items:center;font-weight:950;overflow:hidden;flex:none}.logo img{width:100%;height:100%;object-fit:contain;background:#fff}.brand strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:52vw}.brand small{display:block;color:var(--muted);margin-top:2px}.table-tag{padding:9px 12px;border-radius:999px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;font-weight:950;white-space:nowrap}.wrap{max-width:1180px;margin:auto;padding:16px}.hero{border-radius:25px;padding:20px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;box-shadow:0 18px 45px rgba(15,23,42,.14);display:flex;justify-content:space-between;gap:18px;align-items:center}.hero h1{font-size:clamp(24px,5vw,38px);margin:0 0 6px}.hero p{margin:0;opacity:.9;line-height:1.45}.hero-badge{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);padding:12px 15px;border-radius:18px;text-align:center;min-width:130px}.hero-badge b{display:block;font-size:23px}.toolbar{display:grid;grid-template-columns:minmax(0,1fr);gap:10px;margin:16px 0 12px}.search{width:100%;border:1px solid var(--line);border-radius:17px;padding:14px 15px;font:inherit;background:#fff;outline:none}.search:focus{border-color:var(--secondary);box-shadow:0 0 0 3px color-mix(in srgb,var(--secondary) 14%,transparent)}.cats{display:flex;gap:8px;overflow:auto;padding:2px 0 8px;scrollbar-width:none}.cat{border:1px solid var(--line);background:#fff;border-radius:999px;padding:9px 13px;font-weight:850;white-space:nowrap;cursor:pointer}.cat.active{background:var(--primary);color:#fff;border-color:var(--primary)}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.card{border:1px solid var(--line);background:#fff;border-radius:21px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.05);display:grid;grid-template-columns:104px minmax(0,1fr);min-height:122px;cursor:pointer;text-align:left;padding:0;font:inherit}.card img{width:104px;height:100%;min-height:122px;object-fit:cover;background:#eef2f7}.card-body{padding:13px;display:flex;flex-direction:column;min-width:0}.card small{color:var(--muted);font-weight:750}.card strong{font-size:16px;line-height:1.2;margin:5px 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.price{color:var(--ok);font-weight:950;margin-top:auto}.empty{padding:30px;text-align:center;color:var(--muted);background:#fff;border:1px dashed var(--line);border-radius:20px;grid-column:1/-1}.floating{position:fixed;z-index:40;left:50%;bottom:max(16px,env(safe-area-inset-bottom));transform:translateX(-50%);width:min(94vw,620px);border:0;border-radius:22px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;padding:14px 16px;box-shadow:0 18px 50px rgba(11,31,91,.32);display:flex;align-items:center;justify-content:space-between;gap:12px;font:inherit;font-weight:950;cursor:pointer}.floating[disabled]{opacity:.55;cursor:not-allowed}.float-count{display:inline-grid;place-items:center;min-width:31px;height:31px;border-radius:999px;background:#fff;color:var(--primary);padding:0 8px}.modal{position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.62);display:none;align-items:flex-end;justify-content:center;padding:14px}.modal.open{display:flex}.dialog{width:min(720px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:26px 26px 20px 20px;box-shadow:0 24px 80px rgba(2,6,23,.32)}.mhead{position:sticky;top:0;z-index:2;background:rgba(255,255,255,.96);backdrop-filter:blur(10px);border-bottom:1px solid var(--line);padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:10px}.mhead h2{margin:0;font-size:20px}.close{width:40px;height:40px;border-radius:14px;border:1px solid var(--line);background:#fff;font-size:24px;cursor:pointer}.mbody{padding:16px}.product-top{display:grid;grid-template-columns:120px minmax(0,1fr);gap:14px}.product-top img{width:120px;height:120px;border-radius:20px;object-fit:cover;background:#eef2f7}.product-top h3{margin:3px 0 8px;font-size:22px}.field{display:grid;gap:6px;margin-top:12px}.field>span,.group-title{font-weight:900;font-size:13px;color:#334155}.field input,.field textarea{width:100%;border:1px solid var(--line);border-radius:15px;padding:12px;font:inherit}.field textarea{min-height:80px;resize:vertical}.options{display:grid;gap:12px;margin-top:14px}.opt-group{border:1px solid var(--line);border-radius:18px;padding:12px}.group-head{display:flex;justify-content:space-between;gap:10px;margin-bottom:8px}.group-head small{color:var(--muted)}.opt-list{display:grid;gap:7px}.opt{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #e9eef5;border-radius:14px;padding:10px;background:#fbfdff}.opt label{display:flex;gap:9px;align-items:center;flex:1;cursor:pointer}.actions{display:flex;gap:9px;justify-content:flex-end;margin-top:16px}.btn{border:1px solid var(--line);border-radius:15px;background:#fff;padding:12px 15px;font:inherit;font-weight:900;cursor:pointer}.btn-primary{border-color:var(--primary);background:var(--primary);color:#fff}.cart-list{display:grid;gap:9px}.cart-item{border:1px solid var(--line);border-radius:18px;padding:12px}.cart-line{display:flex;justify-content:space-between;gap:12px}.cart-line strong{line-height:1.25}.cart-item small{display:block;color:var(--muted);margin-top:5px;white-space:pre-line}.qtyrow{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px}.qty-controls{display:flex;align-items:center;gap:7px}.qty-controls button{width:36px;height:36px;border-radius:12px;border:1px solid var(--line);background:#fff;font-weight:950}.remove{border:0;background:transparent;color:var(--danger);font-weight:900;cursor:pointer}.cart-total{display:flex;justify-content:space-between;font-size:21px;font-weight:950;border-top:1px solid var(--line);padding-top:14px;margin-top:14px}.notice{padding:12px 14px;border-radius:16px;margin:0 0 14px}.notice.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.success{max-width:650px;margin:50px auto;padding:25px;border:1px solid #a7f3d0;background:#fff;border-radius:28px;text-align:center;box-shadow:0 20px 55px rgba(15,23,42,.1)}.success-icon{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;background:#dcfce7;color:#047857;font-size:38px;font-weight:950}.success h1{margin:0 0 9px}.success p{color:var(--muted);line-height:1.5}.overlay{position:fixed;inset:0;z-index:999;background:rgba(255,255,255,.88);backdrop-filter:blur(5px);display:none;place-items:center}.overlay.show{display:grid}.processing{text-align:center}.spinner{width:58px;height:58px;border:6px solid #dbeafe;border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 13px}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.wrap{padding:12px}.hero{align-items:flex-start}.hero-badge{display:none}.grid{grid-template-columns:1fr}.card{grid-template-columns:92px minmax(0,1fr);min-height:108px}.card img{width:92px;min-height:108px}.modal{padding:0;align-items:flex-end}.dialog{max-height:94vh;border-radius:25px 25px 0 0}.product-top{grid-template-columns:90px 1fr}.product-top img{width:90px;height:90px}.actions{position:sticky;bottom:0;background:#fff;padding-top:12px}.actions .btn{flex:1}.brand strong{max-width:44vw}}
</style>
</head>
<body>
<?php if (!$publicAccessValid): ?>
  <div class="wrap"><section class="success"><div class="success-icon" style="background:#f59e0b">!</div><h1>Sessão encerrada</h1><p>Esta comanda foi finalizada ou a autorização de acesso expirou.</p><p>Para sua segurança, leia novamente o <strong>QR Code disponível na mesa</strong> antes de fazer outro pedido.</p></section></div>
<?php elseif ($result): ?>
  <div class="wrap"><section class="success"><div class="success-icon">✓</div><h1>Pedido enviado!</h1><p>Recebemos seu pedido na <strong>mesa/comanda <?= comanda_public_h($comanda['codigo']) ?></strong>.</p><p>Número da solicitação: <strong>#<?= (int)$result['order_id'] ?></strong><br>Total: <strong><?= comanda_money((float)$result['subtotal']) ?></strong></p><p>Os itens foram separados para a cozinha, bar ou setor configurado. Aguarde o preparo.</p><a class="btn btn-primary" href="<?= comanda_public_h('comanda_pedido.php?empresa=' . rawurlencode(COMPANY_SLUG) . '&c=' . (int)$comanda['id']) ?>">Fazer outro pedido</a></section></div>
  <script>try{localStorage.removeItem(<?= json_encode($storageKey) ?>)}catch(e){}</script>
<?php else: ?>
<header class="top"><div class="top-in"><div class="brand"><div class="logo"><?php if ($logoUrl): ?><img src="<?= comanda_public_h($logoUrl) ?>" alt=""><?php else: ?><?= comanda_public_h(function_exists('mb_substr') ? mb_substr($companyName,0,1,'UTF-8') : substr($companyName,0,1)) ?><?php endif; ?></div><div><strong><?= comanda_public_h($companyName) ?></strong><small>Pedido pelo QR Code</small></div></div><div class="table-tag">Mesa <?= comanda_public_h($comanda['codigo']) ?></div></div></header>
<main class="wrap">
  <section class="hero"><div><h1>O que você deseja pedir?</h1><p>Escolha os produtos, confira o carrinho e informe seus dados para enviar.</p></div><div class="hero-badge"><span>Identificado</span><b><?= comanda_public_h($comanda['codigo']) ?></b><small>Mesa/Comanda</small></div></section>
  <?php if ($error): ?><div class="notice err" style="margin-top:14px"><?= comanda_public_h($error) ?></div><?php endif; ?>
  <div class="toolbar"><input class="search" id="search" type="search" placeholder="Pesquisar produto..."><div class="cats" id="cats"><button class="cat active" data-cat="Todos">Todos</button><?php foreach (array_keys($categories) as $cat): ?><button class="cat" data-cat="<?= comanda_public_h($cat) ?>"><?= comanda_public_h($cat) ?></button><?php endforeach; ?></div></div>
  <section class="grid" id="products"></section>
</main>
<button class="floating" id="cartBtn" disabled><span><span class="float-count" id="cartCount">0</span> Conferir pedido</span><strong id="cartTotal">R$ 0,00</strong></button>

<div class="modal" id="productModal"><div class="dialog"><div class="mhead"><h2>Adicionar produto</h2><button class="close" data-close="productModal">×</button></div><div class="mbody"><div class="product-top"><img id="pmImage" src="" alt=""><div><small id="pmCategory"></small><h3 id="pmName"></h3><div class="price" id="pmPrice"></div></div></div><div class="field"><span>Quantidade</span><input id="pmQty" type="number" min="0.001" max="99" step="1" value="1" inputmode="decimal"></div><div class="options" id="pmOptions"></div><label class="field"><span>Observação</span><textarea id="pmNote" maxlength="500" placeholder="Ex.: sem cebola, gelo separado..."></textarea></label><div class="actions"><button class="btn" data-close="productModal">Cancelar</button><button class="btn btn-primary" id="addItem">Adicionar ao pedido</button></div></div></div></div>

<div class="modal" id="cartModal"><div class="dialog"><div class="mhead"><h2>Confira seu pedido</h2><button class="close" data-close="cartModal">×</button></div><div class="mbody"><div class="cart-list" id="cartList"></div><div class="cart-total"><span>Total</span><span id="cartModalTotal">R$ 0,00</span></div><div class="actions"><button class="btn" data-close="cartModal">Continuar escolhendo</button><button class="btn btn-primary" id="identifyBtn">Identificar e enviar</button></div></div></div></div>

<div class="modal" id="identifyModal"><div class="dialog"><div class="mhead"><h2>Identificação do pedido</h2><button class="close" data-close="identifyModal">×</button></div><form class="mbody" method="post" id="orderForm"><input type="hidden" name="action" value="send_public_order"><input type="hidden" name="comanda_id" value="<?= (int)$comanda['id'] ?>"><input type="hidden" name="request_token" value="<?= comanda_public_h($requestToken) ?>"><input type="hidden" name="items_json" id="itemsJson"><label class="field"><span>Seu nome<?= !empty($settings['public_qr_require_name']) ? '' : ' (opcional)' ?></span><input name="customer_name" <?= !empty($settings['public_qr_require_name']) ? 'required' : '' ?> maxlength="120" autocomplete="name" placeholder="Digite seu nome"></label><label class="field"><span>Celular com DDD<?= !empty($settings['public_qr_require_phone']) ? '' : ' (opcional)' ?></span><input name="customer_phone" <?= !empty($settings['public_qr_require_phone']) ? 'required' : '' ?> maxlength="20" inputmode="tel" autocomplete="tel" placeholder="(31) 99999-9999"></label><p style="color:var(--muted);font-size:13px;line-height:1.45">Ao enviar, os produtos entrarão diretamente na mesa/comanda <?= comanda_public_h($comanda['codigo']) ?> e serão encaminhados aos setores de preparo.</p><div class="actions"><button class="btn" type="button" data-close="identifyModal">Voltar</button><button class="btn btn-primary" type="submit">Enviar pedido</button></div></form></div></div>
<div class="overlay" id="processing"><div class="processing"><div class="spinner"></div><strong>Enviando seu pedido...</strong><p style="color:var(--muted)">Aguarde e não toque novamente.</p></div></div>
<script>
const SESSION_STATUS_URL=<?= json_encode('comanda_pedido.php?empresa=' . rawurlencode(COMPANY_SLUG) . '&c=' . (int)$comanda['id'] . '&session_status=1') ?>;
let sessionLocked=false;
async function checkTableSession(){
  if(sessionLocked)return;
  try{
    const response=await fetch(SESSION_STATUS_URL,{credentials:'same-origin',cache:'no-store'});
    const data=await response.json();
    if(!data.ok){
      sessionLocked=true;
      try{localStorage.removeItem(<?= json_encode($storageKey) ?>)}catch(e){}
      alert('Esta comanda foi finalizada. Leia novamente o QR Code da mesa para fazer outro pedido.');
      window.location.reload();
    }
  }catch(e){}
}
window.setInterval(checkTableSession,30000);
window.addEventListener('focus',checkTableSession);
const PRODUCTS=<?= $productsJson ?: '[]' ?>, STORAGE_KEY=<?= json_encode($storageKey) ?>, REQUIRE_PHONE=<?= !empty($settings['public_qr_require_phone']) ? 'true' : 'false' ?>;
const money=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0));
let cart=[], current=null, activeCat='Todos';
try{const saved=JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]');if(Array.isArray(saved))cart=saved}catch(e){}
const $=id=>document.getElementById(id);function openModal(id){$(id).classList.add('open')}function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>closeModal(b.dataset.close)));
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeModal(m.id)}));
function persist(){try{localStorage.setItem(STORAGE_KEY,JSON.stringify(cart))}catch(e){} renderCartBadge()}
function normalized(s){return String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase()}
function renderProducts(){const q=normalized($('search').value);const list=PRODUCTS.filter(p=>(activeCat==='Todos'||p.category===activeCat)&&(!q||normalized(p.name+' '+p.category).includes(q)));$('products').innerHTML=list.length?list.map(p=>`<button class="card" data-product="${p.id}"><img src="${escapeHtml(p.image)}" alt=""><span class="card-body"><small>${escapeHtml(p.category)}</small><strong>${escapeHtml(p.name)}</strong><span class="price">${money(p.price)}</span></span></button>`).join(''):'<div class="empty">Nenhum produto encontrado.</div>';document.querySelectorAll('[data-product]').forEach(b=>b.onclick=()=>showProduct(Number(b.dataset.product)))}
function escapeHtml(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
$('search').addEventListener('input',renderProducts);document.querySelectorAll('.cat').forEach(b=>b.onclick=()=>{document.querySelectorAll('.cat').forEach(x=>x.classList.remove('active'));b.classList.add('active');activeCat=b.dataset.cat;renderProducts()});
function showProduct(id){current=PRODUCTS.find(p=>p.id===id);if(!current)return;$('pmImage').src=current.image;$('pmCategory').textContent=current.category;$('pmName').textContent=current.name;$('pmPrice').textContent=money(current.price);$('pmQty').value='1';$('pmNote').value='';$('pmOptions').innerHTML=(current.options||[]).map(g=>{const inputType=g.type==='multiple'?'checkbox':'radio';const required=Math.max(Number(g.min||0),Number(g.required?1:0));const info=required?`Mínimo ${required}${g.max>0?' • Máximo '+g.max:''}`:(g.max>0?'Máximo '+g.max:'Opcional');return `<section class="opt-group" data-group="${g.id}"><div class="group-head"><span class="group-title">${escapeHtml(g.title)}</span><small>${info}</small></div><div class="opt-list">${(g.items||[]).map(it=>`<div class="opt"><label><input type="${inputType}" name="group_${g.id}" value="${it.id}"> <span>${escapeHtml(it.name)}</span></label><strong>${Number(it.price)>0?'+ '+money(it.price):'Incluso'}</strong></div>`).join('')}</div></section>`}).join('');openModal('productModal')}
function selectionsForCurrent(){const options={}, lines=[];let extra=0;for(const g of current.options||[]){const selected=[...document.querySelectorAll(`[name="group_${g.id}"]:checked`)].map(el=>Number(el.value));const min=Math.max(Number(g.min||0),Number(g.required?1:0)),max=g.type==='single'?1:Number(g.max||0);if(selected.length<min)throw new Error(`Selecione ao menos ${min} opção(ões) em “${g.title}”.`);if(max>0&&selected.length>max)throw new Error(`Selecione no máximo ${max} opção(ões) em “${g.title}”.`);if(selected.length){options[g.id]=selected;const names=[];for(const id of selected){const it=(g.items||[]).find(x=>x.id===id);if(it){names.push(it.name);extra+=Number(it.price||0)}}if(names.length)lines.push(`${g.title}: ${names.join(', ')}`)}}return{options,lines,extra}}
$('addItem').onclick=()=>{try{const qty=Math.max(.001,Math.min(99,Number(String($('pmQty').value).replace(',','.'))||1));const sel=selectionsForCurrent();const note=$('pmNote').value.trim();cart.push({key:Date.now()+'_'+Math.random(),product_id:current.id,name:current.name,qty,base_price:current.price,unit_price:Number(current.price)+sel.extra,options:sel.options,option_lines:sel.lines,note});persist();closeModal('productModal');openModal('cartModal');renderCart()}catch(e){alert(e.message)}};
function cartTotals(){return cart.reduce((a,it)=>a+Number(it.qty)*Number(it.unit_price),0)}
function renderCartBadge(){$('cartCount').textContent=String(cart.length);$('cartTotal').textContent=money(cartTotals());$('cartBtn').disabled=!cart.length}
function renderCart(){if(!cart.length){$('cartList').innerHTML='<div class="empty">Seu pedido está vazio.</div>'}else{$('cartList').innerHTML=cart.map((it,i)=>`<article class="cart-item"><div class="cart-line"><strong>${escapeHtml(it.name)}</strong><b>${money(Number(it.qty)*Number(it.unit_price))}</b></div>${it.option_lines?.length?`<small>${escapeHtml(it.option_lines.join('\n'))}</small>`:''}${it.note?`<small>Obs.: ${escapeHtml(it.note)}</small>`:''}<div class="qtyrow"><div class="qty-controls"><button data-minus="${i}">−</button><b>${String(it.qty).replace('.',',')}x</b><button data-plus="${i}">+</button></div><button class="remove" data-remove="${i}">Remover</button></div></article>`).join('')} $('cartModalTotal').textContent=money(cartTotals());document.querySelectorAll('[data-minus]').forEach(b=>b.onclick=()=>changeQty(Number(b.dataset.minus),-1));document.querySelectorAll('[data-plus]').forEach(b=>b.onclick=()=>changeQty(Number(b.dataset.plus),1));document.querySelectorAll('[data-remove]').forEach(b=>b.onclick=()=>{cart.splice(Number(b.dataset.remove),1);persist();renderCart()})}
function changeQty(i,d){if(!cart[i])return;cart[i].qty=Math.max(.001,Math.min(99,Number(cart[i].qty)+d));persist();renderCart()}
$('cartBtn').onclick=()=>{renderCart();openModal('cartModal')};$('identifyBtn').onclick=()=>{if(!cart.length)return;closeModal('cartModal');openModal('identifyModal')};
$('orderForm').addEventListener('submit',e=>{if(!cart.length){e.preventDefault();return alert('Adicione produtos ao pedido.')}const phone=e.currentTarget.customer_phone.value.replace(/\D+/g,'');if(REQUIRE_PHONE&&phone.length<10){e.preventDefault();return alert('Informe um celular válido com DDD.')} $('itemsJson').value=JSON.stringify(cart.map(it=>({product_id:it.product_id,qty:it.qty,note:it.note,options:it.options})));$('processing').classList.add('show');e.currentTarget.querySelector('button[type="submit"]').disabled=true});
renderProducts();renderCartBadge();
</script>
<?php endif; ?>
</body></html>
