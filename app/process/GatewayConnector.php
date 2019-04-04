<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2018/11/12
 * Time: 10:44
 */

namespace app\process;

use im\core\Container;
use im\core\service\protocols\GatewayProtocols;
use Swoole\Process;
use swoole_client;
use swoole_process;

/**
 * 网关连接器
 * Class GatewayConnector
 * @package app\process
 */
class GatewayConnector
{
	protected $process;
	
	protected $console;
	
	protected $gateway_host;//网关的地址
	
	protected $gateway_port;//网关的端口
	
	public function __construct(swoole_process $process, $gateway_host, $gateway_port, $option)
	{
		$this->process      = $process;
		$this->gateway_host = $gateway_host;
		$this->gateway_port = $gateway_port;
		$this->console      = Container::get('console');
		$this->init($process);
		if ($option['daemonize']) {
			$this->process::daemon(true, false);
		}
	}
	
	/**
	 * 初始化swoole_client
	 * @param swoole_process $process
	 * @return bool
	 */
	protected function init(swoole_process $process)
	{
		$client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC); //异步非阻塞
		$client->on("connect", function (swoole_client $cli) use ($process) {
			$this->console->success('连接上网关');
			$request      = new GatewayProtocols();
			$request->cmd = GatewayProtocols::CMD_ON_CONNECT_GATEWAY;
			return $process->write($request->encode());
		});
		
		$client->on("receive", function (swoole_client $cli, $data) use ($process) {
			if ($data <> 'pong') {
				return $process->write($data);
			}
		});
		
		$client->on("error", function (swoole_client $cli) use ($process) {
			$response      = new GatewayProtocols();
			$response->cmd = GatewayProtocols::CMD_PROCESS_DISCONNECT;
			$process->write($response->encode());
			
			$this->console->error("网关连接出错了，请检查网关服务是否开启（host:$this->gateway_host,port:$this->gateway_port ）");
			$this->console->info('将在一秒钟后重连...');
			return $this->reconnect($cli);
		});
		
		$client->on("close", function (swoole_client $cli) use ($process) {
			$response      = new GatewayProtocols();
			$response->cmd = GatewayProtocols::CMD_PROCESS_DISCONNECT;
			$process->write($response->encode());
			$this->console->error('子进程与网关连接被关闭');
			$this->console->info('将在一秒钟后重连...');
			return $this->reconnect($cli);
		});
		
		if ($client->connect($this->gateway_host, $this->gateway_port, -1)) {
		
		} else {
			$response      = new GatewayProtocols();
			$response->cmd = GatewayProtocols::CMD_PROCESS_DISCONNECT;
			$process->write($response->encode());
			
			$this->console->error('网关连接失败');
			$this->console->info('将在一秒钟后重连...');
			return $this->reconnect($client);
		}
		
		swoole_event_add($process->pipe,
			function ($pipe) use ($process, $client) {//读取父进程管道消息
				$data = $process->read();
				if ($data === 'exit') {
					Process::kill(getmypid());//杀掉自己
				}
				if ($client->isConnected()) {
					$this->console->info(date('Y-m-d H:i:s', time()) . '>>>>2消息被子进程获取并发送给网关');
					return $client->send($data);
				} else {
					return $this->reconnect($client);
				}
			});
	}
	
	/**
	 * 重连
	 * @param swoole_client $client
	 * @return bool
	 */
	private function reconnect(swoole_client $client)
	{
		sleep(1);//休眠一秒然后重连
		return $client->connect($this->gateway_host, $this->gateway_port, -1);
	}
}