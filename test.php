<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$t = new \Workbunny\Process('abc', 2);

$t->listener('tcp://0.0.0.0:8888', function (\Workbunny\Process $process, string $remoteAddress, $result){
    dump($result);
});

$t::run();
