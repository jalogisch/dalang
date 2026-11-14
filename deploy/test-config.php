<?php
// Configuration for the local test only (deploy/compose.yml, deploy/test.sh). Never use it anywhere else:
// the password is public, it is "dalang-test-password".
// deploy/test.sh creates the two marker files to test the answers without a fallback (404).
return (is_file('/data/fallback-unset') ? [] : [
    'fallback_url' => is_file('/data/fallback-empty') ? '' : 'https://www.example.org',
]) + [
    'base_url' => 'https://short.example.org',
    'password_hash' => '$2y$12$tILjxxQUhc0BxXsKJOBsSOuh4DLKLuBDPWrOSuGG1o4sj/kHCIDHS',
    'database' => '/data/links.sqlite',
];
