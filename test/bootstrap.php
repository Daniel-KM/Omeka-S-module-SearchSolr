<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

if (! class_exists(ExpectationFailedException::class)) {
    class_alias(\PHPUnit_Framework_ExpectationFailedException::class, ExpectationFailedException::class);
}

if (! class_exists(TestCase::class)) {
    class_alias(\PHPUnit_Framework_TestCase::class, TestCase::class);
}

use OmekaTestHelper\Bootstrap;

Bootstrap::bootstrap(__DIR__);
Bootstrap::loginAsAdmin();
Bootstrap::enableModule('Search');
Bootstrap::enableModule('Solr');
