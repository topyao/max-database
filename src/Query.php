<?php

declare(strict_types=1);

/**
 * This file is part of MaxPHP.
 *
 * @link     https://github.com/marxphp
 * @license  https://github.com/marxphp/max/blob/master/LICENSE
 */

namespace Max\Database;

use Closure;
use Max\Database\Contracts\ConnectorInterface;
use Max\Database\Contracts\QueryInterface;
use Max\Database\Events\QueryExecuted;
use Max\Database\Query\Builder;
use PDO;
use PDOException;
use Psr\EventDispatcher\EventDispatcherInterface;

class Query implements QueryInterface
{
    protected PDO $PDO;

    /**
     * @param ConnectorInterface            $connector
     * @param null|EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        protected ConnectorInterface $connector,
        protected ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->PDO = $this->connector->get();
    }

    /**
     * @return false|\PDOStatement
     */
    public function statement(string $query, array $bindings = [])
    {
        try {
            $startTime    = microtime(true);
            $PDOStatement = $this->PDO->prepare($query);
            foreach ($bindings as $key => $value) {
                $PDOStatement->bindValue(
                    is_string($key) ? $key : $key + 1,
                    $value,
                    is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
                );
            }
            $PDOStatement->execute();
            $this->eventDispatcher?->dispatch(
                new QueryExecuted($query, $bindings, microtime(true) - $startTime)
            );
            return $PDOStatement;
        } catch (PDOException $PDOException) {
            throw new PDOException(
                $PDOException->getMessage() . sprintf(' (SQL: %s)', $query),
                (int) $PDOException->getCode(),
                $PDOException->getPrevious()
            );
        }
    }

    public function getPDO(): PDO
    {
        return $this->PDO;
    }

    /**
     * @param ...$args
     */
    public function select(string $query, array $bindings = [], int $mode = PDO::FETCH_ASSOC, ...$args): bool|array
    {
        return $this->statement($query, $bindings)->fetchAll($mode, ...$args);
    }

    /**
     * @param ...$args
     *
     * @return mixed
     */
    public function selectOne(string $query, array $bindings = [], int $mode = PDO::FETCH_ASSOC, ...$args)
    {
        return $this->statement($query, $bindings)->fetch($mode, ...$args);
    }

    /**
     * @return Builder
     */
    public function table(string $table, ?string $alias = null)
    {
        return (new Builder($this))->from($table, $alias);
    }

    public function update(string $query, array $bindings = []): int
    {
        return $this->statement($query, $bindings)->rowCount();
    }

    public function delete(string $query, array $bindings = []): int
    {
        return $this->statement($query, $bindings)->rowCount();
    }

    public function insert(string $query, array $bindings = [], ?string $id = null): bool|string
    {
        $this->statement($query, $bindings);
        return $this->getPDO()->lastInsertId($id);
    }

    /**
     * @return bool
     */
    public function begin()
    {
        return $this->PDO->beginTransaction();
    }

    /**
     * @return bool
     */
    public function commit()
    {
        return $this->PDO->commit();
    }

    /**
     * @return bool
     */
    public function rollBack()
    {
        return $this->PDO->rollBack();
    }

    /**
     * @throws \Throwable
     * @return mixed
     */
    public function transaction(Closure $transaction)
    {
        $this->begin();
        try {
            $result = ($transaction)($this);
            $this->commit();
            return $result;
        } catch (\Throwable $throwable) {
            $this->rollBack();
            throw $throwable;
        }
    }
}
