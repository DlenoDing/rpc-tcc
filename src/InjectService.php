<?php

namespace Dleno\RpcTcc;

/**
 * 事务注入服务对象
 * Class InjectService
 * @package Dleno\RpcTcc
 */
class InjectService
{
    /**
     * @var string 服务类名
     */
    private $serviceClass;

    /**
     * @var Transaction 事务对象
     */
    private $transaction;

    public function __construct(string $serviceClass, Transaction $transaction)
    {
        $this->serviceClass = $serviceClass;
        $this->transaction  = $transaction;
    }

    public function setTransaction(Transaction $transaction)
    {
        $this->transaction  = $transaction;
        return $this;
    }

    public function __call(string $method, array $params)
    {
        $transactionId   = $this->transaction->getTransactionId();
        $transactionType = rpc_context_get(Transaction::TRANSACTION_TYPE);
        $transactionReq  = rpc_context_get(Transaction::TRANSACTION_REQ);
        //调用服务
        $result = rpc_service_get($this->serviceClass)->{$method}(...$params);
        //记录服务调用-成功才添加
        $this->transaction->addServiceFun(
            $transactionId,
            $transactionType,
            $transactionReq,
            $this->serviceClass,
            $method,
            $params
        );
        return $result;
    }

    public function __destruct()
    {
        // TODO: Implement __destruct() method.
    }
}