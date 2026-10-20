<?php
declare(strict_types=1);
foreach(['migrate','seed'] as $command){
    $p=proc_open([PHP_BINARY,__DIR__ . '/vault.php',$command],[STDIN,STDOUT,STDERR],$pipes,dirname(__DIR__));
    if(!is_resource($p)||proc_close($p)!==0){exit(1);}
}
pcntl_exec(PHP_BINARY,['-S','0.0.0.0:8088','-t','services/core-api/public','services/core-api/public/router.php']);
throw new RuntimeException('server_start_failed');
