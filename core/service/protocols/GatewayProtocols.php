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
	const CMD_REGISTER_USER            = 8;//通知服务注册用户信息
	const CMD_UNREGISTER_USER          = 10;//通知服务解绑用户链接信息
	const CMD_ON_MANAGE_CLIENT_MESSAGE = 4;//通知网关有管理客户端信息
	const CMD_PROCESS_DISCONNECT       = 5;//子进程与网关断开了连接
	const CMD_ON_CONNECT_GATEWAY       = 6;//连接上了网关事件
	const CMD_GATEWAY_PUSH             = 7;//网关消息转发
	const CMD_SEND_TO_UID              = 9;//发送给uid
	const CMD_TEST_FD_ONLINE           = 11;//网关检测fd是否在线
	const TYPE_SOCKET                  = 1;//服务类型为socket
	const TYPE_WEB_SOCKET              = 2;//服务类型为websocket
	const TYPE_USER_ACCOUNT            = 2;//商家
	const TYPE_USER_U                  = 1;//买家
	
	public $cmd;//命令
	
	public $key;//服务key
	
	public $fd;//服务的fd
	
	public $master_pid;//master pid
	
	public $data;//数据
	
	public $extra;//扩展
	
	public $from_uid;
	
	public $to_uid;
	
	public $from_user_type;
	
	public $to_user_type;
	
	public $message_id;
	
	/**
	 * 协议配置项
	 * @var array
	 */
	public static $length_check_config = [
		'open_length_check'     => true,      // 开启协议解析
		'package_length_type'   => 'N',     // 长度字段的类型
		'package_length_offset' => 0,       //第几个字节是包长度的值
		'package_body_offset'   => 4,       //第几个字节开始计算长度
		'package_max_length'    => 81920,  //协议最大长度
	];
	
	/**
	 * 将要发的数据编码
	 * @return false|string
	 */
	public function encode()
	{
		$data = [
			'cmd'            => $this->cmd,
			'key'            => $this->key,
			'fd'             => $this->fd,
			'message_id'     => $this->message_id,
			'master_pid'     => $this->master_pid,
			'from_uid'       => $this->from_uid,
			'from_user_type' => $this->from_user_type,
			'to_uid'         => $this->to_uid,
			'to_user_type'   => $this->to_user_type,
			'data'           => $this->data,
			'extra'          => $this->extra,
		];
		foreach ($data as $k => $v) {
			if ($v == '' || $v == null) {
				unset($data[$k]);
			}
		}
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
		$info                 = unpack('N', $string);
		$len                  = $info[1];
		$body                 = substr($string, -$len);
		$data                 = json_decode($body, true);
		$this->cmd            = $data['cmd'];
		$this->key            = isset($data['key']) ? $data['key'] : '';
		$this->fd             = isset($data['fd']) ? $data['fd'] : '';
		$this->message_id     = isset($data['message_id']) ? $data['message_id'] : '';
		$this->from_uid       = isset($data['from_uid']) ? $data['from_uid'] : '';
		$this->from_user_type = isset($data['from_user_type']) ? $data['from_user_type'] : '';
		$this->to_uid         = isset($data['to_uid']) ? $data['to_uid'] : '';
		$this->to_user_type   = isset($data['to_user_type']) ? $data['to_user_type'] : '';
		$this->master_pid     = isset($data['master_pid']) ? $data['master_pid'] : '';
		$this->data           = isset($data['data']) ? $data['data'] : '';
		$this->extra          = isset($data['extra']) ? $data['extra'] : '';
		return $this;
	}
}