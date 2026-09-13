<?php
/**
 * Continuum Bootstrap
 *
 * Based on Enchilada Framework 3.0
 */

error_reporting(E_ALL);
ini_set("display_errors", 1);
date_default_timezone_set('America/New_York');

require_once("system/app.conf.php"); //Application Constants
@include_once("config/local.conf.php"); //User Made Application Options
require_once('system/autoload.inc.php'); // Libraries and Classes autoloader

// Load settings from INI file (typed IniConfig access)
use Enchilada\Config\IniConfig;

$settingsFile = APPLICATION_CONFDIR . 'settings.ini';
if (file_exists($settingsFile)) {
    $SETTINGS = IniConfig::load($settingsFile);
} else {
    $SETTINGS = null;
}
