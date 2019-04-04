<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/16
 * Time: 10:33
 */

namespace im\core\connect;

use im\core\App;
use im\core\Container;

class RedisConnectPool
{
	protected     $num         = 50;
	
	protected     $redisConfig = [];
	
	protected     $config;
	
	protected     $chan;//pool
	
	public static $instance;
	
	public function __construct()
	{
		$this->config      = Container::get('config');
		$this->redisConfig = $this->config->get('redis');
		$this->chan        = new \Chan(1024);
		
		self::$instance = $this;
	}
	
	/**
	 *创建
	 */
	public function init()
	{
		for ($i = 0; $i < $this->num; $i++) {
			$this->chan->push($this->preRedis());
		}
	}
	
	/**
	 * 获取一个redis
	 * @return mixed
	 * @throws \Exception
	 */
	public function getConnect()
	{
		return $this->chan->pop(1);
	}
	
	/**
	 * 释放一个连接回去
	 * @param $redis
	 */
	public function release($redis)
	{
		if ($this->chan->length() < $this->num) {
			$this->chan->push($redis);
		}
	}
	
	/**
	 *准备一个redis
	 */
	private function preRedis()
	{
		echo '准备一个redis>>>>>>>>>>>>>>>>>>>>>>>'."\n";
		$redis = new \Redis();
		if ($redis->connect($this->redisConfig['host'], $this->redisConfig['port'], $this->redisConfig['timeout'])) {
			return $redis;
		} else {
			throw new \Exception('Redis connect Fail');
		}
	}
	
	public static function getInstance()
	{
		if (self::$instance instanceof self) {
			return self::$instance;
		}
		return new self;
	}
}