# Nexa Próspero — Manual do sistema para o Claude

Sistema SaaS de gestão para varejo (PDV, pedidos, comandas, estoque, financeiro, fiscal NF-e/NFC-e/NFS-e, boletos, OS, CRM, contratos). Responda e escreva comentários em **português do Brasil**. O dono do projeto não é programador: explique mudanças em linguagem simples e entregue comandos prontos para colar.

## Ambiente de produção

- VPS Hostinger, Ubuntu 24.04, Apache (`.htaccess` com mod_rewrite), PHP 8.x, MySQL, atrás da Cloudflare.
- Código em `/var/www/nexa` (este repositório). Landing separada em `/var/www/nexa-landing` (fora deste repo).
- Banco oficial: `nexaprospero` (definido via `SetEnv DB_NAME` no `.htaccess`).
- O histórico Git da VPS fica em `/root/git/nexa.git` (fora da pasta pública).
- Apache só na porta 80 (HTTPS feito pela Cloudflare). Sites: `nexa-app.conf` (app.nexaprospero.com.br), `nexaprospero.conf` (domínio raiz → `/var/www/nexa-landing`; curinga `*.nexaprospero.com.br` → `/var/www/nexa`), `nexa-homolog.conf` (homologação).
- Crons em produção: `workers/os_fiscal_queue_worker.php` e `workers/whatsapp_keepalive.php` (a cada minuto). Serviços systemd: `nexa-brain-worker`, `nexa-realtime`, `nexa-fiscal-dfe-sync`, `redis-server`, `php8.3-fpm`.

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
