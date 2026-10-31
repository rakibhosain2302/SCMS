<?php

/*
 * App menus in nit-master's format: one array per module. On a module's pages (controller
 * namespace contains the key) only that menu is shown; the dashboard shows all of them.
 * nit-master adds its own menu as "master", scms-finance as "finance".
 */
return [
    'members' => [
        ['sl' => 0, 'title' => 'Main dashboard', 'icon' => 'fas fa-th-large', 'url' => '/', 'dashboard' => true],
        ['sl' => 0.5, 'header' => 'Members', 'permission' => ['member_view', 'member_create']],
        ['sl' => 0.6, 'title' => 'Members', 'icon' => 'fas fa-users', 'url' => '/members'],
        ['sl' => 0.7, 'title' => 'Register member', 'icon' => 'fas fa-user-plus', 'url' => '/members/create'],
        ['sl' => 0.8, 'title' => 'Import members', 'icon' => 'fas fa-file-import', 'url' => '/members/import'],
    ],
];
