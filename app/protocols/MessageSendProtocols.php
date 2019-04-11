<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/4/11
 * Time: 11:44
 */

namespace app\protocols;

/**
 * 消息发送协议
 * Class MessageProtocols
 * @package app\protocols
 */
class MessageSendProtocols
{
	const CMD_SEND    = 1;//消息发送
	
	public $cmd;
	
	public $from_uid;
	
	public $to_uid;
	
	public $from_user_type;
	
	public $to_user_type;
	
	public $data;
	
	public $extra;
	
	/**
	 * 将要发的数据编码
	 * @return false|string
	 */
	public function encode()
	{
		$data = [
			'cmd'            => $this->cmd,
			'from_uid'       => $this->from_uid,
			'from_user_type' => $this->from_user_type,
			'to_uid'         => $this->to_uid,
			'to_user_type'   => $this->to_user_type,
			'data'           => $this->data,
			'extra'          => $this->extra,
		];
		$str  = json_encode($data);
		return $str;
	}
	
	/**
	 *解码
	 * @param $string
	 * @return $this
	 */
	public function decode($string)
	{
		$data                 = json_decode($string, true);
		$this->cmd            = $data['cmd'];
		$this->from_uid       = isset($data['from_uid']) ? $data['from_uid'] : '';
		$this->from_user_type = isset($data['from_user_type']) ? $data['from_user_type'] : '';
		$this->to_uid         = isset($data['to_uid']) ? $data['to_uid'] : '';
		$this->to_user_type   = isset($data['to_user_type']) ? $data['to_user_type'] : '';
		$this->to_user_type   = isset($data['to_user_type']) ? $data['to_user_type'] : '';
		
		$this->data  = isset($data['data']) ? $data['data'] : '';
		$this->extra = isset($data['extra']) ? $data['extra'] : '';
		return $this;
	}
}