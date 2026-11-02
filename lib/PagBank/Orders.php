<?php
declare(strict_types=1);

namespace Nexa\PagBank;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final class Orders
{
    private $client;
    private $config;

    public function __construct(Client $client, array $config) { $this->client = $client; $this->config = $config; }

    public function createPix(array $customer, array $item, string $referenceId, string $notificationUrl): array
    {
        $amount = max(100, (int)($item['unit_amount'] ?? 0));
        $minutes = max(5, min(43200, (int)($this->config['pix_expiration_minutes'] ?? 1440)));
        $expiration = (new DateTimeImmutable('now'))->modify('+' . $minutes . ' minutes')->format(DateTimeInterface::ATOM);
        $payload = [
            'reference_id'=>substr($referenceId, 0, 64),
            'customer'=>$customer,
            'items'=>[array_merge([
                'reference_id'=>'NEXA-' . substr($referenceId, 0, 40),
                'name'=>'Assinatura Nexa Próspero',
                'quantity'=>1,
                'unit_amount'=>$amount,
            ], $item)],
            'qr_codes'=>[['amount'=>['value'=>$amount], 'expiration_date'=>$expiration]],
            'notification_urls'=>[$notificationUrl],
        ];
        $response = $this->client->request('create_pix_order', 'POST', '/orders', $payload);
        $order = $response['json'];
        $qr = isset($order['qr_codes'][0]) && is_array($order['qr_codes'][0]) ? $order['qr_codes'][0] : [];
        $copy = (string)($qr['text'] ?? '');
        $imageUrl = self::link($qr['links'] ?? [], 'image/png');
        $textUrl = self::link($qr['links'] ?? [], 'text/plain');
        if ($copy === '' && $textUrl !== '') $copy = $this->fetchText($textUrl);
        // O endpoint de imagem do Sandbox pode retornar QRCO-20001/HTTP 500 mesmo
        // quando o pedido foi criado corretamente. A imagem é acessória: nunca deve
        // invalidar a cobrança. Primeiro obtemos o copia-e-cola e então geramos o
        // QR Code localmente usando a biblioteca já instalada no Nexa.
        $image = '';
        if ($copy !== '') {
            $image = $this->generateLocalQr($copy);
        }
        if ($image === '' && $imageUrl !== '') {
            try {
                $image = $this->fetchBinary($imageUrl);
            } catch (\Throwable $ignored) {
                // Mantém a cobrança válida mesmo se o PNG remoto estiver indisponível.
            }
        }
        if ($copy === '') throw new RuntimeException('O pedido foi criado, mas o PagBank não retornou o Pix copia e cola. Verifique se há uma chave Pix ativa na conta vinculada ao token.');
        return [
            'id'=>(string)($order['id'] ?? ''), 'reference_id'=>(string)($order['reference_id'] ?? $referenceId),
            'status'=>self::status($order), 'copy_paste'=>$copy,
            'qr_image'=>$image !== '' ? base64_encode($image) : '',
            'expires_at'=>(string)($qr['expiration_date'] ?? $expiration),
            'payload'=>$order, 'request_payload'=>$payload, 'log_id'=>(int)$response['log_id'],
        ];
    }

    public function get(string $orderId): array
    {
        return $this->client->request('get_order', 'GET', '/orders/' . rawurlencode($orderId))['json'];
    }

    public static function status(array $payload): string
    {
        foreach (($payload['charges'] ?? []) as $charge) if (is_array($charge) && isset($charge['status'])) return strtoupper((string)$charge['status']);
        foreach (($payload['qr_codes'] ?? []) as $qr) if (is_array($qr) && isset($qr['status'])) return strtoupper((string)$qr['status']);
        return strtoupper((string)($payload['status'] ?? 'WAITING'));
    }

    public static function isPaid(array $payload): bool
    {
        $found = false;
        $walk = function ($node) use (&$walk, &$found): void {
            if ($found || !is_array($node)) return;
            foreach (['status','payment_status'] as $key) {
                if (isset($node[$key]) && in_array(strtoupper((string)$node[$key]), ['PAID','CAPTURED','COMPLETED'], true)) { $found = true; return; }
            }
            foreach ($node as $value) if (is_array($value)) $walk($value);
        };
        $walk($payload);
        return $found;
    }

    private static function link(array $links, string $media): string
    {
        foreach ($links as $link) if (is_array($link) && strtolower((string)($link['media'] ?? '')) === strtolower($media) && !empty($link['href'])) return (string)$link['href'];
        return '';
    }

    private function generateLocalQr(string $content): string
    {
        try {
            if (!class_exists('\Com\Tecnick\Barcode\Barcode')) {
                $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
                if (is_file($autoload)) require_once $autoload;
            }
            if (!class_exists('\Com\Tecnick\Barcode\Barcode')) return '';
            $barcode = new \Com\Tecnick\Barcode\Barcode();
            $object = $barcode->getBarcodeObj('QRCODE,M', $content, -5, -5, 'black', [-2, -2, -2, -2])->setBackgroundColor('white');
            return (string)$object->getPngData();
        } catch (\Throwable $ignored) {
            return '';
        }
    }

    private function fetchText(string $url): string { return trim($this->client->request('download_pix_text', 'GET', $url, null, ['Accept: text/plain'])['raw']); }
    private function fetchBinary(string $url): string { return (string)$this->client->request('download_pix_image', 'GET', $url, null, ['Accept: image/png'])['raw']; }
}
