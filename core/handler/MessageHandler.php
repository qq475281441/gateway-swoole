<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/27
 * Time: 15:35
 */

namespace im\core\handler;

class MessageHandler
{
	/**
	 * 用户日志
	 * @param $title
	 * @param $content
	 */
	public function user_log($title, $content=[])
	{
		echo "[" . date('Y-m-d H:i:s', time()) . "][" . $title . "]" . var_export($content) . "\n";
	}
}