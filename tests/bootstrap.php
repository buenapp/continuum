<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Continuum\Tests
 */

define('PHPUNIT_RUNNING', true);
defined('CONTINUUM_AGENT') || define('CONTINUUM_AGENT', 'test-agent');

chdir(dirname(__DIR__) . '/src');
require_once 'includes/bootstrap.inc.php';
require_once __DIR__ . '/Fakes.php';
