# V11.11.6 — WhatsApp R6: recuperação sob demanda do Chromium

Data: 18/09/2026

## Problema observado

A sessão podia aparecer como `connected=true`, `ready=true` e `authenticated=true`, mas manter `lastError` com mensagens como:

- `Attempted to use detached Frame ...`
- `Execution context was destroyed`
- `Target closed`

Nessa condição, o LocalAuth continuava autenticado, porém a página/frame do Chromium usada pelo `whatsapp-web.js` já não era válida. A versão R5 tentava reinjetar o WWebJS na mesma página, o que podia repetir o erro.

Também havia consultas periódicas de status no navegador:

- Admin > WhatsApp: a cada 15 segundos;
- Pedidos: a cada 30 segundos.

## Estratégia R6

A R6 não faz monitoramento contínuo da sessão.

### Recuperação somente quando necessário

Quando um envio é solicitado:

1. tenta usar o cliente atual;
2. erros leves de WWebJS permitem uma única reinjeção;
3. erros de frame/contexto/target não são reinjetados na página quebrada;
4. o Chromium da empresa é encerrado com `client.destroy()` — sem `logout()`;
5. o mesmo `LocalAuth` é reutilizado;
6. o cliente é recriado e aguarda `ready`;
7. o envio é tentado novamente uma única vez.

Há uma trava por empresa para impedir duas reconstruções simultâneas.

### Sem polling de interface

- `admin/whatsapp.php` consulta uma vez ao abrir e depois somente ao clicar em Atualizar ou executar uma ação.
- `admin/pedidos.php` consulta o indicador uma vez ao abrir.
- O serviço expõe `/admin/sessions`, que apenas lê o estado em memória/pastas persistidas e NÃO inicia Chromium.
- `/admin/session/start` existe para o worker de keepalive acordar apenas sessão persistida quando essa tarefa for explicitamente executada pelo servidor.

## Arquivos alterados

- `nexa/tools/whatsapp-service-server-com-pdf.js`
- `nexa/tools/instalar_whatsapp_pdf.sh`
- `nexa/admin/whatsapp.php`
- `nexa/admin/whatsapp_api.php`
- `nexa/admin/pedidos.php`

Não há alteração de banco de dados.

## Instalação

1. Faça backup.
2. Copie a pasta `nexa/` do pacote sobre `/var/www/nexa/`.
3. Atualize o serviço Node e reinicie o PM2:

```bash
sudo bash /var/www/nexa/tools/instalar_whatsapp_pdf.sh \
  /var/www/nexa/tools/whatsapp-service-server-com-pdf.js \
  /opt/nexa-whatsapp-service \
  nexa-whatsapp
```

4. Confira:

```bash
curl -s http://127.0.0.1:3333/health
pm2 status
pm2 logs nexa-whatsapp --lines 80
```

O `/health` deve informar `service_version: r6`, `recovery_browser_on_demand: true` e `background_polling: false`.

5. Faça `Ctrl + F5` no navegador.

## Homologação sugerida

1. Abra Admin > WhatsApp e confirme que a tela não se atualiza sozinha a cada 15 segundos.
2. Clique em Atualizar uma vez.
3. Se a autenticação já existir, não faça logout nem novo QR desnecessariamente.
4. Abra um boleto e clique em Enviar PDF pelo WhatsApp.
5. Se ocorrer `detached Frame`, o próprio envio deve recriar o Chromium e tentar novamente uma única vez.
6. Confirme no `pm2 logs` as mensagens `Recuperação sob demanda` somente quando houver esse erro.
7. Abra Pedidos e confirme que o indicador é consultado somente no carregamento da página.

## Observação importante

A R6 não executa `logout()` durante recuperação automática e não apaga `.wwebjs_auth`. Portanto, a tendência é preservar o vínculo do aparelho. Novo QR Code continua sendo contingência para autenticação realmente expirada ou para uso manual do botão `Corrigir conexão`.
