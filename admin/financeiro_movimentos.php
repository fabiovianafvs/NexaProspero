<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/cash_operator_guard.php';
require_once __DIR__ . '/../config/screen_performance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
if (!function_exists('finance_movement_is_settlement_history')) {
  function finance_movement_is_settlement_history(PDO $pdo, int $companyId, int $movementId): bool {
    if ($movementId<=0) return false;
    if (finance_column_exists($pdo,'finance_settlements','reversal_movement_id')) {
      $st=$pdo->prepare('SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND (movement_id=? OR reversal_movement_id=?)');
      $st->execute([$companyId,$movementId,$movementId]); if((int)$st->fetchColumn()>0)return true;
    } else {
      $st=$pdo->prepare('SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND movement_id=?');
      $st->execute([$companyId,$movementId]); if((int)$st->fetchColumn()>0)return true;
    }
    if(finance_table_exists($pdo,'finance_settlement_payments')){$st=$pdo->prepare('SELECT COUNT(*) FROM finance_settlement_payments WHERE company_id=? AND movement_id=?');$st->execute([$companyId,$movementId]);if((int)$st->fetchColumn()>0)return true;}
    $st=$pdo->prepare("SELECT COUNT(*) FROM finance_movements WHERE company_id=? AND id=? AND origin_type='ESTORNO_BAIXA'");$st->execute([$companyId,$movementId]);return (int)$st->fetchColumn()>0;
  }
}
$financeCrossReadOnly = function_exists('finance_multi_company_readonly') && finance_multi_company_readonly($pdo);
$financeCrossMovementScope = $financeCrossContext
  && function_exists('finance_multi_company_movement_scope_enabled')
  && finance_multi_company_movement_scope_enabled($pdo);
$financeCrossMovementWrite = $financeCrossMovementScope
  && function_exists('finance_multi_company_movement_write_enabled')
  && finance_multi_company_movement_write_enabled($pdo);
$financeCrossMovementCancel = $financeCrossMovementScope
  && function_exists('finance_multi_company_movement_cancel_enabled')
  && finance_multi_company_movement_cancel_enabled($pdo);
$financeMcCsrf = $financeCrossMovementScope && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
$financeMcCtx = $financeCrossMovementScope && function_exists('multi_company_context_request_token')
  ? multi_company_context_request_token()
  : '';
$err = '';
$postAction = '';

// V11.54: ajuste manual da data de entrada no banco, sem alterar o titulo/baixa.
// Token especifico protege esta acao em operacao normal e no F11 (além do token mc_csrf).
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_fin_mov_date_csrf'])) {
  $_SESSION['nexa_fin_mov_date_csrf'] = bin2hex(random_bytes(24));
}
$financeDateCsrf = session_status() === PHP_SESSION_ACTIVE
  ? (string)($_SESSION['nexa_fin_mov_date_csrf'] ?? '') : '';
// Habilitacao explicita para homologacao: por padrao, a funcao sensivel fica desligada.
// O arquivo de habilitacao sera instalado SEPARADAMENTE, quando o gestor autorizar.
$financeDateFeaturePath = __DIR__ . '/../config/nexa_fin_mov_date_feature.php';
$financeDateFeatureEnabled = is_file($financeDateFeaturePath) && (require $financeDateFeaturePath) === true;

if (!function_exists('finance_mov_date_adjustable_origin')) {
  function finance_mov_date_adjustable_origin($origin): bool {
    return in_array(strtoupper(trim((string)$origin)), ['RECEBER','PAGAR','BOLETO_TARIFA'], true);
  }
}
if (!function_exists('finance_mov_date_is_valid')) {
  function finance_mov_date_is_valid(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $date;
  }
}
if (!function_exists('finance_mov_date_blocked_by_links')) {
  function finance_mov_date_blocked_by_links(PDO $pdo,int $companyId,int $movementId): ?string {
    // Nao reescrever movimentos cujo valor ja foi conferido em conciliacao OFX.
    if (finance_table_exists($pdo,'finance_bank_reconciliation_items')
        && finance_column_exists($pdo,'finance_bank_reconciliation_items','movement_id')) {
      $st=$pdo->prepare("SELECT COUNT(*) FROM finance_bank_reconciliation_items WHERE company_id=? AND movement_id=? AND status='CONCILIADO'");
      $st->execute([$companyId,$movementId]);
      if ((int)$st->fetchColumn()>0) return 'Movimento ja conciliado com extrato OFX. Desfaca a conciliacao pelo procedimento homologado antes de ajustar.';
    }
    // O livro de caixa operacional possui fechamento proprio; nao alterar um movimento ja ligado a operacao de caixa.
    if (finance_table_exists($pdo,'gestao_cash_operations')
        && finance_column_exists($pdo,'gestao_cash_operations','movement_id')) {
      $st=$pdo->prepare('SELECT COUNT(*) FROM gestao_cash_operations WHERE company_id=? AND movement_id=?');
      $st->execute([$companyId,$movementId]);
      if ((int)$st->fetchColumn()>0) return 'Movimento vinculado a operacao do caixa operacional. O ajuste isolado poderia divergir do fechamento do caixa.';
    }
    if (finance_table_exists($pdo,'finance_settlements') && finance_column_exists($pdo,'finance_settlements','status')) {
      $st=$pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND movement_id=? AND COALESCE(status,'ATIVO')<>'ATIVO'");
      $st->execute([$companyId,$movementId]);
      if ((int)$st->fetchColumn()>0) return 'Movimento associado a baixa estornada ou cancelada; ajuste bloqueado.';
      if (finance_table_exists($pdo,'finance_settlement_payments')) {
        $st=$pdo->prepare("SELECT COUNT(*) FROM finance_settlement_payments sp INNER JOIN finance_settlements s ON s.company_id=sp.company_id AND s.id=sp.settlement_id WHERE sp.company_id=? AND sp.movement_id=? AND COALESCE(s.status,'ATIVO')<>'ATIVO'");
        $st->execute([$companyId,$movementId]);
        if ((int)$st->fetchColumn()>0) return 'Movimento de baixa estornada ou cancelada; ajuste bloqueado.';
      }
    }
    return null;
  }
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? 'save');
    $action = $postAction;

    if ($financeCrossContext) {
      if (!$financeCrossMovementScope) {
        throw new RuntimeException('V11.23: o Movimento Financeiro ainda não está homologado para gravação nesta empresa pelo F11.');
      }
      if (!in_array($action, ['save','delete','cancel','adjust_date'], true)) {
        throw new RuntimeException('Ação não autorizada no Movimento Financeiro Multiempresa.');
      }
      if (!function_exists('multi_company_context_csrf_valid')
          || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
        throw new RuntimeException('Sessão Multiempresa expirada ou token de segurança inválido. Atualize a tela e tente novamente.');
      }
      if (in_array($action, ['save','delete','adjust_date'], true) && !$financeCrossMovementWrite) {
        throw new RuntimeException('Seu perfil não possui permissão efetiva para lançar ou editar movimento manual nesta empresa.');
      }
      if ($action === 'cancel' && !$financeCrossMovementCancel) {
        throw new RuntimeException('Seu perfil não possui permissão efetiva para cancelar movimentos nesta empresa.');
      }
    } else {
      if (in_array($action, ['save','delete','adjust_date','transfer'], true) && function_exists('access_has_permission') && !access_has_permission($pdo, $cid, 'financeiro_movimento_manual')) {
        throw new RuntimeException('Seu usuário não possui permissão para lançar ou editar movimento manual.');
      }
      if ($action === 'cancel' && function_exists('access_has_permission') && !access_has_permission($pdo, $cid, 'financeiro_cancelar_titulos')) {
        throw new RuntimeException('Seu usuário não possui permissão para cancelar movimentos.');
      }
    }

    if ($action === 'adjust_date') {
      if (!$financeDateFeatureEnabled) throw new RuntimeException('Ajuste de data financeira nao habilitado para esta instalacao.');
      if ($financeDateCsrf === '' || !hash_equals($financeDateCsrf, (string)($_POST['fm_date_csrf'] ?? ''))) {
        throw new RuntimeException('Token de seguranca invalido. Reabra o ajuste da data.');
      }
      $dateMovementId=(int)($_POST['id'] ?? 0);
      $oldDateExpected=trim((string)($_POST['old_date'] ?? ''));
      $newDate=trim((string)($_POST['new_movement_date'] ?? ''));
      $dateReason=preg_replace('/\s+/u',' ',trim((string)($_POST['date_reason'] ?? ''))) ?? '';
      if ($dateMovementId<=0) throw new RuntimeException('Movimento financeiro nao informado.');
      if (!finance_mov_date_is_valid($newDate)) throw new RuntimeException('Informe uma data bancaria valida.');
      if (!finance_mov_date_is_valid($oldDateExpected)) throw new RuntimeException('Data anterior invalida. Reabra o movimento.');
      if (mb_strlen($dateReason,'UTF-8')<10 || mb_strlen($dateReason,'UTF-8')>250) {
        throw new RuntimeException('Informe o motivo do ajuste (10 a 250 caracteres). Ex.: credito no extrato em 28/10, cliente pagou em 27/10.');
      }
      $pdo->beginTransaction();
      try {
        $st=$pdo->prepare('SELECT * FROM finance_movements WHERE company_id=? AND id=? FOR UPDATE');
        $st->execute([$cid,$dateMovementId]);
        $mov=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$mov || !finance_mov_date_adjustable_origin($mov['origin_type'] ?? '')) {
          throw new RuntimeException('Somente baixas RECEBER/PAGAR e tarifas bancarias podem ter a data ajustada por esta opcao.');
        }
        $movStatus=strtoupper(trim((string)($mov['status'] ?? 'ATIVO')));
        if (!in_array($movStatus, ['','ATIVO'], true)) {
          throw new RuntimeException('Somente movimentos ativos podem ter a data alterada.');
        }
        if ((string)($mov['movement_date'] ?? '') !== $oldDateExpected) {
          throw new RuntimeException('Outro processo alterou a data deste movimento. Reabra a tela para conferir.');
        }
        $linkedBank=(int)($mov['bank_account_id'] ?? 0);
        if ($financeCrossMovementScope) {
          if ($linkedBank>0 && function_exists('finance_assert_cash_account_effective')) {
            finance_assert_cash_account_effective($pdo,$cid,$linkedBank,'Conta/Caixa deste movimento fora do contexto selecionado.');
          }
        } elseif (function_exists('access_user_cash_account_ids') && function_exists('access_is_company_master') && !access_is_company_master()) {
          $permittedBanks=array_map('intval',access_user_cash_account_ids($pdo,$cid));
          if ($linkedBank<=0 || !in_array($linkedBank,$permittedBanks,true)) {
            throw new RuntimeException('Usuario sem permissao para a conta bancaria vinculada ao movimento.');
          }
        }
        $blockingReason=finance_mov_date_blocked_by_links($pdo,$cid,$dateMovementId);
        if ($blockingReason!==null) throw new RuntimeException($blockingReason);
        if ($newDate===$oldDateExpected) throw new RuntimeException('A nova data e igual a data atual.');
        $uid=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
        if ($uid<=0) throw new RuntimeException('Nao foi possivel identificar o usuario responsavel pelo ajuste.');
        $auditLine='[AJUSTE_DATA_FINANCEIRA] '.date('Y-m-d H:i:s').' usuario='.$uid
          .' empresa='.$cid.' '.$oldDateExpected.' -> '.$newDate.' motivo: '.$dateReason;
        $sep=trim((string)($mov['notes']??''))==='' ? '' : "\n";
        // Audita no proprio movimento para evitar alteracoes no schema do ERP em producao.
        $st=$pdo->prepare("UPDATE finance_movements SET movement_date=?,notes=CONCAT(COALESCE(notes,''),?),updated_at=NOW() WHERE company_id=? AND id=? AND movement_date=?");
        $st->execute([$newDate,$sep.$auditLine,$cid,$dateMovementId,$oldDateExpected]);
        if ($st->rowCount()!==1) throw new RuntimeException('Nao foi possivel aplicar a alteracao; tente novamente.');
        $pdo->commit();
        error_log('[NEXA MOV DATA] empresa='.$cid.' movimento='.$dateMovementId.' usuario='.$uid.' '.$oldDateExpected.' -> '.$newDate);
        finance_redirect('financeiro_movimentos.php?ok='.rawurlencode('Data do movimento financeiro atualizada. Titulo e data do pagamento permanecem inalterados.'));
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
    }

    if ($action === 'transfer') {
      // Transferência entre contas: débito na origem e crédito no destino, ligados
      // entre si (origin_type TRANSFERENCIA, origin_id = movimento par).
      $fromBank = (int)($_POST['from_bank_account_id'] ?? 0);
      $toBank = (int)($_POST['to_bank_account_id'] ?? 0);
      $fromChart = (int)($_POST['from_chart_account_id'] ?? 0);
      $toChart = (int)($_POST['to_chart_account_id'] ?? 0);
      $trCost = (int)($_POST['cost_center_id'] ?? 0) ?: null;
      $trAmount = finance_money_in($_POST['amount'] ?? 0);
      $trDate = finance_date_or_null($_POST['movement_date'] ?? '') ?: date('Y-m-d');
      $trDesc = trim((string)($_POST['description'] ?? ''));
      $trNotes = trim((string)($_POST['notes'] ?? ''));
      if ($fromBank <= 0 || $toBank <= 0) throw new RuntimeException('Informe a conta de origem e a conta de destino.');
      if ($fromBank === $toBank) throw new RuntimeException('A conta de origem e a de destino devem ser diferentes.');
      if ($trAmount <= 0) throw new RuntimeException('Informe o valor da transferência.');
      $trBanks = [];
      foreach ([$fromBank, $toBank] as $b) {
        $row = finance_query_one($pdo, 'SELECT id, name FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1', [$cid, $b]);
        if (!$row) throw new RuntimeException('Conta/Caixa inexistente ou inativo.');
        if (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo, $cid, $b);
        if (function_exists('nexa_cash_assert_operator')) nexa_cash_assert_operator($pdo, $cid, $b, (int)(auth_user_id() ?? 0));
        $trBanks[$b] = (string)$row['name'];
      }
      foreach ([[$fromChart, 'DEBITO', 'origem'], [$toChart, 'CREDITO', 'destino']] as [$chId, $mt, $side]) {
        $ch = finance_query_one($pdo, 'SELECT is_analytic, movement_type FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1', [$cid, $chId]);
        if (!$ch || (int)$ch['is_analytic'] !== 1) throw new RuntimeException('Selecione uma conta analítica no plano de contas de ' . $side . '.');
        if (!in_array(strtoupper((string)$ch['movement_type']), [$mt, 'AMBOS'], true)) throw new RuntimeException('O plano de contas de ' . $side . ' não aceita ' . ($mt === 'DEBITO' ? 'débito' : 'crédito') . '.');
      }
      if (!finance_validate_analytic_cost($pdo, $cid, $trCost)) throw new RuntimeException('Selecione um centro de custo analítico.');
      $baseDesc = $trDesc !== '' ? $trDesc : 'Transferência';
      $pdo->beginTransaction();
      try {
        $ins = $pdo->prepare('INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([$cid, $trDate, 'DEBITO', null, $fromChart, $trCost, $fromBank, 'TRANSFERENCIA', null, $baseDesc . ' para ' . $trBanks[$toBank], '', $trAmount, $trNotes !== '' ? $trNotes : null, 'ATIVO']);
        $outId = (int)$pdo->lastInsertId();
        $ins->execute([$cid, $trDate, 'CREDITO', null, $toChart, $trCost, $toBank, 'TRANSFERENCIA', $outId, $baseDesc . ' de ' . $trBanks[$fromBank], 'TRF-' . $outId, $trAmount, $trNotes !== '' ? $trNotes : null, 'ATIVO']);
        $inId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE finance_movements SET origin_id=?, document_number=? WHERE company_id=? AND id=?")->execute([$inId, 'TRF-' . $outId, $cid, $outId]);
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
      finance_redirect('financeiro_movimentos.php?ok=' . rawurlencode('Transferência TRF-' . $outId . ' lançada: R$ ' . finance_money_br($trAmount) . ' de ' . $trBanks[$fromBank] . ' para ' . $trBanks[$toBank] . '.'));
    }

    if ($action === 'cancel') {
      $id = (int)($_POST['id'] ?? 0);
      $reason = trim((string)($_POST['cancel_reason'] ?? ''));
      if ($id <= 0) throw new RuntimeException('Movimento não informado.');
      if ($reason === '') throw new RuntimeException('Informe o motivo do cancelamento.');
      $mov = finance_query_one($pdo, 'SELECT id, status, origin_type, origin_id FROM finance_movements WHERE company_id=? AND id=?', [$cid, $id]);
      if (!$mov) throw new RuntimeException('Movimento não encontrado.');
      if (strtoupper((string)($mov['origin_type'] ?? '')) === 'TRANSFERENCIA') {
        // Cancela os dois lados da transferência juntos.
        if ((string)($mov['status'] ?? 'ATIVO') === 'CANCELADO') throw new RuntimeException('Esta transferência já está cancelada.');
        $pairId = (int)($mov['origin_id'] ?? 0);
        $pdo->prepare("UPDATE finance_movements SET status='CANCELADO', canceled_at=NOW(), canceled_reason=?, updated_at=NOW() WHERE company_id=? AND origin_type='TRANSFERENCIA' AND id IN (?,?) AND COALESCE(status,'ATIVO')<>'CANCELADO'")->execute([$reason, $cid, $id, $pairId]);
        finance_redirect('financeiro_movimentos.php?ok=' . rawurlencode('Transferência cancelada (saída e entrada).'));
      }
      if (finance_movement_is_settlement_history($pdo,$cid,$id)) throw new RuntimeException('Movimento vinculado ao histórico de uma baixa. Faça o estorno pela própria baixa.');
      if ((string)($mov['status'] ?? 'ATIVO') === 'CANCELADO') throw new RuntimeException('Este movimento já está cancelado.');
      $pdo->prepare("UPDATE finance_movements SET status='CANCELADO', canceled_at=NOW(), canceled_reason=?, updated_at=NOW() WHERE company_id=? AND id=?")->execute([$reason, $cid, $id]);
      finance_redirect('financeiro_movimentos.php?ok=' . rawurlencode('Movimento cancelado.'));
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $pdo->prepare("UPDATE finance_movements SET status='CANCELADO', canceled_at=NOW(), canceled_reason='Cancelamento manual', updated_at=NOW() WHERE company_id=? AND id=? AND origin_type='MANUAL' AND COALESCE(status,'ATIVO') <> 'CANCELADO'")->execute([$cid, $id]);
      }
      finance_redirect('financeiro_movimentos.php?ok=' . rawurlencode('Movimento manual cancelado.'));
    }

    $id = (int)($_POST['id'] ?? 0);
    $type = in_array((string)($_POST['type'] ?? ''), ['CREDITO','DEBITO'], true) ? (string)$_POST['type'] : 'CREDITO';
    $chartId = (int)($_POST['chart_account_id'] ?? 0);
    $costId = (int)($_POST['cost_center_id'] ?? 0) ?: null;
    if (!finance_validate_analytic_chart($pdo, $cid, $chartId)) throw new RuntimeException('Selecione uma conta analítica no plano de contas.');
    if (!finance_validate_analytic_cost($pdo, $cid, $costId)) throw new RuntimeException('Selecione um centro de custo analítico.');
    $amount = finance_money_in($_POST['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Informe o valor.');

    $movementDate = finance_date_or_null($_POST['movement_date'] ?? '') ?: date('Y-m-d');
    $personId = (int)($_POST['person_id'] ?? 0) ?: null;
    if ($financeCrossMovementScope && $personId !== null
        && (!function_exists('finance_validate_person_role') || !finance_validate_person_role($pdo, $cid, $personId, ''))) {
      throw new RuntimeException('A pessoa selecionada não pertence à empresa escolhida no F11 ou está inativa.');
    }
    $bankId = (int)($_POST['bank_account_id'] ?? 0) ?: null;
    if (function_exists('finance_assert_cash_account_effective')) {
      finance_assert_cash_account_effective($pdo, $cid, $bankId, 'O Caixa/Banco selecionado não pertence à empresa escolhida no F11 ou está inativo.');
    } elseif (function_exists('access_assert_cash_account')) {
      access_assert_cash_account($pdo, $cid, $bankId);
    }
    // V11.61: conta tipo CAIXA vinculada a outro operador não pode receber lançamentos manuais.
    if ($bankId && function_exists('nexa_cash_assert_operator')) {
      nexa_cash_assert_operator($pdo, $cid, (int)$bankId, (int)(auth_user_id() ?? 0));
    }
    $description = trim((string)($_POST['description'] ?? ''));
    $documentNumber = trim((string)($_POST['document_number'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($id > 0) {
      $mov = finance_query_one($pdo, 'SELECT id, status, origin_type FROM finance_movements WHERE company_id=? AND id=?', [$cid, $id]);
      if (!$mov) throw new RuntimeException('Movimento não encontrado para edição.');
      if (strtoupper(trim((string)($mov['origin_type'] ?? ''))) !== 'MANUAL') {
        throw new RuntimeException('Movimento gerado por outra rotina não pode ser editado no livro-caixa. Para ajustar vencimento ou valor do título, abra Contas a Receber ou Contas a Pagar na origem.');
      }
      if (finance_movement_is_settlement_history($pdo,$cid,$id)) throw new RuntimeException('Movimento vinculado ao histórico de uma baixa e não pode ser editado diretamente.');
      if ((string)($mov['status'] ?? 'ATIVO') === 'CANCELADO') throw new RuntimeException('Movimento cancelado não pode ser editado.');
      $pdo->prepare('UPDATE finance_movements SET movement_date=?, type=?, person_id=?, chart_account_id=?, cost_center_id=?, bank_account_id=?, description=?, document_number=?, amount=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([
        $movementDate,
        $type,
        $personId,
        $chartId,
        $costId,
        $bankId,
        $description,
        $documentNumber,
        $amount,
        $notes,
        $cid,
        $id,
      ]);
      finance_redirect('financeiro_movimentos.php?ok=' . rawurlencode('Movimento atualizado.'));
    }

    $pdo->prepare('INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
      $cid,
      $movementDate,
      $type,
      $personId,
      $chartId,
      $costId,
      $bankId,
      'MANUAL',
      null,
      $description,
      $documentNumber,
      $amount,
      $notes,
      'ATIVO',
    ]);
    finance_redirect('financeiro_movimentos.php?ok=' . rawurlencode('Movimento lançado.'));
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$typeFilter = trim((string)($_GET['type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'ATIVO'));
if (!in_array($statusFilter, ['ATIVO','CANCELADO',''], true)) $statusFilter = 'ATIVO';
$q = trim((string)($_GET['q'] ?? ''));
$bankFilter = (int)($_GET['bank_account_id'] ?? 0);
$movementPeriodCfg = nexa_screen_performance_get($pdo, $cid);
$movementInitialPeriod = nexa_screen_performance_period((int)($movementPeriodCfg['movements_days'] ?? 30));
$ini = finance_date_or_null($_GET['ini'] ?? '') ?: $movementInitialPeriod['from'];
$fim = finance_date_or_null($_GET['fim'] ?? '') ?: $movementInitialPeriod['to'];

$editId = (int)($_GET['edit'] ?? 0);
$cancelId = (int)($_GET['cancel'] ?? 0);
$adjustDateId = (int)($_GET['adjust_date'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $postAction === 'adjust_date') $adjustDateId = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $postAction === 'save') $editId = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $postAction === 'cancel') $cancelId = (int)($_POST['id'] ?? 0);

$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save');
$showCancel = $cancelId > 0;

$where = ['m.company_id=?', 'm.movement_date BETWEEN ? AND ?'];
$params = [$cid, $ini, $fim];
if (in_array($typeFilter, ['CREDITO','DEBITO'], true)) { $where[] = 'm.type=?'; $params[] = $typeFilter; }
if ($statusFilter === 'ATIVO') $where[] = "(m.status IS NULL OR m.status='' OR m.status='ATIVO')";
if ($statusFilter === 'CANCELADO') $where[] = "m.status='CANCELADO'";
if ($q !== '') {
  $where[] = '(m.description LIKE ? OR m.document_number LIKE ? OR p.name LIKE ? OR ca.name LIKE ? OR ca.code LIKE ? OR m.canceled_reason LIKE ?)';
  $like = nexa_search_like($q);
  array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($bankFilter > 0) {
  if ($financeCrossMovementScope) {
    if (!function_exists('finance_validate_bank_account') || !finance_validate_bank_account($pdo, $cid, $bankFilter)) {
      throw new RuntimeException('O Caixa/Banco filtrado não pertence à empresa escolhida no F11 ou está inativo.');
    }
  } elseif (function_exists('access_assert_cash_account')) {
    access_assert_cash_account($pdo, $cid, $bankFilter);
  }
  $where[] = 'm.bank_account_id = ?';
  $params[] = $bankFilter;
} elseif (!$financeCrossMovementScope && function_exists('access_user_cash_account_ids') && !access_is_company_master()) {
  $allowedBankIds = access_user_cash_account_ids($pdo, $cid);
  if (!$allowedBankIds) {
    $where[] = '0=1';
  } else {
    $where[] = 'm.bank_account_id IN (' . implode(',', array_fill(0, count($allowedBankIds), '?')) . ')';
    foreach ($allowedBankIds as $allowedBankId) $params[] = (int)$allowedBankId;
  }
}

$rows = finance_query_all($pdo, 'SELECT m.*, p.name AS person_name, ca.code, ca.name AS account_name, cc.name AS cost_name, b.name AS bank_name FROM finance_movements m LEFT JOIN finance_people p ON p.company_id=m.company_id AND p.id=m.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=m.company_id AND ca.id=m.chart_account_id LEFT JOIN finance_cost_centers cc ON cc.company_id=m.company_id AND cc.id=m.cost_center_id LEFT JOIN finance_bank_accounts b ON b.company_id=m.company_id AND b.id=m.bank_account_id WHERE ' . implode(' AND ', $where) . ' ORDER BY m.movement_date DESC, m.id DESC LIMIT 700', $params);
$people = finance_people_options($pdo, $cid, '');
$charts = finance_chart_options($pdo, $cid, '');
$costs = finance_cost_center_options($pdo, $cid);
$banks = $financeCrossMovementScope
  ? finance_query_all($pdo, 'SELECT id, name, type FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name ASC', [$cid])
  : finance_bank_options($pdo, $cid);

$edit = $editId ? finance_query_one($pdo, 'SELECT * FROM finance_movements WHERE company_id=? AND id=?', [$cid, $editId]) : null;
if ($edit && strtoupper(trim((string)($edit['origin_type'] ?? ''))) !== 'MANUAL') {
  $edit = null;
  $showForm = false;
  if ($err === '') $err = 'Este movimento foi gerado por outra rotina. Altere o título de origem (Contas a Receber/Pagar), quando permitido.';
}
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $postAction === 'save') $edit = $_POST;
$adjustMovement = $adjustDateId ? finance_query_one($pdo, 'SELECT m.*, b.name AS bank_name FROM finance_movements m LEFT JOIN finance_bank_accounts b ON b.company_id=m.company_id AND b.id=m.bank_account_id WHERE m.company_id=? AND m.id=?', [$cid,$adjustDateId]) : null;
// Restringe a abertura direta via URL aos mesmos caixas permitidos no filtro.
if ($adjustMovement && !$financeCrossMovementScope && function_exists('access_user_cash_account_ids')
    && function_exists('access_is_company_master') && !access_is_company_master()) {
  $allowIds=array_map('intval',access_user_cash_account_ids($pdo,$cid));
  if (!in_array((int)($adjustMovement['bank_account_id']??0),$allowIds,true)) $adjustMovement=null;
}
if (!$financeDateFeatureEnabled) $adjustMovement=null;
if ($adjustMovement && (!finance_mov_date_adjustable_origin($adjustMovement['origin_type'] ?? '')
    || !in_array(strtoupper(trim((string)($adjustMovement['status']??'ATIVO'))),['','ATIVO'],true))) $adjustMovement=null;
$cancelMovement = $cancelId ? finance_query_one($pdo, 'SELECT m.*, p.name AS person_name, ca.code, ca.name AS account_name FROM finance_movements m LEFT JOIN finance_people p ON p.company_id=m.company_id AND p.id=m.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=m.company_id AND ca.id=m.chart_account_id WHERE m.company_id=? AND m.id=?', [$cid, $cancelId]) : null;
if ($showCancel && !$cancelMovement) { $showCancel = false; if ($err === '') $err = 'Movimento não encontrado para cancelamento.'; }

$creditos = 0; $debitos = 0;
foreach ($rows as $r) {
  $status = (string)($r['status'] ?? 'ATIVO');
  if ($status === '') $status = 'ATIVO';
  if ($status === 'CANCELADO') continue;
  if ((string)$r['type'] === 'CREDITO') $creditos += (float)$r['amount']; else $debitos += (float)$r['amount'];
}

$saldoInicialBancos = (float)(finance_query_one($pdo, 'SELECT COALESCE(SUM(initial_balance),0) AS total FROM finance_bank_accounts WHERE company_id=? AND active=1', [$cid])['total'] ?? 0);
$saldoAnteriorMov = (float)(finance_query_one($pdo, "SELECT COALESCE(SUM(CASE WHEN type='CREDITO' THEN amount WHEN type='DEBITO' THEN -amount ELSE 0 END),0) AS total FROM finance_movements WHERE company_id=? AND movement_date < ? AND (status IS NULL OR status='' OR status='ATIVO')", [$cid, $ini])['total'] ?? 0);
$saldoInicialPeriodo = round($saldoInicialBancos + $saldoAnteriorMov, 2);
$saldoFinalPeriodo = round($saldoInicialPeriodo + $creditos - $debitos, 2);

$filterArgs = ['ini' => $ini, 'fim' => $fim];
if ($typeFilter !== '') $filterArgs['type'] = $typeFilter;
if ($bankFilter > 0) $filterArgs['bank_account_id'] = $bankFilter;
if ($statusFilter !== 'ATIVO') $filterArgs['status'] = $statusFilter;
if ($q !== '') $filterArgs['q'] = $q;
$listUrl = 'financeiro_movimentos.php' . ($filterArgs ? '?' . http_build_query($filterArgs) : '');
if ($financeCrossContext && function_exists('multi_company_context_url')) $listUrl = multi_company_context_url($listUrl);
$linkWithAction = static function (array $extra) use ($filterArgs, $financeCrossContext): string {
  $url = 'financeiro_movimentos.php?' . http_build_query(array_merge($filterArgs, $extra));
  if ($financeCrossContext && function_exists('multi_company_context_url')) $url = multi_company_context_url($url);
  return $url;
};
$clearUrl = 'financeiro_movimentos.php?limpar_filtros=1';
if ($financeCrossContext && function_exists('multi_company_context_url')) $clearUrl = multi_company_context_url($clearUrl);

finance_admin_start('Financeiro - Movimento Financeiro', 'fin_mov');
?>
<style>
  /* NEXA_PATCH_MOVIMENTO_FILTROS_LINHA_UNICA_V1_20260910
     Mantém este ajuste isolado na tela de Movimento Financeiro. */
  .finance-movements-filter{
    display:grid;
    grid-template-columns:150px 150px 145px 145px minmax(160px,.9fr) minmax(260px,1.7fr) auto;
    gap:8px 10px;
    align-items:end;
  }
  .finance-movements-filter > div{min-width:0}
  .finance-movements-filter .history{grid-column:auto}
  .finance-movements-filter .finance-filter-actions{
    min-width:0;
    display:flex;
    gap:8px;
    justify-content:flex-end;
    align-items:center;
    flex-wrap:nowrap;
  }
  .finance-movements-filter .finance-filter-actions .btn{
    min-height:39px;
    padding-left:14px;
    padding-right:14px;
    white-space:nowrap;
  }
  @media(max-width:1280px){
    .finance-movements-filter{
      grid-template-columns:repeat(4,minmax(0,1fr));
    }
    .finance-movements-filter .history{grid-column:span 3}
    .finance-movements-filter .finance-filter-actions{grid-column:span 1}
  }
  @media(max-width:820px){
    .finance-movements-filter{grid-template-columns:1fr}
    .finance-movements-filter .history,
    .finance-movements-filter .finance-filter-actions{grid-column:auto}
    .finance-movements-filter .finance-filter-actions{justify-content:stretch}
    .finance-movements-filter .finance-filter-actions .btn{flex:1}
  }
  /* V11.51.6: compactação local dos filtros para ampliar a área visível da grade. */
  .finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}
  .finance-movements-filter label{margin-bottom:3px}
  .finance-movements-filter input,.finance-movements-filter select{min-height:39px}

  /* V11.55: somente o modal de lançamento/edição MANUAL. Outras rotinas preservadas. */
  .nexa-movement-manual-form .nexa-movement-manual-grid{
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px 14px;
  }
  .nexa-movement-manual-grid > div{min-width:0}
  .nexa-movement-manual-grid .nexa-movement-search-field{grid-column:span 2}
  .nexa-movement-manual-grid .nexa-movement-full{grid-column:1/-1}
  .nexa-movement-manual-form .finance-form-section textarea[name="notes"]{width:100%;box-sizing:border-box}
  .nexa-movement-manual-form .nexa-f8-display{min-width:0}
  @media(max-width:820px){
    .nexa-movement-manual-form .nexa-movement-manual-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .nexa-movement-manual-grid .nexa-movement-search-field{grid-column:1/-1}
  }
  @media(max-width:530px){
    .nexa-movement-manual-form .nexa-movement-manual-grid{grid-template-columns:minmax(0,1fr)}
    .nexa-movement-manual-grid .nexa-movement-search-field,.nexa-movement-manual-grid .nexa-movement-full{grid-column:1/-1}
  }
</style>
<?php
$canManualMovement = function_exists('finance_effective_permission_allowed')
  ? finance_effective_permission_allowed($pdo, 'financeiro_movimento_manual')
  : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_movimento_manual'));
$canCancelMovement = function_exists('finance_effective_permission_allowed')
  ? finance_effective_permission_allowed($pdo, 'financeiro_cancelar_titulos')
  : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_cancelar_titulos'));
if ($financeCrossContext) {
  $canManualMovement = $financeCrossMovementWrite;
  $canCancelMovement = $financeCrossMovementCancel;
  if (!$canManualMovement) { $showForm = false; $adjustMovement = null; }
  if (!$canCancelMovement) $showCancel = false;
} elseif ($financeCrossReadOnly) {
  $canManualMovement = false;
  $canCancelMovement = false;
  $showForm = false;
  $showCancel = false;
  $adjustMovement = null;
}
finance_page_header('Movimento Financeiro', '', $canManualMovement ? [
  ['href'=>'financeiro_movimentos.php?new=1','label'=>'+ Movimento manual','class'=>'btn btn-primary'],
] + ($financeCrossContext ? [] : [1 => ['href'=>'financeiro_movimentos.php?transfer=1','label'=>'⇄ Transferência','class'=>'btn']]) : []);
// V11.51.6: tela otimizada para priorizar a listagem; as abas gerais do Financeiro
// continuam disponíveis nas demais telas.
finance_flash(); if($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<style>
.fin-mov-kpis{gap:10px!important}.fin-mov-kpis .finance-kpi{padding:8px 14px;border-radius:14px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
.fin-mov-kpis .finance-kpi small{margin:0;font-size:12px}.fin-mov-kpis .finance-kpi strong{font-size:clamp(17px,1.7vw,21px)}
</style>
<div class="finance-grid-4 fin-mov-kpis">
  <div class="finance-kpi"><small>Saldo inicial</small><strong class="<?= $saldoInicialPeriodo>=0?'pos':'neg' ?>">R$ <?= finance_money_br($saldoInicialPeriodo) ?></strong></div>
  <div class="finance-kpi"><small>Créditos</small><strong class="pos">R$ <?= finance_money_br($creditos) ?></strong></div>
  <div class="finance-kpi"><small>Débitos</small><strong class="neg">R$ <?= finance_money_br($debitos) ?></strong></div>
  <div class="finance-kpi"><small>Saldo final</small><strong class="<?= $saldoFinalPeriodo>=0?'pos':'neg' ?>">R$ <?= finance_money_br($saldoFinalPeriodo) ?></strong></div>
</div>

<div class="finance-card finance-filter-card" style="margin-top:10px"><form method="get" class="finance-filter finance-movements-filter" data-lembrar-filtros="movimentos" data-lembrar-ignorar="edit,new,cancel,adjust_date,transfer"><?php if($financeCrossContext && $financeMcCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($financeMcCtx) ?>"><?php endif; ?>
  <div><label>Data inicial</label><input type="date" name="ini" value="<?= finance_h($ini) ?>"></div>
  <div><label>Data final</label><input type="date" name="fim" value="<?= finance_h($fim) ?>"></div>
  <div><label>Tipo</label><select name="type"><option value="">Todos</option><option value="CREDITO" <?= $typeFilter==='CREDITO'?'selected':'' ?>>Créditos</option><option value="DEBITO" <?= $typeFilter==='DEBITO'?'selected':'' ?>>Débitos</option></select></div>
  <div><label>Status</label><select name="status"><option value="ATIVO" <?= $statusFilter==='ATIVO'?'selected':'' ?>>Ativos</option><option value="CANCELADO" <?= $statusFilter==='CANCELADO'?'selected':'' ?>>Cancelados</option><option value="" <?= $statusFilter===''?'selected':'' ?>>Todos</option></select></div>
  <div><label>Caixa/Banco</label><select name="bank_account_id"><option value="">Todos</option><?php foreach($banks as $bank): ?><option value="<?= (int)$bank['id'] ?>" <?= $bankFilter===(int)$bank['id']?'selected':'' ?>><?= finance_h($bank['name'] ?? '') ?></option><?php endforeach; ?></select></div>
  <div class="history"><label>Histórico</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Histórico, documento, pessoa, plano ou motivo"></div>
  <div class="finance-filter-actions"><button class="btn">Filtrar</button><a class="btn" href="<?= finance_h($clearUrl) ?>">Limpar</a></div>
</form><script src="/assets/nexa-lembrar-filtros.js?v=1"></script></div>

<?php
  require_once __DIR__ . '/../config/grid_preferences.php';
  $movGridPrefs = nexa_grid_prefs_load($pdo, (int)COMPANY_ID, 'fin_movimentos');
?>
<link rel="stylesheet" href="assets/nexa_titles_grid.css?v=20261108_4">
<style id="ntgFirstPaint">.ntg-wrap:not(.is-ready){visibility:hidden}</style>
<script>setTimeout(function(){var s=document.getElementById('ntgFirstPaint');if(s&&!document.documentElement.classList.contains('ntg-ready'))s.remove();},4000);</script>
<div class="finance-card">
  <div class="finance-card-head" style="align-items:center">
    <div><h2 class="finance-subtitle">Movimentos</h2><div class="ntg-subline"><span class="finance-muted"><?= count($rows) ?> movimento(s)</span><span class="ntg-legend"><span><i class="lg-credit"></i>Crédito</span><span><i class="lg-debit"></i>Débito</span><span><i class="lg-canceled"></i>Cancelado</span></span></div></div>
    <div class="ntg-toolbar">
      <details class="ntg-picker"><summary class="btn btn-sm">☷ Colunas</summary><div class="ntg-picker-panel"><strong>Colunas visíveis</strong><div data-ntg-columns="fin_movimentos"></div><button type="button" class="btn ntg-reset" data-ntg-reset="fin_movimentos">Restaurar padrão</button><small>Clique no título para ordenar · arraste ⠿ para mudar de lugar · ajuste a largura pela borda do cabeçalho.</small></div></details>
      <span class="ntg-pref-state" data-ntg-state="fin_movimentos" role="status" aria-live="polite"></span>
    </div>
  </div>
  <?php if(!$rows): ?><div class="finance-empty">Nenhum movimento encontrado.</div><?php else: ?>
  <div class="ntg-wrap">
    <table class="ntg-table" data-ntg="fin_movimentos" data-scope="<?= (int)COMPANY_ID ?>" data-save-url="grid_preferencias.php" data-csrf="<?= finance_h(nexa_grid_csrf()) ?>"
      data-prefs="<?= finance_h(json_encode($movGridPrefs ?: new stdClass(), JSON_UNESCAPED_UNICODE)) ?>"
      data-defaults='{"data":102,"tipo":76,"pessoa":200,"plano":190,"historico":250,"doc":110,"centro":120,"conta":150,"origem":95,"valor":120}'>
      <thead><tr>
        <th data-col="data">Data</th>
        <th data-col="tipo">Tipo</th>
        <th data-col="pessoa">Pessoa</th>
        <th data-col="plano">Plano</th>
        <th data-col="historico">Histórico</th>
        <th data-col="doc">Documento</th>
        <th data-col="centro">Centro</th>
        <th data-col="conta">Conta/Caixa</th>
        <th data-col="origem">Origem</th>
        <th data-col="valor" data-type="num" class="ntg-num">Valor</th>
        <th class="ntg-actions">Ações</th>
      </tr></thead>
      <tbody>
<?php foreach($rows as $r):
  $cred = (string)$r['type'] === 'CREDITO';
  $status = (string)($r['status'] ?? 'ATIVO');
  if ($status === '') $status = 'ATIVO';
  $canceled = $status === 'CANCELADO';
  $reason = trim((string)($r['canceled_reason'] ?? ''));
  $movDate = substr((string)($r['movement_date'] ?? ''), 0, 10);
  $movPlan = trim((string)($r['code'] ?? '') . ' ' . (string)($r['account_name'] ?? ''));
  $movDesc = (string)($r['description'] ?? '');
  $originKind = strtoupper(trim((string)($r['origin_type'] ?? '')));
  $originId = (int)($r['origin_id'] ?? 0);
?>
<tr class="<?= $canceled ? 'ntg-canceled' : ($cred ? 'ntg-credit' : 'ntg-debit') ?>">
  <td data-col="data" data-sort="<?= finance_h($movDate) ?>"><?= finance_date_br($r['movement_date'] ?? '') ?></td>
  <td data-col="tipo"><?= $cred ? 'Crédito' : 'Débito' ?></td>
  <td data-col="pessoa" title="<?= finance_h($r['person_name'] ?? '') ?>"><?= finance_h($r['person_name'] ?? '') ?: '<span class="ntg-muted">—</span>' ?></td>
  <td data-col="plano" title="<?= finance_h($movPlan) ?>"><span class="code"><?= finance_h($r['code'] ?? '') ?></span> <?= finance_h($r['account_name'] ?? '') ?></td>
  <td data-col="historico" title="<?= finance_h($movDesc . ($canceled && $reason !== '' ? ' · Motivo do cancelamento: ' . $reason : '')) ?>"><?= $movDesc !== '' ? finance_h($movDesc) : '<span class="ntg-muted">—</span>' ?><?php if($canceled && $reason !== ''): ?> <span class="ntg-muted">· Motivo: <?= finance_h($reason) ?></span><?php endif; ?></td>
  <td data-col="doc" title="<?= finance_h($r['document_number'] ?? '') ?>"><?= finance_h($r['document_number'] ?? '') ?: '<span class="ntg-muted">—</span>' ?></td>
  <td data-col="centro" title="<?= finance_h($r['cost_name'] ?? '') ?>"><?= finance_h($r['cost_name'] ?? '') ?: '<span class="ntg-muted">—</span>' ?></td>
  <td data-col="conta" title="<?= finance_h($r['bank_name'] ?? '') ?>"><?= finance_h($r['bank_name'] ?? '') ?></td>
  <td data-col="origem"><?= finance_h($r['origin_type'] ?? '') ?></td>
  <td data-col="valor" class="ntg-num" data-sort="<?= number_format(($cred ? 1 : -1) * (float)($r['amount'] ?? 0), 2, '.', '') ?>"><?= $cred ? '' : '- ' ?>R$ <?= finance_money_br($r['amount'] ?? 0) ?></td>
  <td class="ntg-actions"><div class="ntg-icons">
    <?php if($canceled): ?>
      <span class="ntg-icon ntg-static" title="Movimento cancelado<?= $reason !== '' ? ' · Motivo: ' . finance_h($reason) : '' ?>" aria-label="Cancelado"><?= nexa_grid_icon('ban') ?></span>
    <?php else: ?>
      <?php if($canManualMovement && $originKind==='MANUAL'): ?><a class="ntg-icon" href="<?= finance_h($linkWithAction(['edit'=>(int)$r['id']])) ?>" title="Editar movimento" aria-label="Editar movimento"><?= nexa_grid_icon('edit') ?></a>
      <?php elseif(finance_mov_date_adjustable_origin($originKind)): ?>
        <?php if($canManualMovement && $financeDateFeatureEnabled): ?><a class="ntg-icon" href="<?= finance_h($linkWithAction(['adjust_date'=>(int)$r['id']])) ?>" title="Ajustar a data do extrato neste movimento financeiro, sem mudar o título" aria-label="Ajustar data"><?= nexa_grid_icon('calendar') ?></a><?php endif; ?>
        <?php if($originId>0 && in_array($originKind,['RECEBER','PAGAR'],true)):
          $titlePage=$originKind==='RECEBER'?'financeiro_contas_receber.php':'financeiro_contas_pagar.php';
          $titleUrl=$titlePage.'?edit='.$originId;
          if($financeCrossContext && function_exists('multi_company_context_url'))$titleUrl=multi_company_context_url($titleUrl);
        ?><a class="ntg-icon" href="<?= finance_h($titleUrl) ?>" title="Ver o título original (não é preciso alterá-lo para ajustar a data no Financeiro)" aria-label="Ver título"><?= nexa_grid_icon('view') ?></a><?php endif; ?>
      <?php endif; ?>
      <?php if($canCancelMovement): ?><a class="ntg-icon ntg-danger" href="<?= finance_h($linkWithAction(['cancel' => (int)$r['id']])) ?>" title="Cancelar movimento" aria-label="Cancelar movimento"><?= nexa_grid_icon('cancel') ?></a><?php endif; ?>
    <?php endif; ?>
  </div></td>
</tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<script src="assets/nexa_titles_grid.js?v=20261108_4" defer></script>

<?php if($showForm && $canManualMovement): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog finance-modal-wide"><div class="finance-modal-head"><div><h2><?= $editId ? 'Editar movimento #' . (int)$editId : 'Novo movimento manual' ?></h2><p class="finance-muted"><?= $editId ? 'Altere os dados do movimento financeiro. A origem do lançamento é preservada.' : 'Use para ajustes, entradas manuais, saídas manuais, tarifas, sangrias e transferências simples.' ?></p></div><a class="finance-modal-close" href="<?= finance_h($listUrl) ?>">×</a></div><form method="post" class="finance-form nexa-movement-manual-form"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><?php if($financeCrossMovementScope): ?><input type="hidden" name="mc_csrf" value="<?= finance_h($financeMcCsrf) ?>"><?php if($financeMcCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($financeMcCtx) ?>"><?php endif; ?><?php endif; ?><div class="finance-form-section"><h3>Dados do movimento</h3><div class="finance-form-grid nexa-movement-manual-grid">
  <div><label>Data</label><input type="date" name="movement_date" value="<?= finance_h($edit['movement_date'] ?? date('Y-m-d')) ?>"></div>
  <div><label>Tipo</label><select name="type"><option value="CREDITO" <?= (($edit['type'] ?? '')==='CREDITO' || !isset($edit['type']))?'selected':'' ?>>Crédito</option><option value="DEBITO" <?= (($edit['type'] ?? '')==='DEBITO')?'selected':'' ?>>Débito</option></select></div>
  <div><label>Valor</label><input name="amount" inputmode="decimal" value="<?= finance_money_br($edit['amount'] ?? 0) ?>" required></div>
  <div><label>Nº documento</label><input name="document_number" value="<?= finance_h($edit['document_number'] ?? '') ?>"></div>
  <div class="nexa-movement-search-field"><label>Pessoa</label><select name="person_id" id="nexaMovementPerson"><?php finance_select_options($people, $edit['person_id'] ?? '', 'Opcional'); ?></select></div>
  <div class="nexa-movement-search-field"><label>Conta/Caixa</label><select name="bank_account_id" id="nexaMovementBank"><?php finance_select_options($banks, $edit['bank_account_id'] ?? '', 'Opcional'); ?></select></div>
  <div class="nexa-movement-search-field"><label>Plano de contas *</label><select name="chart_account_id" id="nexaMovementChart" required><?php finance_select_options($charts, $edit['chart_account_id'] ?? '', 'Selecione conta analítica'); ?></select></div>
  <div class="nexa-movement-search-field"><label>Centro de custo</label><select name="cost_center_id" id="nexaMovementCost"><?php finance_select_options($costs, $edit['cost_center_id'] ?? '', 'Opcional'); ?></select></div>
  <div class="nexa-movement-full"><label>Histórico</label><input name="description" value="<?= finance_h($edit['description'] ?? '') ?>"></div></div></div><div class="finance-form-section"><h3>Observações</h3><textarea name="notes" rows="3"><?= finance_h($edit['notes'] ?? '') ?></textarea></div><?php if($editId && $edit): ?><div class="finance-form-section"><h3>Origem</h3><div class="finance-form-grid three"><div><label>Origem</label><input readonly value="<?= finance_h($edit['origin_type'] ?? 'MANUAL') ?>"></div><div><label>ID origem</label><input readonly value="<?= finance_h($edit['origin_id'] ?? '') ?>"></div><div><label>Status</label><input readonly value="<?= finance_h($edit['status'] ?? 'ATIVO') ?>"></div></div></div><?php endif; ?><div class="finance-form-actions"><a class="btn" href="<?= finance_h($listUrl) ?>">Cancelar</a><button class="btn btn-primary"><?= $editId ? 'Salvar alterações' : 'Lançar movimento' ?></button></div></form></div></div>
<?php endif; ?>

<?php
$showTransfer = !$financeCrossContext && $canManualMovement && (isset($_GET['transfer']) || ($err !== '' && $postAction === 'transfer'));
if ($showTransfer):
  require_once __DIR__ . '/../config/cash_settings.php';
  $trCfg = nexa_cash_settings($pdo, $cid);
  $trPost = ($err !== '' && $postAction === 'transfer') ? $_POST : [];
  $trChartsOut = finance_chart_options($pdo, $cid, 'DEBITO');
  $trChartsIn = finance_chart_options($pdo, $cid, 'CREDITO');
?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog finance-modal-wide"><div class="finance-modal-head"><div><h2>Transferência entre contas</h2><p class="finance-muted">Lança um débito na conta de origem e um crédito na conta de destino, ligados entre si. Cancelar um lado cancela os dois.</p></div><a class="finance-modal-close" href="<?= finance_h($listUrl) ?>">×</a></div>
<form method="post" class="finance-form"><input type="hidden" name="action" value="transfer">
<div class="finance-form-section"><h3>Dados da transferência</h3><div class="finance-form-grid">
  <div><label>Data</label><input type="date" name="movement_date" value="<?= finance_h($trPost['movement_date'] ?? date('Y-m-d')) ?>"></div>
  <div><label>Valor *</label><input name="amount" inputmode="decimal" value="<?= finance_h($trPost['amount'] ?? '') ?>" required autofocus></div>
  <div><label>Conta de origem *</label><select name="from_bank_account_id" required><?php finance_select_options($banks, $trPost['from_bank_account_id'] ?? '', 'Selecione'); ?></select></div>
  <div><label>Conta de destino *</label><select name="to_bank_account_id" required><?php finance_select_options($banks, $trPost['to_bank_account_id'] ?? '', 'Selecione'); ?></select></div>
  <div><label>Plano de contas de origem (saída) *</label><select name="from_chart_account_id" required><?php finance_select_options($trChartsOut, $trPost['from_chart_account_id'] ?? ((int)$trCfg['chart_transfer_out'] ?: ''), 'Selecione conta analítica'); ?></select></div>
  <div><label>Plano de contas de destino (entrada) *</label><select name="to_chart_account_id" required><?php finance_select_options($trChartsIn, $trPost['to_chart_account_id'] ?? ((int)$trCfg['chart_transfer_in'] ?: ''), 'Selecione conta analítica'); ?></select></div>
  <div><label>Centro de custo</label><select name="cost_center_id"><?php finance_select_options($costs, $trPost['cost_center_id'] ?? '', 'Opcional'); ?></select></div>
  <div><label>Histórico</label><input name="description" value="<?= finance_h($trPost['description'] ?? '') ?>" placeholder="Transferência"></div>
</div><div class="hint" style="margin-top:8px">Planos de contas padrão: <a href="configuracoes_caixa.php">Configurações de caixa</a>.</div></div>
<div class="finance-form-section"><h3>Observações</h3><textarea name="notes" rows="2"><?= finance_h($trPost['notes'] ?? '') ?></textarea></div>
<div class="finance-form-actions"><a class="btn" href="<?= finance_h($listUrl) ?>">Cancelar</a><button class="btn btn-primary">Lançar transferência</button></div></form></div></div>
<?php endif; ?>

<?php if($adjustMovement && $canManualMovement): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog"><div class="finance-modal-head"><div><h2>Ajustar data bancaria — Movimento #<?= (int)$adjustMovement['id'] ?></h2><p class="finance-muted">Corrige a data usada nos saldos do Movimento Financeiro para corresponder ao extrato. Nao modifica o titulo, o vencimento, a baixa, o valor ou o pagamento registrado.</p></div><a class="finance-modal-close" href="<?= finance_h($listUrl) ?>">×</a></div>
<form method="post" class="finance-form">
<input type="hidden" name="action" value="adjust_date"><input type="hidden" name="id" value="<?= (int)$adjustMovement['id'] ?>"><input type="hidden" name="old_date" value="<?= finance_h($adjustMovement['movement_date'] ?? '') ?>"><input type="hidden" name="fm_date_csrf" value="<?= finance_h($financeDateCsrf) ?>">
<?php if($financeCrossMovementScope): ?><input type="hidden" name="mc_csrf" value="<?= finance_h($financeMcCsrf) ?>"><?php if($financeMcCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($financeMcCtx) ?>"><?php endif; ?><?php endif; ?>
<div class="finance-form-section"><h3>Dados originais</h3><div class="finance-form-grid three">
<div><label>Data atual no movimento</label><input type="text" readonly value="<?= finance_date_br($adjustMovement['movement_date'] ?? '') ?>"></div>
<div><label>Valor (inalterado)</label><input type="text" readonly value="R$ <?= finance_money_br($adjustMovement['amount'] ?? 0) ?>"></div>
<div><label>Origem</label><input type="text" readonly value="<?= finance_h($adjustMovement['origin_type'] ?? '') ?>"></div>
<div class="span-3"><label>Conta/Caixa</label><input type="text" readonly value="<?= finance_h($adjustMovement['bank_name'] ?? '') ?>"></div>
<div class="span-3"><label>Historico</label><input type="text" readonly value="<?= finance_h($adjustMovement['description'] ?? '') ?>"></div>
</div></div>
<div class="finance-form-section"><h3>Ajuste para o extrato bancario</h3><div class="finance-form-grid three">
<div><label>Nova data no Movimento Financeiro *</label><input type="date" name="new_movement_date" value="<?= finance_h($_POST['new_movement_date'] ?? ($adjustMovement['movement_date'] ?? '')) ?>" required></div>
<div class="span-3"><label>Motivo obrigatorio *</label><textarea name="date_reason" minlength="10" maxlength="250" rows="3" required placeholder="Ex.: boleto pago dia 27/10, credito no extrato Itaú dia 28/10"><?= finance_h($_POST['date_reason'] ?? '') ?></textarea></div>
</div><div class="hint">O ajuste ficará registrado nas observações do movimento, com usuário, empresa, data anterior, data nova e justificativa. Baixas já conciliadas com OFX ou vinculadas ao caixa operacional não podem ser alteradas por esta opção.</div></div>
<div class="finance-form-actions"><a class="btn" href="<?= finance_h($listUrl) ?>">Cancelar</a><button class="btn btn-primary">Salvar nova data financeira</button></div>
</form></div></div>
<?php endif; ?>

<?php if ($showForm && $canManualMovement): ?>
<?php
  require_once __DIR__ . '/_buscar_f8.php';
  nexa_buscar_f8_assets();
?>
<script>
(function(){
  if (!window.NexaBuscarF8) return;
  const form = document.querySelector('form.nexa-movement-manual-form');
  if (!form) return;
  const fields = [
    ['nexaMovementPerson', {title:'Buscar pessoa', placeholder:'Selecione a pessoa', registerUrl:'financeiro_pessoas.php?new=1', registerLabel:'+ Cadastrar'}],
    ['nexaMovementBank', {title:'Buscar conta/caixa', placeholder:'Selecione a conta ou caixa', registerUrl:'financeiro_caixas_bancos.php?new=1', registerLabel:'+ Cadastrar'}],
    ['nexaMovementChart', {title:'Buscar plano de contas', placeholder:'Selecione o plano de contas', registerUrl:'financeiro_plano_contas.php?new=1', registerLabel:'+ Cadastrar'}],
    ['nexaMovementCost', {title:'Buscar centro de custo', placeholder:'Selecione o centro de custo', registerUrl:'financeiro_centros_custo.php?new=1', registerLabel:'+ Cadastrar'}]
  ];
  fields.forEach(([id,opts])=>{
    const select=form.querySelector('#'+id);
    if (!select) return;
    const instance=window.NexaBuscarF8.enhanceSelect(select,opts);
    if (!instance) return;
    // Padrão Nexa: ao começar a digitar no campo selecionado, pesquisar no modal.
    instance.display.addEventListener('keydown',e=>{
      if(e.key.length!==1 || e.ctrlKey || e.altKey || e.metaKey)return;
      e.preventDefault();
      window.NexaBuscarF8.open(instance);
      requestAnimationFrame(()=>{
        const q=document.getElementById('nexaF8Query');
        if(q){q.value=e.key;q.focus();}
      });
    });
  });
})();
</script>
<?php endif; ?>

<?php if($showCancel && $cancelMovement && $canCancelMovement): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog"><div class="finance-modal-head"><div><h2>Cancelar movimento #<?= (int)$cancelMovement['id'] ?></h2><p class="finance-muted">Informe o motivo do cancelamento. O movimento deixa de entrar nos totais, mas permanece no histórico para auditoria.</p></div><a class="finance-modal-close" href="<?= finance_h($listUrl) ?>">×</a></div>
<div class="finance-form-section"><h3>Resumo do movimento</h3><div class="finance-form-grid three">
  <div><label>Data</label><input readonly value="<?= finance_date_br($cancelMovement['movement_date'] ?? '') ?>"></div>
  <div><label>Tipo</label><input readonly value="<?= (string)($cancelMovement['type'] ?? '') === 'CREDITO' ? 'Crédito' : 'Débito' ?>"></div>
  <div><label>Valor</label><input readonly value="R$ <?= finance_money_br($cancelMovement['amount'] ?? 0) ?>"></div>
  <div><label>Pessoa</label><input readonly value="<?= finance_h($cancelMovement['person_name'] ?? '') ?>"></div>
  <div><label>Origem</label><input readonly value="<?= finance_h($cancelMovement['origin_type'] ?? '') ?>"></div>
  <div><label>Plano</label><input readonly value="<?= finance_h(trim((string)($cancelMovement['code'] ?? '') . ' ' . (string)($cancelMovement['account_name'] ?? ''))) ?>"></div>
  <div class="span-3"><label>Histórico</label><input readonly value="<?= finance_h($cancelMovement['description'] ?? '') ?>"></div>
</div></div>
<form method="post" class="finance-form"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$cancelMovement['id'] ?>"><?php if($financeCrossMovementScope): ?><input type="hidden" name="mc_csrf" value="<?= finance_h($financeMcCsrf) ?>"><?php if($financeMcCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($financeMcCtx) ?>"><?php endif; ?><?php endif; ?><div class="finance-form-section"><h3>Motivo do cancelamento</h3><textarea name="cancel_reason" rows="4" required placeholder="Ex.: lançamento duplicado, valor incorreto, estorno operacional..."><?= finance_h($_POST['cancel_reason'] ?? '') ?></textarea><div class="hint">Atenção: em lançamentos originados de pedidos ou baixas, o cancelamento aqui afeta o movimento financeiro, mas não altera automaticamente o pedido ou o título de origem.</div></div><div class="finance-form-actions"><a class="btn" href="<?= finance_h($listUrl) ?>">Voltar</a><button class="btn btn-danger">Confirmar cancelamento</button></div></form></div></div>
<?php endif; ?>
<?php finance_admin_end(); ?>
