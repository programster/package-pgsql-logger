<?php

/*
 * The throwaway database the tests use, given by the TEST_DB_HOST, TEST_DB_PORT (default 5432), TEST_DB_NAME,
 * TEST_DB_USER and TEST_DB_PASSWORD environment variables. The tests only create and drop schemas, tables and types
 * whose names start with "logger_test_" (and the docs' examples, the default "log" table and "log_level" type, and the
 * "app" schema), and a "logger_test_user" role.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLogger\Testing;


use PgSql\Connection;


final readonly class TestDatabase
{
    public const PREFIX = 'logger_test_';


    /**
     * Connect to the test database, with a connection of its own.
     * @return Connection
     * @throws \RuntimeException - if the settings aren't given, or it can't connect.
     */
    public static function connect() : Connection
    {
        $host = getenv('TEST_DB_HOST');
        $name = getenv('TEST_DB_NAME');

        if ($host === false || $host === '' || $name === false || $name === '')
        {
            throw new \RuntimeException("Set TEST_DB_HOST, TEST_DB_NAME, TEST_DB_USER and TEST_DB_PASSWORD.");
        }

        $settings = [
            'host' => $host,
            'port' => (string)(getenv('TEST_DB_PORT') ?: 5432),
            'dbname' => $name,
            'user' => (string)getenv('TEST_DB_USER'),
            'password' => (string)getenv('TEST_DB_PASSWORD'),
        ];

        $parts = [];

        foreach ($settings as $key => $value)
        {
            $parts[] = $key . "='" . addcslashes($value, "'\\") . "'";
        }

        $connection = pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);

        if ($connection === false)
        {
            throw new \RuntimeException("Couldn't connect to the test database.");
        }

        return $connection;
    }


    /**
     * Get the shared connection the tests in this process use.
     * @return Connection
     */
    public static function get() : Connection
    {
        /** @var Connection|null $connection */
        static $connection = null;

        if ($connection === null)
        {
            $connection = self::connect();
        }

        return $connection;
    }


    /**
     * Run a query on the shared connection, throwing if it fails.
     *
     * @param string $query
     *
     * @param list<string|int|null> $params - the values for $1, $2 and so on.
     *
     * @return \PgSql\Result
     *
     * @throws \RuntimeException - if the query fails.
     */
    public static function query(string $query, array $params = []) : \PgSql\Result
    {
        $result = @pg_query_params(self::get(), $query, $params);

        if ($result === false)
        {
            throw new \RuntimeException("Test query failed: " . pg_last_error(self::get()) . " in: {$query}");
        }

        return $result;
    }


    /**
     * Run several statements, separated by semicolons (e.g. a migration's SQL), on the shared connection, as
     * query() can only run one.
     *
     * @param string $sql
     *
     * @return void
     *
     * @throws \RuntimeException - if they fail.
     */
    public static function runStatements(string $sql) : void
    {
        if (@pg_query(self::get(), $sql) === false)
        {
            throw new \RuntimeException("Test statements failed: " . pg_last_error(self::get()));
        }
    }


    /**
     * Fetch every row a query returns.
     *
     * @param string $query
     *
     * @param list<string|int|null> $params
     *
     * @return list<array<string, string|null>>
     */
    public static function fetchRows(string $query, array $params = []) : array
    {
        $rows = [];
        $result = self::query($query, $params);

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            $namedRow = [];

            foreach ($row as $column => $value)
            {
                $namedRow[(string)$column] = $value;
            }

            $rows[] = $namedRow;
        }

        return $rows;
    }


    /**
     * Drop every schema, table and type the tests (and the docs' examples) create, so each starts from nothing.
     * @return void
     */
    public static function dropAll() : void
    {
        $prefix = self::PREFIX;

        $findTables = <<<SQL
            SELECT quote_ident(table_name) AS name FROM information_schema.tables
            WHERE table_schema = 'public' AND (starts_with(table_name, '{$prefix}') OR table_name = 'log')
            SQL;

        foreach (self::fetchRows($findTables) as $row)
        {
            self::query("DROP TABLE {$row['name']}");
        }

        $findSchemas = "SELECT quote_ident(nspname) AS name FROM pg_namespace"
            . " WHERE starts_with(nspname, '{$prefix}') OR nspname = 'app'";

        foreach (self::fetchRows($findSchemas) as $row)
        {
            self::query("DROP SCHEMA {$row['name']} CASCADE");
        }

        $findTypes = <<<SQL
            SELECT 'public.' || quote_ident(typname) AS name FROM pg_type
            WHERE typtype = 'e' AND typnamespace = 'public'::regnamespace
                AND (starts_with(typname, '{$prefix}') OR typname = 'log_level')
            SQL;

        foreach (self::fetchRows($findTypes) as $row)
        {
            self::query("DROP TYPE {$row['name']}");
        }
    }
}
