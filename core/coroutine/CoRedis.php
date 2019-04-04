<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/3/1
 * Time: 11:18
 */

namespace im\core\coroutine;

use Swoole\Coroutine\Redis;

class CoRedis extends Redis
{
	public function __construct($options)
	{
		parent::__construct($options);
	}
}