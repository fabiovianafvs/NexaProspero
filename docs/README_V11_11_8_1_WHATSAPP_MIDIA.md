# V11.11.8.1 — WhatsApp R8.1 — correção do instalador de mídia

## Motivo
A instalação V11.11.8 podia abortar antes do patch de mídia porque reaplicava a R7 e procurava o bloco original de `Utils.js/getChat`, que já havia sido modificado numa instalação anterior.

## Estratégia
- não reaplica R7;
- confirma que o marcador estrutural da R7 já existe;
- aplica somente a correção `MediaData.__x_id` na função `sendMessage`;
- só substitui/reinicia o `server.js` após o patch novo ser aplicado com sucesso;
- preserva `.wwebjs_auth` e `tokens.json`;
- nenhum watchdog ou polling de sessão é criado.

## Instalação
```bash
sudo bash /var/www/nexa/tools/instalar_whatsapp_pdf_r8_1.sh \
  /var/www/nexa/tools/whatsapp-service-server-com-pdf.js \
  /opt/nexa-whatsapp-service \
  nexa-whatsapp-service \
  /var/www/nexa/tools/patch_whatsapp_webjs_media_r8_1.js
```

O `/health` deve indicar `service_version: r8.1`, `media_wid_compat: true`, `media_private_id_fix: true` e `background_polling: false`.
