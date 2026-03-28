<?php

namespace YlsIdeas\CockroachDb;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\PDO\PostgresDriver;
use Illuminate\Database\PostgresConnection;
use PDOException;
use Throwable;
use Illuminate\Filesystem\Filesystem;
use YlsIdeas\CockroachDb\Builder\CockroachDbBuilder as DbBuilder;
use YlsIdeas\CockroachDb\Processor\CockroachDbProcessor as DbProcessor;
use YlsIdeas\CockroachDb\Query\CockroachGrammar as QueryGrammar;
use YlsIdeas\CockroachDb\Schema\CockroachDbGrammar as SchemaGrammar;
use YlsIdeas\CockroachDb\Schema\CockroachSchemaState as SchemaState;

class CockroachDbConnection extends PostgresConnection implements ConnectionInterface
{
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    public function getSchemaBuilder(): DbBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new DbBuilder($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new SchemaState($this, $files, $processFactory);
    }

    protected function getDefaultPostProcessor()
    {
        return new DbProcessor();
    }

    /**
     * CockroachDB implicitly commits DDL statements, which causes
     * "There is no active transaction" errors when Laravel tries
     * to commit the wrapping transaction. We override both commit paths.
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (Throwable $e) {
                $this->handleTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            $levelBeingCommitted = $this->transactions;

            try {
                if ($this->transactions == 1) {
                    $this->fireConnectionEvent('committing');

                    try {
                        $this->getPdo()->commit();
                    } catch (PDOException $e) {
                        if (! str_contains($e->getMessage(), 'There is no active transaction')) {
                            throw $e;
                        }
                    }
                }

                $this->transactions = max(0, $this->transactions - 1);
            } catch (Throwable $e) {
                $this->handleCommitTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            $this->transactionsManager?->commit(
                $this->getName(),
                $levelBeingCommitted,
                $this->transactions
            );

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    public function commit()
    {
        try {
            parent::commit();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'There is no active transaction')) {
                $this->transactions = max(0, $this->transactions - 1);

                return;
            }

            throw $e;
        }
    }

    protected function getDoctrineDriver()
    {
        return new PostgresDriver();
    }
}
