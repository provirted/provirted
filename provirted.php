#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use App\Console;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$input = new ArgvInput();
$output = new ConsoleOutput();

$app = new Console();
$app->run($input, $output);
