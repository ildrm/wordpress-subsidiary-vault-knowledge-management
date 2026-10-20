<?php
declare(strict_types=1);
// Unit/local HTTP fixtures must never inherit a deployment's database, KMS, storage or identity settings.
foreach(array_keys(getenv()) as $variable){
    if(str_starts_with($variable,'SV_') && $variable!=='SV_TEST_REPORT'){putenv($variable);}
}
putenv('SV_ENV=local');
