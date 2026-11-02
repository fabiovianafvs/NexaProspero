<?php
declare(strict_types=1);

namespace Nexa\PagBank;

final class Webhook
{
    public static function identifiers(array $payload): array
    {
        $ids=[];
        $walk=function($node) use (&$walk,&$ids):void { if(!is_array($node))return; foreach(['id','reference_id'] as $key) if(isset($node[$key])&&is_scalar($node[$key]))$ids[]=trim((string)$node[$key]); foreach($node as $value)if(is_array($value))$walk($value); };
        $walk($payload);
        return array_values(array_unique(array_filter($ids)));
    }

    public static function status(array $payload): string { return Orders::status($payload); }
    public static function isPaid(array $payload): bool { return Orders::isPaid($payload); }
}
