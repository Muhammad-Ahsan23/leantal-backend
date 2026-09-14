<?php

use Illuminate\Support\Facades\Storage;

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Two R2 disks — one per storage region, matching the client-
        // confirmed split (EU+UK share the EU bucket; US+rest-of-world
        // use the US bucket). Which one a given candidate's resume uses
        // is decided by RegionResolver::storageDiskFor() at upload time,
        // based on the COMPANY's region — never hardcoded here.
        //
        // IMPORTANT: each bucket has its own JURISDICTION (US / EU),
        // and jurisdiction-scoped R2 buckets require a jurisdiction-
        // specific endpoint hostname (the account-wide default endpoint
        // returns "NoSuchBucket" for them) — hence two separate endpoint
        // env vars below, not one shared R2_ENDPOINT.
        'r2_us' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_US_BUCKET'),
            'endpoint' => env('R2_US_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'visibility' => 'private',
            'options' => array_filter([
                'verify' => env('AWS_CA_BUNDLE_PATH') ?: null,
            ]),
        ],

        'r2_eu' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_EU_BUCKET'),
            'endpoint' => env('R2_EU_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'visibility' => 'private',
            'options' => array_filter([
                'verify' => env('AWS_CA_BUNDLE_PATH') ?: null,
            ]),
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
