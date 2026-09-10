<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
echo "Password (12–72 bytes, input is visible): ";
$password=trim((string)fgets(STDIN));
if (strlen($password)<12 || strlen($password)>72) { fwrite(STDERR,"Invalid password length\n"); exit(1); }
echo password_hash($password,PASSWORD_DEFAULT).PHP_EOL;
