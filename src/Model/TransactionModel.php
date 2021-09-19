<?php
declare (strict_types=1);

namespace Dleno\RpcTcc\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property string $id
 * @property string $transaction_id 事务ID
 * @property string $service_class 服务类名
 * @property string $service_func 服务方法名
 * @property string $service_params 服务参数
 * @property int $status 事务状态
 * @property int $execute_count 已执行次数
 * @property int $max_retry 最大重试次数
 * @property string $service_result 服务返回值
 * @property string $ct 
 * @property string $mt 
 */
class TransactionModel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = '__transaction__';

    //不管理时间字段
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['id', 'transaction_id', 'service_class', 'service_func', 'service_params', 'status', 'execute_count', 'max_retry', 'service_result', 'ct', 'mt'];
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = ['id' => 'integer', 'status' => 'integer', 'execute_count' => 'integer', 'max_retry' => 'integer'];

    public function getId()
    {
        return $this->id;
    }
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }
    public function getTransactionId()
    {
        return $this->transaction_id;
    }
    public function setTransactionId($transaction_id)
    {
        $this->transaction_id = $transaction_id;
        return $this;
    }
    public function getServiceClass()
    {
        return $this->service_class;
    }
    public function setServiceClass($service_class)
    {
        $this->service_class = $service_class;
        return $this;
    }
    public function getServiceFunc()
    {
        return $this->service_func;
    }
    public function setServiceFunc($service_func)
    {
        $this->service_func = $service_func;
        return $this;
    }
    public function getServiceParams()
    {
        return $this->service_params;
    }
    public function setServiceParams($service_params)
    {
        $this->service_params = $service_params;
        return $this;
    }
    public function getStatus()
    {
        return $this->status;
    }
    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }
    public function getExecuteCount()
    {
        return $this->execute_count;
    }
    public function setExecuteCount($execute_count)
    {
        $this->execute_count = $execute_count;
        return $this;
    }
    public function getMaxRetry()
    {
        return $this->max_retry;
    }
    public function setMaxRetry($max_retry)
    {
        $this->max_retry = $max_retry;
        return $this;
    }
    public function getServiceResult()
    {
        return $this->service_result;
    }
    public function setServiceResult($service_result)
    {
        $this->service_result = $service_result;
        return $this;
    }
    public function getCt()
    {
        return $this->ct;
    }
    public function setCt($ct)
    {
        $this->ct = $ct;
        return $this;
    }
    public function getMt()
    {
        return $this->mt;
    }
    public function setMt($mt)
    {
        $this->mt = $mt;
        return $this;
    }
}