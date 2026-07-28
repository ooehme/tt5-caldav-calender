<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/ProjectTest.php';
require_once __DIR__ . '/UpdaterTest.php';
require_once __DIR__ . '/ClientTest.php';
require_once __DIR__ . '/ParserTest.php';

$assertions = 0;
$tests = array(
	new TT5_Project_Test(),
	new TT5_Updater_Test(),
	new TT5_Client_Test(),
	new TT5_Parser_Test(),
);
foreach ( $tests as $test ) {
	$assertions += $test->run();
}

echo "OK ({$assertions} assertions)\n";
