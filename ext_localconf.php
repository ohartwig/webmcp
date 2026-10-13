<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;

defined('TYPO3') or exit;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['webmcp'] ??= [
    'backend' => SimpleFileBackend::class,
    'options' => [
        'defaultLifetime' => 3600,
    ],
    'groups' => ['pages'],
];
