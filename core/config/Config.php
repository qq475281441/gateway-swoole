<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/27
 * Time: 15:46
 */

namespace im\core\config;

class Config
{
	protected      $configPath = APP . '/config/';//默认配置文件
	
	public         $config     = [];
	
	public function __construct()
	{
		$this->scanConfig();
	}
	
	/**
	 *扫配置文件
	 */
	public function scanConfig()
	{
		$dir = scandir($this->configPath);
		foreach ($dir as $file) {
			if ('.' . pathinfo($file, PATHINFO_EXTENSION) === CONFIG_EXT && is_file($this->configPath . $file)) {
				$this->config[explode('.', $file)[0]] = require_once $this->configPath . $file;
			}
		}
	}
	
	public function __get($name)
	{
		//		var_dump(func_get_args());
	}
	
	/**
	 * 获取
	 * @param        $name
	 * @param string $value
	 * @return array|mixed
	 */
	public function get($name, $value = '')
	{
		if (!$value) {
			return isset($this->config['app'][$name]) ? $this->config['app'][$name] : [];
		}
		return isset($this->config['app'][$name][$value]) ? $this->config['app'][$name][$value] : [];
	}
	
	public static function __callStatic($name, $arguments)
	{
		$object = new Config();
		return call_user_func_array([$object, $name], $arguments);
	}
	
}