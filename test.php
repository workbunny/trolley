<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$t = new \Workbunny\Process('abc', 2);

//$t->on('WorkerStart', function (){
////    \Workbunny\Process::mainLoop()->addTimer(0.0, 2.0, function (){
////        dump(microtime(true));
////    });
//});

$t->on('WorkerStop', function (\WorkBunny\Process $process, int $runtimeId){

    dump($process->getRuntimeIdMap());
    dump($runtimeId);
});

$t::run();
