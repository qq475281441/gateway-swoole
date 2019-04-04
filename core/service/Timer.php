<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/28
 * Time: 18:24
 */

namespace im\core\service;

use im\core\Container;

/**
 * swoole定时器
 * Class Timer
 * @package im\core\service
 */
class Timer
{
	/**
	 * 注册一个tick定时器
	 * @param          $ms  毫秒
	 * @param          $class
	 * @param          $args
	 * @return int
	 */
	public static function RegisterTick($ms, $class, $args)
	{
		$object = Container::get($class, $args);
		return swoole_timer_tick($ms, function () use ($object) {
			return $object->handler();
		});
	}
}