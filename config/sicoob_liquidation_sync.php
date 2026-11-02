<?php
declare(strict_types=1);
/**
 * Nexa Próspero - Sicoob LIQUI v2.3: baixa assistida com encargos e descontos conciliados.
 * Tarifas informativas, sem débito automático. Recebimento único pelo líquido do retorno.
 * Ajustes sem reconciliação exata ou sem autorização continuam bloqueados.
 * Não é webhook. A consulta nunca altera dados bancários/financeiros.
 */
require_once __DIR__ . '/sicoob_movimentacoes.php';
require_once __DIR__ . '/sicoob_boleto_register.php';

if (!function_exists('sicoob_liqui_digits')) {
    function sicoob_liqui_digits($v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
}
if (!function_exists('sicoob_liqui_number_key')) {
    function sicoob_liqui_number_key($v): string {
        $s=sicoob_liqui_digits($v);
        return ltrim($s, '0') ?: ($s === '' ? '' : '0');
    }
}
if (!function_exists('sicoob_liqui_money')) {
    function sicoob_liqui_money($v): float {
        if (!is_numeric($v)) return -1;
        $n=(float)$v;
        return is_finite($n) ? round($n,2) : -1;
    }
}
if (!function_exists('sicoob_liqui_date')) {
    function sicoob_liqui_date($v): string {
        $s=trim((string)$v);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:T| |$)/', $s, $m)) return '';
        $t=explode('-',$m[1]);
        return checkdate((int)$t[1],(int)$t[2],(int)$t[0]) ? $m[1] : '';
    }
}
/**
 * Reconciliação estrita em CENTAVOS do LIQUI: valorLiquido = nominal + acréscimos -
 * desconto - abatimento. Tarifa bancária é informativa e não entra na fórmula.
 * valorMora não desdobra juros/multa; usa decomposição explícita no retorno ou
 * regras CRE quando a soma coincide EXATAMENTE. Nunca inventa parcelas.
 */
if (!function_exists('sicoob_liqui_cents')) {
    function sicoob_liqui_cents($value): int {
        $amount=sicoob_liqui_money($value);
        if ($amount<0 || $amount>99999999) throw new RuntimeException('Valor bancário ausente, inválido ou fora do limite seguro.');
        return (int)round($amount*100);
    }
}
if (!function_exists('sicoob_liqui_adjustments')) {
    function sicoob_liqui_adjustments(array $r, ?array $cre = null): array {
        $face=sicoob_liqui_cents($r['valorTitulo']??null);
        $net=sicoob_liqui_cents($r['valorLiquido']??null);
        $mora=sicoob_liqui_cents($r['valorMora']??0);
        $desconto=sicoob_liqui_cents($r['valorDesconto']??0);
        $abatimento=sicoob_liqui_cents($r['valorAbatimento']??0);
        $fee=sicoob_liqui_cents($r['valorTarifaMovimento']??0);
        if ($face<=0 || $net<=0 || $desconto+$abatimento>=$face+$mora+1) {
            throw new RuntimeException('Principal, crédito ou descontos/abatimentos inválidos.');
        }
        $fineField=array_key_exists('valorMulta',$r) && $r['valorMulta']!==null && $r['valorMulta']!=='';
        $interestField=array_key_exists('valorJuros',$r) && $r['valorJuros']!==null && $r['valorJuros']!=='';
        $explicitFine=$fineField?sicoob_liqui_cents($r['valorMulta']):0;
        $explicitInterest=$interestField?sicoob_liqui_cents($r['valorJuros']):0;
        $principalAdjustments=$desconto+$abatimento;
        $moraInferred=false;
        // Duas variantes documentais podem existir: valorMora já agrega a multa
        // ou valorMora representa só os juros. Aceita somente a que fecha o crédito.
        if ($mora>0 && $fineField && $net===$face+$mora+$explicitFine-$principalAdjustments
            && $net!==$face+$mora-$principalAdjustments) {
            $fine=$explicitFine;
            $interest=$mora;
            if ($interestField && $explicitInterest!==$interest) throw new RuntimeException('Composição da mora contradiz o valor de juros informado.');
        } elseif ($net===$face+$mora-$principalAdjustments) {
            if ($fineField && $interestField) {
                if ($explicitFine+$explicitInterest!==$mora) throw new RuntimeException('Soma da multa e juros difere da mora informada.');
                $fine=$explicitFine; $interest=$explicitInterest;
            } elseif ($fineField) {
                if ($explicitFine>$mora) throw new RuntimeException('Multa supera o acréscimo confirmado.');
                $fine=$explicitFine; $interest=$mora-$fine;
            } elseif ($interestField) {
                if ($explicitInterest>$mora) throw new RuntimeException('Juros superam o acréscimo confirmado.');
                $interest=$explicitInterest; $fine=$mora-$interest;
            } elseif ($mora===0) {
                $fine=0; $interest=0;
            } elseif ($cre!==null) {
                $expectedFine=sicoob_liqui_cents($cre['fine_amount']??0);
                $expectedInterest=sicoob_liqui_cents($cre['interest_amount']??0);
                if ($expectedFine+$expectedInterest===$mora) {
                    $fine=$expectedFine; $interest=$expectedInterest;
                } elseif ($expectedFine===0) {
                    // Sem multa configurada: mora bancária representa juros.
                    $fine=0; $interest=$mora; $moraInferred=true;
                } else {
                    throw new RuntimeException('O LIQUI informa mora sem separar multa/juros e as regras da empresa não fecham o valor: concilie manualmente.');
                }
            } else {
                throw new RuntimeException('Mora sem composição de juros/multa; aguarde conferência das regras CRE.');
            }
        } elseif ($mora===0 && ($explicitFine>0 || $explicitInterest>0)
            && $net===$face+$explicitFine+$explicitInterest-$principalAdjustments) {
            $fine=$explicitFine; $interest=$explicitInterest;
        } else {
            throw new RuntimeException('O valor liquidado não confere com nominal + juros/multa - descontos/abatimentos. Tarifa não é deduzida.');
        }
        if ($net!==$face+$fine+$interest-$principalAdjustments) {
            throw new RuntimeException('Divergência de centavos na composição da baixa; título preservado.');
        }
        return [
            'principal'=>$face/100,'received'=>$net/100,'fine'=>$fine/100,
            'interest'=>$interest/100,'discount'=>$desconto/100,'rebate'=>$abatimento/100,
            'total_discount'=>$principalAdjustments/100,'fee'=>$fee/100,
            'mora'=>$mora/100,'mora_inferred'=>$moraInferred,
        ];
    }
}
if (!function_exists('sicoob_liqui_get_records')) {
    function sicoob_liqui_get_records(array $cfg, PDO $pdo, string $requestCode, array $fileIds): array {
        if (!ctype_digit($requestCode) || strlen($requestCode)>16) throw new RuntimeException('Solicitação LIQUI inválida.');
        if (!$fileIds || count($fileIds)>20 || !class_exists('ZipArchive')) throw new RuntimeException('Arquivos Sicoob indisponíveis ou extensão ZIP ausente.');
        $c=sicoob_boleto_config($cfg);
        $rows=[];
        $totalBytes=0;
        foreach (array_values(array_unique(array_map('intval',$fileIds))) as $fileId) {
            if ($fileId<=0) throw new RuntimeException('ID do arquivo LIQUI inválido.');
            $q=http_build_query(['numeroCliente'=>$c['numero_cliente'],'codigoSolicitacao'=>$requestCode,'idArquivo'=>$fileId],'','&',PHP_QUERY_RFC3986);
            $res=sicoob_v16_auth_request($cfg,$pdo,'GET','/boletos/movimentacoes/download?'.$q);
            if ((int)($res['status']??0)!==200) throw new RuntimeException(sicoob_v16_error($res));
            $base64=$res['json']['resultado']['arquivo']??null;
            if (!is_string($base64)||$base64===''||strlen($base64)>6000000) throw new RuntimeException('Arquivo LIQUI ausente ou acima do limite seguro (6 MB Base64).');
            $zipBytes=base64_decode($base64,true);
            if (!is_string($zipBytes)||strlen($zipBytes)>4600000||substr($zipBytes,0,2)!=='PK') throw new RuntimeException('ZIP LIQUI inválido.');
            $tmp=tempnam(sys_get_temp_dir(),'sicoob_liqui_');
            if ($tmp===false) throw new RuntimeException('Diretório temporário indisponível.');
            $zip=new ZipArchive();$opened=false;
            try {
                if (file_put_contents($tmp,$zipBytes,LOCK_EX)===false || $zip->open($tmp)!==true) throw new RuntimeException('Não foi possível abrir o ZIP Sicoob.');
                $opened=true;
                if ($zip->numFiles<1||$zip->numFiles>12) throw new RuntimeException('Arquivo LIQUI com quantidade inesperada de entradas.');
                for ($i=0;$i<$zip->numFiles;$i++) {
                    $stat=$zip->statIndex($i);$filename=(string)($stat['name']??'');$size=(int)($stat['size']??0);
                    if (!preg_match('/^[A-Za-z0-9_.-]+\.json$/iD',$filename)||$size<1||$size>5000000) throw new RuntimeException('ZIP Sicoob contém entrada não autorizada.');
                    $totalBytes+=$size;
                    if ($totalBytes>12000000) throw new RuntimeException('Retorno LIQUI acima do limite total de leitura.');
                    $raw=$zip->getFromIndex($i,5000001);
                    if (!is_string($raw)||strlen($raw)>5000000) throw new RuntimeException('JSON LIQUI grande demais.');
                    $doc=json_decode($raw,true,64,JSON_BIGINT_AS_STRING|JSON_THROW_ON_ERROR);
                    if (!is_array($doc)||!array_is_list($doc)) throw new RuntimeException('Estrutura LIQUI desconhecida. Necessária conferência antes da baixa.');
                    foreach ($doc as $item) {
                        if (!is_array($item)) throw new RuntimeException('Registro de movimentação inválido.');
                        if (strtoupper(trim((string)($item['siglaMovimento']??'')))!=='LIQUI') throw new RuntimeException('Arquivo misto ou não identificado como LIQUI. Nenhuma baixa efetuada.');
                        $item['_nexa_file']=(string)$fileId;
                        $rows[]=$item;
                        if (count($rows)>1000) throw new RuntimeException('Mais de 1000 movimentos; reduza o período e solicite nova consulta.');
                    }
                }
            } finally {
                if ($opened) @$zip->close();
                @unlink($tmp);
            }
        }
        return $rows;
    }
}
if (!function_exists('sicoob_liqui_candidates')) {
    function sicoob_liqui_candidates(PDO $pdo,int $cid,int $bank,array $cfg,array $records,bool $lock=false): array {
        $expectedClient=sicoob_liqui_number_key($cfg['convenio']??'');
        $expectedMod=sicoob_liqui_number_key($cfg['carteira']??'');
        if ($expectedClient===''||$expectedMod==='') throw new RuntimeException('Número do cliente e modalidade da conta Sicoob não configurados.');
        $results=[]; $seen=[];
        foreach ($records as $i=>$r) {
            $key=sicoob_liqui_number_key($r['numeroTitulo']??'');
            $client=sicoob_liqui_number_key($r['numeroCliente']??'');
            $mod=sicoob_liqui_number_key($r['modalidade']??'');
            $date=sicoob_liqui_date($r['dataLiquidacao']??'');
            $paid=sicoob_liqui_money($r['valorLiquido']??null);
            $face=sicoob_liqui_money($r['valorTitulo']??null);
            $fee=sicoob_liqui_money($r['valorTarifaMovimento']??0);
            $item=['index'=>$i,'nosso_numero'=>$key,'valor'=>$paid,'nominal'=>$face,'tarifa'=>$fee,
                   'data'=>$date,'status'=>'PENDENTE','motivo'=>'','charge'=>null,'adjustments'=>null];
            if ($key===''||$client!==$expectedClient||$mod!==$expectedMod||$date===''||$paid<=0||$face<=0||$fee<0) {
                $item['motivo']='Identificadores, data ou valores bancários não conferem.'; $results[]=$item;continue;
            }
            if (isset($seen[$key])) {$item['motivo']='Nosso Número repetido no arquivo de liquidação.';$results[]=$item;continue;}
            $seen[$key]=true;
            if (isset($r['dataPrevisaoCredito']) && trim((string)$r['dataPrevisaoCredito'])!=='') {
                $forecast=sicoob_liqui_date($r['dataPrevisaoCredito']);
                if ($forecast==='' || $forecast!==$date) {
                    $item['motivo']='Previsão de crédito do banco diferente da data de liquidação. Confira a data efetiva no extrato para baixar manualmente.';
                    $results[]=$item;continue;
                }
            }
            // A tarifa não reduz o valor do título; também não gera débito automático.
            // Nunca bloqueie recebimento apenas por existir mora/desconto: confira
            // cobrança, saldo principal e composição de centavos primeiro.
            $stmt=$pdo->prepare("SELECT * FROM finance_boleto_charges WHERE company_id=? AND bank_account_id=? AND provider='SICOOB' AND integration_mode='API' AND nosso_numero IS NOT NULL".($lock?' FOR UPDATE':''));
            $stmt->execute([$cid,$bank]);
            $matches=[];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $charge) {
                if (sicoob_liqui_number_key($charge['nosso_numero']??'')===$key) $matches[]=$charge;
            }
            if (count($matches)!==1) {$item['motivo']=count($matches)>1?'Nosso Número ambíguo nesta empresa/conta.':'Cobrança não localizada nesta empresa/conta.';$results[]=$item;continue;}
            $charge=$matches[0];$item['charge']=$charge;
            if (trim((string)($r['seuNumero']??''))!=='' && trim((string)($charge['seu_numero']??''))!=='' && trim((string)$r['seuNumero'])!==trim((string)$charge['seu_numero'])) {
                $item['motivo']='Seu Número diferente do registro Nexa.';$results[]=$item;continue;
            }
            if ((int)round((float)$charge['amount']*100)!==(int)round($face*100)) {$item['motivo']='Valor NOMINAL Sicoob diferente da cobrança Nexa.';$results[]=$item;continue;}
            $localBar=sicoob_liqui_digits($charge['barcode']??'');$bankBar=sicoob_liqui_digits($r['codigoBarras']??'');
            if ($localBar!=='' && $bankBar!=='' && $localBar!==$bankBar) {$item['motivo']='Código de barras não coincide.';$results[]=$item;continue;}
            if (strtoupper((string)$charge['status'])==='PAGO') {$item['status']='JA_BAIXADO';$item['motivo']='Boleto já marcado como pago.';$results[]=$item;continue;}
            $rid=(int)($charge['receivable_id']??0);
            if ($rid<=0) {$item['motivo']='Boleto sem Conta a Receber vinculada.';$results[]=$item;continue;}
            $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''));
            $st->execute([$cid,$rid]);$title=$st->fetch(PDO::FETCH_ASSOC)?:[];
            if (!$title) {$item['motivo']='Conta a Receber não localizada.';$results[]=$item;continue;}
            $open=round(function_exists('finance_cre_title_principal_open')?finance_cre_title_principal_open($pdo,$cid,$title):(float)($title['open_amount']??0),2);
            $previous=(float)($title['paid_amount']??0)>0.009 || $open<(float)($title['amount']??0)-0.009;
            if (strtoupper((string)($title['status']??''))==='BAIXADO' || $open<=0.009) {$item['status']='JA_BAIXADO';$item['motivo']='Conta a Receber já liquidada.';$results[]=$item;continue;}
            if (strtoupper((string)($title['status']??''))==='CANCELADO' || $previous || (int)round($open*100)!==(int)round($face*100)) {
                $item['motivo']='Conta a Receber cancelada, parcialmente paga ou com valor em aberto divergente.';$results[]=$item;continue;
            }
            try {
                // As regras locais (CRE) são utilizadas para separar multa/juros
                // quando o LIQUI retorna apenas valorMora agregado.
                $cre=function_exists('finance_cre_adjustments')
                    ? finance_cre_adjustments($pdo,$cid,$title,$date,$face):null;
                $adjust=sicoob_liqui_adjustments($r,$cre);
                if ((int)round($adjust['principal']*100)!==(int)round($face*100)) {
                    throw new RuntimeException('Valor nominal divergente.');
                }
                if (($adjust['fine']+$adjust['interest']+$adjust['total_discount'])>0.009) {
                    if (!function_exists('finance_settlement_review')) {
                        throw new RuntimeException('Validação de encargos e descontos do Financeiro indisponível.');
                    }
                    $item['review']=finance_settlement_review($pdo,$cid,$title,$date,
                        $adjust['principal'],$adjust['fine'],$adjust['interest'],
                        $adjust['total_discount'],[]);
                }
                $item['adjustments']=$adjust;
            } catch (Throwable $e) {
                $item['motivo']='Conciliação financeira pendente: '.$e->getMessage();
                $results[]=$item;continue;
            }
            $item['status']='APTO';
            $parts=[];
            foreach (['fine'=>'multa','interest'=>'juros','discount'=>'desconto','rebate'=>'abatimento'] as $field=>$name) {
                if ($adjust[$field]>0) $parts[]=$name.' R$ '.number_format($adjust[$field],2,',','.');
            }
            if (!empty($adjust['mora_inferred'])) $parts[]='mora sem detalhamento lançada como juros, pois não há multa prevista na regra local';
            if ($fee>0.009) $parts[]='tarifa bancária APARTADA R$ '.number_format($fee,2,',','.').' (sem débito nesta rotina)';
            $item['motivo']='Recebimento conciliado'.($parts?' com '.implode(', ',$parts):' sem ajustes').'.';
            $results[]=$item;
        }
        return $results;
    }
}
if (!function_exists('sicoob_liqui_diagnostic_money')) {
    // Apenas leitura. Nunca altera as regras de baixa nem pressupõe que valorLiquido seja o crédito em conta.
    function sicoob_liqui_diagnostic_money(array $record,string $field): ?float {
        if (!array_key_exists($field,$record)) return null;
        $value=sicoob_liqui_money($record[$field]);
        return $value < 0 ? null : $value;
    }
}
if (!function_exists('sicoob_liqui_preview')) {
    function sicoob_liqui_preview(PDO $pdo,int $cid,int $bank,array $cfg,array $records): array {
        $rows=sicoob_liqui_candidates($pdo,$cid,$bank,$cfg,$records,false);
        $counts=['aptos'=>0,'ja_baixados'=>0,'pendentes'=>0];
        $diagnostics=[];
        $readyCharges=[]; $feeRecords=0; $feeTotal=0.0;
        foreach ($rows as $i=>$row) {
            if ($row['status']==='APTO') {
                $charge=(array)($row['charge']??[]);
                $fee=(float)($row['tarifa']??0);
                if ($fee>0.009) { $feeRecords++; $feeTotal+=round($fee,2); }
                $readyCharges[]=[
                    'charge_id'=>(int)($charge['id']??0),
                    'nosso_numero_final'=>substr((string)($row['nosso_numero']??''),-6),
                    'amount'=>(float)($row['valor']??0),
                    'principal'=>(float)($row['nominal']??0),
                    'fine'=>(float)($row['adjustments']['fine']??0),
                    'interest'=>(float)($row['adjustments']['interest']??0),
                    'discount'=>(float)($row['adjustments']['discount']??0),
                    'rebate'=>(float)($row['adjustments']['rebate']??0),
                    'fee'=>$fee,
                ];
            }
            if ($row['status']==='APTO') $counts['aptos']++;
            elseif ($row['status']==='JA_BAIXADO') $counts['ja_baixados']++;
            else $counts['pendentes']++;
            if (count($diagnostics)>=20) continue;
            $raw=(array)($records[$i]??[]);
            $nn=sicoob_liqui_digits($raw['numeroTitulo']??'');
            // Referência parcial para localizar o boleto, sem expor Nosso Número completo.
            $short=$nn!=='' ? 'final '.substr($nn,-6) : 'não informado';
            $diagnostics[]=[
                'seq'=>$i+1,
                'nosso_numero_parcial'=>$short,
                'data'=>sicoob_liqui_date($raw['dataLiquidacao']??''),
                'nominal'=>sicoob_liqui_diagnostic_money($raw,'valorTitulo'),
                'liquido'=>sicoob_liqui_diagnostic_money($raw,'valorLiquido'),
                'tarifa'=>sicoob_liqui_diagnostic_money($raw,'valorTarifaMovimento'),
                'mora'=>sicoob_liqui_diagnostic_money($raw,'valorMora'),
                'multa'=>isset($row['adjustments'])?(float)($row['adjustments']['fine']??0):sicoob_liqui_diagnostic_money($raw,'valorMulta'),
                'juros'=>isset($row['adjustments'])?(float)($row['adjustments']['interest']??0):sicoob_liqui_diagnostic_money($raw,'valorJuros'),
                'desconto'=>sicoob_liqui_diagnostic_money($raw,'valorDesconto'),
                'abatimento'=>sicoob_liqui_diagnostic_money($raw,'valorAbatimento'),
                'status'=>(string)$row['status'],
                'motivo'=>(string)$row['motivo'],
            ];
        }
        return [
            'counts'=>$counts,
            'rows'=>array_map(static fn($r)=>['status'=>$r['status'],'motivo'=>$r['motivo']],array_slice($rows,0,100)),
            'diagnostics'=>$diagnostics,
            'ready_charges'=>array_slice($readyCharges,0,30),
            'fee_records'=>$feeRecords,
            'fee_total'=>round($feeTotal,2),
        ];
    }
}
if (!function_exists('sicoob_liqui_settle')) {
    function sicoob_liqui_settle(PDO $pdo,int $cid,int $bank,array $cfg,array $records,bool $feesSeparatelyConfirmed=false): array {
        if (strtoupper((string)($cfg['environment']??''))!=='PRODUCAO') throw new RuntimeException('Baixa Sicoob só é permitida no ambiente PRODUÇÃO.');
        if (!function_exists('sicoob_v15_company_enabled') || !sicoob_v15_company_enabled($cid)) {
            throw new RuntimeException('Baixa Sicoob indisponível: a empresa não está na lista autorizada do piloto.');
        }
        foreach (['finance_boleto_charges','finance_receivables','finance_settlements','finance_movements','finance_settlement_payments'] as $table) {
            if (function_exists('finance_table_exists') && !finance_table_exists($pdo,$table)) throw new RuntimeException('Tabela financeira ausente: '.$table);
        }
        $done=0;$already=0;$pending=0;$errors=[];
        foreach ($records as $r) {
            $pdo->beginTransaction();
            try {
                $matches=sicoob_liqui_candidates($pdo,$cid,$bank,$cfg,[$r],true);
                $m=$matches[0]??[];$st=$m['status']??'PENDENTE';
                if ($st==='JA_BAIXADO') {$already++;$pdo->commit();continue;}
                if ($st!=='APTO') {$pending++;$errors[]=(string)($m['motivo']??'Pendente');$pdo->commit();continue;}
                if ((float)($m['tarifa']??0)>0.009 && !$feesSeparatelyConfirmed) {
                    $pending++;$errors[]='Confirme no extrato que a tarifa do boleto foi debitada separadamente antes da baixa.';$pdo->commit();continue;
                }
                $charge=$m['charge'];$rid=(int)$charge['receivable_id'];$payment=(float)$m['valor'];$date=(string)$m['data'];$chargeId=(int)$charge['id'];
                $adj=(array)($m['adjustments']??[]);
                if (!$adj || (int)round(($adj['principal']+$adj['fine']+$adj['interest']-$adj['total_discount'])*100)!==(int)round($payment*100)) {
                    throw new RuntimeException('Composição bancária mudou entre a conferência e a baixa.');
                }
                $stt=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id=? FOR UPDATE');
                $stt->execute([$cid,$rid]);$title=$stt->fetch(PDO::FETCH_ASSOC)?:[];
                if (!$title || (int)round((float)($title['open_amount']??0)*100)!==(int)round((float)$adj['principal']*100)
                    || (float)($title['paid_amount']??0)>0.009) {
                    throw new RuntimeException('Conta a Receber alterada entre a consulta e a baixa; operação bloqueada.');
                }
                // Segundo bloqueio idempotente: cobrança parcialmente baixada ou acerto ativo.
                $existing=$pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=? AND COALESCE(status,'ATIVO')='ATIVO'");
                $existing->execute([$cid,$rid]);
                if ((int)$existing->fetchColumn()>0) throw new RuntimeException('Já existe baixa ativa deste título; conferência manual necessária.');
                $other=$pdo->prepare("SELECT COUNT(*) FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND id<>? AND status='PAGO'");
                $other->execute([$cid,$rid,$chargeId]);
                if ((int)$other->fetchColumn()>0) throw new RuntimeException('Outro boleto deste título já está pago.');
                if (function_exists('finance_assert_cash_account_effective')) {
                    finance_assert_cash_account_effective($pdo,$cid,$bank,'Conta Sicoob sem permissão para este operador.');
                } elseif (function_exists('access_assert_cash_account')) {
                    access_assert_cash_account($pdo,$cid,$bank,'Conta Sicoob sem permissão para este operador.');
                }
                $methodId=(int)($charge['payment_method_id']??0);
                $method=$methodId>0 && function_exists('billing_payment_method')?billing_payment_method($pdo,$cid,$methodId):[];
                $methodLabel=trim((string)($method['name']??''))?:'Boleto Sicoob';
                $operatorId=function_exists('auth_user_id')?(int)(auth_user_id()??0):0;
                $feeValue=round((float)($m['tarifa']??0),2);
                $notes='Baixa Sicoob LIQUI confirmada pelo operador #'.$operatorId.'; cobrança #'.$chargeId
                    .'; principal R$ '.number_format((float)$adj['principal'],2,',','.')
                    .'; multa R$ '.number_format((float)$adj['fine'],2,',','.')
                    .'; juros R$ '.number_format((float)$adj['interest'],2,',','.')
                    .'; desconto R$ '.number_format((float)$adj['discount'],2,',','.')
                    .'; abatimento R$ '.number_format((float)$adj['rebate'],2,',','.')
                    .'; total crédito R$ '.number_format($payment,2,',','.').'.';
                if (!empty($adj['mora_inferred'])) $notes.=' Mora agregada sem detalhamento pelo banco classificada como juros conforme regra local sem multa. Revisar se necessário.';
                if ($feeValue>0.009) $notes.=' Tarifa LIQUI R$ '.number_format($feeValue,2,',','.').' debitada APARTADA, conforme confirmação do operador. NÃO gerar despesa/tarifa nesta baixa.';
                $review=(array)($m['review']??[]);
                $sql="INSERT INTO finance_settlements (company_id,origin_type,origin_id,settlement_date,payment_method,bank_account_id,amount,fine_amount,interest_amount,discount_amount,waived_fine_amount,waived_interest_amount,waiver_user_id,notes,movement_id) VALUES (?,'RECEBER',?,?,?,?,?,?,?,?,?,?,?,?,NULL)";
                $pdo->prepare($sql)->execute([$cid,$rid,$date,$methodLabel,$bank,
                    $adj['principal'],$adj['fine'],$adj['interest'],$adj['total_discount'],
                    (float)($review['waived_fine_amount']??0), (float)($review['waived_interest_amount']??0),
                    (int)($review['waiver_user_id']??0)?:null,$notes]);
                $settlementId=(int)$pdo->lastInsertId();
                $split=['payment_method_id'=>$methodId?:null,'payment_method'=>$methodLabel,'bank_account_id'=>$bank,'amount'=>$payment];
                $movementId=finance_create_split_movement($pdo,$cid,'RECEBER',$rid,$date,$title,$split,$payment,'','',$notes);
                if ($movementId<=0) throw new RuntimeException('Movimento financeiro não criado; transação revertida.');
                finance_insert_settlement_payment($pdo,$cid,$settlementId,$split,$payment,$movementId);
                $pdo->prepare('UPDATE finance_settlements SET movement_id=? WHERE company_id=? AND id=?')->execute([$movementId,$cid,$settlementId]);
                $pdo->prepare('UPDATE finance_receivables SET paid_amount=paid_amount+?, fine_amount=fine_amount+?, interest_amount=interest_amount+?, discount_amount=discount_amount+?, updated_at=NOW() WHERE company_id=? AND id=?')
                    ->execute([$payment,$adj['fine'],$adj['interest'],$adj['total_discount'],$cid,$rid]);
                finance_update_title_status($pdo,'finance_receivables',$cid,$rid);
                $check=$pdo->prepare('SELECT status,open_amount FROM finance_receivables WHERE company_id=? AND id=?');
                $check->execute([$cid,$rid]);$after=$check->fetch(PDO::FETCH_ASSOC)?:[];
                if (strtoupper((string)($after['status']??''))!=='BAIXADO' || (float)($after['open_amount']??0)>0.009) throw new RuntimeException('A Conta a Receber não ficou quitada; baixa revertida.');
                $upCharge=$pdo->prepare("UPDATE finance_boleto_charges SET status='PAGO',paid_at=?,paid_amount=?,payment_date=?,credit_date=?,credited_amount=?,error_message=NULL,updated_at=NOW() WHERE company_id=? AND bank_account_id=? AND id=? AND status='REGISTRADO'");
                $upCharge->execute([$date.' 00:00:00',$payment,$date,$date,$payment,$cid,$bank,$chargeId]);
                if ($upCharge->rowCount()!==1) throw new RuntimeException('Cobrança alterada durante a baixa; transação revertida.');
                if ((int)($charge['contract_id']??0)>0 && function_exists('billing_table_exists') && billing_table_exists($pdo,'contract_billing_history')) {
                    try {
                        $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,amount,event_at,created_by) VALUES(?,?,?,'BOLETO',?,'BOLETO_PAGO',?,?,NOW(),NULL)")
                            ->execute([$cid,(int)$charge['contract_id'],(int)($charge['generation_id']??0)?:null,$chargeId,'Pagamento Sicoob LIQUI confirmado para cobrança #'.$chargeId.'.',$payment]);
                    } catch (Throwable $ignored) {} // Histórico secundário não impede a baixa financeira íntegra.
                }
                $pdo->commit();$done++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $pending++;$errors[]='Uma baixa não foi realizada: '.$e->getMessage();
            }
        }
        return ['processed'=>$done,'already'=>$already,'pending'=>$pending,'errors'=>array_slice(array_unique($errors),0,5)];
    }
}
