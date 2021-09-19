<?php

declare(strict_types=1);

/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Dleno\RpcTcc;


use Dleno\RpcTcc\Processes\CmpTransactionProcess;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
            ],
            'processes'    => [
                CmpTransactionProcess::class,
            ],
            'annotations'  => [
                'scan' => [
                    'paths'     => [
                        __DIR__,
                    ],
                    'class_map' => [],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config of tcc transaction.',
                    'source' => __DIR__ . '/../publish/rpc_tcc.php',
                    'destination' => BASE_PATH . '/config/autoload/rpc_tcc.php',
                ],
            ],
        ];
    }
}
