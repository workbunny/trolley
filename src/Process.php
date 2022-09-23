<?php
declare(strict_types=1);

namespace Workbunny;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RuntimeException;
use Throwable;
use WorkBunny\EventLoop\Drivers\LoopInterface;
use WorkBunny\EventLoop\Drivers\NativeLoop;
use WorkBunny\EventLoop\Loop;
use WorkBunny\Process\Runtime;
use WorkBunny\Storage\Driver;

class Process
{
    /** @var string|null  */
    protected static ?string $_main_file = null;

    /** @var Runtime 主Runtime */
    protected static Runtime $_main_runtime;

    /** @var Driver 主储存器 */
    protected static Driver $_main_storage;

    /** @var Logger  */
    protected static Logger $_main_logger;

    /** @var null|LoopInterface 主循环 */
    protected static ?LoopInterface $_main_loop = null;

    /** @var string|null 主定时器 */
    protected static ?string $_main_timer = null;

    /** @var float 主定时器间隔 */
    protected static float $_main_timer_interval = 2.0;

    /** @var float 主启动时间 */
    protected static float $_main_start_time = 0.0;

    /** @var Process[] 进程分组 */
    protected static array $_process_group = [];

    /** @var string 分组名称 */
    protected string $_name;

    /** @var bool 重用 */
    protected bool $_reuseport = true;

    /** @var int 分组的process数量 */
    protected int $_count;

    /** @var array  */
    protected array $_address = [
        'scheme' => '',
        'host'   => '',
        'port'   => 80
    ];

    /** @var resource|null */
    protected $_context = null;

    /** @var resource|null  */
    protected $_socket = null;

    /**
     * @var array
     * @see stream_get_transports()
     */
    protected array $_transport = [];

    /** @var Closure[]|null[]  */
    protected array $_events =
        [
            'WorkerStart'  => null, /** @see Process::_childContext() */
            'WorkerReload' => null,// function (Process $process){}
            'WorkerStop'   => null, /** @see Process::stop() */
            'Listener'     => null, /** @see Process::_socketAccept() */
        ];

    /**
     * @param string $name
     * @param int $count
     * @param array $config = [
     *      'log_path' => '',
     *      'event_loop' => '',     @see Loop::create()
     *      'storage_config' => [], @see Driver::__construct()
     *      'runtime_config' => [], @see Runtime::__construct()
     * ]
     * @throws InvalidArgumentException
     */
    public function __construct(string $name, int $count = 1, array $config = [])
    {
        if(isset(self::$_process_group[$name])){
            throw new InvalidArgumentException("Invalid Kernel Name [$name].");
        }

        $this->_name = $name;
        $this->_count = $count;
        $this->_transport = stream_get_transports();

        self::$_process_group[$name] = $this;
        self::$_main_logger          = self::$_main_logger ?? new Logger(WORKBUNNY_NAME, [
            new StreamHandler(
                $config['log_config']['path'] ?? __DIR__ . '/warning.log',
                Logger::WARNING
            ),
            new StreamHandler(
                $config['log_config']['path'] ?? __DIR__ . '/error.log',
                Logger::ERROR
            ),
            new StreamHandler(
                $config['log_config']['path'] ?? __DIR__ . '/notice.log',
                Logger::NOTICE
            )
        ]);
        self::$_main_storage         = self::$_main_storage ?? new Driver($config['storage_config'] ?? [
            'filename' => __DIR__ . '/storage.db',
            'flags' => SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE,
            'encryptionKey' => '',
            'debug' => false
        ]);
        self::$_main_runtime = self::$_main_runtime ?? new Runtime($config['runtime_config'] ?? [
            'pre_gc' => true
        ]);
        self::$_main_loop    = self::$_main_loop ?? Loop::create($config['event_loop'] ?? NativeLoop::class);
    }

    /**
     * @param string $address
     * @param Closure|null $handler = function(Process $process, resource $socket, string $remote_address){}
     * @param array $context
     * @return void
     */
    public function listener(string $address, ?Closure $handler, array $context = []): void
    {
        if(!$this->_address = parse_url($address)){
            throw new InvalidArgumentException("Invalid Address [$address]. ");
        }
        if(!in_array($this->_address['scheme'] ?? '', $this->_transport)){
            throw new InvalidArgumentException("Invalid Transport [$address]. ");
        }
        $context['socket']['backlog'] = $context['socket']['backlog'] ?? WORKBUNNY_DEFAULT_BACKLOG;
        $this->_context = stream_context_create($context);

        if(!$this->isReuseport()){
            $this->_socketCreate();
        }

        $this->on('Listener', $handler);
    }

    /**
     * 套接字创建
     * @return void
     */
    public function _socketCreate(): void
    {
        if(!is_resource($this->_socket)){
            if ($this->isReuseport()) {
                stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
            }
            if(!$this->_socket = stream_socket_server(
                $this->_address['scheme'] . '://' . $this->_address['host'] . ':' . $this->_address['port'],
                $errorCode,
                $errorMessage,
                ($this->_address['scheme'] === WORKBUNNY_TRANSPORT_UDP or $this->_address['scheme'] === WORKBUNNY_TRANSPORT_UDG) ?
                    \STREAM_SERVER_BIND :
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                $this->_context
            )){
                throw new RuntimeException($errorMessage);
            }

            if($this->_address['scheme'] === WORKBUNNY_TRANSPORT_TCP and extension_loaded('sockets')){
                $socket = socket_import_stream($this->_socket);
                socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
            }
            stream_set_blocking($this->_socket, false);
        }
    }

    /**
     * 套接字接收
     * @return void
     */
    public function _socketAccept(): void
    {
        if(
            is_resource($this->_socket) and
            ($handler = $this->getHandler('Listener'))
        ){
            self::mainLoop()->addReadStream($this->_socket, function($stream) use ($handler){

                if(
                    $this->_address['scheme'] === WORKBUNNY_TRANSPORT_UDP OR
                    $this->_address['scheme'] === WORKBUNNY_TRANSPORT_UDG
                ){
                    $result = @stream_socket_recvfrom($stream, WORKBUNNY_MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
                    if (false === $result or empty($remoteAddress)) {
                        return;
                    }


                }else{
                    if(!$result = @stream_socket_accept($stream, 0, $remoteAddress)){
                        return;
                    }
                }
                try{
                    /**
                     * @var string $result UDP和UDG连接下为 recv-buffer
                     * @var resource $result TCP和unix-socket下为 stream资源
                     */
                    $handler($this, $remoteAddress, $result);
                }catch (Throwable $throwable){
                    self::mainLogger()->warning("Listener Handler Threw Exception. ", [
                        'RuntimeID' => $id,
                        'RuntimePID' => $pid,
                        'Trace' => $throwable->getTrace()
                    ]);
                }
            });
        }
    }

    /**
     * 套接字停止接收
     * @return void
     */
    public function _socketUnaccept(): void
    {
        if(is_resource($this->_socket)){
            self::mainLoop()->delReadStream($this->_socket);
            @fclose($this->_socket);
            $this->_socket = null;
        }
    }

    /**
     * 设置事件回调
     * @param string $event
     * @param Closure|null $handler
     * @return void
     * @throws InvalidArgumentException
     */
    public function on(string $event, ?Closure $handler = null): void
    {
        if(!array_key_exists($event, $this->_events)){
            throw new InvalidArgumentException("Invalid Event [$event]. ");
        }
        $this->_events[$event] = $handler;
    }

    /**
     * 获取事件回调
     * @param string $event
     * @return Closure|null
     * @throws InvalidArgumentException
     */
    public function getHandler(string $event): ?Closure
    {
        if(!array_key_exists($event, $this->_events)){
            throw new InvalidArgumentException("Invalid Event [$event]. ");
        }
        return $this->_events[$event];
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * @return int
     */
    public function getCount(): int
    {
        return $this->_count;
    }

    /**
     * @return bool
     */
    public function isReuseport(): bool
    {
        return $this->_reuseport;
    }

    /**
     * @param bool $reuseport
     */
    public function setReuseport(bool $reuseport): void
    {
        $this->_reuseport = $reuseport;
    }

    /**
     * @return LoopInterface
     */
    public static function mainLoop(): LoopInterface
    {
        return self::$_main_loop;
    }

    /**
     * @return Runtime
     */
    public static function mainRuntime(): Runtime
    {
        return self::$_main_runtime;
    }

    /**
     * @return Logger
     */
    public static function mainLogger(): Logger
    {
        return self::$_main_logger;
    }

    /**
     * @return Driver
     */
    public static function mainStorage(): Driver
    {
        return self::$_main_storage;
    }

    /**
     * @return Process[]
     */
    public static function getProcessGroup(): array
    {
        return self::$_process_group;
    }

    /**
     * @return void
     */
    public static function run(): void
    {
        self::_init();

        foreach (self::getProcessGroup() as $process){
            // fork
            for ($i = 0; $i < $process->getCount(); $i ++){
                self::mainRuntime()->child();
            }
            // parentContext
            if(!self::mainRuntime()->isChild()){
                self::_setProcessTittle();
                self::_parentContext($process)();
            }
            // childContext
            if(self::mainRuntime()->isChild()){
                self::_setProcessTittle("worker {$process->getName()} " . self::mainRuntime()->getId());
                self::_childContext($process)();
            }
        }
        // parent loop
        if(!self::mainRuntime()->isChild()){
            // 开始loop
            self::mainLoop()->loop();
        }
    }

    /**
     * // todo 平滑关闭
     * @param int $code
     * @param string|null $log
     * @return void
     */
    public static function stop(int $code = 0, ?string $log = null): void
    {
        self::mainLoop()->destroy();
        self::mainRuntime()->exit($code, $log);
    }

    /**
     * @return void
     */
    protected static function _init(): void
    {
        // only for cli.
        if (PHP_SAPI !== 'cli') {
            self::mainRuntime()->exit(0, 'Only run in PHP-cli. ');
        }
        // start time
        self::$_main_start_time = microtime(true);
        // start file.
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        self::$_main_file = end($backtrace)['file'] ?? 'unknown';
    }

    /**
     * @param string $key
     * @return void
     */
    protected static function _setProcessTittle(string $key = 'master'): void
    {
        cli_set_process_title(WORKBUNNY_NAME . ": $key (" . self::$_main_file . ')');
    }

    /**
     * @param Process $process
     * @return Closure
     */
    protected static function _childContext(Process $process): Closure
    {
        return function() use ($process){
            // 忽略父Runtime执行
            if(!self::mainRuntime()->isChild()){
                return;
            }
            // 移除子Runtime中因在父Runtime创建main timer后fork产生的main timer
            if(self::$_main_timer){
                self::mainLoop()->delTimer(self::$_main_timer);
                self::$_main_timer = null;
            }
            //子Runtime onWorkerStart响应回调
            if($handler = $process->getHandler('WorkerStart')){
                try {
                    $handler($process, self::mainRuntime()->getId());
                }catch (Throwable $throwable){
                    self::mainLogger()->warning("WorkerStart Handler Threw Exception. ", [
                        'RuntimeID' => $id,
                        'RuntimePID' => $pid,
                        'Trace' => $throwable->getTrace()
                    ]);
                }
            }

            if($process->isReuseport()){
                $process->_socketCreate();
            }
            $process->_socketAccept();

            // 开始loop
            self::mainLoop()->loop();
        };
    }

    /**
     * @param Process $process
     * @return Closure
     */
    protected static function _parentContext(Process $process): Closure
    {
        return function() use($process){
            // 禁止子Runtime执行
            if(self::mainRuntime()->isChild()){
                return;
            }

            // 注册监听子进程的future-handler
            self::$_main_timer = self::$_main_timer ?? self::mainLoop()->addTimer(
                0.0,
                self::$_main_timer_interval,
                function () use ($process){
                    // 移除子Runtime因意外开启的main timer
                    if(self::mainRuntime()->isChild()){
                        self::mainLoop()->delTimer(self::$_main_timer);
                        self::$_main_timer = null;
                        return;
                    }
                    $pidMap = self::mainRuntime()->getPidMap();
                    // 监听子Runtime
                    self::mainRuntime()->listen(
                        function (int $id, int $pid) use ($process, &$pidMap){
                            // notice日志
                            self::mainLogger()->notice("Runtime Exited. ", [
                                'RuntimeID' => $id,
                                'RuntimePID' => $pid
                            ]);

                            // 移除子Runtime PID
                            unset($pidMap[$id]);
                            self::mainRuntime()->setPidMap($pidMap);
                            //子Runtime onWorkerStop响应回调
                            if($handler = $process->getHandler('WorkerStop')){
                                try {
                                    $handler($process, $id);
                                }catch (Throwable $throwable){
                                    self::mainLogger()->warning("WorkerStop Handler Threw Exception. ", [
                                        'RuntimeID' => $id,
                                        'RuntimePID' => $pid,
                                        'Trace' => $throwable->getTrace()
                                    ]);
                                }
                            }
                        },
                        function (int $id, int $pid, int $status) use ($process){
                            // todo 子runtime异常退出相关的操作，如记录日志等

                            //子Runtime onWorkerStop响应回调
                            if($handler = $process->getHandler('WorkerStop')){
                                try {
                                    $handler($process, $id);
                                }catch (Throwable $throwable){
                                    self::mainLogger()->warning("WorkerStop Handler Threw Exception. ", [
                                        'RuntimeID' => $id,
                                        'RuntimePID' => $pid,
                                        'Trace' => $throwable->getTrace()
                                    ]);
                                }
                            }
                            // 重新fork一个子Runtime
                            self::mainRuntime()->child(self::_childContext($process), 0, $id);
                        });

                    // 判断所有子Runtime是否存活
                    if(empty($pidMap)){
                        self::stop(0, 'All Processes Exited. ');
                    }
                });
        };
    }

//    protected static function _initStorage()
//    {
//        self::mainStorage()->create()
//    }
}