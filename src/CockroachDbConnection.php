<?php

namespace YlsIdeas\CockroachDb;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\PDO\PostgresDriver;
use Illuminate\Database\PostgresConnection;
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
        return $this->withTablePrefix(new QueryGrammar($this));
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
        return $this->withTablePrefix(new SchemaGrammar($this));
    }

    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new SchemaState($this, $files, $processFactory);
    }

    protected function getDefaultPostProcessor()
    {
        return new DbProcessor();
    }

    protected function getDoctrineDriver()
    {
        return new PostgresDriver();
    }
}
