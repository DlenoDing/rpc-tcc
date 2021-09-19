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
return [
    //事务DB连接池
    'db_pool' => env('TRANSACTION_DB_POOL', 'default'),
    //事务redis连接池
    'redis_pool' => env('TRANSACTION_REDIS_POOL', 'default'),
    //事务最大重试次数
    'max_retry' => (int)env('TRANSACTION_MAX_RETRY', 5),
    //本地Try调用超时取消时间(秒)
    'try_timeout_cancel' => (int)env('TRANSACTION_TRY_TIMEOUT_CANCEL', 120),
];
