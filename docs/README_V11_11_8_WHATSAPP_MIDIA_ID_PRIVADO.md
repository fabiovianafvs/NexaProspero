# V11.11.8 — WhatsApp R8: correção de mídia MediaData.__x_id

## Objetivo
Corrigir a falha de envio de mídia/documentos no `whatsapp-web.js 1.34.7` em que mensagens de texto continuam funcionando, mas `Client.sendMessage(..., media)` falha com:

`Data passed to getter must include an id property (it's how we memoize) but got undefined`

## Diagnóstico confirmado
- O ERP envia a empresa correta.
- A sessão técnica está conectada, pronta e autenticada.
- O mesmo serviço envia texto normalmente.
- Boleto PDF e documento fiscal falham no mesmo caminho de mídia.
- Houve envios de PDF com sucesso antes da mudança recente do WhatsApp Web.
- O issue upstream `wwebjs/whatsapp-web.js#201922` identifica a colisão de `MediaData.__x_id` com o ID interno da mensagem como causa do erro.

## Correção
O hotfix mantém a R7 e aplica um segundo patch em:

`node_modules/whatsapp-web.js/src/util/Injected/Utils.js`

Depois que o objeto de mensagem é montado, remove apenas a propriedade privada `__x_id` do MediaData antes da inicialização do Msg.

Não muda envio de texto, autenticação, LocalAuth, token, PHP financeiro/fiscal ou banco.

## Desempenho
Permanece sem polling contínuo. A recuperação do navegador segue sob demanda.

## Instalação
Após copiar `nexa/` para `/var/www/nexa`:

```bash
sudo bash /var/www/nexa/tools/instalar_whatsapp_pdf.sh \
  /var/www/nexa/tools/whatsapp-service-server-com-pdf.js \
  /opt/nexa-whatsapp-service \
  nexa-whatsapp-service \
  /var/www/nexa/tools/patch_whatsapp_webjs_media_r7.js \
  /var/www/nexa/tools/patch_whatsapp_webjs_media_r8.js
```

Depois:

```bash
curl -s http://127.0.0.1:3333/health
```

Esperado:
- `service_version: r8`
- `background_polling: false`
- `media_wid_compat: true`
- `media_private_id_fix: true`

## Testes
1. Mensagem simples de Pedido.
2. Boleto PDF.
3. NFC-e/DANFE com anexo.
4. Conferir `whatsapp_logs` e `pm2 logs nexa-whatsapp-service --lines 100`.
