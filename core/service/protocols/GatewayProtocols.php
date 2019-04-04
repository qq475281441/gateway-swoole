<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/5
 * Time: 10:22
 */

namespace im\core\service\protocols;

/**
 * 网关协议
 * Class GatewayProtocols
 * @package im\core\service\protocols
 */
class GatewayProtocols
{
	const CMD_PING                     = 1;//心跳命令
	const CMD_ON_MESSAGE               = 2;//通知服务有消息
	const CMD_REGISTER                 = 3;//通知服务注册信息
	const CMD_ON_MANAGE_CLIENT_MESSAGE = 4;//通知网关有管理客户端信息
	const CMD_PROCESS_DISCONNECT       = 5;//子进程与网关断开了连接
	const CMD_ON_CONNECT_GATEWAY       = 6;//连接上了网关事件
	const CMD_GATEWAY_PUSH             = 7;//网关消息转发
	const TYPE_SOCKET                  = 1;//服务类型为socket
	const TYPE_WEB_SOCKET              = 2;//服务类型为websocket
	
	public $cmd;//命令
	
	public $key;//服务key
	
	public $fd;//服务的fd
	
	public $master_pid;//master pid
	
	public $data;//数据
	
	public $extra;//扩展
	
	/**
	 * 将要发的数据编码
	 * @return false|string
	 */
	public function encode()
	{
		$data  = [
			'cmd'        => $this->cmd,
			'key'        => $this->key,
			'fd'         => $this->fd,
			'master_pid' => $this->master_pid,
			'data'       => $this->data,
			'extra'      => $this->extra,
		];
		$str   = json_encode($data);
		$final = pack('N', strlen($str)) . $str;
		return $final;
	}
	
	/**
	 *
	 * @param $string
	 * @return $this
	 */
	public function decode($string)
	{
		$info             = unpack('N', $string);
		$len              = $info[1];
		$body             = substr($string, -$len);
		$data             = json_decode($body, true);
		$this->cmd        = $data['cmd'];
		$this->key        = isset($data['key']) ? $data['key'] : '';
		$this->fd         = isset($data['fd']) ? $data['fd'] : '';
		$this->master_pid = isset($data['master_pid']) ? $data['master_pid'] : '';
		$this->data       = isset($data['data']) ? $data['data'] : '';
		$this->extra      = isset($data['extra']) ? $data['extra'] : '';
		return $this;
	}
}