<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Elite\EDDN;

$eddn=new EDDN(
	environement:EDDN::ENVIRONEMENT_TEST,
	gateway:'https://beta.eddn.edcd.io:4431/upload/',
);

foreach(file(__DIR__.'/data/test-journal.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line)
{
	$entry=json_decode($line,true);
	$eddn->handle_entry($entry);
}
