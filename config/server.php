<?php declare(strict_types=1);
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    'listen'            => 'http://' . yaml('app.default_listen_host') . ':' . yaml('app.default_listen_port'),
    'transport'         => 'tcp',
    'context'           => [],
    'name'              => yaml('app.name'),
    'count'             => yaml('app.debug_mode', true) ? yaml('app.debug_process_count', 2) : cpu_count() * 4,
    'user'              => '',
    'group'             => '',
    'reusePort'         => false,
    'event_loop'        => '',
    'stop_timeout'      => 2,
    'pid_file'          => runtime_path() . '/' . yaml('app.name') . '.pid',
    'status_file'       => runtime_path() . '/' . yaml('app.name') . '.status',
    'stdout_file'       => runtime_path() . '/logs/' . yaml('app.name') . '.log',
    'log_file'          => runtime_path() . '/logs/' . yaml('app.name') . '.log',
    'max_package_size'  => 10 * 1024 * 1024
];
