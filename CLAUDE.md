# Nexa Próspero — Manual do sistema para o Claude

Sistema SaaS de gestão para varejo (PDV, pedidos, comandas, estoque, financeiro, fiscal NF-e/NFC-e/NFS-e, boletos, OS, CRM, contratos). Responda e escreva comentários em **português do Brasil**. O dono do projeto não é programador: explique mudanças em linguagem simples e entregue comandos prontos para colar.

## Ambiente de produção

- VPS Hostinger, Ubuntu 24.04, Apache (`.htaccess` com mod_rewrite), PHP 8.x, MySQL, atrás da Cloudflare.
- Código em `/var/www/nexa` (este repositório). Landing separada em `/var/www/nexa-landing` (fora deste repo).
- Banco oficial: `nexaprospero` (definido via `SetEnv DB_NAME` no `.htaccess`).
- O histórico Git da VPS fica em `/root/git/nexa.git` (fora da pasta pública).
- Apache só na porta 80 (HTTPS feito pela Cloudflare). Sites: `nexa-app.conf` (app.nexaprospero.com.br), `nexaprospero.conf` (domínio raiz → `/var/www/nexa-landing`; curinga `*.nexaprospero.com.br` → `/var/www/nexa`), `nexa-homolog.conf` (homologação).
- Crons em produção: `workers/os_fiscal_queue_worker.php` e `workers/whatsapp_keepalive.php` (a cada minuto). Serviços systemd: `nexa-brain-worker`, `nexa-realtime`, `nexa-fiscal-dfe-sync`, `redis-server`, `php8.3-fpm`.
- Agente de manutenção `nexa-ops` (`deploy/ops/`): o painel Saúde do Sistema (suporte) grava pedidos com uma palavra fixa (`resolver`/`reiniciar_vps`) em `/var/lib/nexa-ops/queue/`; o `nexa-ops.path` dispara o runner como root. Exige senha master; desligado na homologação. Nunca aceitar comandos vindos do navegador.
- WhatsApp — motor novo (leve, em migração): `whatsapp-engine/server.mjs` (Baileys, sem Chrome, todas as empresas num processo) roda numa VPS própria, serviço systemd `nexa-wa-engine`, acessível só pelo túnel WireGuard `wg-nexawa` (sistema 10.77.0.1 ↔ motor 10.77.0.2:3333). Instalar/atualizar pela VPS do sistema: `bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP`. URL/token/segredo em `/etc/nexa/whatsapp_engine.env` (root:www-data 640, gravado pelo instalador; ignorado na homologação — `app_config` tem FK para `companies`, então company_id=0 não grava). Empresas migradas uma a uma em `saas/whatsapp_motor.php` (troca o `service_url` da empresa; pede QR Code). Mesmas rotas do serviço antigo; mensagens recebidas vão por webhook assinado (HMAC) para `webhooks/whatsapp_entrada.php`, ligado por empresa em `/inbound/config`. Mensagens digitadas por pessoas da empresa no celular chegam como evento `human_reply` (o robô se cala na conversa).
- Chatbot e Atendimento do WhatsApp (módulo do SaaS `whatsapp_chatbot`): regras em `config/whatsapp_chatbot.php` (fluxo em árvore `whatsapp_bot_nodes`, setores `whatsapp_departments`/`whatsapp_department_users`, conversa = `whatsapp_bot_sessions` com status BOT/PENDENTE/ATENDIMENTO/FINALIZADO, mensagens em `whatsapp_bot_messages`) e `config/whatsapp_atendimento.php` (tela estilo WhatsApp Web `admin/whatsapp_atendimento.php` + `_api.php`; arquivos por `admin/whatsapp_midia.php`). Arquivos ficam fora da pasta pública em `/var/www/nexa_private/whatsapp_media/<pasta do app>/` (ou env `NEXA_WA_MEDIA_DIR`). A tela atualiza por evento `whatsapp.chat.updated` do `nexa-realtime` (sem polling). Configuração em WhatsApp > Chatbot (`app_config` chave `whatsapp_bot`). Fluxos com frase de gatilho/perguntas em sequência e "repetir último pedido" (cria pedido `aberto`, source `delivery`, preço atual) em `config/whatsapp_bot_flows.php` (tabelas `whatsapp_bot_flows`/`whatsapp_bot_flow_steps`; respostas em `whatsapp_bot_sessions.answers_json`), editor `admin/whatsapp_chatbot_fluxo.php`.
- WhatsApp (serviço antigo, Node `nexa-whatsapp-service` no PM2/`pm2-root`, um Chrome por empresa): nunca enviar de forma síncrona em fluxos de venda/pedido/nota — usar `whatsapp_queue_message()` (fila `whatsapp_outbox`; o `nexa-whatsapp-outbox.path` inicia o envio só quando há aviso em `/var/lib/nexa-whatsapp/sinais`, sem verificação periódica). `whatsapp_api_request` tem disjuntor (pausa envios após falhas). Limites de CPU/memória e reinício diário às 4h30: `deploy/whatsapp/proteger_whatsapp.sh`.

## Fluxo de trabalho: homologação → produção

| | Branch | Pasta na VPS | Banco | Endereço |
|---|---|---|---|---|
| Homologação | `homolog` | `/var/www/nexa-homolog` | `nexaprospero_homolog` (usuário MySQL `nexa_homolog`, sem acesso à produção) | `homolog.nexaprospero.com.br` (empresa-padrao) |
| Produção | `main` | `/var/www/nexa` | `nexaprospero` | subdomínios das empresas |

1. Toda alteração é feita primeiro na branch `homolog`. Na VPS: `cd /var/www/nexa-homolog && git pull`.
2. O dono testa em `https://homolog.nexaprospero.com.br/admin/login.php`.
3. Aprovado, fazer merge de `homolog` em `main`. Na VPS: `cd /var/www/nexa && git pull`.
4. Migrações de banco: aplicar primeiro pelo Atualizador do Banco da homologação, depois no de produção.
- Correções urgentes podem ir direto para `main`, mas depois fazer merge de `main` em `homolog`.
- Recriar a homologação com cópia nova do banco: `bash /var/www/nexa/deploy/homolog/criar_homologacao.sh`.
- Na homologação, notas fiscais e bancos ficam em HOMOLOGACAO/sandbox, WhatsApp e e-mail ficam sem configuração, e não há crons. Não remover essas travas.
- Serviços auxiliares: `realtime-server/` (Node, WebSocket do Nexa Brain com Redis), units em `deploy/systemd/`, e o `agente-impressao-windows/` que roda no computador dos clientes.

## NFS-e (Ambiente Nacional)

- Desde 01/11/2026 (Resolução CGSN 191/2026) ME/EPP do Simples emitem pelo Sistema Nacional; o Pronim rejeita (P3084). Provider por empresa em Configurações fiscais (`nfse_provider`).
- Produção liberada no servidor por `NFSE_ENABLE_PRODUCTION=1` em `/etc/nexa/nfse.env`; a homologação força `0` no vhost.
- Regras já tratadas no emissor Nacional (`config/nfse_emitter.php`): IM do prestador opcional (E0120); sem `pAliq` para ME/EPP com ISS pelo Simples e sem retenção (E0625); `pTotTribSN` no lugar de `indTotTrib` para ME/EPP (E0712), com o % guardado em `app_config` chave `nfse_nacional`.
- DPS rejeitada no Pronim e nunca autorizada é reenviada pelo Nacional com a mesma série/número (`nfse_provider_adopt_company_provider`).

## O que NÃO está no repositório (ver `.gitignore`)

- `config/db.php` e `config/database.php` — credenciais do MySQL. Existem só na VPS. Nunca recriar com senha real.
- `*.env` (ex.: `nexa-brain.env`), certificados `*.pfx/*.p12/*.pem/*.key`.
- `uploads/`, `storage/`, `downloads/`, `tmp/` — dados dos clientes (certificados A1, XMLs, anexos, logos).
- `vendor/` — instalar com `composer install` (dependências NFePHP: `sped-nfe`, `sped-da`).
- Os `.md/.txt` soltos na raiz (LEIA_ME_*, README_*, VALIDACAO_*) — histórico dos antigos zips.

Credenciais de integrações (Sicoob, Itaú, Inter, PagBank, WhatsApp, Cloudflare) são guardadas **no banco por empresa** (`app_config` e tabelas próprias), não em arquivos. Mantenha assim: nunca escreva senha, token ou chave no código.

## Arquitetura multiempresa (o ponto mais importante)

- **Um único banco** para todas as empresas; isolamento por coluna `company_id` em todas as tabelas de dados.
- A empresa é resolvida em `config/tenant.php` → `tenant_resolve_company()`, nesta ordem: `?company=slug` → `custom_domain` → subdomínio (`empresa.nexaprospero.com.br`). Domínios raiz em `config/root_domains.php` / env `APP_ROOT_DOMAINS`.
- Bootstrap do painel: `admin/_bootstrap.php` → `admin/_bootstrap_public.php`, que define `COMPANY_ID` (empresa do subdomínio) e o contexto **Multiempresa/F11** (`config/multi_company.php`: `MULTI_COMPANY_CONTEXT_ID`, `MULTI_COMPANY_CONTEXT_VALID`), usado só em telas explicitamente homologadas.
- Autenticação em `config/auth.php` (sessão PHP). Papéis: `platform_admin` (dono da plataforma, acessa tudo), `company_admin`, `company_user`. Permissões granulares em `config/access_control.php`. Bloqueio por assinatura em `saas/saas_guard.php`.

**Regras obrigatórias:**
1. Toda consulta a dados de empresa filtra por `company_id = ?` usando `COMPANY_ID` (ou o contexto F11 quando a tela for homologada para ele). Nunca confiar em `company_id` vindo de `$_GET/$_POST`.
2. Sempre `$pdo->prepare()` com parâmetros. Nunca concatenar entrada do usuário em SQL. Nomes de tabela/coluna dinâmicos só via whitelist.
3. Escapar saída HTML com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
4. Ações que gravam exigem POST e validação de permissão.

## Estrutura de pastas

| Pasta | Conteúdo |
|---|---|
| raiz (`*.php`) | Catálogo público, carrinho/checkout, landing, cadastro de empresa, Pix, comanda pública |
| `admin/` | Painel da empresa (≈280 telas). Toda página começa com `require __DIR__.'/_bootstrap.php';` |
| `config/` | Módulos de negócio (funções), conexão (`pdo.php`), tenant, auth, fiscal, bancos. Arquivos grandes: `fiscal_module.php`, `fiscal_operational.php`, `finance_module.php` |
| `saas/` | Central da plataforma (empresas, planos, assinaturas, cobranças) — só `platform_admin` |
| `revenda/`, `contador/`, `contadorprospero/`, `suporte/` | Portais de revendedores, contadores e suporte |
| `api/`, `webhooks/` | Endpoints JSON e retornos de bancos/gateways (Itaú, Inter Pix, PagBank) |
| `workers/` | Scripts de linha de comando (cron, migrações antigas, auditorias). Rodar com `php workers/xxx.php` |
| `tools/` | Diagnósticos e correções pontuais (fiscal/NFS-e, comandas) |
| `db/` | Arquivos SQL das migrações |
| `lib/` | Clientes de terceiros (ex.: `lib/PagBank/Client.php`) |
| `assets/`, `resources/` | JS, CSS, imagens, XSD fiscais |
| `deploy/` | Configs Apache/Nginx wildcard, systemd, instalação do realtime |
| `tests/` | Testes manuais em PHP (sem framework) |

## Mudanças no banco de dados (migrações)

O padrão atual, que deve ser seguido:
1. Criar o SQL em `db/AAAAMMDD_descricao_vX.sql` (idempotente: `IF NOT EXISTS`, checar coluna antes de `ALTER`).
2. Registrar em `config/schema_updater.php` → `nexa_schema_updater_registry()` com `id`, `module`, `version`, `label`, `description`, `file` e o **SHA-256** do arquivo (`sha256sum db/arquivo.sql`).
3. O dono aplica pela tela **Atualizador do Banco** (`admin/atualizador_banco.php`, só `platform_admin`). Versões ficam em `nexa_schema_versions`.
4. Não executar DDL (`CREATE/ALTER`) no caminho normal das páginas.

## Como trabalhar neste projeto

- Faça mudanças pequenas e focadas; nunca reescreva um módulo inteiro sem pedido.
- Antes de editar uma função, procure todos os usos (`grep -rn nome_funcao`). Muitos arquivos usam `if (!function_exists(...))` — mantenha esse padrão.
- Compatível com PHP 7.4+ (composer) e rodando em 8.x: valide com `php -l arquivo.php` em todo arquivo alterado.
- Áreas sensíveis (fiscal, boletos, caixa, estoque): explique o impacto antes de mudar e indique como testar.
- Commits em português, descrevendo o que mudou para o usuário final.
- Ao final de cada tarefa, informe: arquivos alterados, se há migração a aplicar, e o comando de deploy na VPS.
