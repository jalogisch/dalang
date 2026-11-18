<?php
// dalang - admin. Reachable only at /admin/. Login, add, list, delete, CSV import and export.
// Three views on the same address: the form (default, the bookmarklet opens it), ?view=links, ?view=tools.
// Rule: only a POST with a valid CSRF token changes anything. A GET only shows.
declare(strict_types=1);

require __DIR__ . '/../../lib.php';

const LOGIN_WAIT = 5;            // seconds between two checked login attempts (for everybody together)
const SESSION_SECONDS = 1209600; // 14 days, so the bookmarklet does not ask for the password every time

try {
    $cfg = config();
    $db = db($cfg, true);
} catch (Throwable $e) {
    error_log('dalang: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("dalang: configuration or database problem. See the PHP error log.\n");
}

// --- session ---------------------------------------------------------------------------
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) SESSION_SECONDS);
session_name('dalang');
session_cache_limiter('');
// SameSite=Lax, not Strict: the bookmarklet opens /admin/ from another site, and with Strict
// the cookie would not come along. Every POST needs the CSRF token anyway.
session_set_cookie_params([
    'lifetime' => SESSION_SECONDS, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$loggedIn = ($_SESSION['in'] ?? false) === true;

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Cross-Origin-Opener-Policy: same-origin');

$lang = pick_lang((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$t = texts($lang);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function field(array $from, string $key): string
{
    $v = $from[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Redirect after POST, so a reload does not repeat the action. */
function back(string $query = ''): never
{
    header('Location: /admin/' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

// The bookmarklet sends ?url=...&title=... - this only prefills the form.
$prefill = ['url' => substr(field($_GET, 'url'), 0, 2000), 'title' => clean_title(field($_GET, 'title'))];
$prefillQuery = $prefill['url'] !== '' ? http_build_query($prefill) : '';
// Anything unknown shows the form.
$view = in_array(field($_GET, 'view'), ['links', 'tools'], true) ? field($_GET, 'view') : 'add';

// --- actions (POST only) ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit($t['err_csrf'] . "\n");
    }
    $action = field($_POST, 'action');

    if ($action === 'login') {
        // Brake: take the one slot; whoever comes before it is free again is not checked at all.
        $now = time();
        $db->exec('INSERT OR IGNORE INTO brake (id, until) VALUES (1, 0)');
        $slot = $db->prepare('UPDATE brake SET until = ? WHERE id = 1 AND until <= ?');
        $slot->execute([$now + LOGIN_WAIT, $now]);
        $password = $_POST['password'] ?? '';
        if ($slot->rowCount() !== 1) {
            $_SESSION['flash'] = ['errors' => ['password' => 'err_wait']];
        } elseif (is_string($password) && password_verify($password, $cfg['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['in'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $db->exec('UPDATE brake SET until = 0 WHERE id = 1');
        } else {
            $_SESSION['flash'] = ['errors' => ['password' => 'err_password']];
        }
        back($prefillQuery);
    }

    if (!$loggedIn) {
        back();
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        setcookie('dalang', '', ['expires' => 1, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        back();
    }

    if ($action === 'add') {
        $old = ['url' => field($_POST, 'url'), 'code' => field($_POST, 'code'), 'title' => clean_title(field($_POST, 'title'))];
        $errors = [];
        if (!valid_url($old['url'])) {
            $errors['url'] = 'err_url';
        }
        if ($old['code'] !== '' && !valid_code($old['code'])) {
            $errors['code'] = 'err_code';
        }
        if (!$errors) {
            $insert = $db->prepare('INSERT OR IGNORE INTO links (code, url, title) VALUES (?, ?, ?)');
            $code = $old['code'];
            if ($code !== '') {
                $insert->execute([$code, $old['url'], $old['title']]);
                if ($insert->rowCount() !== 1) {
                    $errors['code'] = 'err_taken';
                }
            } else {
                // No code given: 5 random characters, a few tries in case one is taken.
                $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
                for ($try = 0, $done = false; $try < 5 && !$done; $try++) {
                    $code = '';
                    for ($i = 0; $i < 5; $i++) {
                        $code .= $alphabet[random_int(0, 35)];
                    }
                    if (valid_code($code)) {
                        $insert->execute([$code, $old['url'], $old['title']]);
                        $done = $insert->rowCount() === 1;
                    }
                }
                if (!$done) {
                    $errors['code'] = 'err_random';
                }
            }
        }
        $_SESSION['flash'] = $errors ? ['errors' => $errors, 'old' => $old] : ['created' => $code];
        back();
    }

    if ($action === 'delete') {
        $find = $db->prepare('SELECT code, url FROM links WHERE code = ?');
        $find->execute([field($_POST, 'code')]);
        if ($row = $find->fetch()) {
            $db->prepare('DELETE FROM links WHERE code = ?')->execute([$row['code']]);
            // Name code and target, so a slip can be typed in again.
            $_SESSION['flash'] = ['msg' => sprintf($t['msg_deleted'], $row['code'], $row['url'])];
        }
        back('view=links');
    }

    if ($action === 'import') {
        $file = $_FILES['csv'] ?? null;
        $handle = is_array($file) && $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])
            ? fopen($file['tmp_name'], 'r') : false;
        if ($handle === false) {
            $_SESSION['flash'] = ['errors' => ['csv' => 'err_upload']];
            back('view=tools');
        }
        // Columns by position: code, url, title, created. More columns are ignored.
        $insert = $db->prepare('INSERT OR IGNORE INTO links (code, url, title, created) VALUES (?, ?, ?, ?)');
        $new = $known = 0;
        $rejected = [];
        $db->beginTransaction();
        for ($line = 1; ($row = fgetcsv($handle, null, ',', '"', '')) !== false; $line++) {
            $code = trim((string) ($row[0] ?? ''));
            $url = trim((string) ($row[1] ?? ''));
            if ($row === [null] || ($line === 1 && !valid_url($url))) {
                continue;   // empty line or header line
            }
            if (!valid_code($code) || !valid_url($url)) {
                $rejected[] = $line;
                continue;
            }
            $created = (string) ($row[3] ?? '');
            if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $created) !== 1) {
                $created = gmdate('Y-m-d H:i:s');
            }
            $insert->execute([$code, $url, clean_title((string) ($row[2] ?? '')), $created]);
            $insert->rowCount() === 1 ? $new++ : $known++;
        }
        $db->commit();
        fclose($handle);
        $msg = sprintf($t['msg_imported'], $new, $known, count($rejected));
        if ($rejected) {
            $msg .= ' ' . sprintf($t['msg_rejected_lines'], implode(', ', array_slice($rejected, 0, 20)));
        }
        $_SESSION['flash'] = ['msg' => $msg];
        back('view=tools');
    }

    back();
}

// --- CSV export (GET, changes nothing) --------------------------------------------------
if ($loggedIn && isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dalang-links-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['code', 'url', 'title', 'created'], ',', '"', '');
    foreach ($db->query('SELECT code, url, title, created FROM links ORDER BY created, code') as $row) {
        fputcsv($out, $row, ',', '"', '');
    }
    exit;
}

// --- page --------------------------------------------------------------------------------
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$errors = $flash['errors'] ?? [];
$old = ($flash['old'] ?? []) + $prefill + ['code' => ''];
$csrf = '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';

/** Attributes that link a field to its error message. */
$err = fn(string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . $name . '-err"' : '';
/** The error message of a field. */
$errText = fn(string $name): string => isset($errors[$name]) ? '<p class="err" id="' . $name . '-err">' . h($t[$errors[$name]]) . '</p>' : '';

$bookmarklet = 'javascript:void(open(' . json_encode($cfg['base_url'] . '/admin/?url=', JSON_UNESCAPED_SLASHES)
    . '+encodeURIComponent(location.href)+"&title="+encodeURIComponent(document.title),"_blank","noopener"))';
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $loggedIn ? h($t['nav_' . $view]) . ' - ' : '' ?><?= h($t['title']) ?></title>
<style nonce="<?= h($nonce) ?>">
:root { color-scheme: light dark; --line: #8886; --stripe: #8881; --accent: #2b6cb0; }
* { box-sizing: border-box; }
body { font: 1rem/1.5 system-ui, sans-serif; max-width: 72rem; margin: 0 auto; padding: 1rem 1.25rem 3rem; }
h1 { margin: .5rem 0; } h2 { margin: 2rem 0 .5rem; padding-bottom: .25rem; border-bottom: 1px solid var(--line); }
label { display: block; margin-top: .9rem; font-weight: 600; }
input[type=url], input[type=text], input[type=password] { width: 100%; max-width: 40rem; padding: .55rem .7rem; font: inherit; border: 1px solid var(--line); border-radius: 6px; }
input[type=file] { font: inherit; margin-top: .25rem; }
button { display: block; font: inherit; min-height: 44px; padding: .45rem 1rem; margin-top: .9rem; border: 1px solid var(--line); border-radius: 6px; cursor: pointer; }
main > form button, .created button { background: var(--accent); color: #fff; border-color: var(--accent); }
:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
td button, header button { margin: 0; }
td button { min-height: 36px; padding: .25rem .7rem; }
.hint { margin: .1rem 0 0; font-size: .9rem; opacity: .85; }
.err { margin: .25rem 0 0; font-weight: 600; color: #b00020; }
.msg, .created { border: 2px solid; border-radius: 6px; padding: .75rem 1rem; }
.created a { font-size: 1.6rem; font-weight: 700; overflow-wrap: anywhere; }
.tabelle { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; min-width: 48rem; table-layout: fixed; font-size: .95rem; }
caption { text-align: left; font-weight: 600; padding: .25rem 0 .5rem; }
th, td { text-align: left; vertical-align: top; padding: .45rem .6rem; border-top: 1px solid var(--line); }
thead th { position: sticky; top: 0; background: Canvas; border-bottom: 2px solid var(--line); white-space: nowrap; }
tbody tr:nth-child(even) { background: var(--stripe); }
th:nth-child(1) { width: 9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
td:nth-child(2) a, td:nth-child(3) { overflow-wrap: anywhere; }
th:nth-child(3) { width: 28%; }
th:nth-child(4) { width: 7.5rem; } td:nth-child(4) { white-space: nowrap; font-variant-numeric: tabular-nums; }
th:nth-child(5) { width: 6.5rem; }
nav { display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin: .25rem 0 0; }
nav a { display: inline-block; padding: .6rem .2rem; border-bottom: 3px solid transparent; }
nav a[aria-current] { font-weight: 700; border-color: var(--accent); }
header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
@media (prefers-color-scheme: dark) { :root { --accent: #63a4ff; } main > form button, .created button { color: #111; } .err { color: #ff8a80; } }
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
<main>
<h1><?= h($t['title']) ?></h1>
<form method="post" action="/admin/<?= $prefillQuery !== '' ? '?' . h($prefillQuery) : '' ?>">
<?= $csrf ?>
<input type="hidden" name="action" value="login">
<label for="password"><?= h($t['password']) ?></label>
<input type="password" id="password" name="password" required autofocus autocomplete="current-password"<?= $err('password') ?>>
<?= $errText('password') ?>
<button><?= h($t['login']) ?></button>
</form>
</main>
<?php else: ?>
<header>
<h1><?= h($t['title']) ?></h1>
<form method="post" action="/admin/">
<?= $csrf ?>
<input type="hidden" name="action" value="logout">
<button><?= h($t['logout']) ?></button>
</form>
</header>
<nav aria-label="<?= h($t['nav']) ?>">
<?php foreach (['add' => '/admin/', 'links' => '/admin/?view=links', 'tools' => '/admin/?view=tools'] as $name => $href): ?>
<a href="<?= $href ?>"<?= $name === $view ? ' aria-current="page"' : '' ?>><?= h($t['nav_' . $name]) ?></a>
<?php endforeach ?>
</nav>
<main>
<?php if (isset($flash['created'])): $short = $cfg['base_url'] . '/' . $flash['created']; ?>
<div class="created" role="status">
<p class="hint"><?= h($t['msg_created']) ?></p>
<p><a id="short" href="<?= h($short) ?>"><?= h($short) ?></a></p>
<button type="button" id="copy" data-done="<?= h($t['copied']) ?>"><?= h($t['copy']) ?></button>
</div>
<script nonce="<?= h($nonce) ?>">
document.getElementById('copy').addEventListener('click', function () {
  var button = this;
  navigator.clipboard.writeText(document.getElementById('short').href).then(function () {
    button.textContent = button.dataset.done;
  });
});
</script>
<?php endif ?>
<?php if (isset($flash['msg'])): ?>
<p class="msg" role="status"><?= h($flash['msg']) ?></p>
<?php endif ?>

<?php if ($view === 'add'): ?>
<h2><?= h($t['add_heading']) ?></h2>
<form method="post" action="/admin/">
<?= $csrf ?>
<input type="hidden" name="action" value="add">
<label for="url"><?= h($t['url']) ?></label>
<input type="url" id="url" name="url" required maxlength="2000" value="<?= h($old['url']) ?>"<?= $err('url') ?>>
<?= $errText('url') ?>
<label for="code"><?= h($t['code']) ?></label>
<p class="hint" id="code-hint"><?= h($t['code_hint']) ?></p>
<input type="text" id="code" name="code" maxlength="40" pattern="[a-z0-9\-]{1,40}" autocapitalize="none" spellcheck="false" value="<?= h($old['code']) ?>"<?= isset($errors['code']) ? ' aria-invalid="true" aria-describedby="code-hint code-err"' : ' aria-describedby="code-hint"' ?>>
<?= $errText('code') ?>
<label for="title"><?= h($t['link_title']) ?></label>
<input type="text" id="title" name="title" maxlength="1000" value="<?= h($old['title']) ?>">
<button><?= h($t['add']) ?></button>
</form>
<?php elseif ($view === 'tools'): ?>

<h2><?= h($t['csv_heading']) ?></h2>
<p><a href="/admin/?export"><?= h($t['export']) ?></a> - <?= h($t['export_hint']) ?></p>
<form method="post" action="/admin/" enctype="multipart/form-data">
<?= $csrf ?>
<input type="hidden" name="action" value="import">
<label for="csv"><?= h($t['import_file']) ?></label>
<p class="hint" id="csv-hint"><?= h($t['import_hint']) ?></p>
<input type="file" id="csv" name="csv" required accept=".csv,text/csv"<?= isset($errors['csv']) ? ' aria-invalid="true" aria-describedby="csv-hint csv-err"' : ' aria-describedby="csv-hint"' ?>>
<?= $errText('csv') ?>
<button><?= h($t['import']) ?></button>
</form>

<h2><?= h($t['bookmarklet_heading']) ?></h2>
<p><?= h($t['bookmarklet_hint']) ?> <a href="<?= h($bookmarklet) ?>"><?= h($t['bookmarklet_name']) ?></a></p>

<?php else: ?>
<h2><?= h($t['list_heading']) ?></h2>
<?php $rows = $db->query('SELECT code, url, title, created FROM links ORDER BY created DESC, code')->fetchAll(); ?>
<?php if (!$rows): ?>
<p><?= h($t['list_empty']) ?></p>
<?php else: ?>
<div class="tabelle">
<table>
<caption><?= h(sprintf($t['list_count'], count($rows))) ?></caption>
<thead><tr><th scope="col"><?= h($t['col_code']) ?></th><th scope="col"><?= h($t['col_url']) ?></th><th scope="col"><?= h($t['col_title']) ?></th><th scope="col"><?= h($t['col_created']) ?></th><th scope="col"><?= h($t['col_action']) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<th scope="row"><?= h($row['code']) ?></th>
<td><a href="<?= h($row['url']) ?>" rel="noreferrer noopener"><?= h($row['url']) ?></a></td>
<td><?= h($row['title']) ?></td>
<td><time datetime="<?= h($row['created']) ?>"><?= h(substr($row['created'], 0, 10)) ?></time></td>
<td><form method="post" action="/admin/"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="code" value="<?= h($row['code']) ?>"><button aria-label="<?= h(sprintf($t['delete_label'], $row['code'])) ?>"><?= h($t['delete']) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php endif ?>
</main>
<?php endif ?>
</body>
</html>
