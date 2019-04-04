<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2018/11/10
 * Time: 17:48
 */

namespace im\core\service;

use im\core\Console;
use im\core\Container;
use Swoole\Http\Server as HttpServer;
use Swoole\Mysql\Exception;
use Swoole\Server as SwooleServer;
use Swoole\Websocket\Server as Websocket;

class Server
{
	protected     $host           = '0.0.0.0';
	
	protected     $port           = 5555;
	
	protected     $swoole;
	
	/**
	 * SwooleServer类型
	 * @var string
	 */
	protected $serverType = 'socket';
	
	/**
	 * Socket的类型
	 * @var int
	 */
	protected $sockType = SWOOLE_SOCK_TCP;//指定Socket的类型，支持TCP、UDP、TCP6、UDP6、UnixSocket Stream/Dgram 6种
	
	/**
	 * 运行模式
	 * @var int
	 */
	protected $mode = SWOOLE_PROCESS;//SWOOLE_BASE 使用Base模式，业务代码在Reactor进程中直接执行,SWOOLE_PROCESS 使用进程模式，业务代码在Worker进程中执行@
	
	
	/**
	 * 配置
	 * @var array
	 */
	protected $option;
	
	/**
	 * 支持的响应事件
	 * @var array
	 */
	protected $event = ['Start', 'Shutdown', 'WorkerStart', 'WorkerStop', 'WorkerExit', 'Connect', 'Receive', 'Packet', 'Close', 'BufferFull', 'BufferEmpty', 'Task', 'Finish', 'PipeMessage', 'WorkerError', 'ManagerStart', 'ManagerStop', 'Open', 'Message', 'HandShake', 'Request'];
	
	protected $pid_file;
	
	/**
	 * Server constructor.
	 */
	public function __construct()
	{
		try {
			// 实例化 Swoole 服务
			switch ($this->serverType) {
				case 'socket':
					$this->swoole = new Websocket($this->host, $this->port);
					break;
				case 'http':
					$this->swoole = new HttpServer($this->host, $this->port, $this->mode, $this->sockType);
					break;
				default:
					$this->swoole = new SwooleServer($this->host, $this->port, $this->mode, $this->sockType);
			}
			// 设置参数
			if (!empty($this->option)) {
				$this->swoole->set($this->option);
			}
			
			// 初始化
			$this->init();
		} catch (\Exception $e) {
			app('console')->error($e->getMessage());
			die;
		}
	}
	
	protected function init()
	{
	}
	
	/**
	 * 魔术方法 有不存在的操作的时候执行
	 * @access public
	 * @param string $method 方法名
	 * @param array  $args 参数
	 * @return mixed
	 */
	public function __call($method, $args)
	{
		call_user_func_array([$this->swoole, $method], $args);
	}
	
	public function start()
	{
		// 启动服务
		return $this->swoole->start();
	}
}