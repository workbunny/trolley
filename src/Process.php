<?php
declare(strict_types=1);

namespace Workbunny;

use Closure;
use InvalidArgumentException;
use WorkBunny\EventLoop\Drivers\LoopInterface;
use WorkBunny\EventLoop\Drivers\NativeLoop;
use WorkBunny\EventLoop\Loop;
use WorkBunny\Process\Runtime;
use WorkBunny\Storage\Driver;

class Process
{
    /** @var Process[]  */
    protected static array $_process = [];

    /** @var Runtime  */
    protected static Runtime $_runtime;

    /** @var Driver  */
    protected static Driver $_storage;

    /** @var null|LoopInterface  */
    protected static ?LoopInterface $_loop = null;

    /** @var string|null  */
    protected static ?string $_main_timer = null;

    /** @var string 名称 */
    protected string $_name;

    /** @var int 进程数量 */
    protected int $_count;

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
            'onMasterStart'  => null,
            'onMasterStop'   => null,
            'onWorkerStart'  => null,
            'onWorkerReload' => null,
            'onWorkerStop'   => null,
        ];

    /**
     * @param string $name
     * @param int $count
     * @param array $config = [
     *      'event_loop' => '',     @see Loop::create()
     *      'storage_config' => [], @see Driver::__construct()
     *      'runtime_config' => [], @see Runtime::__construct()
     * ]
     */
    public function __construct(string $name, int $count = 1, array $config = [])
    {
        if(!isset(self::$_process[$name])){
            $this->_name = $name;
            $this->_count = $count;
            self::$_process[$name] = $this;
        }

        self::$_storage = self::$_storage ?? new Driver($config['storage_config'] ?? [
            'filename' => __DIR__ . '/storage.db',
            'flags' => SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE,
            'encryptionKey' => '',
            'debug' => false
        ]);
        self::$_runtime = self::$_runtime ?? new Runtime($config['runtime_config'] ?? [
            'pre_gc' => true
        ]);
        self::$_loop    = self::$_loop ?? Loop::create($config['event_loop'] ?? NativeLoop::class);
    }

    /**
     * 设置事件回调
     * @param string $event
     * @param Closure|null $handler
     * @return void
     */
    public function on(string $event, ?Closure $handler = null): void
    {
        if(!array_key_exists($event, $this->_events)){
            throw new InvalidArgumentException("Invalid Event [$event]. ");
        }
        $this->_events[$event] = $handler;
    }

    public function listen(string $address, ?Closure $handler = null): void
    {

    }

    /**
     * 获取事件回调
     * @param string $event
     * @return Closure|null
     */
    public function getHandler(string $event): ?Closure
    {
        return $this->_events[$event] ?? null;
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
    public static function getLoop(): LoopInterface
    {
        return self::$_loop;
    }

    /**
     * @return Runtime
     */
    public static function getRuntime(): Runtime
    {
        return self::$_runtime;
    }

    /**
     * @return Driver
     */
    public static function getStorage(): Driver
    {
        return self::$_storage;
    }

    /**
     * @return Process[]
     */
    public static function getProcess(): array
    {
        return self::$_process;
    }

    /**
     * @return void
     */
    public static function run(): void
    {
        $number = 0;
        foreach (self::getProcess() as $process){
            // master-start事件仅触发一次
            if($number === 0 and $number ++){
                // 父进程onMasterStart响应回调
                if($handler = $process->getHandler('onMasterStart')){
                    $handler($process);
                }
            }
            // fork
            self::getRuntime()->run($child = function() use ($process){
                //子进程onWorkerStart响应回调
                if($handler = $process->getHandler('onWorkerStart')){
                    $handler($process);
                }
                // 开始loop
                self::getLoop()->loop();
            }, null, $process->getCount());

            // 主
            self::getRuntime()->parent(function() use ($child){
                // 注册监听子进程的future-handler
                self::$_main_timer = self::getLoop()->addTimer(0.0, 2, function () use ($child){
                    self::getRuntime()->listen(
                        function (int $id, int $pid, int $status) use ($child){
                            // 重新fork一个子进程
                            self::getRuntime()->child(function() use ($child){
                                // 在fork前移除已经创建的main timer
                                if(self::$_main_timer){
                                    self::getLoop()->delTimer(self::$_main_timer);
                                    self::$_main_timer = null;
                                }
                                $child();
                            }, 0, $id);
                        },
                        function (int $id, int $pid, int $status) use ($child){
                            // 重新fork一个子进程
                            self::getRuntime()->child(function() use ($child){
                                // 在fork前移除已经创建的main timer
                                if(self::$_main_timer){
                                    self::getLoop()->delTimer(self::$_main_timer);
                                    self::$_main_timer = null;
                                }
                                $child();
                            }, 0, $id);
                        });
                });
                // 开始loop
                self::getLoop()->loop();
            });
        }
    }

    /**
     * @param int $code
     * @param string|null $log
     * @return void
     */
    public static function stop(int $code = 0, ?string $log = null): void
    {
        self::getLoop()->destroy();
        self::getRuntime()->exit($code, $log);
    }

    public static function reload(): void
    {

    }

//    protected static function _initStorage()
//    {
//        self::getStorage()->create()
//    }
}