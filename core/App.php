<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/27
 * Time: 17:12
 */

namespace im\core;

use im\core\facade\Cache;
use Swoole\Process;
use Swoole\Runtime;
use swoole_process;
use think\Db;

class App extends Container
{
	private $avaliablecommand = [//有效的命令
	                             'start',
	                             'stop',
	];
	
	private $server           = '';//命令要启动的服务
	
	private $sysServers       = [];//系统注册的服务
	
	private $command          = '';
	
	private $daemonize        = false;
	
	private $sig              = SIGTERM;
	
	private $process_pool     = [];
	
	public function __construct()
	{
	}
	
	/**
	 *运行框架
	 */
	public function run()
	{
		$this->init();
		$this->parseCommand();//命令解析
		$this->runServer();
	}
	
	/**
	 *初始化数据
	 */
	private function init()
	{
		$this->sysServers = $this->config->get('servers');
		
		$extend_class_map = $this->config->get('extend_class_map');
		$extend_file_map  = $this->config->get('extend_file_map');
		if (count($extend_class_map) > 0) {//加载第三方类库
			Loader::registerExtendClassMap($extend_class_map);
			Loader::load_extend();
		}
		if (count($extend_file_map) > 0) {
			foreach ($extend_file_map as $file) {
				Loader::load_file($file);
			}
		}
		Db::setConfig($this->config->get('mysql')['main']);//默认载入主库
		Db::setCacheHandler(Cache::getInstance());
		//		Runtime::enableCoroutine(true);//一键协程
		include CORE . 'helper' . DIRECTORY_SEPARATOR . 'common.php';//加载公共函数
	}
	
	/**
	 *解析命令
	 */
	private function parseCommand()
	{
		global $argv;
		if (count($argv) < 2) {//命令最少2个参数
			echo 'Command error';
			exit;
		}
		if (!isset($this->sysServers[$argv[1]]) && !in_array($argv[1], ['start', 'stop'])) {//服务是否注册
			echo 'Server is not register!';
			exit;
		}
		if (isset($argv[2])) {
			if ($argv[2] != '-d' && !in_array($argv[2], $this->avaliablecommand)) {//命令是否有效
				echo 'Command is not allow';
				exit;
			}
		}
		
		$this->server = $argv[1];//要启动的服务
		
		$this->command = isset($argv[2]) ? $argv[2] : '';
		
		if (isset($argv[3])) {
			$this->daemonize = $argv[3] == '-d' ? true : false;
		} else {
			$this->daemonize = false;
		}
	}
	
	/**
	 * 运行指定服务/命令
	 * @return mixed
	 */
	private function runServer()
	{
		global $argv;
		if ($this->server == 'start') {//启动所有
			foreach ($this->sysServers as $k => $v) {
				$p = new \swoole_process(function (swoole_process $process) use ($v, $k, $argv) {
					swoole_set_process_name(sprintf('im-server:%s', $k));
					$daemonize = (isset($argv[2]) && $argv[2] == '-d') ? true : false;
					$options   = ['daemonize' => $daemonize, 'pid_file' => RUNTIME . 'pid_file/server_' . $k . '_.pid'];
					$servObj   = $this->make($v, ['options' => $options]);
					$servObj->start();
					$this->console->success('服务[' . $k . ']启动成功...');
					usleep(500);
				});
				
				$this->process_pool[$k] = $p;
				$p->start();
			}
		} else {
			$class   = $this->sysServers[$this->server];
			$options = ['daemonize' => $this->daemonize, 'pid_file' => RUNTIME . 'pid_file/server_' . $this->server . '_.pid'];
			
			switch ($this->command) {
				case 'start':
					$servObj = $this->make($class, ['options' => $options]);
					$servObj->start();
					$this->console->success('服务启动成功...');
					break;
				case 'stop':
					return $this->stop($options['pid_file']);
					break;
				default:
					$servObj = $this->make($class, ['options' => $options]);
					$servObj->start();
					break;
			}
		}
	}
	
	/**
	 * 根据pid_file关闭服务
	 * @param $pid_file
	 */
	private function stop($pid_file)
	{
		$console = $this->console;
		$pid = file_get_contents($pid_file) or die($console->error('pid file not exist!'));
		
		if (posix_kill($pid, $this->sig) === true) {
			$console->success('服务关闭中...');
		} else {
			$console->error('关闭失败');
		}
		exit;
	}
}