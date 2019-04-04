<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/1
 * Time: 18:46
 */

namespace app\handler;

use app\auth\Auth;
use app\model\Users;
use im\core\connect\RedisConnectPool;
use im\core\Console;
use im\core\handler\MessageHandler;
use im\core\redis\Redis;
use im\core\service\protocols\GatewayProtocols;
use Swoole\Table;
use swoole_server;

class GatewayHandler extends MessageHandler
{
	protected $count = 0;
	
	protected $servs;
	
	protected $manage;
	
	protected $auth;
	
	protected $redis;
	
	protected $table;
	
	protected $console;
	
	/**
	 * GatewayHandler constructor.
	 * @param Auth $auth
	 */
	public function __construct(Auth $auth)
	{
		RedisConnectPool::getInstance()->init();
		$this->auth    = $auth;
		$this->redis   = new Redis();
		$this->table   = $this->createTable();
		$this->console = new Console();
	}
	
	public function onWorkerStart(swoole_server $serv, $worker_id)
	{
	}
	
	/**
	 * 接收到服务信息
	 * @param swoole_server $serv
	 * @param               $fd
	 * @param               $from_id
	 * @param               $data
	 * @return void
	 */
	public function onReceive(swoole_server $serv, $fd, $from_id, $data)
	{
		$this->user_log('网关收到消息', $data);
		$data = (new GatewayProtocols())->decode($data);
		if ($data->cmd == GatewayProtocols::CMD_PING) {//ping
			$serv->send($fd, 'pong');
		} else {
			switch ($data->cmd) {
				case GatewayProtocols::CMD_REGISTER://注册服务
					$key         = $data->key;//获取key
					$client_info = [//网关的服务列表
					                'server_type' => $data->extra,//服务类型
					                'fd'          => $fd,//fd
					];
					$this->table->set($key, $client_info);
					$response      = new GatewayProtocols();
					$response->cmd = GatewayProtocols::CMD_REGISTER;
					$response->key = $key;
					$serv->send($fd, $response->encode());
					break;
				case GatewayProtocols::CMD_GATEWAY_PUSH://网关消息转发任务
					$this->console->info(date('Y-m-d H:i:s', time()) . '>>>>3.1网关获取了消息');
					$serv_key = $data->extra;
					if (!$serv_key) {//传入的servkey是false
						$this->console->error(date('Y-m-d H:i:s', time()) . '>>>>3.4传入的servkey是false');
						$this->user_log('传入的servkey是false');
						return false;
					}
					$serv_fd = $this->table->get($serv_key);
					if (!$serv_fd) {//没有在网关注册过的serv_key直接放弃-->删除客户端
						$this->deleteUserInRedis($data->key);
						$this->console->error(date('Y-m-d H:i:s', time()) . '>>>>3.5(' . $serv_key . ')没有在网关注册过的serv_key直接放弃');
						$this->user_log('没有在网关注册过的serv_key直接放弃');
						return false;
					}
					$response       = new GatewayProtocols();
					$response->cmd  = GatewayProtocols::CMD_GATEWAY_PUSH;
					$response->data = $data->data;
					$response->fd   = $data->fd;
					if ($serv->exist($serv_fd['fd'])) {
						$this->console->success(date('Y-m-d H:i:s', time()) . '>>>>3.2网关推送给服务');
						return $serv->send($serv_fd['fd'], $response->encode());
					}
					$this->user_log('服务不存在网关推送失败');
					$this->console->error(date('Y-m-d H:i:s', time()) . '>>>>3.3服务不存在网关推送失败');
					return;
					break;
				case GatewayProtocols::CMD_ON_MANAGE_CLIENT_MESSAGE://管理客户端发来的消息
					$order_num = $data->extra;//需要推消息的订单号
					$content   = $data->data;//推送消息正文
					//获取房间的所有fd信息并分别推送至指定与它维持通信的服务
					$users = [];
					$room  = $this->auth->getRoomKey($order_num);//roomkey
					$ukey  = $this->redis->sMembers($room);//房间下的所有ukey.
					
					if (!$ukey) {
						if ($serv->exist($fd)) {//判断管理客户端还在不在
							$serv->send($fd, '房间不存在');
							$serv->close($fd);
							$this->user_log('房间不存在' . $room);
						}
					} else {
						if ($serv->exist($fd)) {//判断管理客户端还在不在
							$serv->send($fd, 'success');//直接返回success给客户端，然后再执行后面的逻辑
							$serv->close($fd);
						}
						
						foreach ($ukey as $v) {//房间的所有ukey
							$user = json_decode($this->redis->get($v), true);//ukey的详细信息
							if ($user && $user['server_key'] && $user['fd']) {
								$users[$user['server_key']][] = $user['fd'];//房间的每个fd可能在不同的serv，这里跟serv进行绑定
							} else {
								$this->redis->sRem($room, $v);
							}
						}
						/**
						 * 循环所有房间
						 */
						foreach ($users as $key => $user) {
							$serv_fd = $this->table->get($key);
							if (!$serv_fd) {//没有在网关注册过的serv_key直接放弃
								continue;
							}
							$response        = new GatewayProtocols();
							$response->cmd   = GatewayProtocols::CMD_ON_MESSAGE;
							$response->extra = $user;
							$response->data  = $content;
							if ($serv->exist(intval($serv_fd['fd']))) {
								$serv->send(intval($serv_fd['fd']), $response->encode());
							} else {
								$this->user_log('推送客户不在线');
								unset($this->servs[$key]);
							}
						}
					}
					
					break;
			}
		}
	}
	
	/**
	 * 连接事件
	 * @param swoole_server $serv
	 * @param               $fd
	 */
	//	public function onConnect(swoole_server $serv, $fd)
	//	{
	//	}
	//
	//	/**
	//	 * 关闭事件
	//	 * @param swoole_server $serv
	//	 * @param               $fd
	//	 */
	//	public function onClose(swoole_server $serv, $fd)
	//	{
	//	}
	
	/**
	 * 创建内存表
	 * @return Table
	 */
	private function createTable()
	{
		$table = new Table(1024);
		$table->column('fd', Table::TYPE_INT, 4);
		$table->column('server_type', Table::TYPE_STRING, 10);
		$table->create();
		return $table;
	}
	
	/**
	 * 在redis中删除用户
	 * @param $key
	 */
	private function deleteUserInRedis($key)
	{
		$user = json_decode($this->redis->get($key), true);//根据ukey查询出当前连接所属房间
		if ($user['room'] <> false) {
			$this->redis->sRem($this->auth->getRoomKey($user['room']), $key);//在这个房间移除此ukey
		}
		$this->redis->del($key);//redis中删除用户哈希表
	}
}