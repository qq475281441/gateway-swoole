<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/18
 * Time: 18:41
 */

require_once 'RedisConnectPool.php';

class Redis2
{
	public function __call($name, $arguments)
	{
		$pool   = RedisConnectPool::getInstance();
		$redis  = $pool->getConnect();//获取
		$result = call_user_func_array([$redis, $name], $arguments);//调用
		$pool->release($redis);//释放
		return $result;
	}
}