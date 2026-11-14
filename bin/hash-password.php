<?php
// dalang - prints the hash for 'password_hash' in config.php.
// Usage: php bin/hash-password.php     (asks for the password, nothing is stored)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$tty = function_exists('posix_isatty') && posix_isatty(STDIN);
fwrite(STDERR, 'Password (at least 12 characters): ');
if ($tty) {
    shell_exec('stty -echo');   // do not show the password while typing
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($tty) {
    shell_exec('stty echo');
    fwrite(STDERR, "\n");
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Too short. Nothing printed.\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), "\n";
