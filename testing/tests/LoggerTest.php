<?php

/*
 * Smoke tests for PgSqlLogger: creating its table and type (or getting the SQL for them), logging at each level, what
 * it stores, the clock, the characters PostgreSQL can't store, the mistakes it rejects, and reconnecting after the
 * connection is lost.
 * These are run by testing/main.php, which provides $smoke.
 */

use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\LogLevel;
use Programster\PgsqlLogger\PgSqlLogger;
use Programster\PgsqlLogger\Testing\FakeClock;
use Programster\PgsqlLogger\Testing\SmokeTest;
use Programster\PgsqlLogger\Testing\TestDatabase;

/**
 * Create a logger for a test, with a table and type of its own, named after the test, creating them first.
 * @param string $name - e.g. "levels", for the table logger_test_levels and the type logger_test_levels_level.
 * @param FakeClock|null $clock
 * @return PgSqlLogger
 */
function createTestLogger(string $name, ?FakeClock $clock = null) : PgSqlLogger
{
    $table = TestDatabase::PREFIX . $name;
    PgSqlLogger::createTables(TestDatabase::get(), $table, "{$table}_level");
    return new PgSqlLogger(TestDatabase::get(), $table, $clock);
}


/**
 * Fetch a test's logs, in the order of their IDs.
 * @param string $name - the name the logger was created with.
 * @return list<array<string, string|null>>
 */
function fetchTestLogs(string $name) : array
{
    $table = pg_escape_identifier(TestDatabase::get(), TestDatabase::PREFIX . $name);
    $query = "SELECT id, message, level, context::text AS context, created_at FROM {$table} ORDER BY id";
    return TestDatabase::fetchRows($query);
}


/** @var SmokeTest $smoke */

$createsTable = function () : void {
    $table = TestDatabase::PREFIX . 'creates';
    PgSqlLogger::createTables(TestDatabase::get(), $table, "{$table}_level");

    $columnsQuery = "SELECT column_name, data_type, udt_name FROM information_schema.columns WHERE table_name = $1"
        . " ORDER BY ordinal_position";

    $columns = TestDatabase::fetchRows($columnsQuery, [$table]);

    $expected = [
        ['column_name' => 'id', 'data_type' => 'uuid', 'udt_name' => 'uuid'],
        ['column_name' => 'message', 'data_type' => 'text', 'udt_name' => 'text'],
        ['column_name' => 'level', 'data_type' => 'USER-DEFINED', 'udt_name' => "{$table}_level"],
        ['column_name' => 'context', 'data_type' => 'jsonb', 'udt_name' => 'jsonb'],
        ['column_name' => 'created_at', 'data_type' => 'numeric', 'udt_name' => 'numeric'],
    ];

    SmokeTest::assertSame($expected, $columns, "the table's columns");
    $indexesQuery = "SELECT indexname FROM pg_indexes WHERE tablename = $1 ORDER BY indexname";
    $indexes = array_column(TestDatabase::fetchRows($indexesQuery, [$table]), 'indexname');
    SmokeTest::assertSame(["{$table}_created_at_idx", "{$table}_pkey"], $indexes, "the table's indexes");
    $levels = TestDatabase::fetchRows("SELECT unnest(enum_range(NULL::{$table}_level))::text AS level");
    $expectedLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    SmokeTest::assertSame($expectedLevels, array_column($levels, 'level'), "the level type's values");

    // Creating them again changes nothing, and keeps the logs.
    $logger = new PgSqlLogger(TestDatabase::get(), $table);
    $logger->info("first");
    PgSqlLogger::createTables(TestDatabase::get(), $table, "{$table}_level");
    $logger->info("second");
    SmokeTest::assertSame(['first', 'second'], array_column(fetchTestLogs('creates'), 'message'), "both logs");
    $indexesAfter = array_column(TestDatabase::fetchRows($indexesQuery, [$table]), 'indexname');
    SmokeTest::assertSame($indexes, $indexesAfter, "the indexes, after creating them again");
};

$smoke->run("logger: createTables() creates its table, level type and index, once", $createsTable);

$createsUsingSql = function () : void {
    $table = TestDatabase::PREFIX . 'sql';
    $sql = PgSqlLogger::getCreateTablesSql($table, "{$table}_level");
    $hasCreateTable = str_contains($sql, "CREATE TABLE IF NOT EXISTS \"public\".\"{$table}\"");
    assert($hasCreateTable, "It's the SQL to create the table, in public.");

    // Run as an application's own migration would, twice.
    TestDatabase::runStatements($sql);
    TestDatabase::runStatements($sql);
    $logger = new PgSqlLogger(TestDatabase::get(), $table);
    $logger->notice("from a migration");
    SmokeTest::assertSame(['from a migration'], array_column(fetchTestLogs('sql'), 'message'), "the log");

    $defaultSql = PgSqlLogger::getCreateTablesSql();
    $isDefaultTable = str_contains($defaultSql, 'TABLE IF NOT EXISTS "public"."log"');
    $isDefaultType = str_contains($defaultSql, 'TYPE "public"."log_level"');
    assert($isDefaultTable && $isDefaultType, "By default, it's the log table and log_level type.");
};

$smoke->run("logger: getCreateTablesSql() gives SQL for migrations, which is safe to run again", $createsUsingSql);

$upgradesOldTable = function () : void {
    // A table as 2.x created it, with an index it left PostgreSQL to name.
    $table = TestDatabase::PREFIX . 'old';
    $type = "{$table}_level";
    $levels = "'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'";
    TestDatabase::query("CREATE TYPE {$type} AS ENUM ({$levels})");
    $columns = "id UUID NOT NULL, message TEXT NOT NULL, level {$type} NOT NULL, context JSONB NOT NULL";
    TestDatabase::query("CREATE TABLE {$table} ({$columns}, created_at DECIMAL(15,4) NOT NULL, PRIMARY KEY (id))");
    TestDatabase::query("CREATE INDEX ON {$table} (\"created_at\")");
    PgSqlLogger::createTables(TestDatabase::get(), $table, $type);
    $indexesQuery = "SELECT indexname FROM pg_indexes WHERE tablename = $1 ORDER BY indexname";
    $indexes = array_column(TestDatabase::fetchRows($indexesQuery, [$table]), 'indexname');
    SmokeTest::assertSame(["{$table}_created_at_idx", "{$table}_pkey"], $indexes, "the indexes, without another");
};

$smoke->run("logger: createTables() leaves a table that 2.x created as it is", $upgradesOldTable);

$requiresTable = function () : void {
    $createWithoutTable = function () : void {
        new PgSqlLogger(TestDatabase::get(), TestDatabase::PREFIX . 'missing');
    };

    SmokeTest::expectException(\Exception::class, $createWithoutTable, 'Create it with PgSqlLogger::createTables()');
};

$smoke->run("logger: the constructor throws if the table doesn't exist, saying how to create it", $requiresTable);

$usesSchemas = function () : void {
    $schema = TestDatabase::PREFIX . 'schema';
    $table = TestDatabase::PREFIX . 'in_schema';
    PgSqlLogger::createTables(TestDatabase::get(), $table, "{$table}_level", $schema);
    PgSqlLogger::createTables(TestDatabase::get(), $table, "{$table}_level", $schema);
    PgSqlLogger::createTables(TestDatabase::get(), $table, "{$table}_level");
    (new PgSqlLogger(TestDatabase::get(), $table, schema: $schema))->info("in the schema");
    (new PgSqlLogger(TestDatabase::get(), $table))->info("in public");
    $schemaRows = TestDatabase::fetchRows("SELECT message FROM {$schema}.{$table}");
    SmokeTest::assertSame([['message' => 'in the schema']], $schemaRows, "the schema's table");
    $publicRows = TestDatabase::fetchRows("SELECT message FROM public.{$table}");
    SmokeTest::assertSame([['message' => 'in public']], $publicRows, "public's table");

    $typesQuery = "SELECT n.nspname AS schema FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace"
        . " WHERE t.typname = $1 ORDER BY n.nspname";

    $typeSchemas = array_column(TestDatabase::fetchRows($typesQuery, ["{$table}_level"]), 'schema');
    SmokeTest::assertSame([$schema, 'public'], $typeSchemas, "the schemas each level type is in");

    $createInOtherSchema = function () use ($table) : void {
        new PgSqlLogger(TestDatabase::get(), $table, schema: TestDatabase::PREFIX . 'other');
    };

    SmokeTest::expectException(\Exception::class, $createInOtherSchema, 'in the "logger_test_other" schema');
};

$smoke->run("logger: creates and logs to a table in any schema, separately from public's", $usesSchemas);

$existingSchemaWithoutCreate = function () : void {
    // A role that can create tables in an existing schema, but not create schemas in the database.
    $schema = TestDatabase::PREFIX . 'restricted';
    $database = pg_escape_identifier(TestDatabase::get(), (string)pg_dbname(TestDatabase::get()));
    TestDatabase::runStatements("DROP ROLE IF EXISTS logger_test_user");
    TestDatabase::runStatements("CREATE ROLE logger_test_user LOGIN PASSWORD 'test'");
    TestDatabase::runStatements("REVOKE CREATE ON DATABASE {$database} FROM PUBLIC");
    TestDatabase::runStatements("CREATE SCHEMA {$schema}");
    TestDatabase::runStatements("GRANT USAGE, CREATE ON SCHEMA {$schema} TO logger_test_user");

    try
    {
        TestDatabase::runStatements("SET ROLE logger_test_user");
        PgSqlLogger::createTables(TestDatabase::get(), TestDatabase::PREFIX . 'restricted', schema: $schema);

        $createSchema = function () : void {
            PgSqlLogger::createTables(TestDatabase::get(), 'log', schema: TestDatabase::PREFIX . 'not_allowed');
        };

        SmokeTest::expectException(\Exception::class, $createSchema, 'permission denied');
    }
    finally
    {
        TestDatabase::runStatements("RESET ROLE");
        TestDatabase::runStatements("GRANT CREATE ON DATABASE {$database} TO PUBLIC");
        TestDatabase::runStatements("DROP SCHEMA {$schema} CASCADE");
        TestDatabase::runStatements("DROP ROLE logger_test_user");
    }
};

$smoke->run(
    "logger: createTables() works in an existing schema for a role that can't create schemas",
    $existingSchemaWithoutCreate
);

$rejectsNames = function () : void {
    $badNames = [
        'an uppercase name' => 'Log',
        'a name with a quote' => 'log"; DROP TABLE x; --',
        'a name with a space' => 'my log',
        'a name starting with a digit' => '1log',
        'an empty name' => '',
        'a name with a trailing newline' => "log\n",
        'a schema-qualified name' => 'app.log',
        'a name over 63 characters' => str_repeat('a', 64),
    ];

    foreach ($badNames as $what => $name)
    {
        $attempts = [
            'createTables()' => function () use ($name) : void {
                PgSqlLogger::createTables(TestDatabase::get(), $name);
            },
            'getCreateTablesSql()' => function () use ($name) : void {
                PgSqlLogger::getCreateTablesSql($name);
            },
            'the constructor' => function () use ($name) : void {
                new PgSqlLogger(TestDatabase::get(), $name);
            },
            'createTables() with it as the schema' => function () use ($name) : void {
                PgSqlLogger::createTables(TestDatabase::get(), 'log', 'log_level', $name);
            },
            'getCreateTablesSql() with it as the schema' => function () use ($name) : void {
                PgSqlLogger::getCreateTablesSql('log', 'log_level', $name);
            },
            'the constructor with it as the schema' => function () use ($name) : void {
                new PgSqlLogger(TestDatabase::get(), 'log', schema: $name);
            },
        ];

        foreach ($attempts as $method => $attempt)
        {
            try
            {
                SmokeTest::expectException(\InvalidArgumentException::class, $attempt, 'lowercase letters');
            }
            catch (\Throwable $exception)
            {
                throw new \Exception("{$method} with {$what}: {$exception->getMessage()}", 0, $exception);
            }
        }
    }

    $longTable = function () : void {
        PgSqlLogger::getCreateTablesSql(str_repeat('a', 49));
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $longTable, '1 to 48');

    $longType = function () : void {
        PgSqlLogger::getCreateTablesSql('log', str_repeat('a', 64));
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $longType, '1 to 63');

    // The longest names allowed work.
    $longestTable = TestDatabase::PREFIX . str_repeat('a', 48 - strlen(TestDatabase::PREFIX));
    $longestType = TestDatabase::PREFIX . str_repeat('b', 63 - strlen(TestDatabase::PREFIX));
    $longestSchema = TestDatabase::PREFIX . str_repeat('c', 63 - strlen(TestDatabase::PREFIX));
    PgSqlLogger::createTables(TestDatabase::get(), $longestTable, $longestType, $longestSchema);
    (new PgSqlLogger(TestDatabase::get(), $longestTable, schema: $longestSchema))->info("the longest names");
};

$smoke->run("logger: rejects a table, type or schema name that isn't a lowercase identifier", $rejectsNames);

$logsEachLevel = function () : void {
    $logger = createTestLogger('levels');
    $logger->debug("a debug");
    $logger->info("an info");
    $logger->notice("a notice");
    $logger->warning("a warning");
    $logger->error("an error");
    $logger->critical("a critical");
    $logger->alert("an alert");
    $logger->emergency("an emergency");
    $logger->log(LogLevel::ERROR, "an enum");
    $logger->log("WARNING", "an uppercase string");
    $logger->log(\Psr\Log\LogLevel::NOTICE, "a PSR-3 constant");
    $logger->log(0, "int 0");
    $logger->log(7, "int 7");

    $expected = [
        'a debug' => 'debug',
        'an info' => 'info',
        'a notice' => 'notice',
        'a warning' => 'warning',
        'an error' => 'error',
        'a critical' => 'critical',
        'an alert' => 'alert',
        'an emergency' => 'emergency',
        'an enum' => 'error',
        'an uppercase string' => 'warning',
        'a PSR-3 constant' => 'notice',
        'int 0' => 'debug',
        'int 7' => 'emergency',
    ];

    $logs = fetchTestLogs('levels');
    SmokeTest::assertSame($expected, array_column($logs, 'level', 'message'), "each log's level, in the order logged");
};

$smoke->run("logger: logs at each level, given as an enum, a string or an int", $logsEachLevel);

$rejectsLevels = function () : void {
    $logger = createTestLogger('bad_levels');

    $badLevels = [
        'a misspelt level' => 'warn',
        'an empty string' => '',
        'an int below 0' => -1,
        'an int above 7' => 8,
        'a float' => 1.5,
        'null' => null,
        'an array' => ['info'],
    ];

    foreach ($badLevels as $what => $level)
    {
        $logWithBadLevel = function () use ($logger, $level) : void {
            $logger->log($level, "never logged");
        };

        try
        {
            SmokeTest::expectException(ExceptionInvalidLogLevel::class, $logWithBadLevel);
        }
        catch (\Throwable $exception)
        {
            throw new \Exception("Logging with {$what}: {$exception->getMessage()}", 0, $exception);
        }
    }

    SmokeTest::assertSame([], fetchTestLogs('bad_levels'), "the logs, as nothing should have been logged");
};

$smoke->run("logger: rejects an invalid level, logging nothing", $rejectsLevels);

$storesContext = function () : void {
    $logger = createTestLogger('context');

    $message = new class implements \Stringable {
        public function __toString() : string
        {
            return "a stringable";
        }
    };

    $logger->info($message, ['user' => 5, 'path' => '/a/b', 'nested' => ['ok' => true]]);
    $logger->info("no context");
    $logger->error("an exception", ['exception' => new \RuntimeException("it broke", 3)]);
    $logs = fetchTestLogs('context');
    SmokeTest::assertSame('a stringable', $logs[0]['message'], "a Stringable message");
    $context = json_decode((string)$logs[0]['context'], true, 512, JSON_THROW_ON_ERROR);
    SmokeTest::assertSame(['path' => '/a/b', 'user' => 5, 'nested' => ['ok' => true]], $context, "the context");
    SmokeTest::assertSame('[]', $logs[1]['context'], "no context");
    /** @var array{exception: array{class: string, message: string, code: int}} $context */
    $context = json_decode((string)$logs[2]['context'], true, 512, JSON_THROW_ON_ERROR);
    $exception = $context['exception'];
    $stored = [$exception['class'], $exception['message'], $exception['code']];
    SmokeTest::assertSame(['RuntimeException', 'it broke', 3], $stored, "the exception");
};

$smoke->run("logger: stores the message and context, including an exception", $storesContext);

$rejectsUnencodableContext = function () : void {
    $logger = createTestLogger('unencodable');

    $logNan = function () use ($logger) : void {
        $logger->info("never logged", ['value' => NAN]);
    };

    SmokeTest::expectException(ExceptionFailedToEncodeLog::class, $logNan);
    SmokeTest::assertSame([], fetchTestLogs('unencodable'), "the logs, as nothing should have been logged");
};

$smoke->run("logger: rejects context that can't be JSON encoded, logging nothing", $rejectsUnencodableContext);

$replacesBadCharacters = function () : void {
    $logger = createTestLogger('characters');
    $replacement = "\u{FFFD}";
    $logger->info("bad \xC3\x28 byte", ['value' => "bad \xC3\x28 byte", "key \xFF" => 1]);
    $logger->info("a \0 nul", ['value' => "a \0 nul", "key \0" => 2, 'nested' => ["\0" => "\0"]]);
    $logger->info('not a nul', ['value' => '\u0000', 'backslash' => "\\\0", 'emoji' => "\u{1F600}"]);
    $logs = fetchTestLogs('characters');
    SmokeTest::assertSame("bad {$replacement}( byte", $logs[0]['message'], "the invalid UTF-8 message");
    $context = json_decode((string)$logs[0]['context'], true, 512, JSON_THROW_ON_ERROR);
    $expected = ['value' => "bad {$replacement}( byte", "key {$replacement}" => 1];
    SmokeTest::assertSame($expected, $context, "the invalid UTF-8 context");
    SmokeTest::assertSame("a {$replacement} nul", $logs[1]['message'], "the message with a NUL");
    $context = json_decode((string)$logs[1]['context'], true, 512, JSON_THROW_ON_ERROR);

    // jsonb keeps an object's keys shortest first.
    $expected = [
        'value' => "a {$replacement} nul",
        'nested' => [$replacement => $replacement],
        "key {$replacement}" => 2,
    ];

    SmokeTest::assertSame($expected, $context, "the context with NULs");
    $context = json_decode((string)$logs[2]['context'], true, 512, JSON_THROW_ON_ERROR);
    $expected = ['emoji' => "\u{1F600}", 'value' => '\u0000', 'backslash' => "\\{$replacement}"];
    SmokeTest::assertSame($expected, $context, "the context with text that isn't a NUL");
};

$smoke->run("logger: replaces invalid UTF-8 and NULs with U+FFFD, rather than losing the log", $replacesBadCharacters);

$usesClock = function () : void {
    $clock = new FakeClock();
    $logger = createTestLogger('clock', $clock);
    $logger->info("first");
    $clock->advance(-3600);
    $logger->info("second, an hour earlier by the clock");
    $logs = fetchTestLogs('clock');
    $expectedFirst = (string)$clock->now()->modify('+3600 seconds')->format('U') . '.0000';
    SmokeTest::assertSame($expectedFirst, $logs[0]['created_at'], "the first log's created_at, from the clock");
    SmokeTest::assertSame($clock->now()->format('U') . '.0000', $logs[1]['created_at'], "the second's");
    $messages = array_column($logs, 'message');
    SmokeTest::assertSame(['first', 'second, an hour earlier by the clock'], $messages, "the logs, by ID");
};

$smoke->run("logger: timestamps logs with the clock, but orders their IDs by when they were logged", $usesClock);

$usesRealTime = function () : void {
    $before = microtime(true);
    $logger = createTestLogger('real_time');
    $logger->info("now");
    $after = microtime(true);
    $createdAt = (float)fetchTestLogs('real_time')[0]['created_at'];
    assert($createdAt >= round($before, 4) - 0.0001 && $createdAt <= round($after, 4) + 0.0001, "It's the real time.");
};

$smoke->run("logger: timestamps logs with the real time when not given a clock", $usesRealTime);

$idsInOrder = function () : void {
    $logger = createTestLogger('order');
    $count = 2000;
    $expected = [];

    for ($i = 0; $i < $count; $i++)
    {
        $logger->info("log {$i}");
        $expected[] = "log {$i}";
    }

    $logs = fetchTestLogs('order');
    SmokeTest::assertSame($expected, array_column($logs, 'message'), "the logs, ordered by ID");
    // The version is the 15th character (uuid_extract_version() is only in PostgreSQL 17 and later).
    $versionsQuery = "SELECT DISTINCT substring(id::text, 15, 1) AS version FROM logger_test_order";
    $versions = TestDatabase::fetchRows($versionsQuery);
    SmokeTest::assertSame([['version' => '7']], $versions, "the IDs' UUID version");
};

$smoke->run("logger: gives each log a UUIDv7, in the order they were logged", $idsInOrder);

$reconnects = function () : void {
    $connection = TestDatabase::connect();
    $table = TestDatabase::PREFIX . 'reconnect';
    PgSqlLogger::createTables($connection, $table, "{$table}_level");
    $logger = new PgSqlLogger($connection, $table);
    $logger->info("before");

    // Disconnect the logger's connection from the server's side, as a database restart would.
    TestDatabase::query("SELECT pg_terminate_backend($1)", [pg_get_pid($connection)]);
    $logger->info("after");
    pg_close($connection);
    SmokeTest::assertSame(['before', 'after'], array_column(fetchTestLogs('reconnect'), 'message'), "both logs");
};

$smoke->run("logger: reconnects when its connection has been lost", $reconnects);

$failsClearly = function () : void {
    $logger = createTestLogger('dropped');
    TestDatabase::query("DROP TABLE logger_test_dropped");

    $logToMissingTable = function () use ($logger) : void {
        $logger->info("never logged");
    };

    SmokeTest::expectException(\Exception::class, $logToMissingTable, 'Failed to insert log into database.');
};

$smoke->run("logger: throws, saying why, when a log can't be inserted", $failsClearly);
