# Upgrading

What changed in each release, and what, if anything, to change when upgrading, newest first.

## 3.0.0

### Changes you need to make
- **Create the table before creating a logger.** The constructor no longer creates the table and its level type.
  Call `PgSqlLogger::createTables($connection)` (with your table and type names, if they aren't the defaults) when
  your application starts, or put the SQL from `PgSqlLogger::getCreateTablesSql()` in your own migrations. Both only
  create what doesn't exist, so they're safe to run on a table that 2.x created, and don't add a second index to it.
  A logger whose table doesn't exist now throws when it's created, saying how to create it.
- **Don't give the constructor the level type's name.** It's only needed to create the table, so it's now
  `createTables()`'s and `getCreateTablesSql()`'s third parameter, and the constructor's third parameter is the clock.
  So `new PgSqlLogger($connection, "log", "log_level", $clock)` becomes `new PgSqlLogger($connection, "log", $clock)`.
  Passing the type's name to the constructor now throws a `TypeError`, or an `Error` for the `logLevelEnumName:` named
  argument, rather than being silently ignored.
- **Table, type and schema names must be lowercase identifiers**: lowercase letters, digits and underscores, not
  starting with a digit, of up to 48 characters for a table and 63 for a type or schema. Anything else throws an
  `\InvalidArgumentException`. If yours is a different case (e.g. `"Log"`), rename it in the database first
  (`ALTER TABLE "Log" RENAME TO log`).
- **`doesLogTableEnumExist()` has been removed**, as nothing needs it now.

### Other changes
- **The table can be in any schema**, given as the last parameter of `createTables()`, `getCreateTablesSql()` and the
  constructor. It defaults to `public`, where 2.x put it, so an existing table is found as it is. Every query now
  names the schema, so the connection's `search_path` no longer matters.
- **Needs `programster/log` 4.0**, rather than 2.x or 3.x. If your application uses that package's other loggers
  too, see [its upgrade notes](https://github.com/programster/package-logging/blob/main/docs/upgrading.md).
- **No longer needs `ramsey/uuid`** (or, through it, `ramsey/collection` and `brick/math`). IDs are generated with
  `programster/pgsql-lib` 3.2's `PgsqlLib::generateUuid()` instead, and within a process each is now always greater
  than the one before, even for logs written in the same millisecond.
- **Invalid UTF-8 and NUL characters are replaced with U+FFFD**, in the message and the context, rather than
  PostgreSQL rejecting the log. (2.1.0's notes said it did this, but it didn't.)
- **Context that can't be JSON encoded (e.g. `NAN`) throws an `ExceptionFailedToEncodeLog`**, before anything is sent
  to the database. Before, it sent an empty string, which PostgreSQL rejected, so it threw an `\Exception` saying the
  log couldn't be inserted. `ExceptionFailedToEncodeLog` extends `\Exception`, so code that catches `\Exception` still
  catches it.
- **An exception in the context is stored as its class, message, code, file, line, trace and previous exception**,
  rather than as `{}`.
- `log()`'s `$level` parameter is declared as `mixed`, as it always accepted anything (throwing for an invalid level).

## 2.1.0
- Adds an optional PSR-20 clock, as the constructor's last parameter, to timestamp the logs' `created_at` with.
- IDs are always generated from the real time, so order by `id` for the order logs were written in, or by
  `created_at` for the order of the clock. This replaces 2.0.1's advice to order by `created_at`.

## 2.0.1
- Reconnects (once per log) if the connection has been lost, e.g. because the database restarted.
- IDs are UUIDv7s, which keep inserts into the primary key's index efficient, rather than sequential UUIDv4s.
- Sends values as query parameters, rather than escaping them into the query.

## 2.0.0
- Needs PHP 8.2 or later, and `programster/log` 2.0 or 3.0.

## 1.0.0
The first release.
