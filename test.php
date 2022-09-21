<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$t = new \Workbunny\Process('abc', 1);

$t->on('onWorkerStart', function (){
    \Workbunny\Process::getLoop()->addTimer(0.0, 1.0, function (){
//        dump(hrtime(true));
    });
});

$t::run();
