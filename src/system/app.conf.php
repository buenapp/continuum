<?php
define('APPLICATION_NAME', 'Continuum');
define('APPLICATION_DESCRIPTION', 'Blackboard coordination MCP server for multi-agent orchestration: tasks, claims, locks, handoffs, inboxes, and an append-only event log.');
define('APPLICATION_WEBSITE', 'https://pacyworld.dev/buenapp/continuum');
define('APPLICATION_VERSION', '0.2.3');
define('APPLICATION_CONFDIR', (@$MULTISITE_CONFDIR ?: 'config/')); //Must have trailing '/'
define('APPLICATION_DEBUG', getenv('ENCHILADA_DEBUG_ENABLE'));
define('APPLICATION_USERAGENT', sprintf('%s/%s (%s; U; %s %s) PHP %s', APPLICATION_NAME, APPLICATION_VERSION, php_uname('s'), php_uname('s'), php_uname('r'), phpversion()));
define('APPLICATION_TEMPDIR', (getenv('ENCHILADA_TEMP_DIR') ?: 'temp/')); //Must have trailing '/'
?>
