<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/19
 * Time: 15:35
 */

namespace think\db\connector;

use PDO;

class MysqlPool
{
	protected     $num         = 10;
	
	protected     $mysqlConfig = [];
	
	protected     $config;
	
	protected     $chan;//pool
	
	protected     $time_tick   = false;
	
	public static $instance;
	
	public function __construct()
	{
		//		$this->config      = Container::get('config');
		$this->mysqlConfig = [
			'type'            => 'mysql',
			// 服务器地址
			'hostname'        => '120.77.205.154',
			// 数据库名
			'database'        => 'app_kuaifaka',
			// 用户名
			'username'        => 'root',
			// 密码
			'password'        => 'xq92mqckxh3Q**d',
			// 端口
			'hostport'        => 3306,
			'prefix'          => 'kfk_',
			'charset'         => 'utf8mb4',
			'break_reconnect' => true,//开启断线重连
			// 连接dsn
			'debug'           => 'true',
		];
		$this->chan        = new \Chan(1024);
		
		$this->init();
	}
	
	/**
	 *创建
	 */
	public function init()
	{
		if (!$this->time_tick) {
			swoole_timer_tick(100, function () {
				$this->time_tick = true;
				while ($this->chan->length() < $this->num) {
					$this->chan->push($this->preMysql());
				}
			});
		}
	}
	
	/**
	 * 获取一个redis
	 * @return PDO
	 * @throws \Exception
	 */
	public function getConnect()
	{
		$conn = $this->chan->pop(1);//队列延迟2秒
		return $conn;
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
	private function preMysql()
	{
		var_dump($this->chan->length());
		$mysql = new PDO("mysql:dbname=app_kuaifaka;host=120.77.205.154;port=3306", 'root', 'xq92mqckxh3Q**d');
		if ($mysql) {
			return $mysql;
		} else {
			throw new \Exception('Mysql connect Fail');
		}
	}
	
	public static function getInstance()
	{
		if (self::$instance instanceof self) {
			return self::$instance;
		}
		self::$instance = new self;
		return self::$instance;
	}
}