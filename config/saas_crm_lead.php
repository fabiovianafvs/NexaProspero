<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Atendimento no CRM da empresa faturadora (Próspero Tecnologia)
 * quando uma nova empresa é cadastrada no SaaS.
 *
 * - Um atendimento por empresa SaaS (controle pelo evento CRM_LEAD:<id> em
 *   saas_billing_events), nunca duplica.
 * - Configuração em app_config (company_id 0, chave 'saas_crm_lead'), sem DDL:
 *   status inicial, tipo de atendimento, responsável e temperatura.
 * - Nunca lança exceção para o cadastro: falha fica no log e no evento.
 */

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/saas_subscription_bridge.php';

if (!function_exists('saas_crm_lead_defaults')) {
  function saas_crm_lead_defaults(): array {
    return ['enabled' => true, 'status_id' => 0, 'service_type_id' => 0, 'responsible_user_id' => 0, 'lead_temperature' => 50];
  }
}

if (!function_exists('saas_crm_lead_config')) {
  function saas_crm_lead_config(PDO $pdo): array {
    $cfg = saas_crm_lead_defaults();
    try { $saved = config_store_get($pdo, 0, 'saas_crm_lead'); if (is_array($saved)) $cfg = array_merge($cfg, $saved); } catch (Throwable $e) {}
    return $cfg;
  }
}

if (!function_exists('saas_crm_lead_ready')) {
  function saas_crm_lead_ready(PDO $pdo): bool {
    return saas_sub_bridge_table_exists($pdo, 'crm_attendances') && saas_sub_bridge_table_exists($pdo, 'crm_attendance_moves');
  }
}

if (!function_exists('saas_crm_lead_options')) {
  /** Status, tipos e usuários ativos do CRM da empresa faturadora (para a configuração). */
  function saas_crm_lead_options(PDO $pdo, int $billingCompanyId): array {
    $out = ['statuses' => [], 'types' => [], 'users' => []];
    if ($billingCompanyId <= 0 || !saas_crm_lead_ready($pdo)) return $out;
    try {
      $st = $pdo->prepare('SELECT id,name FROM crm_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,name'); $st->execute([$billingCompanyId]); $out['statuses'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $st = $pdo->prepare('SELECT id,name FROM crm_service_types WHERE company_id=? AND active=1 ORDER BY sort_order,name'); $st->execute([$billingCompanyId]); $out['types'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $st = $pdo->prepare("SELECT id,name FROM users WHERE company_id=? AND is_active=1 AND role IN ('company_admin','company_user') ORDER BY name"); $st->execute([$billingCompanyId]); $out['users'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    return $out;
  }
}

if (!function_exists('saas_crm_lead_save_config')) {
  function saas_crm_lead_save_config(PDO $pdo, array $input, int $billingCompanyId): array {
    $opts = saas_crm_lead_options($pdo, $billingCompanyId);
    $valid = static function (array $rows, int $id): int { foreach ($rows as $r) if ((int)$r['id'] === $id) return $id; return 0; };
    $cfg = [
      'enabled' => !empty($input['crm_enabled']),
      'status_id' => $valid($opts['statuses'], (int)($input['crm_status_id'] ?? 0)),
      'service_type_id' => $valid($opts['types'], (int)($input['crm_service_type_id'] ?? 0)),
      'responsible_user_id' => $valid($opts['users'], (int)($input['crm_responsible_user_id'] ?? 0)),
      'lead_temperature' => max(0, min(100, (int)($input['crm_lead_temperature'] ?? 50))),
    ];
    config_store_set($pdo, 0, 'saas_crm_lead', $cfg);
    return $cfg;
  }
}

if (!function_exists('saas_crm_lead_attendance_id')) {
  /** Atendimento já aberto para a empresa SaaS (0 se não houver). */
  function saas_crm_lead_attendance_id(PDO $pdo, int $saasCompanyId): int {
    if (!saas_sub_bridge_table_exists($pdo, 'saas_billing_events')) return 0;
    try {
      $st = $pdo->prepare("SELECT origin_id FROM saas_billing_events WHERE event_key=? AND event_type='CRM_LEAD_OPENED' LIMIT 1");
      $st->execute(['CRM_LEAD:' . $saasCompanyId]);
      return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('saas_crm_lead_open')) {
  /**
   * Abre o atendimento comercial da nova empresa. $context: origin ('Direto' ou
   * nome da revenda), login (e-mail de acesso), plan (nome do plano).
   */
  function saas_crm_lead_open(PDO $pdo, int $saasCompanyId, array $context = []): array {
    try {
      $cfg = saas_crm_lead_config($pdo);
      if (empty($cfg['enabled'])) return ['ok' => false, 'skipped' => true, 'message' => 'Atendimento automático no CRM desativado.'];
      if (!saas_crm_lead_ready($pdo)) return ['ok' => false, 'skipped' => true, 'message' => 'Módulo CRM não instalado na empresa faturadora.'];
      $bridge = saas_sub_bridge_config($pdo);
      $billingCompanyId = (int)($bridge['billing_company_id'] ?? 0);
      if ($billingCompanyId <= 0) return ['ok' => false, 'skipped' => true, 'message' => 'Empresa faturadora não configurada.'];
      $existing = saas_crm_lead_attendance_id($pdo, $saasCompanyId);
      if ($existing > 0) return ['ok' => true, 'existing' => true, 'attendance_id' => $existing];

      $company = saas_sub_bridge_company($pdo, $saasCompanyId);
      if (!$company) throw new RuntimeException('Empresa SaaS não localizada.');
      $sub = saas_sub_bridge_latest_subscription($pdo, $saasCompanyId) ?: [];
      $link = saas_sub_bridge_link($pdo, $saasCompanyId) ?: [];
      $opts = saas_crm_lead_options($pdo, $billingCompanyId);

      $statusId = (int)$cfg['status_id'] ?: (int)($opts['statuses'][0]['id'] ?? 0);
      $typeId = (int)$cfg['service_type_id'] ?: (int)($opts['types'][0]['id'] ?? 0);
      $responsible = (int)$cfg['responsible_user_id'];
      if ($responsible <= 0) {
        $st = $pdo->prepare("SELECT id FROM users WHERE company_id=? AND is_active=1 AND role='company_admin' ORDER BY id LIMIT 1");
        $st->execute([$billingCompanyId]);
        $responsible = (int)($st->fetchColumn() ?: 0);
      }
      if ($responsible <= 0) throw new RuntimeException('Defina o responsável pelos atendimentos em Central SaaS > Assinaturas Nexa.');

      $personId = (int)($link['person_id'] ?? 0);
      if ($personId > 0) {
        $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([$billingCompanyId, $personId]);
        if (!(int)$st->fetchColumn()) $personId = 0;
      }
      $isPf = strtolower((string)($company['person_type'] ?? 'pj')) === 'pf';
      $document = preg_replace('/\D+/', '', (string)($isPf ? ($company['cpf'] ?? '') : ($company['cnpj'] ?? ''))) ?? '';
      $phone = trim((string)($company['whatsapp'] ?? '')) ?: trim((string)($company['celular'] ?? ''));
      $email = trim((string)($company['email'] ?? ''));
      $address = trim(implode(', ', array_filter([(string)($company['address'] ?? ''), (string)($company['number'] ?? ''), (string)($company['district'] ?? ''), (string)($company['city'] ?? ''), (string)($company['state'] ?? '')], static fn($v) => trim($v) !== '')));
      $trialEnd = trim((string)($sub['ends_at'] ?? ($link['trial_ends_at'] ?? '')));
      $trialEndBr = $trialEnd !== '' ? date('d/m/Y', strtotime($trialEnd) ?: time()) : '—';
      $origin = trim((string)($context['origin'] ?? '')) ?: 'Direto';
      $plan = trim((string)($context['plan'] ?? ($sub['plan_name'] ?? '')));
      $host = trim((string)($company['subdomain'] ?? $company['slug'] ?? ''));

      $request = implode("\n", array_filter([
        'Nova empresa cadastrada no SaaS (período de teste).',
        'Empresa: ' . (string)($company['name'] ?? ''),
        $host !== '' ? 'Endereço: ' . $host . '.nexaprospero.com.br' : '',
        'Origem: ' . $origin,
        $plan !== '' ? 'Plano: ' . $plan : '',
        'Teste até: ' . $trialEndBr,
        trim((string)($context['login'] ?? '')) !== '' ? 'Login do administrador: ' . trim((string)$context['login']) : '',
        'Para aprovar a contratação, use "Ativar assinatura" na Central SaaS (empresa #' . $saasCompanyId . ').',
      ]));
      $temperature = max(0, min(100, (int)$cfg['lead_temperature']));
      $nextContact = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
      $pending = 'Cliente em período de teste.';

      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO crm_attendances(company_id,person_id,customer_name,phone,document,email,address,status_id,service_type_id,request_text,lead_temperature,state,pending_reason,next_contact_date,responsible_user_id,created_by,closed_by,closed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW(),NOW())')
        ->execute([$billingCompanyId, $personId ?: null, mb_substr((string)($company['name'] ?? ''), 0, 180), $phone ?: null, $document ?: null, $email ?: null, $address !== '' ? mb_substr($address, 0, 500) : null, $statusId ?: null, $typeId ?: null, $request, $temperature, 'PENDENTE', $pending, $nextContact, $responsible, null]);
      $attendanceId = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,pending_reason,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$billingCompanyId, $attendanceId, 'Atendimento aberto automaticamente pelo cadastro da empresa no SaaS.', $temperature, 'PENDENTE', $pending, $nextContact, $responsible, null]);
      $pdo->commit();

      saas_sub_bridge_event($pdo, 'CRM_LEAD:' . $saasCompanyId, 'CRM_LEAD_OPENED', $saasCompanyId, (int)($link['id'] ?? 0) ?: null, $billingCompanyId, 'CRM_ATTENDANCE', $attendanceId, ['origin' => $origin], true, null);
      return ['ok' => true, 'attendance_id' => $attendanceId];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[Nexa][SaaS CRM] Empresa ' . $saasCompanyId . ': ' . $e->getMessage());
      return ['ok' => false, 'message' => $e->getMessage()];
    }
  }
}

if (!function_exists('saas_crm_lead_register_move')) {
  /** Registra um andamento no atendimento (ex.: assinatura ativada) e, se pedido, conclui. */
  function saas_crm_lead_register_move(PDO $pdo, int $saasCompanyId, string $text, bool $complete = false, ?int $userId = null): void {
    try {
      $attendanceId = saas_crm_lead_attendance_id($pdo, $saasCompanyId);
      if ($attendanceId <= 0) return;
      $billingCompanyId = (int)(saas_sub_bridge_config($pdo)['billing_company_id'] ?? 0);
      $st = $pdo->prepare('SELECT lead_temperature,responsible_user_id FROM crm_attendances WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$billingCompanyId, $attendanceId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) return;
      $state = $complete ? 'CONCLUIDO' : 'PENDENTE';
      if ($complete) {
        $pdo->prepare("UPDATE crm_attendances SET state='CONCLUIDO',pending_reason=NULL,closed_by=?,closed_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$userId, $billingCompanyId, $attendanceId]);
      }
      $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,pending_reason,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,NULL,NULL,?,?,NOW())')
        ->execute([$billingCompanyId, $attendanceId, mb_substr($text, 0, 2000), (int)$row['lead_temperature'], $state, (int)$row['responsible_user_id'], $userId]);
    } catch (Throwable $e) {
      error_log('[Nexa][SaaS CRM] Andamento empresa ' . $saasCompanyId . ': ' . $e->getMessage());
    }
  }
}
