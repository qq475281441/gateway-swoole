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
use app\protocols\MessageSendProtocols;
use app\service\Gateway;
use Co;
use http\Message;
use im\core\connect\MysqlConnectPool;
use im\core\connect\RedisConnectPool;
use im\core\Container;
use im\core\coroutine\CoMysql;
use im\core\facade\Cache;
use im\core\handler\MessageHandler;
use im\core\redis\Redis;
use im\core\service\protocols\GatewayProtocols;
use Swoole\Coroutine;
use Swoole\MySQL;
use Swoole\Process;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use swoole_http_request;
use swoole_process;
use swoole_server;
use swoole_websocket_server;
use think\Db;
use think\db\Where;

class WebSocket extends MessageHandler
{
	/**
	 * @var \Redis
	 */
	protected $redis;
	
	protected $process;
	
	public    $serv_key        = 0;//服务在网关的key
	
	protected $console;
	
	protected $auth;
	
	protected $table;
	
	protected $user;
	
	protected $users;
	
	protected $_uidConnections = [];//uid映射链接
	
	protected $cache_time      = 600;
	
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
		$this->serv_key = $this->auth->getServerKey();//创建key，才可以在所有worker中共享
	}
	
	/**
	 * ws握手
	 * @param swoole_websocket_server $serv
	 * @param swoole_http_request     $request
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function onOpen(swoole_websocket_server $serv, swoole_http_request $request)
	{
		$_GET = $request->get;
		if (!isset($_GET['token']) || !isset($_GET['type'])) {
			$this->result($serv, $request->fd, '缺少token或type', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'token_invalid');
			return $serv->disconnect($request->fd, 1001, 'token_invalid');
		}
		if ($_GET['type'] == 'account' && !isset($_GET['authtype'])) {
			$this->result($serv, $request->fd, '缺少authtype', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'token_invalid');
			return $serv->disconnect($request->fd, 1001, 'token_invalid');
		}
		if ($_GET['type'] == 'user' && !isset($_GET['link'])) {
			$this->result($serv, $request->fd, 'link', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'miss_link');
			return $serv->disconnect($request->fd, 1004, 'miss_link');
		}
		if ($uid = $this->auth->validateToken($_GET['token'], $_GET['type'], isset($_GET['authtype']) ? $_GET['authtype'] : '')) {
			//验证通过,在网关绑定uid
			$this->bindID($serv, $request->fd, $uid, $_GET['type'] == 'user' ? GatewayProtocols::TYPE_USER_U : GatewayProtocols::TYPE_USER_ACCOUNT);
			if ($_GET['type'] == 'user') {
				//发送商家自动消息
				$this->bind_account($uid, $_GET['link']);
				$this->send_account_auto_reply($_GET['link'], $request->fd, $uid);
				$this->send_menu_to_user($serv, $_GET['link'], $request->fd, $uid);
			}
			return $this->result($serv, $request->fd, '验证通过', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'login_success');
		} else {
			$this->result($serv, $request->fd, 'token无效', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'token_invalid');
			co::sleep(100);
			return $serv->disconnect($request->fd, 1001, 'token_invalid');
		}
	}
	
	/**
	 * 商家自动回复内容
	 * @param                         $link
	 * @param                         $fd
	 */
	private function send_account_auto_reply($link, $fd, $uid)
	{
		$chan_router = new \Chan(1);
		go(function () use ($link, $chan_router) {
			$router = Db::name('router')->where('short_url', $link)->field('account_id')
				->cache(true, $this->cache_time)->find();
			$chan_router->push($router);
		});
		
		go(function () use ($uid, $chan_router, $fd) {
			if ($account_id = $chan_router->pop(100)) {
				$account_setting         = Db::name('account_settings')->where('account_id', $account_id['account_id'])->field('auto_reply')
					->cache(md5($account_id['account_id'] . 'account_settings'), $this->cache_time)->find();
				$request                 = new GatewayProtocols();
				$request->cmd            = GatewayProtocols::CMD_SEND_TO_UID;
				$request->from_uid       = $account_id['account_id'];
				$request->from_user_type = 2;
				$request->to_uid         = $uid;
				$request->to_user_type   = 1;
				$request->data           = ['content' => $account_setting['auto_reply'], 'content_type' => MessageSendProtocols::CONTENT_TYPE_AUTO_REPLY];
				$request->fd             = $fd;
				return $this->process->write($request->encode());
			}
		});
	}
	
	/**
	 * 发送自动回复菜单给用户
	 * @param swoole_websocket_server $serv
	 * @param                         $link
	 * @param                         $fd
	 * @param                         $uid
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function send_menu_to_user(swoole_websocket_server $serv, $link, $fd, $uid)
	{
		//是否开启了回复菜单功能
		$account_id = $this->get_account_by_link($link);
		
		$account_setting = Db::name('account_settings')->where('account_id', $account_id)->field('menu_title,auto_reply_open')
			->cache(md5($account_id['account_id'] . 'account_settings'), $this->cache_time)->find();
		
		if ($account_setting) {
			$data = Db::name('account_auto_menu')
				->where('account_id', $account_id)
				->cache(true, $this->cache_time)
				->field('question,auto_menu_id,sort')
				->order('sort desc')
				->select();
			
			$content = ['setting' => $account_setting, 'data' => $data];
			return $this->result($serv, $fd, $content, 0, MessageSendProtocols::CMD_MENU);
		}
	}
	
	/**
	 * 商家的自动回复菜单答案
	 * @param $auto_menu_id
	 * @param $fd
	 * @param $uid
	 */
	private function send_account_auto_menu_reply($auto_menu_id, $fd, $uid)
	{
		$chan_auto_menu = new \Chan(1);
		go(function () use ($auto_menu_id, $chan_auto_menu) {
			$data = Db::name('account_auto_menu')->where('auto_menu_id', $auto_menu_id)
				->field('account_id,answer')
				->cache(md5('auto_menu_id' . $auto_menu_id), $this->cache_time)
				->find();
			if ($data) {
				$chan_auto_menu->push($data);
			}
		});
		
		go(function () use ($uid, $chan_auto_menu, $fd) {
			if ($auto_menu = $chan_auto_menu->pop(100)) {
				
				$request                 = new GatewayProtocols();
				$request->cmd            = GatewayProtocols::CMD_SEND_TO_UID;
				$request->from_uid       = $auto_menu['account_id'];
				$request->from_user_type = 2;
				$request->to_uid         = $uid;
				$request->to_user_type   = 1;
				$request->data           = [
					'content'      => $auto_menu['answer'],
					'content_type' => MessageSendProtocols::CONTENT_TYPE_AUTO_MENU_REPLY
				];
				$request->fd             = $fd;
				return $this->process->write($request->encode());
			}
		});
	}
	
	/**
	 * 绑定商家
	 * @param $uid
	 * @param $link
	 */
	private function bind_account($uid, $link)
	{
		$chan_router = new \Chan(1);
		go(function () use ($uid, $link, $chan_router) {
			$router = Db::name('router')->where('short_url', $link)->field('account_id')
				->cache(true, $this->cache_time)->find();
			$chan_router->push($router);
		});
		
		go(function () use ($uid, $link, $chan_router) {
			$router = $chan_router->pop(1);
			$chan_router->push($router);
			$exis_relation = Db::name('account_user_relation')->where('account_id', $router['account_id'])
				->where('user_id', $uid)
				->field('account_id')
				->find();
			if (!$exis_relation) {//不存在好友关系
				Db::name('account_user_relation')->insert(
					[
						'account_id'  => $router['account_id'],
						'user_id'     => $uid,
						'create_time' => time(),
					]
				);
				Cache::clear($this->get_relation_cache_tag($router['account_id'], $uid));
			}
		});
		
		go(function () use ($uid, $link, $chan_router) {
			$router            = $chan_router->pop(1);
			$exis_message_list = Db::name('account_message_list')->where('account_id', $router['account_id'])
				->where('user_id', $uid)
				->field('account_id')->find();
			if (!$exis_message_list) {//商家消息列表不存在
				Db::name('account_message_list')->insert(
					[
						'account_id'  => $router['account_id'],
						'user_id'     => $uid,
						'create_time' => time(),
						'update_time' => time(),
					]
				);
				Cache::clear($this->get_relation_cache_tag($router['account_id'], $uid));
			}
		});
	}
	
	/**
	 * 链接绑定uid
	 * @param $fd
	 * @param $uid
	 * @return int
	 */
	protected function bindID(swoole_websocket_server $serv, $fd, $uid, $user_type = GatewayProtocols::TYPE_USER_ACCOUNT)
	{
		$req_data       = new GatewayProtocols();
		$req_data->cmd  = GatewayProtocols::CMD_REGISTER_USER;
		$req_data->fd   = $fd;
		$req_data->data = $user_type . '_' . $uid;
		$req_data->key  = $this->serv_key;
		$this->localBindUID($serv, $fd, $uid, $user_type);
		return $this->process->write($req_data->encode());
	}
	
	/**
	 * 用户close时解绑此fd
	 * @param swoole_websocket_server $serv
	 * @param                         $fd
	 * @param                         $uid
	 * @param int                     $user_type
	 * @return int
	 */
	protected function unbindID(swoole_websocket_server $serv, $fd, $uid, $user_type = GatewayProtocols::TYPE_USER_ACCOUNT)
	{
		$req_data       = new GatewayProtocols();
		$req_data->cmd  = GatewayProtocols::CMD_UNREGISTER_USER;
		$req_data->fd   = $fd;
		$req_data->data = $user_type . '_' . $uid;
		$req_data->key  = $this->serv_key;
		return $this->process->write($req_data->encode());
	}
	
	/**
	 * 本地将fd与uid绑定
	 * @param swoole_websocket_server $serv
	 * @param                         $fd
	 * @param                         $uid
	 * @return bool
	 */
	protected function localBindUID(swoole_websocket_server $serv, $fd, $id, $type)
	{
		$uid = (int)"$type" . $id;
		return $serv->bind($fd, $uid);//当前绑定一个uid
	}
	
	/**
	 * 获取绑定的UID
	 * @param swoole_websocket_server $serv
	 * @param                         $fd
	 * @return string
	 */
	protected function getLocalBindUID(swoole_websocket_server $serv, $fd)
	{
		$clientInfo = $serv->getClientInfo($fd);
		$uid        = $clientInfo['uid'];
		$type       = substr($uid, 0, 1);
		return $type . '_' . substr($uid, 1, strlen($uid) - 1);
	}
	
	/**
	 * @param swoole_server $serv
	 * @param               $fd
	 * @return
	 */
	public
	function onConnect(swoole_server $serv, $fd)
	{
		$this->console->success('connect' . $fd);
		$cli_info    = $serv->getClientInfo($fd);
		$remote_ip   = $cli_info['remote_ip'];
		$remote_port = $cli_info['remote_port'];
		$cache       = $this->redis->get(md5('swoole_limit' . $remote_ip));
		if ($cache && $cache >= 5) {//一个ip一秒钟只能调用5次onconnect
			if ($cache >= 5) {
				return $serv->disconnect($fd, 1002, 'limit');
			} else {
				$this->redis->set(md5('swoole_limit' . $remote_ip), ++$cache, 1);//一个ip一秒钟只能调用5次onconnect
			}
		} else {
			$this->redis->set(md5('swoole_limit' . $remote_ip), 1, 1);//一个ip一秒钟只能调用5次onconnect
			$serv->confirm($fd);
		}
	}
	
	/**
	 * SIGTREM信号关闭事件
	 * @param swoole_server $server
	 */
	public
	function onShutdown(swoole_server $server)
	{
		$this->process->write('exit');
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
	public
	function onStart(swoole_server $serv)
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
	public
	function onWorkerStart(\swoole_server $serv, $worker_id)
	{
		RedisConnectPool::getInstance()->init();
		if ($serv->taskworker === true) {// 表示当前的进程是Task工作进程
		
		} else {//表示当前的进程是Worker进程
		
		}
	}
	
	/**
	 *维持子进程的心跳
	 */
	private
	function holdPing()
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
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function onPipeMessage(swoole_websocket_server $serv, $src_worker_id, $data)
	{
		$data = (new GatewayProtocols())->decode($data);
		if ($data->cmd == GatewayProtocols::CMD_GATEWAY_PUSH) {//单个消息推送任务
			$fd = $data->fd;
			if ($serv->isEstablished($fd)) {
				$messageSend                 = new MessageSendProtocols();
				$messageSend->cmd            = MessageSendProtocols::CMD_SEND_MESSAGE;//发送消息
				$messageSend->to_user_type   = $data->to_user_type;
				$messageSend->to_uid         = $data->to_uid;
				$messageSend->from_uid       = $data->from_uid;
				$messageSend->from_user_type = $data->from_user_type;
				$messageSend->data           = $data->data;
				$messageSend->message_id     = $data->message_id;
				$messageSend->from_name      = $data->extra;
				$messageSend->time           = time();
				if ($data->from_user_type == '1') {
					$relation                 = Db::name('account_user_relation')->where('account_id', $data->to_uid)
						->where('user_id', $data->from_uid)
						->field('need_notice')
						->cache(true, $this->cache_time, $this->get_relation_cache_tag($data->to_uid, $data->from_uid))
						->find();
					$messageSend->need_notice = $relation ? $relation['need_notice'] : 1;
				}
				
				return $serv->push($fd, $messageSend->encode());//向fd发消息
			} else {
				return $this->unbindID($serv, $fd, $data->to_uid, $data->to_user_type);
			}
		} else {
			$this->console->error('无效消息');
		}
	}
	
	/**
	 * 获取account_id
	 * @param $link
	 * @return bool|mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function get_account_by_link($link)
	{
		$router = Db::name('router')->field('account_id')->where('short_url', $link)->cache(true, $this->cache_time)->find();
		return $router ? $router['account_id'] : false;
	}
	
	/**
	 * 解析本地uid
	 * @param $uid
	 * @return array
	 */
	private
	function explore_local_uid($uid)
	{
		$uid_array = explode('_', $uid);//2_16
		return ['user_type' => $uid_array[0], 'user_id' => $uid_array[1]];
	}
	
	/**
	 * @param swoole_websocket_server $serv
	 * @param \swoole_websocket_frame $frame
	 * @return bool|void
	 * @throws \Exception
	 */
	public
	function onMessage(swoole_websocket_server $serv, \swoole_websocket_frame $frame)
	{
		if ($frame->opcode == 0x08) {//客户端发送关闭帧
			$code   = $frame->code;
			$reason = $frame->reason;
			$serv->close($frame->fd);
		}
		$fd   = $frame->fd;
		$data = json_decode($frame->data, true);
		$uid  = $this->getLocalBindUID($serv, $fd);
		if ($frame->data == 'ping') {
			$user_id = $this->explore_local_uid($uid)['user_id'];
			$this->redis->set($this->auth->get_ping_key($user_id), time(), 15);
			return $serv->push($fd, 'pong');
		} else {
			switch ($data['cmd']) {
				case 'send'://{"cmd":"send","to_uid":"Tkzljq","to_user_type":"2","content_type":"1","content":"666"}
					if (!in_array($data['content_type'], [MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CONTENT_TYPE_IMAGES])) {
						return $this->result($serv, $fd, 'content_type错误', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'error');
					}
					if (!is_array($data['content']) && !trim($data['content'])) {//不可以发空消息
						return false;
					}
					if (mb_strlen($data['content']) > 500) {
						return $this->result($serv, $fd, '最多500个字符', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'error');
					}
					$to_uid       = $data['to_uid'];
					$to_user_type = $data['to_user_type'];
					$content      = [
						'content'      => $data['content'],
						'content_type' => $data['content_type'],
					];
					if ($to_user_type == '2') {
						//将店铺链接转为account_id
						$to_uid = $this->get_account_by_link($to_uid);
						if (!$to_uid) {
							return $this->result($serv, $fd, '接收方错误', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'uid_error');
						}
					}
					
					//					if ($user_id == $to_uid && $utype == $to_user_type) {
					//						//自己发给自己
					//						return $this->result($this->ser)
					//		}
					return $this->sendToUID($uid, $to_uid, $to_user_type, $content, $fd);
					break;
				case 'list_item'://商家收到消息但是列表项已被删除的情况，需要调这个接口拉列表项数据{"cmd":"list_item","uid":"1"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2') {
						return false;
					}
					$message_list = Db::name('account_message_list')->where('account_id', $user['user_id'])
						->where('user_id', $data['uid'])
						->field('account_id,user_id,last_message_id,message_list_id,update_time')
						->cache(true, $this->cache_time)
						->find();
					if ($message_list) {
						$user_relation = Db::name('account_user_relation')->field('user_remark,need_notice')->where('account_id', $user['user_id'])
							->where('user_id', $data['uid'])->cache(true, $this->cache_time, $this->get_relation_cache_tag($user['user_id'], $data['uid']))->find();
						$user          = Db::name('user')->field('nickname')->where('user_id', $data['uid'])->cache(true, $this->cache_time)->find();
						$last_message  = Db::name('user_message')->field('content,from_uid,content_type')
							->where('message_id', $message_list['last_message_id'])->cache(true, $this->cache_time)->find();
						
						$message_list['name']    = $user['nickname'];
						$message_list['message'] = $message_list['last_message_id'] ? $last_message : new \stdClass();
						
						return $this->result($serv, $fd, $message_list, 0, MessageSendProtocols::CMD_LIST_ITEM);
					}
					
					break;
				case 'user_info'://{"cmd":"user_info","uid":"Tkzljq"}
					$mine  = [];//自己的信息
					$their = [];
					$uid   = $this->explore_local_uid($uid);
					if ($uid['user_type'] == '2') {
						//本机的用户类型为商家
						$mine                 = Db::name('account')->field('show_name,head_img_url')->where('account_id', $uid['user_id'])
							->cache(true, $this->cache_time)->find();
						$user                 = Db::name('user')->where('user_id', $data['uid'])->field('nickname,phone,user_id')->find();
						$relation             = Db::name('account_user_relation')->where('account_id', $uid['user_id'])
							->field('user_remark,need_notice')
							->where('user_id', $data['uid'])
							->cache(true, $this->cache_time, $this->get_relation_cache_tag($uid['user_id'], $data['uid']))
							->find();
						$their['user_remark'] = $relation['user_remark'];
						$their['nickname']    = $user['nickname'];
						$their['need_notice'] = $relation['need_notice'];
						$their['phone']       = mb_substr($user['phone'], 0, 3) . '******' . mb_substr($user['phone'], -3);
						$their['user_id']     = $user['user_id'];
					} else {
						//本机的用户类型为买家
						$mine = Db::name('user')->where('user_id', $uid['user_id'])->field('nickname,phone,user_id')->find();
						
						$router  = Db::name('router')->field('show_name,account_id,short_url')->where('short_url', $data['uid'])
							->cache(true, $this->cache_time)->find();
						$account = Db::name('account')->field('show_name,head_img_url')->where('account_id', $router['account_id'])
							->cache(true, $this->cache_time)->find();
						
						$account['show_name']  = $account['show_name'] ?: $data['uid'];
						$their['show_name']    = $router['show_name'] ?: $account['show_name'];
						$their['head_img_url'] = $account['head_img_url'];
					}
					
					return $this->result($serv, $fd, ['mine' => $mine, 'their' => $their], 0, MessageSendProtocols::CMD_USERINFO);
					
					break;
				case 'message_list'://{"cmd":"message_list","page":"1","uid":"Tkzljq"}
					$page      = $data['page'];
					$uid_array = explode('_', $uid);//2_16
					$utype     = $uid_array[0];//用户类型
					$user_id   = $uid_array[1];
					
					$search_uid = $data['uid'];
					
					if ($utype == '1') {
						//将店铺链接转为account_id
						$search_uid = $this->get_account_by_link($search_uid);
						if (!$search_uid) {
							return $this->result($serv, $fd, 'uid错误', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'uid_error');
						}
						$content_type = [1, 2, 3];//买家要显示3
					} else {
						$content_type = [1, 2];
					}
					
					$where                   = new Where();//他发的
					$where['from_uid']       = $user_id;
					$where['from_user_type'] = $utype;
					$where['to_uid']         = $search_uid;
					$where['to_user_type']   = $utype == '1' ? '2' : '1';
					
					$whereOr                   = new Where();//他接收的
					$whereOr['to_uid']         = $user_id;
					$whereOr['to_user_type']   = $utype;
					$whereOr['from_uid']       = $search_uid;
					$whereOr['from_user_type'] = $utype == '1' ? '2' : '1';
					
					$chan_message = new \Chan(1);
					go(function () use ($where, $whereOr, $page, $chan_message, $content_type) {
						$message = Db::name('user_message')
							->where($where->enclose())
							->whereOr($whereOr->enclose())
							->where('content_type', 'in', $content_type)
							->field('from_uid,from_user_type,to_uid,to_user_type,content,content_type,create_time')
							->order('create_time desc')
							->paginate(20, false, ['page' => $page]);
						$chan_message->push($message);
					});
					
					go(function () use ($where, $whereOr, $page, $chan_message, $serv, $search_uid, $fd) {
						$message          = $chan_message->pop(100);
						$account_head_img = Db::name('account')->where('account_id', $search_uid)->field('head_img_url')
							->cache(true, 600)->find();
						
						return $this->result($serv, $fd, $message->toArray(), 0, MessageSendProtocols::CMD_MESSAGE_LIST, 'success', $account_head_img['head_img_url']);
					});
					
					break;
				case 'set_user'://商家设置用户{"cmd":"set_user","uid":"1","need_notice":"1","user_remark":"test"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2') {
						return false;
					}
					$update = [];
					if (isset($data['need_notice']) && in_array($data['need_notice'], ['1', '0'])) {
						$update['need_notice'] = $data['need_notice'];
					}
					if (isset($data['user_remark'])) {
						$update['user_remark'] = $data['user_remark'];
					}
					if (count($update) > 0) {
						Cache::clear($this->get_relation_cache_tag($user['user_id'], $data['uid']));
						Db::name('account_user_relation')->where('account_id', $user['user_id'])
							->where('user_id', $data['uid'])->update($update);
					}
					
					break;
				case 'delete_list_item'://{"cmd":"delete_list_item","message_list_id":"1"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2') {
						return false;
					}
					$message_list_id = $data['message_list_id'];
					Db::name('account_message_list')
						->where('account_id', $user['user_id'])
						->where('message_list_id', $message_list_id)
						->update(['is_delete' => 1]);
					break;
				case 'read_msg'://{"cmd":"read_msg","message_id":"1"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2' || !isset($data['message_id']) || !check_num($data['message_id'])) {
						return false;
					}
					$msg_id = $data['message_id'];
					$this->redis->sAdd($this->auth->get_readed_list_key(), $user['user_id'] . '_' . $msg_id);
					break;
				case 'add_common_message'://{"cmd":"add_common_message","content":"666"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2' || !isset($data['content']) || mb_strlen($data['content']) > 200) {
						return false;
					}
					$count = Db::name('account_common_message')
						->cache(true, $this->cache_time, $this->get_common_message_cache_tag($user['user_id']))
						->count('common_message_id');
					if ($count >= 50) {
						return false;
					}
					$last   = Db::name('account_common_message')->field('sort')
						->where('account_id', $user['user_id'])
						->cache(true, $this->cache_time, $this->get_common_message_cache_tag($user['user_id']))
						->order('sort desc')->order('update_time desc')->find();
					$insert = [
						'content'     => $data['content'],
						'account_id'  => $user['user_id'],
						'update_time' => time(),
						'sort'        => $last ? $last['sort'] + 5000 : 5000,
					];
					Db::name('account_common_message')->insert($insert);
					Cache::clear($this->get_common_message_cache_tag($user['user_id']));
					break;
				case 'common_message_list'://{"cmd":"common_message_list"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2') {
						return false;
					}
					$data = Db::name('account_common_message')->field('common_message_id,account_id,sort,update_time,content')
						->where('account_id', $user['user_id'])
						->cache(true, $this->cache_time, $this->get_common_message_cache_tag($user['user_id']))
						->order('sort desc')->order('update_time desc')
						->select();
					return $this->result($serv, $fd, $data, 0, MessageSendProtocols::CMD_COMMON_MESSAGE_LIST);
					break;
				case 'edit_common_message'://{"cmd":"edit_common_message","content":"777","sort":"1000","common_message_id":"10"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2' || !isset($data['common_message_id']) || !check_num($data['common_message_id'])) {
						return false;
					}
					$update['update_time'] = time();
					if (isset($data['content'])) {
						$update['content'] = $data['content'];
					}
					
					if (isset($data['sort']) && check_num($data['sort'])) {
						$update['sort'] = $data['sort'];
					}
					Db::name('account_common_message')->where('common_message_id', $data['common_message_id'])
						->where('account_id', $user['user_id'])
						->update($update);
					Cache::clear($this->get_common_message_cache_tag($user['user_id']));
					break;
				
				case 'del_common_message'://{"cmd":"del_common_message","common_message_id":"10"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '2' || !isset($data['common_message_id']) || !check_num($data['common_message_id'])) {
						return false;
					}
					Db::name('account_common_message')->where('common_message_id', $data['common_message_id'])
						->where('account_id', $user['user_id'])->delete();
					Cache::clear($this->get_common_message_cache_tag($user['user_id']));
					break;
				case 'get_answer'://{"cmd":"get_answer","auto_menu_id":"1"}
					$user = $this->explore_local_uid($uid);
					if ($user['user_type'] <> '1' || !isset($data['auto_menu_id']) || !check_num($data['auto_menu_id'])) {
						return false;
					}
					return $this->send_account_auto_menu_reply($data['auto_menu_id'], $fd, $user['user_id']);
					break;
			}
		}
	}
	
	/**
	 * 链接关闭
	 * @param swoole_server $serv
	 * @param               $fd
	 * @return int
	 */
	public function onClose(swoole_server $serv, $fd)
	{
		$this->console->error('close' . $fd);
		$user = $this->getLocalBindUID($serv, $fd);
		$user = $this->explore_local_uid($user);
		return $this->unbindID($serv, $fd, $user['user_id'], $user['user_type']);
	}
	
	/**
	 * 发送返回消息
	 * @param swoole_server $serv 服务
	 * @param               $fd         接受者fd
	 * @param               $content    消息正文
	 * @param int           $cmd
	 * @param string        $msg 提示信息
	 * @return bool
	 */
	protected function result(swoole_server $serv, $fd, $content, $content_type = MessageSendProtocols::CONTENT_TYPE_TEXT, $cmd = MessageSendProtocols::CMD_TIPS, $msg = 'success', $extra = '')
	{
		if (!is_array($content) && !trim($content)) {
			return false;
		}
		$response        = new MessageSendProtocols();
		$response->cmd   = $cmd;
		$response->data  = is_array($content) ? $content : ['content' => $content, 'content_type' => $content_type];
		$response->msg   = $msg;
		$response->extra = $extra;
		$response->time  = time();
		
		if ($serv->isEstablished($fd)) {
			return $serv->push($fd, $response->encode());
		}
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
						
						case GatewayProtocols::CMD_PROCESS_DISCONNECT:
							$this->console->error('网关断开连接');
							break;
						
						case GatewayProtocols::CMD_ON_CONNECT_GATEWAY://网关连接成功
							$this->initServKey();//找网关注册
							break;
						
						case GatewayProtocols::CMD_TEST_FD_ONLINE://检测连接是否在线
							$fd            = $data->fd;
							$response      = new GatewayProtocols();
							$response->cmd = GatewayProtocols::CMD_TEST_FD_ONLINE;
							if ($serv->isEstablished($fd)) {
								$response->data = 1;
							} else {
								$response->data = 1;
							}
							return $process->write($response->encode());
							break;
						default:
							//其他类型消息一律分配worker处理
							$serv->sendMessage($data->encode(), mt_rand(1, 3));
							break;
					}
				} catch (\Exception $e) {
					$this->console->error($e->getMessage());
				}
			});
	}
	
	/**
	 * 给用户发消息
	 * @param $from_uid
	 * @param $to_uid
	 * @param $to_user_type
	 * @param $content
	 * @param $fd
	 * @return void
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	protected function sendToUID($from_uid, $to_uid, $to_user_type, $content, $fd)
	{
		$uid_array = explode('_', $from_uid);//2_16
		$utype     = $uid_array[0];//用户类型
		$user_id   = $uid_array[1];
		
		$request                 = new GatewayProtocols();
		$request->cmd            = GatewayProtocols::CMD_SEND_TO_UID;
		$request->from_uid       = $user_id;
		$request->from_user_type = $utype;
		$request->to_uid         = $to_uid;
		$request->to_user_type   = $to_user_type;
		$request->data           = $content;
		$request->fd             = $fd;
		
		if ($utype == '1') {
			$request->extra = $this->get_username($user_id, $to_uid);
		}
		if (mb_strlen($content['content']) < 1) {
			return false;
		}
		$chan_id = new \Chan(1);
		go(function () use ($content, $user_id, $utype, $to_uid, $to_user_type, $chan_id) {
			$insertId = Db::name('user_message')->insertGetId(
				[
					'content'        => $content['content'],
					'content_type'   => $content['content_type'],
					'from_uid'       => $user_id,
					'from_user_type' => $utype,
					'create_time'    => time(),
					'to_uid'         => $to_uid,
					'to_user_type'   => $to_user_type,
				]);
			if ($insertId) {
				$chan_id->push($insertId);
			}
		});
		$message_chan = new \Chan(1);
		go(function () use ($request, $chan_id, $message_chan) {
			if ($msg_id = $chan_id->pop(100)) {
				$chan_id->push($msg_id);
				$request->message_id = $msg_id;
				$message_chan->push($request);
				$this->process->write($request->encode());
			}
		});
		
		go(function () use ($utype, $user_id, $to_uid, $to_user_type, $chan_id) {
			if ($msg_id = $chan_id->pop(100)) {
				$chan_id->push($msg_id);
				$account_id            = $utype == 2 ? $user_id : $to_uid;
				$user_id               = $to_user_type == 1 ? $to_uid : $user_id;
				$update['update_time'] = time();
				if ($utype == '1') {//买家发的
					$update['last_message_id'] = $msg_id;
				}
				$update['is_delete'] = 0;
				Db::name('account_message_list')->where('account_id', $account_id)->where('user_id', $user_id)->update($update);
				$chan_id->close();
			}
		});
		
		go(function () use ($to_user_type, $message_chan, $content) {
			if ($to_user_type == '2'
				&& $content['content_type'] <> MessageSendProtocols::CONTENT_TYPE_AUTO_REPLY
				&& $content['content_type'] <> MessageSendProtocols::CONTENT_TYPE_AUTO_MENU_REPLY
			) {
				//发给商家,排除自动回复消息
				if ($msg = $message_chan->pop(100)) {
					$this->redis->sAdd($this->auth->get_unread_list_key(), $msg->to_uid . '_' . $msg->message_id);
					$this->redis->set($this->auth->get_msg_detail_key($msg->message_id), $msg->encode());
					$message_chan->close();
				}
			}
		});
	}
	
	/**
	 * 获取用户的备注或昵称
	 * @param $uid
	 * @param $account_id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function get_username($uid, $account_id)
	{
		$data = Db::name('account_user_relation')->where('account_id', $account_id)
			->where('user_id', $uid)
			->field('user_remark')
			->cache(true, $this->cache_time, $this->get_relation_cache_tag($account_id, $uid))
			->find();
		if ($data && $data['user_remark']) {
			return $data['user_remark'];
		} else {
			$user = Db::name('user')->field('nickname')->where('user_id', $uid)
				->cache(true, $this->cache_time, $this->get_relation_cache_tag($account_id, $uid))
				->find();
			return $user['nickname'];
		}
	}
	
	/**
	 * 商家与用户relation的缓存tag
	 * @param $account_id
	 * @param $user_id
	 * @return string
	 */
	private function get_relation_cache_tag($account_id, $user_id)
	{
		return md5($account_id . "_" . $user_id);
	}
	
	/**
	 * get_common_message_cache_tag
	 * @param $account_id
	 * @param $user_id
	 * @return string
	 */
	private function get_common_message_cache_tag($account_id)
	{
		return md5('get_common_message_cache_tag' . $account_id);
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
}