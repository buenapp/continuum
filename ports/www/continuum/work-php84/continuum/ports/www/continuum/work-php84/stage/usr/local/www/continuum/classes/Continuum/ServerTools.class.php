<?php

namespace Continuum;

use EnchiladaMCP\McpTool;

/**
 * Continuum server meta-tools: identity, health, and surface discovery.
 */
class ServerTools {

    /**
     * Server identity and build information.
     */
    #[McpTool(
        name: 'server_info',
        description: 'Return Continuum server identity, version, and engine configuration summary.'
    )]
    public function server_info(): array {
        global $SETTINGS;
        return [
            'name' => APPLICATION_NAME,
            'version' => APPLICATION_VERSION,
            'description' => APPLICATION_DESCRIPTION,
            'website' => APPLICATION_WEBSITE,
            'engines' => [
                'ephemeral' => 'valkey@' . ($SETTINGS ? $SETTINGS->getString('valkey', 'host', '127.0.0.1') : '127.0.0.1'),
                'durable' => 'couchdb@' . ($SETTINGS ? $SETTINGS->getString('couchdb', 'host', '127.0.0.1') : '127.0.0.1'),
                'structural' => 'arcadedb@' . ($SETTINGS ? $SETTINGS->getString('arcadedb', 'host', '127.0.0.1') : '127.0.0.1'),
            ],
            'php' => phpversion(),
        ];
    }
}
