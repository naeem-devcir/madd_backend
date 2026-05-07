<?php
// config/magento.php

return [
    'base_url'   => env('MAGENTO_BASE_URL', 'http://dev.magento22.local/'),
    'admin_user' => env('MAGENTO_ADMIN_USER', 'admin'),
    'admin_pass' => env('MAGENTO_ADMIN_PASS', ''),
    'access_token' => env('MAGENTO_ACCESS_TOKEN', ''),
];