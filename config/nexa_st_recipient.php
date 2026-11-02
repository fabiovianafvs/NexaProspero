<?php
declare(strict_types=1);

/**
 * Nexa Próspero | ICMS-ST por regime do destinatário, v1
 *
 * Nunca determina incidência de ST apenas pelo regime do cliente:
 * só é invocado para item explicitamente configurado com CST/CSOSN ST,
 * CEST, pICMSST e MVA por NCM+UF. Somente NF-e modelo 55 e operações
 * internas no primeiro ciclo homologável.
 */
if (!function_exists('nexa_st_settings')) {
  function nexa_st_settings(PDO $pdo, int $companyId): array {
    try {
      $st = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
      $st->execute([$companyId, 'fiscal_st_recipient_v1']);
      $json = json_decode((string)($st->fetchColumn() ?: ''), true);
      return is_array($json) ? $json : [];
    } catch (Throwable $e) { return []; } // Sem configuração: desligado.
  }
}
if (!function_exists('nexa_st_enabled')) {
  function nexa_st_enabled(PDO $pdo, int $companyId, string $model, string $environment): bool {
    if ($model !== '55') return false;
    $settings = nexa_st_settings($pdo, $companyId);
    if (empty($settings['enabled'])) return false;
    if ($environment === 'HOMOLOGACAO') return true;
    // Habilitação de produção não pode ser feita pela interface comum.
    return $environment === 'PRODUCAO' && !empty($settings['production_approved']);
  }
}
if (!function_exists('nexa_st_customer_eligible')) {
  function nexa_st_customer_eligible(PDO $pdo, int $companyId, int $personId): bool {
    if ($personId <= 0) return false;
    $st = $pdo->prepare(
      'SELECT person_type, tax_regime, is_customer, active FROM finance_people WHERE company_id=? AND id=? LIMIT 1'
    );
    $st->execute([$companyId, $personId]);
    $person = $st->fetch(PDO::FETCH_ASSOC);
    return (bool)($person && (int)$person['active'] === 1
      && (int)$person['is_customer'] === 1
      && (string)$person['person_type'] === 'JURIDICA'
      && in_array((string)($person['tax_regime'] ?? ''), ['LUCRO_PRESUMIDO','LUCRO_REAL'], true));
  }
}
if (!function_exists('nexa_st_own_rate')) {
  function nexa_st_own_rate(PDO $pdo, string $uf, string $date): float {
    $st = $pdo->prepare(
      'SELECT own_icms_rate FROM fiscal_st_state_rates
       WHERE uf=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
       ORDER BY valid_from DESC LIMIT 1'
    );
    $st->execute([$uf,$date,$date]);
    $value = $st->fetchColumn();
    if ($value === false || $value === null || $value === '') {
      throw new RuntimeException('ICMS-ST: alíquota de ICMS próprio de referência não cadastrada para '.$uf.' na data '.$date.'.');
    }
    return (float)$value;
  }
}
if (!function_exists('nexa_st_mva')) {
  function nexa_st_mva(PDO $pdo, string $uf, string $ncm, string $cest, string $date): float {
    $st = $pdo->prepare(
      "SELECT mva_rate FROM fiscal_st_ncm_mva
       WHERE uf=? AND ncm=? AND cest IN (?, '') AND valid_from<=?
         AND (valid_to IS NULL OR valid_to>=?)
       ORDER BY (cest = ?) DESC, valid_from DESC LIMIT 1"
    );
    $st->execute([$uf,$ncm,$cest,$date,$date,$cest]);
    $value=$st->fetchColumn();
    if ($value === false || $value === null) {
      throw new RuntimeException('ICMS-ST: MVA não cadastrada para NCM '.$ncm.' / CEST '.$cest.' / UF '.$uf.'.');
    }
    return (float)$value;
  }
}
if (!function_exists('nexa_st_calculate')) {
  function nexa_st_calculate(float $base, float $mvaRate, float $stRate, float $ownRate): array {
    foreach ([$base, $mvaRate, $stRate, $ownRate] as $number) {
      if (!is_finite($number) || $number < 0) throw new InvalidArgumentException('ICMS-ST: valores inválidos.');
    }
    if ($base <= 0 || $stRate <= 0 || $stRate > 100 || $ownRate > 100 || $mvaRate > 1000) {
      throw new InvalidArgumentException('ICMS-ST: base, MVA ou alíquotas fora dos limites.');
    }
    $stBase = round($base * (1 + $mvaRate / 100), 2);
    $presumed = round($stBase * $stRate / 100, 2);
    $ownReference = round($base * $ownRate / 100, 2);
    return [
      'st_base' => $stBase,
      'st_rate' => $stRate,
      'st_amount' => round(max(0, $presumed - $ownReference), 2),
      'st_mva' => $mvaRate,
      'st_modbc' => '4',
      'reference_own_rate' => $ownRate,
      'reference_own_amount' => $ownReference,
      'presumed_total' => $presumed,
      'calc_version' => 'NEXA_ST_RECIPIENT_V1',
    ];
  }
}


/**
 * Regra NF-e de ICMS-ST separada da tributação da NFC-e. MVA por NCM/CEST/UF
 * é compartilhada, mas CFOP/CST/CSOSN pertence somente à empresa ativa.
 */
if (!function_exists('nexa_st_nfe_profile')) {
  function nexa_st_nfe_profile(PDO $pdo, int $companyId, string $uf, string $ncm, string $cest, string $date): array {
    $stmt = $pdo->prepare(
      "SELECT m.id AS mva_id, m.mva_rate, m.uf, m.ncm, m.cest,
              p.cfop, p.cst, p.csosn
       FROM fiscal_st_ncm_mva m
       LEFT JOIN fiscal_st_nfe_profiles p ON p.mva_id=m.id AND p.company_id=?
       WHERE m.uf=? AND m.ncm=? AND m.cest IN (?, '')
         AND m.valid_from<=? AND (m.valid_to IS NULL OR m.valid_to>=?)
       ORDER BY (m.cest = ?) DESC, m.valid_from DESC, m.id DESC LIMIT 1"
    );
    $stmt->execute([$companyId, $uf, $ncm, $cest, $date, $date, $cest]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
      throw new RuntimeException('ICMS-ST: não há MVA vigente para NCM '.$ncm.' / CEST '.$cest.' / UF '.$uf.'.');
    }
    if (trim((string)($found['cfop'] ?? '')) === '') {
      throw new RuntimeException('ICMS-ST: configure CFOP e CST/CSOSN da NF-e na tela Fiscal > NCM/IBPT > ICMS-ST por UF/MVA para o NCM '.$ncm.' (empresa atual).');
    }
    return $found;
  }
}
if (!function_exists('nexa_st_nfe_validate_codes')) {
  function nexa_st_nfe_validate_codes(array $profile, string $crt): array {
    $cfop = preg_replace('/\D+/', '', (string)($profile['cfop'] ?? ''));
    $cst = preg_replace('/\D+/', '', (string)($profile['cst'] ?? ''));
    $csosn = preg_replace('/\D+/', '', (string)($profile['csosn'] ?? ''));
    // V3 homologada somente para operações internas com retenção atual do ST.
    // 5405/CSOSN 500/CST 60 são substituído com imposto anteriormente retido:
    // não podem ser usados para o cálculo de novo ICMS-ST desta regra.
    if (!in_array($cfop, ['5401','5403'], true)) {
      throw new RuntimeException('ICMS-ST NF-e: CFOP '.$cfop.' inválido para retenção atual nesta versão. Use 5401 ou 5403 somente quando cabível.');
    }
    $crt = preg_replace('/\D+/', '', $crt) ?: '1';
    if ($crt === '1') {
      if (!in_array($csosn, ['202','203'], true)) {
        throw new RuntimeException('ICMS-ST NF-e: para emitente CRT 1 configure CSOSN 202/203 compatível com a operação.');
      }
    } elseif ($cst !== '30') {
      throw new RuntimeException('ICMS-ST NF-e: para emitente fora do CRT 1, somente CST 30 (sem destaque do ICMS próprio) está homologado nesta regra.');
    }
    return ['cfop'=>$cfop, 'cst'=>$cst, 'csosn'=>$csosn];
  }
}
