<?php

return [
    'home' => env('ACME_HOME', '/home/smos/acme'),
    'certs' => env('ACME_CERTS', '/home/smos/acme/certs'),
    'binary' => env('ACME_BINARY', storage_path('app/acme/acme.sh')),
];
