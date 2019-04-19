<?php

use Swlib\SaberGM;

require_once 'vendor/autoload.php';
go(function () {
	$websocket = SaberGM::websocket('ws://192.168.3.9:9501');
	$websocket->withQueryParam('token', 'A375016B5D26F63910BDCB5371F4E1');
	$websocket->withQueryParam('type', 'user');
	$websocket->withQueryParam('link', 'tkzljq');
	$websocket->withQueryParams(
		[
			'token' => 'A375016B5D26F63910BDCB5371F4E1',
			'type'  => 'user',
			'link'  => 'tkzljq',
		]
	);
	while (true) {
		var_dump($websocket->recv(1));
		$websocket->push("ping");
		co::sleep(1);
	}
});