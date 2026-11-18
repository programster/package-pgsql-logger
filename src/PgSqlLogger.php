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
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;
use Psr\Clock\ClockInterface;

final class PgSqlLogger extends AbstractLogger
{
    private readonly Connection $m_connection;
    private readonly string $m_logTableName;
    private readonly string $m_logLevelEnumName;
    private readonly ClockInterface $m_clock;


    /**
     * Creates a database logger using the provided pgsql connection and table name
     * @param Connection $connection - the connection from pg_connect
     * @param string $logTable - the name of the table that will store the logs
     * @param string $logLevelEnumName - the name of the enum in the table that represents the log level.
     * @param ClockInterface|null $clock - an optional PSR-20 clock to timestamp the logs' created_at with, such as
     * an application's fake/offset "system clock". If not provided, the real current time is used. Note that the
     * clock only affects created_at, and the log's id is always generated from the real time.
     * @return void - (constructor)
     * @throws \Exception - if the log level type or logs table don't exist and couldn't be created.
     */
    public function __construct(
        Connection $connection,
        string $logTable = "log",
        string $logLevelEnumName = "log_level",
        ?ClockInterface $clock = null
    )
    {
        $this->m_connection = $connection;
        $this->m_logTableName = $logTable;
        $this->m_logLevelEnumName = $logLevelEnumName;

        /*
         * Create the clock for the user if one was not provided. E.g. just
         * uses normal time as one would expect.
         */
        if ($clock === null)
        {
            $clock = new class implements ClockInterface {
                public function now() : \DateTimeImmutable
                {
                    return new \DateTimeImmutable('now');
                }
            };
        }

        $this->m_clock = $clock;
        if ($this->doesLogTableEnumExist() === false) { $this->createLogsTableEnum(); }
        if ($this->doesLogsTableExist()    === false) { $this->createLogsTable();     }
    }


    /**
     * Checks whether the enum type for the log level column already exists in the database.
     * @return bool - true if the type exists, false otherwise.
     * @throws \Exception - if the query fails.
     */
    public function doesLogTableEnumExist() : bool
    {
        $result = $this->runQuery(
            "SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = $1)",
            [$this->m_logLevelEnumName],
            "Failed to check if the log level type exists."
        );

        $row = pg_fetch_array($result);
        return $row['exists'] === 't';
    }


    /**
     * Checks whether the logs table already exists in the public schema.
     * @return bool - true if the table exists, false otherwise.
     * @throws \Exception - if the query fails.
     */
    private function doesLogsTableExist() : bool
    {
        $query = "SELECT EXISTS(
            SELECT *
            FROM information_schema.tables
            WHERE
              table_schema = 'public' AND table_name = $1
        ) as exists";

        $result = $this->runQuery(
            $query,
            [$this->m_logTableName],
            "Failed to check if the logs table exists."
        );

        $resultArray = pg_fetch_array($result);
        return $resultArray['exists'] === "t";
    }


    /**
     * Creates the enum type for the log level column, with a value for each of the PSR-3 log levels.
     * @return void
     * @throws \Exception - if the type could not be created.
     */
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


    /**
     * Creates the logs table, along with an index on the created_at column. The log level enum type must already
     * exist.
     * @return void
     * @throws \Exception - if the table or index could not be created.
     */
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
     * @param mixed $level - a LogLevel enum, a case-insensitive PSR-3 level string (e.g. "info"), or an integer
     * from 0 (debug) to 7 (emergency).
     * @param string|\Stringable $message - the message to log, e.g "failed to connect to db"
     * @param array $context - name value pairs providing context to the message, e.g. ["dbname" => "yolo"]
     *
     * @return void
     * @throws ExceptionInvalidLogLevel - if the level is not valid.
     * @throws \Exception - if the log could not be inserted into the database.
     */
    public function log($level, string|\Stringable $message, array $context = []) : void
    {
        $logLevelEnum = $this->convertLogLevelMixedVariable($level);
        $contextString = json_encode($context, JSON_UNESCAPED_SLASHES);

        // A unix timestamp (which is always UTC, whatever the clock's timezone) with microseconds. It's passed
        // as a string rather than a float to avoid any precision loss, and the DECIMAL(15,4) column rounds it.
        $createdAt = $this->m_clock->now()->format('U.u');

        // The id is deliberately generated from the REAL time rather than the clock, so that ids are always in
        // real insertion order, even if the clock is moved backwards or forwards. Applications rely on this to
        // order and paginate logs by id.
        $logId = PgsqlLib::generateUuid(UuidVersion::V7);

        $params = array(
            $logId,
            (string)$message,
            $logLevelEnum->value,
            $contextString,
            $createdAt,
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
}
