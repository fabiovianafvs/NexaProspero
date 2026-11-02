# V11.11.8.2 — WhatsApp R8.2 — Pré-resolução LID para mídia

## Objetivo
Corrigir o erro `No LID for user` observado após a correção do `MediaData.__x_id` na R8.1.

## Estratégia
- Mantém `whatsapp-web.js` 1.34.7 e os patches R7/R8.1 já homologados.
- Não altera `node_modules` novamente.
- Antes de enviar **documentos/mídia**, executa uma única resolução PN → LID usando `client.getContactLidAndPhone()`, que na 1.34.7 usa `enforceLidAndPnRetrieval` e `queryWidExists` para popular o mapeamento interno.
- A primeira tentativa continua usando o número normal (`@c.us`).
- Somente se o WhatsApp Web responder especificamente `No LID for user`/`Lid is missing in chat table` e houver um LID resolvido, faz uma segunda tentativa única usando `@lid`.
- O envio de texto não é alterado.
- `sendSeen` é desligado apenas no envio de documento para evitar uma resolução de chat adicional sem necessidade.
- Nenhum polling, watchdog ou verificação contínua foi adicionado.

## Instalação
```bash
sudo bash /var/www/nexa/tools/instalar_whatsapp_pdf_r8_2.sh \
  /var/www/nexa/tools/whatsapp-service-server-com-pdf.js \
  /opt/nexa-whatsapp-service \
  nexa-whatsapp-service
```

Depois:
```bash
curl -s http://127.0.0.1:3333/health
```
Deve mostrar `service_version: r8.2` e `media_lid_preflight: true`.

## Teste
1. Não reconectar QR.
2. Enviar uma mensagem simples (controle).
3. Enviar um boleto PDF.
4. Enviar uma NFC-e com anexo.
5. Em caso de falha, enviar os últimos 100 registros dos logs do PM2.
