<?php

/*
 * This class is a logger which logs to a mysqli database connection.
 * Requires a database table with at LEAST the following fields
 *  - message - (varchar/text)
 *  - priority - (int)
 *  - context - (full text for json)
 */

declare(strict_types = 1);

namespace Programster\PgsqlLogger;


use PgSql\Connection;
use PgSql\Result;
use Programster\Log\AbstractLogger;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Ramsey\Uuid\Uuid;

final class PgSqlLogger extends AbstractLogger
{
    protected Connection $m_connection;

    protected string $m_logLevelEnumName;

    protected string $m_logTableName;


    /**
     * Creates a database logger using the provided pgsql connection and table name
     * @param $connection - the connection resource from pg_connect
     * @param string $logTable - the name of the table that will store the logs
     * @param string $logLevelEnumName - the name of the enum in the table that represents the log level.
     * @return void - (constructor)
     */
    public function __construct(
        Connection $connection,
        string $logTable = "log",
        string $logLevelEnumName = "log_level"
    )
    {
        $this->m_connection = $connection;
        $this->m_logTableName = $logTable;
        $this->m_logLevelEnumName = $logLevelEnumName;

        if ($this->doesLogTableEnumExist() === false)
        {
            $this->createLogsTableEnum();
        }

        if ($this->doesLogsTableExist() === false)
        {
            $this->createLogsTable();
        }
    }


    public function doesLogTableEnumExist() : bool
    {
        $query = "select exists (select 1 from pg_type where typname = '{$this->m_logLevelEnumName}')";
        $result = pg_query($this->m_connection, $query);
        $row = pg_fetch_array($result);
        return $row['exists'] === 't';
    }


    private function doesLogsTableExist() : bool
    {
        $query = "SELECT EXISTS(
            SELECT *
            FROM information_schema.tables
            WHERE
              table_schema = 'public' AND table_name = '{$this->m_logTableName}'
        ) as exists";


        $result = pg_query($this->m_connection, $query);

        /* @var $result Result */
        $resultArray = pg_fetch_array($result);
        return $resultArray['exists'] === "t";
    }


    private function createLogsTableEnum() : void
    {
        $createTypeQuery =
            "CREATE TYPE " . pg_escape_identifier($this->m_connection, $this->m_logLevelEnumName) . " AS ENUM ("
            . "'debug', "
            . "'info', "
            . "'notice', "
            . "'warning', "
            . "'error', "
            . "'critical', "
            . "'alert', "
            . "'emergency'"
            . ");";

        $createTypeResult = pg_query($this->m_connection, $createTypeQuery);

        if ($createTypeResult === false)
        {
            throw new \Exception("Failed to create the log level type.");
        }
    }

    private function createLogsTable() : void
    {
        $createLogsTableQuery =
            "CREATE TABLE " . pg_escape_identifier($this->m_connection, $this->m_logTableName) . " (
                id UUID NOT NULL,
                message TEXT NOT NULL,
                level {$this->m_logLevelEnumName} NOT NULL,
                context JSONB NOT NULL,
                created_at DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (id)
            )";

        $createResult = pg_query($this->m_connection, $createLogsTableQuery);

        if ($createResult === FALSE)
        {
            throw new \Exception("Failed to create the logs table.");
        }

        $indexQuery = "CREATE INDEX ON " . pg_escape_identifier($this->m_connection, $this->m_logTableName) . ' ("created_at")';
        $indexResult = pg_query($this->m_connection, $indexQuery);

        if ($indexResult === FALSE)
        {
            throw new \Exception("Failed to create index on logging table.");
        }
    }


    /**
     * Logs with an arbitrary level.
     *
     * @param int $level - the priority of the message - see LogLevel class
     * @param string $message -  the message of the error, e.g "failed to connect to db"
     * @param array $context - name value pairs providing context to error, e.g. "dbname => "yolo")
     *
     * @return void
     * @throws ExceptionInvalidLogLevel
     */
    public function log($level, $message, array $context = array()) : void
    {
        $logLevelEnum = $this->convertLogLevelMixedVariable($level);
        $contextString = json_encode($context, JSON_UNESCAPED_SLASHES);

        $params = array(
            'id' => pg_escape_string($this->m_connection, $this->generateUuid()),
            'message'  => pg_escape_string($this->m_connection, $message),
            'level' => $logLevelEnum->value,
            'context'  => pg_escape_string($this->m_connection, $contextString),
            'created_at' => microtime(true),
        );

        $query =
            "INSERT INTO " . pg_escape_identifier($this->m_connection, $this->m_logTableName) . " (id, message, level, context, created_at)" .
            " VALUES ('{$params['id']}','{$params['message']}', '{$params['level']}', '{$params['context']}', {$params['created_at']})";

        $result = pg_query($this->m_connection, $query);

        if ($result === FALSE)
        {
            $err_msg =
                'Failed to insert log into database: ' . PHP_EOL .
                'Query: ' . $query . PHP_EOL;

            throw new \Exception($err_msg);
        }
    }


    /**
     * Generates a sequential UUID4.
     * @staticvar type $factory
     * @return string - the generated UUID
     */
    private function generateUuid() : string
    {
        static $factory = null;

        if ($factory === null)
        {
            $factory = new \Ramsey\Uuid\UuidFactory();

            $generator = new \Ramsey\Uuid\Generator\CombGenerator(
                $factory->getRandomGenerator(),
                $factory->getNumberConverter()
            );

            $codec = new \Ramsey\Uuid\Codec\TimestampFirstCombCodec($factory->getUuidBuilder());

            $factory->setRandomGenerator($generator);
            $factory->setCodec($codec);
        }

        Uuid::setFactory($factory);
        $uuidString1 = Uuid::uuid4()->toString();
        return $uuidString1;
    }
}
