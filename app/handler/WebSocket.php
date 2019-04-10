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
			$this->result($serv, $request->fd, '缺少token或type', 'text', 'token_invalid', 'tips');
			return $serv->close($request->fd);
		}
		if ($_GET['type'] == 'account' && !isset($_GET['authtype'])) {
			$this->result($serv, $request->fd, '缺少authtype', 'text', 'token_invalid', 'tips');
			return $serv->close($request->fd);
		}
		
		if ($uid = $this->auth->validateToken($_GET['token'], $_GET['type'], $_GET['authtype'])) {
			//验证通过,在网关绑定uid
			$this->bindID($serv, $request->fd, $uid, $_GET['type'] == 'user' ? GatewayProtocols::TYPE_USER_U : GatewayProtocols::TYPE_USER_ACCOUNT);
			return $this->result($serv, $request->fd, $uid, 'text', 'login_success', 'tips');
		} else {
			$this->result($serv, $request->fd, 'token无效', 'text', 'token_invalid', 'tips');
			co::sleep(100);
			return $serv->close($request->fd);
		}
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
		$req_data->data = $user_type . $uid;
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
		if ($type === GatewayProtocols::TYPE_USER_ACCOUNT) {
			$uid = (int)('1' . $id);
		} else {
			$uid = (int)('2' . $id);
		}
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
		if ($type == 1) {
			//account
			return 'A' . substr($uid, 1, strlen($uid) - 1);
		} else {
			//user
			return 'U' . substr($uid, 1, strlen($uid) - 1);
		}
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
		RedisConnectPool::getInstance()->init();
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
								'type'     => GatewayProtocols::CMD_GATEWAY_PUSH,
								'fd'       => $data->fd,
								'data'     => $data->data,
								'from_uid' => $data->extra,
							];
							$this->console->error(date('Y-m-d H:i:s', time()) . '>>>>4服务worker_id' . $serv->worker_id . '收到网关的消息推送任务准备写入管道');
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
		$data = json_decode($data, true);
		if ($data['type'] == GatewayProtocols::CMD_GATEWAY_PUSH) {//单个消息
			$fd = $data['fd'];
			if ($serv->exist($fd)) {
				$content = json_decode($data['data'], true);
				$fd      = $data['fd'];
				return $serv->push($fd, json_encode($content));//向fd发消息
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
		$data = json_decode($frame->data, true);
		$uid  = $this->getLocalBindUID($serv, $fd);
		if ($frame->data == 'ping') {
			return $serv->push($fd, 'pong');
		} else {
			switch ($data['cmd']) {
				case 'send'://{"cmd":"send","to_uid":"A16","data":{"content_type":"text","content":"666"}}
					$content = $data['data'];
					$to_uid  = $data['to_uid'];
					if (!in_array(substr($to_uid, 0, 1), ['A', 'U'])) {
						return $this->result($serv,$fd,'接受者消息格式不正确','text','error','tips');
					}
					$this->sendToUID($uid, $to_uid, $content);
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
	 * 给用户发消息
	 * @param $from_uid
	 * @param $to_uid
	 * @param $content
	 */
	protected function sendToUID($from_uid, $to_uid, $content)
	{
		$request        = new GatewayProtocols();
		$request->cmd   = GatewayProtocols::CMD_SEND_TO_UID;
		$request->key   = $from_uid;
		$request->extra = $to_uid;
		$request->data  = json_encode($content);
		$this->process->write($request->encode());
	}
}