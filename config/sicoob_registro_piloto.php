<?php
/**
 * NEXA / SICOOB V1.7 - PERMISSOES EXPLICITAS POR EMPRESA
 * Libera registro manual e automatico SOMENTE para a empresa piloto 5.
 * Auto-POST exige TAMBEM a forma de pagamento marcada: Registrar boleto automaticamente.
 * Para desabilitar novamente, troque ambas as listas para [].
 * NAO inserir aqui senhas, PFX, Client ID, CPF ou outros dados de clientes.
 */
return [
    'empresas_registro_manual' => [5],
    'empresas_registro_automatico' => [5],
];
