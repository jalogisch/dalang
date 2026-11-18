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
/* Colours after Tron: dark grid, cyan light, orange for danger. Every pair meets WCAG AA (text 4.5:1, borders 3:1). */
:root {
  color-scheme: light dark;
  --bg: #f3f7f9; --surface: #ffffff; --text: #0b1c24; --muted: #48616c; --accent: #00687e; --ink: #ffffff;
  --edge: #74909b; --line: #d3e0e5; --grid: #e3ecf0; --danger: #a84500; --glow: #00687e33;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #05090e; --surface: #0a1219; --text: #d4f2f9; --muted: #8aaab5; --accent: #4fd6ec; --ink: #04161c;
    --edge: #2f6c7c; --line: #13252e; --grid: #0c1820; --danger: #ff9a40; --glow: #4fd6ec55;
  }
}
* { box-sizing: border-box; }
html {
  background-color: var(--bg);
  background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
  background-size: 3rem 3rem;
}
body { font: 300 1rem/1.6 system-ui, sans-serif; color: var(--text); max-width: 72rem; margin: 0 auto; padding: 1rem 1.25rem 3rem; }
::selection { background: var(--accent); color: var(--ink); }
a { color: var(--accent); text-underline-offset: .2em; text-decoration-thickness: 1px; }
h1, h2 { font-weight: 400; text-transform: uppercase; letter-spacing: .14em; }
h1 { font-size: .8rem; margin: 0; color: var(--muted); }
h2 { font-size: .85rem; margin: 2.5rem 0 1rem; color: var(--accent); }
label { display: block; margin-top: 1.1rem; font-size: .8rem; font-weight: 400; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); }
input[type=url], input[type=text], input[type=password] {
  width: 100%; max-width: 40rem; margin-top: .3rem; padding: .6rem .75rem; font: inherit; font-weight: 400; color: var(--text);
  background: var(--surface); border: 1px solid var(--edge); border-radius: 3px;
}
input:focus { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent), 0 0 14px var(--glow); }
input[type=file] { width: 100%; max-width: 40rem; font: inherit; font-size: .9rem; margin-top: .4rem; color: var(--muted); }
button, .knopf, input[type=file]::file-selector-button {
  display: inline-block; font: inherit; font-size: .8rem; font-weight: 400; text-transform: uppercase; letter-spacing: .12em; text-decoration: none;
  min-height: 44px; padding: .65rem 1.2rem; color: var(--accent); background: transparent; border: 1px solid var(--accent); border-radius: 3px; cursor: pointer;
}
input[type=file]::file-selector-button { margin-right: .9rem; }
button:hover, .knopf:hover, input[type=file]::file-selector-button:hover { box-shadow: 0 0 14px var(--glow); }
main > form button, .created button { display: block; margin-top: 1.4rem; background: var(--accent); color: var(--ink); }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.leise { min-height: 36px; padding: .4rem .9rem; color: var(--muted); border-color: var(--edge); }
.hint { margin: .15rem 0 0; font-size: .85rem; color: var(--muted); }
.err { margin: .35rem 0 0; font-weight: 400; color: var(--danger); }
input[aria-invalid] { border-color: var(--danger); }
.msg, .created { border: 1px solid var(--edge); border-radius: 3px; padding: .9rem 1.1rem; background: var(--surface); }
.created { border-color: var(--accent); box-shadow: 0 0 18px var(--glow); }
.created a { font-size: 1.5rem; font-weight: 400; letter-spacing: .02em; overflow-wrap: anywhere; }
.knopf[href^="javascript:"] { cursor: grab; border-style: dashed; }
.tabelle { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; min-width: 48rem; table-layout: fixed; font-size: .92rem; }
caption { text-align: left; font-size: .8rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); padding: 0 0 .6rem; }
th, td { text-align: left; vertical-align: top; padding: .5rem .6rem; border-top: 1px solid var(--line); font-weight: 300; }
thead th { position: sticky; top: 0; background: var(--bg); border-bottom: 1px solid var(--edge); white-space: nowrap; font-size: .75rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); }
tbody tr { background: var(--surface); }
tbody th { font-family: ui-monospace, monospace; font-size: .88rem; color: var(--accent); }
th:nth-child(1) { width: 9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
td:nth-child(2) a, td:nth-child(3) { overflow-wrap: anywhere; }
th:nth-child(3) { width: 28%; }
th:nth-child(4) { width: 7.5rem; } td:nth-child(4) { white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--muted); }
th:nth-child(5) { width: 7rem; }
td button { min-height: 32px; padding: .25rem .6rem; font-size: .7rem; color: var(--danger); border-color: var(--danger); }
header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem 1.5rem; border-bottom: 1px solid var(--line); }
nav { display: flex; flex-wrap: wrap; gap: 0 1.5rem; }
nav a { display: inline-block; padding: .9rem 0 .7rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .14em; text-decoration: none; color: var(--muted); border-bottom: 2px solid transparent; margin-bottom: -1px; }
nav a:hover { color: var(--text); }
nav a[aria-current] { color: var(--accent); border-color: var(--accent); text-shadow: 0 0 10px var(--glow); }
.wer { display: flex; align-items: center; gap: 1rem; }
.login { max-width: 24rem; margin: 18vh auto 0; }
.login h1 { font-size: 1rem; color: var(--accent); }
@media (forced-colors: active) { nav a[aria-current] { border-color: CanvasText; } }
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
<main class="login">
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
<nav aria-label="<?= h($t['nav']) ?>">
<?php foreach (['add' => '/admin/', 'links' => '/admin/?view=links', 'tools' => '/admin/?view=tools'] as $name => $href): ?>
<a href="<?= $href ?>"<?= $name === $view ? ' aria-current="page"' : '' ?>><?= h($t['nav_' . $name]) ?></a>
<?php endforeach ?>
</nav>
<div class="wer">
<h1><?= h($t['title']) ?></h1>
<form method="post" action="/admin/">
<?= $csrf ?>
<input type="hidden" name="action" value="logout">
<button class="leise"><?= h($t['logout']) ?></button>
</form>
</div>
</header>
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
<p><a class="knopf" href="/admin/?export"><?= h($t['export']) ?></a></p>
<p class="hint"><?= h($t['export_hint']) ?></p>
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
<p class="hint"><?= h($t['bookmarklet_hint']) ?></p>
<p><a class="knopf" href="<?= h($bookmarklet) ?>"><?= h($t['bookmarklet_name']) ?></a></p>

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
