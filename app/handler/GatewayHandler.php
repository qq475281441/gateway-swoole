<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/1
 * Time: 18:46
 */

namespace app\handler;

use app\auth\Auth;
use app\common\JiGuangPush;
use app\model\Users;
use app\protocols\MessageSendProtocols;
use http\Message;
use im\core\connect\RedisConnectPool;
use im\core\Console;
use im\core\handler\MessageHandler;
use im\core\redis\Redis;
use im\core\service\protocols\GatewayProtocols;
use Swoole\Table;
use swoole_lock;
use swoole_server;

class GatewayHandler extends MessageHandler
{
	protected $count = 0;
	
	protected $servs;
	
	protected $manage;
	
	protected $auth;
	
	/**
	 * @var \Redis
	 */
	protected $redis;
	
	protected $table;
	
	protected $user_table;
	
	protected $console;
	
	protected $_uidConnections = [];
	
	/**
	 * GatewayHandler constructor.
	 * @param Auth $auth
	 */
	public function __construct(Auth $auth)
	{
		$this->auth       = $auth;
		$this->redis      = new Redis();
		$this->table      = $this->createTable();
		$this->user_table = $this->createUserTable();
		$this->console    = new Console();
	}
	
	/**
	 * @param swoole_server $serv
	 */
	public function onStart(swoole_server $serv)
	{
	}
	
	/**
	 * @param swoole_server $serv
	 * @param               $worker_id
	 */
	public function onWorkerStart(swoole_server $serv, $worker_id)
	{
		RedisConnectPool::getInstance()->init();
		if (!$serv->taskworker && $worker_id == 1) {
			swoole_timer_tick(2000, function () use ($serv) {
				$unread = $this->redis->sDiff($this->auth->get_unread_list_key(), $this->auth->get_readed_list_key());
				$jPush  = new JiGuangPush();
				foreach ($unread as $k => $v) {
					$message_id = explode('_', $v);
					$account_id = $message_id[0];
					$msg_detail = $this->redis->get($this->auth->get_msg_detail_key($message_id[1]));
					if ($msg_detail <> false && $msg_detail <> '') {
						$messageObj = (new GatewayProtocols())->decode($msg_detail);
						if ($messageObj->to_uid && $messageObj->to_uid == $account_id && $this->redis->get($this->auth->get_ping_key($messageObj->to_uid))) {
							$this->send_to_uid($serv, $messageObj, false);
						} else {//离线-极光
							
							$jPush_result = new \Chan(1);
							go(function () use ($jPush, $account_id, $messageObj, $jPush_result) {
								$result = $jPush->pushImMsg($account_id,
								                            $messageObj->extra,
								                            $messageObj->data['content_type'] == MessageSendProtocols::CONTENT_TYPE_IMAGES ?
									                            '[图片消息]' : $messageObj->data['content'],
								                            $messageObj->from_uid);
								if ($result === true) {
									$jPush_result->push([$messageObj->message_id, $messageObj->to_uid]);
								} else {
									//极光推送失败的情况
									
								}
							});
							
							go(function () use ($jPush_result) {
								if ($message = $jPush_result->pop(10)) {
									$this->redis->sAdd($this->auth->get_readed_list_key(), $message[1] . '_' . $message[0]);
								}
							});
						}
					} else {
						continue;
					}
				}
				$read_ids = $this->redis->sMembers($this->auth->get_readed_list_key());
				foreach ($read_ids as $k => $v) {
					$this->redis->sRem($this->auth->get_unread_list_key(), $v);
				}
			});
		}
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
		var_dump($data);
		$data = (new GatewayProtocols())->decode($data);
		if ($data->cmd == GatewayProtocols::CMD_PING) {//ping
			$response      = new GatewayProtocols();
			$response->cmd = GatewayProtocols::CMD_PING;
			$serv->send($fd, $response->encode());
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
				case GatewayProtocols::CMD_REGISTER_USER://注册用户
					$user_fd  = $data->fd;//获取fd
					$uid      = $data->data;//获取uid
					$serv_key = $data->key;//获取servkey
					
					if (explode('_', $uid)[0] == '1') {//买家
						$this->redis->set("user_account_id:$uid:$user_fd:$serv_key", $data->extra);
					}
					$lock = new swoole_lock(SWOOLE_MUTEX);//加锁
					if ($lock->lockwait(1)) {
						$user_info = $this->user_table->get($uid);
						if ($user_info && $user_info <> '') {
							$user_info = json_decode($user_info['data'], true);
							//检测一波用户是否在线
							$user_info[] = ['fd' => $user_fd, 'serv_key' => $serv_key];
							$this->user_table->set($uid, ['data' => json_encode($user_info)]);
						} else {
							$this->user_table->set($uid, ['data' => json_encode([['fd' => $user_fd, 'serv_key' => $serv_key]])]);
						}
						$lock->unlock();
					}
					
					break;
				case GatewayProtocols::CMD_UNREGISTER_USER://解绑用户某个fd
					$user_fd  = $data->fd;//获取fd
					$uid      = $data->data;//获取uid
					$serv_key = $data->key;//获取servkey
					if (explode('_', $uid)[0] == '1') {//买家
						$this->redis->del("user_account_id:$uid:$user_fd:$serv_key");
					}
					$lock = new swoole_lock(SWOOLE_MUTEX);//加锁
					if ($lock->lockwait(1)) {
						$user_info = $this->user_table->get($uid);
						if ($user_info && $user_info <> '' && $user_info['data'] <> 'null') {
							$user_info = json_decode($user_info['data'], true);
							foreach ($user_info as $k => $v) {
								if ($v['serv_key'] == $serv_key && $v['fd'] == $user_fd) {
									unset($user_info[$k]);
								}
							}
							$this->user_table->set($uid, ['data' => json_encode($user_info)]);
							$lock->unlock();
						}
					}
					
					break;
				case  GatewayProtocols::CMD_SEND_TO_UID://往uid发消息
					$this->send_to_uid($serv, $data);
					break;
			}
		}
	}
	
	/**
	 * 给uid发消息
	 * @param swoole_server $serv
	 * @param               $data
	 * @return bool
	 */
	private function send_to_uid(swoole_server $serv, GatewayProtocols $data, $need_to_sender = true)
	{
		$recv_fds   = $this->user_table->get($data->to_user_type . '_' . $data->to_uid);
		$sender_fds = $this->user_table->get($data->from_user_type . '_' . $data->from_uid);
		$sender_fd  = $data->fd;
		if ($recv_fds && $recv_fds <> '' && isset($recv_fds['data'])) {
			$recv_fds = json_decode($recv_fds['data'], true);//该用户绑定的fd
			if (!$recv_fds) {
				return false;
			}
			foreach ($recv_fds as $k => $v) {
				$serv_fd   = $this->table->get($v['serv_key']);//子服务的fd
				$data->cmd = GatewayProtocols::CMD_GATEWAY_PUSH;//协议转发复用
				$data->fd  = $v['fd'];//需要接受消息的fd
				if ($serv->exist($serv_fd['fd'])) {
					if ($data->to_user_type == '1') {//商家发给买家,判断fd是否访问的是当前发送信息的商家店铺，否则不发消息给他
						$user_account_id = $this->redis->get("user_account_id:1_$data->to_uid:$data->fd:" . $v['serv_key']);
						if ($user_account_id && $user_account_id == $data->from_uid) {
							$serv->send($serv_fd['fd'], $data->encode());
						}
					} else {//买家发给商家
						$serv->send($serv_fd['fd'], $data->encode());
					}
				}
			}
		}
		if ($need_to_sender === true
			&& $sender_fds
			&& $sender_fds <> ''
			&& isset($sender_fds['data'])
			&& $data->data['content_type'] <> MessageSendProtocols::CONTENT_TYPE_AUTO_REPLY
			&& $data->data['content_type'] <> MessageSendProtocols::CONTENT_TYPE_AUTO_MENU_REPLY
			&& $data->from_user_type <> '1'//买家自己发的消息不要推给自己的另一个客户端，他们跟一个商家只能一个页面聊天
		) {
			$sender_fds = json_decode($sender_fds['data'], true);//该用户绑定的fd
			if (!$sender_fds) {
				return false;
			}
			foreach ($sender_fds as $k => $v) {
				if ($v['fd'] == $sender_fd) {//不发送给当前这个客户端，其他fd均发送
					continue;
				}
				$serv_fd   = $this->table->get($v['serv_key']);//子服务的fd
				$data->cmd = GatewayProtocols::CMD_GATEWAY_PUSH;//协议转发复用
				$data->fd  = $v['fd'];//需要接受消息的fd
				if ($serv->exist($serv_fd['fd'])) {
					$serv->send($serv_fd['fd'], $data->encode());
				}
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
	 * 用户信息table
	 * @return Table
	 */
	private function createUserTable()
	{
		$table = new Table(40960);
		$table->column('data', Table::TYPE_STRING, 1024);//data,json串
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