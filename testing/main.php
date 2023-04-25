<?php

use Programster\Log\PgSqlLogger;

require_once(__DIR__ . '/../vendor/autoload.php');


function getConnection(
    $host,
    $dbName,
    $user,
    $password,
    $port="5432",
    $useUtf8=true,
    $forceNew=false,
    $useAsync=false
)
{
    if ($forceNew && $useAsync)
    {
        $forceNew = false;
    }

    $connString =
        "host=" . $host
        . " dbname=" . $dbName
        . " user=" . $user
        . " password=" . $password
        . " port=" . $port;

    if ($useUtf8)
    {
        $connString .= " options='--client_encoding=UTF8'";
    }

    if ($useAsync)
    {
        $connection = pg_connect($connString, PGSQL_CONNECT_ASYNC);
    }
    elseif ($forceNew)
    {
        $connection = pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
    }
    else
    {
        $connection = pg_connect($connString);
    }

    if ($connection == false)
    {
        throw new Exception("Failed to initialize database connection.");
    }

    return $connection;
}


function main()
{
    $connection = getConnection(host: "127.0.0.1", user: "testuser", dbName: "testdb", password: "testPassword");
    $logger = new \Programster\PgsqlLogger\PgSqlLogger($connection, "log", "log_level");
    $logger->debug("This is an info log", ['name' => 'value']);
    $logger->notice("This is a notice log", ['name' => 'value']);
    $logger->info("This is an info log", ['name' => 'value']);
    $logger->warning("This is a warning log", ['name' => 'value']);
    $logger->alert("This is an alert log", ['name' => 'value']);
    $logger->emergency("This is an emergency log", ['name' => 'value']);
    $logger->critical("This is a critical log", ['name' => 'value']);
    $logger->log(\Psr\Log\LogLevel::WARNING, "This is another warning log", ['name' => 'value']);
    $logger->info("You can log with just a message and no context.");


    $query = "SELECT * FROM " . pg_escape_identifier($connection, "log");
    $result = pg_query($connection, $query);
    /* @var $result \PgSql\Result */
    $rows = pg_fetch_all($result);
    print "Logs found in database: " . print_r($rows, true) . PHP_EOL;
}

main();