# Nexa Próspero — Atualização 14/09/2026

## Escopo

Esta atualização foi preparada sobre o backup `Backup_14092026_0851` e contempla dois pontos:

1. granularidade de acesso para os menus e submenus do painel administrativo, respeitando os módulos habilitados para a empresa;
2. novo layout do boleto Itaú, baseado no modelo de homologação fornecido.

## Perfis de acesso

- Cada item real de menu/submenu do `admin/_chrome.php` possui agora uma permissão correspondente.
- O perfil só pode habilitar uma permissão quando pelo menos um dos módulos exigidos por ela estiver ativo.
- Permissões vinculadas a módulos temporariamente desabilitados ficam bloqueadas na edição, mas são preservadas no JSON do perfil para não se perderem se o módulo for reativado.
- O acesso à O.S. Mobile continua exigindo também a liberação individual `allow_os_mobile` do usuário.
- Foram criadas permissões separadas para itens que antes compartilhavam a mesma chave, incluindo Modelo de Mensagens, Novo Orçamento, O.S. Mobile, Documentos Fiscais da O.S., Cobranças/Boletos e Entrada de Notas.
- Usuários e Perfis de acesso também passaram a ser permissões configuráveis. `usuarios_perfis` é uma permissão administrativa elevada, pois permite alterar os acessos de outros usuários.
- A validação de página continua sendo aplicada além da ocultação do menu, impedindo acesso direto por URL quando a permissão não estiver liberada.

### Compatibilidade dos perfis existentes

Execute uma única vez:

`db/20260914_perfis_acesso_menus_granulares_v2.sql`

O script preserva o comportamento dos perfis atuais adicionando as novas permissões granulares equivalentes quando a permissão antiga já existia.

## Boleto Itaú

- O layout específico é aplicado somente quando o provedor é Itaú ou o código bancário é 341.
- Outros bancos continuam usando o layout genérico anterior.
- O novo modelo possui Recibo do Pagador, cabeçalho Itaú, linha digitável, grade de dados, área de instruções, pagador, corte, Ficha de Compensação, QR Code Pix e código de barras.
- O logotipo foi obtido do próprio modelo de homologação fornecido e está em `assets/img/itau-logo.png`.
- O QR Code do PDF usa preferencialmente a imagem base64 retornada pela API Itaú. Se houver apenas o PIX EMV, existe fallback vetorial em PHP e não é necessária extensão GD/Imagick.

## Arquivos alterados

- `admin/financeiro_boleto_imprimir.php`
- `admin/usuario_email_teste.php`
- `admin/usuario_form.php`
- `admin/usuario_perfil_form.php`
- `admin/usuarios.php`
- `admin/usuarios_perfis.php`
- `config/access_control.php`
- `config/boleto_document.php`
- `config/saas_modules.php`

## Arquivos novos

- `assets/img/itau-logo.png`
- `db/20260914_perfis_acesso_menus_granulares_v2.sql`
- `docs/ATUALIZACAO_20260914_PERFIS_BOLETO_ITAU.md`

## Implantação sugerida

1. Faça backup dos arquivos atuais e do banco.
2. Copie a pasta `nexa` do pacote sobre `/var/www/nexa`, preservando a estrutura e sobrescrevendo apenas os arquivos do pacote.
3. Execute `db/20260914_perfis_acesso_menus_granulares_v2.sql` no banco `nexaprospero`.
4. Entre em **Tabelas > Perfis de acesso** e revise um perfil de teste antes de aplicar aos usuários em produção.
5. Gere um boleto Itaú de homologação e confira linha digitável, vencimento, valor, Nosso Número, QR Code Pix e código de barras antes de liberar em produção.

## Validações realizadas

- PHP lint (`php -l`) nos arquivos PHP alterados: sem erros de sintaxe.
- Conferência automática dos itens de menu do `admin/_chrome.php`: nenhum item real ficou sem mapeamento ACL.
- Geração e renderização de PDF Itaú de teste: layout, logotipo, QR Code e código de barras conferidos visualmente.
