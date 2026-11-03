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
