AGENTE DE IMPRESSAO AUTOMATICA NEXA/PROSPERO - V1.10
==================================================

OBJETIVO
O agente recebe os pedidos do servidor e envia diretamente para impressoras termicas, sem abrir a janela de impressao do navegador.

TIPOS SUPORTADOS
1. WINDOWS: impressora USB ou de rede instalada no Windows.
2. TCP: impressora Ethernet acessivel diretamente por IP, normalmente na porta 9100.

UM COMPUTADOR / VARIAS IMPRESSORAS
Cadastre varias linhas no configurador. Exemplo:
- Cozinha -> WINDOWS -> EPSON TM-T20X
- Bar -> WINDOWS -> ELGIN i9 BAR
- Bebidas -> TCP -> 192.168.1.60:9100

COMPUTADORES SEPARADOS
Crie um agente/chave no sistema para cada computador e libere somente os setores daquele local.

INSTALACAO
1. Extraia esta pasta para um local fixo, recomendado: C:\NexaAgenteImpressao.
2. No sistema, acesse Tabelas > Agentes de Impressao.
3. Crie a estacao, selecione os setores e copie a chave.
4. Execute Configurar_Agente.bat.
5. Informe o endereco da API, a chave e as impressoras.
6. Clique em Testar conexao e Salvar.
7. Execute Testar_Agente.bat e envie um pedido de teste.
8. Depois do teste, execute Instalar_Ao_Entrar_no_Windows.bat.

SE O CONFIGURADOR NAO ABRIR
1. Execute novamente Configurar_Agente.bat. A versao 1.2 corrige o caminho vazio do config.json, mantem a janela aberta e grava o erro em configurador_erro.log.
2. Como alternativa, execute Configurar_Manual.bat e preencha o arquivo config.json no Bloco de Notas.
3. O cadastro no site cria a estacao, a chave e os setores permitidos. O endereco da impressora e o relacionamento setor/impressora sao configurados neste computador.

INSTALAR COMO SISTEMA
A opcao Instalar_Como_Sistema.bat inicia antes do login. E indicada para impressoras TCP ou impressoras instaladas para todos os usuarios. Execute como Administrador.

LARGURA
- 58 mm: normalmente 32 colunas.
- 80 mm: normalmente 48 colunas.

CODIFICACAO
O padrao e CP850 / tabela ESC-POS 2. Caso os acentos saiam incorretos, altere Encoding e EscPosTable no config.json conforme o manual da impressora.

REIMPRESSAO
Falhas temporarias sao tentadas novamente automaticamente. Depois de cinco tentativas, o trabalho fica como ERRO no Monitor de Impressao, onde pode ser reenviado.

LOGS
Os registros ficam em logs\agente.log.

SEGURANCA
A chave identifica a empresa e os setores liberados. Ao gerar uma nova chave no sistema, a anterior para de funcionar imediatamente.

CORRECAO V1.2
- Corrigido erro Join-Path com parametro Path vazio ao abrir ConfigurarAgente.ps1.
- Configurador, teste, inicializacao oculta e servico recebem o caminho completo do config.json.
- O agente tambem resolve a propria pasta sem depender do PSScriptRoot no bloco param.


CORRECAO V1.3
- O endereco HTTP dos dominios nexaprospero.com.br e convertido automaticamente para HTTPS.
- A confirmacao de impressao passa a ser persistida localmente antes do retorno ao servidor.
- Se a impressao chegar ao spooler e a confirmacao falhar, o agente tenta confirmar novamente sem reimprimir.
- O agente recarrega o config.json a cada ciclo; novos setores normalmente entram em funcionamento sem reiniciar.
- Incluido Reiniciar_Agente.bat para reiniciar a tarefa agendada quando necessario.
- Nome da tarefa no Agendador do Windows: Nexa Prospero - Agente de Impressao.


CORRECAO V1.4
- Sincroniza automaticamente os setores configurados no computador com o servidor.
- Um setor novo, como BOLOS, passa a ser atendido sem editar novamente o agente no painel web.
- Envia ao Monitor o nome da impressora realmente usada.
- Mantem a protecao contra reimpressoes quando a confirmacao estiver pendente.


CORRECAO V1.5
- Corrigido o processamento de pedidos que possuem itens de dois ou mais setores.
- O agente agora esvazia a fila disponivel no mesmo ciclo, imprimindo BOLOS, BEBIDAS, COZINHA e demais setores em sequencia.
- Uma confirmacao pendente de um setor nao bloqueia a impressao dos outros setores do mesmo pedido.
- Cada setor continua sendo confirmado separadamente pelo ID do trabalho, evitando perda ou duplicidade.


CORRECAO V1.6
- O servidor entrega todos os grupos de um mesmo pedido em um unico lote.
- Um pedido com dois, tres ou mais grupos gera um trabalho separado para cada grupo.
- Cada trabalho e enviado para a impressora mapeada para seu setor, mesmo quando as impressoras estao em computadores diferentes.
- Uma falha em um setor nao interrompe os demais setores do mesmo pedido.
- A fila e reconstruida a partir dos itens gravados, inclusive para pedidos recentes que tenham sido marcados como impressos antes de todos os grupos serem criados.


CORRECAO V1.7
- O configurador agora valida a chave, sincroniza os setores e ativa a tarefa em um unico botao: Salvar e ativar.
- Incluidos Ativar_Agente.bat e Diagnosticar_Agente.bat.
- O diagnostico confere chave, API, tarefa agendada, impressoras, setores, status local e as ultimas linhas do log.
- A tarefa agendada e recriada apontando para a pasta atual, evitando continuar executando uma versao antiga em outro diretorio.
- O bloqueio de instancia passou a usar arquivo exclusivo, evitando problemas entre execucao como usuario e como SYSTEM.
- Chaves recusadas mostram uma mensagem clara para gerar e copiar a chave completa novamente.
- Valores 58 e 80 no campo Colunas sao convertidos automaticamente para 32 e 48.
- O servidor reserva todos os trabalhos pendentes em lote e reconstrói os grupos a partir dos itens do pedido.
- O Monitor ganhou Reconstruir fila por setores e resumo da fila por grupo de producao.


CORRECAO V1.8
- Corrigido erro de sintaxe do DiagnosticarAgente.ps1 em mensagens que continham o nome do setor seguido de dois-pontos.
- O modo recomendado para impressoras WINDOWS nao depende mais de criar tarefa agendada com privilegio elevado.
- Salvar e ativar registra o agente na inicializacao do usuario (HKCU) e inicia o processo imediatamente, sem exigir Administrador.
- Instalar_Como_Sistema.bat continua disponivel para impressoras TCP ou instaladas para todos os usuarios e solicita elevacao do Windows.
- Reiniciar_Agente.bat agora reinicia diretamente o agente no modo do usuario.
- O diagnostico reconhece tanto a inicializacao do usuario quanto uma tarefa do sistema e verifica se o processo esta realmente em execucao.

CORRECAO V1.9
- A ativacao nao depende mais de localizar o processo pelo texto da linha de comando. O processo iniciado e acompanhado diretamente pelo PID.
- Incluido ExecutarAgente.ps1, que captura erros fatais de sintaxe, Add-Type, configuracao e inicializacao antes que a janela feche.
- Falhas fatais sao gravadas em logs\inicio_fatal.log e status_agente.json.
- O arquivo agente.pid permite iniciar, parar, reiniciar e diagnosticar o agente sem depender do WMI.
- O ativador aguarda ate 15 segundos pelo status do agente e exibe o motivo real caso ele encerre.
- O diagnostico valida a sintaxe dos scripts com o parser do proprio PowerShell antes de testar a API.
- Testar_Agente.bat agora executa o mesmo inicializador usado no modo automatico, mostrando qualquer falha em primeiro plano.



CORRECAO V1.10

- Corrigida a falha de sintaxe em AgenteImpressao.ps1 na mensagem de erro do trabalho, causada por uma variavel seguida diretamente de dois-pontos.

- A mensagem agora usa formatacao segura e nao impede a inicializacao do agente.

- Todos os scripts foram verificados para localizar outras interpolacoes de variavel seguidas de dois-pontos.

- Mantido o processamento independente de todos os grupos de producao do pedido.

