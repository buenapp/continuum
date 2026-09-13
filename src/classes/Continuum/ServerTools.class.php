<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;

/**
 * Continuum server meta-tools: identity, health, and surface discovery.
 */
class ServerTools {

    /**
     * Server identity and build information.
     */
    #[McpTool(
        name: 'server_info',
        description: 'Return Continuum server identity, version, and engine configuration summary.',
        readOnlyHint: true,
        outputSchema: self::INFO_SCHEMA
    )]
    public function server_info(): ToolResult {
        global $SETTINGS;
        $data = [
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
        $text = $data['name'] . ' ' . $data['version'] . " — " . $data['description'] . "\n"
            . 'engines: ephemeral=' . $data['engines']['ephemeral']
            . ', durable=' . $data['engines']['durable']
            . ', structural=' . $data['engines']['structural'] . "\n"
            . 'php: ' . $data['php'] . "\n"
            . 'website: ' . $data['website'];
        return ToolResult::structured($text, $data);
    }

    private const INFO_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'version' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'website' => ['type' => 'string'],
            'engines' => [
                'type' => 'object',
                'properties' => [
                    'ephemeral' => ['type' => 'string'],
                    'durable' => ['type' => 'string'],
                    'structural' => ['type' => 'string'],
                ],
                'required' => ['ephemeral', 'durable', 'structural'],
            ],
            'php' => ['type' => 'string'],
        ],
        'required' => ['name', 'version', 'description', 'website', 'engines', 'php'],
    ];
}
