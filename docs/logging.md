# Logging

- [Creating the table](#creating-the-table)
    - [Schemas](#schemas)
- [Creating a logger](#creating-a-logger)
- [The table](#the-table)
- [Levels](#levels)
- [Context](#context)
- [Characters PostgreSQL can't store](#characters-postgresql-cant-store)
- [Using a custom clock](#using-a-custom-clock)
- [Reconnecting after database restarts](#reconnecting-after-database-restarts)
- [Errors](#errors)

## Creating the table
Create the log table, its level type and its index first, in one of two ways. Either way, everything is created only
if it doesn't exist, so it's safe to run again, including on a table created by an earlier version of this package.

`PgSqlLogger::createTables()` creates them, e.g. when your application starts:

```php
use Programster\PgsqlLogger\PgSqlLogger;

$connection = pg_connect("host=localhost dbname=app user=app password=secret");
PgSqlLogger::createTables($connection);
PgSqlLogger::createTables($connection); // Safe to run again.
```

Or `PgSqlLogger::getCreateTablesSql()` gives you the SQL, to run in your own migrations. It's several statements, so
run it with `pg_query()` (or your migration tool), not `pg_query_params()`, which only runs one:

```php
$sql = PgSqlLogger::getCreateTablesSql();
pg_query($connection, $sql);
```

Both take the names of the table and its level type, which default to `log` and `log_level`, e.g. for a part of an
application to have a table of its own:

```php
PgSqlLogger::createTables($connection, "payment_log", "payment_log_level");
$paymentSql = PgSqlLogger::getCreateTablesSql("payment_log", "payment_log_level");
```

Each name must be lowercase letters, digits and underscores, not starting with a digit: up to 48 characters for the
table (so its index's name, e.g. `payment_log_created_at_idx`, fits within PostgreSQL's limit), and 63 for the type
and the schema. Anything else throws an `\InvalidArgumentException`, so a name can't be mistaken for another in a
different case, or need quoting.

### Schemas
The table and its level type go in the `public` schema by default. To put them in another, give its name as the
last parameter, and give the logger the same schema. The schema is created if it doesn't exist:

```php
PgSqlLogger::createTables($connection, schema: "app");
$appSql = PgSqlLogger::getCreateTablesSql(schema: "app");
$appLogger = new PgSqlLogger($connection, schema: "app");
```

Each schema can have a table of the same name, so `app.log` and `public.log` are separate logs. Every query names the
schema, so the connection's `search_path` doesn't matter.

The schema is only created when it doesn't exist, rather than with `CREATE SCHEMA IF NOT EXISTS`, which PostgreSQL
refuses to a user without the `CREATE` privilege on the database, even when the schema exists. So a user that can only
create tables in an existing schema can still run `createTables()` (or the SQL) for it.

```php
// Throws InvalidArgumentException
PgSqlLogger::createTables($connection, "PaymentLog");
```

## Creating a logger
Give `PgSqlLogger` a connection from `pg_connect()`, and optionally the name of the table (as given to
`createTables()`, defaulting to `log`), a clock (see [below](#using-a-custom-clock)), and the schema the table is in
(defaulting to `public`, see [Schemas](#schemas)):

```php
$logger = new PgSqlLogger($connection);
$paymentsLogger = new PgSqlLogger($connection, "payment_log");
```

The table must already exist. If it doesn't, the constructor throws an `\Exception` straight away, saying how to
create it, rather than the first log failing:

```php
// Throws Exception
$logger = new PgSqlLogger($connection, "missing_log");
```

## The table
The table has these columns:

| Column | Type | Holds |
|---|---|---|
| `id` | `uuid` | A UUIDv7, generated from the real time, so ordering by it gives the order the logs were written in. |
| `message` | `text` | The message. |
| `level` | The level type, e.g. `log_level` | The PSR-3 level, e.g. `warning`. |
| `context` | `jsonb` | The context. |
| `created_at` | `decimal(15,4)` | When it was logged, from the clock, as a unix timestamp (UTC) to a 10,000th of a second. |

It also has an index on `created_at`. To read the logs back, order by `id` for the order they were written in, or by
`created_at` for the order of your application's clock (see [Using a custom clock](#using-a-custom-clock)):

```php
$result = pg_query($connection, "SELECT message, level FROM log ORDER BY id DESC LIMIT 50");
$latest = pg_fetch_all($result);
```

## Levels
Log with the PSR-3 methods, or with `log()` and a level, which can be a `Programster\Log\LogLevel`, a PSR-3 level
string in any case (e.g. `"warning"` or `"WARNING"`), or an integer from 0 (debug) to 7 (emergency):

```php
use Programster\Log\LogLevel;

$logger->debug("Starting the import.");
$logger->notice("An old API version was used.");
$logger->critical("The payment provider is down.");
$logger->log(LogLevel::ERROR, "An enum level.");
$logger->log(\Psr\Log\LogLevel::ALERT, "A PSR-3 level.");
$logger->log(7, "An emergency, by number.");
```

Anything else throws an `ExceptionInvalidLogLevel`, and nothing is logged:

```php
// Throws ExceptionInvalidLogLevel
$logger->log("warn", "A misspelt level.");
```

## Context
The context is stored as `jsonb`, so it can be queried, e.g. to find a user's logs:

```php
$logger->info("A user signed in.", ['userId' => 5, 'ip' => '203.0.113.7']);

$query = "SELECT message FROM log WHERE context @> $1 ORDER BY id";
$result = pg_query_params($connection, $query, [json_encode(['userId' => 5])]);
```

An exception in the context (e.g. in the `exception` key, as PSR-3 recommends) is stored as its class, message, code,
file, line, trace and previous exception, rather than as `{}`. Other `Stringable` objects are stored as their string,
unless they're `JsonSerializable`.

Context that can't be JSON encoded at all (e.g. `NAN` or `INF`) throws an `ExceptionFailedToEncodeLog`, rather than
writing a log with missing or misleading data:

```php
// Throws ExceptionFailedToEncodeLog
$logger->info("A sum went wrong.", ['result' => NAN]);
```

## Characters PostgreSQL can't store
PostgreSQL can't store invalid UTF-8 in a `text` column, or a NUL character in `text` or `jsonb`, so it would reject
the whole log. The logger replaces each of them with U+FFFD (`�`, the replacement character), in the message and the
context (keys included), so the log is kept:

```php
$logger->warning("Read a bad file name: \xC3\x28.", ['bytes' => "a\0b"]);
// Stored with the message "Read a bad file name: �(." and the context {"bytes": "a�b"}.
```

## Using a custom clock
You can pass a [PSR-20](https://www.php-fig.org/psr/psr-20/) clock as the last parameter. This is useful if your
application uses a fake or offset "system clock" (e.g. for testing time-based jobs), and you want the logs to be
timestamped with that clock rather than the real time:

```php
$clock = new class implements \Psr\Clock\ClockInterface {
    public function now() : \DateTimeImmutable
    {
        return new \DateTimeImmutable('2030-01-01 09:00:00');
    }
};

$clockLogger = new PgSqlLogger($connection, clock: $clock);
```

Without one, the real current time is used.

The clock is only used for `created_at`. The log's `id` is always generated from the real time, so IDs stay in the
order the logs were written in, even if the clock is moved backwards or forwards. Within a process, each ID is always
greater than the one before, even for logs written in the same millisecond.

## Reconnecting after database restarts
The logger is safe to use in long-running processes, such as background workers and daemons. If the database
is restarted, or the connection is lost, the next log call resets the connection with `pg_connection_reset()` and
tries once more. If the database is still unavailable, an exception is thrown, and the next call tries to reconnect
again.

The connection you give the logger is the one that gets reset, so any other code in your application sharing that
connection is reconnected too.

## Errors

| Exception | Thrown when |
|---|---|
| `Programster\Log\Exceptions\ExceptionInvalidLogLevel` | The level isn't valid. It extends `Psr\Log\InvalidArgumentException`, as PSR-3 requires. |
| `Programster\Log\Exceptions\ExceptionFailedToEncodeLog` | The context can't be JSON encoded, e.g. it holds `NAN`. |
| `\InvalidArgumentException` | A table, type or schema name isn't a lowercase identifier (see [Creating the table](#creating-the-table)). |
| `\Exception` | The table doesn't exist when the logger is created, it can't be created, or a log can't be written, with PostgreSQL's reason in the message. |

The level and the context are checked before anything is sent to the database, so nothing is logged when either is
invalid.
