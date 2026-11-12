<?php
declare(strict_types=1);

/**
 * DANFSe v2.0 (layout do Documento Auxiliar da NFS-e do Ambiente Nacional).
 * Um só "molde" (nfse_danfse_layout) alimenta a impressão em HTML
 * (admin/fiscal_nfse_imprimir.php) e o PDF enviado por WhatsApp/e-mail
 * (nfse_danfse_pdf_build, usado por nfse_document_pdf_build).
 */

require_once __DIR__ . '/nfse_document_pdf.php';
require_once __DIR__ . '/print_codes.php';

if (!function_exists('nfse_danfse_uf_by_ibge')) {
  /** Sigla da UF pelos 2 primeiros dígitos do código IBGE do município. */
  function nfse_danfse_uf_by_ibge(string $code): string {
    $map = ['11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO', '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF'];
    $d = preg_replace('/\D+/', '', $code) ?? '';
    return strlen($d) >= 2 ? ($map[substr($d, 0, 2)] ?? '') : '';
  }
}

if (!function_exists('nfse_danfse_layout')) {
  /**
   * Monta os blocos do DANFSe a partir do XML autorizado.
   * Retorno: header, top (chave + 3 linhas de 3 campos), sections (título + linhas de campos), info, bottom.
   * Cada campo: ['l' => rótulo, 'v' => valor, 's' => colunas (1-4), 'b' => destaque].
   */
  function nfse_danfse_layout(array $document): array {
    $d = nfse_document_view_data($document);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML((string)$d['xml']);
    $xp = new DOMXPath($dom);
    $inf = $xp->query('//*[local-name()="infNFSe"]')->item(0);
    $dps = $inf ? $xp->query('.//*[local-name()="DPS"]/*[local-name()="infDPS"]', $inf)->item(0) : null;
    $t = static function (string $q, ?DOMNode $ctx) use ($xp): string { return $ctx ? nfse_document_xpath_text($xp, $q, $ctx) : ''; };
    $n = static function (string $local) { return '*[local-name()="' . $local . '"]'; };

    $dash = '-';
    $val = static fn($v): string => trim((string)$v) !== '' ? trim((string)$v) : '-';
    $money = static fn($v): string => trim((string)$v) === '' ? '-' : 'R$ ' . number_format((float)$v, 2, ',', '.');
    $moneyNz = static fn($v): string => (trim((string)$v) === '' || abs((float)$v) < 0.005) ? '-' : 'R$ ' . number_format((float)$v, 2, ',', '.');
    $pct = static fn($v): string => trim((string)$v) === '' ? '-' : number_format((float)$v, 2, ',', '.') . ' %';
    $cep = static function ($v): string { $x = preg_replace('/\D+/', '', (string)$v) ?? ''; return strlen($x) === 8 ? substr($x, 0, 2) . '.' . substr($x, 2, 3) . '-' . substr($x, 5) : trim((string)$v); };
    // Datas do XML (ex.: 2026-10-22T09:18:38-03:00) mostradas no horário que veio na nota, sem converter fuso.
    $dt = static function ($v, bool $time = true): string {
      $v = trim((string)$v);
      if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/', $v, $m)) {
        $out = $m[3] . '/' . $m[2] . '/' . $m[1];
        if ($time && isset($m[4]) && $m[4] !== '') $out .= ' ' . $m[4] . ':' . $m[5] . ':' . (($m[6] ?? '') !== '' ? $m[6] : '00');
        return $out;
      }
      return nfse_document_human_date($v, $time) ?: '-';
    };

    $emit = $inf ? $xp->query('./' . $n('emit'), $inf)->item(0) : null;
    $prest = $dps ? $xp->query('./' . $n('prest'), $dps)->item(0) : null;
    $toma = $dps ? $xp->query('./' . $n('toma'), $dps)->item(0) : null;
    $interm = $dps ? $xp->query('./' . $n('interm'), $dps)->item(0) : null;
    $serv = $dps ? $xp->query('./' . $n('serv'), $dps)->item(0) : null;
    $valDps = $dps ? $xp->query('./' . $n('valores'), $dps)->item(0) : null;
    $valNfse = $inf ? $xp->query('./' . $n('valores'), $inf)->item(0) : null;
    $ibsNfse = $inf ? $xp->query('./' . $n('IBSCBS'), $inf)->item(0) : null;
    $ibsDps = $dps ? $xp->query('./' . $n('IBSCBS'), $dps)->item(0) : null;
    $dest = $ibsDps ? $xp->query('./' . $n('dest'), $ibsDps)->item(0) : null;

    // ---------- Partes (prestador / tomador / intermediário / destinatário) ----------
    $party = function (array $base, ?DOMNode $node, string $role) use ($t, $val, $cep): array {
      $a = (array)($base['address'] ?? []);
      $cityCode = trim((string)($base['city_code'] ?? '')) ?: $t('.//*[local-name()="cMun"]', $node);
      $uf = trim((string)($a['uf'] ?? '')) ?: nfse_danfse_uf_by_ibge($cityCode);
      $city = trim((string)($a['city'] ?? ''));
      $street = trim((string)($a['address'] ?? ''));
      $addr = implode(', ', array_filter([$street, trim((string)($a['number'] ?? '')), trim((string)($a['complement'] ?? '')), trim((string)($a['district'] ?? ''))], static fn($x) => $x !== ''));
      $doc = trim((string)($base['document'] ?? ''));
      if ($doc === '' && $node) $doc = $t('./*[local-name()="CNPJ"]', $node) ?: ($t('./*[local-name()="CPF"]', $node) ?: $t('./*[local-name()="NIF"]', $node));
      $fone = trim((string)($base['phone'] ?? '')) ?: $t('./*[local-name()="fone"]', $node);
      $mail = trim((string)($base['email'] ?? '')) ?: $t('./*[local-name()="email"]', $node);
      return [
        [$role, '', 1, true, 'title'],
        ['CNPJ / CPF / NIF', $val(nfse_document_format_document($doc)), 1],
        ['Indicador Municipal (Inscrição)', $val($base['municipal_registration'] ?? $t('./*[local-name()="IM"]', $node)), 1],
        ['Telefone', $val($fone), 1],
        '|',
        ['Nome / Nome Empresarial', $val($base['name'] ?? $t('./*[local-name()="xNome"]', $node)), 2],
        ['Município / Sigla UF', $val(trim($city . ($uf !== '' ? ' / ' . $uf : ''), ' /')), 1],
        ['Código IBGE / CEP', $val(trim($cityCode . (($z = $cep($a['zip'] ?? '')) !== '' ? ' / ' . $z : ''), ' /')), 1],
        '|',
        ['Endereço', $val($addr), 2],
        ['E-mail', $val($mail), 2],
      ];
    };

    // ---------- Rótulos dos códigos ----------
    $meta = (array)($d['meta'] ?? []);
    $opSimp = (string)($meta['simple_national_code'] ?? '');
    $simpleLabel = ['1' => 'Não Optante', '2' => 'Optante - Microempreendedor Individual (MEI)', '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)'][$opSimp] ?? $val($opSimp);
    $regAp = (string)($meta['simple_tax_regime'] ?? '');
    $regApLabel = ['1' => 'Regime de apuração dos tributos federais e municipal pelo SN', '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN por fora do SN conforme respectiva legislação municipal do tributo', '3' => 'Regime de apuração dos tributos federais e municipal por fora do SN conforme respectivas legislações federal e municipal de cada tributo'][$regAp] ?? $dash;
    $regEsp = (string)($meta['special_tax_regime'] ?? '');
    $regEspLabel = ['0' => 'Nenhum', '1' => 'Ato Cooperado (Cooperativa)', '2' => 'Estimativa', '3' => 'Microempresa Municipal', '4' => 'Notário ou Registrador', '5' => 'Profissional Autônomo', '6' => 'Sociedade de Profissionais', '9' => 'Outros'][$regEsp] ?? $val($regEsp);
    $tribIss = (string)($d['tax']['iss']['type'] ?? '');
    $tribIssLabel = ['1' => 'Operação Tributável', '2' => 'Imunidade', '3' => 'Exportação de Serviço', '4' => 'Não Incidência'][$tribIss] ?? $val($tribIss);
    $retIss = (string)($d['tax']['iss']['retention'] ?? '');
    $retIssLabel = ['1' => 'Não Retido', '2' => 'Retido pelo Tomador', '3' => 'Retido pelo Intermediário'][$retIss] ?? $val($retIss);
    $imun = $t('.//*[local-name()="tribMun"]/*[local-name()="tpImunidade"]', $valDps);
    $imunLabel = $imun === '' ? $dash : (['0' => 'Imunidade (tipo não informado)', '1' => 'Patrimônio, renda ou serviços, uns dos outros', '2' => 'Templos de qualquer culto', '3' => 'Partidos, sindicatos, educação e assistência social sem fins lucrativos', '4' => 'Livros, jornais, periódicos e papel para impressão', '5' => 'Fonogramas e videofonogramas musicais'][$imun] ?? 'Código ' . $imun);
    $susp = $t('.//*[local-name()="exigSusp"]/*[local-name()="tpSusp"]', $valDps);
    $suspLabel = $susp === '' ? $dash : (['1' => 'Suspensa por Decisão Judicial', '2' => 'Suspensa por Processo Administrativo'][$susp] ?? 'Código ' . $susp);
    $suspProc = $t('.//*[local-name()="exigSusp"]/*[local-name()="nProcesso"]', $valDps);
    $tpEmit = $t('./*[local-name()="tpEmit"]', $dps);
    $emitLabel = ['1' => 'Prestador', '2' => 'Tomador', '3' => 'Intermediário'][$tpEmit] ?? 'Prestador';
    $cStat = $t('./*[local-name()="cStat"]', $inf);
    $statusLabel = strtoupper((string)$d['status']) === 'CANCELADA' ? 'NFS-e Cancelada'
      : (['100' => 'NFS-e Gerada', '101' => 'NFS-e de Substituição Gerada', '102' => 'NFS-e de Decisão Judicial', '103' => 'NFS-e Avulsa', '107' => 'NFS-e MEI'][$cStat] ?? 'NFS-e Gerada');
    $tpAmb = $t('./*[local-name()="tpAmb"]', $dps);
    $ambLabel = $tpAmb === '1' ? 'Produção' : ($tpAmb === '2' ? 'Homologação' : (string)($meta['environment_type'] ?? '-'));
    $ambGer = $t('./*[local-name()="ambGer"]', $inf);
    $ambGerLabel = ['1' => 'Prefeitura', '2' => 'Sistema Nacional da NFS-e'][$ambGer] ?? $val($meta['generator'] ?? '');
    $cLocEmi = $t('./*[local-name()="cLocEmi"]', $dps);
    $munEmi = trim((string)$d['city_issue']);
    $ufEmi = nfse_danfse_uf_by_ibge($cLocEmi) ?: (string)($d['issuer']['address']['uf'] ?? '');

    // ---------- Serviço ----------
    $cTribNac = preg_replace('/\D+/', '', (string)$d['service']['national_code']) ?? '';
    $tribNacFmt = strlen($cTribNac) === 6 ? substr($cTribNac, 0, 2) . '.' . substr($cTribNac, 2, 2) . '.' . substr($cTribNac, 4, 2) : (string)$d['service']['national_code'];
    $cTribMun = trim((string)$d['service']['municipal_code']);
    $nbs = preg_replace('/\D+/', '', (string)$d['service']['nbs']) ?? '';
    $nbsFmt = strlen($nbs) === 9 ? substr($nbs, 0, 1) . '.' . substr($nbs, 1, 4) . '.' . substr($nbs, 5, 2) . '.' . substr($nbs, 7, 2) : (string)$d['service']['nbs'];
    $cLocPrest = $t('.//*[local-name()="locPrest"]/*[local-name()="cLocPrestacao"]', $serv);
    $locPrest = trim((string)$d['city_service']);
    $locPrestUf = nfse_danfse_uf_by_ibge($cLocPrest);
    $locPrestLabel = $locPrest !== '' ? $locPrest . ($locPrestUf !== '' && !str_contains($locPrest, '/') ? ' / ' . $locPrestUf : '') : $dash;
    $xLocIncid = $t('./*[local-name()="xLocIncid"]', $inf);

    // ---------- Federais ----------
    $tribFed = $valDps ? $xp->query('.//*[local-name()="tribFed"]', $valDps)->item(0) : null;
    $vIRRF = $t('./*[local-name()="vRetIRRF"]', $tribFed);
    $vCP = $t('./*[local-name()="vRetCP"]', $tribFed);
    $vCSLL = $t('./*[local-name()="vRetCSLL"]', $tribFed);
    $vPis = $t('./*[local-name()="piscofins"]/*[local-name()="vPis"]', $tribFed);
    $vCofins = $t('./*[local-name()="piscofins"]/*[local-name()="vCofins"]', $tribFed);
    $tpRetPC = $t('./*[local-name()="piscofins"]/*[local-name()="tpRetPisCofins"]', $tribFed);
    $tpRetPCLabel = $tpRetPC === '' ? $dash : (['0' => 'PIS/COFINS/CSLL Não Retidos', '1' => 'PIS/COFINS Retidos', '2' => 'PIS/COFINS Não Retidos', '3' => 'PIS/COFINS/CSLL Retidos', '4' => 'PIS/COFINS Retidos, CSLL Não Retido', '5' => 'PIS Retido, COFINS/CSLL Não Retido', '6' => 'COFINS Retido, PIS/CSLL Não Retido', '7' => 'PIS Não Retido, COFINS/CSLL Retidos', '8' => 'PIS/COFINS Não Retidos, CSLL Retido', '9' => 'COFINS Não Retido, PIS/CSLL Retidos'][$tpRetPC] ?? 'Código ' . $tpRetPC);

    // ---------- IBS / CBS ----------
    $ibs = (array)($d['tax']['ibscbs'] ?? []);
    $g = static fn(string $q) => $ibsNfse ? $t($q, $ibsNfse) : '';
    $ibsBase = $g('./*[local-name()="valores"]/*[local-name()="vBC"]');
    $redUF = $g('.//*[local-name()="uf"]/*[local-name()="pRedAliqUF"]');
    $redMun = $g('.//*[local-name()="mun"]/*[local-name()="pRedAliqMun"]');
    $redCBS = $g('.//*[local-name()="fed"]/*[local-name()="pRedAliqCBS"]');
    $pUF = $g('.//*[local-name()="uf"]/*[local-name()="pIBSUF"]');
    $pMun = $g('.//*[local-name()="mun"]/*[local-name()="pIBSMun"]');
    $pCBS = $g('.//*[local-name()="fed"]/*[local-name()="pCBS"]');
    $efUF = $g('.//*[local-name()="uf"]/*[local-name()="pAliqEfetUF"]');
    $efMun = $g('.//*[local-name()="mun"]/*[local-name()="pAliqEfetMun"]');
    $efCBS = $g('.//*[local-name()="fed"]/*[local-name()="pAliqEfetCBS"]');
    $vIBSUF = $g('.//*[local-name()="gIBSUFTot"]/*[local-name()="vIBSUF"]');
    $vIBSMun = $g('.//*[local-name()="gIBSMunTot"]/*[local-name()="vIBSMun"]');
    $vIBSTot = $g('.//*[local-name()="gIBS"]/*[local-name()="vIBSTot"]');
    $vCBS = $g('.//*[local-name()="gCBS"]/*[local-name()="vCBS"]');
    $vTotNF = $g('.//*[local-name()="totCIBS"]/*[local-name()="vTotNF"]');
    $serviceValue = (float)($d['values']['service'] ?? 0);
    $exclusions = $ibsBase !== '' ? max(0, $serviceValue - (float)$ibsBase) : null;
    $cst = trim((string)($ibs['cst'] ?? ''));
    $cls = trim((string)($ibs['class'] ?? ''));
    $incid = implode(' / ', array_filter([(string)($ibs['operation_indicator'] ?? ''), (string)($ibs['incidence_code'] ?? ''), (string)($ibs['incidence_name'] ?? '')], static fn($x) => trim($x) !== ''));
    $hasIbs = $ibsNfse !== null || $cst !== '';

    // ---------- Totais ----------
    $vTotalRet = $t('./*[local-name()="vTotalRet"]', $valNfse);
    $liquid = (float)($d['values']['liquid'] ?? 0);
    $ibsCbsTotal = ($vIBSTot !== '' ? (float)$vIBSTot : (float)$d['values']['ibs']) + ($vCBS !== '' ? (float)$vCBS : (float)$d['values']['cbs']);
    $liquidPlus = $vTotNF !== '' ? (float)$vTotNF : $liquid;

    // ---------- Informações complementares ----------
    $info = [];
    $totTrib = $valDps ? $xp->query('.//*[local-name()="totTrib"]', $valDps)->item(0) : null;
    if ($totTrib) {
      $fed = $t('.//*[local-name()="vTotTribFed"]', $totTrib);
      if ($fed !== '') {
        $info[] = 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: ' . $money($fed) . ' ; Estaduais: ' . $money($t('.//*[local-name()="vTotTribEst"]', $totTrib) ?: '0') . ' ; Municipais: ' . $money($t('.//*[local-name()="vTotTribMun"]', $totTrib) ?: '0');
      } elseif (($pf = $t('.//*[local-name()="pTotTribFed"]', $totTrib)) !== '') {
        $info[] = 'Percentuais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: ' . $pct($pf) . ' ; Estaduais: ' . $pct($t('.//*[local-name()="pTotTribEst"]', $totTrib) ?: '0') . ' ; Municipais: ' . $pct($t('.//*[local-name()="pTotTribMun"]', $totTrib) ?: '0');
      } elseif (($psn = $t('./*[local-name()="pTotTribSN"]', $totTrib)) !== '') {
        $info[] = 'Percentual Aproximado dos Tributos (Simples Nacional) cfe. Lei nº 12.741/2012: ' . $pct($psn);
      }
    }
    foreach ([(string)($d['additional_info'] ?? ''), $t('./*[local-name()="xOutInf"]', $inf)] as $extra) {
      $extra = trim($extra);
      if ($extra !== '' && !in_array($extra, $info, true)) $info[] = $extra;
    }

    // ---------- Notas de destinatário / intermediário ----------
    $notes = [];
    $extraParties = [];
    if ($dest) $extraParties[] = $party(['name' => $t('./*[local-name()="xNome"]', $dest)], $dest, 'DESTINATÁRIO DA OPERAÇÃO');
    else $notes[] = 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO';
    if ($interm) $extraParties[] = $party(['name' => $t('./*[local-name()="xNome"]', $interm)], $interm, 'INTERMEDIÁRIO DA OPERAÇÃO');
    else $notes[] = 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e';

    $rows = static function (array $flat): array {
      $out = [[]];
      foreach ($flat as $c) { if ($c === '|') { $out[] = []; continue; } $out[count($out) - 1][] = $c; }
      return array_values(array_filter($out));
    };

    $sections = [];
    $sections[] = ['rows' => $rows(array_merge($party($d['issuer'], $emit ?: $prest, 'PRESTADOR / FORNECEDOR'), ['|',
      ['Simples Nacional na Data de Competência', $simpleLabel, 2],
      ['Regime de Apuração Tributária pelo SN', $opSimp === '3' ? $regApLabel : $dash, 2],
    ]))];
    $sections[] = ['rows' => $rows($party($d['customer'], $toma, 'TOMADOR / ADQUIRENTE')), 'notes' => $notes];
    foreach ($extraParties as $p) $sections[] = ['rows' => $rows($p)];
    $servDesc = trim((string)$d['service']['description']);
    $sections[] = ['rows' => $rows([
      ['SERVIÇO PRESTADO', '', 1, true, 'title'],
      ['Código de Tributação Nacional / Municipal', $val(trim($tribNacFmt . ($cTribMun !== '' ? ' / ' . $cTribMun : ''), ' /')), 1],
      ['Código da NBS', $val($nbsFmt), 1],
      ['Local da Prestação / Sigla UF / País', $locPrestLabel, 1],
      '|',
      ['', trim((string)$d['national_service_label']), 4, false, 'plain'],
      '|',
      ['Descrição do Serviço', $val($servDesc), 4, false, 'wide'],
    ])];
    $sections[] = ['rows' => $rows([
      ['TRIBUTAÇÃO MUNICIPAL (ISSQN)', '', 1, true, 'title'],
      ['Tipo de Tributação do ISSQN', $tribIssLabel, 1],
      ['Município / Sigla UF / País de Incidência do ISSQN', $val($xLocIncid ?: $locPrest), 2],
      '|',
      ['Regime Especial de Tributação do ISSQN', $regEspLabel, 1],
      ['Tipo de Imunidade do ISSQN', $imunLabel, 1],
      ['Suspensão da Exigibilidade do ISSQN', $suspLabel, 1],
      ['Número Processo Suspensão', $val($suspProc), 1],
      '|',
      ['BC ISSQN', $money($d['values']['base']), 1],
      ['Alíquota Aplicada', $pct((string)($d['values']['iss_rate'] ?: ($d['tax']['iss']['rate'] ?? ''))), 1],
      ['Retenção do ISSQN', $retIssLabel, 1],
      ['ISSQN Apurado', $money($d['values']['iss']), 1],
    ])];
    $sections[] = ['rows' => $rows([
      ['TRIBUTAÇÃO FEDERAL (EXCETO CBS)', '', 1, true, 'title'],
      ['IRRF', $moneyNz($vIRRF), 1],
      ['Contribuição Previdenciária - Retida', $moneyNz($vCP), 1],
      ['Contribuições Sociais - Retidas', $moneyNz($vCSLL), 1],
      '|',
      ['PIS - Débito Apuração Própria', $moneyNz($vPis), 1],
      ['COFINS - Débito Apuração Própria', $moneyNz($vCofins), 1],
      ['Descrição Contrib. Sociais - Retidas', $tpRetPCLabel, 2],
    ])];
    if ($hasIbs) {
      $sections[] = ['rows' => $rows([
        ['TRIBUTAÇÃO IBS / CBS', '', 1, true, 'title'],
        ['CST / cClassTrib', $val(trim($cst . ($cls !== '' ? ' / ' . $cls : ''), ' /')), 1],
        ['Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $val($incid), 2],
        '|',
        ['Exclusões e Reduções da Base de Cálculo', $exclusions === null ? $dash : $money($exclusions), 1],
        ['Base de Cálculo Após Exclusões e Reduções', $money($ibsBase !== '' ? $ibsBase : ($ibs['base'] ?? '')), 1],
        ['Red. Alíquota IBS / Red. Alíquota CBS', ($redUF !== '' ? $pct($redUF) : '-') . ' / ' . ($redMun !== '' ? $pct($redMun) : '-') . ' / ' . ($redCBS !== '' ? $pct($redCBS) : '-'), 1],
        ['Alíquota - IBS UF / IBS Mun', $pct($pUF !== '' ? $pUF : ($ibs['ibs_uf_rate'] ?? '')) . ' / ' . $pct($pMun !== '' ? $pMun : ($ibs['ibs_mun_rate'] ?? '')), 1],
        '|',
        ['Alíq. Efetiva Municipal - IBS', $pct($efMun !== '' ? $efMun : $pMun), 1],
        ['Valor Apurado Municipal - IBS', $money($vIBSMun !== '' ? $vIBSMun : ($ibs['ibs_mun'] ?? '')), 1],
        ['Alíq. Efetiva Estadual - IBS', $pct($efUF !== '' ? $efUF : $pUF), 1],
        ['Valor Apurado Estadual - IBS', $money($vIBSUF !== '' ? $vIBSUF : ($ibs['ibs_uf'] ?? '')), 1],
        '|',
        ['Valor Total Apurado - IBS', $money($vIBSTot !== '' ? $vIBSTot : $d['values']['ibs']), 1],
        ['Alíquota - CBS', $pct($pCBS !== '' ? $pCBS : ($ibs['cbs_rate'] ?? '')), 1],
        ['Alíquota Efetiva - CBS', $pct($efCBS !== '' ? $efCBS : $pCBS), 1],
        ['Valor Total Apurado - CBS', $money($vCBS !== '' ? $vCBS : $d['values']['cbs']), 1],
      ])];
    }
    $sections[] = ['rows' => $rows([
      ['VALOR TOTAL DA NFS-E', '', 1, true, 'title'],
      ['VALOR DA OPERAÇÃO / SERVIÇO', $money($serviceValue), 1, true, 'big'],
      ['Desconto Incondicionado', $moneyNz($d['discounts']['unconditional'] ?? ''), 1],
      ['Desconto Condicionado', $moneyNz($d['discounts']['conditional'] ?? ''), 1],
      '|',
      ['Total das Retenções (ISSQN / Federais)', $moneyNz($vTotalRet), 1],
      ['VALOR LÍQUIDO DA NFS-e', $money($liquid), 1, true, 'big'],
      ['Total do IBS/CBS', $hasIbs ? $money($ibsCbsTotal) : '-', 1],
      ['VALOR LÍQUIDO DA NFS-e + IBS/CBS', $money($liquidPlus), 1, true, 'big-shade'],
    ])];

    $key = (string)$d['access_key'];
    // QR do DANFSe: consulta pública da NFS-e no portal nacional pela chave de acesso.
    $qrUrl = $key !== '' ? 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $key : '';

    return [
      'title' => 'DANFSe v2.0',
      'subtitle' => 'Documento Auxiliar da NFS-e',
      'header_right' => [
        ['Município: ' . ($munEmi !== '' ? $munEmi . ($ufEmi !== '' && !str_contains($munEmi, '/') ? ' / ' . $ufEmi : '') : '-'), true],
        ['Ambiente Gerador: ' . $ambGerLabel, false],
        ['Tipo de Ambiente: ' . $ambLabel, false],
      ],
      'homologation' => $tpAmb === '2' || ($tpAmb === '' && strtoupper((string)$d['environment']) !== 'PRODUCAO' && ($meta['environment_type'] ?? '') !== 'Produção'),
      'cancelled' => strtoupper((string)$d['status']) === 'CANCELADA',
      'access_key' => $key,
      'qr_text' => $qrUrl,
      'qr_note' => 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e',
      'top' => [
        [['Número da NFS-e', $val($d['nfse_number'])], ['Competência da NFS-e', $dt($d['competence'], false)], ['Data e Hora da Emissão da NFS-e', $dt($d['issue_datetime'])]],
        [['Número da DPS', $val($d['dps_number'])], ['Série da DPS', $val($d['dps_series'])], ['Data e Hora da Emissão da DPS', $dt($meta['dps_issue_datetime'] ?? '')]],
        [['Emitente da NFS-e', $emitLabel], ['Situação da NFS-e', $statusLabel], ['Finalidade', $val($meta['finality'] ?? 'NFS-e regular')]],
      ],
      'sections' => $sections,
      'info' => $info,
      'bottom' => [['Data Cientificação:', '', 1], ['Identificação e Assinatura', '', 1], ['Nº NFS-e / Chave NFS-e', $val($d['nfse_number']) . ' / ' . ($key !== '' ? $key : '-'), 2]],
      'nfse_number' => (string)$d['nfse_number'],
      'filename_id' => $key !== '' ? $key : ((string)$d['nfse_number'] !== '' ? (string)$d['nfse_number'] : (string)($document['id'] ?? date('YmdHis'))),
    ];
  }
}

/* ------------------------------------------------------------------------- */
/* HTML (impressão pelo navegador)                                            */
/* ------------------------------------------------------------------------- */

if (!function_exists('nfse_danfse_css')) {
  function nfse_danfse_css(): string {
    return <<<'CSS'
.dfs{width:210mm;min-height:297mm;margin:0 auto 18px;background:#fff;padding:5mm;box-sizing:border-box;box-shadow:0 10px 30px rgba(15,23,42,.10);font-family:Arial,Helvetica,sans-serif;color:#000;display:flex;flex-direction:column}
.dfs *{box-sizing:border-box}
.dfs-doc{border:.25mm solid #000;flex:1;display:flex;flex-direction:column}
.dfs-head{display:grid;grid-template-columns:1fr 1fr 1fr;align-items:start;padding:1.2mm 1.4mm 1mm;border-bottom:.25mm solid #000}
.dfs-mark{display:flex;align-items:center;gap:1.5mm}.dfs-mark b{font-size:21pt;font-weight:900;color:#2b8a3e;letter-spacing:-.6mm;line-height:1}.dfs-mark b i{font-style:normal;color:#1d4f91}.dfs-mark span{font-size:6.6pt;color:#555;line-height:1.15}
.dfs-title{text-align:center;font-weight:700;font-size:9.2pt;line-height:1.25}
.dfs-right{font-size:6.4pt;line-height:1.35}.dfs-right .m{font-size:8pt}
.dfs-g{display:grid;grid-template-columns:repeat(4,1fr)}
.dfs-c{padding:.6mm 1.4mm .8mm;min-width:0}
.dfs-c .l{font-size:6pt;font-weight:700;line-height:1.2}
.dfs-c .v{font-size:7pt;line-height:1.2;overflow-wrap:anywhere;white-space:pre-wrap}
.dfs-c.title .l{font-size:6.9pt;font-weight:900;padding-top:.6mm;white-space:nowrap}
.dfs-c.big .l{font-size:7pt;font-weight:900}.dfs-c.big .v{font-size:7.2pt}
.dfs-c.big-shade{background:#e6e6e6;-webkit-print-color-adjust:exact;print-color-adjust:exact}.dfs-c.big-shade .l{font-size:7pt;font-weight:900;white-space:nowrap}
.dfs-c.plain .v{padding-top:.4mm}
.dfs-s1{grid-column:span 1}.dfs-s2{grid-column:span 2}.dfs-s3{grid-column:span 3}.dfs-s4{grid-column:1/-1}
.dfs-sec{border-top:.25mm solid #000}
.dfs-note{border-top:.2mm solid #000;text-align:center;font-size:6.6pt;padding:.3mm 0}
.dfs-top{display:grid;grid-template-columns:3fr 1fr}
.dfs-qr{display:flex;flex-direction:column;align-items:center;padding:1mm 1.4mm;gap:1mm}.dfs-qr img{width:22mm;height:22mm}.dfs-qr div{font-size:6pt;line-height:1.25;text-align:left}
.dfs-info{border-top:.25mm solid #000;flex:1;display:flex;flex-direction:column}.dfs-info .v{font-size:7pt;padding:.6mm 1.4mm;white-space:pre-wrap}
.dfs-bottom{display:grid;grid-template-columns:repeat(4,1fr);border-top:.25mm solid #000}.dfs-bottom .dfs-c{border-right:.25mm solid #000;min-height:7mm}.dfs-bottom .dfs-c:last-child{border-right:0}
.dfs-band{border:.4mm solid #000;text-align:center;font-weight:900;font-size:8.5pt;padding:1mm;margin-bottom:1.2mm;background:#fff7ed}.dfs-band.cancel{border-color:#991b1b;color:#991b1b;background:#fff}
@media print{@page{size:A4 portrait;margin:5mm}.dfs{width:auto;min-height:calc(297mm - 10mm);margin:0;padding:0;box-shadow:none}}
CSS;
  }
}

if (!function_exists('nfse_danfse_html')) {
  /** Corpo do DANFSe (sem <html>), pronto para a página de impressão. */
  function nfse_danfse_html(array $L): string {
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $cell = static function (array $c) use ($h): string {
      $span = max(1, min(4, (int)($c[2] ?? 1)));
      $cls = 'dfs-c dfs-s' . $span . (!empty($c[4]) ? ' ' . $c[4] : '');
      $label = (string)($c[0] ?? '');
      return '<div class="' . $cls . '">' . ($label !== '' ? '<div class="l">' . $h($label) . '</div>' : '') . ((string)($c[1] ?? '') !== '' ? '<div class="v">' . $h($c[1]) . '</div>' : '') . '</div>';
    };
    $o = '<main class="dfs">';
    if (!empty($L['homologation'])) $o .= '<div class="dfs-band">AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL</div>';
    if (!empty($L['cancelled'])) $o .= '<div class="dfs-band cancel">NFS-e CANCELADA</div>';
    $o .= '<div class="dfs-doc">';
    $o .= '<header class="dfs-head"><div class="dfs-mark"><b>NFS<i>e</i></b><span>Nota Fiscal de<br>Serviço Eletrônica</span></div>';
    $o .= '<div class="dfs-title">' . $h($L['title']) . '<br>' . $h($L['subtitle']) . '</div><div class="dfs-right">';
    foreach ($L['header_right'] as [$txt, $main]) $o .= '<div class="' . ($main ? 'm' : '') . '">' . $h($txt) . '</div>';
    $o .= '</div></header>';
    // Chave + números + QR.
    $qr = '';
    if ((string)$L['qr_text'] !== '') { $svg = nexa_print_qr_svg((string)$L['qr_text']); $qr = $svg !== '' ? nexa_print_svg_data_uri($svg) : ''; }
    $o .= '<div class="dfs-top"><div><div class="dfs-g"><div class="dfs-c dfs-s4"><div class="l">CHAVE DE ACESSO DA NFS-E</div><div class="v">' . $h($L['access_key'] ?: '-') . '</div></div></div>';
    foreach ($L['top'] as $r) {
      $o .= '<div class="dfs-g" style="grid-template-columns:repeat(3,1fr)">';
      foreach ($r as $c) $o .= '<div class="dfs-c"><div class="l">' . $h(mb_strtoupper((string)$c[0], 'UTF-8')) . '</div><div class="v">' . $h($c[1]) . '</div></div>';
      $o .= '</div>';
    }
    $o .= '</div><div class="dfs-qr">' . ($qr !== '' ? '<img src="' . $h($qr) . '" alt="QR Code da NFS-e">' : '') . '<div>' . $h($L['qr_note']) . '</div></div></div>';
    foreach ($L['sections'] as $sec) {
      $o .= '<section class="dfs-sec">';
      foreach ($sec['rows'] as $row) {
        $o .= '<div class="dfs-g">';
        foreach ($row as $c) $o .= $cell($c);
        $o .= '</div>';
      }
      foreach ((array)($sec['notes'] ?? []) as $note) $o .= '<div class="dfs-note">' . $h($note) . '</div>';
      $o .= '</section>';
    }
    $o .= '<div class="dfs-info"><div class="dfs-c"><div class="l" style="font-size:7.6pt;font-weight:900">INFORMAÇÕES COMPLEMENTARES</div></div><div class="v">' . $h(implode("\n", (array)$L['info'])) . '</div></div>';
    $o .= '<div class="dfs-bottom">';
    foreach ($L['bottom'] as $c) $o .= $cell([mb_strtoupper((string)$c[0], 'UTF-8'), $c[1], $c[2]]);
    $o .= '</div></div></main>';
    return $o;
  }
}

/* ------------------------------------------------------------------------- */
/* PDF (enviado por WhatsApp / e-mail)                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('nfse_danfse_pdf_build')) {
  function nfse_danfse_pdf_build(array $document): array {
    nfse_document_pdf_bootstrap();
    $L = nfse_danfse_layout($document);
    $tx = 'nfse_document_pdf_text';
    $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P', 'mm', 'A4');
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $X = 5.0; $W = 200.0; $BOTTOM = 292.0; $col = $W / 4;
    $y = 5.0;
    $up = static fn(string $s): string => function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);

    // Quebra de texto pela largura real das letras.
    $wrap = function (string $text, float $width, float $size, string $style = '') use ($pdf, $tx): array {
      $pdf->SetFont('Arial', $style, $size);
      $max = max(4.0, $width);
      $out = [];
      foreach (preg_split('/\R/u', $text) ?: [''] as $para) {
        $line = '';
        foreach (preg_split('/\s+/u', trim($para)) ?: [] as $word) {
          if ($word === '') continue;
          $try = $line === '' ? $word : $line . ' ' . $word;
          if ($pdf->GetStringWidth($tx($try)) <= $max) { $line = $try; continue; }
          if ($line !== '') $out[] = $line;
          while ($pdf->GetStringWidth($tx($word)) > $max && mb_strlen($word) > 1) {
            $cut = mb_strlen($word);
            while ($cut > 1 && $pdf->GetStringWidth($tx(mb_substr($word, 0, $cut))) > $max) $cut--;
            $out[] = mb_substr($word, 0, $cut);
            $word = mb_substr($word, $cut);
          }
          $line = $word;
        }
        $out[] = $line;
      }
      return $out ?: [''];
    };
    // Mede/desenha um campo (rótulo em negrito pequeno + valor).
    $cellSpec = static function (array $c): array {
      $style = (string)($c[4] ?? '');
      return [
        'label' => (string)($c[0] ?? ''), 'value' => (string)($c[1] ?? ''), 'span' => max(1, min(4, (int)($c[2] ?? 1))),
        'lsize' => $style === 'title' ? 7.0 : (in_array($style, ['big', 'big-shade'], true) ? 7.2 : 5.9),
        'vsize' => in_array($style, ['big', 'big-shade'], true) ? 7.3 : 6.9,
        'shade' => $style === 'big-shade',
      ];
    };
    $cellHeight = function (array $s, float $w) use ($wrap): float {
      $h = 1.2;
      if ($s['label'] !== '') $h += count($wrap($s['label'], $w - 2.4, $s['lsize'], 'B')) * ($s['lsize'] * .42);
      if ($s['value'] !== '') $h += count($wrap($s['value'], $w - 2.4, $s['vsize'])) * ($s['vsize'] * .43);
      return $h + .6;
    };
    $drawCell = function (array $s, float $x, float $yy, float $w, float $h) use ($pdf, $wrap, $tx): void {
      if ($s['shade']) { $pdf->SetFillColor(230, 230, 230); $pdf->Rect($x, $yy, $w, $h, 'F'); }
      $cy = $yy + .9;
      if ($s['label'] !== '') {
        foreach ($wrap($s['label'], $w - 2.4, $s['lsize'], 'B') as $ln) { $pdf->SetXY($x + 1.2, $cy); $pdf->SetFont('Arial', 'B', $s['lsize']); $pdf->Cell($w - 2.4, $s['lsize'] * .42, $tx($ln), 0, 0, 'L'); $cy += $s['lsize'] * .42; }
      }
      if ($s['value'] !== '') {
        foreach ($wrap($s['value'], $w - 2.4, $s['vsize']) as $ln) { $pdf->SetXY($x + 1.2, $cy); $pdf->SetFont('Arial', '', $s['vsize']); $pdf->Cell($w - 2.4, $s['vsize'] * .43, $tx($ln), 0, 0, 'L'); $cy += $s['vsize'] * .43; }
      }
    };
    $docTop = $y;
    $newPage = function () use ($pdf, &$y, &$docTop, $X, $W): void {
      $pdf->Rect($X, $docTop, $W, $y - $docTop);
      $pdf->AddPage(); $y = 5.0; $docTop = $y;
    };
    $drawRow = function (array $row, float $baseW = 0) use (&$y, $X, $col, $cellSpec, $cellHeight, $drawCell, $newPage, $BOTTOM): void {
      $specs = array_map($cellSpec, $row);
      $h = 4.0;
      foreach ($specs as $s) $h = max($h, $cellHeight($s, $col * $s['span']));
      if ($y + $h > $BOTTOM - 22) $newPage();
      $x = $X + $baseW;
      foreach ($specs as $s) { $w = $col * $s['span']; $drawCell($s, $x, $y, $w, $h); $x += $w; }
      $y += $h;
    };

    if (!empty($L['homologation']) || !empty($L['cancelled'])) {
      foreach ([['homologation', 'AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL', [0, 0, 0]], ['cancelled', 'NFS-e CANCELADA', [153, 27, 27]]] as [$k, $msg, $rgb]) {
        if (empty($L[$k])) continue;
        $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]); $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetXY($X, $y); $pdf->Cell($W, 5.5, $tx($msg), 1, 1, 'C');
        $y += 6.5;
      }
      $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(0, 0, 0);
      $docTop = $y;
    }
    $pdf->SetLineWidth(.25);

    // Cabeçalho.
    $pdf->SetXY($X + 1.5, $y + 1.5); $pdf->SetFont('Arial', 'B', 19); $pdf->SetTextColor(43, 138, 62); $nfsW = $pdf->GetStringWidth('NFS'); $pdf->Cell($nfsW, 8, 'NFS', 0, 0, 'L');
    $pdf->SetTextColor(29, 79, 145); $pdf->Cell(5, 8, 'e', 0, 0, 'L');
    $pdf->SetTextColor(85, 85, 85); $pdf->SetFont('Arial', '', 6.4); $pdf->SetXY($X + 3 + $nfsW + 4.5, $y + 2.6); $pdf->Cell(30, 3, $tx('Nota Fiscal de'), 0, 2, 'L'); $pdf->Cell(30, 3, $tx('Serviço Eletrônica'), 0, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 9.2); $pdf->SetXY($X + $col * 1.33, $y + 2); $pdf->Cell($col * 1.34, 4, $tx($L['title']), 0, 2, 'C'); $pdf->Cell($col * 1.34, 4, $tx($L['subtitle']), 0, 0, 'C');
    $ry = $y + 1.6;
    foreach ($L['header_right'] as [$txt, $main]) { $pdf->SetFont('Arial', '', $main ? 8 : 6.4); $pdf->SetXY($X + $col * 2.72, $ry); $pdf->Cell($col * 1.28 - 1, $main ? 4 : 3, $tx($txt), 0, 0, 'L'); $ry += $main ? 4.6 : 3; }
    $y += 13; $pdf->Line($X, $y, $X + $W, $y);

    // Chave, números e QR (QR ocupa a 4ª coluna).
    $topStart = $y;
    $drawRow([['CHAVE DE ACESSO DA NFS-E', $L['access_key'] ?: '-', 3]]);
    foreach ($L['top'] as $r) $drawRow(array_map(static fn($c) => [$up((string)$c[0]), $c[1], 1], $r));
    $qrH = $y - $topStart;
    if ((string)$L['qr_text'] !== '') {
      $png = nexa_print_qr_png_data((string)$L['qr_text'], 3, 2);
      if ($png !== '') {
        $tmp = tempnam(sys_get_temp_dir(), 'nexa_danfse_qr_');
        if ($tmp !== false) {
          file_put_contents($tmp, $png);
          try { $s = min(22.0, $qrH - 12); $pdf->Image($tmp, $X + $col * 3 + ($col - $s) / 2, $topStart + 1, $s, $s, 'PNG'); } catch (Throwable $e) {}
          @unlink($tmp);
        }
      }
    }
    $note = $wrap((string)$L['qr_note'], $col - 2.4, 5.8);
    $ny = $y - count($note) * 2.5 - .8;
    $pdf->SetFont('Arial', '', 5.8);
    foreach ($note as $ln) { $pdf->SetXY($X + $col * 3 + 1.2, $ny); $pdf->Cell($col - 2.4, 2.5, $tx($ln), 0, 0, 'L'); $ny += 2.5; }

    // Seções.
    foreach ($L['sections'] as $sec) {
      if ($y + 12 > $BOTTOM - 22) $newPage();
      $pdf->Line($X, $y, $X + $W, $y);
      foreach ($sec['rows'] as $row) $drawRow($row);
      foreach ((array)($sec['notes'] ?? []) as $note) {
        $pdf->Line($X, $y, $X + $W, $y);
        $pdf->SetFont('Arial', '', 6.4); $pdf->SetXY($X, $y + .2); $pdf->Cell($W, 3.2, $tx($note), 0, 0, 'C');
        $y += 3.6;
      }
    }

    // Informações complementares (ocupa o resto da página) + rodapé.
    $pdf->Line($X, $y, $X + $W, $y);
    $pdf->SetFont('Arial', 'B', 7.4); $pdf->SetXY($X + 1.2, $y + .9); $pdf->Cell($W - 2.4, 3.2, $tx('INFORMAÇÕES COMPLEMENTARES'), 0, 0, 'L');
    $y += 4.6;
    $footH = 9.0;
    foreach ($wrap(implode("\n", (array)$L['info']), $W - 2.4, 6.9) as $ln) {
      if ($y + 3 > $BOTTOM - $footH) $newPage();
      $pdf->SetFont('Arial', '', 6.9); $pdf->SetXY($X + 1.2, $y); $pdf->Cell($W - 2.4, 3, $tx($ln), 0, 0, 'L');
      $y += 3;
    }
    $fy = $BOTTOM - $footH;
    $pdf->Line($X, $fy, $X + $W, $fy);
    $x = $X;
    foreach ($L['bottom'] as $c) {
      $w = $col * (int)$c[2];
      if ($x > $X) $pdf->Line($x, $fy, $x, $BOTTOM);
      $drawCell($cellSpec([$up((string)$c[0]), $c[1], $c[2]]), $x, $fy, $w, $footH);
      $x += $w;
    }
    $pdf->Rect($X, $docTop, $W, $BOTTOM - $docTop);

    $content = $pdf->Output('', 'S');
    if (!is_string($content) || !str_starts_with($content, '%PDF')) throw new RuntimeException('Não foi possível gerar o PDF da NFS-e.');
    $filename = 'DANFSE-NFSE-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$L['filename_id']) . '.pdf';
    return ['content' => $content, 'filename' => $filename, 'mimetype' => 'application/pdf', 'size' => strlen($content), 'label' => 'NFS-e'];
  }
}
