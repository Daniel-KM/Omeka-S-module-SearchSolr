<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use OmekaTestHelper\Bootstrap;
use PHPUnit\Framework\ExpectationFailedException;

if (! class_exists(ExpectationFailedException::class)) {
    class_alias(\PHPUnit_Framework_ExpectationFailedException::class, ExpectationFailedException::class);
}

if (! class_exists(TestCase::class)) {
    class_alias(\PHPUnit_Framework_TestCase::class, TestCase::class);
}

use PHPUnit\Framework\TestCase;

Bootstrap::bootstrap(__DIR__);
Bootstrap::loginAsAdmin();
Bootstrap::enableModule('AdvancedSearch');
Bootstrap::enableModule('SearchSolr');
