<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';

$texts = [
    'en' => [
        'dashboard' => 'Dashboard',
        'inventory' => 'Inventory',
        'inbound' => 'Inbound',
        'outbound' => 'Outbound',
        'logout' => 'Logout'
    ],
    'fr' => [
        'dashboard' => 'Tableau de bord',
        'inventory' => 'Inventaire',
        'inbound' => 'Entrée',
        'outbound' => 'Sortie',
        'logout' => 'Déconnexion'
    ]
];

function t($key) {
    global $texts, $lang;
    return $texts[$lang][$key] ?? $key;
}
