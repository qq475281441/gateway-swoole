<?php
/**
 * Created by PhpStorm.
 * User: Asus
 * Date: 2019/2/27
 * Time: 15:36
 */

return [
	'redis' => [
		'host'     => '127.0.0.1',//本地
		'port'     => 6379,
		'password' => '',
		'expire'   => 24 * 60 * 60,//默认最长保存24小时
		'timeout'  => 0.1,//s
	],
	
	'mysql' => [
		'main' => [
			'type'            => 'mysql',
			// 服务器地址
			'hostname'        => '120.77.205.154',
			// 数据库名
			'database'        => 'app_kuaifaka',
			// 用户名
			'username'        => 'root',
			// 密码
			'password'        => 'xq92mqckxh3Q**d',
			// 端口
			'hostport'        => 3306,
			'prefix'          => 'kfk_',
			'charset'         => 'utf8mb4',
			'break_reconnect' => true,//开启断线重连
			// 连接dsn
			'debug'           => 'true',
		],
	
	],
	
	'servers' => [//注册的服务
	              '9501'      => \app\service\WebSocketServer9501::class,//84端口ws
	              'gateway' => \app\service\Gateway::class,//gateway服务
	              'http'    => \app\service\Http::class,//http服务
	],
	
	'extend_class_map' => [//第三方类库映射
	                       'think' => EXTEND_PATH . '/topthink/think-orm/src',
	],
	
	'extend_file_map' => [EXTEND_PATH . '/topthink/think-orm/src/config.php'],//需要直接加载的第三方文件
];