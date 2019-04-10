<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/18
 * Time: 10:18
 */

namespace im\core\redis;

use im\core\connect\RedisConnectPool;
use im\core\Container;

/**
 * @var \Redis
 *一个简单的redis连接池
 * Class Redis
 * @package im\core\redis
 */
class Redis
{
	public function __call($name, $arguments)
	{
		$chan = new \Chan(1);
		go(function () use ($name, $arguments, $chan) {
			$pool   = RedisConnectPool::getInstance();
			$redis  = $pool->getConnect();//获取
			$result = call_user_func_array([$redis, $name], $arguments);//调用
			$pool->release($redis);//释放
			$chan->push($result);
		});
		
		return $chan->pop(100);
	}
}