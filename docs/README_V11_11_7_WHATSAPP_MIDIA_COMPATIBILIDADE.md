# V11.11.7 — WhatsApp R7: compatibilidade de mídia/documentos

## Diagnóstico confirmado

- Serviço efetivamente em R6.
- `whatsapp-web.js`: **1.34.7**.
- Texto simples funciona.
- Envio com mídia/documento (Boleto PDF e NFC-e) falha com erro do WhatsApp Web semelhante a:
  `Data passed to getter must include an id property (it's how we memoize) but got undefined`.

O WhatsApp Web passou a expor alguns WIDs com `$1` no lugar de `_serialized`. O hotfix aplica um patch local e versionado na dependência 1.34.7, inspirado na correção upstream aberta para esse cenário, sem atualizar toda a biblioteca e sem alterar a autenticação LocalAuth.

## O que muda

- Normalização `_serialized <- $1` em Message/Chat.
- Normalização dos modelos injetados no WhatsApp Web.
- Fallback de localização do chat pela coleção atual.
- Serviço passa a identificar-se como **R7** e informa `media_wid_compat=true` no `/health`.
- Recuperação do Chromium continua **somente sob demanda**.
- Não há `setInterval`, heartbeat ou polling contínuo novo.
- Texto simples continua usando o mesmo `client.sendMessage()` existente.

## Instalação

Copiar a pasta `nexa/` do pacote sobre `/var/www/nexa/` e executar:

```bash
sudo bash /var/www/nexa/tools/instalar_whatsapp_pdf.sh \
  /var/www/nexa/tools/whatsapp-service-server-com-pdf.js \
  /opt/nexa-whatsapp-service \
  nexa-whatsapp-service \
  /var/www/nexa/tools/patch_whatsapp_webjs_media_r7.js
```

Depois:

```bash
curl -s http://127.0.0.1:3333/health
pm2 status
pm2 logs nexa-whatsapp-service --lines 100
```

O `/health` deve conter:

- `service_version: r7`
- `background_polling: false`
- `recovery_browser_on_demand: true`
- `whatsapp_webjs_version: 1.34.7`
- `media_wid_compat: true`

## Teste mínimo

1. Não clicar em **Corrigir conexão**.
2. Enviar uma mensagem simples de Pedido para confirmar texto.
3. Enviar o PDF de um Boleto.
4. Enviar o PDF/XML/documento pelo mesmo caminho usado na NFC-e.
5. Verificar o log somente se a mídia ainda falhar.

## Rollback

O instalador cria backup de `server.js` e o patch cria backups individuais dos arquivos da biblioteca com sufixo `.bak-nexa-r7-<timestamp>`. As pastas `.wwebjs_auth` e `tokens.json` não são apagadas.

## Observação

O patch é deliberadamente bloqueado para versões diferentes de `whatsapp-web.js 1.34.7`. Se a dependência for atualizada no futuro, o instalador aborta em vez de aplicar modificações incompatíveis.
