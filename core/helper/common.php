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

/**
 * 字符串转二进制
 */
if (!function_exists('StrToBin')) {
	function StrToBin($str)
	{
		//1.列出每个字符
		$arr = preg_split('/(?<!^)(?!$)/u', $str);
		//2.unpack字符
		foreach ($arr as &$v) {
			$temp = unpack('H*', $v);
			$v    = base_convert($temp[1], 16, 2);
			unset($temp);
		}
		
		return (int)join('3', $arr);
	}
}

/**
 * 二进制转字符串
 */
if (!function_exists('BinToStr')) {
	function BinToStr($str)
	{
		$arr = explode('3', $str);
		foreach ($arr as &$v) {
			$v = pack("H" . strlen(base_convert($v, 2, 16)), base_convert($v, 2, 16));
		}
		
		return join('', $arr);
	}
}
