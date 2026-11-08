<?php

return [
    'default' => env('MAIL_MAILER', 'smtp'),
    'mailers' => [
        'smtp' => ['transport' => 'smtp', 'host' => env('MAIL_HOST', '127.0.0.1'), 'port' => env('MAIL_PORT', 2525), 'encryption' => env('MAIL_ENCRYPTION'), 'username' => env('MAIL_USERNAME'), 'password' => env('MAIL_PASSWORD'), 'timeout' => null, 'local_domain' => env('MAIL_EHLO_DOMAIN')],
        'ses' => ['transport' => 'ses'], 'mailgun' => ['transport' => 'mailgun'], 'postmark' => ['transport' => 'postmark'], 'sendmail' => ['transport' => 'sendmail', 'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs')], 'log' => ['transport' => 'log', 'channel' => env('LOG_CHANNEL')], 'array' => ['transport' => 'array'], 'failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'noreply@piie.ac.ug'), 'name' => env('MAIL_FROM_NAME', 'Prime International Institute of Excellence')],
    'markdown' => ['theme' => 'default', 'paths' => [resource_path('views/vendor/mail')]],
];
