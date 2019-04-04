<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/1
 * Time: 17:16
 */

namespace app\handler;

use app\auth\Auth;
use app\common\JiGuangPush;
use app\model\Users;
use im\core\connect\MysqlConnectPool;
use im\core\connect\RedisConnectPool;
use im\core\Container;
use im\core\coroutine\CoMysql;
use im\core\handler\MessageHandler;
use im\core\redis\Redis;
use im\core\service\protocols\GatewayProtocols;
use Swoole\Coroutine;
use Swoole\MySQL;
use Swoole\Process;
use Swoole\Table;
use swoole_process;
use swoole_server;
use swoole_websocket_server;
use think\Db;

class WebSocket extends MessageHandler
{
	protected $redis;
	
	protected $process;
	
	public    $serv_key = 0;//服务在网关的key
	
	protected $console;
	
	protected $auth;
	
	protected $table;
	
	protected $user;
	
	protected $users;
	
	/**
	 * TestHandler constructor.
	 *
	 * swoole->start()之前执行
	 * @param swoole_process $process
	 * @param Auth           $auth
	 */
	public function __construct(swoole_process $process, Auth $auth)
	{
		$this->redis    = new Redis();
		$this->process  = $process;//子进程保持网关连接
		$this->console  = Container::get('console');
		$this->auth     = $auth;
		$this->table    = $this->createTable();
		$this->users    = new Users($this->redis, $this->table);
		$this->serv_key = $this->auth->getServerKey();//创建key，才可以在所有worker中共享
		RedisConnectPool::getInstance()->init();
	}
	
	/**
	 * @param swoole_server $serv
	 * @param               $fd
	 * @return
	 */
	public function onConnect(swoole_server $serv, $fd)
	{
		$this->console->success('connect' . $fd);
		$cli_info    = $serv->getClientInfo($fd);
		$remote_ip   = $cli_info['remote_ip'];
		$remote_port = $cli_info['remote_port'];
		$cache       = $this->redis->get(md5('swoole_limit' . $remote_ip));
		if ($cache && $cache >= 5) {//一个ip一秒钟只能调用5次onconnect
			if ($cache >= 5) {
				return $serv->close($fd);
			} else {
				$this->redis->set(md5('swoole_limit' . $remote_ip), ++$cache, 1);//一个ip一秒钟只能调用5次onconnect
			}
		} else {
			$this->redis->set(md5('swoole_limit' . $remote_ip), 1, 1);//一个ip一秒钟只能调用5次onconnect
			$serv->confirm($fd);
		}
		
		if (!$this->table->set($fd, ['ukey' => $this->auth->getUkey($fd)])) {
			return $this->console->error('table设置失败，可能内存不足');
		}
	}
	
	/**
	 * SIGTREM信号关闭事件
	 * @param swoole_server $server
	 */
	public function onShutdown(swoole_server $server)
	{
		$this->removeUkey();
		sleep(2);
		$this->process->write('exit');
	}
	
	/**
	 *移除本serv的ukey绑定
	 */
	private function removeUkey()
	{
		foreach ($this->table as $v) {
			$this->redis->del($v['ukey']);//将ukey绑定用户删除
		}
	}
	
	/**
	 * SWOOLE_PROCESS模式下才有此事件
	 * 服务启动
	 * 已创建了manager进程
	 * 已创建了worker子进程
	 * 已监听所有TCP/UDP/UnixSocket端口，但未开始Accept连接和请求
	 * 已监听了定时器
	 * onStart事件在Master进程的主线程中被调用。
	 *
	 * 准备执行：主Reactor开始接收事件，客户端可以connect到Server
	 *
	 * 在onStart中创建的全局资源对象不能在Worker进程中被使用，因为发生onStart调用时，worker进程已经创建好了
	 * 新创建的对象在主进程内，Worker进程无法访问到此内存区域
	 * 因此全局对象创建的代码需要放置在Server::start之前
	 *
	 * onWorkerStart/onStart是并发执行的，没有先后顺序
	 *
	 * @param swoole_server $serv
	 */
	public function onStart(swoole_server $serv)
	{
		$this->holdPing();//维持心跳
		$this->processEvent($serv, $this->process);//子进程消息监听处理
		Process::signal(SIGINT, function ($signo) use ($serv) {//ctrl+c
			$this->console->error('SIGINT退出中...');
			$serv->shutdown();
		});
		Process::signal(SIGHUP, function ($signo) use ($serv) {//终端的挂断或进程死亡
			$this->console->error('SIGHUP退出中...');
			$serv->shutdown();
		});
	}
	
	/**
	 * 此事件在Worker进程/Task进程启动时发生。这里创建的对象可以在进程生命周期内使用
	 * @param swoole_server $serv
	 * @param               $worker_id
	 */
	public function onWorkerStart(\swoole_server $serv, $worker_id)
	{
		if ($serv->taskworker === true) {// 表示当前的进程是Task工作进程
		
		} else {//表示当前的进程是Worker进程
		
		}
	}
	
	/**
	 * 处理子进程数据
	 * @param $process
	 */
	private function processEvent(swoole_server $serv, $process)
	{
		swoole_event_add($process->pipe,
			function ($pipe) use ($process, $serv) {//获取子进程的管道消息
				try {
					$data = (new GatewayProtocols())->decode($process->read());
					switch ($data->cmd) {
						case GatewayProtocols::CMD_REGISTER://注册
							break;
						case GatewayProtocols::CMD_ON_MESSAGE://需要给指定多个fd发消息
							$fds = $data->extra;//需要接受消息的fd
							$job = [
								'type' => GatewayProtocols::CMD_ON_MESSAGE,
								'fd'   => $fds,
								'data' => $data->data,
							];
							$serv->sendMessage(json_encode($job), mt_rand(1, 3));
							break;
						
						case GatewayProtocols::CMD_PROCESS_DISCONNECT:
							$this->console->error('网关断开连接');
							break;
						
						case GatewayProtocols::CMD_ON_CONNECT_GATEWAY://网关连接成功
							$this->initServKey();//找网关注册
							break;
						case GatewayProtocols::CMD_GATEWAY_PUSH://网关发来的消息推送任务
							$job = [
								'type' => GatewayProtocols::CMD_GATEWAY_PUSH,
								'fd'   => $data->fd,
								'data' => $data->data,
							];
							$this->console->info(date('Y-m-d H:i:s', time()) . '>>>>4服务worker_id' . $serv->worker_id . '收到网关的消息推送任务准备写入管道');
							$serv->sendMessage(json_encode($job), mt_rand(1, 3));
							break;
						default:
							break;
					}
				} catch (\Exception $e) {
					$this->console->error($e->getMessage());
				}
			});
	}
	
	/**
	 *维持子进程的心跳
	 */
	private function holdPing()
	{
		swoole_timer_tick(7000, function () {//让子进程维持和网关的心跳
			$body      = new GatewayProtocols();
			$body->cmd = GatewayProtocols::CMD_PING;
			$this->process->write($body->encode());
		});
	}
	
	/**
	 * master进程给worker发消息
	 * @param swoole_websocket_server $serv
	 * @param                         $src_worker_id
	 * @param                         $data
	 * @return bool
	 */
	public function onPipeMessage(swoole_websocket_server $serv, $src_worker_id, $data)
	{
		$this->console->info('触发onPipe');
		$this->console->info($src_worker_id);
		$data = json_decode($data, true);
		if ($data['type'] == GatewayProtocols::CMD_ON_MESSAGE) {//发网关批量消息任务-给房间发消息
			
			$this->console->error('>>>>>>>>>>>>>>>>>>>>>>>>发网关批量消息任务-给房间发消息');
			foreach ($data['fd'] as $v) {
				if ($serv->exist($v)) {
					$data['data']['data']['content'] = $data['data']['data']['content'];
					$serv->push($v, json_encode($data['data']));//向这些fd发消息
				} else {
					return $this->users->removeUser($data['fd']);
				}
			}
		} else if ($data['type'] == GatewayProtocols::CMD_GATEWAY_PUSH) {//单个消息
			if ($serv->exist($data['fd'])) {
				$data['data']['data']['content'] =$data['data']['data']['content'];
				$this->console->success(date('Y-m-d H:i:s', time()) . '>>>>5管道获取了任务准备发送');
				
				if (!is_array($data['data']['data']['content'])) {//不为数组时
					foreach ($this->safeTips($data['data']['data']['content']) as $k => $v) {//给用户发送安全提示
						$this->result($serv, $data['fd'], $v, 'text', 'success', 'sys');
					}
				}
				return $serv->push($data['fd'], json_encode($data['data']));//向fd发消息
			} else {
				return $this->users->removeUser($data['fd']);
			}
		} else {
			$this->console->error(date('Y-m-d H:i:s', time()) . '>>>>5.2无效消息');
		}
	}
	
	/**
	 * @param swoole_websocket_server $serv
	 * @param \swoole_websocket_frame $frame
	 * @return bool|void
	 * @throws \Exception
	 */
	public function onMessage(swoole_websocket_server $serv, \swoole_websocket_frame $frame)
	{
		$fd   = $frame->fd;
		$data = $frame->data;
		//onMessage访问频率限制
		if (!$this->users->onMessageLimitFreque($this->serv_key, $fd)) {
			return $this->result($serv, $fd, '您的发送频率太快了，休息一下吧', 'text', 'error', 'sys');
		}
		$info = $this->table->get($fd);//每个fd在table必须有记录，没有的话不执行后面
		if (!$info || !isset($info['ukey'])) {
			return $this->result($serv, $fd, '非法客户端', 'text', 'error', 'sys');
		}
		$ukey     = $info['ukey'];
		$userinfo = $this->users->init($ukey);//查询一次用户信息
		if ($data == 'ping') {
			if ($userinfo === false) {
				$this->users->removeUser($fd);
			} else if ($userinfo->user_type == 'account') {
				$this->redis->set($this->auth->getAccountKey($userinfo->account_id), time());//把商户最后一次心跳时间保存起来，用来判断他是否在线
			}
			return $serv->push($fd, 'pong');
		} else {
			try {
				$data = json_decode($data, true);
				switch ($data['type']) {
					case 'say':
						$data['data']['content'] = htmlspecialchars($data['data']['content']);
						if (!$data['data']['content']) {
							return $this->result($serv, $fd, '不能发送空信息', 'text', 'error', 'sys');
						}
						if ($userinfo === false) {
							return $this->result($serv, $fd, '请先登录', 'text', 'no_login', 'sys');
						}
						return $this->sendToRoomAll($serv, $data['data']['content'], $data['data']['type'], $userinfo);
						
						break;
					case 'login':
						if (!$this->serv_key) {
							return $this->result($serv, $fd, '请稍等，服务未就绪...', 'text', 'error', 'sys');
						}
						//登录进房间
						$token     = $data['token'];
						$room      = $data['data']['room'];
						$user_type = $data['data']['type'];
						if (!$room) {
							return $this->result($serv, $fd, '参数错误', 'text', 'error', 'sys');
						}
						if (!$ukey) {
							//table满了，无法接收新连接
							return $this->result($serv, $fd, '服务器超过最大连接数', 'text', 'error', 'sys');
						}
						//验证token
						if ($this->auth->validateToken($token, $user_type, $data['data']['authtype'], $room)) {
							$build             = $this->users;
							$build->room       = $room;
							$build->fd         = $fd;
							$build->user_type  = $user_type;
							$build->server_key = $this->serv_key;
							$build->login_time = time();
							$build->ukey       = $ukey;
							$result            = $build->build();
							if ($result === false) {
								return $this->result($serv, $fd, '登陆失败', 'text', 'error', 'sys');
							} else {
								return $this->result($serv, $fd, '登陆成功', 'text', 'login_success', 'sys');
							}
						} else {
							return $this->result($serv, $fd, '验证失败', 'text', 'token_invalid', 'sys');
						}
						
						break;
					case 'report_detail':
						break;
					
					case 'msg_list':
						break;
					default:
						$serv->push($fd, 'pong');
						break;
				}
			} catch (\Exception $e) {
				$this->console->error('发生异常' . $e->getMessage());
				return $this->result($serv, $fd, '消息格式不正确', 'text', 'error', 'sys');
			}
		}
	}
	
	/**
	 * 链接关闭
	 * @param swoole_server $serv
	 * @param               $fd
	 */
	public function onClose(swoole_server $serv, $fd)
	{
		$this->console->error('close' . $fd);
		$this->users->removeUser($fd);
	}
	
	/**
	 * 提示沟通双方文明用语
	 * @param $content
	 * @return array
	 */
	private function civilizationTips($content, $user_type)
	{
		if (is_array($content) || !in_array($user_type, ['account', 'user'])) {
			return [];
		}
		$config = Container::get('config');
		$key    = $config->config['civilization'];
		
		foreach ($key['content'] as $k => $v) {
			if (stristr($content, $v)) {
				return [$key['tips'][mt_rand(0, count($key['tips']) - 1)]];
			}
		}
		return [];
	}
	
	/**
	 * 仅对买家的安全提示
	 * @param $content
	 * @return array
	 */
	private function safeTips($content, $user_type)
	{
		if (is_array($content) || $user_type <> 'account') {
			return [];
		}
		$tips = [
			'密码' => '请勿将投诉密码告诉除快发卡客服的任何人',
			'明天' => '如商家以各种理由推脱到第二天发货，请联系快发卡QQ公众号：800157060处理',
			'晚点' => '如商家以各种理由推脱到第二天发货，请联系快发卡QQ公众号：800157060处理',
			'撤销' => '撤销投诉前请确保商家已经发货，且商品可以使用',
			'撤诉' => '撤销投诉前请确保商家已经发货，且商品可以使用',
			'死'  => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'你妈' => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'草'  => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'操'  => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'傻逼' => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'沙雕' => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'SB' => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'sb' => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
			'艹'  => '如卖家对你人身攻击，可联系快发卡QQ公众号：800157060处理',
		];
		
		$return = [];
		
		foreach ($tips as $k => $v) {
			if (stristr($content, $k)) {
				$return[] = $v;
			}
		}
		
		return array_unique($return);
	}
	
	/**
	 * 发送给房间所有人
	 * @param swoole_server $serv
	 * @param               $content
	 * @param               $type
	 * @param Users         $sender
	 * @return void
	 * @throws \Exception
	 */
	protected function sendToRoomAll(swoole_server $serv, $content, $type, Users $sender)
	{
		$send_usertype   = $sender->user_type;
		$send_room       = $sender->room;
		$send_fd         = $sender->fd;
		$send_account_id = $sender->account_id;
		$safe_tips       = $this->safeTips($content, $send_usertype);
		$civi_tips       = $this->civilizationTips($content, $send_usertype);
		$all_key         = $this->redis->sMembers($this->auth->getRoomKey($send_room));//房间里的所有ukey查出来
		if (!$all_key) {//找不到房间,并发大会导致这里找不到房间
			return false;
		}
		foreach ($all_key as $ukey) {
			$u = $this->users->init($ukey);
			if ($u === false) {
				$this->users->destoryRedis($ukey, $send_room);
				continue;
			}
			if ($this->serv_key === $u->server_key) {//本服务器
				if ($serv->exist($u->fd)) {//在线
					if ($u->fd != $send_fd) {//不发给自己
						$this->result($serv, $u->fd, $content, $type, 'success', $send_usertype);
					}
					if ($u->user_type == 'user') {
						foreach ($safe_tips as $k => $v) {//给用户发送安全提示
							$this->result($serv, $u->fd, $v, 'text', 'success', 'sys');
						}
					}
					foreach ($civi_tips as $k => $v) {//给用户发送文明用语提示
						$this->result($serv, $u->fd, $v, 'text', 'success', 'sys');
					}
				} else {//不在线，删除这个链接
					$this->users->removeUser($u->fd);
					continue;
				}
			} else {//非本服务器-》推送给网关
				$data           = [
					'msg'  => 'success',
					'from' => $send_usertype,
					'time' => time(),
					'data' => [
						'content' => $content,
						'type'    => $type,
					]
				];
				$request        = new GatewayProtocols();
				$request->cmd   = GatewayProtocols::CMD_GATEWAY_PUSH;
				$request->data  = $data;//需要发送的消息内容
				$request->fd    = $u->fd;//接受消息的fd
				$request->key   = $u->ukey;//ukey也传过去
				$request->extra = $u->server_key;//服务key
				$this->process->write($request->encode());
				$this->console->info(date('Y-m-d H:i:s', time()) . '>>>>1消息写入子进程管道');
			}
		}
		return $this->writeMysql($send_usertype, $send_room, $content, $type, $send_account_id);//聊天记录落盘
		
	}
	
	/**
	 * 此连接$fd的发送队列已触顶即将塞满，这时不应当再向此$fd发送数据
	 * @param swoole_websocket_server $serv
	 * @param                         $fd
	 */
	public function onBufferFull(swoole_websocket_server $serv, $fd)
	{
		echo $this->console->error("连接$fd 缓冲区即将满》》》》》》》》》》》》");
	}
	
	/**
	 * 表明当前的$fd发送队列中的数据已被发出，可以继续向此连接发送数据了
	 * @param swoole_websocket_server $serv
	 * @param                         $fd
	 */
	public function onBufferEmpty(swoole_websocket_server $serv, $fd)
	{
		echo $this->console->success("连接$fd 缓冲区已空，可以继续发消息》》》》》》》》》》》》");
	}
	
	/**
	 * fd从房间移除
	 * @param $room
	 * @param $ukey
	 * @return bool
	 */
	protected function removeRoom($room, $ukey)
	{
		return $this->redis->sRem($this->auth->getRoomKey($room), $ukey);
	}
	
	/**
	 * 聊天记录落盘
	 * @param $from
	 * @param $order_num
	 * @param $content
	 * @param $type
	 * @param $account_id
	 * @return int|string
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function writeMysql($from, $order_num, $content, $type, $account_id)
	{
		$utype = 1;
		switch ($from) {
			case 'user':
				$utype = 1;
				if ($account_id) {//需要判断商户是否在线
					$last_ping = $this->redis->get($this->auth->getAccountKey($account_id));
					if (time() - $last_ping > 15) {//最后一次心跳距离现在超过15秒
						$jpush = new JiGuangPush();
						$jpush->pushReportMsg($account_id, $content, $order_num);
					}
				}
				break;
			case 'account':
				$utype = 2;
				break;
			case 'admin':
				$utype = 3;
				if ($account_id) {//需要判断商户是否在线
					$last_ping = $this->redis->get($this->auth->getAccountKey($account_id));
					if (time() - $last_ping > 15) {//最后一次心跳距离现在超过15秒
						$jpush = new JiGuangPush();
						$jpush->pushReportMsg($account_id, $content, $order_num);
					}
				}
				break;
			case 'sys':
				$utype = 4;
				break;
		}
		return Db::name('report_msg')->insert(
			[
				'order_num'    => $order_num,
				'type'         => $utype,
				'content'      => $content,
				'created_at'   => time(),
				'content_type' => $type
			]);
	}
	
	/**
	 * 发送返回消息
	 * @param swoole_server $serv 服务
	 * @param               $fd         接受者fd
	 * @param               $content    消息正文
	 * @param string        $type 消息的类型，目前是text和image
	 * @param string        $msg 提示信息
	 * @param string        $from sys:系统，user：买家，account：卖家，admin:管理员
	 * @return bool
	 */
	protected function result(swoole_server $serv, $fd, $content, $type = 'text', $msg = 'success', $from = 'user')
	{
		if (!is_array($content) && !trim($content)) {
			return false;
		}
		$data = [
			'msg'  => $msg,
			'from' => $from,
			'data' => [
				'content' => $content,
				'type'    => $type,
			],
		];
		
		return $serv->push($fd, json_encode($data));
	}
	
	/**
	 * 创建表保存所有ukey和fd映射
	 * @return Table
	 */
	private function createTable()
	{
		$table = new Table(20480);//20mb
		$table->column('ukey', Table::TYPE_STRING, 40);
		$table->create();
		return $table;
	}
	
	/**
	 * 向网关注册key
	 * @return int
	 */
	private function initServKey()
	{
		$request        = new GatewayProtocols();
		$request->cmd   = GatewayProtocols::CMD_REGISTER;
		$request->key   = $this->serv_key;
		$request->extra = GatewayProtocols::TYPE_WEB_SOCKET;
		return $this->process->write($request->encode());
	}
}