<?php
declare(strict_types=1);

namespace Workbunny;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
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

    /** @var string 进程名称 */
    protected string $_name;

    /** @var int 进程数量 */
    protected int $_count;

    /** @var array  */
    protected array $_runtimeIdMap = [];

    /** @var string|null  */
    protected ?string $_address = null;

    /** @var array */
    protected array $_protocol =
        [
            'tcp', 'udp', 'socket'
        ];

    /** @var Closure[]|null[]  */
    protected array $_events =
        [
            'WorkerStart'  => null,
            'WorkerReload' => null,
            'WorkerStop'   => null,
        ];

    /**
     * @param string $name
     * @param int $count
     * @param array $config = [
     *      'event_loop' => '',     @see Loop::create()
     *      'storage_config' => [], @see Driver::__construct()
     *      'runtime_config' => [], @see Runtime::__construct()
     *      'log_path' => '',
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
     * 新增事件回调
     * @param string $event
     * @param Closure|null $handler
     * @return void
     * @throws InvalidArgumentException
     */
    public function register(string $event, ?Closure $handler = null): void
    {
        if(array_key_exists($event, $this->_events)){
            throw new InvalidArgumentException("The Event already exists [$event]. ");
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
     * @param int $id
     * @return void
     */
    public function addRuntimeId(int $id): void
    {
        $this->_runtimeIdMap[$id] = $id;
    }

    /**
     * @param int $id
     * @return void
     */
    public function delRuntimeId(int $id): void
    {
        if(isset($this->_runtimeIdMap[$id])){
            unset($this->_runtimeIdMap[$id]);
        }
    }

    /**
     * @return array
     */
    public function getRuntimeIdMap(): array
    {
        return $this->_runtimeIdMap;
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
            self::_setProcessTittle("worker {$process->getName()} " . self::mainRuntime()->getId());
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
            // todo 网络

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
                            // 移除processGroup id
                            $process->delRuntimeId($id);
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

    /**
     * @return void
     */
    public static function run(): void
    {
        self::_init();

        foreach (self::getProcessGroup() as $process){
            // fork
            for ($i = 0; $i < $process->getCount(); $i ++){
                $process->addRuntimeId(self::mainRuntime()->child());
            }
            // parentContext
            if(!self::mainRuntime()->isChild()){
                self::_parentContext($process)();
            }
            // childContext
            if(self::mainRuntime()->isChild()){
                self::_childContext($process)();
            }
        }
        // parent loop
        if(!self::mainRuntime()->isChild()){
            // 开始loop
            self::mainLoop()->loop();
        }
    }

    protected static function _init(): void
    {
        self::$_main_start_time = microtime(true);
        // Start file.
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        self::$_main_file = end($backtrace)['file'] ?? 'unknown';
        self::_setProcessTittle();

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
     * @param int $code
     * @param string|null $log
     * @return void
     */
    public static function stop(int $code = 0, ?string $log = null): void
    {
        self::mainLoop()->destroy();
        self::mainRuntime()->exit($code, $log);
    }

//    protected static function _initStorage()
//    {
//        self::mainStorage()->create()
//    }
}