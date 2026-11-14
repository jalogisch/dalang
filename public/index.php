<?php
// dalang - public entry point. Looks up a short code and redirects. Unknown: fallback or 404.
// Never writes, stores nothing about visitors.
declare(strict_types=1);

require __DIR__ . '/../lib.php';

try {
    $cfg = config();
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/') {
        $uri = '/';
    }
    $target = false;
    if (preg_match('#^/([a-z0-9-]{1,40})(?:\?|$)#D', $uri, $m) === 1) {
        $stmt = db($cfg, false)->prepare('SELECT url FROM links WHERE code = ?');
        $stmt->execute([$m[1]]);
        $target = $stmt->fetchColumn();
    }
    if ($target !== false) {
        header('Location: ' . $target, true, 301);
    } elseif ($cfg['fallback_url'] !== '') {
        // Unknown code or any other path: hand it to the fallback site, path and query unchanged.
        header('Location: ' . $cfg['fallback_url'] . $uri, true, 301);
    } else {
        // 404, not 410: dalang does not remember deleted codes, so it can not say "gone".
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 - there is no short link here.\n";
    }
} catch (Throwable $e) {
    // No 301 here: browsers remember a 301, and a wrong one would stick.
    error_log('dalang: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
}
