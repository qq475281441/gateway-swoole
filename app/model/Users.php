<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/13
 * Time: 18:32
 */

namespace app\model;

use app\auth\Auth;
use think\Db;
use think\Exception;

class Users
{
	public $user_type;
	
	public $fd;
	
	public $room;
	
	public $ukey;
	
	public $server_key;
	
	public $login_time;
	
	public $account_id;
	
	public $raw_data;
	
	public $redis;
	
	public $table;
	
	public function __construct($redis, \swoole_table $table)
	{
		$this->redis = $redis;
		$this->table = $table;
		$this->auth  = new Auth();
	}
	
	/**
	 * 初始化用户数据
	 * @param $ukey
	 * @return Users
	 * @throws \Exception
	 */
	public function init(string $ukey)
	{
		$this->raw_data = $this->redis->get($ukey);
		$this->raw_data = json_decode($this->raw_data, true);
		if (!$this->raw_data || !is_array($this->raw_data) || count($this->raw_data) < 6) {
			return false;
		}
		$this->user_type  = $this->raw_data['user_type'];
		$this->fd         = $this->raw_data['fd'];
		$this->room       = $this->raw_data['room'];
		$this->server_key = $this->raw_data['server_key'];
		$this->ukey       = $ukey;
		$this->login_time = $this->raw_data['login_time'];
		$this->account_id = $this->raw_data['account_id'];
		return $this;
	}
	
	/**
	 * 创建用户并加入房间
	 * @return $this
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function build()
	{
		$this->checkBeforeBuild();
		$account = Db::name('report')->field('account_id')->cache(true, 600)
			->where('order_num', $this->room)->find();
		$r       = $this->redis->set($this->ukey,
		                             json_encode([
			                                         'server_key' => $this->server_key,
			                                         'room'       => $this->room,
			                                         'user_type'  => $this->user_type,
			                                         'fd'         => $this->fd,
			                                         'login_time' => $this->login_time,
			                                         'account_id' => $account['account_id']
		                                         ]));//设置用户哈希表
		
		if ($r) {
			if (!$this->redis->sAdd($this->auth->getRoomKey($this->room), $this->ukey)) {//room集合添加ukey
				return false;
			}
			return $this;
		} else {
			return false;
		}
	}
	
	public function checkBeforeBuild()
	{
		if (!$this->fd) {
			throw new \Exception('缺少FD');
		}
		if (!$this->room) {
			throw new \Exception('缺少room');
		}
		if (!$this->server_key) {
			throw new \Exception('缺少server_key');
		}
		if (!$this->user_type) {
			throw new \Exception('缺少user_type');
		}
		if (!$this->login_time) {
			throw new \Exception('缺少login_time');
		}
	}
	
	/**
	 * 销毁一个ukey关联信息-redis中
	 * @param        $ukey
	 * @param string $room 需要解绑房间就需要传
	 * @return bool
	 */
	public function destoryRedis($ukey, $room = '')
	{
		$this->redis->del($ukey);
		if ($room) {
			$this->redis->sRem($this->auth->getRoomKey($room), $ukey);
		}
		return true;
	}
	
	/**
	 * 删除一个连接的所有信息
	 * @param               $fd
	 * @return bool
	 */
	public function removeUser($fd)
	{
		echo "删除一个连接的所有信息>>>>>>>>>>>>>>>>$fd>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>\n";
		$fd_info = $this->table->get($fd);//根据fd查询出当前连接所属的ukey
		if ($fd_info) {
			$ukey = $fd_info['ukey'];
			$user = json_decode($this->redis->get($ukey), true);//根据ukey查询出当前连接所属房间
			if ($user && $user['room'] <> false) {
				$this->redis->sRem($this->auth->getRoomKey($user['room']), $ukey);//在这个房间移除此ukey
			}
			$this->redis->del($ukey);//redis中删除用户哈希表
			return $this->table->del($fd);//内存表中删除此fd数据
		} else {
			return false;//fd不存在
		}
	}
	
	/**
	 * onMessage时，进行频率控制
	 * @param $serv_key
	 * @param $fd
	 * @return bool
	 */
	public function onMessageLimitFreque($serv_key, $fd)
	{
		$key = md5($serv_key . $fd);
		$tmp = $this->redis->get($key);
		if ($tmp) {
			if ($tmp >= 5) {//触发了频率限制
				return false;
			} else {
				$this->redis->set($key, intval($tmp) + 1, 1);
				return true;
			}
		} else {
			$this->redis->set($key, 1, 1);
			return true;
		}
	}
}