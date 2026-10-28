<?php

return [
    // Name of a request header that carries the real client IP when a trusted proxy (e.g. Cloudflare:
    // CF-Connecting-IP) sits in front of the API. Leave empty to use the direct connection address.
    'ip_header' => env('ADMIN_IP_HEADER'),
];
