<?php

/*
 * Runs the examples in the README and every page in docs/, so the documentation can't drift from what the code does.
 * These are run by testing/main.php, which provides $smoke. See DocsExampleRunner for how a document's examples run.
 */

use Programster\PgsqlLogger\Testing\DocsExampleRunner;

/** @var \Programster\PgsqlLogger\Testing\SmokeTest $smoke */

$repositoryDirectory = dirname(__DIR__, 2);
$docsRunner = new DocsExampleRunner($repositoryDirectory);
$documents = array_merge([$repositoryDirectory . '/README.md'], glob($repositoryDirectory . '/docs/*.md') ?: []);

foreach ($documents as $document)
{
    if ($docsRunner->countExamples($document) > 0)
    {
        $runExamples = function () use ($docsRunner, $document): void {
            $docsRunner->run($document);
        };

        $smoke->run("docs: the examples in " . basename(dirname($document)) . "/" . basename($document), $runExamples);
    }
}
