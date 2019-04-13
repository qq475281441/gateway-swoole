<?php
/**
 * 同步redis客户端
 * Created by PhpStorm.
 * User: Asus
 * Date: 2018/11/12
 * Time: 14:52
 */

namespace im\core\cache;

use im\core\Container;
use Swoole\Runtime;

class Redis
{
	protected $options
		               = [
			'host'     => '127.0.0.1',
			'port'     => 6379,
			'password' => '',
			'select'   => '',
			'timeout'  => 0,
			'expire'   => 0,
			'prefix'   => 'mula_'
		];
	
	/**
	 * @var \Redis
	 */
	protected $handler = null;//redis句柄
	
	protected $tag     = null;//tag
	
	/**
	 * Redis constructor.
	 * @param array $options
	 * @throws \Exception
	 */
	public function __construct($options = [])
	{
		$config        = Container::get('config');
		$this->options = array_merge($this->options, $config->get('redis'));
		if (!empty($options)) {
			$this->options = array_merge($this->options, $options);
		}
		
		$this->handler = new \im\core\redis\Redis();
		if ('' != $this->options['password']) {
			$this->handler->auth($this->options['password']);
		}
		if ('' != $this->options['select']) {
			$this->handler->select($this->options['select']);
		}
	}
	
	public function has($name)
	{
		return $this->handler->exists($this->getCacheKey($name));
	}
	
	public function get($name, $default = false)
	{
		$value = $this->handler->get($this->getCacheKey($name));
		
		if (is_null($value) || false === $value) {
			return $default;
		}
		
		return $this->unSerialize($value);
	}
	
	public function rm($name)
	{
		return $this->handler->delete($this->getCacheKey($name));
	}
	
	public function set($name, $value, $expire = null)
	{
		if (is_null($expire)) {
			$expire = $this->options['expire'];
		}
		
		$key = $this->getCacheKey($name);
		
		$value = $this->serialize($value);
		
		if ($this->tag && !$this->has($name)) {
			$this->setTagItem($key);//tag和此缓存绑定起来
		}
		
		if ($expire) {
			$result = $this->handler->setex($key, $expire, $value);
		} else {
			$result = $this->handler->set($key, $value);
		}
		return $result;
	}
	
	public function clear($tag = null)
	{
		if ($tag) {
			// 指定标签清除
			$keys = $this->getTagItem($tag);
			$this->handler->del($keys);
			
			$tagName = $this->getTagKey($tag);
			$this->handler->del($tagName);
			return true;
		}
		return $this->handler->flushDB();
	}
	
	/**
	 * 缓存标签
	 * @access public
	 * @param  string       $name 标签名
	 * @param  string|array $keys 缓存标识
	 * @param  bool         $overlay 是否覆盖,同名删除就tag
	 * @return $this
	 */
	public function tag($name, $keys = null, $overlay = false)
	{
		if (is_null($keys)) {
			$this->tag = $name;
		} else {
			$tagName = $this->getTagKey($name);
			if ($overlay) {
				$this->handler->del($tagName);
			}
			
			foreach ($keys as $key) {
				$this->handler->sAdd($tagName, $key);
			}
		}
		
		return $this;
	}
	
	/**
	 * 更新标签
	 * @access protected
	 * @param  string $name 缓存标识
	 * @return void
	 */
	protected function setTagItem($name)
	{
		if ($this->tag) {
			$tagName = $this->getTagKey($this->tag);
			$this->handler->sAdd($tagName, $name);
		}
	}
	
	/**
	 * 获取标签包含的缓存标识
	 * @access protected
	 * @param  string $tag 缓存标签
	 * @return array
	 */
	protected function getTagItem($tag)
	{
		$tagName = $this->getTagKey($tag);
		return $this->handler->sMembers($tagName);
	}
	
	public function serialize($value)
	{
		return serialize($value);
	}
	
	public function unSerialize($value)
	{
		return unserialize($value);
	}
	
	protected function getCacheKey($name)
	{
		return md5($this->options['prefix'] . $this->serialize($this->options) . $name);
	}
	
	protected function getTagKey($tag)
	{
		return 'tag_' . md5($tag);
	}
	
	public static function getInstance($option = [])
	{
		$redis = new self($option);
		return $redis->handler;
	}
}