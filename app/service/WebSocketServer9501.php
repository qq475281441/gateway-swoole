<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/1
 * Time: 17:16
 */

namespace app\service;

use app\auth\Auth;
use app\handler\WebSocket;
use app\process\GatewayConnector;
use im\core\Container;
use im\core\service\Server;
use swoole_process;

class WebSocketServer9501 extends Server
{
	/**
	 * SwooleServer类型
	 * @var string
	 */
	protected $serverType   = 'socket';
	
	protected $port         = 9501;
	
	protected $gateway_host = '127.0.0.1';//网关的地址
	
	protected $gateway_port = '90';//网关的端口
	
	/**
	 * Socket的类型
	 * @var int
	 */
	protected $sockType = SWOOLE_SOCK_TCP;
	
	/**
	 * 运行模式
	 * @var int
	 */
	protected $mode = SWOOLE_PROCESS;
	
	/**
	 * 配置
	 * @var array
	 */
	protected $option
		                      = [
			'worker_num'                => 4,    //worker process num
			'backlog'                   => 128,   //listen backlog
			'daemonize'                 => 0,
			'log_file'                  => RUNTIME . 'log/ws84_server.log',
			'log_level'                 => 1,//0 => SWOOLE_LOG_DEBUG1 => SWOOLE_LOG_TRACE2 => SWOOLE_LOG_INFO3 => SWOOLE_LOG_NOTICE4 => SWOOLE_LOG_WARNING5 => SWOOLE_LOG_ERROR
			'max_connection'            => 10000,//不填与服务器ulimit -n相同，默认不超过10000，一个连接约224bytes
			'heartbeat_idle_time'       => 15,//15秒无数据就强制结束他
			'heartbeat_check_interval'  => 10,//每60秒进行一次轮训检测
			'open_cpu_affinity'         => true,//cpu亲和性
			'tcp_fastopen'              => true,
			'enable_delay_receive'      => true,//延时收包
			'dispatch_mode'             => 5,
			/**
			 * 1，轮循模式，收到会轮循分配给每一个Worker进程
			 * 2，固定模式，根据连接的文件描述符分配Worker。这样可以保证同一个连接发来的数据只会被同一个Worker处理
			 * 3，抢占模式，主进程会根据Worker的忙闲状态选择投递，只会投递给处于闲置状态的Worker
			 * 4，IP分配，根据客户端IP进行取模hash，分配给一个固定的Worker进程。可以保证同一个来源IP的连接数据总会被分配到同一个Worker进程。算法为 ip2long(ClientIP) % worker_num
			 * 5，UID分配，需要用户代码中调用 Server->bind() 将一个连接绑定1个uid。然后底层根据UID的值分配到不同的Worker进程。算法为 UID % worker_num，如果需要使用字符串作为UID，可以使用crc32(UID_STRING)
			 * 7，stream模式，空闲的Worker会accept连接，并接受Reactor的新请求
			 */
			//			'pipe_buffer_size' => 64 * 1024 *1024, //必须为数字，调整管道通信的内存缓存区长度。Swoole使用Unix Socket实现进程间通信，没卵用，被废弃
			//			'task_ipc_mode'            => '2',
			'max_coroutine'             => 10000,
			'pen_websocket_close_frame' => true,
			//			'buffer_high_watermark' => 8 * 1024 * 1024,//控制FD缓存区高水位线，单位为字节
			'send_yield'                => true,//发送数据协程调度
			//			'task_worker_num'          => 1,
			//			// 设置worker/task子进程的所属用户
			//			'user'                     => 'www',
			//			// 设置worker/task子进程的进程用户组
			//			'group'                    => 'www',
			//			'cpu_affinity_ignore'        => [],//cpu网络中断处理
		
		];
	
	public    $messageHandler = WebSocket::class;//消息处理器
	
	public    $processHandler = GatewayConnector::class;//子进程的消息处理
	
	protected $console;
	
	public function __construct($options = [])
	{
		if (isset($options)) {
			$this->option = array_merge($this->option, $options);
		}
		$this->console = Container::get('console');
		parent::__construct();
	}
	
	/**
	 *  初始化
	 */
	protected function init()
	{
		parent::init();
		//注册process
		$process = new swoole_process(function (swoole_process $process) {
			return Container::get($this->processHandler,
			                      [
				                      'process'      => $process,
				                      'gateway_host' => $this->gateway_host,
				                      'gateway_port' => $this->gateway_port,
				                      'option'       => $this->option,
			                      ]);
		}, false, SOCK_DGRAM);
		$process->name('Gateway');
		$process->start();//启动子进程
		// 设置回调
		$obj = Container::get($this->messageHandler,
		                      [
			                      'process' => $process,
			                      'auth'    => Container::get(Auth::class),
		                      ]
		);//官方代码直接调用方法，没有上下文，必须使用容器先创建对象
		foreach ($this->event as $event) {
			if (method_exists($obj, 'on' . $event)) {
				$this->swoole->on($event, [$obj, 'on' . $event]);
			}
		}
	}
}