<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/4/18
 * Time: 13:44
 */

namespace app\task;

use app\handler\WebSocket;
use app\protocols\MessageSendProtocols;
use im\core\redis\Redis;
use im\core\service\protocols\GatewayProtocols;
use Swoole\Process;
use swoole_websocket_server;
use think\Db;

/**
 * 用于处理onTask
 * Class TaskEvent
 * @package app\task
 */
class TaskEvent
{
	public $serv;
	
	public $task;
	
	public $redis;
	
	public $process;
	
	public $event;
	
	/**
	 * @var WebSocket
	 */
	public $msgHandler;
	
	public function __construct(swoole_websocket_server $serv, $task, Redis $redis, Process $process)
	{
		$this->serv    = $serv;
		$this->task    = $task;
		$this->redis   = $redis;
		$this->process = $process;
	}
	
	public function run()
	{
		$this->event = $this->task->data['event'];
		return call_user_func_array([$this, $this->event], $this->task->data['args']);
	}
	
	/**
	 * 商家自动回复内容
	 * @param                         $link
	 * @param                         $fd
	 * @param                         $uid
	 * @return int
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function send_account_auto_reply($link, $fd, $uid)
	{
		$account_id      = Db::name('router')->where('short_url', $link)->field('account_id')
			->cache(true, $this->msgHandler->cache_time)->find();
		$account_setting = Db::name('account_settings')->where('account_id', $account_id['account_id'])->field('auto_reply')
			->where('auto_reply_open', 1)
			->cache(md5($account_id['account_id'] . 'account_settings'), $this->msgHandler->cache_time)->find();
		
		if (trim($account_setting['auto_reply']) <> '') {//空数据不发送
			$response                 = new MessageSendProtocols();
			$response->cmd            = MessageSendProtocols::CMD_SEND_MESSAGE;
			$response->from_uid       = $account_id['account_id'];
			$response->from_user_type = 2;
			$response->to_uid         = $uid;
			$response->to_user_type   = 1;
			$response->data           = ['content' => trim($account_setting['auto_reply']), 'content_type' => MessageSendProtocols::CONTENT_TYPE_AUTO_REPLY];
			$response->time           = time();
			
			if ($this->serv->isEstablished($fd)) {
				return $this->serv->push($fd, $response->encode());
			}
		}
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
	private function send_menu_to_user($link, $fd, $uid)
	{
		//是否开启了回复菜单功能
		$account_id = $this->msgHandler->get_account_by_link($link);
		
		$account_setting = Db::name('account_settings')->where('account_id', $account_id)->field('menu_title,auto_reply_open')
			->cache(md5($account_id['account_id'] . 'account_settings'), $this->msgHandler->cache_time)->find();
		
		if ($account_setting) {
			$data                     = Db::name('account_auto_menu')
				->where('account_id', $account_id)
				->cache(true, $this->msgHandler->cache_time)
				->field('question,auto_menu_id,sort')
				->order('sort desc')
				->select();
			$response                 = new MessageSendProtocols();
			$response->cmd            = MessageSendProtocols::CMD_MENU;
			$response->from_uid       = $account_id['account_id'];
			$response->from_user_type = 2;
			$response->to_uid         = $uid;
			$response->to_user_type   = 1;
			$response->data           = ['setting' => $account_setting, 'data' => $data];
			$response->time           = time();
			
			if ($this->serv->isEstablished($fd)) {
				return $this->serv->push($fd, $response->encode());
			}
		}
	}
	
	/**
	 * 执行网关推送的消息
	 * @param GatewayProtocols $data
	 * @return bool|int
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function push_one_message(GatewayProtocols $data)
	{
		$fd = $data->fd;
		if ($this->serv->isEstablished($fd)) {
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
					->cache(true, $this->msgHandler->cache_time, $this->msgHandler->get_relation_cache_tag($data->to_uid, $data->from_uid))
					->find();
				$messageSend->need_notice = $relation ? $relation['need_notice'] : 1;
			}
			
			return $this->serv->push($fd, $messageSend->encode());//向fd发消息
		} else {
			return $this->msgHandler->unbindID($this->serv, $fd, $data->to_uid, $data->to_user_type);
		}
	}
}