<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/19
 * Time: 14:29
 */

namespace im\core\mysql;

use im\core\App;
use im\core\connect\MysqlConnectPool;
use im\core\Container;

class Mysql
{
	/**
	 * @var \PDO
	 */
	protected $connection;
	
	protected $name;
	
	protected $config;
	
	protected $field = '*';
	
	protected $connectionPool;
	
	public function __construct(App $app)
	{
		$this->config         = $app->config->get('mysql');
		$this->connectionPool = MysqlConnectPool::getInstance();
	}
	
	/**
	 * 设置表名
	 * @param $name
	 * @return $this
	 */
	public function name($name)
	{
		$this->name = $this->config['prefix'] . $name;
		return $this;
	}
	
	/**
	 * 操作的字段
	 * @param string $field
	 * @return $this
	 */
	public function field($field = '*')
	{
		$this->field = $field;
		return $this;
	}
	
	public function where(array $where)
	{
	
	}
	
	public function select()
	{
		if (!$this->connection) {
			$this->connection = $this->connectionPool->getConnect();
		}
	}
}