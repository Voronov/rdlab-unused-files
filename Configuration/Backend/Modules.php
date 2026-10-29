<?php

use RDLAB\UnusedFiles\Controller\UnusedFilesController;

return [
    'file_rdlab_unused_files' => [
        'parent' => 'file',
        'position' => ['after' => 'media_management'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/file/rdlab-unused-files',
        'iconIdentifier' => 'rdlab-unused-files-module',
        'labels' => 'LLL:EXT:rdlab_unused_files/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => UnusedFilesController::class . '::handleRequest',
            ],
        ],
    ],
];
