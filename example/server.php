<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Handlers\Handshake;
use Onion\Framework\WebSocket\Server;
use Onion\Framework\WebSocket\Stream;

$server = new Server('0.0.0.0', 1337, Server::TYPE_TCP);
$server->on('handshake', function ($request, $socket) {
	$handshake = new Handshake();

	return $handshake($request, $socket);
});
$server->on('message', function (Stream $socket, Frame $data) {
	echo "Message\n";

	$socket->write($data);
});
$server->on('open', function () {
	echo "Open\n";
});
$server->on('close', function (int $code) {
	echo "Close ({$code})\n";
});
$server->start();
