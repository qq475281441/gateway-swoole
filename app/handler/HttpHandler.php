<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/4
 * Time: 17:17
 */

namespace app\handler;

use im\core\handler\MessageHandler;

class HttpHandler extends MessageHandler
{
	protected $httpServ;
	
	protected $response;
	
	/**
	 * @param \swoole_http_server $serv
	 * @param                     $worker_id
	 */
	public function onWorkerStart(\swoole_http_server $serv, $worker_id)
	{
		$this->httpServ = $serv;
	}
	
	/**
	 * 请求来到
	 * @param \swoole_http_request  $request
	 * @param \swoole_http_response $response
	 */
	public function onRequest(\swoole_http_request $request, \swoole_http_response $response)
	{
		echo 'request';
		$task_id        = $this->httpServ->task('666', 0);
		$this->response = $response;
	}
	
	/**
	 * task
	 * @param \swoole_http_server $serv
	 * @param                     $task_id
	 * @param                     $from_id
	 * @param                     $data
	 */
	public function onTask(\swoole_http_server $serv, $task_id, $from_id, $data)
	{
		echo "Tasker进程接收到数据";
		echo "#{$this->httpServ->worker_id}\tonTask: [PID={$this->httpServ->worker_pid}]: task_id=$task_id, data_len=" . strlen($data) . "." . PHP_EOL;
		$serv->finish($data);
	}
	
	/**
	 * task finish
	 * @param \swoole_http_server $serv
	 * @param                     $task_id
	 * @param                     $data
	 */
	public function onFinish(\swoole_http_server $serv, $task_id, $data)
	{
		echo "Task#$task_id finished, data_len=" . strlen($data) . PHP_EOL;
		$this->response->end('666');
	}
}