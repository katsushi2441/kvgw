<?php
/**
 * 台帳が Web から直接読めない状態になっているかを確認する。
 * 実行: php scripts/check_data_protection.php https://proto.exbridge.jp/kvgwc
 *
 * .htaccess が効いていないサーバーだと、users.json をURLで叩くだけで
 * 社員のメールアドレスが全部取れてしまう。設置のたびに必ず確認する。
 */
$base = isset($argv[1]) ? rtrim($argv[1], '/') : '';
if ($base === '') { echo "使い方: php scripts/check_data_protection.php <設置先URL>\n"; exit(1); }

$targets = array(
    'kvgwc_data/employees.json'  => '社員マスタ',
    'kvgwc_data/users.json'      => 'アカウント台帳',
    'kvgwc_data/app_nippou.json' => '日報の記録',
    'kvgwc_data/app_schedule.json' => 'スケジュールの記録',
    'kvgwc_config.php'           => '設定(クライアントシークレット)',
);

$fail = 0;
foreach ($targets as $path => $label) {
    $url = $base . '/' . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15));
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 403/404 なら塞がっている。200でも中身が漏れていなければ可(PHPは実行されるため空)。
    $leaked = ($code === 200 && (strpos($body, '"users"') !== false
              || strpos($body, '"employees"') !== false
              || strpos($body, '"records"') !== false
              || strpos($body, 'KVGWC_CLIENT_SECRET') !== false));
    if ($leaked) { $fail++; echo "  危険 $label ($code) $url\n"; }
    else { echo "  OK   $label ($code)\n"; }
}
echo $fail === 0 ? "\n台帳は外から読めません。\n" : "\n$fail 件が読めてしまいます。すぐ塞いでください。\n";
exit($fail === 0 ? 0 : 1);
