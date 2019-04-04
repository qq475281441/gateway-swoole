<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/27
 * Time: 16:28
 */

namespace im\core;

use im\core\config\Config;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Class Container
 * @package im\core
 *
 * @property Config  $config
 * @property Console $console
 */
class Container
{
	protected static $instance; //容器实例
	
	protected        $instances;//容器中对象实例
	
	/**
	 * 容器别名和类名的映射
	 * @var array
	 */
	protected $map = [
		'config'  => Config::class,
		'console' => Console::class,
	];
	
	/**
	 * 创建
	 * @param      $class
	 * @param      $argv
	 * @param bool $newInstance //是否强制用新实例
	 * @return mixed
	 */
	public function make($class, $argv = '', $newInstance = false)
	{
		$class = isset($this->map[$class]) ? $this->map[$class] : $class;//别名和类名的映射,没有映射就是类名
		
		if (isset($this->instances[$class]) && !$newInstance) {//如果已存在就用旧的
			return $this->instances[$class];
		}
		$object = $this->invokeClass($class, $argv);
		if (!$newInstance) {//不强制用新的就缓存旧的
			$this->instances[$class] = $object;
		}
		
		return $object;
	}
	
	/**
	 * 调用反射执行类的实例化 支持依赖注入
	 * @access public
	 * @param  string $class 类名
	 * @param  array  $vars 参数
	 * @return mixed
	 */
	public function invokeClass($class, $vars = [])
	{
		try {
			$reflect = new ReflectionClass($class);
			
			if ($reflect->hasMethod('__make')) {
				$method = new ReflectionMethod($class, '__make');
				
				if ($method->isPublic() && $method->isStatic()) {
					$args = $this->bindParams($method, $vars);
					return $method->invokeArgs(null, $args);
				}
			}
			
			$constructor = $reflect->getConstructor();
			
			$args = $constructor ? $this->bindParams($constructor, $vars) : [];
			
			return $reflect->newInstanceArgs($args);
		} catch (ReflectionException $e) {
			$this->console->error($e->getMessage());
			exit;
		}
	}
	
	/**
	 * 绑定参数
	 * @access protected
	 * @param  \ReflectionMethod|\ReflectionFunction $reflect 反射类
	 * @param  array                                 $vars 参数
	 * @return array
	 * @throws ReflectionException
	 */
	protected function bindParams($reflect, $vars = [])
	{
		if ($reflect->getNumberOfParameters() == 0) {
			return [];
		}
		// 判断数组类型 数字数组时按顺序绑定参数
		reset($vars);
		$type   = key($vars) === 0 ? 1 : 0;
		$params = $reflect->getParameters();
		foreach ($params as $param) {
			$name      = $param->getName();
			$lowerName = Loader::parseName($name);
			$class     = $param->getClass();
			if ($class) {
				$args[] = $this->getObjectParam($class->getName(), $vars);
			} else if (1 == $type && !empty($vars)) {
				$args[] = array_shift($vars);
			} else if (0 == $type && isset($vars[$name])) {
				$args[] = $vars[$name];
			} else if (0 == $type && isset($vars[$lowerName])) {
				$args[] = $vars[$lowerName];
			} else if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
			} else {
				throw new InvalidArgumentException('method param miss:' . $name);
			}
		}
		
		return $args;
	}
	
	/**
	 * 获取对象类型的参数值
	 * @access protected
	 * @param  string $className 类名
	 * @param  array  $vars 参数
	 * @return mixed
	 */
	protected function getObjectParam($className, &$vars)
	{
		$array = $vars;
		$value = array_shift($array);
		
		if ($value instanceof $className) {
			$result = $value;
			array_shift($vars);
		} else {
			$result = $this->make($className);
		}
		
		return $result;
	}
	
	/**
	 * @return mixed
	 */
	public static function getInstance()
	{
		if (is_null(static::$instance)) {
			static::$instance = new static;
		}
		
		return static::$instance;
	}
	
	public function __get($name)
	{
		return $this->make($name);
	}
	
	/**
	 * 获取容器中的对象实例
	 * @access public
	 * @param  string     $abstract 类名或者标识
	 * @param  array|true $vars 变量
	 * @param  bool       $newInstance 是否每次创建新的实例
	 * @return object
	 */
	public static function get($abstract, $vars = [], $newInstance = false)
	{
		return static::getInstance()->make($abstract, $vars, $newInstance);
	}
}