<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/6
 * Time: 17:51
 */

namespace app\auth;

use im\core\cache\Redis;
use think\Db;

class Auth
{
	/**
	 * 检测im登录token
	 * @param $token
	 * @param $type
	 * @param $authtype
	 * @param $room
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function validateToken($token, $type, $authtype, $room)
	{
		
		$redis = Redis::getInstance();
		switch ($type) {
			case 'user':
				if (!$r = $redis->get($token)) {//没有查询到这个token
					return false;
				}
				if ($room <> $r) {//token有效但不是这个订单的token
					return false;
				}
				return true;
				break;
			case 'account':
				$redis_cache_key_token = $token . $authtype;
				if ($redis->exists($redis_cache_key_token)) {
					//有此集合
					$data = $redis->zRangeByScore($redis_cache_key_token, time(), '+inf');
					$redis->zRemRangeByScore($redis_cache_key_token, 0, time());
					if ($data) {
						//清理过期token记录，如果有
						return $this->checkAccountOrderNum($data[0], $room);
					} else {
						//token过期
						return false;
					}
				} else {
					$account_token = Db::name('account_token')->where('token', $token)->where('auth_type', $authtype)->find();
					if ($account_token) {
						//token是否过期
						if ($account_token['expire_time'] < time()) {
							return false;//token已过期，请重新登录
						}
						$redis->zAdd($redis_cache_key_token, $account_token['expire_time'], $account_token['account_id']);//加入set
						return $this->checkAccountOrderNum($account_token['account_id'], $room);
					} else {
						return false;//token无效，请重新登录
					}
				}
				break;
			case 'admin':
				$admin_token = Db::name('admin')->where('token', $token)
					->where('token', '<>', '0')
					->where('state', '0')->find();
				if ($admin_token) {
					return true;
				}
				return false;
				break;
			default:
				return false;
		}
		return false;
	}
	
	/**
	 * 检测订单号是不是这个商户的
	 * @param $account_id
	 * @param $order_num
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	private function checkAccountOrderNum($account_id, $order_num)
	{
		$report = Db::name('report')->where('account_id', $account_id)->where('order_num', $order_num)
			->cache(true, 600)->find();
		return $report ? true : false;
	}
	
	/**
	 * 用户ukey生成算法
	 * @param $fd
	 * @return string
	 */
	public function getUkey($fd)
	{
		return 'ukey_' . md5('imfd-bind' . $fd . uniqid());
	}
	
	/**
	 * roomkey生成算法
	 * @param $room
	 * @return string
	 */
	public function getRoomKey($room)
	{
		return 'imroom_' . md5($room);
	}
	
	/**
	 * 生成服务key
	 * @return string
	 */
	public function getServerKey()
	{
		return md5(uniqid());
	}
	
	/**
	 * 获取商户key
	 * @param $account_id
	 * @return string
	 */
	public function getAccountKey($account_id)
	{
		return md5('last_ping_time' . $account_id);
	}
}