<?php

use Koh\WebMcp\Presentation\Backend\ToolOverviewController;

return [
    'content_webmcp' => [
        'parent' => 'content',
        'position' => ['after' => '*'],
        'access' => 'admin',
        'iconIdentifier' => 'webmcp-module',
        'labels' => 'LLL:EXT:webmcp/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ToolOverviewController::class . '::indexAction',
            ],
            'log' => [
                'target' => ToolOverviewController::class . '::logAction',
            ],
            'consent' => [
                'target' => ToolOverviewController::class . '::consentAction',
            ],
        ],
    ],
];
