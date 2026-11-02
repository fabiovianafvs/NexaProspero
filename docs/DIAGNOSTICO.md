# Diagnóstico da interrupção

**Marco de retomada:** backup de 29/07/2026 às 17:46.

O backup posterior continha arquivos da NFS-e e arquivos de conferência de estoque. Eles não devem ser reaplicados como um único bloco.

O principal risco encontrado foi o acoplamento indevido entre a configuração dos XSDs e a configuração do banco:

- um script antigo usava `DB_NAME=u807353405_nexadelivery` como padrão;
- o valor podia ser gravado em um arquivo de ambiente;
- uma alteração posterior permitia que `config/pdo.php` carregasse esse ambiente nas requisições web.

A verificação do ambiente mostrou que existem dois bancos diferentes:

- `u807353405_nexadelivery`: hospedagem compartilhada/phpMyAdmin, com 6 empresas;
- `nexaprospero`: MySQL da VPS usado pelo Nexa em `/var/www/nexa`, com 16 empresas.

A retomada deve ocorrer somente no banco `nexaprospero` da VPS.

O pacote elimina o acoplamento perigoso:

- não modifica `config/pdo.php`;
- não modifica `DB_NAME`;
- carrega somente variáveis `NFSE_*`;
- exige conferência explícita do banco no pré-voo;
- mantém produção bloqueada;
- não inclui alterações da conferência de estoque.
