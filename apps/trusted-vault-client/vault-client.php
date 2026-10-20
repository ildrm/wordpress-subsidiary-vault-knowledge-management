<?php
declare(strict_types=1);
require __DIR__ . '/FileCipher.php';
use SubsidiaryVault\LocalClient\FileCipher;
umask(0077);
try {
    $op=$argv[1]??'';
    if($op==='keygen' && count($argv)===3){FileCipher::generateKey($argv[2]);echo "Generated local file key. Store separately from ciphertext.\n";exit;}
    if(in_array($op,['encrypt','decrypt'],true) && count($argv)===7 && $argv[4]==='--key') {
        FileCipher::transform($op,$argv[2],$argv[3],$argv[5],$argv[6]);echo "Completed offline $op. Unsigned development utility; not a certified vault client.\n";exit;
    }
    fwrite(STDERR,"Usage:\n php vault-client.php keygen PRIVATE_KEY_FILE\n php vault-client.php encrypt INPUT OUTPUT --key PRIVATE_KEY_FILE CONTEXT\n php vault-client.php decrypt INPUT OUTPUT --key PRIVATE_KEY_FILE CONTEXT\n");exit(2);
} catch(Throwable $e){fwrite(STDERR,'Local file operation failed: ' . $e->getMessage() . "\n");exit(1);}
