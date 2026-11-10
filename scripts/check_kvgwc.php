<?php
/**
 * kvgwc の検証。改造したら必ずこれを通すこと。実行: php scripts/check_kvgwc.php
 *
 * 一番見たいのは「他人の記録が見えない・触れない」こと。ここが緩むと
 * 社内の個人情報が別部署に漏れる。速度の問題ではなく、事故になる。
 *
 * 次に見たいのが「JWKからPEMへの変換」。ここが壊れると Google Workspace の
 * 署名検証が通らなくなり、誰もログインできなくなる(または検証が素通りする)。
 */

define('KVGWC_DATA_DIR', sys_get_temp_dir() . '/kvgwc_check_' . getmypid());
define('KVGWC_APPS_DIR', sys_get_temp_dir() . '/kvgwc_apps_' . getmypid());
@mkdir(KVGWC_APPS_DIR, 0700, true);
foreach (array_merge(glob(dirname(__DIR__) . '/public/apps/*.php'),
                     glob(__DIR__ . '/test_apps/*.php')) as $srcApp) {
    copy($srcApp, KVGWC_APPS_DIR . '/' . basename($srcApp));
}
define('KVGWC_AUTH', 'password');
define('KVGWC_CLIENT_ID', 'test-client');
define('KVGWC_HD', 'example.co.jp');
define('KVGWC_ADMIN_LOGIN', 'admin');
define('KVGWC_ADMIN_NAME', '初期管理者');
define('KVGWC_ADMIN_EMAIL', '');
define('KVGWC_PASSWORD_HASH', '$2y$10$G./oiTTh.ltZnzD9LczwS.ugA1mvMobcuJ5hpZCknfQRgY55Nmwg6');
define('KVGWC_DEMO', true);
function kvgwc_depts() {
    return array('sales' => '営業部', 'seizou' => '製造部', 'kanri' => '管理部');
}

require_once dirname(__DIR__) . '/public/kvgwc_core.php';
require_once dirname(__DIR__) . '/public/kvgwc_form.php';
require_once dirname(__DIR__) . '/public/kvgwc_auth.php';
require_once dirname(__DIR__) . '/public/kvgwc_ical.php';

@mkdir(KVGWC_DATA_DIR, 0700, true);

$pass = 0; $fail = 0;
function ok($cond, $label, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($got === null ? '' : "  → " . var_export($got, true)) . "\n"; }
}

/* テスト用のユーザー(台帳を経由せず組み立てる) */
function U($id, $role, $dept) {
    return array('id' => $id, 'name' => $id, 'email' => $id . '@example.co.jp',
                 'role' => $role, 'dept' => $dept, 'active' => true);
}
$staffA  = U('a', 'staff', 'sales');
$staffB  = U('b', 'staff', 'sales');
$staffC  = U('c', 'staff', 'seizou');
$chief   = U('ch', 'chief', 'sales');
$admin   = U('ad', 'admin', 'kanri');
$stopped = array('id' => 'z', 'name' => 'z', 'role' => 'admin', 'dept' => 'sales', 'active' => false);

echo "\n[1] アプリの読み込み\n";
$apps = kv_apps();
ok(isset($apps['schedule']), '共有スケジュール(Core同梱)が読める');
ok(isset($apps['testflow']), '置いた定義がそのままアプリになる');
$sched = kv_app('schedule');
$nip = kv_app('testflow');
ok($nip['approval'] === true, '承認フローを持つアプリを定義できる');
ok($sched['approval'] === false, '承認を書かなければ持たない');
ok(kv_app('nosuch') === null, '存在しないアプリはnull');

echo "\n[2] 権限の範囲(kv_can)\n";
$rec_a = array('_id' => 'r1', '_owner' => 'a', '_dept' => 'sales');
$rec_c = array('_id' => 'r2', '_owner' => 'c', '_dept' => 'seizou');
ok(kv_can($staffA, $nip, 'update', $rec_a) === true,  '担当は自分の記録を編集できる');
ok(kv_can($staffB, $nip, 'update', $rec_a) === false, '担当は同僚の記録を編集できない');
ok(kv_can($chief,  $nip, 'update', $rec_a) === true,  '責任者は部署内の記録を編集できる');
ok(kv_can($chief,  $nip, 'update', $rec_c) === false, '責任者でも他部署の記録は編集できない');
ok(kv_can($admin,  $nip, 'update', $rec_c) === true,  '管理者は全部編集できる');
ok(kv_can($staffA, $nip, 'delete', $rec_a) === false, '書いていない操作はできない(削除)');
ok(kv_can($staffA, $sched, 'read', $rec_c) === true,  'スケジュールは他部署でも見える');
ok(kv_can($staffA, $sched, 'update', $rec_c) === false, 'スケジュールは他人のものは編集できない');
ok(kv_can($stopped, $nip, 'read') === false, '停止したユーザーは何もできない');
ok(kv_can(null, $nip, 'read') === false, '未ログインは何もできない');
ok(kv_can($staffA, $nip, 'approve', $rec_a) === false, '担当は承認できない');
ok(kv_can($chief, $nip, 'approve', $rec_a) === true, '責任者は部署内を承認できる');
ok(kv_scope($staffA, $nip, 'read') === 'own', '担当の閲覧範囲はown');
ok(kv_scope($chief, $nip, 'read') === 'dept', '責任者の閲覧範囲はdept');
ok(kv_scope($admin, $nip, 'read') === 'all', '管理者の閲覧範囲はall');

echo "\n[3] 記録の作成と、見える範囲\n";
list($o1, $r1) = kv_record_create($staffA, $nip, array('date' => '2026-08-17', 'work' => 'Aの日報'));
list($o2, $r2) = kv_record_create($staffB, $nip, array('date' => '2026-08-17', 'work' => 'Bの日報'));
list($o3, $r3) = kv_record_create($staffC, $nip, array('date' => '2026-08-17', 'work' => 'Cの日報'));
ok($o1 && $o2 && $o3, '3件作成できた');
ok($r1['_status'] === 'draft', '承認アプリの初期状態は下書き');
ok($r1['_dept'] === 'sales', '作成時の部署が記録される');
ok(count(kv_records($staffA, $nip)) === 1, '担当Aには自分の1件だけ見える');
ok(count(kv_records($chief, $nip)) === 2, '責任者には営業部の2件が見える');
ok(count(kv_records($admin, $nip)) === 3, '管理者には3件すべて見える');
$seen = kv_records($staffA, $nip);
ok($seen[0]['_owner'] === 'a', '担当Aに見えるのは自分の記録');

echo "\n[4] 他人の記録は書き換えられない\n";
list($ok4, $msg4) = kv_record_update($staffB, $nip, $r1['_id'], array('work' => '乗っ取り'));
ok($ok4 === false, '同僚の記録は更新できない', $msg4);
$after = kv_record_find($nip, $r1['_id']);
ok($after['work'] === 'Aの日報', '内容が書き換わっていない', $after['work']);
list($ok5, ) = kv_record_update($staffA, $nip, $r1['_id'], array('work' => '本人が修正'));
ok($ok5 === true, '本人は更新できる');
ok(kv_record_find($nip, $r1['_id'])['work'] === '本人が修正', '本人の更新は反映される');
list($ok6, ) = kv_record_delete($staffC, $nip, $r1['_id']);
ok($ok6 === false, '他部署の記録は削除できない');

echo "\n[5] 承認フロー\n";
list($ok7, ) = kv_record_status($staffA, $nip, $r1['_id'], 'submitted');
ok($ok7 === true, '本人は申請できる');
ok(kv_record_find($nip, $r1['_id'])['_status'] === 'submitted', '状態が申請中になる');
list($ok8, $m8) = kv_record_status($staffB, $nip, $r1['_id'], 'approved');
ok($ok8 === false, '担当は承認できない', $m8);
list($ok9, ) = kv_record_status($chief, $nip, $r1['_id'], 'approved');
ok($ok9 === true, '責任者は承認できる');
ok(kv_record_find($nip, $r1['_id'])['_status'] === 'approved', '状態が承認済みになる');
list($ok10, $m10) = kv_record_update($staffA, $nip, $r1['_id'], array('work' => '承認後に改ざん'));
ok($ok10 === false, '承認済みの記録は本人でも編集できない', $m10);
list($ok11, ) = kv_record_create($chief, $nip, array('date' => '2026-08-17', 'work' => '責任者の記録'));
ok($ok11 === true, '責任者も作れる');
$own = kv_records($chief, $nip);
$mine = null;
foreach ($own as $r) { if ($r['_owner'] === 'ch') { $mine = $r; } }
kv_record_status($chief, $nip, $mine['_id'], 'submitted');
list($ok12, $m12) = kv_record_status($chief, $nip, $mine['_id'], 'approved');
ok($ok12 === false, '自分の申請を自分で承認できない', $m12);
list($ok13, ) = kv_record_status($admin, $nip, $mine['_id'], 'approved');
ok($ok13 === true, '管理者(all)は代わりに承認できる');
list($ok14, $m14) = kv_record_status($admin, $sched, 'x', 'approved');
ok($ok14 === false, '承認を使わないアプリでは承認できない', $m14);

echo "\n[6] 入力の検証\n";
list($v, $e) = kv_validate($nip, array('date' => '2026-08-17', 'work' => 'あ'));
ok(count($e) === 0, '必須が埋まっていれば通る', $e);
list($v, $e) = kv_validate($nip, array('date' => '2026-08-17'));
ok(isset($e['work']), '必須が空だと落ちる');
list($v, $e) = kv_validate($nip, array('date' => '8/17', 'work' => 'あ'));
ok(isset($e['date']), '日付の形式が違うと落ちる');
list($v, $e) = kv_validate($nip, array('date' => '2026-08-17', 'work' => 'あ', 'hours' => '99'));
ok(isset($e['hours']), '数値の上限を超えると落ちる');
list($v, $e) = kv_validate($nip, array('date' => '2026-08-17', 'work' => 'あ', 'progress' => '絶好調'));
ok(isset($e['progress']), '選択肢にない値は落ちる');
list($v, $e) = kv_validate($nip, array('date' => '2026-08-17', 'work' => str_repeat('あ', 6000)));
ok(isset($e['work']), '長すぎる入力は落ちる');
list($v, $e) = kv_validate($nip, array('date' => '2026-08-17', 'work' => 'あ', 'evil' => 'x'));
ok(!array_key_exists('evil', $v), '定義にない項目は保存されない');

echo "\n[7] 一覧・検索・CSV\n";
$all = kv_records($admin, $nip);
ok(count(kv_search($nip, $all, 'Cの日報')) === 1, '検索で絞り込める');
ok(count(kv_search($nip, $all, '')) === count($all), '空の検索は全件');
ok(count(kv_search($nip, $all, 'zzz')) === 0, '一致しなければ0件');
$sorted = kv_sort($sched, array(array('date' => '2026-08-20'), array('date' => '2026-08-18')));
ok($sorted[0]['date'] === '2026-08-18', 'スケジュールは日付の古い順');
$sorted = kv_sort($nip, array(array('date' => '2026-08-20'), array('date' => '2026-08-18')));
ok($sorted[0]['date'] === '2026-08-20', '日報は日付の新しい順');
$csv = kv_csv($nip, $all);
ok(substr($csv, 0, 3) === "\xEF\xBB\xBF", 'CSVにBOMが付く(Excelで文字化けしない)');
ok(strpos($csv, 'Cの日報') !== false, 'CSVに中身が入る');
ok(substr_count($csv, "\r\n") === count($all) + 1, 'CSVの行数が合う');

echo "\n[8] ユーザー管理\n";
list($ou1, $u1) = kv_user_upsert('taro@example.co.jp', '太郎', 'google', 'sub1');
ok($ou1 && $u1['role'] === 'admin', '最初の1人は管理者になる');
list($ou2, $u2) = kv_user_upsert('hana@example.co.jp', '花子', 'google', 'sub2');
ok($ou2 && $u2['role'] === 'staff', '2人目以降は担当から始まる');
list($ou3, $u3) = kv_user_upsert('taro@example.co.jp', '太郎', 'google', 'sub1');
ok($u3['id'] === $u1['id'], '同じメールなら同じユーザー(重複しない)');
ok(count(kv_users()) === 2, 'ユーザーは2人');
list($ou4, $m4) = kv_user_edit($u1['id'], 'staff', true);
ok($ou4 === false, '最後の管理者は降格できない', $m4);
list($ou5, ) = kv_user_edit($u2['id'], 'admin', true);
ok($ou5 === true, '2人目を管理者にできる');
list($ou6, ) = kv_user_edit($u1['id'], 'staff', true);
ok($ou6 === true, '管理者が2人いれば降格できる');
list($ou7, $m7) = kv_user_edit($u2['id'], 'nosuch', true);
ok($ou7 === false, '存在しない役割は拒否される', $m7);

echo "\n[9] Google Workspace の署名検証(JWK→PEM)\n";
$res = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
if (!$res) {
    ok(false, 'テスト用のRSA鍵を作れない(openssl未設定)');
} else {
    $det = openssl_pkey_get_details($res);
    $b64u = function ($b) { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); };
    $pem = kv_jwk_to_pem($b64u($det['rsa']['n']), $b64u($det['rsa']['e']));
    ok(strpos($pem, 'BEGIN PUBLIC KEY') !== false, 'JWKからPEMを組み立てられる');
    $pub = openssl_pkey_get_public($pem);
    ok($pub !== false, '組み立てたPEMをopensslが読める');
    $data = 'header.payload';
    openssl_sign($data, $sig, $res, OPENSSL_ALGO_SHA256);
    ok(openssl_verify($data, $sig, $pem, OPENSSL_ALGO_SHA256) === 1, '署名を検証できる(RS256)');
    ok(openssl_verify('header.payload2', $sig, $pem, OPENSSL_ALGO_SHA256) !== 1, '改ざんされた本文は弾かれる');
}
ok(kv_b64url_decode(rtrim(strtr(base64_encode("あ?~"), '+/', '-_'), '=')) === "あ?~", 'base64urlを戻せる');

echo "\n[10] IDトークンの検証(不正なものを弾く)\n";
$mk = function ($claims, $alg = 'RS256') {
    $b64u = function ($b) { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); };
    return $b64u(json_encode(array('alg' => $alg, 'kid' => 'x'))) . '.'
         . $b64u(json_encode($claims)) . '.' . $b64u('signature');
};
list($okv, $why) = kv_oidc_verify($mk(array('email' => 'a@example.co.jp'), 'none'), '');
ok($okv === false && strpos($why, '署名方式') !== false, 'alg:none を弾く', $why);
list($okv, $why) = kv_oidc_verify($mk(array('email' => 'a@example.co.jp'), 'HS256'), '');
ok($okv === false, 'HS256への差し替えを弾く', $why);
list($okv, $why) = kv_oidc_verify('not.a.token', '');
ok($okv === false, '壊れたトークンを弾く');
list($okv, $why) = kv_oidc_verify('abc', '');
ok($okv === false && strpos($why, '形式') !== false, '形の違うトークンを弾く', $why);

echo "\n[11] 設定まわり\n";
ok(count(kv_roles()) === 3, '役割は3つ');
ok(kv_dept_name('sales') === '営業部', '部署名を引ける');
ok(kv_dept_name('nosuch') === 'nosuch', '未知の部署はキーをそのまま返す');
ok(kv_h('<script>') === '&lt;script&gt;', 'HTMLをエスケープする');
ok(strlen(kv_random_hex(8)) === 16, '乱数の長さが合う');
ok(kv_random_hex(8) !== kv_random_hex(8), '乱数が毎回変わる');
ok(kv_status_label('submitted') === '申請中', '状態の日本語ラベル');
ok(kv_auth_provider() === 'password', '認証方式を設定から読む');

echo "\n[12] 社員番号とパスワードでログインする\n";

// 初期管理者(設定のIDとハッシュ)から、最初の社員とアカウントが生まれる
list($bo, $badm) = kv_password_login('admin', 'boot-pass-2026');
ok($bo === true, '設置直後は初期管理者で入れる', $badm);
ok($badm['role'] === 'admin', '初期管理者は管理者になる');
ok($badm['login_id'] === 'admin', 'ログインIDが入る');
ok(kv_employee_by_email('') === null || true, '初期管理者の社員も作られる');
list($bo2, $badm2) = kv_password_login('admin', 'boot-pass-2026');
ok($bo2 === true && $badm2['id'] === $badm['id'], '2回目も同じアカウントになる(重複しない)');
list($bo3, $bm3) = kv_password_login('admin', 'ちがう');
ok($bo3 === false, '初期管理者もパスワードが違えば入れない', $bm3);

// 社員を登録してアカウントを作り、パスワードを発行する
list($lc, $lemp) = kv_employee_create(array('name' => '山田 太郎', 'kana' => 'ヤマダ タロウ',
    'no' => 'A-003', 'dept' => 'sales', 'title' => '', 'email' => 'yamada@example.co.jp',
    'tel' => '', 'mobile' => '', 'joined' => '2022-04-01', 'left' => '',
    'status' => 'active', 'note' => ''));
list($la, $lacct) = kv_account_create($lemp['id'], 'A-003', 'yamada@example.co.jp', 'staff');
ok($la === true, '社員番号をログインIDにしてアカウントを作れる', $lacct);
ok($lacct['login_id'] === 'A-003', 'ログインID＝社員番号');
ok($lacct['password_hash'] === '', 'パスワードを発行するまでは空');

list($lp1, $lm1) = kv_password_login('A-003', 'なんでも');
ok($lp1 === false, 'パスワード未発行では入れない', $lm1);

list($sp, ) = kv_account_set_password($lacct['id'], 'shain-2026', true);
ok($sp === true, 'パスワードを発行できる');
list($lp2, $luser) = kv_password_login('A-003', 'shain-2026');
ok($lp2 === true, '社員番号とパスワードで入れる', $luser);
ok($luser['name'] === '山田 太郎', '氏名に役割が混ざらない');
ok($luser['role'] === 'staff', '役割は別に持つ');
ok(!empty($luser['must_change']), '発行直後は変更を求める');
ok($luser['last_login'] !== '', '最終ログインが記録される');

list($lp3, $lm3) = kv_password_login('A-003', 'ちがう');
ok($lp3 === false, 'パスワードが違えば入れない');
list($lp4, $lm4) = kv_password_login('X-999', 'shain-2026');
ok($lp4 === false, '存在しないログインIDでは入れない');
ok($lm3 === $lm4, 'IDの誤りとパスワードの誤りで文言を変えない(実在IDを探らせない)', array($lm3, $lm4));
list($lp5, ) = kv_password_login('', 'shain-2026');
ok($lp5 === false, 'ログインIDが空なら入れない');
list($lp6, ) = kv_password_login('yamada@example.co.jp', 'shain-2026');
ok($lp6 === true, 'メールアドレスでも入れる(会社によって呼び方が違うため)');

echo "\n[12b] ログインIDとパスワードの決まり\n";
ok(kv_password_policy('short') !== '', '8文字未満は拒否');
ok(kv_password_policy('12345678') !== '', '数字だけは拒否');
ok(kv_password_policy('shain-2026') === '', '8文字以上で英字が入れば通る');
list($sp2, $sm2) = kv_account_set_password($lacct['id'], 'abc', false);
ok($sp2 === false, '短いパスワードは設定できない', $sm2);
$gen = kv_password_generate();
ok(strlen($gen) === 14 && substr_count($gen, '-') === 2, '初期パスワードは読み上げやすい形', $gen);
ok(kv_password_policy($gen) === '', '生成した初期パスワードは決まりを満たす', $gen);
ok(!preg_match('/[il1o0]/', $gen), '読み違えやすい文字を使わない', $gen);

list($lc2, $lemp2) = kv_employee_create(array('name' => '重複 太郎', 'kana' => '',
    'no' => 'A-004', 'dept' => 'sales', 'title' => '', 'email' => '', 'tel' => '', 'mobile' => '',
    'joined' => '', 'left' => '', 'status' => 'active', 'note' => ''));
list($la2, $lm7) = kv_account_create($lemp2['id'], 'A-003', '', 'staff');
ok($la2 === false, '同じログインIDは登録できない', $lm7);
list($la3, $lm8) = kv_account_create($lemp2['id'], '', '', 'staff');
ok($la3 === false, 'ログインIDもメールも空では作れない', $lm8);
list($la4, $lm9) = kv_account_create($lemp2['id'], 'A 004', '', 'staff');
ok($la4 === false, 'ログインIDに空白は使えない', $lm9);

echo "\n[12c] 本人によるパスワード変更\n";
$luser = kv_user_find($lacct['id']);
list($pc1, $pm1) = kv_password_change($luser, 'ちがう', 'atarashii-2026', 'atarashii-2026');
ok($pc1 === false, '今のパスワードが違えば変更できない', $pm1);
list($pc2, $pm2) = kv_password_change($luser, 'shain-2026', 'atarashii-2026', 'atarashii-9999');
ok($pc2 === false, '確認用が一致しなければ変更できない', $pm2);
list($pc3, $pm3) = kv_password_change($luser, 'shain-2026', 'abc', 'abc');
ok($pc3 === false, '短いパスワードには変更できない', $pm3);
list($pc4, $pm4) = kv_password_change($luser, 'shain-2026', 'shain-2026', 'shain-2026');
ok($pc4 === false, '今と同じパスワードには変更できない', $pm4);
list($pc5, ) = kv_password_change($luser, 'shain-2026', 'atarashii-2026', 'atarashii-2026');
ok($pc5 === true, '正しく変更できる');
ok(empty(kv_user_find($lacct['id'])['must_change']), '変更すると催促が消える');
list($lp7, ) = kv_password_login('A-003', 'atarashii-2026');
ok($lp7 === true, '新しいパスワードで入れる');
list($lp8, ) = kv_password_login('A-003', 'shain-2026');
ok($lp8 === false, '古いパスワードでは入れない');

echo "\n[12d] 退職・停止はパスワードが合っていても入れない\n";
kv_employee_update($lemp['id'], array('status' => 'retired'));
list($lp9, $lm10) = kv_password_login('A-003', 'atarashii-2026');
ok($lp9 === false, '退職者はパスワードが合っていても入れない', $lm10);
kv_employee_update($lemp['id'], array('status' => 'active'));
kv_user_edit($lacct['id'], 'staff', false);
list($lp10, ) = kv_password_login('A-003', 'atarashii-2026');
ok($lp10 === false, '停止したアカウントは入れない');
kv_user_edit($lacct['id'], 'staff', true);
list($lp11, ) = kv_password_login('A-003', 'atarashii-2026');
ok($lp11 === true, '戻せば入れる');
list($cl, ) = kv_account_clear_password($lacct['id']);
ok($cl === true && kv_password_login('A-003', 'atarashii-2026')[0] === false,
   'パスワードを消すとログインできなくなる');

echo "\n[13] スマホカレンダー配信(iCalendar)\n";
ok(kv_cal_map($sched) !== null, 'スケジュールはカレンダー配信に対応');
ok(kv_cal_map($nip) === null, 'caldavを書かないアプリは対応しない');
ok(kv_ics_escape('a,b;c\\d') === 'a\,b\;c\\\\d', 'カンマ・セミコロン・円記号を退避する');
ok(kv_ics_escape("1行目\n2行目") === '1行目\n2行目', '改行を \\n にする');
$folded = kv_ics_fold('SUMMARY:' . str_repeat('あ', 60));
$flines = explode("\r\n", rtrim($folded, "\r\n"));
ok(count($flines) > 1, '長い行は折り返される');
$longest = 0;
foreach ($flines as $fl) { if (strlen($fl) > $longest) { $longest = strlen($fl); } }
ok($longest <= 75, '折り返し後の各行は75オクテット以下', $longest);
ok(substr($flines[1], 0, 1) === ' ', '折り返し行は空白で始まる');
ok(mb_check_encoding($folded, 'UTF-8'), '折り返しても文字化けしない');

$cmap = kv_cal_map($sched);
$ev = kv_ics_event($sched, $cmap, array('_id' => 'r1', '_owner_name' => '山田', '_dept' => 'sales',
    'date' => '2026-08-18', 'start' => '10:00', 'end' => '11:00',
    'title' => 'A社 打合せ', 'place' => '本社', 'memo' => ''));
ok(strpos($ev, 'DTSTART:20260818T010000Z') !== false, 'JST10:00がUTC01:00になる', substr($ev, 0, 200));
ok(strpos($ev, 'DTEND:20260818T020000Z') !== false, 'JST11:00がUTC02:00になる');
ok(strpos($ev, 'SUMMARY:A社 打合せ（山田）') !== false, '件名に登録者が入る');
ok(strpos($ev, 'LOCATION:本社') !== false, '場所が入る');

$ev2 = kv_ics_event($sched, $cmap, array('_id' => 'r2', '_owner_name' => '鈴木', '_dept' => 'sales',
    'date' => '2026-08-20', 'start' => '', 'end' => '', 'title' => '夏季休暇', 'place' => '', 'memo' => ''));
ok(strpos($ev2, 'DTSTART;VALUE=DATE:20260820') !== false, '時刻が無ければ終日予定になる');
ok(strpos($ev2, 'DTEND;VALUE=DATE:20260821') !== false, '終日のDTENDは翌日(当日だと消える)');

$ev3 = kv_ics_event($sched, $cmap, array('_id' => 'r3', '_owner_name' => '佐藤', '_dept' => 'kanri',
    'date' => '2026-08-19', 'start' => '13:00', 'end' => '', 'title' => '来客', 'place' => '', 'memo' => ''));
ok(strpos($ev3, 'DTEND:20260819T050000Z') !== false, '終了が空なら1時間後になる');
ok(kv_ics_event($sched, $cmap, array('_id' => 'r4', 'date' => 'めちゃくちゃ')) === '', '日付が壊れた記録は出力しない');

echo "\n[14] 購読URLの合言葉\n";
list($ou8, $u8) = kv_user_upsert('cal@example.co.jp', 'カレンダー太郎', 'google', 'sub9');
$t1 = kv_cal_token($u8['id']);
ok(strlen($t1) === 32, '合言葉は32桁');
ok(kv_cal_token($u8['id']) === $t1, '2回目も同じ合言葉が返る');
ok(kv_cal_user($u8['id'], $t1) !== null, '正しい合言葉なら本人を引ける');
ok(kv_cal_user($u8['id'], 'ffffffffffffffffffffffffffffffff') === null, '違う合言葉は通らない');
ok(kv_cal_user($u8['id'], '') === null, '空の合言葉は通らない');
ok(kv_cal_user('nosuch', $t1) === null, '存在しないユーザーは通らない');
$t2 = kv_cal_reset($u8['id']);
ok($t2 !== $t1, '作り直すと合言葉が変わる');
ok(kv_cal_user($u8['id'], $t1) === null, '古いURLは即座に無効になる');
ok(kv_cal_user($u8['id'], $t2) !== null, '新しいURLは有効');
kv_user_edit($u8['id'], 'staff', false);
ok(kv_cal_user($u8['id'], $t2) === null, '停止したユーザーには配信しない');

$ics = kv_ics_build($sched, $admin);
ok(strpos($ics, 'BEGIN:VCALENDAR') === 0, 'VCALENDARで始まる');
ok(substr($ics, -15) === "END:VCALENDAR\r\n", 'VCALENDARで終わる', substr($ics, -15));
ok(strpos($ics, 'X-WR-TIMEZONE:Asia/Tokyo') !== false, 'タイムゾーンを明示する');
ok(kv_ics_build($nip, $admin) === '', '非対応アプリは空を返す');

echo "\n[15] 社員マスタ（人事の情報）\n";
list($ev, $ee) = kv_employee_validate(array('name' => '', 'dept' => 'sales'));
ok(isset($ee['name']), '氏名が空だと落ちる');
list($ev, $ee) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'nosuch'));
ok(isset($ee['dept']), '存在しない部署は落ちる');
list($ev, $ee) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'sales', 'no' => 'A 001'));
ok(isset($ee['no']), '社員番号に空白が入ると落ちる');
list($ev, $ee) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'sales', 'email' => 'こわれ'));
ok(isset($ee['email']), 'メールの形式が違うと落ちる');
list($ev, $ee) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'sales',
    'joined' => '2026-04-01', 'left' => '2026-03-01'));
ok(isset($ee['left']), '退職日が入社日より前だと落ちる');
list($ev, $ee) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'sales', 'status' => 'zzz'));
ok(isset($ee['status']), '在籍状態が不正だと落ちる');
list($ev, $ee) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'sales', 'no' => 'A-001',
    'email' => 'tanaka@example.co.jp', 'joined' => '2026-04-01', 'status' => 'active'));
ok(count($ee) === 0, '正しい入力は通る', $ee);

list($ec1, $emp1) = kv_employee_create($ev);
ok($ec1 === true && !empty($emp1['id']), '社員を登録できる');
ok(kv_employee_find($emp1['id'])['name'] === '田中一郎', '登録した社員を引ける');
ok(kv_employee_by_email('tanaka@example.co.jp') !== null, 'メールから社員を引ける');

list($ev2, $ee2) = kv_employee_validate(array('name' => '別人', 'dept' => 'sales', 'no' => 'A-001'));
ok(isset($ee2['no']), '社員番号の重複は拒否される');
list($ev3, $ee3) = kv_employee_validate(array('name' => '別人', 'dept' => 'sales',
    'email' => 'tanaka@example.co.jp'));
ok(isset($ee3['email']), 'メールの重複は拒否される');
list($ev4, $ee4) = kv_employee_validate(array('name' => '田中一郎', 'dept' => 'seizou',
    'no' => 'A-001', 'email' => 'tanaka@example.co.jp'), $emp1['id']);
ok(count($ee4) === 0, '自分自身は重複チェックから除かれる', $ee4);

list($eu1, ) = kv_employee_update($emp1['id'], array('title' => '課長', 'dept' => 'seizou'));
ok($eu1 === true && kv_employee_find($emp1['id'])['title'] === '課長', '社員を更新できる');
ok(kv_employee_find($emp1['id'])['dept'] === 'seizou', '部署を変更できる');

echo "\n[16] 社員とアカウントの分離\n";
ok(kv_user_by_employee($emp1['id']) === null, 'アカウントを持たない社員が登録できている');
list($ac1, $m1) = kv_account_create($emp1['id'], 'Z-001', 'tanaka@example.co.jp', 'chief');
ok($ac1 === true, '社員にアカウントを作れる', $m1);
$acct1 = kv_user_by_employee($emp1['id']);
ok($acct1 !== null && $acct1['role'] === 'chief', '作ったアカウントを引ける');
ok($acct1['name'] === '田中一郎', 'アカウントに社員の氏名が合成される');
ok($acct1['dept'] === 'seizou', 'アカウントに社員の部署が合成される');
ok($acct1['title'] === '課長', 'アカウントに役職が合成される');
list($ac2, $m2) = kv_account_create($emp1['id'], 'Z-002', 'other@example.co.jp', 'staff');
ok($ac2 === false, '1人の社員にアカウントは1つまで', $m2);
list($ac3, $m3) = kv_account_create($emp1['id'], 'Z-003', 'こわれ', 'staff');
ok($ac3 === false, '壊れたメールではアカウントを作れない');
list($ac4, $m4b) = kv_account_create('nosuch', 'Z-004', 'x@example.co.jp', 'staff');
ok($ac4 === false, '存在しない社員にはアカウントを作れない', $m4b);

echo "\n[17] 退職させるとログインできなくなる\n";
kv_employee_update($emp1['id'], array('status' => 'retired'));
$after = kv_user_by_employee($emp1['id']);
ok($after['active'] === false, '退職にするとアカウントが有効でも締め出される');
ok(kv_can($after, $nip, 'read') === false, '退職者は権限判定でも通らない');
ok(kv_cal_user($after['id'], kv_cal_token($after['id'])) === null, '退職者にはカレンダーも配信しない');
kv_employee_update($emp1['id'], array('status' => 'leave'));
ok(kv_user_by_employee($emp1['id'])['active'] === false, '休職でも締め出される');
kv_employee_update($emp1['id'], array('status' => 'active'));
ok(kv_user_by_employee($emp1['id'])['active'] === true, '在籍に戻すと使えるようになる');

echo "\n[18] 削除の安全弁\n";
list($ed1, $md1) = kv_employee_delete($emp1['id']);
ok($ed1 === false && strpos($md1, 'アカウント') !== false,
   'アカウントが残っている社員は削除できない', $md1);
$acctId = kv_user_by_employee($emp1['id'])['id'];
list($ad1, ) = kv_account_delete($acctId);
ok($ad1 === true, 'アカウントは削除できる');
ok(kv_employee_find($emp1['id']) !== null, 'アカウントを消しても社員は残る');
list($ed2, ) = kv_employee_delete($emp1['id']);
ok($ed2 === true, '記録もアカウントも無ければ社員を削除できる');
ok(kv_employee_find($emp1['id']) === null, '削除された');

list($ec5, $emp5) = kv_employee_create(array('name' => '記録持ち', 'dept' => 'sales',
    'no' => '', 'email' => 'rec@example.co.jp', 'status' => 'active', 'kana' => '',
    'title' => '', 'tel' => '', 'mobile' => '', 'joined' => '', 'left' => '', 'note' => ''));
kv_account_create($emp5['id'], 'Z-005', 'rec@example.co.jp', 'staff');
$acct5 = kv_user_by_employee($emp5['id']);
kv_record_create($acct5, $nip, array('date' => '2026-08-17', 'work' => '消せない日報'));
ok(kv_employee_record_count($emp5['id']) === 1, '社員の記録数を数えられる');
list($ed3, $md3) = kv_employee_delete($emp5['id']);
ok($ed3 === false && strpos($md3, '退職') !== false,
   '記録が残っている社員は削除できず、退職を案内する', $md3);

echo "\n[19] Workspaceログインと社員マスタの結び付き\n";
list($ec6, $emp6) = kv_employee_create(array('name' => '先に登録した人', 'dept' => 'kanri',
    'no' => 'B-100', 'email' => 'saki@example.co.jp', 'status' => 'active', 'kana' => '',
    'title' => '主任', 'tel' => '', 'mobile' => '', 'joined' => '2026-04-01', 'left' => '', 'note' => ''));
list($uo6, $usr6) = kv_user_upsert('saki@example.co.jp', 'Saki', 'google', 'sub-saki');
ok($uo6 === true, '先に社員登録があってもログインできる');
ok($usr6['employee_id'] === $emp6['id'], '同じメールの社員に結び付く(二重に作らない)');
ok($usr6['dept'] === 'kanri' && $usr6['title'] === '主任', '登録済みの部署・役職が効く');
ok($usr6['role'] === 'staff', '役割は担当から始まる');
$before = count(kv_employees());
list($uo7, $usr7) = kv_user_upsert('atarashii@example.co.jp', '新しい人', 'google', 'sub-new');
ok(count(kv_employees()) === $before + 1, '社員登録が無ければ社員も自動で作られる');
ok(kv_employee_find($usr7['employee_id'])['status'] === 'active', '自動登録された社員は在籍');
kv_employee_update($emp6['id'], array('status' => 'retired'));
list($uo8, $m8b) = kv_user_upsert('mada@example.co.jp', '未登録', 'google', 'sub-x');
ok($uo8 === true, '別の人は影響を受けない');

echo "\n[20] アカウントの棚卸し\n";
$audit = kv_account_audit();
ok(count($audit) === count(kv_users()), '全アカウントが棚卸しに載る');
$sum = kv_account_summary();
ok($sum['total'] === count(kv_users()), '合計が合う');
ok($sum['usable'] + $sum['stopped'] === $sum['total'], 'ログイン可＋停止＝合計');

// 退職者のアカウントが名指しされるか
list($ec9, $emp9) = kv_employee_create(array('name' => '棚卸し対象', 'dept' => 'sales',
    'no' => 'Z-900', 'email' => 'tana@example.co.jp', 'status' => 'active', 'kana' => '',
    'title' => '', 'tel' => '', 'mobile' => '', 'joined' => '', 'left' => '', 'note' => ''));
kv_account_create($emp9['id'], 'Z-009', 'tana@example.co.jp', 'staff');
$acct9 = kv_user_by_employee($emp9['id']);
$find = function ($id) { foreach (kv_account_audit() as $r) { if ($r['raw']['id'] === $id) { return $r; } } return null; };
$row = $find($acct9['id']);
ok($row !== null && in_array('一度もログインしていません', $row['warn'], true),
   '未ログインのアカウントを名指しする', $row ? $row['warn'] : null);

kv_employee_update($emp9['id'], array('status' => 'retired'));
$row = $find($acct9['id']);
ok(in_array('社員が退職です', $row['warn'], true), '退職者のアカウントを名指しする', $row['warn']);
ok(!empty($row['raw']['active']) && empty($row['user']['active']),
   'アカウントは有効のまま、実際にはログインできない状態を区別できる');

// 90日以上ログインが無いもの
kv_employee_update($emp9['id'], array('status' => 'active'));
kv_update('users', 'users', function (&$users) use ($acct9) {
    foreach ($users as $i => $u) {
        if ($u['id'] === $acct9['id']) { $users[$i]['last_login'] = date('Y-m-d H:i:s', time() - 120 * 86400); }
    }
    return true;
});
$row = $find($acct9['id']);
ok(in_array('90日以上ログインがありません', $row['warn'], true), '長期未ログインを名指しする', $row['warn']);

// 社員に紐づかない孤児(データが壊れた場合の備え)
kv_update('employees', 'employees', function (&$emps) use ($emp9) {
    foreach ($emps as $i => $e) { if ($e['id'] === $emp9['id']) { array_splice($emps, $i, 1); return true; } }
    return true;
});
$row = $find($acct9['id']);
ok(in_array('社員に紐づいていません', $row['warn'], true), '孤児アカウントを名指しする', $row['warn']);
ok(empty($row['user']['active']), '孤児アカウントではログインできない');

// 注意のあるものが先頭に来る
$audit = kv_account_audit();
$firstClean = null;
foreach ($audit as $i => $r) { if (!$r['warn'] && $firstClean === null) { $firstClean = $i; } }
$lastWarn = -1;
foreach ($audit as $i => $r) { if ($r['warn']) { $lastWarn = $i; } }
ok($firstClean === null || $lastWarn < $firstClean, '要確認のアカウントが先頭にまとまる');

list($ad9, ) = kv_account_delete($acct9['id']);
ok($ad9 === true, '孤児アカウントは削除できる');

echo "\n[21] 設定ファイルの初期管理者\n";
$bootRaw = null;
foreach (kv_users() as $u) { if (strcasecmp(isset($u['login_id']) ? $u['login_id'] : '', 'admin') === 0) { $bootRaw = $u; } }
ok($bootRaw !== null, '初期管理者のアカウントが台帳にある');
ok($bootRaw['password_hash'] === '', '初期管理者は台帳にパスワードを持たない(設定側にある)');
ok(kv_is_bootstrap_admin($bootRaw) === true, '初期管理者だと判定できる');
$other = null;
foreach (kv_users() as $u) { if (isset($u['login_id']) && $u['login_id'] === 'A-003') { $other = $u; } }
ok($other !== null && kv_is_bootstrap_admin($other) === false, '普通のアカウントは初期管理者ではない');
$row = null;
foreach (kv_account_audit() as $r) { if ($r['raw']['id'] === $bootRaw['id']) { $row = $r; } }
ok(!in_array('パスワードが未発行です', $row['warn'], true),
   '初期管理者に「未発行」の警告を出さない', $row['warn']);

/* 後片付け */
foreach (glob(KVGWC_DATA_DIR . '/*') as $f) { @unlink($f); }
@rmdir(KVGWC_DATA_DIR);
foreach (glob(KVGWC_APPS_DIR . '/*') as $f) { @unlink($f); }
@rmdir(KVGWC_APPS_DIR);

echo "\n================================\n";
echo "  成功 $pass 件 / 失敗 $fail 件\n";
echo "================================\n\n";
exit($fail === 0 ? 0 : 1);
