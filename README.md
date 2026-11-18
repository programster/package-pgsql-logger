# PostgreSQL Logger

[![Tests](https://github.com/programster/package-pgsql-logger/actions/workflows/tests.yml/badge.svg)](https://github.com/programster/package-pgsql-logger/actions/workflows/tests.yml)

- [What it's for](#what-its-for)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Design principles](#design-principles)
- [Documentation](#documentation)
    - [Logging](docs/logging.md)
    - [Testing](docs/testing.md)
    - [Upgrading](docs/upgrading.md)
- [Licence](#licence)

## What it's for
A [PSR-3](https://www.php-fig.org/psr/psr-3/) logger that writes logs to a PostgreSQL table, which it can create for
you (in any schema), or give you the SQL for, to run in your own migrations.
Each log has a UUIDv7 ID (so ordering by it gives the order they were written in), its message, its level, its context
as `jsonb` (so you can query it), and when it was logged. It's safe to use in long-running processes, such as workers
and daemons, as it reconnects if the database restarts.

It extends [`programster/log`](https://github.com/programster/package-logging)'s `AbstractLogger`, so it works with
that package's other loggers, e.g. as one of a `FailoverLogger`'s loggers.

## Requirements
- PHP 8.2, 8.3, 8.4 or 8.5, with the `pgsql` extension.
- PostgreSQL 14, 15, 16, 17 or 18.

## Installation
```bash
composer require programster/pgsql-logger
```

## Quick start
```php
use Programster\PgsqlLogger\PgSqlLogger;

$connection = pg_connect("host=localhost dbname=app user=app password=secret");

// Create the "log" table (and its "log_level" type), if they don't exist, e.g. when the application starts. Or put
// the SQL from PgSqlLogger::getCreateTablesSql() in your own migrations.
PgSqlLogger::createTables($connection);
$logger = new PgSqlLogger($connection);

$logger->info("A user signed in.", ['userId' => 5]);
$logger->warning("The disk is nearly full.", ['freeBytes' => 1024]);

try
{
    throw new RuntimeException("The payment provider timed out.");
}
catch (RuntimeException $exception)
{
    $logger->error("A payment failed.", ['exception' => $exception]);
}
```

See [Logging](docs/logging.md) for creating the table in your own migrations or another schema, levels, context, a
custom clock, and reconnecting.

## Design principles
- **Your migrations, or ours.** `createTables()` creates the table when your application starts, or
  `getCreateTablesSql()` gives you the SQL to run in your own migrations. Either is safe to run again. A logger
  created before its table exists throws straight away, saying how to create it, rather than at the first log.
- **A log is never lost to bad characters.** Invalid UTF-8 and NUL characters, which PostgreSQL can't store, are
  replaced with U+FFFD (the replacement character), in the message and the context.
- **Failures throw.** A log that can't be written throws an exception, rather than being dropped silently, so it can be
  caught (e.g. by a `FailoverLogger`, to log somewhere else).
- **IDs are in the order logs were written**, even if your application's clock moves, so you can page through logs by
  ID.

## Documentation
The [`docs`](docs/) folder has the full documentation:

- [Logging](docs/logging.md): creating the table (or getting the SQL for your migrations), schemas, levels,
  context, characters PostgreSQL can't store, a custom clock, reconnecting, and errors.
- [Testing](docs/testing.md): running the package's tests.
- [Upgrading](docs/upgrading.md): what changed in each release.

## Licence
MIT. See [LICENSE](LICENSE).
