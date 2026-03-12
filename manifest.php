<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$manifest = [
    'name' => 'VETLINK',
    'short_name' => 'VETLINK',
    'description' => 'Application de gestion veterinaire VETLINK.',
    'lang' => 'fr',
    'dir' => 'ltr',
    'start_url' => base_url('index.php?page=login'),
    'scope' => base_url(),
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#f5f7fb',
    'theme_color' => '#0b3d5c',
    'icons' => [
        [
            'src' => base_url('assets/pwa/192x192.png'),
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src' => base_url('assets/pwa/512X512.png'),
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src' => base_url('assets/pwa/1024X1024.png'),
            'sizes' => '1024x1024',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
    ],
    'shortcuts' => [
        [
            'name' => 'Tableau de bord',
            'url' => base_url('index.php?page=dashboard'),
            'icons' => [[
                'src' => base_url('assets/pwa/192x192.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
            ]],
        ],
        [
            'name' => 'Clients',
            'url' => base_url('index.php?page=clients'),
            'icons' => [[
                'src' => base_url('assets/pwa/192x192.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
            ]],
        ],
        [
            'name' => 'Patients',
            'url' => base_url('index.php?page=patients'),
            'icons' => [[
                'src' => base_url('assets/pwa/192x192.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
            ]],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
