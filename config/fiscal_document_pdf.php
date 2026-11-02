<?php
declare(strict_types=1);
require_once __DIR__ . '/nfse_document_pdf.php';


if (!function_exists('fiscal_document_pdf_compact_ibpt_text')) {
    function fiscal_document_pdf_compact_ibpt_text(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') return '';
        $m = [];
        if (!preg_match('~Federal\s+R\$\s*([0-9.,]+).*?Estadual\s+R\$\s*([0-9.,]+).*?Municipal\s+R\$\s*([0-9.,]+).*?Total\s+R\$\s*([0-9.,]+)~iu', $text, $m)) {
            return $text;
        }
        $source = '';
        if (preg_match('~Fonte:\s*([^\r\n]+)$~iu', $text, $sm)) $source = trim((string)$sm[1]);
        $prefix = preg_replace('~Tributos\s+aproximados\s+Lei\s+12\.741/2012:.*$~iu', '', $text) ?? $text;
        $prefix = preg_replace('~Tributos\s+totais\s+Incidentes\s*\(Lei\s+Federal\s+12\.741/2012\):\s*R\$\s*[0-9.,]+\.?\s*~iu', '', $prefix) ?? $prefix;
        $prefix = preg_replace('~Fiscal\s+gerado\s+pelo\s+Nexa\s+Prospero\s+para\s+assinatura\s+e\s+autorizacao\s+pelo\s+motor\s+fiscal\s+configurado\.?\s*~iu', '', $prefix) ?? $prefix;
        $prefix = trim($prefix, " \t\n\r\0\x0B.;-");
        $line1 = 'Trib. aprox. Lei 12.741/12: Fed R$ ' . $m[1] . ' | Est R$ ' . $m[2] . ' | Mun R$ ' . $m[3];
        $line2 = 'Total R$ ' . $m[4] . ($source !== '' ? ' | Fonte: ' . $source : '');
        return trim(($prefix !== '' ? $prefix . "\n" : '') . $line1 . "\n" . $line2);
    }
}

if (!function_exists('fiscal_document_pdf_prepare_xml')) {
    function fiscal_document_pdf_prepare_xml(string $xml, array $issuer = []): string
    {
        if (trim($xml) === '' || !class_exists(DOMDocument::class)) return $xml;
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            libxml_use_internal_errors(true);
            if (!$dom->loadXML($xml)) return $xml;
            $xp = new DOMXPath($dom);
            $emit = $xp->query('//*[local-name()="infNFe"]/*[local-name()="emit"]')->item(0);
            if ($emit instanceof DOMElement && $issuer) {
                $set = static function (DOMElement $parent, string $tag, string $value) use ($xp, $dom): void {
                    $value = trim($value);
                    if ($value === '') return;
                    $node = $xp->query('./*[local-name()="' . $tag . '"]', $parent)->item(0);
                    if ($node instanceof DOMElement) {
                        $node->nodeValue = $value;
                        return;
                    }
                    $ns = $parent->namespaceURI ?: 'http://www.portalfiscal.inf.br/nfe';
                    $parent->appendChild($dom->createElementNS($ns, $tag, $value));
                };
                $set($emit, 'xNome', (string)($issuer['name'] ?? ''));
                $doc = preg_replace('/\D+/', '', (string)($issuer['document'] ?? '')) ?: '';
                if (strlen($doc) === 14) $set($emit, 'CNPJ', $doc);
                $set($emit, 'IE', (string)($issuer['ie'] ?? ''));
                $ender = $xp->query('./*[local-name()="enderEmit"]', $emit)->item(0);
                if (!$ender instanceof DOMElement) {
                    $ns = $emit->namespaceURI ?: 'http://www.portalfiscal.inf.br/nfe';
                    $ender = $dom->createElementNS($ns, 'enderEmit');
                    $emit->appendChild($ender);
                }
                $set($ender, 'xLgr', (string)($issuer['address'] ?? ''));
                $set($ender, 'nro', (string)($issuer['number'] ?? ''));
                $set($ender, 'xCpl', (string)($issuer['complement'] ?? ''));
                $set($ender, 'xBairro', (string)($issuer['district'] ?? ''));
                $set($ender, 'xMun', (string)($issuer['city'] ?? ''));
                $set($ender, 'UF', strtoupper((string)($issuer['uf'] ?? '')));
                $set($ender, 'CEP', preg_replace('/\D+/', '', (string)($issuer['zip'] ?? '')) ?: '');
                $set($ender, 'fone', preg_replace('/\D+/', '', (string)($issuer['phone'] ?? '')) ?: '');
            }
            $infCpl = $xp->query('//*[local-name()="infAdic"]/*[local-name()="infCpl"]')->item(0);
            if ($infCpl instanceof DOMElement) {
                $compact = fiscal_document_pdf_compact_ibpt_text((string)$infCpl->textContent);
                if ($compact !== '') $infCpl->nodeValue = $compact;
            }
            $out = $dom->saveXML();
            return is_string($out) && $out !== '' ? $out : $xml;
        } catch (Throwable $e) {
            return $xml;
        }
    }
}

/**
 * Gera o PDF oficial do DANFE/DANFCE a partir do XML armazenado no documento fiscal.
 *
 * @return array{content:string,filename:string,mimetype:string,size:int,label:string}
 */
function fiscal_document_pdf_build(array $document): array
{
    $modelRaw = strtoupper(trim((string)($document['model'] ?? '')));
    if ($modelRaw === 'NFSE') return nfse_document_pdf_build($document);
    $model = preg_replace('/\D+/', '', $modelRaw);
    if (!in_array($model, ['55', '65'], true)) {
        throw new RuntimeException('Modelo fiscal não suportado para geração de PDF.');
    }

    $xml = trim((string)($document['xml_raw'] ?? ''));
    $xml = fiscal_document_pdf_prepare_xml($xml, (array)($document['_issuer_identity'] ?? []));
    if ($xml === '') {
        throw new RuntimeException('O XML do documento fiscal não está armazenado no sistema.');
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Biblioteca fiscal não instalada. Execute o Composer do projeto antes de gerar o PDF.');
    }
    require_once $autoload;
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('A extensão PHP DOM/XML não está habilitada no servidor.');
    }

    try {
        if ($model === '55') {
            if (!class_exists(\NFePHP\DA\NFe\Danfe::class)) {
                throw new RuntimeException('Classe de geração do DANFE NF-e não encontrada.');
            }
            $renderer = new \NFePHP\DA\NFe\Danfe($xml);
            $content = $renderer->render();
            $label = 'NF-e';
            $prefix = 'DANFE-NFE';
        } else {
            if (!class_exists(\NFePHP\DA\NFe\Danfce::class)) {
                throw new RuntimeException('Classe de geração do DANFCE NFC-e não encontrada.');
            }
            $renderer = new \NFePHP\DA\NFe\Danfce($xml);
            if (method_exists($renderer, 'setPaperWidth')) {
                $renderer->setPaperWidth(80);
            }
            if (method_exists($renderer, 'setMargins')) {
                $renderer->setMargins(2);
            }
            if (strtoupper((string)($document['status'] ?? '')) === 'CANCELADA' && method_exists($renderer, 'setAsCanceled')) {
                $renderer->setAsCanceled();
            }
            $content = $renderer->render();
            $label = 'NFC-e';
            $prefix = 'DANFCE-NFCE';
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Não foi possível gerar o PDF do documento fiscal: ' . $e->getMessage(), 0, $e);
    }

    if (!is_string($content) || $content === '' || !str_starts_with($content, '%PDF')) {
        throw new RuntimeException('A biblioteca fiscal não retornou um PDF válido.');
    }

    $maxBytes = 8 * 1024 * 1024;
    $size = strlen($content);
    if ($size > $maxBytes) {
        throw new RuntimeException('O PDF gerado ultrapassa o limite de 8 MB para envio pelo WhatsApp.');
    }

    $key = preg_replace('/\D+/', '', (string)($document['access_key'] ?? ''));
    $series = preg_replace('/\D+/', '', (string)($document['series'] ?? ''));
    $number = preg_replace('/\D+/', '', (string)($document['number'] ?? ''));
    $identifier = $key !== '' ? $key : trim($series . '-' . $number, '-');
    if ($identifier === '') {
        $identifier = (string)($document['id'] ?? date('YmdHis'));
    }

    $filename = $prefix . '-' . $identifier . '.pdf';
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: ($prefix . '.pdf');

    return [
        'content' => $content,
        'filename' => $filename,
        'mimetype' => 'application/pdf',
        'size' => $size,
        'label' => $label,
    ];
}
