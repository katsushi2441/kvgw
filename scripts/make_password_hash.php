<?php
// ログインパスワードのハッシュを作る: php scripts/make_password_hash.php
echo "パスワードを入力してEnter: ";
$pw = trim(fgets(STDIN));
if ($pw === '') { echo "空です\n"; exit(1); }
echo "kvgwc_config.php の KVGWC_PASSWORD_HASH に貼ってください:\n";
echo password_hash($pw, PASSWORD_DEFAULT) . "\n";
