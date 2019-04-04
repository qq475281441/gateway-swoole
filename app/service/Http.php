<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/4
 * Time: 17:11
 */

namespace app\service;

use app\handler\HttpHandler;
use im\core\Container;
use im\core\service\Server;

class Http extends Server
{
	/**
	 * SwooleServer类型
	 * @var string
	 */
	protected $serverType = 'http';
	
	protected $port       = 91;
	
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
			'worker_num'               => 4,    //worker process num
			'backlog'                  => 128,   //listen backlog
			'daemonize'                => 0,
			'log_file '                => RUNTIME . 'server_log.log',
			'log_level'                => 0,//0 => SWOOLE_LOG_DEBUG1 => SWOOLE_LOG_TRACE2 => SWOOLE_LOG_INFO3 => SWOOLE_LOG_NOTICE4 => SWOOLE_LOG_WARNING5 => SWOOLE_LOG_ERROR
			'max_connection'           => 10000,//不填与服务器ulimit -n相同，默认不超过10000，一个连接约224bytes
			'heartbeat_idle_time'      => 15,//15秒无数据就强制结束他
			'heartbeat_check_interval' => 10,//每60秒进行一次轮训检测
			'open_cpu_affinity'        => true,//cpu亲和性
			'tcp_fastopen'             => true,
			'task_worker_num'          => 2,
			//			'cpu_affinity_ignore'        => [],//cpu网络中断处理
		
		];
	
	public    $messageHandler = HttpHandler::class;//消息处理器
	
	public function __construct($options = [])
	{
		if (isset($options)) {
			$this->option = array_merge($this->option, $options);
		}
		parent::__construct();
	}
	
	/**
	 *  初始化
	 */
	protected function init()
	{
		parent::init();
		// 设置回调
		foreach ($this->event as $event) {
			$obj = Container::get($this->messageHandler);//官方代码直接调用方法，没有上下文，必须使用容器先创建对象
			if (method_exists($obj, 'on' . $event)) {
				$this->swoole->on($event, [$obj, 'on' . $event]);
			}
		}
	}
}