<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Continuum\Tests
 */

define('PHPUNIT_RUNNING', true);

chdir(dirname(__DIR__) . '/src');
require_once 'includes/bootstrap.inc.php';
require_once __DIR__ . '/Fakes.php';
