# PostgreSQL Logger Package
A PSR-3 logger for logging to a PostgreSQL database in PHP.

## Installation

```bash
composer require programster/pgsql-logger
```

## Example Usage

```php
# Connect to your database
$pgsqlConn = pg_connect($connectionString);

# Create the logger using your Pgsql connection.
$logger = new \Programster\PgsqlLogger\PgSqlLogger($pgsqlConn);

# Create some logs using the standard PSR-3 interface
$logger->debug("This is an info log");
$logger->notice("This is a notice log", ['name' => 'value']);
$logger->info("This is an info log", ['name' => 'value']);
$logger->warning("This is a warning log", ['name' => 'value']);
$logger->alert("This is an alert log", ['name' => 'value']);
$logger->emergency("This is an emergency log", ['name' => 'value']);
$logger->critical("This is a critical log", ['name' => 'value']);
$logger->log(\Psr\Log\LogLevel::WARNING, "This is another warning log", ['name' => 'value']);
```

## Using a Custom Clock
You can optionally pass a [PSR-20](https://www.php-fig.org/psr/psr-20/) clock as the last constructor parameter.
This is useful if your application uses a fake or offset "system clock" (e.g. for testing time-based jobs), and
you want the logs to be timestamped with that clock rather than the real time.

```php
$logger = new \Programster\PgsqlLogger\PgSqlLogger($pgsqlConn, clock: $myClock);
```

If no clock is provided, the real current time is used.

The clock is only used for the `created_at` column, which is stored as a unix timestamp (UTC) with sub-second
precision. The log's `id` (a UUIDv7) is always generated from the real time, so ids stay in real insertion order
even if the clock is moved backwards or forwards. If you need logs in the order they were written, order by `id`.
If you need them in the order of your application's clock, order by `created_at`.


## Reconnecting After Database Restarts
The logger is safe to use in long-running processes, such as background workers and daemons. If the database
is restarted, or the connection is lost, the next log call will reset the connection with
`pg_connection_reset()` and retry once. If the database is still unavailable, an exception is thrown, and the
next call will try to reconnect again.

Note that the connection you pass to the logger is the one that gets reset, so any other code in your application
sharing that connection will also be reconnected.


## Testing
Spin up a test database using the docker-compose.yml file, before running the `main.php` script that
will test connecting to the database and creating some logs.
