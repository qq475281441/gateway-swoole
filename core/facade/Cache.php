<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2018/11/12
 * Time: 14:51
 */

namespace im\core\facade;

use im\core\cache\Redis;
/**
 * Class Cache
 * @package im\core\facade
 *
 * @method void rm($name) static
 * @method void clear($tag) static
 * @method void set($name, $value, $expire) static
 * @method void tag($name, $keys, $overlay) static
 * @method void get($name) static
 * @method void has($name) static
 */
class Cache
{
	protected static $type = ['rm', 'clear', 'set', 'get', 'has','tag'];
	
	/**
	 * 得到静态对象
	 * @author needModify
	 * @date   2019/1/18 10:28:40
	 * @param $name
	 * @param $arguments
	 * @return mixed
	 * @throws \Exception
	 */
	public static function __callStatic($name, $arguments)
	{
		if (in_array($name, self::$type)) {
			$object = new Redis();
			return call_user_func_array([$object, $name], $arguments);
		}
	}
	
	/**
	 * 得到静态对象
	 * @author needModify
	 * @date   2019/1/18 10:29:00
	 * @param $name
	 * @param $arguments
	 * @return mixed
	 * @throws \Exception
	 */
	public function __call($name, $arguments)
	{
		if (in_array($name, self::$type)) {
			$object = new Redis();
			return call_user_func_array([$object, $name], $arguments);
		}
	}
	
	public static function getInstance()
	{
		return new static();
	}
}