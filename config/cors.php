<?php

return [

    'paths' => ['api/*', 'addWordAPI'],

    'allowed_methods' => ['POST'],

    'allowed_origins' => ['chrome-extension://*', '*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
