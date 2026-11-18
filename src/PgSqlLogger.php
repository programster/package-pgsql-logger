<?php

/*
 * A PSR-3 logger that logs to a PostgreSQL table, in any schema. Create the table (and an enum type for its levels)
 * first, with PgSqlLogger::createTables(), or the SQL from getCreateTablesSql() in the application's own migrations.
 * It has these columns:
 *  - id - a UUIDv7, so ordering by it gives the order the logs were written in.
 *  - message - the message (text).
 *  - level - the PSR-3 level, e.g. "warning" (the enum type).
 *  - context - the context (jsonb).
 *  - created_at - when it was logged, as a unix timestamp with 4 decimal places (decimal(15,4)), from the clock.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLogger;


use PgSql\Connection;
use PgSql\Result;
use Programster\Log\AbstractLogger;
use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;
use Psr\Clock\ClockInterface;


final class PgSqlLogger extends AbstractLogger
{
    public const DEFAULT_TABLE = 'log';
    public const DEFAULT_LEVEL_TYPE = 'log_level';
    public const DEFAULT_SCHEMA = 'public';

    // A plain lowercase identifier, which needs no escaping, and can't be mistaken for another in a different case.
    private const NAME_PATTERN = '#^[a-z_][a-z0-9_]*$#D';

    // PostgreSQL's longest identifier, and the longest table name that leaves room for its index's "_created_at_idx".
    private const MAX_NAME_LENGTH = 63;
    private const MAX_TABLE_NAME_LENGTH = 48;

    // What's put in place of each character PostgreSQL can't store: invalid UTF-8, and NUL.
    private const REPLACEMENT_CHARACTER = "\u{FFFD}";

    // A JSON-escaped NUL (\u0000), only where its backslash isn't itself escaped (i.e. after an even number of them).
    private const ESCAPED_NUL_PATTERN = '/(?<!\\\\)((?:\\\\\\\\)*)\\\\u0000/';

    private readonly Connection $m_connection;
    private readonly string $m_logTableName;
    private readonly string $m_schema;
    private readonly ClockInterface $m_clock;


    /**
     * Create a logger that logs to the given table, which must already exist (see createTables()).
     *
     * @param Connection $connection - the connection from pg_connect(). If it's lost (e.g. the database restarts), the
     * logger resets it, so any other code sharing it is reconnected too.
     *
     * @param string $logTable - the name of the table to log to, as given to createTables(). Defaults to "log".
     *
     * @param ClockInterface|null $clock - an optional PSR-20 clock to timestamp the logs' created_at with, such as
     * an application's fake/offset "system clock". If not provided, the real current time is used. The clock only
     * affects created_at, and the log's id is always generated from the real time.
     *
     * @param string $schema - the schema the table is in, as given to createTables(). Defaults to "public".
     *
     * @throws \InvalidArgumentException - if the table's name isn't a lowercase identifier of up to 48 characters, or
     * the schema's of up to 63.
     *
     * @throws \Exception - if the table doesn't exist, or the query to check it fails.
     */
    public function __construct(
        Connection $connection,
        string $logTable = self::DEFAULT_TABLE,
        ?ClockInterface $clock = null,
        string $schema = self::DEFAULT_SCHEMA
    )
    {
        self::assertValidName($logTable, "log table", self::MAX_TABLE_NAME_LENGTH);
        self::assertValidName($schema, "schema", self::MAX_NAME_LENGTH);
        $this->m_connection = $connection;
        $this->m_logTableName = $logTable;
        $this->m_schema = $schema;

        // Without a clock, use the real time, as one would expect.
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

        if (!$this->doesLogTableExist())
        {
            throw new \Exception(
                "The \"{$logTable}\" log table doesn't exist in the \"{$schema}\" schema. Create it with "
                . "PgSqlLogger::createTables(), or the SQL from PgSqlLogger::getCreateTablesSql() in your migrations, "
                . "giving them the same schema."
            );
        }
    }


    /**
     * Get the SQL that creates the schema, the log table, its level type and its index, for an application to run in
     * its own migrations. Everything is created only if it doesn't exist, so it's safe to run
     * again, including on a table created by an earlier version of this package.
     *
     * @param string $logTable - the name of the table. Defaults to "log".
     *
     * @param string $logLevelType - the name of the enum type for the table's level column, which is created in the
     * same schema. Defaults to "log_level".
     *
     * @param string $schema - the schema to put them in. Defaults to "public".
     *
     * @return string - several statements, separated by semicolons.
     *
     * @throws \InvalidArgumentException - if a name isn't a lowercase identifier (of up to 48 characters for the table,
     * or 63 for the type and the schema).
     */
    public static function getCreateTablesSql(
        string $logTable = self::DEFAULT_TABLE,
        string $logLevelType = self::DEFAULT_LEVEL_TYPE,
        string $schema = self::DEFAULT_SCHEMA
    ) : string
    {
        // The names are checked to be plain lowercase identifiers, so they're safe to quote like this.
        self::assertValidName($logTable, "log table", self::MAX_TABLE_NAME_LENGTH);
        self::assertValidName($logLevelType, "log level type", self::MAX_NAME_LENGTH);
        self::assertValidName($schema, "schema", self::MAX_NAME_LENGTH);
        $table = '"' . $schema . '"."' . $logTable . '"';
        $type = '"' . $schema . '"."' . $logLevelType . '"';
        $index = '"' . $logTable . '_created_at_idx"';

        return <<<SQL
            -- CREATE SCHEMA IF NOT EXISTS needs the CREATE privilege on the database even when the schema exists, which
            -- a user that only logs may not have, so it's only run when the schema doesn't exist.
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = '{$schema}') THEN
                    CREATE SCHEMA "{$schema}";
                END IF;
            END
            $$;

            -- CREATE TYPE has no IF NOT EXISTS, so an existing type is skipped by catching the error instead.
            DO $$
            BEGIN
                CREATE TYPE {$type} AS ENUM (
                    'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'
                );
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END
            $$;

            CREATE TABLE IF NOT EXISTS {$table} (
                id UUID NOT NULL,
                message TEXT NOT NULL,
                level {$type} NOT NULL,
                context JSONB NOT NULL,
                created_at DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (id)
            );

            -- Named as PostgreSQL named the index that earlier versions created without a name.
            CREATE INDEX IF NOT EXISTS {$index} ON {$table} (created_at);
            SQL;
    }


    /**
     * Create the schema, the log table, its level type and its index, if they don't exist, e.g. when the application
     * starts. See getCreateTablesSql().
     *
     * @param Connection $connection - the database.
     *
     * @param string $logTable - the name of the table. Defaults to "log".
     *
     * @param string $logLevelType - the name of the enum type for the table's level column, which is created in the
     * same schema. Defaults to "log_level".
     *
     * @param string $schema - the schema to put them in. Defaults to "public".
     *
     * @return void
     *
     * @throws \InvalidArgumentException - if a name isn't a lowercase identifier (of up to 48 characters for the table,
     * or 63 for the type and the schema).
     *
     * @throws \Exception - if they can't be created, with PostgreSQL's reason.
     */
    public static function createTables(
        Connection $connection,
        string $logTable = self::DEFAULT_TABLE,
        string $logLevelType = self::DEFAULT_LEVEL_TYPE,
        string $schema = self::DEFAULT_SCHEMA
    ) : void
    {
        $sql = self::getCreateTablesSql($logTable, $logLevelType, $schema);

        if (@pg_query($connection, $sql) === false)
        {
            throw new \Exception("Failed to create the log table. " . pg_last_error($connection));
        }
    }


    /**
     * Log a message at any level.
     *
     * Invalid UTF-8 and NUL characters, which PostgreSQL can't store, are replaced with U+FFFD (the replacement
     * character), in both the message and the context, so a log isn't lost because of them.
     *
     * @param mixed $level - a LogLevel enum, a case-insensitive PSR-3 level string (e.g. "info"), or an integer
     * from 0 (debug) to 7 (emergency).
     *
     * @param string|\Stringable $message - the message to log, e.g "failed to connect to db".
     *
     * @param array<mixed> $context - name value pairs providing context to the message, e.g. ["dbname" => "yolo"].
     * An exception (e.g. in the "exception" key, as PSR-3 recommends) is stored as its class, message, code, file,
     * line, trace and previous exception.
     *
     * @return void
     *
     * @throws ExceptionInvalidLogLevel - if the level is not valid.
     * @throws ExceptionFailedToEncodeLog - if the context can't be JSON encoded, e.g. it holds NAN.
     * @throws \Exception - if the log could not be inserted into the database.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []) : void
    {
        $logLevelEnum = $this->convertLogLevelMixedVariable($level);
        $contextJson = self::replaceEscapedNuls($this->encodeJson($context, $message));

        // A unix timestamp (which is always UTC, whatever the clock's timezone) with microseconds. It's passed
        // as a string rather than a float to avoid any precision loss, and the DECIMAL(15,4) column rounds it.
        $createdAt = $this->m_clock->now()->format('U.u');

        // The id is deliberately generated from the REAL time rather than the clock, so that ids are always in
        // real insertion order, even if the clock is moved backwards or forwards. Applications rely on this to
        // order and paginate logs by id.
        $logId = PgsqlLib::generateUuid(UuidVersion::V7);

        $params = [
            $logId,
            self::makeStorable((string)$message),
            $logLevelEnum->value,
            $contextJson,
            $createdAt,
        ];

        $query = "INSERT INTO {$this->getQuotedTableName()} (id, message, level, context, created_at)"
            . " VALUES ($1, $2, $3, $4, $5)";

        $this->runQuery($query, $params, "Failed to insert log into database.");
    }


    /**
     * Make a string storable in a text column: replace invalid UTF-8 and NUL characters with U+FFFD.
     * @param string $text
     * @return string
     */
    private static function makeStorable(string $text) : string
    {
        // json_encode() replaces the invalid UTF-8, as it needs no extension (unlike mb_scrub()), and can't fail on a
        // string once invalid UTF-8 is substituted.
        $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
        $validUtf8 = json_decode(json_encode($text, $flags), false, 512, JSON_THROW_ON_ERROR);

        if (!is_string($validUtf8))
        {
            throw new \LogicException("Replacing invalid UTF-8 in a string gave a " . get_debug_type($validUtf8) . ".");
        }

        return str_replace("\0", self::REPLACEMENT_CHARACTER, $validUtf8);
    }


    /**
     * Replace the NULs in JSON (escaped as \u0000, which a jsonb column rejects) with U+FFFD, escaped as �,
     * leaving text that only looks like one (an escaped backslash, followed by "u0000") as it is.
     *
     * @param string $json
     *
     * @return string
     */
    private static function replaceEscapedNuls(string $json) : string
    {
        return (string)preg_replace(self::ESCAPED_NUL_PATTERN, '$1\\ufffd', $json);
    }


    /**
     * Check a table or type's name is a plain lowercase identifier, which needs no escaping, and isn't so long that
     * PostgreSQL would cut it short.
     *
     * @param string $name
     *
     * @param string $what - what it names, for the message, e.g. "log table".
     *
     * @param int $maxLength
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValidName(string $name, string $what, int $maxLength) : void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) > $maxLength)
        {
            throw new \InvalidArgumentException(
                "The {$what}'s name must be 1 to {$maxLength} lowercase letters, digits and underscores, not starting "
                . "with a digit, not \"{$name}\"."
            );
        }
    }


    /**
     * Check whether the log table exists, in its schema.
     * @return bool
     * @throws \Exception - if the query fails.
     */
    private function doesLogTableExist() : bool
    {
        $result = $this->runQuery(
            "SELECT to_regclass($1) IS NOT NULL",
            [$this->getQuotedTableName()],
            "Failed to check if the log table exists."
        );

        return (pg_fetch_result($result, 0, 0) === 't');
    }


    /**
     * Get the table's name, qualified by its schema, and quoted for a query, e.g. "public"."log". The names are
     * checked to be plain lowercase identifiers, so they're safe to quote like this.
     *
     * @return string
     */
    private function getQuotedTableName() : string
    {
        return '"' . $this->m_schema . '"."' . $this->m_logTableName . '"';
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
     *
     * @param list<string> $params - the parameter values for the placeholders in the query.
     *
     * @param string $errorMessage - the message for the exception thrown if the query ultimately fails.
     *
     * @return Result - the result of the query.
     *
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

        if (
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
