<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/18
 * Time: 18:35
 */

class RedisConnectPool
{
	protected     $num         = 2;
	
	protected     $redisConfig = [];
	
	protected     $config;
	
	protected     $chan;//pool
	
	public static $instance;
	
	public function __construct()
	{
		$this->redisConfig = [
			'host'    => '127.0.0.1',
			'port'    => 6379,
			'timeout' => 0,
		];
		$this->chan        = new \Chan(1024);
		
		$this->init();
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
		return $this->chan->pop(2);
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