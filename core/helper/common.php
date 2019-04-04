<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/28
 * Time: 10:43
 */

use im\core\Container;

if (!function_exists('app')) {
	function app($name = 'think\App', $args = [], $newInstance = false)
	{
		return Container::get($name, $args, $newInstance);
	}
}