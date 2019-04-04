<?php
/**
 * Created by PhpStorm.
 * User: qq475
 * Date: 2018/11/11
 * Time: 21:46
 */

namespace im\core;

class Loader
{
	protected static $classMap
		                             = [
			'im/core/cache'             => CORE . '/cache/',
			'im/core/config'            => CORE . '/config/',
			'im/core/db'                => CORE . '/db/',
			'im/core/facade'            => CORE . '/facade/',
			'im/core/log'               => CORE . '/log/',
			'im/core/redis'             => CORE . '/redis/',
			'im/core/service'           => CORE . '/service/',
			'im/core/service/protocols' => CORE . '/service/protocols/',
			'im/core/handler'           => CORE . '/handler/',
			'im/core/coroutine'         => CORE . '/coroutine/',
			'im/core/connect'           => CORE . '/connect/',
			'im/core/mysql'             => CORE . '/mysql/',
			'im/core'                   => CORE,
		];
	
	protected static $appClassMap    = [];
	
	protected static $extendClassMap = [];
	
	public static function Register($loader = "")
	{
		if (!empty($loader)) {
			spl_autoload_register($loader, true);
		} else {
			spl_autoload_register("self::autoload", true);
		}
		self::load_app(APP);
		self::load_composer();
	}
	
	/**
	 * 自动加载
	 * @param $class
	 */
	public static function autoload($class)
	{
		$class     = str_replace('\\', '/', $class);//命名空间统一将\转换为/,否则dirname在linux下和windows下返回的结果不同
		$namespace = dirname($class);
		
		if (isset(self::$classMap[$namespace])) {//先加载系统核心，这样扫描应用类库就可以使用核心cache，避免每次scandir
			$path = self::$classMap[$namespace];
			require_once $path . basename($class) . '.php';
		} else if (isset(self::$appClassMap[$namespace])) {//应用类库
			$path = self::$appClassMap[$namespace];
			require_once $path . '/' . basename($class) . '.php';
		} else if (isset(self::$extendClassMap[$namespace])) {//第三方类库
			$path = self::$extendClassMap[$namespace];
			require_once $path . '/' . basename($class) . '.php';
		} else {
			return;
		}
	}
	
	/**
	 * 字符串命名风格转换
	 * type 0 将Java风格转换为C的风格 1 将C风格转换为Java的风格
	 * @access public
	 * @param  string  $name 字符串
	 * @param  integer $type 转换类型
	 * @param  bool    $ucfirst 首字母是否大写（驼峰规则）
	 * @return string
	 */
	public static function parseName($name, $type = 0, $ucfirst = true)
	{
		if ($type) {
			$name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
				return strtoupper($match[1]);
			}, $name);
			return $ucfirst ? ucfirst($name) : lcfirst($name);
		}
		
		return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
	}
	
	/**
	 * 应用类文件自动映射
	 * @param $dir
	 */
	public static function load_app($dir, $base = 'app')
	{
		if (@$handle = opendir($dir)) {
			while (($file = readdir($handle)) !== false) {
				if ($file != ".." && $file != "." && is_dir($dir . '/' . $file)) { //排除根目录和文件
					self::$appClassMap[$base . '/' . $file] = $dir . '/' . $file;
					self::load_app($dir . '/' . $file, $base . '/' . $file);
				}
			}
			closedir($handle);
		}
	}
	
	/**
	 * 第三方类库加载
	 * @param $dir
	 */
	public static function load_extend()
	{
		foreach (self::$extendClassMap as $k => $dir) {
			self::loop_load_extend($k, $dir);
		}
	}
	
	public static function load_file($file)
	{
		if (file_exists($file)) {
			require_once $file;
		}
	}
	
	/**
	 * loop
	 * @param $base
	 * @param $dir
	 */
	private static function loop_load_extend($base, $dir)
	{
		if (@$handle = opendir($dir)) {
			while (($file = readdir($handle)) !== false) {
				if ($file != ".." && $file != "." && is_dir($dir . '/' . $file)) { //排除根目录和文件
					self::$extendClassMap[$base . '/' . $file] = $dir . '/' . $file;
					self::loop_load_extend($base . '/' . $file, $dir . '/' . $file);
				}
			}
			closedir($handle);
		}
	}
	
	/**
	 * 加载一个第三方的类库映射进来
	 * @param array $map
	 */
	public static function registerExtendClassMap(array $map)
	{
		self::$extendClassMap = array_merge(self::$extendClassMap, $map);
	}
	
	private static function load_composer()
	{
		require_once ROOT . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
	}
}