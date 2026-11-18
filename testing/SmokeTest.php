<?php

/*
 * A small test runner for the smoke tests in testing/tests/. Each test is a named callback, which fails if it throws
 * (including a failed assert()) or raises a warning, notice or deprecation.
 */

namespace Programster\PgsqlLogger\Testing;


final class SmokeTest
{
    private int $m_numPassed = 0;
    /** @var list<string> */
    private array $m_failures = [];

    /** @var list<string> */
    private array $m_warnings = [];



    public function __construct()
    {
        error_reporting(-1);

        $recordWarning = function (int $errorNumber, string $message, string $file, int $line): bool {
            # Respect the @ operator, in case a dependency deliberately uses it.
            if (error_reporting() & $errorNumber)
            {
                $this->m_warnings[] = "[E{$errorNumber}] {$message} @ " . basename($file) . ":{$line}";
            }

            return true;
        };

        set_error_handler($recordWarning);
    }


    /**
     * Run one test, recording it as failed if it throws, or raises any warnings/deprecations.
     * @param string $label - the name of the test, shown if it fails.
     * @param callable $test - the test to run.
     * @return void
     */
    public function run(string $label, callable $test): void
    {
        $numWarningsBefore = count($this->m_warnings);
        $error = "";
        # PGSQL_LOGGER_TEST_ONLY runs only the tests whose name contains it.
        $only = getenv("PGSQL_LOGGER_TEST_ONLY");
        $isSelected = ($only === false || $only === "" || str_contains($label, $only));

        try
        {
            if ($isSelected)
            {
                $test();
            }
        }
        catch (\Throwable $e)
        {
            $error = get_class($e) . ": " . $e->getMessage() . " @ " . basename($e->getFile()) . ":" . $e->getLine();

        }

        $newWarnings = array_slice($this->m_warnings, $numWarningsBefore);

        if (!$isSelected)
        {
            # Not selected, so neither passed nor failed.
        }
        elseif ($error === "" && count($newWarnings) === 0)
        {
            $this->m_numPassed++;
        }
        else
        {
            $this->m_failures[] = trim("{$label}: {$error} " . implode(" | ", $newWarnings));
        }
    }


    /**
     * Assert that a callback throws a specific type of exception.
     *
     * @param string $exceptionClass - the class of exception that should be thrown.
     *
     * @param callable $callback - the code that should throw it.
     *
     * @param string $messageContains - optionally, text the exception's message must contain, for when the same class
     * of exception can be thrown for different reasons.
     *
     * @return void
     *
     * @throws \Throwable - if the callback throws a different exception, it is rethrown.
     * @throws \Exception - if the callback doesn't throw at all, or the message doesn't contain $messageContains.
     */
    public static function expectException(
        string $exceptionClass,
        callable $callback,
        string $messageContains = ""
    ): void
    {
        $threwExpectedException = false;

        try
        {
            $callback();
        }
        catch (\Throwable $e)
        {
            if (!($e instanceof $exceptionClass))
            {
                throw $e;
            }

            if (!str_contains($e->getMessage(), $messageContains))
            {
                throw new \Exception(
                    "Expected the {$exceptionClass} message to contain \"{$messageContains}\", but it was: " .
                    $e->getMessage()
                );
            }

            $threwExpectedException = true;
        }

        if (!$threwExpectedException)
        {
            throw new \Exception("Expected {$exceptionClass} to be thrown, but nothing was thrown.");
        }
    }


    /**
     * Assert that two values are identical, showing both if they aren't, which a bare assert() doesn't.
     * @param mixed $expected - the value it should be.
     * @param mixed $actual - the value it is.
     * @param string $what - what is being compared, to show if it fails.
     * @return void
     */
    public static function assertSame(mixed $expected, mixed $actual, string $what): void
    {
        $describe = function (mixed $value): string {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        };

        assert($expected === $actual, "{$what}: expected {$describe($expected)}, got {$describe($actual)}");
    }


    # Accessors
    public function getNumPassed(): int { return $this->m_numPassed; }
    /** @return list<string> */
    public function getFailures(): array { return $this->m_failures; }
}
