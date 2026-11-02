<?php
declare(strict_types=1);
/** Consulta avulsa por DPS, SOMENTE LEITURA. Sem reserva, gravação ou transmissão. */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';
require_once __DIR__ . '/../config/nfse_pronim.php';

$cid = function_exists('multi_company_operational_company_id')
    ? (int)multi_company_operational_company_id($pdo, 'fiscal_nfse_homologation') : (int)COMPANY_ID;
$crossCompany = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true && $cid !== (int)COMPANY_ID;
if ($crossCompany) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
$url = static function(string $path) use ($crossCompany): string {
    return $crossCompany && function_exists('multi_company_context_url') ? multi_company_context_url($path) : $path;
};
$error = '';
$result = null;
$series = trim((string)($_POST['serie'] ?? ''));
$number = trim((string)($_POST['numero'] ?? ''));
$cfg = fiscal_company_config($pdo, $cid);
$environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
$provider = strtoupper(trim((string)($cfg['nfse_provider'] ?? '')));
$token = nfse_emitter_csrf_token('nfse_dps_avulsa');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if ($crossCompany) throw new RuntimeException('Consulta avulsa indisponível pelo F11. Acesse diretamente a empresa do prestador.');
        if (!in_array($provider, ['PRONIM','GOVBR','ISS_DIGITAL','PRONIM_GOVBR'], true))
            throw new RuntimeException('Esta consulta está disponível somente para o provedor Pronim/GOVBR.');
        if (!hash_equals($token, (string)($_POST['csrf'] ?? '')))
            throw new RuntimeException('Sessão expirada. Atualize a página.');
        if (!preg_match('/^[0-9]{1,5}$/D', $series) || (int)$series < 1 || (int)$series >= 49990)
            throw new RuntimeException('Série inválida; informe de 1 a 49989.');
        if (!preg_match('/^[0-9]{1,15}$/D', $number) || (int)$number < 1)
            throw new RuntimeException('Número de DPS inválido.');
        if (!in_array($environment, ['PRODUCAO','HOMOLOGACAO'], true))
            throw new RuntimeException('Ambiente fiscal não reconhecido.');
        if (nfse_pronim_api_base($cfg, $environment) === '')
            throw new RuntimeException('Endpoint Pronim indisponível na configuração fiscal.');
        $dpsId = nfse_emitter_dps_id($cfg, $series, (int)$number);
        // Consulta GET remota mTLS já utilizada pela recuperação de DPS do Nexa.
        // Não chama reserve_document, prepare_order, authorize_document nem UPDATE.
        $http = nfse_pronim_http($pdo, $cid, $cfg, $environment, 'GET',
            nfse_pronim_dps_lookup_operation($cfg, $series, (int)$number), null);
        $parsed = nfse_emitter_response_data((string)($http['body'] ?? ''));
        $status = (int)($http['status'] ?? 0);
        $curlErrno = (int)($http['curl_errno'] ?? 0);
        $successful = $status >= 200 && $status < 300 && $curlErrno === 0;
        $key = nfse_emitter_digits((string)($parsed['access_key'] ?? ''));
        $xml = trim((string)($parsed['xml'] ?? ''));
        $hasDocument = $successful && ($key !== '' || $xml !== '');
        $rawBody = trim((string)($http['body'] ?? ''));
        $providerMessage = trim((string)($parsed['message'] ?? ''));
        $transportError = trim((string)($http['curl_error'] ?? ''));
        $combined = strtolower($providerMessage.' '.$rawBody);
        // HTTP 404 pode indicar rota não suportada, não necessariamente DPS inexistente.
        // Não inferir numeração disponível a partir de erros HTTP, respostas vazias ou falhas de rede.
        $routeMissing = $status === 404 && (
            str_contains($combined, 'no http resource was found')
            || str_contains($combined, 'matches the request uri')
            || str_contains($combined, 'route not found')
            || str_contains($combined, 'endpoint not found')
        );
        if ($curlErrno !== 0 || $status === 0 || $status >= 500) {
            $classification = 'communication_error';
            $headline = 'Falha de comunicação: consulta inconclusiva';
            $guidance = 'Verifique a comunicação com o provedor. Não transmita novamente nem altere a sequência com base nesta resposta.';
        } elseif ($routeMissing || in_array($status, [405, 501], true)) {
            $classification = 'unsupported_route';
            $headline = 'Consulta indisponível nesta rota do Pronim/GOVBR';
            $guidance = 'O serviço não reconheceu a operação consultada. Isso não significa que a DPS esteja livre. Confirme no portal municipal; a emissão existente do Nexa não foi alterada.';
        } elseif ($hasDocument) {
            $classification = 'found';
            $headline = 'Documento localizado na resposta do provedor';
            $guidance = 'Confira a identificação e a situação fiscal antes de qualquer nova tentativa.';
        } elseif ($status === 401 || $status === 403) {
            $classification = 'access_denied';
            $headline = 'Consulta não autorizada pelo provedor';
            $guidance = 'Verifique credenciais e permissões para consulta, sem modificar a configuração da emissão homologada.';
        } else {
            $classification = 'inconclusive';
            $headline = 'DPS não confirmada: resultado inconclusivo';
            $guidance = 'A resposta não comprova a disponibilidade da numeração. Consulte o portal municipal ou os registros do sistema anterior.';
        }
        $result = [
            'dps' => $dpsId, 'environment' => $environment, 'status' => $status,
            'has_document' => $hasDocument, 'classification' => $classification,
            'headline' => $headline, 'guidance' => $guidance,
            'key' => $key, 'code' => (string)($parsed['code'] ?? ''),
            'message' => $providerMessage,
            'transport_error' => $transportError,
            'success' => $successful,
        ];
    } catch (Throwable $e) {
        error_log('[NEXA_DPS_LOOKUP] company='.$cid.' error='.$e->getMessage());
        $error = 'Consulta não concluída: '.$e->getMessage();
    }
}
fiscal_admin_start('Consultar DPS existente', 'fiscal_nfse_nacional');
fiscal_page_header('Consultar DPS existente', 'Consulta avulsa ao Pronim/GOVBR; não transmite, não reserva número e não altera a sequência.', [
    ['label'=>'Voltar à NFS-e', 'href'=>$url('fiscal_nfse_nacional.php'), 'class'=>'btn'],
]);
?>
<div class="fiscal-card" style="max-width:900px">
  <h2>Verificar série e número da DPS</h2>
  <p>Prestador: empresa operacional #<?= (int)$cid ?> · Ambiente: <strong><?= fiscal_h($environment) ?></strong> · Provedor: <?= fiscal_h($provider) ?></p>
  <div class="msg">Uma resposta 404, vazia ou inconclusiva <strong>não comprova que o número está livre</strong>. Confirme também no portal municipal ou sistema anterior antes de reutilizá-lo.</div>
  <?php if ($crossCompany): ?><div class="msg err">Pelo F11, abra diretamente a empresa do prestador antes da consulta.</div><?php endif; ?>
  <form method="post" style="display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin:18px 0">
    <input type="hidden" name="csrf" value="<?= fiscal_h($token) ?>">
    <label style="flex:1 1 140px;font-weight:700">Série DPS<br><input type="text" name="serie" inputmode="numeric" pattern="[0-9]{1,5}" maxlength="5" required value="<?= fiscal_h($series === '' ? '2' : $series) ?>" style="width:100%;min-height:42px;padding:8px;box-sizing:border-box"></label>
    <label style="flex:1 1 140px;font-weight:700">Número DPS<br><input type="text" name="numero" inputmode="numeric" pattern="[0-9]{1,15}" maxlength="15" required value="<?= fiscal_h($number === '' ? '1' : $number) ?>" style="width:100%;min-height:42px;padding:8px;box-sizing:border-box"></label>
    <button class="btn btn-primary" type="submit" <?= $crossCompany ? 'disabled' : '' ?>>Consultar DPS</button>
  </form>
  <?php if ($error !== ''): ?><div class="msg err"><?= fiscal_h($error) ?></div><?php endif; ?>
  <?php if ($result): ?>
    <div class="msg <?= $result['has_document'] ? 'err' : '' ?>">
      <strong><?= fiscal_h($result['headline']) ?></strong>
      <p>ID: <?= fiscal_h($result['dps']) ?> · HTTP <?= (int)$result['status'] ?></p>
      <p><?= fiscal_h($result['guidance']) ?></p>
      <?php if ($result['key'] !== ''): ?><p>Chave de acesso: <?= fiscal_h($result['key']) ?></p><?php endif; ?>
      <?php if ($result['code'] !== ''): ?><p>Código: <?= fiscal_h($result['code']) ?></p><?php endif; ?>
      <?php if ($result['message'] !== ''): ?><p>Retorno: <?= fiscal_h($result['message']) ?></p><?php endif; ?>
      <?php if ($result['transport_error'] !== ''): ?><p>Comunicação: <?= fiscal_h($result['transport_error']) ?></p><?php endif; ?>
      <p><strong>Esta consulta não autoriza automaticamente uma nova emissão.</strong></p>
    </div>
  <?php endif; ?>
</div>
<?php fiscal_admin_end(); ?>
