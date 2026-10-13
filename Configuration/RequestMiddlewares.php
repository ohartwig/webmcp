<?php

return [
    'frontend' => [
        'koh/webmcp-agent-discovery' => [
            'target' => Koh\WebMcp\Infrastructure\Http\AgentDiscoveryMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'koh/webmcp-api' => [
            'target' => Koh\WebMcp\Infrastructure\Http\WebMcpApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
