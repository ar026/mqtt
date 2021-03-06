<?php
/**
 * This file is part of Simps
 *
 * @link     https://github.com/simps/mqtt
 * @contact  lufei <lufei@simps.io>
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 */

include __DIR__ . '/../vendor/autoload.php';

use Simps\MQTT\Protocol;
use Simps\MQTT\Types;

$server = new Swoole\Server('127.0.0.1', 1883, SWOOLE_BASE);

$server->set(
    [
        'open_mqtt_protocol' => true,
        'worker_num' => 2,
        'package_max_length' => 2 * 1024 * 1024
    ]
);

$server->on('connect', function ($server, $fd) {
    echo "Client #{$fd}: Connect.\n";
});

$server->on('receive', function (Swoole\Server $server, $fd, $from_id, $data) {
    try {
        $data = Protocol::unpack($data);
        var_dump($data);
        if (is_array($data) && isset($data['type'])) {
            switch ($data['type']) {
                case Types::CONNECT:
                    if ($data['protocol_name'] != 'MQTT') {
                        $server->close($fd);
                        return false;
                    }

                    // ...

                    $server->send(
                        $fd,
                        Protocol::pack(
                            [
                                'type' => Types::CONNACK,
                                'code' => 0,
                                'session_present' => 0,
                            ]
                        )
                    );
                    break;
                case Types::PINGREQ:
                    $server->send($fd, Protocol::pack(['type' => Types::PINGRESP]));
                    break;
                case Types::DISCONNECT:
                    if ($server->exist($fd)) {
                        $server->close($fd);
                    }
                    break;
                case Types::PUBLISH:
                    // send subscribe
                    $server->send(
                        1,
                        Protocol::pack(
                            [
                                'type' => $data['type'],
                                'topic' => $data['topic'],
                                'message' => $data['message'],
                                'dup' => $data['dup'],
                                'qos' => $data['qos'],
                                'retain' => $data['retain'],
                                'message_id' => $data['message_id'] ?? ''
                            ]
                        )
                    );

                    if ($data['qos'] === 1) {
                        $server->send(
                            $fd,
                            Protocol::pack(
                                [
                                    'type' => Types::PUBACK,
                                    'message_id' => $data['message_id'] ?? '',
                                ]
                            )
                        );
                    }

                    break;
                case Types::SUBSCRIBE: // 订阅
                    $payload = [];
                    foreach ($data['topics'] as $k => $qos) {
                        if (is_numeric($qos) && $qos < 3) {
                            $payload[] = chr($qos);
                        } else {
                            $payload[] = chr(0x80);
                        }
                    }
                    $server->send(
                        $fd,
                        Protocol::pack(
                            [
                                'type' => Types::SUBACK,
                                'message_id' => $data['message_id'] ?? '',
                                'payload' => $payload
                            ]
                        )
                    );
                    break;
                case Types::UNSUBSCRIBE:
                    $server->send(
                        $fd,
                        Protocol::pack(
                            [
                                'type' => Types::UNSUBACK,
                                'message_id' => $data['message_id'] ?? '',
                            ]
                        )
                    );
                    break;
            }
        } else {
            $server->close($fd);
        }
    } catch (\Throwable $e) {
        $server->close($fd);
    }
});

$server->on('close', function ($server, $fd) {
    echo "Client #{$fd}: Close.\n";
});

$server->start();