<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/5
 * Time: 11:47
 */

namespace im\core\coroutine;

use im\core\Container;
use Swoole\Coroutine\Mysql;

class CoMysql
{
	public $client;
	
	public function __construct()
	{
		$config = Container::get('config')->get('mysql')['main'];
		$this->client = new Mysql();
		$this->client->connect([
			                 'host'     => $config['hostname'],
			                 'user'     => $config['username'],
			                 'password' => $config['password'],
			                 'database' => $config['database'],
			                 'port'     => $config['hostport'],
			                 'timeout'  => 1,
			                 'charset'  => $config['charset']
		                 ]);
	}
}