#!/usr/bin/env php
<?php
require 'vendor/autoload.php'; // Only if autoload is not set up already.
$app = new \App\Console;
$result = $app->runWithTry($argv); // $argv is a global variable containing command line arguments.
// CLIFramework returns false when an exception bubbled up to runWithTry; commands themselves
// may return an int from execute() that we want to surface as the process exit code.
if ($result === false) {
    exit(1);
}
if (is_int($result)) {
    exit($result);
}
exit(0);
