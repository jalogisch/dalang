<?php
// dalang configuration. Copy this file to config.php (same folder, outside the web root) and fill it in.
// config.php is in .gitignore. Keep it out of git and out of the web root.
return [
    // Public address of your shortener, without a slash at the end.
    'base_url' => 'https://example.org',

    // Optional. Everything that is not a known short code is redirected here, path and query included.
    // Example: https://example.org/about -> https://www.example.org/about
    // Leave it empty ('') or delete the line, and unknown paths answer with 404 instead.
    'fallback_url' => 'https://www.example.org',

    // Hash of the admin password. Create it with:  php bin/hash-password.php
    // Use single quotes, the hash contains "$".
    'password_hash' => 'REPLACE-WITH-THE-OUTPUT-OF-bin/hash-password.php',

    // SQLite file. The folder must be writable for PHP and must not be inside public/.
    'database' => __DIR__ . '/data/links.sqlite',
];
