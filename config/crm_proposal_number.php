<?php
declare(strict_types=1);
/**
 * Número de apresentação da proposta. Não utilizar em SQL ou parâmetros de URL.
 * Os IDs originais permanecem inalterados e continuam identificando os registros.
 */
if (!function_exists('crm_proposal_number')) {
    function crm_proposal_number(int $proposalId): int {
        return $proposalId > 0 ? $proposalId + 1000 : 0;
    }
}
