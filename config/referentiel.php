<?php

return [
    'private_shared_group' => (bool) env('PRIVATE_SHARED_GROUP', false),
    'version' => env('RELEASE_VERSION', is_file(base_path('RELEASE.json')) ? (json_decode(file_get_contents(base_path('RELEASE.json')), true)['version'] ?? 'dev') : 'dev'),
    'storage_quota' => (int) env('STORAGE_QUOTA_BYTES', 1073741824),
    'external_storage_used' => (int) env('EXTERNAL_STORAGE_USED_BYTES', 0),
    'batch_size' => 300,
    'languages' => ['de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'en' => 'English'],
    'scope_prefixes' => ['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'],
    'unconfirmed_scope_prefixes' => ['PHV', 'WIP', 'TEST', 'OLD'],
];
