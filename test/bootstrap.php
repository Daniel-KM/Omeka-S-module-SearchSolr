<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use OmekaTestHelper\Bootstrap;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

if (!class_exists(ExpectationFailedException::class)) {
    class_alias(\PHPUnit\Framework\ExpectationFailedException::class, ExpectationFailedException::class);
}

if (!class_exists(TestCase::class)) {
    class_alias(\PHPUnit\Framework\TestCase::class, TestCase::class);
}

Bootstrap::bootstrap(__DIR__);
Bootstrap::loginAsAdmin();
Bootstrap::enableModule('AdvancedSearch');
Bootstrap::enableModule('SearchSolr');
