<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class Secrets
{
    public static function optional(string $name): ?string
    {
        $file=getenv($name . '_FILE');
        if ($file!==false && $file!=='') {
            $value=@file_get_contents($file);
            if ($value===false || strlen($value)>65536 || trim($value)==='') { throw new \RuntimeException('secret_file_unavailable'); }
            return rtrim($value,"\r\n");
        }
        $value=getenv($name);return $value===false||$value===''?null:$value;
    }
    public static function required(string $name): string
    {
        return self::optional($name) ?? throw new \RuntimeException('required_secret_unavailable');
    }
}
