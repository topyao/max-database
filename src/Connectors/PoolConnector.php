<?php

namespace Max\Database\Connectors;

use Max\Context\Context;
use Max\Database\Context\Connection;
use Max\Database\Contracts\ConnectorInterface;
use Max\Database\Contracts\PoolInterface;
use Max\Database\DatabaseConfig;
use PDO;
use Swoole\Coroutine\Channel;

class PoolConnector implements ConnectorInterface, PoolInterface
{
    /**
     * @var Channel
     */
    protected Channel $pool;

    /**
     * 容量
     *
     * @var int
     */
    protected int $capacity;

    /**
     * 大小
     *
     * @var int
     */
    protected int $size = 0;

    /**
     * @param DatabaseConfig $config
     */
    public function __construct(protected DatabaseConfig $config)
    {
        $this->pool = new Channel($this->capacity = $config->getPoolSize());
        if ($config->isAutofill()) {
            $this->fill();
        }
    }

    /**
     * 取
     *
     * @return mixed
     */
    public function get()
    {
        $name = $this->config->getName();
        $key  = Connection::class;
        // TODO 连接出错
        if (!Context::has($key)) {
            $connection = new Connection();
            if ($this->size < $this->capacity) {
                $PDO = $this->create();
                $this->size++;
            } else {
                $PDO = $this->pool->pop(3);
            }
            $connection->offsetSet($name, [
                'pool' => $this,
                'item' => $PDO,
            ]);
            Context::put($key, $connection);
        }
        return Context::get($key)[$name]['item'];
    }

    /**
     * @return PDO
     */
    protected function create()
    {
        return new PDO($this->config->getDsn(), $this->config->getUser(), $this->config->getPassword(), $this->config->getOptions());
    }

    /**
     * 归还连接，如果连接不能使用则归还null
     *
     * @param $PDO
     */
    public function put($PDO)
    {
        if (is_null($PDO)) {
            $this->size--;
        } else if (!$this->pool->isFull()) {
            $this->pool->push($PDO);
        }
    }

    /**
     * 填充连接池
     */
    public function fill()
    {
        for ($i = 0; $i < $this->capacity; $i++) {
            $this->put($this->create());
        }
        $this->size = $this->capacity;
    }
}