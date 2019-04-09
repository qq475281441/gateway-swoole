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
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function validateToken($token, $type, $authtype)
	{
		$redis = Redis::getInstance();
		switch ($type) {
			case 'user':
				return 1;
				break;
			case 'account':
				$redis_cache_key_token = $token . $authtype;
				
				if ($redis->exists($redis_cache_key_token)) {
					//有此集合
					$data = $redis->zRangeByScore($redis_cache_key_token, time(), '+inf');
					$redis->zRemRangeByScore($redis_cache_key_token, 0, time());
					if (!$data) {
						//token过期
						return false;
					}else{
						return $data[0];
					}
				} else {
					$account_token = Db::name('account_token')->where('token', $token)
						->field('expire_time,account_id')
						->where('auth_type', $authtype)->find();
					if ($account_token) {
						//token是否过期
						if ($account_token['expire_time'] < time()) {
							return false;//token已过期，请重新登录
						}
						$redis->zAdd($redis_cache_key_token, $account_token['expire_time'], $account_token['account_id']);//加入set
						return $account_token['account_id'];
					} else {
						return false;//token无效，请重新登录
					}
				}
				break;
			default:
				return false;
		}
		return false;
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