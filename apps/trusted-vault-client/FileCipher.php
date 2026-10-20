<?php
declare(strict_types=1);
namespace SubsidiaryVault\LocalClient;

/** Offline file-encryption utility. Not a reviewed multi-recipient vault protocol. */
final class FileCipher
{
    private const MAGIC = "SVLOCAL1";
    private const CHUNK = 65536;

    public static function generateKey(string $path): void
    {
        $f=fopen($path,'x'); if (!$f) { throw new \RuntimeException('key_file_exists_or_unavailable'); }
        chmod($path,0600);
        try { self::write($f,random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)); } finally { fclose($f); }
    }

    public static function transform(string $operation, string $input, string $output, string $keyFile, string $context): void
    {
        if (!in_array($operation,['encrypt','decrypt'],true)) { throw new \RuntimeException('invalid_operation'); }
        $key=@file_get_contents($keyFile);
        if ($key===false || strlen($key)!==32) { throw new \RuntimeException('invalid_key'); }
        if (file_exists($output) || is_link($output)) { throw new \RuntimeException('output_must_not_exist'); }
        $source=fopen($input,'rb');if (!$source) { throw new \RuntimeException('input_unavailable'); }
        $temp=$output . '.part-' . bin2hex(random_bytes(8));
        $dest=fopen($temp,'x+b');if (!$dest) { fclose($source);throw new \RuntimeException('output_unavailable'); }chmod($temp,0600);
        $aad='svkms-offline-file-v1:' . $context;
        try {
            if ($operation==='encrypt') {
                [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
                self::write($dest,self::MAGIC . $header);
                $chunk=fread($source,self::CHUNK);
                if ($chunk===false) { throw new \RuntimeException('read_failed'); }
                do {
                    $next=fread($source,self::CHUNK);
                    if ($next===false) { throw new \RuntimeException('read_failed'); }
                    $tag=$next===''?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                    $encrypted=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,$aad,$tag);
                    self::write($dest,pack('N',strlen($encrypted)) . $encrypted);$chunk=$next;
                } while ($tag!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            } else {
                if (self::read($source,8)!==self::MAGIC) { throw new \RuntimeException('invalid_format'); }
                $header=self::read($source,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
                $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);
                do {
                    $length=unpack('Nlength',self::read($source,4))['length'];
                    if ($length<17 || $length>self::CHUNK+17) { throw new \RuntimeException('invalid_frame'); }
                    $result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::read($source,$length),$aad);
                    if ($result===false) { throw new \RuntimeException('authentication_failed'); }
                    [$plain,$tag]=$result;
                    if (!in_array($tag,[SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],true)) { throw new \RuntimeException('unsupported_tag'); }
                    self::write($dest,$plain);sodium_memzero($plain);
                } while ($tag!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
                if (fread($source,1)!=='') { throw new \RuntimeException('trailing_data'); }
            }
            if (!fflush($dest) || (function_exists('fsync') && !fsync($dest))) { throw new \RuntimeException('flush_failed'); }
            fclose($dest);$dest=null;
            // Hard link publishes atomically and refuses to overwrite another process's file.
            if (!link($temp,$output)) { throw new \RuntimeException('publish_failed'); }
        } finally {
            sodium_memzero($key);if (isset($state)) { sodium_memzero($state); }
            fclose($source);if (is_resource($dest)) { fclose($dest); }if (is_file($temp)) { unlink($temp); }
        }
    }

    private static function write($file,string $data):void
    {
        $offset=0;$length=strlen($data);
        while($offset<$length){$n=fwrite($file,substr($data,$offset));if($n===false||$n===0){throw new \RuntimeException('write_failed');}$offset+=$n;}
    }
    private static function read($file,int $length):string
    {
        $buffer='';while(strlen($buffer)<$length){$part=fread($file,$length-strlen($buffer));if($part===false||$part===''){throw new \RuntimeException('truncated_input');}$buffer.=$part;}return $buffer;
    }
}
