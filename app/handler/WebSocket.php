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
use swoole_http_request;
use swoole_process;
use swoole_server;
use swoole_websocket_server;
use think\Db;
use think\db\Where;

class WebSocket extends MessageHandler
{
	protected $redis;
	
	protected $process;
	
	public    $serv_key        = 0;//服务在网关的key
	
	protected $console;
	
	protected $auth;
	
	protected $table;
	
	protected $user;
	
	protected $users;
	
	protected $_uidConnections = [];//uid映射链接
	
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
				$this->bind_account($uid, $_GET['link']);
			}
			return $this->result($serv, $request->fd, '验证通过', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'login_success');
		} else {
			$this->result($serv, $request->fd, 'token无效', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'token_invalid');
			co::sleep(100);
			return $serv->disconnect($request->fd, 1001, 'token_invalid');
		}
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
			$router = Db::name('router')->where('short_url', $link)->field('account_id')->cache(true, 600)->find();
			$chan_router->push($router);
		});
		
		go(function () use ($uid, $link, $chan_router) {
			$router = $chan_router->pop(1);
			$chan_router->push($router);
			$exis_relation = Db::name('account_user_relation')->where('account_id', $router['account_id'])
				->where('user_id', $uid)
				->field('account_id')->find();
			if (!$exis_relation) {//不存在好友关系
				Db::name('account_user_relation')->insert(
					[
						'account_id'  => $router['account_id'],
						'user_id'     => $uid,
						'create_time' => time(),
					]
				);
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
	public function onConnect(swoole_server $serv, $fd)
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
		
		if (!$this->table->set($fd, ['ukey' => $this->auth->getUkey($fd)])) {
			$serv->disconnect($fd, 1003, 'sys_error');
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
		co::sleep(2);
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
		RedisConnectPool::getInstance()->init();
		if ($serv->taskworker === true) {// 表示当前的进程是Task工作进程
		
		} else {//表示当前的进程是Worker进程
		
		}
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
				$messageSend->time           = time();
				
				return $serv->push($fd, $messageSend->encode());//向fd发消息
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
		$router = Db::name('router')->field('account_id')->where('short_url', $link)->cache(true, 600)->find();
		return $router ? $router['account_id'] : false;
	}
	
	/**
	 * 解析本地uid
	 * @param $uid
	 * @return array
	 */
	private function explore_local_uid($uid)
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
	public function onMessage(swoole_websocket_server $serv, \swoole_websocket_frame $frame)
	{
		$fd   = $frame->fd;
		$data = json_decode($frame->data, true);
		$uid  = $this->getLocalBindUID($serv, $fd);
		if ($frame->data == 'ping') {
			return $serv->push($fd, 'pong');
		} else {
			switch ($data['cmd']) {
				case 'send'://{"cmd":"send","to_uid":"Tkzljq","to_user_type":"2","content_type":"1","content":"666"}
					if (!in_array($data['content_type'], [MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CONTENT_TYPE_IMAGES])) {
						return $this->result($serv, $fd, 'content_type错误', MessageSendProtocols::CONTENT_TYPE_TEXT, MessageSendProtocols::CMD_TIPS, 'error');
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
				case 'user_info'://{"cmd":"user_info","uid":"Tkzljq"}
					$mine  = [];//自己的信息
					$their = [];
					$uid   = $this->explore_local_uid($uid);
					if ($uid['user_type'] == '2') {
						//本机的用户类型为商家
						$mine                 = Db::name('account')->field('show_name,head_img_url')->where('account_id', $uid['user_id'])
							->cache(true, 600)->find();
						$user                 = Db::name('user')->where('user_id', $data['uid'])->field('nickname,phone,user_id')->find();
						$relation             = Db::name('account_user_relation')->where('account_id', $uid['user_id'])
							->field('user_remark,need_notice')
							->where('user_id', $data['uid'])
							->cache(true, 600)
							->find();
						$their['name']        = $relation['user_remark'] ?: $user['nickname'];
						$their['need_notice'] = $user['need_notice'];
						$their['phone']       = substr($user['phone'], 1, 3) . '***';
						$their['user_id']     = $user['user_id'];
					} else {
						//本机的用户类型为买家
						$mine = Db::name('user')->where('user_id', $uid['user_id'])->field('nickname,phone,user_id')->find();
						
						$router                = Db::name('router')->field('show_name,account_id,short_url')->where('short_url', $data['uid'])
							->cache(true, 600)->find();
						$account               = Db::name('account')->field('show_name,head_img_url')->where('account_id', $router['account_id'])
							->cache(true, 600)->find();
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
					}
					
					$where                   = new Where();//他发的
					$where['from_uid']       = $user_id;
					$where['from_user_type'] = $utype;
					$where['to_uid']         = $search_uid;
					$where['to_user_type']   = $utype == 1 ? 2 : 1;
					
					$whereOr                   = new Where();//他接收的
					$whereOr['to_uid']         = $user_id;
					$whereOr['to_user_type']   = $utype;
					$whereOr['from_uid']       = $search_uid;
					$whereOr['from_user_type'] = $utype == 1 ? 2 : 1;
					
					$chan_message = new \Chan(1);
					go(function () use ($where, $whereOr, $page, $chan_message) {
						$message = Db::name('user_message')->where($where)->whereOr($whereOr)
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
	 * @param $content
	 * @return bool
	 */
	protected function sendToUID($from_uid, $to_uid, $to_user_type, $content, $fd)
	{
		if (!is_array($content) && !trim($content)) {//不可以发空消息
			return false;
		}
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
		
		$this->process->write($request->encode());
		
		go(function () use ($content, $user_id, $utype, $to_uid, $to_user_type) {
			Db::name('user_message')->insert(
				[
					'content'        => $content['content'],
					'content_type'   => $content['content_type'],
					'from_uid'       => $user_id,
					'from_user_type' => $utype,
					'create_time'    => time(),
					'to_uid'         => $to_uid,
					'to_user_type'   => $to_user_type,
				]);
		});
		go(function () use ($utype, $user_id, $to_uid, $to_user_type) {
			$account_id = $utype == 2 ? $user_id : $to_uid;
			$user_id    = $to_user_type == 1 ? $to_uid : $user_id;
			Db::name('account_message_list')->where('account_id', $account_id)->where('user_id', $user_id)->update(['update_time' => time()]);
		});
	}
}