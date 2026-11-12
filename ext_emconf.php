<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Unused files',
    'description' => 'Lists FAL files that are not referenced anywhere in TYPO3 and lets you delete them: CSV report via CLI and a backend module under File.',
    'category' => 'module',
    'author' => 'RDLAB',
    'state' => 'stable',
    'version' => '1.7.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
