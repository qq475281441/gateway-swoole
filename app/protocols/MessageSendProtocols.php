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
	const CMD_SEND_MESSAGE    = 1;//消息发送
	const CMD_TIPS            = 2;//系统提示
	const CMD_MESSAGE_LIST    = 3;//消息列表
	const CMD_USERINFO          = 4;//详情接口
	const CONTENT_TYPE_TEXT   = 1;//content-type text
	const CONTENT_TYPE_IMAGES = 2;//content-type images
	
	public $cmd;
	
	public $from_uid;
	
	public $to_uid;
	
	public $from_user_type;
	
	public $to_user_type;
	
	public $data;
	
	public $extra;
	
	public $msg = 'success';//状态信息
	
	public $time;
	
	/**
	 * 将要发的数据编码
	 * @return false|string
	 */
	public function encode()
	{
		$data = [
			'cmd'            => $this->cmd,
			'msg'            => $this->msg,
			'from_uid'       => $this->from_uid,
			'from_user_type' => $this->from_user_type,
			'to_uid'         => $this->to_uid,
			'to_user_type'   => $this->to_user_type,
			'data'           => $this->data,
			'extra'          => $this->extra,
			'time'           => $this->time,
		];
		$str  = json_encode($data);
		return $str;
	}
	
	/**
	 *  解码
	 * @param $string
	 * @return $this
	 */
	public function decode($string)
	{
		$data                 = json_decode($string, true);
		$this->cmd            = $data['cmd'];
		$this->msg            = isset($data['msg']) ? $data['msg'] : 'success';
		$this->from_uid       = isset($data['from_uid']) ? $data['from_uid'] : '';
		$this->from_user_type = isset($data['from_user_type']) ? $data['from_user_type'] : '';
		$this->to_uid         = isset($data['to_uid']) ? $data['to_uid'] : '';
		$this->to_user_type   = isset($data['to_user_type']) ? $data['to_user_type'] : '';
		$this->to_user_type   = isset($data['to_user_type']) ? $data['to_user_type'] : '';
		$this->time           = isset($data['time']) ? $data['time'] : '';
		
		$this->data  = isset($data['data']) ? $data['data'] : '';
		$this->extra = isset($data['extra']) ? $data['extra'] : '';
		return $this;
	}
}