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

namespace Dleno\RpcTcc\Processes;

use Hyperf\DbConnection\Db;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Hyperf\Redis\RedisFactory;
use Dleno\CommonCore\Tools\Lock\DcsLock;
use Dleno\CommonCore\Tools\Server;
use Dleno\RpcTcc\Model\TransactionModel;
use Dleno\RpcTcc\Transaction;
use Psr\Container\ContainerInterface;

/**
 * 事务服务补偿
 * Class CmpTransactionProcess
 * @package Dleno\RpcTcc\Processes
 */
class CmpTransactionProcess extends AbstractProcess
{
    const CACHE_LOCK_TIME = 60;

    public $name = 'RpcTcc-CmpTransactionProcess';

    private $cmpLocalService       = false;
    private $cmpRemoteService      = false;
    private $cancelLocalTryService = false;

    /**
     * @var \Hyperf\Redis\Redis
     */
    protected $redis;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        // 通过 DI 容器获取或直接注入 RedisFactory 类
        $this->redis = $container->get(RedisFactory::class)
                                 ->get(config('rpc_tcc.db_pool', 'default'));
    }

    public function handle(): void
    {
        while (ProcessManager::isRunning()) {
            $thisVal = md5(Server::getIpAddr() . Server::getMacAddr());
            $lockKey = Transaction::CACHE_KEY_PREFIX . 'LOCK_RUN:' . md5(config('app_name'));

            //分布式锁
            $isLock = DcsLock::lock($lockKey, $thisVal, self::CACHE_LOCK_TIME);
            if (!$isLock) {
                goto NORUN;
            }

            //执行业务逻辑
            $this->delHistoryData();
            $this->cmpLocalService();
            $this->cmpRemoteService();
            $this->cancelLocalTryService();

            $intval = mt_rand(2, 5);
            \Swoole\Coroutine::sleep($intval);

            //解锁
            DcsLock::unlock($lockKey, $thisVal);

            NORUN:

            $intval = mt_rand(1, 3);
            \Swoole\Coroutine::sleep($intval);
        }
    }

    /**
     * 删除历史数据
     */
    private function delHistoryData()
    {
        $checkTime = date('Y-m-d H:i:s', time() - 3600 * 4);//只保留4小时的历史记录
        TransactionModel::getModel()
                        ->setConnection(config('rpc_tcc.db_pool', 'default'))
                        ->where('status', Transaction::SERVICE_TRANSACTION_STATUS_SUCCESS)
                        ->where('mt', '<=', $checkTime)
                        ->delete();
    }

    /**
     * 补偿本地调用失败服务
     */
    private function cmpLocalService()
    {
        go(
            function () {
                if ($this->cmpLocalService) {
                    return;
                }
                $this->cmpLocalService = true;
                defer(
                    function () {
                        $this->cmpLocalService = false;
                    }
                );
                $fields    = [
                    'transaction_id',
                    'transaction_req',
                    'transaction_type',
                    'service_class',
                    'service_func',
                    'service_params',
                ];
                $checkTime = date('Y-m-d H:i:s', time() - 60);
                $list      = TransactionModel::getModel()
                                             ->setConnection(config('rpc_tcc.db_pool', 'default'))
                                             ->where('status', Transaction::SERVICE_TRANSACTION_STATUS_WAIT)
                                             ->where('is_local', 1)
                                             ->where('mt', '<=', $checkTime)
                                             ->whereIn(
                                                 'transaction_type',
                                                 [
                                                     Transaction::TRANSACTION_TYPE_CONFIRM,
                                                     Transaction::TRANSACTION_TYPE_CANCEL,
                                                 ]
                                             )
                                             ->where('execute_count', '<', Db::raw('`max_retry`'))
                                             ->select($fields)
                                             ->get();
                $list      = $list->toArray();
                foreach ($list as $service) {
                    $serviceClient = rpc_service_get($service['service_class']);
                    rpc_context_set(Transaction::TRANSACTION_ID, $service['transaction_id']);
                    rpc_context_set(Transaction::TRANSACTION_TYPE, $service['transaction_type']);
                    rpc_context_set(Transaction::TRANSACTION_REQ, $service['transaction_req']);
                    rpc_context_set(Transaction::TRANSACTION_LOCAL_REQ, true);
                    try {
                        $result = $serviceClient->{$service['service_func']}(
                            ...unserialize($service['service_params'])
                        );
                        unset($service['service_params'], $service['is_local']);
                        //成功
                        TransactionModel::getModel()
                                        ->setConnection(config('rpc_tcc.db_pool', 'default'))
                                        ->where($service)
                                        ->update(
                                            [
                                                'execute_count'  => Db::raw('`execute_count`+1'),
                                                'status'         => Transaction::SERVICE_TRANSACTION_STATUS_SUCCESS,
                                                'service_result' => serialize($result),
                                            ]
                                        );
                    } catch (\Throwable $throwable) {
                        //失败不抛错
                    }
                }
            }
        );
    }

    /**
     * 补偿远程调用失败服务
     */
    private function cmpRemoteService()
    {
        go(
            function () {
                if ($this->cmpRemoteService) {
                    return;
                }
                $this->cmpRemoteService = true;
                defer(
                    function () {
                        $this->cmpRemoteService = false;
                    }
                );
                $fields    = [
                    'transaction_id',
                    'transaction_req',
                    'transaction_type',
                    'service_class',
                    'service_func',
                    'service_params',
                ];
                $checkTime = date('Y-m-d H:i:s', time() - 60);
                $list      = TransactionModel::getModel()
                                             ->setConnection(config('rpc_tcc.db_pool', 'default'))
                                             ->where('status', Transaction::SERVICE_TRANSACTION_STATUS_WAIT)
                                             ->where('is_local', 0)
                                             ->where('mt', '<=', $checkTime)
                                             ->whereIn(
                                                 'transaction_type',
                                                 [
                                                     Transaction::TRANSACTION_TYPE_CONFIRM,
                                                     Transaction::TRANSACTION_TYPE_CANCEL,
                                                 ]
                                             )
                                             ->where('execute_count', '<', Db::raw('`max_retry`'))
                                             ->select($fields)
                                             ->get();
                $list      = $list->toArray();
                foreach ($list as $service) {
                    $serviceClient = rpc_service_get($service['service_class']);
                    rpc_context_set(Transaction::TRANSACTION_ID, $service['transaction_id']);
                    rpc_context_set(Transaction::TRANSACTION_TYPE, $service['transaction_type']);
                    rpc_context_set(Transaction::TRANSACTION_REQ, $service['transaction_req']);
                    try {
                        $serviceClient->{$service['service_func']}(...unserialize($service['service_params']));
                        unset($service['service_params'], $service['is_local']);
                        //成功
                        TransactionModel::getModel()
                                        ->setConnection(config('rpc_tcc.db_pool', 'default'))
                                        ->where($service)
                                        ->delete();
                    } catch (\Throwable $throwable) {
                        unset($service['service_params'], $service['is_local']);
                        //失败不抛错
                        TransactionModel::getModel()
                                        ->setConnection(config('rpc_tcc.db_pool', 'default'))
                                        ->where($service)
                                        ->update(
                                            [
                                                'execute_count' => Db::raw('`execute_count`+1'),
                                                'status'        => Db::raw(
                                                    'if(`execute_count`<`max_retry`,`status`,' . Transaction::SERVICE_TRANSACTION_STATUS_FAIL . ')'
                                                ),
                                            ]
                                        );
                    }
                }
            }
        );
    }

    /**
     * 本地Try服务超时取消（手动补偿最稳妥）
     */
    private function cancelLocalTryService()
    {
        go(
            function () {
                if ($this->cancelLocalTryService) {
                    return;
                }
                $this->cancelLocalTryService = true;
                defer(
                    function () {
                        $this->cancelLocalTryService = false;
                    }
                );
                $fields    = [
                    'transaction_id',
                    'transaction_req',
                    'transaction_type',
                    'service_class',
                    'service_func',
                    'service_params',
                ];
                $checkTime = date('Y-m-d H:i:s', time() - config('rpc_tcc.try_timeout_cancel', 120));
                $list      = TransactionModel::getModel()
                                             ->setConnection(config('rpc_tcc.db_pool', 'default'))
                                             ->where('status', Transaction::SERVICE_TRANSACTION_STATUS_SUCCESS)
                                             ->where('is_local', 1)
                                             ->where('mt', '<=', $checkTime)
                                             ->where('transaction_type', Transaction::TRANSACTION_TYPE_TRY)
                                             ->where('execute_count', '>', 0)
                                             ->select($fields)
                                             ->orderBy('mt', 'desc')
                                             ->get();
                $list      = $list->toArray();
                foreach ($list as $service) {
                    $serviceClient = rpc_service_get($service['service_class']);
                    rpc_context_set(Transaction::TRANSACTION_ID, $service['transaction_id']);
                    rpc_context_set(Transaction::TRANSACTION_TYPE, Transaction::TRANSACTION_TYPE_CANCEL);
                    rpc_context_set(Transaction::TRANSACTION_REQ, $service['transaction_req']);
                    rpc_context_set(Transaction::TRANSACTION_LOCAL_REQ, true);
                    try {
                        $serviceClient->{$service['service_func']}(...unserialize($service['service_params']));
                        //成功(cancel调用会自动删除TRY)
                    } catch (\Throwable $throwable) {
                        //失败不抛错
                    }
                }
            }
        );
    }
}
