<?php

namespace Dleno\RpcTcc;

use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Redis\RedisFactory;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Context;
use Dleno\CommonCore\Tools\Server;
use Dleno\RpcTcc\Exception\TransactionException;
use Dleno\RpcTcc\Exception\TransactionMethodException;
use Dleno\RpcTcc\Model\TransactionModel;

/**
 * 分布式事务封装处理
 */
class Transaction
{
    const TRANSACTION_OBJECT     = '_TRANSACTION_OBJECT_';//事务对象在协程上下文的KEY
    const TRANSACTION_TYPE       = '_TRANSACTION_TYPE_';//事务类型在RPC上下文的KEY
    const TRANSACTION_REQ        = '_TRANSACTION_REQ_';//事务服务请求号在RPC上下文的KEY
    const TRANSACTION_ID         = '_TRANSACTION_ID_';//事务ID在RPC上下文的KEY,整个链路使用同一个ID
    const TRANSACTION_LOCAL_REQ  = '_TRANSACTION_LOCAL_REQ_';
    const TRANSACTION_FIRST_NODE = '_TRANSACTION_FIRST_NODE_';
    const TRANSACTION_PARENT_REQ = '_TRANSACTION_PARENT_REQ_';

    //TCC类型
    const TRANSACTION_TYPE_TRY     = 'Try';//尝试
    const TRANSACTION_TYPE_CONFIRM = 'Confirm';//确认
    const TRANSACTION_TYPE_CANCEL  = 'Cancel';//取消

    //服务事务执行状态
    const SERVICE_TRANSACTION_STATUS_WAIT    = 0;//未执行
    const SERVICE_TRANSACTION_STATUS_FAIL    = 1;//执行失败
    const SERVICE_TRANSACTION_STATUS_SUCCESS = 2;//执行成功

    //服务重试最大次数
    const SERVICE_TRANSACTION_MAX_RETRY = 5;

    const CACHE_KEY_PREFIX = 'TCC:';

    /**
     * @var array 事务涉及的服务列表调用详情
     */
    private $serviceList = [];

    /**
     * @var int 事务涉及的服务列表调用索引
     */
    private $serviceListIdx = 0;

    /**
     * @var bool 是否事务起始节点
     */
    private $isFirstNode = false;

    /**
     * @var array 整个事务前后涉及的DB对应的连接池配置
     */
    private $dbPools = [];

    /**
     * @var int 内部事务编号
     */
    private static $transactionNo = 0;

    /**
     * @var string 事务ID
     */
    private $transactionId;

    /**
     * @var string 事务类型
     */
    private $transactionType;

    /**
     * @var string 事务父级请求号
     */
    private $transactionParentReq = null;

    /**
     * @var string 事务DB连接池
     */
    private $transactionDbPool = 'default';

    /**
     * @var bool 是否已开启事务
     */
    private $isTransaction = false;

    /**
     * @var Redis
     */
    private $redis;

    /**
     * 获取事务对象
     *
     * @param array $dbPools 本地DB事务涉及的DB连接池
     * @return Transaction
     */
    public static function getTransaction(array $dbPools = [])
    {
        //获取对象并初始化
        $transactionType = rpc_context_get(self::TRANSACTION_TYPE);
        $isFirstNode     = false;
        if (empty($transactionType) ||
            ($transactionType == self::TRANSACTION_TYPE_TRY && Context::get(self::TRANSACTION_FIRST_NODE, false))
        ) {
            $isFirstNode = true;
        }
        $transactionType = $transactionType ?? self::TRANSACTION_TYPE_TRY;
        $transaction     = new self();
        $transaction->setDbPools($dbPools)
                    ->setTransactionType($transactionType)
                    ->setIsFirstNode($isFirstNode)
                    ->init();
        //放入协程上下文，主要用于发生异常未捕获时自动回滚
        Context::set(self::TRANSACTION_OBJECT, $transaction);

        return $transaction;
    }

    /**
     * 全局事务回滚
     */
    public static function commonRollBack()
    {
        $serviceTrans = false;
        //rpc事务
        if (Context::has(self::TRANSACTION_OBJECT)) {
            $serviceTrans = true;
            /** @var $transaction self */
            $transaction = Context::get(self::TRANSACTION_OBJECT);
            if ($transaction->isTransaction()) {
                $transaction->rollBack();
            }
        }
        //db事务
        if (!$serviceTrans && Db::transactionLevel() > 0) {
            //首次beginTransaction为开始一个事务，后续的每次调用beginTransaction为创建事务保存点。
            //rollBack回滚也只是回滚到上一个保存点，并不是回滚整个事务
            Db::rollBack(0);//回滚整个事务
        }
    }

    /**
     * 执行服务实际对应的方法
     *
     * @param $class
     * @param $func
     * @param $params
     * @param callable $callable
     * @return mixed
     * @throws \Throwable
     */
    public static function execServiceMethod($class, $func, $params, callable $callable)
    {
        $transactionType = rpc_context_get(self::TRANSACTION_TYPE);
        if (empty($transactionType)) {
            throw new TransactionMethodException($class . '::' . $func . ' TRANSACTION_TYPE is empty!');
        }
        if (!method_exists($class, $func . $transactionType)) {
            throw new TransactionMethodException($class . '::' . $func . ' Method is not found!');
        }

        $transactionDbPool = config('rpc_tcc.db_pool', 'default');

        //唯一标识数据
        $uniData = [
            'transaction_type' => $transactionType,
            'transaction_id'   => rpc_context_get(self::TRANSACTION_ID),
            'transaction_req'  => rpc_context_get(self::TRANSACTION_REQ),
            'service_class'    => $class,
            'service_func'     => $func,
        ];

        //查找历史记录-保持幂等
        $history = TransactionModel::getModel()
                                   ->setConnection($transactionDbPool)
                                   ->where($uniData)
                                   ->select(
                                       [
                                           'status',
                                           'service_result'
                                       ]
                                   )
                                   ->first();
        //不用检查是否成功，只要有记录则返回（CC重试由本地实现）
        if (!empty($history) && $history['status'] == self::SERVICE_TRANSACTION_STATUS_SUCCESS) {
            return unserialize($history['service_result']);
        }

        //将本地服务存入DB
        if (empty($history)) {
            //go(function () use ($uniData, $params) {
            $localServiceData = array_merge(
                $uniData,
                [
                    'is_local'       => 1,
                    'service_params' => serialize($params),
                    'status'         => self::SERVICE_TRANSACTION_STATUS_WAIT,
                    'execute_count'  => 0,
                    'max_retry'      => config('rpc_tcc.max_retry', self::SERVICE_TRANSACTION_MAX_RETRY),
                ]
            );
            TransactionModel::getModel()
                            ->setConnection($transactionDbPool)
                            ->insert($localServiceData);
            //});
            //非try方法，只要到了则删除TRY
            if ($transactionType != self::TRANSACTION_TYPE_TRY) {
                TransactionModel::getModel()
                                ->setConnection($transactionDbPool)
                                ->where(
                                    array_merge(
                                        $uniData,
                                        [
                                            'status'           => self::SERVICE_TRANSACTION_STATUS_SUCCESS,
                                            'transaction_type' => self::TRANSACTION_TYPE_TRY,
                                        ]
                                    )
                                )
                                ->delete();
            }
        }

        $result = null;
        try {
            //执行对应方法
            $result = $callable($func . $transactionType, $params);
            //成功
            //go(function () use ($uniData, $result) {
            TransactionModel::getModel()
                            ->setConnection($transactionDbPool)
                            ->where($uniData)
                            ->update(
                                [
                                    'execute_count'  => Db::raw('`execute_count`+1'),
                                    'status'         => self::SERVICE_TRANSACTION_STATUS_SUCCESS,
                                    'service_result' => serialize($result),
                                ]
                            );
            if ($transactionType == self::TRANSACTION_TYPE_TRY) {
            } else {
                //暂不删除，后面以缓存方式保存一段时间，防止客户端因为网络原因失败，而本地成功；客户端再次请求时保持幂等
                /*TransactionModel::getModel()
                                ->setConnection($transactionDbPool)
                                ->where($uniData)
                                ->delete();*/
            }
            //});

        } catch (\Throwable $throwable) {
            //先回滚事务
            if (Context::has(self::TRANSACTION_OBJECT)) {
                /** @var $transaction self */
                $transaction = Context::get(self::TRANSACTION_OBJECT);
                if ($transaction->isTransaction()) {
                    $transaction->rollBack();
                }
            }
            //失败删除记录
            if ($transactionType == self::TRANSACTION_TYPE_TRY) {
                //go(function () use ($uniData) {
                TransactionModel::getModel()
                                ->setConnection($transactionDbPool)
                                ->where($uniData)
                                ->delete();

                //try才抛出错误（兼容本地服务出错）
                throw $throwable;
                //});
            } else {
                TransactionModel::getModel()
                                ->setConnection($transactionDbPool)
                                ->where($uniData)
                                ->update(
                                    [
                                        'execute_count' => Db::raw('`execute_count`+1'),
                                        'status'        => Db::raw(
                                            'if(`execute_count`<`max_retry`,`status`,' . self::SERVICE_TRANSACTION_STATUS_FAIL . ')'
                                        ),
                                    ]
                                );
                //本地调用抛错
                if (rpc_context_get(Transaction::TRANSACTION_LOCAL_REQ, false)) {
                    throw $throwable;
                }
            }
        }


        return $result;
    }

    /**
     * 获取服务对象，使用分布式事务时必须使用此方法获取
     *
     * @param string $class 服务契约class name
     *
     * @return mixed Entry.
     */
    public function getService(string $class, $type = 'init')
    {
        if (!$this->isTransaction) {
            var_dump('The transaction did not start!');
            throw new TransactionException('The transaction did not start!');
        }
        return new InjectService($class, $this);
    }

    /**
     * 事务开启
     */
    public function beginTransaction()
    {
        if ($this->isTransaction) {
            //事务不能重复开启
            var_dump('The transaction has started!');
            throw new TransactionException('The transaction has started!');
        }
        //上级节点请求号，一直保持不变
        if (is_null($this->transactionParentReq)) {
            $this->transactionParentReq = Context::get(
                self::TRANSACTION_PARENT_REQ,
                rpc_context_get(self::TRANSACTION_REQ, '')
            );
            Context::set(self::TRANSACTION_PARENT_REQ, $this->transactionParentReq);
        }

        if ($this->isFirstNode) {
            //初始节点，每次事务更换一个ID；从节点固定不变
            $this->setTransactionId($this->createTransactionId());
            //初始节点，重置事务类型；从节点不变
            $this->setTransactionType(self::TRANSACTION_TYPE_TRY);
        } else {
            if (empty($this->transactionId)) {
                $this->transactionId = rpc_context_get(self::TRANSACTION_ID);
                if (empty($this->transactionId)) {
                    $this->setTransactionId($this->createTransactionId());
                }
            }
        }

        $this->isTransaction = true;

        //重置已请求服务列表
        $this->serviceList    = [];
        $this->serviceListIdx = 0;
        $this->setTransactionReq($this->getTransactionReq($this->serviceListIdx));

        $this->dbBeginTransaction();
        //放入协程上下文，主要用于发生异常未捕获时自动回滚
        Context::set(self::TRANSACTION_OBJECT, $this);
    }

    /**
     * 事务回滚
     */
    public function rollBack()
    {
        $clone = clone $this;
        $this->dbRollBack();
        $this->isTransaction = false;
        if ($clone->getTransactionType() == self::TRANSACTION_TYPE_TRY) {
            go(
                function () use ($clone) {
                    foreach ($clone->serviceList as $idx => $serviceClass) {
                        //将已请求服务存入DB
                        $serviceClass['uniData'] = [
                            'transaction_type' => self::TRANSACTION_TYPE_CANCEL,
                            'transaction_id'   => $serviceClass['tcId'],
                            'transaction_req'  => $serviceClass['req'],
                            'service_class'    => $serviceClass['class'],
                            'service_func'     => $serviceClass['func'],
                        ];
                        $transServiceData        = array_merge(
                            $serviceClass['uniData'],
                            [
                                'service_params' => serialize($serviceClass['params']),
                                'status'         => self::SERVICE_TRANSACTION_STATUS_WAIT,
                                'execute_count'  => 0,
                                'max_retry'      => config('rpc_tcc.max_retry', self::SERVICE_TRANSACTION_MAX_RETRY),
                            ]
                        );
                        TransactionModel::getModel()
                                        ->setConnection($clone->transactionDbPool)
                                        ->insert($transServiceData);

                        $clone->serviceList[$idx] = $serviceClass;
                    }

                    //依次Cancel请求之前已经try过的服务
                    foreach ($clone->serviceList as $idx => $serviceClass) {
                        try {
                            $clone->setTransactionType(self::TRANSACTION_TYPE_CANCEL)
                                  ->setTransactionReq($serviceClass['req'])
                                  ->getService($serviceClass['class'], 'rollback')
                                  ->setTransaction($clone)
                                  ->{$serviceClass['func']}(
                                      ...$serviceClass['params']
                                  );
                            TransactionModel::getModel()
                                            ->setConnection($this->transactionDbPool)
                                            ->where($serviceClass['uniData'])
                                            ->delete();
                            //TODO 测试
                            /*TransactionModel::getModel()
                                            ->setConnection($clone->transactionDbPool)
                                            ->where($serviceClass['uniData'])
                                            ->update(
                                                [
                                                    'status' => self::SERVICE_TRANSACTION_STATUS_SUCCESS
                                                ]
                                            );*/
                        } catch (\Throwable $throwable) {
                            var_dump('===============RollBack ERROR===================', $throwable->getMessage());
                            //不再抛出异常，有try，这里应该必须成功(兼容网络失败)
                            TransactionModel::getModel()
                                            ->setConnection($clone->transactionDbPool)
                                            ->where($serviceClass['uniData'])
                                            ->update(
                                                [
                                                    'execute_count' => Db::raw('`execute_count`+1'),
                                                    'status'        => Db::raw(
                                                        'if(`execute_count`<`max_retry`,`status`,' . self::SERVICE_TRANSACTION_STATUS_FAIL . ')'
                                                    ),
                                                ]
                                            );
                        }
                    }
                }
            );
        }
    }

    /**
     * 事务提交
     */
    public function commit()
    {
        $clone = clone $this;
        //将已请求服务存入DB
        if ($clone->isFirstNode) {
            foreach ($clone->serviceList as $idx => $serviceClass) {
                $serviceClass['uniData'] = [
                    'transaction_type' => self::TRANSACTION_TYPE_CONFIRM,
                    'transaction_id'   => $serviceClass['tcId'],
                    'transaction_req'  => $serviceClass['req'],
                    'service_class'    => $serviceClass['class'],
                    'service_func'     => $serviceClass['func'],
                ];
                $transServiceData        = array_merge(
                    $serviceClass['uniData'],
                    [
                        'service_params' => serialize($serviceClass['params']),
                        'status'         => self::SERVICE_TRANSACTION_STATUS_WAIT,
                        'execute_count'  => 0,
                        'max_retry'      => config('rpc_tcc.max_retry', self::SERVICE_TRANSACTION_MAX_RETRY),
                    ]
                );
                TransactionModel::getModel()
                                ->setConnection($clone->transactionDbPool)
                                ->insert($transServiceData);

                $clone->serviceList[$idx] = $serviceClass;
            }
        }

        $this->dbCommit();

        $this->isTransaction = false;
        if ($clone->isFirstNode) {
            go(
                function () use ($clone) {
                    //依次Confirm请求之前已经try过的服务
                    foreach ($clone->serviceList as $serviceClass) {
                        try {
                            $clone->setTransactionType(self::TRANSACTION_TYPE_CONFIRM)
                                  ->setTransactionId($serviceClass['tcId'])
                                  ->setTransactionReq($serviceClass['req'])
                                  ->getService($serviceClass['class'], 'commit')
                                  ->setTransaction($clone)
                                  ->{$serviceClass['func']}(
                                      ...$serviceClass['params']
                                  );
                            TransactionModel::getModel()
                                            ->setConnection($clone->transactionDbPool)
                                            ->where($serviceClass['uniData'])
                                            ->delete();
                            //TODO 测试
                            /*TransactionModel::getModel()
                                            ->setConnection($clone->transactionDbPool)
                                            ->where($serviceClass['uniData'])
                                            ->update(
                                                [
                                                    'status' => self::SERVICE_TRANSACTION_STATUS_SUCCESS
                                                ]
                                            );*/
                        } catch (\Throwable $throwable) {
                            var_dump('===============Commit ERROR===================', $throwable->getMessage());
                            //不再抛出异常，有try，这里应该必须成功(兼容网络失败)
                            TransactionModel::getModel()
                                            ->setConnection($clone->transactionDbPool)
                                            ->where($serviceClass['uniData'])
                                            ->update(
                                                [
                                                    'execute_count' => Db::raw('`execute_count`+1'),
                                                    'status'        => Db::raw(
                                                        'if(`execute_count`<`max_retry`,`status`,' . self::SERVICE_TRANSACTION_STATUS_FAIL . ')'
                                                    ),
                                                ]
                                            );
                        }
                    }
                }
            );
        }
    }

    /**
     * 是否已开启事务
     * @return bool
     */
    public function isTransaction()
    {
        return $this->isTransaction;
    }

    public function __call(string $method, array $params)
    {
        if (!method_exists($this, $method)) {
            throw new TransactionException($method . ' is not found in Transaction Class!');
        }

        return $this->{$method}(...$params);
    }

    //禁止外部new
    private function __construct()
    {
    }

    /**
     * 添加服务调用记录
     * @param $transactionId
     * @param $transactionType
     * @param $transactionReq
     * @param $class
     * @param $fun
     * @param array $params
     */
    private function addServiceFun($transactionId, $transactionType, $transactionReq, $class, $fun, array $params)
    {
        //调用顺序放入队列
        if ($transactionType == self::TRANSACTION_TYPE_TRY) {
            $this->serviceList[] = [
                'tcId'   => $transactionId,
                'req'    => $transactionReq,
                'class'  => $class,
                'func'   => $fun,
                'params' => $params,
            ];

        }

        //递增请求号
        $this->setTransactionReq($this->getTransactionReq(++$this->serviceListIdx));
    }

    /**
     * 开启数据库事务
     */
    private function dbBeginTransaction()
    {
        foreach ($this->dbPools as $dbPool) {
            Db::connection($dbPool)
              ->beginTransaction();
        }
    }

    /**
     * 回滚数据库事务
     */
    private function dbRollBack()
    {
        foreach ($this->dbPools as $dbPool) {
            Db::connection($dbPool)
              ->rollBack(0);
        }
    }

    /**
     * 提交数据库事务
     */
    private function dbCommit()
    {
        foreach ($this->dbPools as $dbPool) {
            Db::connection($dbPool)
              ->commit();
        }
    }

    /**
     * set $dbPolls
     * @param array $dbPools
     * @return $this
     */
    private function setDbPools(array $dbPools)
    {
        $this->transactionDbPool = config('rpc_tcc.db_pool', 'default');
        $dbPools                 = !empty($dbPools) ? $dbPools : ['default'];
        $dbPools[]               = $this->transactionDbPool;
        $dbPools                 = array_unique(array_filter($dbPools));
        $this->dbPools           = $dbPools;

        return $this;
    }

    /**
     * get $transactionType
     * @return string|null
     */
    private function getTransactionType()
    {
        return $this->transactionType;
    }

    /**
     * set $transactionType
     * @param $transactionType
     * @return $this
     */
    private function setTransactionType($transactionType)
    {
        //通过rpc上下文传递TCC类型
        rpc_context_set(self::TRANSACTION_TYPE, $transactionType);
        $this->transactionType = $transactionType;
        return $this;
    }

    /**
     * set $isFirstNode
     * @param bool $isFirstNode
     * @return $this
     */
    private function setIsFirstNode($isFirstNode)
    {
        $this->isFirstNode = $isFirstNode;
        if ($isFirstNode) {
            Context::set(self::TRANSACTION_FIRST_NODE, true);
        }
        return $this;
    }

    /**
     * 获取请求号
     * @param $req
     * @return string
     */
    private function getTransactionReq($req)
    {
        $transactionReq = '';
        if ($this->transactionParentReq !== '') {
            $transactionReq .= $this->transactionParentReq . '-';
        }
        if (!$this->isFirstNode) {
            $transactionReq .= self::$transactionNo . '-';
        }
        $transactionReq .= $req;
        return $transactionReq;
    }

    /**
     * 设置请求号
     * @param $transactionReq
     * @return $this
     */
    private function setTransactionReq($transactionReq)
    {
        rpc_context_set(self::TRANSACTION_REQ, $transactionReq);
        return $this;
    }

    private function createTransactionId()
    {
        self::$transactionNo++;
        //事务ID：请求号-进程ID-协程ID-内部事务号
        $transactionId = Server::getTraceId();//依赖使用框架里的工具
        $transactionId .= '-' . getmypid();
        $transactionId .= '-' . \Swoole\Coroutine::getCid();
        $transactionId .= '-' . self::$transactionNo;

        return $transactionId;
    }

    public function getTransactionId()
    {
        return $this->transactionId;
    }

    private function setTransactionId($transactionId)
    {
        $this->transactionId = $transactionId;
        rpc_context_set(self::TRANSACTION_ID, $transactionId);
        return $this;
    }

    /**
     * init
     * @return $this
     */
    private function init()
    {
        /*$this->redis = ApplicationContext::getContainer()
                                         ->get(RedisFactory::class)
                                         ->get(config('rpc_tcc.redis_pool', 'default'));*/

        return $this;
    }
}