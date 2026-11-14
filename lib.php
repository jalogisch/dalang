<?php
// dalang - shared code for the public redirect and the admin.
declare(strict_types=1);

/** Loads config.php from the folder next to this file (outside the web root). */
function config(): array
{
    $file = __DIR__ . '/config.php';
    $cfg = is_file($file) ? require $file : null;
    foreach (['base_url', 'password_hash', 'database'] as $key) {
        if (!is_array($cfg) || !is_string($cfg[$key] ?? null) || $cfg[$key] === '') {
            throw new RuntimeException("config.php is missing or has no '$key'");
        }
    }
    // Optional: empty or not set means "unknown paths answer 404".
    $cfg['fallback_url'] ??= '';
    if (!is_string($cfg['fallback_url'])) {
        throw new RuntimeException("config.php: 'fallback_url' must be a string");
    }
    $cfg['base_url'] = rtrim($cfg['base_url'], '/');
    $cfg['fallback_url'] = rtrim($cfg['fallback_url'], '/');
    return $cfg;
}

/**
 * Opens the database. The public part passes $create = false: it never creates
 * anything, a missing file is an error (503) and not "unknown code".
 */
function db(array $cfg, bool $create): PDO
{
    if (!$create && !is_file($cfg['database'])) {
        throw new RuntimeException('database file not found');
    }
    $pdo = new PDO('sqlite:' . $cfg['database'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($create) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS links (
            code    TEXT PRIMARY KEY,
            url     TEXT NOT NULL,
            title   TEXT NOT NULL DEFAULT '',
            created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        // One row: the time until which no further login attempt is checked.
        $pdo->exec('CREATE TABLE IF NOT EXISTS brake (id INTEGER PRIMARY KEY CHECK (id = 1), until INTEGER NOT NULL)');
    }
    return $pdo;
}

/** Short codes: a-z, 0-9 and "-". Nothing that starts with "admin" (reserved for the admin path). */
function valid_code(string $code): bool
{
    return preg_match('/^[a-z0-9-]{1,40}$/D', $code) === 1 && !str_starts_with($code, 'admin');
}

/** Targets: http or https with a host, no whitespace, quotes or control characters. */
function valid_url(string $url): bool
{
    return strlen($url) <= 2000
        && preg_match('#^https?://[^\s"<>\\\\\x00-\x1f\x7f]+$#D', $url) === 1
        && !empty(parse_url($url, PHP_URL_HOST));
}

/** Titles: one line, at most 1000 characters; invalid UTF-8 becomes an empty title. */
function clean_title(string $title): string
{
    $title = preg_replace('/\s+/u', ' ', trim($title));
    return is_string($title) && preg_match('/^.{0,1000}/u', $title, $m) === 1 ? $m[0] : '';
}

/**
 * Picks the admin language from Accept-Language (highest q first), falls back to English.
 * A language is available when lang/<code>.php exists.
 */
function pick_lang(string $header): string
{
    $wanted = [];
    foreach (explode(',', strtolower($header)) as $i => $part) {
        if (preg_match('/^\s*([a-z]{2,3})(?:-[a-z0-9-]+)?\s*(?:;\s*q=([01](?:\.\d{0,3})?))?\s*$/', $part, $m) === 1) {
            $q = isset($m[2]) ? (float) $m[2] : 1.0;
            if ($q > 0 && !isset($wanted[$m[1]])) {
                $wanted[$m[1]] = [$q, -$i];   // same q: earlier entry wins
            }
        }
    }
    arsort($wanted);
    foreach (array_keys($wanted) as $lang) {
        if (is_file(__DIR__ . "/lang/$lang.php")) {
            return $lang;
        }
    }
    return 'en';
}

/** Texts of the chosen language; keys missing there come from English. */
function texts(string $lang): array
{
    return (require __DIR__ . "/lang/$lang.php") + (require __DIR__ . '/lang/en.php');
}
