# Motor de WhatsApp leve (Nexa Próspero)

Serviço Node único (Baileys, sem navegador) que atende todas as empresas.
Substitui o serviço antigo `tools/whatsapp-service-server-com-pdf.js`
(um Chrome por empresa) mantendo as mesmas rotas HTTP.

- Instalação/atualização (na VPS do sistema, como root):
  `bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP_DA_VPS_DO_WHATSAPP`
- Migração das empresas: Central SaaS > WhatsApp > Motor novo (leve).
- Logs na VPS do WhatsApp: `journalctl -u nexa-wa-engine -f`
- Dados: `/var/lib/nexa-wa` (sessões em `auth/company-<id>`, `tokens.json`, `settings.json`).
- Configuração: `/etc/nexa-wa/engine.env`.
