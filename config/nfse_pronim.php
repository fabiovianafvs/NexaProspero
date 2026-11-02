<?php
/**
 * Provider NFS-e Pronim / GOVBR ISS Digital (Padrão Nacional 1.01).
 *
 * Esta implementação é isolada do emissor Nacional existente. Ela reaproveita
 * somente helpers comuns de cadastro/persistência/certificado e aplica as
 * particularidades observadas em Itabira/MG no XML autorizado em 28/09/2026.
 * R3: o endpoint municipal EnviarSincrono recebe loteXmlGZipB64 como array;
 * não usa o envelope dpsXmlGZipB64 do endpoint Nacional direto.
 */
declare(strict_types=1);

require_once __DIR__ . '/nfse_emitter.php';

if (!function_exists('nfse_pronim_production_enabled')) {
  function nfse_pronim_production_enabled(): bool {
    // Produção pode ser liberada permanentemente pela variável de servidor ou,
    // preferencialmente, apenas na requisição atual após confirmação explícita
    // do operador na tela fiscal. A constante nunca persiste entre requisições.
    $confirmedForRequest = defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')
      && NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED === true;
    return nfse_emitter_env_flag('NFSE_PRONIM_ENABLE_PRODUCTION', false) || $confirmedForRequest;
  }
}

if (!function_exists('nfse_pronim_schema_path')) {
  function nfse_pronim_schema_path(): string {
    $custom = trim((string)(getenv('NFSE_PRONIM_DPS_XSD_PATH') ?: ''));
    if ($custom !== '' && is_file($custom)) return $custom;
    $bundled = dirname(__DIR__) . '/resources/nfse/nacional/1.01/DPS_v1.01.xsd';
    return is_file($bundled) ? $bundled : '';
  }
}

if (!function_exists('nfse_pronim_api_base')) {
  function nfse_pronim_api_base(array $cfg, string $environment): string {
    $environment = strtoupper(trim($environment));
    if ($environment === 'PRODUCAO') {
      $env = trim((string)(getenv('NFSE_PRONIM_API_BASE_PRODUCAO') ?: ''));
      $db = trim((string)($cfg['nfse_pronim_prod_base'] ?? ''));
      if ($env !== '') return rtrim($env, '/');
      if ($db !== '') return rtrim($db, '/');
      $ibge = preg_replace('/\D+/', '', (string)($cfg['city_ibge'] ?? '')) ?: '';
      if ($ibge === '3131703') return 'https://webapp1-itabira.cidade360.cloud/NFSe.Api/NotaNacional';
      return '';
    }
    $env = trim((string)(getenv('NFSE_PRONIM_API_BASE_HOMOLOGACAO') ?: ''));
    $db = trim((string)($cfg['nfse_pronim_hom_base'] ?? ''));
    if ($env !== '') return rtrim($env, '/');
    if ($db !== '') return rtrim($db, '/');
    return 'https://reformatributaria.govbr.cloud/NFSe.Api.Teste/NotaNacional';
  }
}

if (!function_exists('nfse_pronim_profile')) {
  function nfse_pronim_profile(array $cfg): string {
    $profile = strtoupper(trim((string)($cfg['nfse_pronim_profile'] ?? '')));
    if ($profile !== '') return $profile;
    return preg_replace('/\D+/', '', (string)($cfg['city_ibge'] ?? '')) === '3131703' ? 'ITABIRA_MG' : 'PRONIM_NACIONAL';
  }
}

// RTC NFS-e/Itabira: restauração GLOBAL para o perfil municipal homologado.
// Usa a mesma estrutura DPS 1.01 presente na NFS-e nº 58 autorizada
// em 07/10/2026 (cStat=100). Sem variável de ambiente/opt-in por empresa.
// Outros municípios preservam o comportamento anterior.
if (!function_exists('nfse_pronim_itabira_rtc_enabled')) {
  function nfse_pronim_itabira_rtc_enabled(array $cfg, int $companyId): bool {
    // O arquivo só é usado pelo emissor PRONIM. A cidade determina
    // aplicação global; não depender do ID ou de variável de ambiente.
    return nfse_emitter_digits($cfg['city_ibge'] ?? '') === '3131703';
  }
}

if (!function_exists('nfse_pronim_rtc_input_issues')) {
  function nfse_pronim_rtc_input_issues(array $tax): array {
    $fields = [
      'nbs_code' => ['NBS do serviço', 9],
      'nfse_national_operation_indicator' => ['cIndOp (indicador da operação)', 6],
      'ibs_cbs_cst' => ['CST IBS/CBS', 3],
      'ibs_cbs_classification_code' => ['cClassTrib IBS/CBS', 6],
    ];
    $issues = [];
    foreach ($fields as $key => [$label, $length]) {
      $raw = trim((string)($tax[$key] ?? ''));
      $digits = nfse_emitter_digits($raw);
      // Não aceitar valores com letras, quantidade errada ou códigos truncados.
      if ($raw === '' || $raw !== $digits || strlen($digits) !== $length) {
        $issues[] = $label . ' deve conter exatamente ' . $length . ' dígitos';
      }
    }
    return $issues;
  }
}

if (!function_exists('nfse_pronim_schema_issues')) {
  function nfse_pronim_schema_issues(PDO $pdo): array {
    $issues = nfse_emitter_schema_issues($pdo);
    foreach (['fiscal_company_config'=>['nfse_pronim_profile','nfse_pronim_hom_base','nfse_pronim_prod_base'], 'fiscal_nfse_details'=>['provider_code']] as $table=>$cols) {
      if (!fiscal_table_exists($pdo,$table)) continue;
      foreach ($cols as $col) if (!fiscal_column_exists($pdo,$table,$col)) $issues[]='Coluna ausente: '.$table.'.'.$col;
    }
    return array_values(array_unique($issues));
  }
}

if (!function_exists('nfse_pronim_require_schema')) {
  function nfse_pronim_require_schema(PDO $pdo): void {
    $issues = nfse_pronim_schema_issues($pdo);
    if ($issues) throw new RuntimeException('Aplique a migration V11.41 antes de utilizar o provider Pronim. '.implode(' | ',$issues));
  }
}

if (!function_exists('nfse_pronim_service_product_issues')) {
  function nfse_pronim_service_product_issues(array $row): array {
    $issues = nfse_nacional_service_product_issues($row);
    $municipal = preg_replace('/\D+/', '', (string)($row['municipal_tax_code'] ?? '')) ?: '';
    if ($municipal !== '' && strlen($municipal) > 10) $issues[]='Código de Tributação Municipal deve possuir no máximo 10 dígitos';
    return array_values(array_unique($issues));
  }
}

if (!function_exists('nfse_pronim_readiness')) {
  function nfse_pronim_readiness(PDO $pdo, int $companyId): array {
    $base = nfse_nacional_readiness($pdo,$companyId);
    $cfg = fiscal_company_config($pdo,$companyId);
    $environment = strtoupper((string)($base['environment'] ?? $cfg['environment'] ?? 'HOMOLOGACAO'));
    $series = $base['series'] ?? null;
    $seriesInt = $series ? (int)nfse_emitter_digits($series['series'] ?? '') : 0;
    $provider = strtoupper(trim((string)($cfg['nfse_provider'] ?? '')));
    $providerOk = in_array($provider,['PRONIM','GOVBR','ISS_DIGITAL','PRONIM_GOVBR'],true);
    $endpoint = nfse_pronim_api_base($cfg,$environment);
    $ibge = preg_replace('/\D+/', '', (string)($cfg['city_ibge'] ?? '')) ?: '';
    $checks = $base['checks'] ?? [];
    $checks[]=['key'=>'provider_pronim','label'=>'Provider Pronim / GOVBR selecionado','ok'=>$providerOk,'detail'=>$providerOk?'Integração municipal isolada selecionada.':'Selecione Pronim / GOVBR nas Configurações fiscais.','href'=>'fiscal_configuracoes.php?edit_config=1'];
    $checks[]=['key'=>'pronim_endpoint','label'=>'Endpoint ISS Digital','ok'=>$endpoint!=='' && str_starts_with(strtolower($endpoint),'https://'),'detail'=>$endpoint!==''?$endpoint:'Informe a URL HTTPS do ambiente municipal.','href'=>'fiscal_configuracoes.php?edit_config=1'];
    $checks[]=['key'=>'pronim_series','label'=>'Série DPS compatível com ISS Digital','ok'=>$seriesInt>=1 && $seriesInt<49990,'detail'=>$seriesInt>=1&&$seriesInt<49990?'Série entre 1 e 49989.':'A série deve estar entre 1 e 49989.','href'=>'fiscal_configuracoes.php?new_series=1'];
    $profile = nfse_pronim_profile($cfg);
    $profileOk = $ibge !== '' && strlen($ibge) === 7;
    if ($profile === 'ITABIRA_MG') $profileOk = $ibge === '3131703';
    $checks[]=['key'=>'pronim_profile','label'=>'Perfil municipal','ok'=>$profileOk,'detail'=>'Perfil '.$profile.' · IBGE '.$ibge.'.','href'=>'fiscal_configuracoes.php?edit_config=1'];
    $products = is_array($base['products'] ?? null)?$base['products']:[];
    $incomplete=[];
    foreach($products as $row){$i=nfse_pronim_service_product_issues($row); if($i){$row['issues']=$i;$incomplete[]=$row;}}
    $ok=0; foreach($checks as $c) if(!empty($c['ok'])) $ok++;
    $base['checks']=$checks; $base['ok_count']=$ok; $base['check_count']=count($checks); $base['incomplete_products']=$incomplete;
    $base['cadaster_ready']=$ok===count($checks) && !$incomplete;
    $base['provider']='PRONIM'; $base['provider_label']='Pronim / GOVBR - ISS Digital'; $base['profile']=nfse_pronim_profile($cfg); $base['endpoint']=$endpoint;
    return $base;
  }
}

if (!function_exists('nfse_pronim_build_dps_xml')) {
  function nfse_pronim_build_dps_xml(array $context, array $doc): string {
    nfse_emitter_require_runtime(false);
    $cfg = $context['config'];
    $tax = $context['tax'];
    $rtcEnabledItabira = nfse_pronim_itabira_rtc_enabled($cfg, (int)($doc['company_id'] ?? 0));
    $includeRtc = nfse_pronim_profile($cfg) !== 'ITABIRA_MG' || $rtcEnabledItabira;
    if ($rtcEnabledItabira) {
      $rtcIssues = nfse_pronim_rtc_input_issues($tax);
      if ($rtcIssues) throw new RuntimeException('NFS-e Itabira - IBS/CBS obrigatórios: ' . implode(' | ', $rtcIssues) . '. Corrija o cadastro fiscal do serviço antes de transmitir.');
    }
    $environment = strtoupper(trim((string)$context['environment']));
    // Gerar/assinar uma DPS de produção não transmite o documento. A trava de
    // produção é aplicada no momento do POST ao ISS Digital, após confirmação
    // explícita do operador no servidor (ver fiscal_nfse_acao.php).

    $series = (string)($doc['dps_series'] ?? $doc['series'] ?? '1');
    $number = (int)($doc['dps_number'] ?? $doc['number'] ?? 0);
    if ($number <= 0) throw new RuntimeException('Número da DPS inválido.');
    $seriesInt = (int)nfse_emitter_digits($series);
    if ($seriesInt < 1 || $seriesInt >= 49990) throw new RuntimeException('No ISS Digital/Pronim a série DPS deve estar entre 1 e 49989.');
    $dpsId = nfse_emitter_dps_id($cfg, $series, $number);

    $dom = new DOMDocument('1.0','UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $root = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse','DPS');
    $root->setAttribute('versao','1.01');
    $dom->appendChild($root);
    $inf = nfse_emitter_xml_element($dom,'infDPS');
    $inf->setAttribute('Id',$dpsId);
    $root->appendChild($inf);

    nfse_emitter_xml_add($dom,$inf,'tpAmb',$environment === 'PRODUCAO' ? '1' : '2');
    $timezone = new DateTimeZone(date_default_timezone_get() ?: 'America/Sao_Paulo');
    $now = new DateTimeImmutable('now',$timezone);
    nfse_emitter_xml_add($dom,$inf,'dhEmi',$now->format('Y-m-d\TH:i:sP'));
    nfse_emitter_xml_add($dom,$inf,'verAplic','NEXA-PRONIM-1.01');
    nfse_emitter_xml_add($dom,$inf,'serie',(string)(int)nfse_emitter_digits($series));
    nfse_emitter_xml_add($dom,$inf,'nDPS',(string)$number);
    $competence = (string)($doc['competence_date'] ?? date('Y-m-d'));
    nfse_emitter_xml_add($dom,$inf,'dCompet',substr($competence,0,10));
    nfse_emitter_xml_add($dom,$inf,'tpEmit','1');
    nfse_emitter_xml_add($dom,$inf,'cLocEmi',substr(nfse_emitter_digits($cfg['city_ibge'] ?? ''),0,7));

    $prest = nfse_emitter_xml_element($dom,'prest'); $inf->appendChild($prest);
    $prestDoc = nfse_emitter_digits($cfg['company_cnpj'] ?? '');
    if (!in_array(strlen($prestDoc), [11,14], true)) throw new RuntimeException('CPF/CNPJ do prestador inválido para o ISS Digital/Pronim.');
    nfse_emitter_xml_add($dom,$prest,strlen($prestDoc) === 11 ? 'CPF' : 'CNPJ',$prestDoc);
    $im = nfse_emitter_text($cfg['municipal_registration'] ?? '',30);
    if ($im === '') throw new RuntimeException('No ISS Digital/Pronim a Inscrição Municipal do prestador é obrigatória.');
    nfse_emitter_xml_add($dom,$prest,'IM',$im);
    $reg = nfse_emitter_xml_element($dom,'regTrib'); $prest->appendChild($reg);
    $simpleStatus = nfse_emitter_simple_status($cfg);
    nfse_emitter_xml_add($dom,$reg,'opSimpNac',$simpleStatus);
    $simpleRegime = nfse_emitter_simple_regime($tax,$simpleStatus);
    if ($simpleRegime !== '') nfse_emitter_xml_add($dom,$reg,'regApTribSN',$simpleRegime);
    nfse_emitter_xml_add($dom,$reg,'regEspTrib',nfse_emitter_special_regime($tax));

    $tom = $context['tomador'];
    if ($tom['document'] !== '') {
      $toma = nfse_emitter_xml_element($dom,'toma'); $inf->appendChild($toma);
      nfse_emitter_xml_add($dom,$toma,strlen($tom['document']) === 11 ? 'CPF' : 'CNPJ',$tom['document']);
      if ($tom['municipal_registration'] !== '') nfse_emitter_xml_add($dom,$toma,'IM',$tom['municipal_registration']);
      nfse_emitter_xml_add($dom,$toma,'xNome',$tom['name']);
      $addressComplete = strlen($tom['city_ibge']) === 7 && strlen($tom['zip']) === 8 && $tom['address'] !== '' && $tom['district'] !== '';
      if ($addressComplete) {
        $end = nfse_emitter_xml_element($dom,'end'); $toma->appendChild($end);
        $endNac = nfse_emitter_xml_element($dom,'endNac'); $end->appendChild($endNac);
        nfse_emitter_xml_add($dom,$endNac,'cMun',$tom['city_ibge']);
        nfse_emitter_xml_add($dom,$endNac,'CEP',$tom['zip']);
        nfse_emitter_xml_add($dom,$end,'xLgr',$tom['address']);
        nfse_emitter_xml_add($dom,$end,'nro',$tom['number'] !== '' ? $tom['number'] : 'S/N');
        if ($tom['complement'] !== '') nfse_emitter_xml_add($dom,$end,'xCpl',$tom['complement']);
        nfse_emitter_xml_add($dom,$end,'xBairro',$tom['district']);
      }
      if (strlen($tom['phone']) >= 6) nfse_emitter_xml_add($dom,$toma,'fone',$tom['phone']);
      if ($tom['email'] !== '' && filter_var($tom['email'],FILTER_VALIDATE_EMAIL)) nfse_emitter_xml_add($dom,$toma,'email',$tom['email']);
    }

    $serv = nfse_emitter_xml_element($dom,'serv'); $inf->appendChild($serv);
    $locPrest = nfse_emitter_xml_element($dom,'locPrest'); $serv->appendChild($locPrest);
    nfse_emitter_xml_add($dom,$locPrest,'cLocPrestacao',substr(nfse_emitter_digits($context['service_city_ibge']),0,7));
    $cServ = nfse_emitter_xml_element($dom,'cServ'); $serv->appendChild($cServ);
    $cTribNac = str_pad(substr(nfse_emitter_digits($tax['nfse_national_tax_code'] ?? ''),0,6),6,'0',STR_PAD_LEFT);
    nfse_emitter_xml_add($dom,$cServ,'cTribNac',$cTribNac);
    $cTribMun = substr(nfse_emitter_digits($tax['municipal_tax_code'] ?? ''),0,10);
    if ($cTribMun !== '') nfse_emitter_xml_add($dom,$cServ,'cTribMun',$cTribMun);
    nfse_emitter_xml_add($dom,$cServ,'xDescServ',$context['description']);
    // Em Itabira, incluir NBS quando todos os dados obrigatórios estiverem válidos.
    // Nunca omitir silenciosamente apenas por ser o perfil ITABIRA_MG.
    if ($includeRtc) {
      $nbs = substr(nfse_emitter_digits($tax['nbs_code'] ?? ''),0,9);
      if (strlen($nbs) === 9) nfse_emitter_xml_add($dom,$cServ,'cNBS',$nbs);
    }

    // Itabira/ISS Digital: quando um mesmo cTribNac possui mais de uma atividade
    // municipal vigente, o provider exige cIntContrib para desambiguar o serviço.
    // Reaproveitamos o CNAE fiscal já cadastrado no produto/regra, preservando
    // cTribMun (ex.: 002) exatamente como na NFS-e 1495 homologada.
    if (nfse_pronim_profile($cfg) === 'ITABIRA_MG') {
      $cIntContrib = substr(preg_replace('/[^a-zA-Z0-9]+/', '', (string)($tax['cnae'] ?? '')) ?: '', 0, 20);
      if ($cIntContrib !== '') nfse_emitter_xml_add($dom, $cServ, 'cIntContrib', $cIntContrib);
    }

    $valores = nfse_emitter_xml_element($dom,'valores'); $inf->appendChild($valores);
    $vServPrest = nfse_emitter_xml_element($dom,'vServPrest'); $valores->appendChild($vServPrest);
    nfse_emitter_xml_add($dom,$vServPrest,'vServ',nfse_emitter_money($context['gross']));
    if ((float)$context['discount'] > 0) {
      $desc = nfse_emitter_xml_element($dom,'vDescCondIncond'); $valores->appendChild($desc);
      nfse_emitter_xml_add($dom,$desc,'vDescIncond',nfse_emitter_money($context['discount']));
    }
    $trib = nfse_emitter_xml_element($dom,'trib'); $valores->appendChild($trib);
    $tribMun = nfse_emitter_xml_element($dom,'tribMun'); $trib->appendChild($tribMun);
    nfse_emitter_xml_add($dom,$tribMun,'tribISSQN',nfse_emitter_iss_taxation($tax));
    nfse_emitter_xml_add($dom,$tribMun,'tpRetISSQN',!empty($tax['iss_withheld']) ? '2' : '1');
    if ((float)($tax['iss_rate'] ?? 0) > 0) nfse_emitter_xml_add($dom,$tribMun,'pAliq',nfse_emitter_percent($tax['iss_rate']));

    $net = max(0.0,(float)$context['net']);
    $pisRetention = nfse_emitter_code_from_label($tax['pis_cofins_retention_type'] ?? '', [
      'pis/cofins/csll nao retidos' => '0',
      'pis/cofins retido' => '1',
      'pis/cofins nao retido' => '2',
      'pis/cofins/csll retidos' => '3',
      'pis/cofins retidos, csll nao retido' => '4',
      'pis retido, cofins/csll nao retido' => '5',
      'cofins retido, pis/csll nao retido' => '6',
      'pis nao retido, cofins/csll retidos' => '7',
      'pis/cofins nao retidos, csll retido' => '8',
      'cofins nao retido, pis/csll retidos' => '9',
    ], '');
    $pisRate = (float)($tax['pis_rate'] ?? 0);
    $cofinsRate = (float)($tax['cofins_rate'] ?? 0);
    $irrfRate = (float)($tax['irrf_rate'] ?? 0);
    $csllRate = (float)($tax['csll_rate'] ?? 0);
    $inssRate = (float)($tax['inss_rate'] ?? 0);
    if ($pisRetention !== '' || $pisRate > 0 || $cofinsRate > 0 || $irrfRate > 0 || $csllRate > 0 || $inssRate > 0) {
      $tribFed = nfse_emitter_xml_element($dom,'tribFed'); $trib->appendChild($tribFed);
      if ($pisRetention !== '' || $pisRate > 0 || $cofinsRate > 0) {
        $pc = nfse_emitter_xml_element($dom,'piscofins'); $tribFed->appendChild($pc);
        $pisCst = str_pad(substr(nfse_emitter_digits($tax['pis_cst'] ?? '00'),0,2),2,'0',STR_PAD_LEFT);
        nfse_emitter_xml_add($dom,$pc,'CST',$pisCst);
        if ($pisRate > 0 || $cofinsRate > 0) {
          nfse_emitter_xml_add($dom,$pc,'vBCPisCofins',nfse_emitter_money($net));
          // A ordem dos elementos precisa seguir o XSD: alíquotas antes dos valores.
          if ($pisRate > 0) nfse_emitter_xml_add($dom,$pc,'pAliqPis',nfse_emitter_percent($pisRate));
          if ($cofinsRate > 0) nfse_emitter_xml_add($dom,$pc,'pAliqCofins',nfse_emitter_percent($cofinsRate));
          if ($pisRate > 0) nfse_emitter_xml_add($dom,$pc,'vPis',nfse_emitter_money($net*$pisRate/100));
          if ($cofinsRate > 0) nfse_emitter_xml_add($dom,$pc,'vCofins',nfse_emitter_money($net*$cofinsRate/100));
        }
        if ($pisRetention !== '') nfse_emitter_xml_add($dom,$pc,'tpRetPisCofins',$pisRetention);
      }
      if ($inssRate > 0) nfse_emitter_xml_add($dom,$tribFed,'vRetCP',nfse_emitter_money($net*$inssRate/100));
      if ($irrfRate > 0) nfse_emitter_xml_add($dom,$tribFed,'vRetIRRF',nfse_emitter_money($net*$irrfRate/100));
      if ($csllRate > 0) nfse_emitter_xml_add($dom,$tribFed,'vRetCSLL',nfse_emitter_money($net*$csllRate/100));
    }
    $totTrib = nfse_emitter_xml_element($dom,'totTrib'); $trib->appendChild($totTrib);
    nfse_emitter_xml_add($dom,$totTrib,'indTotTrib','0');

    $cIndOp = substr(nfse_emitter_digits($tax['nfse_national_operation_indicator'] ?? ''),0,6);
    $ibsCbsCst = str_pad(substr(nfse_emitter_digits($tax['ibs_cbs_cst'] ?? ''),0,3),3,'0',STR_PAD_LEFT);
    $classCode = substr(nfse_emitter_digits($tax['ibs_cbs_classification_code'] ?? ''),0,6);
    // Para Itabira, NBS e IBSCBS são restaurados conjuntamente,
    // após validação obrigatória dos códigos fiscais do serviço.
    if ($includeRtc && strlen($cIndOp) === 6 && strlen($ibsCbsCst) === 3 && strlen($classCode) === 6) {
      $ibscbs = nfse_emitter_xml_element($dom,'IBSCBS'); $inf->appendChild($ibscbs);
      nfse_emitter_xml_add($dom,$ibscbs,'finNFSe','0');
      nfse_emitter_xml_add($dom,$ibscbs,'indFinal','0');
      nfse_emitter_xml_add($dom,$ibscbs,'cIndOp',$cIndOp);
      $tpOper = nfse_emitter_operation_type($tax);
      if ($tpOper !== '') nfse_emitter_xml_add($dom,$ibscbs,'tpOper',$tpOper);
      nfse_emitter_xml_add($dom,$ibscbs,'indDest','0');
      $ibsValores = nfse_emitter_xml_element($dom,'valores'); $ibscbs->appendChild($ibsValores);
      $ibsTrib = nfse_emitter_xml_element($dom,'trib'); $ibsValores->appendChild($ibsTrib);
      $g = nfse_emitter_xml_element($dom,'gIBSCBS'); $ibsTrib->appendChild($g);
      nfse_emitter_xml_add($dom,$g,'CST',$ibsCbsCst);
      nfse_emitter_xml_add($dom,$g,'cClassTrib',$classCode);
    }

    $xml = $dom->saveXML($dom->documentElement, LIBXML_NOXMLDECL);
    if (!is_string($xml) || $xml === '') throw new RuntimeException('Falha ao gerar o XML da DPS.');
    return $xml;
  }
}


if (!function_exists('nfse_pronim_sign_xml')) {
  function nfse_pronim_sign_xml(PDO $pdo, int $companyId, string $xml): string {
    nfse_emitter_require_common();
    $cert = fiscal_nfce_active_certificate($pdo,$companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    if ($password === '') throw new RuntimeException('A senha do certificado A1 não está salva.');
    $certificate = \NFePHP\Common\Certificate::readPfx($pfx,$password);
    $canonical=[false,false,null,null];
    $signed=\NFePHP\Common\Signer::sign($certificate,$xml,'infDPS','Id',OPENSSL_ALGO_SHA1,$canonical,'DPS');
    $signed=nfse_emitter_ensure_utf8_xml($signed);
    if (!\NFePHP\Common\Signer::isSigned($signed,'infDPS',$canonical)) throw new RuntimeException('A assinatura SHA-1 da DPS Pronim não pôde ser validada localmente.');
    $errs=nfse_pronim_verify_signature($signed);
    if($errs) throw new RuntimeException('Assinatura Pronim inválida: '.implode(' | ',$errs));
    return $signed;
  }
}

if (!function_exists('nfse_pronim_verify_signature')) {
  function nfse_pronim_verify_signature(string $xml): array {
    $errors=[]; $dom=new DOMDocument('1.0','UTF-8'); $dom->preserveWhiteSpace=false; $dom->formatOutput=false;
    libxml_use_internal_errors(true);
    if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)){foreach(libxml_get_errors() as $e)$errors[]='XML inválido: '.trim($e->message);libxml_clear_errors();return $errors?:['XML inválido.'];}
    libxml_clear_errors();
    $xp=new DOMXPath($dom); $xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
    $sig=$xp->query('//ds:Signature')->item(0); if(!$sig instanceof DOMElement)return ['Elemento Signature ausente.'];
    $si=$xp->query('.//ds:SignedInfo',$sig)->item(0); $ref=$xp->query('.//ds:Reference',$sig)->item(0); $dm=$xp->query('.//ds:DigestMethod',$sig)->item(0); $dv=$xp->query('.//ds:DigestValue',$sig)->item(0); $sm=$xp->query('.//ds:SignatureMethod',$sig)->item(0); $sv=$xp->query('.//ds:SignatureValue',$sig)->item(0); $xc=$xp->query('.//ds:X509Certificate',$sig)->item(0);
    if(!$si||!$ref||!$dm||!$dv||!$sm||!$sv||!$xc)return ['Assinatura incompleta.'];
    if((string)$sm->attributes->getNamedItem('Algorithm')?->nodeValue!=='http://www.w3.org/2000/09/xmldsig#rsa-sha1')$errors[]='SignatureMethod deve ser RSA-SHA1 para o perfil Pronim homologado.';
    if((string)$dm->attributes->getNamedItem('Algorithm')?->nodeValue!=='http://www.w3.org/2000/09/xmldsig#sha1')$errors[]='DigestMethod deve ser SHA-1 para o perfil Pronim homologado.';
    $uri=(string)$ref->attributes->getNamedItem('URI')?->nodeValue; $id=ltrim($uri,'#'); $node=$xp->query('//*[@Id="'.$id.'"]')->item(0); if(!$node instanceof DOMElement)return array_merge($errors,['Nó assinado não encontrado.']);
    $canon=$node->C14N(false,false); $calc=base64_encode(hash('sha1',(string)$canon,true)); $informed=preg_replace('/\s+/','',(string)$dv->textContent)?:''; if(!hash_equals($informed,$calc))$errors[]='DigestValue SHA-1 incompatível.';
    $canonSi=$si->C14N(false,false); $cert64=preg_replace('/\s+/','',(string)$xc->textContent)?:''; $pem="-----BEGIN CERTIFICATE-----\n".chunk_split($cert64,64,"\n")."-----END CERTIFICATE-----\n"; $pub=openssl_pkey_get_public($pem); $sigBytes=base64_decode(preg_replace('/\s+/','',(string)$sv->textContent)?:'',true);
    if($pub===false||$sigBytes===false||openssl_verify((string)$canonSi,$sigBytes,$pub,OPENSSL_ALGO_SHA1)!==1)$errors[]='SignatureValue SHA-1 inválido.';
    return array_values(array_unique($errors));
  }
}

if (!function_exists('nfse_pronim_validate_xml')) {
  function nfse_pronim_validate_xml(string $xml, bool $requireRtc = false): array {
    $errors=[];
    if(str_starts_with($xml,"\xEF\xBB\xBF"))$errors[]='XML não pode iniciar com BOM.';
    if(preg_match('//u',$xml)!==1)$errors[]='XML contém bytes inválidos em UTF-8.';
    $dom=new DOMDocument('1.0','UTF-8'); $dom->preserveWhiteSpace=false; libxml_use_internal_errors(true);
    if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)){foreach(libxml_get_errors() as $e)$errors[]='XML: '.trim($e->message);libxml_clear_errors();return array_values(array_unique($errors));} libxml_clear_errors();
    $xp=new DOMXPath($dom); $text=fn($q)=>trim((string)($xp->query($q)->item(0)?->textContent??''));
    if($dom->documentElement?->localName!=='DPS'||$dom->documentElement?->getAttribute('versao')!=='1.01')$errors[]='Raiz DPS versão 1.01 obrigatória.';
    if($text('//*[local-name()="tpEmit"]')!=='1')$errors[]='tpEmit deve ser 1-Prestador.';
    $im=$text('//*[local-name()="prest"]/*[local-name()="IM"]'); if($im==='')$errors[]='IM do prestador é obrigatória.';
    if($xp->query('//*[local-name()="prest"]/*[local-name()="NIF" or local-name()="cNaoNIF"]')->length>0)$errors[]='Prestador não pode usar NIF/cNaoNIF no ISS Digital.';
    $series=(int)preg_replace('/\D+/','',$text('//*[local-name()="serie"]')); if($series<1||$series>=49990)$errors[]='Série deve estar entre 1 e 49989.';
    if(strlen(preg_replace('/\D+/','',$text('//*[local-name()="cTribNac"]'))?:'')!==6)$errors[]='cTribNac deve possuir 6 dígitos.';
    $nbs=preg_replace('/\D+/','',$text('//*[local-name()="cNBS"]'))?:''; if($nbs!==''&&strlen($nbs)!==9)$errors[]='cNBS deve possuir 9 dígitos.';
    // Para Itabira, obrigar NBS + IBSCBS em toda nova DPS.
    // Demais municípios mantêm seu comportamento anterior.
    $municipio = preg_replace('/\D+/', '', $text('//*[local-name()="cLocEmi"]')) ?: '';
    $hasIbsCbs = $xp->query('//*[local-name()="IBSCBS"]')->length > 0;
    if (($municipio !== '3131703' || $requireRtc) && !$hasIbsCbs) $errors[]='Grupo IBS/CBS ausente no XML da DPS.';
    if ($hasIbsCbs && $nbs === '') $errors[]='Quando informado IBSCBS, cNBS deve estar presente.';
    if ($requireRtc) {
      if (strlen($nbs) !== 9) $errors[]='NBS obrigatório: cNBS deve conter 9 dígitos.';
      foreach (['cIndOp'=>6, 'CST'=>3, 'cClassTrib'=>6] as $field=>$length) {
        $query = $field === 'CST'
          ? '//*[local-name()="IBSCBS"]/*[local-name()="valores"]/*[local-name()="trib"]/*[local-name()="gIBSCBS"]/*[local-name()="CST"]'
          : '//*[local-name()="IBSCBS"]//*[local-name()="' . $field . '"]';
        $digits = nfse_emitter_digits($text($query));
        if (strlen($digits) !== $length) $errors[]=$field.' IBS/CBS deve conter '.$length.' dígitos.';
      }
    }
    $xsd=nfse_pronim_schema_path();
    if($xsd!==''){
      libxml_use_internal_errors(true); if(!$dom->schemaValidate($xsd)){foreach(libxml_get_errors() as $e)$errors[]='XSD 1.01: '.trim($e->message);} libxml_clear_errors();
    }
    $errors=array_merge($errors,nfse_pronim_verify_signature($xml));
    return array_values(array_unique(array_filter($errors)));
  }
}

if (!function_exists('nfse_pronim_http')) {
  function nfse_pronim_http(PDO $pdo,int $companyId,array $cfg,string $environment,string $method,string $operation,?array $jsonPayload=null): array {
    if(!function_exists('curl_init'))throw new RuntimeException('A extensão cURL do PHP é obrigatória para o ISS Digital/Pronim.');
    $base=nfse_pronim_api_base($cfg,$environment); if($base==='')throw new RuntimeException('Endpoint Pronim não configurado para este ambiente.');
    $op=trim($operation,'/'); $url=$op===''?rtrim($base,'/'):rtrim($base,'/').'/'.$op;
    $headers=['Accept: application/json, application/xml, text/xml','User-Agent: Nexa-Prospero-Pronim/1.01']; $body=null;
    if($jsonPayload!==null){$body=json_encode($jsonPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($body))throw new RuntimeException('Falha ao gerar JSON da DPS Pronim.');$headers[]='Content-Type: application/json; charset=UTF-8';$headers[]='Content-Length: '.strlen($body);}
    $pem=nfse_emitter_certificate_pem_files($pdo,$companyId); $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>90,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSLCERT=>$pem['cert'],CURLOPT_SSLKEY=>$pem['key'],CURLOPT_SSLCERTTYPE=>'PEM',CURLOPT_SSLKEYTYPE=>'PEM',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]); if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    try{$response=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);}finally{curl_close($ch);nfse_emitter_cleanup_pem($pem);} if($response===false)$response='';
    return ['url'=>$url,'method'=>strtoupper($method),'status'=>$status,'body'=>(string)$response,'content_type'=>$contentType,'curl_errno'=>$errno,'curl_error'=>$error,'request_body'=>$body];
  }
}

if (!function_exists('nfse_pronim_xml_info')) {
  function nfse_pronim_xml_info(string $xml): array {
    $out=nfse_emitter_xml_info($xml);
    $out['status_code']=''; $out['status_message']=''; $out['provider_app']='';
    if(trim($xml)==='')return $out;
    $dom=new DOMDocument(); libxml_use_internal_errors(true);
    if($dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)){
      $xp=new DOMXPath($dom);
      $read=static function(DOMXPath $xp,string $q): string { $n=$xp->query($q)->item(0); return $n?trim((string)$n->textContent):''; };
      $auth=$read($xp,'//*[local-name()="codAutenticidade"]'); if($auth!=='')$out['verification_code']=$auth;
      $out['status_code']=$read($xp,'//*[local-name()="infNFSe"]/*[local-name()="cStat"]');
      $out['status_message']=$read($xp,'//*[local-name()="infNFSe"]/*[local-name()="xMotivo"]');
      if($out['status_message']==='')$out['status_message']=$read($xp,'//*[local-name()="xMotivo"]');
      $out['provider_app']=$read($xp,'//*[local-name()="infNFSe"]/*[local-name()="verAplic"]');
    }
    libxml_clear_errors(); return $out;
  }
}

if (!function_exists('nfse_pronim_mark_provider')) {
  function nfse_pronim_mark_provider(PDO $pdo,int $companyId,int $docId): void {
    if(fiscal_column_exists($pdo,'fiscal_nfse_details','provider_code'))$pdo->prepare("UPDATE fiscal_nfse_details SET provider_code='PRONIM' WHERE company_id=? AND fiscal_document_id=?")->execute([$companyId,$docId]);
  }
}

if (!function_exists('nfse_pronim_prepare_order')) {
  function nfse_pronim_prepare_order(PDO $pdo,int $companyId,int $orderId): array {
    nfse_pronim_require_schema($pdo); $context=nfse_emitter_order_context($pdo,$companyId,$orderId); if($context['issues'])throw new RuntimeException(implode(' ',$context['issues']));
    $cfg=$context['config']; $provider=strtoupper(trim((string)($cfg['nfse_provider']??''))); if(!in_array($provider,['PRONIM','GOVBR','ISS_DIGITAL','PRONIM_GOVBR'],true))throw new RuntimeException('Selecione Pronim / GOVBR como provedor NFS-e.');
    $series=nfse_nacional_active_series($pdo,$companyId,(string)$context['environment']); $seriesInt=$series?(int)nfse_emitter_digits($series['series']??''):0; if($seriesInt<1||$seriesInt>=49990)throw new RuntimeException('A série DPS do ISS Digital deve estar entre 1 e 49989.');
    $doc=nfse_emitter_reserve_document($pdo,$companyId,$orderId,$context); $docId=(int)$doc['id']; nfse_pronim_mark_provider($pdo,$companyId,$docId);
    $xml=nfse_pronim_build_dps_xml($context,$doc); $signed=nfse_pronim_sign_xml($pdo,$companyId,$xml); $errors=nfse_pronim_validate_xml($signed, nfse_pronim_itabira_rtc_enabled($cfg, $companyId)); if($errors)throw new RuntimeException('DPS Pronim inválida: '.implode(' | ',$errors));
    $dpsId=nfse_emitter_dps_id($cfg,(string)($doc['dps_series']??$doc['series']),(int)($doc['dps_number']??$doc['number'])); $path=nfse_emitter_store_xml_file($companyId,(string)$context['environment'],$docId,'dps-pronim-assinada',$signed);
    $pdo->prepare("UPDATE fiscal_documents SET status='ASSINADA',xml_path=?,xml_raw=?,xml_hash=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$path,$signed,hash('sha256',$signed),$companyId,$docId]);
    $pdo->prepare("UPDATE fiscal_nfse_details SET status='ASSINADA',provider_code='PRONIM',dps_id=?,dps_xml=?,signed_dps_xml=?,request_hash=?,api_error_code=NULL,api_error_message=NULL WHERE company_id=? AND fiscal_document_id=?")->execute([$dpsId,$xml,$signed,hash('sha256',$signed),$companyId,$docId]);
    return nfse_emitter_document_by_id($pdo,$companyId,$docId)?:$doc;
  }
}

if (!function_exists('nfse_pronim_success_from_http')) {
  function nfse_pronim_success_from_http(PDO $pdo,int $companyId,int $docId,array $http): array {
    $parsed=nfse_emitter_response_data((string)$http['body']);
    if((int)$http['status']>=200 && (int)$http['status']<300 && $parsed['xml']!==''){
      $info=nfse_pronim_xml_info($parsed['xml']);
      if($info['access_key']==='' && !empty($parsed['access_key']))$info['access_key']=nfse_emitter_digits($parsed['access_key']);
      if($info['status_code']!=='' && $info['status_code']!=='100'){
        $message=$info['status_message']!==''?$info['status_message']:('ISS Digital retornou cStat '.$info['status_code'].'.');
        nfse_emitter_mark_error($pdo,$companyId,$docId,'REJEITADA',$info['status_code'],$message,(int)$http['status'],(string)$http['body']);
        return ['ok'=>false,'ambiguous'=>false,'code'=>$info['status_code'],'message'=>$message,'http'=>$http,'response'=>$parsed];
      }
      if($info['access_key']===''){
        $message='A API Pronim retornou XML, mas a chave da NFS-e não foi identificada. Consulte antes de reenviar.';
        nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO','SEM_CHAVE',$message,(int)$http['status'],(string)$http['body']);
        return ['ok'=>false,'ambiguous'=>true,'message'=>$message,'http'=>$http,'response'=>$parsed];
      }
      $doc=nfse_emitter_document_by_id($pdo,$companyId,$docId); $env=(string)($doc['environment']??'HOMOLOGACAO');
      $xmlPath=nfse_emitter_store_xml_file($companyId,$env,$docId,'nfse-pronim-autorizada',$parsed['xml']);
      $pdo->prepare("UPDATE fiscal_documents SET access_key=?,protocol_number=?,status='AUTORIZADA',sefaz_status_code='100',sefaz_status_message='NFS-e autorizada pelo ISS Digital',authorization_datetime=COALESCE(authorization_datetime,NOW()),xml_path=?,xml_raw=?,xml_hash=?,updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$info['access_key'],$info['protocol'],$xmlPath,$parsed['xml'],hash('sha256',$parsed['xml']),$companyId,$docId]);
      $pdo->prepare("UPDATE fiscal_nfse_details SET status='AUTORIZADA',provider_code='PRONIM',nfse_number=?,nfse_id=?,verification_code=?,response_xml=?,response_payload=?,api_http_status=?,api_error_code=NULL,api_error_message=NULL,attempts=attempts+1,authorized_at=COALESCE(authorized_at,NOW()),last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
          ->execute([$info['nfse_number']!==''?(int)$info['nfse_number']:null,$info['nfse_id'],$info['verification_code'],$parsed['xml'],(string)$http['body'],(int)$http['status'],$companyId,$docId]);
      return ['ok'=>true,'access_key'=>$info['access_key'],'nfse_number'=>$info['nfse_number'],'document'=>nfse_emitter_document_by_id($pdo,$companyId,$docId),'http'=>$http,'response'=>$parsed];
    }
    if((int)$http['status']>=200 && (int)$http['status']<300 && !empty($parsed['access_key'])){
      $key=nfse_emitter_digits($parsed['access_key']);
      if($key!==''){
        $pdo->prepare("UPDATE fiscal_documents SET access_key=?,status='ERRO_COMUNICACAO',sefaz_status_code='PROCESSANDO',sefaz_status_message='Chave recebida sem XML; consulta obrigatória',updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$key,$companyId,$docId]);
        $pdo->prepare("UPDATE fiscal_nfse_details SET status='ERRO_COMUNICACAO',provider_code='PRONIM',response_payload=?,api_http_status=?,api_error_code='SEM_XML',api_error_message='Chave recebida sem XML; consulte antes de reenviar.',attempts=attempts+1,last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
          ->execute([(string)$http['body'],(int)$http['status'],$companyId,$docId]);
        return ['ok'=>false,'ambiguous'=>true,'access_key'=>$key,'message'=>'A API retornou a chave, mas não devolveu o XML autorizado. Use Consultar NFS-e; não retransmita a DPS.','http'=>$http,'response'=>$parsed];
      }
    }
    $code=$parsed['code']!==''?$parsed['code']:(string)$http['status']; $message=$parsed['message']!==''?$parsed['message']:('ISS Digital/Pronim rejeitou a DPS. HTTP '.$http['status'].'.');
    if((int)$http['status']===403)$message.=' Verifique se o certificado/CNPJ está liberado para integração na GOVBR/Prefeitura.';
    $ambiguous=(int)$http['status']>=500||in_array((int)$http['status'],[408,409],true);
    nfse_emitter_mark_error($pdo,$companyId,$docId,$ambiguous?'ERRO_COMUNICACAO':'REJEITADA',$code,$message,(int)$http['status'],(string)$http['body']);
    return ['ok'=>false,'ambiguous'=>$ambiguous,'code'=>$code,'message'=>$message,'http'=>$http,'response'=>$parsed];
  }
}

if (!function_exists('nfse_pronim_authorize_document_unlocked')) {
  function nfse_pronim_authorize_document_unlocked(PDO $pdo,int $companyId,int $docId): array {
    nfse_pronim_require_schema($pdo); nfse_emitter_require_runtime(true);
    $doc=nfse_emitter_document_by_id($pdo,$companyId,$docId); if(!$doc)throw new RuntimeException('Documento NFS-e não encontrado.');
    $status=strtoupper((string)($doc['status']??'')); if($status==='AUTORIZADA')return ['ok'=>true,'document'=>$doc,'already_authorized'=>true];
    if(in_array($status,['TRANSMITINDO','ERRO_COMUNICACAO'],true))throw new RuntimeException('Existe transmissão com resultado incerto. Use Recuperar por DPS antes de qualquer novo envio.');
    $docEnvironment=strtoupper(trim((string)($doc['environment']??'')));
    if(!in_array($docEnvironment,['HOMOLOGACAO','PRODUCAO'],true))throw new RuntimeException('Ambiente gravado na DPS é inválido. Gere uma nova DPS.');
    // Em uma NFS-e de O.S., source_id e o ID da O.S., nao o do pedido.
    // O vinculo fiscal verdadeiro do pedido consta em fiscal_nfse_details.pedido_id.
    $originType = strtoupper(trim((string)($doc['source_type'] ?? '')));
    $linkedPedidoId = (int)($doc['pedido_id'] ?? 0);
    $sourceId = (int)($doc['source_id'] ?? 0);
    if ($originType === 'ORDEM_SERVICO') {
      if ($linkedPedidoId <= 0) throw new RuntimeException('NFS-e de O.S. sem pedido faturado vinculado. Transmissao bloqueada.');
      $orderId = $linkedPedidoId;
    } elseif ($originType === 'PEDIDO') {
      if ($linkedPedidoId > 0 && $sourceId > 0 && $linkedPedidoId !== $sourceId) {
        throw new RuntimeException('Vinculos divergentes da NFS-e de pedido. Transmissao bloqueada para conferencia.');
      }
      $orderId = $linkedPedidoId > 0 ? $linkedPedidoId : $sourceId;
    } else {
      throw new RuntimeException('Origem fiscal nao reconhecida para transmissao da NFS-e.');
    }
    if ($orderId <= 0) throw new RuntimeException('Pedido da NFS-e nao identificado.');
    $context=nfse_emitter_order_context($pdo,$companyId,$orderId);
    if($context['issues'])throw new RuntimeException(implode(' ',$context['issues']));
    $contextEnvironment=strtoupper(trim((string)($context['environment']??'')));
    if($contextEnvironment!==$docEnvironment){
      throw new RuntimeException('O ambiente fiscal da empresa foi alterado após a geração desta DPS. Esta DPS pertence a '.$docEnvironment.'; gere uma nova DPS no ambiente atual antes de transmitir.');
    }
    $context['environment']=$docEnvironment;
    $cfg=$context['config']; if($docEnvironment==='PRODUCAO'&&!nfse_pronim_production_enabled())throw new RuntimeException('Transmissão em PRODUÇÃO não confirmada. Digite EMITIR na tela fiscal para autorizar esta requisição.');
    // Uma DPS apenas ASSINADA deve ser transmitida exatamente como o operador a conferiu.
    // Porém, após REJEIÇÃO fiscal, o botão de correção precisa reconstruir a MESMA DPS
    // (mesma série/número) usando o cadastro/regra fiscal ATUAL do produto/serviço.
    // Isso permite corrigir NBS, cTribMun, CST, cClassTrib etc. sem avançar a numeração.
    $storedSigned=trim((string)($doc['signed_dps_xml']??''));
    $rebuildAfterRejection=($status==='REJEITADA');
    if($storedSigned!=='' && !$rebuildAfterRejection){
      $signed=$storedSigned;
      $xml=trim((string)($doc['dps_xml']??''));
      if($xml==='')$xml=$signed;
    }else{
      $xml=nfse_pronim_build_dps_xml($context,$doc);
      $signed=nfse_pronim_sign_xml($pdo,$companyId,$xml);
    }
    $validation=nfse_pronim_validate_xml($signed, nfse_pronim_itabira_rtc_enabled($cfg, $companyId));
    if ($validation && $storedSigned !== '' && !$rebuildAfterRejection && nfse_pronim_itabira_rtc_enabled($cfg, $companyId)) {
      throw new RuntimeException('DPS previamente ASSINADA não atende às exigências atuais de NBS/IBS/CBS. Não retransmita nem recrie o documento: verifique o status no Pronim e a preparação fiscal antes de prosseguir. '.implode(' | ', $validation));
    }
    if($validation)throw new RuntimeException('DPS Pronim inválida: '.implode(' | ',$validation));
    $dpsId=nfse_emitter_dps_id($cfg,(string)($doc['dps_series']??$doc['series']),(int)($doc['dps_number']??$doc['number']));
    $gzip=gzencode($signed,9,FORCE_GZIP); if($gzip===false)throw new RuntimeException('Não foi possível compactar a DPS Pronim.');
    $payload=['loteXmlGZipB64'=>[base64_encode($gzip)]]; $payloadJson=json_encode($payload,JSON_UNESCAPED_SLASHES); $hash=hash('sha256',$signed);
    $path=nfse_emitter_store_xml_file($companyId,(string)$context['environment'],$docId,'dps-pronim-assinada',$signed);
    $pdo->prepare("UPDATE fiscal_documents SET status='TRANSMITINDO',xml_path=?,xml_raw=?,xml_hash=?,issue_datetime=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")->execute([$path,$signed,$hash,$companyId,$docId]);
    $pdo->prepare("UPDATE fiscal_nfse_details SET status='TRANSMITINDO',provider_code='PRONIM',dps_id=?,dps_xml=?,signed_dps_xml=?,request_payload=?,request_hash=?,api_error_code=NULL,api_error_message=NULL,last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
        ->execute([$dpsId,$xml,$signed,$payloadJson,$hash,$companyId,$docId]);
    $http=nfse_pronim_http($pdo,$companyId,$cfg,(string)$context['environment'],'POST','EnviarSincrono',$payload);
    if((int)$http['curl_errno']!==0||(int)$http['status']===0){$message='Falha de comunicação com o ISS Digital/Pronim: '.((string)$http['curl_error']?:'sem resposta HTTP').'. Não retransmita; use Recuperar por DPS.';nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO','CURL',$message,(int)$http['status'],(string)$http['body']);return ['ok'=>false,'ambiguous'=>true,'code'=>'CURL','message'=>$message,'http'=>$http];}
    return nfse_pronim_success_from_http($pdo,$companyId,$docId,$http);
  }
}

if (!function_exists('nfse_pronim_authorize_document')) {
  function nfse_pronim_authorize_document(PDO $pdo,int $companyId,int $docId): array {
    $lock='nexa_nfse_pronim_'.$companyId.'_'.$docId; $st=$pdo->prepare('SELECT GET_LOCK(?,0)');$st->execute([$lock]);if((int)$st->fetchColumn()!==1)throw new RuntimeException('Este documento já está sendo processado. Aguarde e atualize a tela.');
    try{return nfse_pronim_authorize_document_unlocked($pdo,$companyId,$docId);}finally{try{$r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lock]);}catch(Throwable $e){}}
  }
}

if (!function_exists('nfse_pronim_authorize_order')) {
  function nfse_pronim_authorize_order(PDO $pdo,int $companyId,int $orderId): array {
    $doc=nfse_pronim_prepare_order($pdo,$companyId,$orderId); return nfse_pronim_authorize_document($pdo,$companyId,(int)$doc['id']);
  }
}

if (!function_exists('nfse_pronim_query_by_key')) {
  function nfse_pronim_query_by_key(PDO $pdo,int $companyId,int $docId,string $accessKey): array {
    nfse_pronim_require_schema($pdo); $doc=nfse_emitter_document_by_id($pdo,$companyId,$docId); if(!$doc)throw new RuntimeException('Documento NFS-e não encontrado.');
    $key=nfse_emitter_digits($accessKey); if($key==='')throw new RuntimeException('Chave de acesso não informada.'); $cfg=fiscal_company_config($pdo,$companyId);
    $http=nfse_pronim_http($pdo,$companyId,$cfg,(string)$doc['environment'],'GET','ConsultarNFSe/'.rawurlencode($key),null); $parsed=nfse_emitter_response_data((string)$http['body']);
    if((int)$http['status']>=200&&(int)$http['status']<300&&$parsed['xml']!=='')return nfse_pronim_success_from_http($pdo,$companyId,$docId,$http);
    $message=$parsed['message']?:('Consulta Pronim retornou HTTP '.(int)$http['status'].'.');
    // Uma falha de consulta nunca deve rebaixar uma NFS-e já autorizada.
    if (strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA') {
      $pdo->prepare("UPDATE fiscal_nfse_details SET api_http_status=?,api_error_code=?,api_error_message=?,response_payload=?,consulted_at=NOW(),last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
        ->execute([(int)$http['status'],substr((string)($parsed['code']?:'CONSULTA'),0,40),nfse_emitter_text($message,4000),(string)$http['body'],$companyId,$docId]);
      return ['ok'=>false,'message'=>$message,'http'=>$http,'authorized_preserved'=>true];
    }
    nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO',$parsed['code']?:'CONSULTA',$message,(int)$http['status'],(string)$http['body']);
    return ['ok'=>false,'message'=>$message,'http'=>$http];
  }
}

if (!function_exists('nfse_pronim_dps_lookup_operation')) {
  /** Rota documentada no Swagger ISS Digital Itabira: documento / serie / numero. */
  function nfse_pronim_dps_lookup_operation(array $cfg, string $series, int $number): string {
    $cnpj = nfse_emitter_digits((string)($cfg['company_cnpj'] ?? ''));
    if (strlen($cnpj) !== 14) throw new RuntimeException('CNPJ do prestador inválido para a consulta da DPS.');
    $series = nfse_emitter_digits($series);
    if ($series === '' || (int)$series < 1 || (int)$series >= 49990 || $number < 1)
      throw new RuntimeException('Série ou número da DPS inválido para consulta.');
    return 'ConsultarDPS/'.rawurlencode($cnpj).'/'.rawurlencode((string)(int)$series).'/'.rawurlencode((string)$number);
  }
}

if (!function_exists('nfse_pronim_recover_by_dps')) {
  function nfse_pronim_recover_by_dps(PDO $pdo,int $companyId,int $docId): array {
    nfse_pronim_require_schema($pdo); $doc=nfse_emitter_document_by_id($pdo,$companyId,$docId); if(!$doc)throw new RuntimeException('Documento NFS-e não encontrado.');
    if(strtoupper((string)($doc['status']??''))==='AUTORIZADA'&&!empty($doc['access_key']))return ['ok'=>true,'document'=>$doc,'already_authorized'=>true];
    $cfg=fiscal_company_config($pdo,$companyId);
    $series=(string)($doc['dps_series']??$doc['series']??'');
    $number=(int)($doc['dps_number']??$doc['number']??0);
    $operation=nfse_pronim_dps_lookup_operation($cfg,$series,$number);
    $http=nfse_pronim_http($pdo,$companyId,$cfg,(string)$doc['environment'],'GET',$operation,null);
    $parsed=nfse_emitter_response_data((string)$http['body']);
    if((int)$http['status']>=200&&(int)$http['status']<300){if($parsed['xml']!=='')return nfse_pronim_success_from_http($pdo,$companyId,$docId,$http);$key=nfse_emitter_digits($parsed['access_key']??'');if($key!=='')return nfse_pronim_query_by_key($pdo,$companyId,$docId,$key);}
    $message=$parsed['message']?:('Consulta da DPS no Pronim retornou HTTP '.(int)$http['status'].'.');
    // 404 pode significar DPS inexistente, rota diferente ou liberação pendente no provider.
    // Por segurança fiscal, não autoriza retransmissão automática apenas por esse HTTP.
    // Consulta não é transmissão: uma falha de rota/404 não deve transformar uma
    // DPS ASSINADA em ERRO_COMUNICACAO nem avançar a sequência fiscal.
    // O operador recebe o diagnóstico pela mensagem de retorno, sem gravar um
    // estado fiscal falso. A inexistência da DPS continua NÃO confirmada.
    if (strtoupper((string)($doc['status'] ?? '')) === 'ASSINADA') {
      return ['ok'=>false,'ambiguous'=>true,
        'message'=>'Consulta inconclusiva (HTTP '.(int)$http['status'].'): '.$message.' A DPS continua ASSINADA no Nexa. Não retransmita sem confirmar a situação no provedor.',
        'http'=>$http];
    }
    nfse_emitter_mark_error($pdo,$companyId,$docId,'ERRO_COMUNICACAO',$parsed['code']?:'CONSULTA_DPS',$message,(int)$http['status'],(string)$http['body']);
    return ['ok'=>false,'ambiguous'=>true,'message'=>$message,'http'=>$http];
  }
}


if (!function_exists('nfse_pronim_event_schema_path')) {
  function nfse_pronim_event_schema_path(): string {
    $bundled = dirname(__DIR__) . '/resources/nfse/nacional/1.01/pedRegEvento_v1.01.xsd';
    return is_file($bundled) ? $bundled : '';
  }
}

if (!function_exists('nfse_pronim_build_cancel_event_xml')) {
  function nfse_pronim_build_cancel_event_xml(array $cfg, array $doc, string $reasonCode, string $reason): string {
    $key = nfse_emitter_digits($doc['access_key'] ?? '');
    if (strlen($key) !== 50) throw new RuntimeException('Chave de acesso da NFS-e inválida para cancelamento.');
    if (!in_array($reasonCode, ['1','2','9'], true)) throw new RuntimeException('Código do motivo de cancelamento inválido. Use 1, 2 ou 9.');
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    $len = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($len < 15 || $len > 255) throw new RuntimeException('O motivo do cancelamento deve possuir entre 15 e 255 caracteres.');
    $author = nfse_emitter_digits($cfg['company_cnpj'] ?? '');
    if (!in_array(strlen($author), [11,14], true)) throw new RuntimeException('CPF/CNPJ do autor do cancelamento inválido.');
    // No leiaute 1.01 efetivamente aceito pelo Pronim, o Id do pedido é PRE + chave(50) + tipo(6),
    // sem nPedRegEvento dentro de infPedReg. O retorno do evento trará nSeqEvento=001.
    $eventType = '101101';
    $eventId = 'PRE' . $key . $eventType;
    $env = strtoupper(trim((string)($doc['environment'] ?? 'HOMOLOGACAO')));
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $tz);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $root = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
    $root->setAttribute('versao', '1.01');
    $dom->appendChild($root);
    $inf = nfse_emitter_xml_element($dom, 'infPedReg');
    $inf->setAttribute('Id', $eventId);
    $root->appendChild($inf);
    nfse_emitter_xml_add($dom, $inf, 'tpAmb', $env === 'PRODUCAO' ? '1' : '2');
    nfse_emitter_xml_add($dom, $inf, 'verAplic', 'NEXA-PRONIM-1.01');
    nfse_emitter_xml_add($dom, $inf, 'dhEvento', $now->format('Y-m-d\\TH:i:sP'));
    nfse_emitter_xml_add($dom, $inf, strlen($author) === 11 ? 'CPFAutor' : 'CNPJAutor', $author);
    nfse_emitter_xml_add($dom, $inf, 'chNFSe', $key);
    $event = nfse_emitter_xml_element($dom, 'e101101');
    $inf->appendChild($event);
    nfse_emitter_xml_add($dom, $event, 'xDesc', 'Cancelamento de NFS-e');
    nfse_emitter_xml_add($dom, $event, 'cMotivo', $reasonCode);
    nfse_emitter_xml_add($dom, $event, 'xMotivo', $reason);
    $xml = $dom->saveXML($dom->documentElement, LIBXML_NOXMLDECL);
    if (!is_string($xml) || $xml === '') throw new RuntimeException('Falha ao gerar o XML do evento de cancelamento.');
    return $xml;
  }
}

if (!function_exists('nfse_pronim_sign_event_xml')) {
  function nfse_pronim_sign_event_xml(PDO $pdo, int $companyId, string $xml): string {
    nfse_emitter_require_common();
    $cert = fiscal_nfce_active_certificate($pdo, $companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    if ($password === '') throw new RuntimeException('A senha do certificado A1 não está salva.');
    $certificate = \NFePHP\Common\Certificate::readPfx($pfx, $password);
    $canonical = [false, false, null, null];
    $signed = \NFePHP\Common\Signer::sign($certificate, $xml, 'infPedReg', 'Id', OPENSSL_ALGO_SHA1, $canonical, 'pedRegEvento');
    $signed = nfse_emitter_ensure_utf8_xml($signed);
    if (!\NFePHP\Common\Signer::isSigned($signed, 'infPedReg', $canonical)) throw new RuntimeException('A assinatura SHA-1 do evento Pronim não pôde ser validada localmente.');
    $errs = nfse_pronim_verify_signature($signed);
    if ($errs) throw new RuntimeException('Assinatura do evento Pronim inválida: ' . implode(' | ', $errs));
    return $signed;
  }
}

if (!function_exists('nfse_pronim_validate_cancel_event_xml')) {
  function nfse_pronim_validate_cancel_event_xml(string $xml): array {
    $errors = [];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
      foreach (libxml_get_errors() as $e) $errors[] = 'XML: ' . trim($e->message);
      libxml_clear_errors();
      return array_values(array_unique($errors ?: ['XML de evento inválido.']));
    }
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $text = static fn(string $q): string => trim((string)($xp->query($q)->item(0)?->textContent ?? ''));
    if ($dom->documentElement?->localName !== 'pedRegEvento' || $dom->documentElement?->getAttribute('versao') !== '1.01') $errors[] = 'Raiz pedRegEvento versão 1.01 obrigatória.';
    if ($xp->query('//*[local-name()="nPedRegEvento"]')->length > 0) $errors[] = 'No perfil Pronim 1.01 não envie nPedRegEvento dentro de infPedReg.';
    if ($xp->query('//*[local-name()="e101101"]')->length !== 1) $errors[] = 'Evento e101101 de cancelamento obrigatório.';
    if (!in_array($text('//*[local-name()="e101101"]/*[local-name()="cMotivo"]'), ['1','2','9'], true)) $errors[] = 'cMotivo do cancelamento deve ser 1, 2 ou 9.';
    $reason = $text('//*[local-name()="e101101"]/*[local-name()="xMotivo"]');
    $len = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($len < 15 || $len > 255) $errors[] = 'xMotivo deve possuir entre 15 e 255 caracteres.';
    $xsd = nfse_pronim_event_schema_path();
    if ($xsd !== '') {
      libxml_use_internal_errors(true);
      if (!$dom->schemaValidate($xsd)) foreach (libxml_get_errors() as $e) $errors[] = 'XSD evento 1.01: ' . trim($e->message);
      libxml_clear_errors();
    }
    $errors = array_merge($errors, nfse_pronim_verify_signature($xml));
    return array_values(array_unique(array_filter($errors)));
  }
}

if (!function_exists('nfse_pronim_event_info')) {
  function nfse_pronim_event_info(string $xml): array {
    $out = ['valid'=>false,'key'=>'','event_type'=>'','sequence'=>'','processed_at'=>'','event_id'=>''];
    if (trim($xml) === '') return $out;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) { libxml_clear_errors(); return $out; }
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $read = static function(string $q) use ($xp): string { $n=$xp->query($q)->item(0); return $n?trim((string)$n->textContent):''; };
    $infEvt = $xp->query('//*[local-name()="infEvento"]')->item(0);
    if ($infEvt instanceof DOMElement) $out['event_id'] = $infEvt->getAttribute('Id');
    $out['key'] = nfse_emitter_digits($read('//*[local-name()="pedRegEvento"]/*[local-name()="infPedReg"]/*[local-name()="chNFSe"]'));
    if ($xp->query('//*[local-name()="pedRegEvento"]/*[local-name()="infPedReg"]/*[local-name()="e101101"]')->length > 0) $out['event_type'] = '101101';
    $out['sequence'] = nfse_emitter_digits($read('//*[local-name()="infEvento"]/*[local-name()="nSeqEvento"]'));
    $out['processed_at'] = $read('//*[local-name()="infEvento"]/*[local-name()="dhProc"]');
    $out['valid'] = $out['key'] !== '' && $out['event_type'] === '101101' && $infEvt instanceof DOMElement;
    return $out;
  }
}

if (!function_exists('nfse_pronim_append_event')) {
  function nfse_pronim_append_event(PDO $pdo, int $companyId, int $docId, string $statusCode, string $statusMessage, string $requestXml, string $responseXml, string $notes=''): void {
    if (!fiscal_table_exists($pdo, 'fiscal_document_events')) return;
    $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id,company_id,event_type,event_sequence,event_datetime,protocol_number,status_code,status_message,request_xml,response_xml,notes,created_at) VALUES (?,?,?,?,NOW(),NULL,?,?,?,?,?,NOW())')
      ->execute([$docId,$companyId,'CANCELAMENTO_NFSE',1,substr($statusCode,0,10),nfse_emitter_text($statusMessage,255),$requestXml,$responseXml,$notes]);
  }
}

if (!function_exists('nfse_pronim_cancel_document')) {
  function nfse_pronim_cancel_document(PDO $pdo, int $companyId, int $docId, string $reasonCode, string $reason): array {
    nfse_pronim_require_schema($pdo);
    nfse_emitter_require_runtime(true);
    $lock = 'nexa_nfse_pronim_cancel_' . $companyId . '_' . $docId;
    $st = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $st->execute([$lock]);
    $lockAcquired = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($lockAcquired !== 1) throw new RuntimeException('Este documento já está sendo processado. Aguarde e atualize a tela.');
    try {
      $doc = nfse_emitter_document_by_id($pdo, $companyId, $docId);
      if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
      $status = strtoupper(trim((string)($doc['status'] ?? '')));
      if ($status === 'CANCELADA') return ['ok'=>true,'already_cancelled'=>true,'document'=>$doc,'message'=>'A NFS-e já está cancelada no Nexa.'];
      if ($status !== 'AUTORIZADA') throw new RuntimeException('Somente NFS-e autorizada pode ser cancelada.');
      $key = nfse_emitter_digits($doc['access_key'] ?? '');
      if (strlen($key) !== 50) throw new RuntimeException('A NFS-e não possui chave de acesso válida para cancelamento.');
      $environment = strtoupper(trim((string)($doc['environment'] ?? 'HOMOLOGACAO')));
      if ($environment === 'PRODUCAO' && !nfse_pronim_production_enabled()) throw new RuntimeException('Cancelamento em PRODUÇÃO não confirmado nesta requisição.');
      $cfg = fiscal_company_config($pdo, $companyId);
      $xml = nfse_pronim_build_cancel_event_xml($cfg, $doc, $reasonCode, $reason);
      $signed = nfse_pronim_sign_event_xml($pdo, $companyId, $xml);
      $validation = nfse_pronim_validate_cancel_event_xml($signed);
      if ($validation) throw new RuntimeException('Evento de cancelamento inválido: ' . implode(' | ', $validation));
      $gzip = gzencode($signed, 9, FORCE_GZIP);
      if ($gzip === false) throw new RuntimeException('Não foi possível compactar o evento de cancelamento.');
      // A API Pronim/Cidade360 usa o mesmo endpoint síncrono para DPS e eventos:
      // cada XML assinado é compactado em GZip/Base64 e enviado no loteXmlGZipB64.
      $payload = ['loteXmlGZipB64'=>[base64_encode($gzip)]];
      $http = nfse_pronim_http($pdo,$companyId,$cfg,$environment,'POST','EnviarSincrono',$payload);
      if ((int)$http['curl_errno'] !== 0 || (int)$http['status'] === 0) {
        $message = 'Falha de comunicação durante o cancelamento: ' . ((string)$http['curl_error'] ?: 'sem resposta HTTP') . '. Consulte a NFS-e/eventos antes de repetir.';
        nfse_pronim_append_event($pdo,$companyId,$docId,'CURL',$message,$signed,'','Resultado incerto; NFS-e mantida como AUTORIZADA no Nexa.');
        return ['ok'=>false,'ambiguous'=>true,'message'=>$message,'http'=>$http];
      }
      $parsed = nfse_emitter_response_data((string)$http['body']);
      if ((int)$http['status'] >= 200 && (int)$http['status'] < 300 && $parsed['xml'] !== '') {
        $info = nfse_pronim_event_info($parsed['xml']);
        if ($info['valid'] && hash_equals($key, $info['key'])) {
          $eventPath = nfse_emitter_store_xml_file($companyId,$environment,$docId,'nfse-pronim-cancelamento',$parsed['xml']);
          $pdo->prepare("UPDATE fiscal_documents SET status='CANCELADA',sefaz_status_code='101101',sefaz_status_message='NFS-e cancelada pelo ISS Digital',updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$companyId,$docId]);
          $pdo->prepare("UPDATE fiscal_nfse_details SET status='CANCELADA',api_http_status=?,api_error_code=NULL,api_error_message=NULL,last_attempt_at=NOW() WHERE company_id=? AND fiscal_document_id=?")
            ->execute([(int)$http['status'],$companyId,$docId]);
          nfse_pronim_append_event($pdo,$companyId,$docId,'101101','Cancelamento de NFS-e registrado pelo ISS Digital',$signed,$parsed['xml'],$eventPath!==''?('XML do evento: '.$eventPath):'Evento de cancelamento autorizado.');
          return ['ok'=>true,'document'=>nfse_emitter_document_by_id($pdo,$companyId,$docId),'event_xml'=>$parsed['xml'],'http'=>$http,'message'=>'NFS-e cancelada com sucesso no Pronim/GOVBR.'];
        }
        $message = 'O Pronim retornou XML no cancelamento, mas o evento e101101/chave não pôde ser confirmado. Não repita o cancelamento sem consulta.';
        nfse_pronim_append_event($pdo,$companyId,$docId,'RETORNO',$message,$signed,$parsed['xml'],'Resultado ambíguo; NFS-e mantida como AUTORIZADA no Nexa.');
        return ['ok'=>false,'ambiguous'=>true,'message'=>$message,'http'=>$http];
      }
      $code = $parsed['code'] !== '' ? $parsed['code'] : (string)$http['status'];
      $message = $parsed['message'] !== '' ? $parsed['message'] : ('ISS Digital/Pronim não confirmou o cancelamento. HTTP ' . $http['status'] . '.');
      nfse_pronim_append_event($pdo,$companyId,$docId,$code,$message,$signed,$parsed['xml'] ?: (string)$http['body'],'NFS-e permaneceu AUTORIZADA no Nexa.');
      return ['ok'=>false,'ambiguous'=>(int)$http['status']>=500,'code'=>$code,'message'=>$message,'http'=>$http,'response'=>$parsed];
    } finally {
      try {
        $r = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $r->execute([$lock]);
        $r->fetchColumn();
        $r->closeCursor();
      } catch (Throwable $e) {}
    }
  }
}
