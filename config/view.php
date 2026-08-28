<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Laravel's default is storage/framework/views. On Fly the storage_dir volume
    | is mounted over /var/www/html/storage, which shadows anything the image put
    | there -- a build-time `view:cache` would be discarded on boot and every
    | request would recompile Blade on demand. bootstrap/cache is part of the
    | image and is not shadowed, so precompiled views actually survive to runtime.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        base_path('bootstrap/cache/views')
    ),

];
