<?php
declare(strict_types=1);

/**
 * CRM — envio da proposta comercial pelo WhatsApp conectado.
 * Não executa DDL; reaproveita crm_attendance_moves e whatsapp_send_message().
 * Registra SENT somente após confirmação de aceite do serviço.
 */
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_crm.php';
require_once __DIR__.'/../config/crm_commercial.php';
require_once __DIR__.'/../config/crm_proposal_number.php';
require_once __DIR__.'/../config/whatsapp_service.php';

header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$cid = crm_company_id();
$uid = crm_user_id();
$attendanceId = max(0, (int)($_POST['atendimento'] ?? 0));
$proposalId = max(0, (int)($_POST['id'] ?? 0));
$returnTo = ((string)($_POST['return_to'] ?? '')) === 'proposta' ? 'proposta' : 'tramitar';

$redirect = static function (bool $ok) use ($attendanceId, $proposalId, $returnTo): void {
    $base = $returnTo === 'proposta'
        ? 'crm_proposta.php?atendimento='.$attendanceId.'&id='.$proposalId
        : 'crm_tramitar.php?id='.$attendanceId;
    $base .= $ok ? '&proposal_sent='.$proposalId : '&proposal_send_error=1';
    header('Location: '.$base, true, 303);
    exit;
};

try {
    crm_csrf_check((string)($_POST['csrf'] ?? ''));
    if ($attendanceId <= 0 || $proposalId <= 0 || $cid <= 0 || $uid <= 0) {
        throw new RuntimeException('Identificadores inválidos.');
    }

    $attendance = crm_attendance($pdo, $cid, $attendanceId);
    $proposal = crm_commercial_proposal($pdo, $cid, $attendanceId, $proposalId);
    if (!$attendance || !$proposal) {
        http_response_code(404);
        exit('Proposta não encontrada para esta empresa e atendimento.');
    }

    $phone = crm_commercial_phone((string)($attendance['phone'] ?? ''));
    if ($phone === '') {
        throw new RuntimeException('Cliente sem telefone WhatsApp válido.');
    }

    $cfg = whatsapp_load_config($pdo, $cid);
    if (empty($cfg['enabled'])) {
        throw new RuntimeException('WhatsApp automático desativado nesta empresa.');
    }

    // Mesma URL de visualização já utilizada pelo formulário de propostas.
    $token = (string)($proposal['share_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new RuntimeException('Token de compartilhamento inválido.');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{2,5})?$/D', $host)) {
        throw new RuntimeException('Domínio da aplicação inválido.');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $url = ($https ? 'https://' : 'http://').$host.'/crm_proposta_publica.php?token='.rawurlencode($token);

    $customer = trim((string)($attendance['customer_name'] ?? ''));
    $title = trim((string)($proposal['title'] ?? ''));
    $greeting = $customer !== '' ? 'Olá, '.$customer.'!' : 'Olá!';
    $text = $greeting."\n\nSegue a proposta comercial #".crm_proposal_number($proposalId)
        .($title !== '' ? ' — '.$title : '')
        .".\nVocê pode visualizar a proposta no link:\n".$url;

    // A própria ação de enviar constitui autorização explícita para ativar
    // o link público; o usuário também recebe confirmação antes de postar.
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE crm_proposals SET share_enabled=1 WHERE company_id=? AND attendance_id=? AND id=?')
        ->execute([$cid, $attendanceId, $proposalId]);

    $note = 'Envio por WhatsApp da proposta #'.crm_proposal_number($proposalId)
        .($title !== '' ? ' — '.$title : '');
    $stmt = $pdo->prepare(
        'INSERT INTO crm_attendance_moves
        (company_id,attendance_id,movement_text,lead_temperature,
         message_channel,message_to,message_text,message_status,
         state_result,pending_reason,next_contact_date,responsible_user_id,
         created_by,created_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
    );
    $stmt->execute([
        $cid, $attendanceId, $note, (int)($attendance['lead_temperature'] ?? 20),
        'WHATSAPP', $phone, $text, 'PENDING',
        (string)($attendance['state'] ?? 'PENDENTE'),
        $attendance['pending_reason'] ?? null,
        $attendance['next_contact_date'] ?? null,
        $attendance['responsible_user_id'] ?? null,
        $uid,
    ]);
    $moveId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $sent = false;
    $error = 'Serviço WhatsApp indisponível ou envio recusado.';
    try {
        $sent = whatsapp_send_message($pdo, $cid, $phone, $text, 'crm_proposal', null, $cfg);
    } catch (Throwable $ex) {
        $error = mb_substr($ex->getMessage(), 0, 500, 'UTF-8');
    }

    $pdo->prepare('UPDATE crm_attendance_moves
        SET message_status=?,message_error=?,message_sent_at=?
        WHERE company_id=? AND attendance_id=? AND id=?')
        ->execute([
            $sent ? 'SENT' : 'ERROR',
            $sent ? null : $error,
            $sent ? date('Y-m-d H:i:s') : null,
            $cid, $attendanceId, $moveId,
        ]);
    $redirect($sent);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[Nexa CRM proposal WhatsApp] '.$ex->getMessage());
    $redirect(false);
}
