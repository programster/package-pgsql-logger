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
    private readonly Connection $m_connection;
    private readonly string $m_logTableName;
    private readonly string $m_logLevelEnumName;


    /**
     * Creates a database logger using the provided pgsql connection and table name
     * @param Connection $connection - the connection from pg_connect
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

        if ($this->doesLogTableEnumExist() === false) { $this->createLogsTableEnum(); }
        if ($this->doesLogsTableExist()    === false) { $this->createLogsTable();     }
    }


    public function doesLogTableEnumExist() : bool
    {
        $query = "select exists (select 1 from pg_type where typname = $1)";
        $result = $this->runQuery($query, [$this->m_logLevelEnumName], "Failed to check if the log level type exists.");
        $row = pg_fetch_array($result);
        return $row['exists'] === 't';
    }


    private function doesLogsTableExist() : bool
    {
        $query = "SELECT EXISTS(
            SELECT *
            FROM information_schema.tables
            WHERE
              table_schema = 'public' AND table_name = $1
        ) as exists";

        $result = $this->runQuery($query, [$this->m_logTableName], "Failed to check if the logs table exists.");
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

        $this->runQuery($createTypeQuery, [], "Failed to create the log level type.");
    }

    private function createLogsTable() : void
    {
        $createLogsTableQuery =
            "CREATE TABLE " . pg_escape_identifier($this->m_connection, $this->m_logTableName) . " (
                id UUID NOT NULL,
                message TEXT NOT NULL,
                level " . pg_escape_identifier($this->m_connection, $this->m_logLevelEnumName) . " NOT NULL,
                context JSONB NOT NULL,
                created_at DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (id)
            )";

        $this->runQuery($createLogsTableQuery, [], "Failed to create the logs table.");
        $indexQuery = "CREATE INDEX ON " . pg_escape_identifier($this->m_connection, $this->m_logTableName) . ' ("created_at")';
        $this->runQuery($indexQuery, [], "Failed to create index on logging table.");
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
            $this->generateUuid(),
            (string)$message,
            $logLevelEnum->value,
            $contextString,
            microtime(true),
        );

        $query =
            "INSERT INTO " . pg_escape_identifier($this->m_connection, $this->m_logTableName)
            . " (id, message, level, context, created_at) VALUES ($1, $2, $3, $4, $5)";

        $this->runQuery($query, $params, "Failed to insert log into database.");
    }


    /**
     * Runs a query against the database, transparently recovering from a lost connection.
     *
     * If the database has been restarted (or the connection otherwise dropped) since the connection was created,
     * libpq marks the connection as bad and every subsequent query on it will fail, even once the database is
     * back up. PHP only auto-resets persistent connections (and only if pgsql.auto_reset_persistent is enabled),
     * so we detect the bad connection ourselves, reset it, and retry the query once.
     *
     * @param string $query - the query to run, using $1, $2... placeholders for any parameters.
     * @param array $params - the parameter values for the placeholders in the query.
     * @param string $errorMessage - the message for the exception thrown if the query ultimately fails.
     * @return Result - the result of the query.
     * @throws \Exception - if the query fails, or the connection could not be re-established.
     */
    private function runQuery(string $query, array $params, string $errorMessage) : Result
    {
        if (pg_connection_status($this->m_connection) !== PGSQL_CONNECTION_OK)
        {
            @pg_connection_reset($this->m_connection);
        }

        // Suppress the warning so that frameworks which convert warnings to exceptions don't prevent us retrying.
        $result = @pg_query_params($this->m_connection, $query, $params);

        if
        (
            $result === false
            && pg_connection_status($this->m_connection) !== PGSQL_CONNECTION_OK
            && @pg_connection_reset($this->m_connection)
        )
        {
            $result = @pg_query_params($this->m_connection, $query, $params);
        }

        if ($result === false)
        {
            throw new \Exception($errorMessage . " " . pg_last_error($this->m_connection));
        }

        return $result;
    }


    /**
     * Generates a time-ordered UUIDv7, which keeps inserts into the primary key index efficient.
     * @return string - the generated UUID
     */
    private function generateUuid() : string
    {
        return Uuid::uuid7()->toString();
    }
}
