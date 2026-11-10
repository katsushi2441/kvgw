<?php
/**
 * kvgw(メッセージ・施設予約・ToDo)の検証。直したら必ず通す。実行: php scripts/check_kvgw.php
 * Core の検証(scripts/check_kvgwc.php)も一緒に通すこと。
 *
 * 一番見たいのは3つ。
 *   - 宛先に入っていない人が、メッセージを読めない・書けない・添付を取れない
 *   - 同じ施設・同じ時間に、2つの予約が入らない
 *   - 人の ToDo が見えない・勝手に変えられない
 */

define('KVGWC_DATA_DIR', sys_get_temp_dir() . '/kvgw_check_' . getmypid());
define('KVGWC_APPS_DIR', dirname(__DIR__) . '/public/apps');
define('KVGWC_AUTH', 'password');
define('KVGWC_ADMIN_LOGIN', 'admin');
define('KVGWC_ADMIN_NAME', '初期管理者');
define('KVGWC_ADMIN_EMAIL', '');
define('KVGWC_PASSWORD_HASH', '');
define('KVGWC_DEMO', false);
define('KVGW_RESERVE_DAYS', 60);
function kvgwc_depts() { return array('sales' => '営業部', 'seizou' => '製造部', 'kanri' => '管理部'); }
$_SERVER['SCRIPT_NAME'] = '/kvgwc.php';

require_once dirname(__DIR__) . '/public/kvgw.php';
require_once dirname(__DIR__) . '/public/kvgwc_core.php';
require_once dirname(__DIR__) . '/public/kvgwc_form.php';
require_once dirname(__DIR__) . '/public/kvgwc_auth.php';
@mkdir(KVGWC_DATA_DIR, 0700, true);

$pass = 0; $fail = 0;
function ok($cond, $label, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($got === null ? '' : '  → ' . var_export($got, true)) . "\n"; }
}

/* 本物と同じ道筋(社員マスタ → アカウント)で人を作る */
function mk($no, $name, $dept, $role, $status = 'active') {
    list($v, $e) = kv_employee_validate(array('name' => $name, 'no' => $no, 'dept' => $dept, 'status' => $status));
    if ($e) { die('社員を作れません: ' . json_encode($e, JSON_UNESCAPED_UNICODE)); }
    $emp = kv_employee_create($v);
    $acc = kv_account_create($emp[1]['id'], $no, '', $role);
    return kv_user_find($acc[1]['id']);
}
$yamada = mk('A-003', '山田太郎', 'sales', 'staff');
$suzuki = mk('A-002', '鈴木美和', 'sales', 'chief');
$sato   = mk('A-001', '佐藤健一', 'kanri', 'admin');
$kato   = mk('A-004', '加藤里奈', 'seizou', 'staff');
$ito    = mk('A-005', '伊藤誠', 'seizou', 'chief');
$gone   = mk('A-009', '退職花子', 'sales', 'staff', 'retired');
kvgw_people(true);

echo "\n[1] 共通\n";
ok(KVGWC_PRODUCT === 'Kurage Vibe Groupware', '製品名が Kurage Vibe Groupware になる');
$people = kvgw_people();
ok(count($people) === 5 && !isset($people[$gone['id']]), '宛先の候補に退職した人が出ない', array_keys($people));
ok(kvgw_date_ok('2026-02-28') && !kvgw_date_ok('2026-02-30') && !kvgw_date_ok('2026/02/01'), '日付の確かめ');
ok(kvgw_time_ok('09:05') && !kvgw_time_ok('24:00') && !kvgw_time_ok('9:5'), '時刻の確かめ');
ok(kvgw_url('message', array('a' => 'view', 'id' => 'm1', 'q' => '')) === 'kvgwc.php?m=message&a=view&id=m1', 'URL の組み立て(空の値は付けない)');

echo "\n[2] メッセージ — 宛先の人だけが読める\n";
$r = kvgw_msg_save($yamada, '', array('subject' => '', 'body' => 'x', 'to' => array($suzuki['id'])), false);
ok(!$r[0], '件名が無いと送れない');
$r = kvgw_msg_save($yamada, '', array('subject' => '見積の件', 'body' => 'x', 'to' => array()), false);
ok(!$r[0], '宛先が無いと送れない');
$r = kvgw_msg_save($yamada, '', array('subject' => '見積の件', 'body' => 'x', 'to' => array($gone['id'], 'nobody')), false);
ok(!$r[0], '退職した人・居ない人だけが宛先だと送れない');
$r = kvgw_msg_save($yamada, '', array('subject' => '見積の件', 'body' => "A社の見積を見てください。\n<script>alert(1)</script>",
                                      'to' => array($suzuki['id'], $suzuki['id'], $yamada['id'], $gone['id'])), false);
ok($r[0], '送信できる', $r[1]);
$m = $r[1];
ok($m['to'] === array($suzuki['id']), '宛先の重複・本人・退職者は外れる', $m['to']);
ok(kvgw_msg_can($yamada, $m, 'read') && kvgw_msg_can($suzuki, $m, 'read'), '差出人と宛先は読める');
ok(!kvgw_msg_can($kato, $m, 'read') && !kvgw_msg_can($ito, $m, 'read'), '宛先に入っていない人は読めない');
ok(!kvgw_msg_can($sato, $m, 'read'), '管理者でも、宛先に入っていなければ読めない');
ok(count(kvgw_msg_list($suzuki, 'inbox', '')) === 1 && count(kvgw_msg_list($kato, 'inbox', '')) === 0
   && count(kvgw_msg_list($sato, 'inbox', '')) === 0, '受信箱に出るのは宛先の人だけ');
ok(count(kvgw_msg_list($yamada, 'sent', '')) === 1 && count(kvgw_msg_list($yamada, 'inbox', '')) === 0, '差出人は送信箱に出る(コメントが付くまで受信箱には出ない)');

echo "\n[3] メッセージ — 未読・既読\n";
ok(kvgw_msg_unread_count($suzuki) === 1, '届いた人は未読1');
ok(kvgw_msg_unread_count($yamada) === 0, '送った本人は未読にならない');
kvgw_msg_state_set($suzuki['id'], $m['id'], array('rev' => (int)$m['rev'], 'read' => kv_now()));
ok(kvgw_msg_unread_count($suzuki) === 0, '開くと既読になる');
$st = kvgw_msg_state($suzuki['id']);
ok(isset($st[$m['id']]['rev']), '閲覧状況(だれが読んだか)が残る');
$c = kvgw_msg_comment($suzuki, $m['id'], '確認しました。金額はこれで出してください。');
ok($c[0], '宛先の人がコメントできる', $c[1]);
ok(kvgw_msg_unread_count($yamada) === 1, 'コメントが付くと、差出人に未読が立つ');
ok(count(kvgw_msg_list($yamada, 'inbox', '')) === 1, 'コメントが付いた自分のメッセージは受信箱に出る');
ok(kvgw_msg_unread_count($suzuki) === 0, 'コメントを書いた本人は未読にならない');
$c2 = kvgw_msg_comment($kato, $m['id'], '割り込み');
ok(!$c2[0], '宛先に入っていない人はコメントできない');
$c3 = kvgw_msg_comment($sato, $m['id'], '管理者の割り込み');
ok(!$c3[0], '管理者でも、宛先に入っていなければコメントできない');
ok(!kvgw_msg_comment($suzuki, $m['id'], '   ')[0], '空のコメントは書けない');
$d = kvgw_msg_comment_delete($yamada, $m['id'], $c[1]['id']);
ok(!$d[0], '人のコメントは消せない');
$d = kvgw_msg_comment_delete($suzuki, $m['id'], $c[1]['id']);
ok($d[0] && count(kvgw_msg_find($m['id'])['comments']) === 0, '自分のコメントは消せる');

echo "\n[4] メッセージ — 書き直し・宛先の追加・削除\n";
$e = kvgw_msg_save($suzuki, $m['id'], array('subject' => '書き換え', 'body' => 'x', 'to' => array()), false);
ok(!$e[0] && kvgw_msg_find($m['id'])['subject'] === '見積の件', '宛先の人は本文を書き直せない');
$e = kvgw_msg_save($yamada, $m['id'], array('subject' => '見積の件（訂正）', 'body' => '金額を直しました', 'to' => array()), false);
ok($e[0] && $e[1]['to'] === array($suzuki['id']) && $e[1]['edited'] !== '', '差出人は書き直せる。宛先は減らない', $e[1]);
ok(kvgw_msg_unread_count($suzuki) === 1, '書き直すと、宛先の人に未読が立つ');
$e = kvgw_msg_save($yamada, $m['id'], array('subject' => 'x', 'body' => 'x', 'to' => array()), true);
ok(!$e[0], '送ったメッセージは下書きに戻せない');
$a = kvgw_msg_add_to($suzuki, $m['id'], array($kato['id']));
ok(!$a[0], '許されていなければ、宛先の人は宛先を足せない');
$a = kvgw_msg_add_to($kato, $m['id'], array($kato['id']));
ok(!$a[0], '関係ない人が自分を宛先に足すことはできない');
$a = kvgw_msg_add_to($yamada, $m['id'], array($kato['id'], $gone['id']));
ok($a[0] && $a[1] === array($kato['id']), '差出人は宛先を足せる(退職者は足されない)', $a[1]);
ok(kvgw_msg_can($kato, kvgw_msg_find($m['id']), 'read') && kvgw_msg_unread_count($kato) === 1, '足された人は読めて、未読で届く');
$m2 = kvgw_msg_save($suzuki, '', array('subject' => '来週の会議', 'body' => '資料を見ておいてください', 'to' => array($yamada['id']), 'allow_add' => true), false);
$a = kvgw_msg_add_to($yamada, $m2[1]['id'], array($ito['id']));
ok($a[0], '差出人が許したメッセージは、宛先の人も宛先を足せる', $a[1]);
$x = kvgw_msg_delete($suzuki, $m['id']);
ok(!$x[0], '宛先の人はメッセージを消せない');
$x = kvgw_msg_delete($sato, $m['id']);
ok(!$x[0], '管理者でも、人のメッセージは消せない');
kvgw_msg_state_set($kato['id'], $m['id'], array('trash' => true));
ok(count(kvgw_msg_list($kato, 'inbox', '')) === 0 && count(kvgw_msg_list($kato, 'trash', '')) === 1
   && count(kvgw_msg_list($suzuki, 'inbox', '')) === 1, 'ごみ箱は自分の画面だけの話(ほかの人には残る)');
ok(count(kvgw_msg_list($suzuki, 'inbox', '訂正')) === 1 && count(kvgw_msg_list($suzuki, 'inbox', '無い言葉')) === 0, '検索できる');
ok(count(kvgw_msg_list($ito, 'inbox', '訂正')) === 0, '検索しても、宛先外のメッセージは出ない');
$x = kvgw_msg_delete($yamada, $m['id']);
ok($x[0] && kvgw_msg_find($m['id']) === null && count(kvgw_msg_list($suzuki, 'sent', '')) === 1 && count(kvgw_msg_list($kato, 'trash', '')) === 0,
   '差出人が消すと、全員の画面から消える');

echo "\n[5] メッセージ — 下書き・添付\n";
$dr = kvgw_msg_save($kato, '', array('subject' => '書きかけ', 'body' => '', 'to' => array($ito['id'])), true);
ok($dr[0] && !empty($dr[1]['draft']), '宛先・本文が途中でも下書きに置ける', $dr[1]);
ok(!kvgw_msg_can($ito, $dr[1], 'read') && count(kvgw_msg_list($ito, 'inbox', '')) === 1, '下書きは宛先の人に見えない(届いているのは別の1通だけ)');
ok(count(kvgw_msg_list($kato, 'draft', '')) === 1 && kvgw_msg_unread_count($ito) === 1, '下書きは本人の下書きにだけ出る');
ok(!kvgw_msg_comment($kato, $dr[1]['id'], 'x')[0], '下書きにはコメントできない');
$sd = kvgw_msg_save($kato, $dr[1]['id'], array('subject' => '書きかけ', 'body' => '仕上げました', 'to' => array($ito['id'])), false);
ok($sd[0] && empty($sd[1]['draft']) && kvgw_msg_unread_count($ito) === 2, '下書きを送ると、宛先に届く');
ok(kvgw_file_name_ok('見積書.pdf') && kvgw_file_name_ok('写真.JPG') && !kvgw_file_name_ok('shell.php')
   && !kvgw_file_name_ok('a.PHTML') && !kvgw_file_name_ok('.htaccess') && !kvgw_file_name_ok('x.html') && !kvgw_file_name_ok('run.exe'),
   'サーバーで動く種類・開くと危ない種類の添付は断る');
ok(kvgw_file_path('../../etc/passwd') === kvgw_file_dir() . '/ecad', '添付の置き場所は、乱数の名前以外を受け付けない', kvgw_file_path('../../etc/passwd'));
@mkdir(kvgw_file_dir(), 0700, true);
file_put_contents(kvgw_file_path('abc123'), 'pdf');
$fm = kvgw_msg_save($yamada, '', array('subject' => '添付あり', 'body' => '', 'to' => array($suzuki['id']),
                                       'files' => array(array('id' => 'abc123', 'name' => '見積書.pdf', 'size' => 3))), false);
ok($fm[0] && count($fm[1]['files']) === 1, '添付だけのメッセージを送れる');
kvgw_msg_save($yamada, $fm[1]['id'], array('subject' => '添付あり', 'body' => '外しました', 'to' => array(), 'remove' => array('abc123')), false);
ok(!is_file(kvgw_file_path('abc123')) && count(kvgw_msg_find($fm[1]['id'])['files']) === 0, '添付を外すと、実体も消える');
file_put_contents(kvgw_file_path('def456'), 'pdf');
$fm2 = kvgw_msg_save($yamada, '', array('subject' => '添付2', 'body' => '', 'to' => array($suzuki['id']),
                                        'files' => array(array('id' => 'def456', 'name' => 'a.pdf', 'size' => 3))), false);
kvgw_msg_delete($yamada, $fm2[1]['id']);
ok(!is_file(kvgw_file_path('def456')), 'メッセージを消すと、添付の実体も消える');
ok(file_exists(KVGWC_DATA_DIR . '/.htaccess'), '台帳フォルダ(添付の置き場所を含む)は外から読めない設定になる');

echo "\n[6] 施設 — 登録は管理者だけ\n";
$f = kvgw_fac_save($yamada, '', array('name' => '第1会議室', 'group' => '会議室'));
ok(!$f[0], '担当者は施設を登録できない');
$f = kvgw_fac_save($suzuki, '', array('name' => '第1会議室', 'group' => '会議室'));
ok(!$f[0], '責任者も施設を登録できない');
$f = kvgw_fac_save($sato, '', array('name' => '', 'group' => '会議室'));
ok(!$f[0], '名前が無い施設は登録できない');
$room = kvgw_fac_save($sato, '', array('name' => '第1会議室', 'group' => '会議室', 'note' => '8名', 'order' => 10));
ok($room[0], '管理者は施設を登録できる', $room[1]);
$room = $room[1];
ok(!kvgw_fac_save($sato, '', array('name' => '第1会議室', 'group' => '会議室'))[0], '同じ名前の施設は二重に登録できない');
$car = kvgw_fac_save($sato, '', array('name' => '社用車1号', 'group' => '社用車'))[1];
$old = kvgw_fac_save($sato, '', array('name' => '旧応接室', 'group' => '会議室', 'active' => false))[1];
ok(count(kvgw_fac_all()) === 3, '施設が3つ');

echo "\n[7] 施設予約 — 重ならない\n";
$day = date('Y-m-d', strtotime('+3 days'));
$base = array('facility' => $room['id'], 'date' => $day, 'start' => '10:00', 'end' => '11:00', 'title' => 'A社 定例');
$r1 = kvgw_res_create($yamada, $base);
ok($r1[0] && count($r1[1]) === 1, '予約できる', $r1[1]);
$r1 = $r1[1][0];
$r2 = kvgw_res_create($kato, array('start' => '10:30', 'end' => '11:30', 'title' => '重なる') + $base);
ok(!$r2[0] && isset($r2[1]['clash']), '時間が重なる予約は入らない', $r2[1]);
$r2 = kvgw_res_create($kato, array('start' => '09:00', 'end' => '10:01', 'title' => '1分重なる') + $base);
ok(!$r2[0], '1分でも重なれば入らない');
$r2 = kvgw_res_create($kato, array('start' => '09:00', 'end' => '12:00', 'title' => '包む') + $base);
ok(!$r2[0], '前後を包む予約も入らない');
$r3 = kvgw_res_create($kato, array('start' => '11:00', 'end' => '12:00', 'title' => '直後') + $base);
ok($r3[0], '直後(11:00から)は入る', $r3[1]);
$r4 = kvgw_res_create($kato, array('facility' => $car['id'], 'title' => '別の施設') + $base);
ok($r4[0], '同じ時間でも、別の施設なら入る');
ok(count(kvgw_res_all()) === 3, '入ったのは3件だけ', count(kvgw_res_all()));
ok(!kvgw_res_create($yamada, array('date' => date('Y-m-d', strtotime('-1 day'))) + $base)[0], '過ぎた日は予約できない');
ok(!kvgw_res_create($yamada, array('date' => date('Y-m-d', strtotime('+61 days'))) + $base)[0], '設定より先の日は予約できない');
ok(!kvgw_res_create($yamada, array('start' => '15:00', 'end' => '14:00') + $base)[0], '終了が開始より前の予約はできない');
ok(!kvgw_res_create($yamada, array('title' => '', 'start' => '15:00', 'end' => '16:00') + $base)[0], '利用目的が無い予約はできない');
ok(!kvgw_res_create($yamada, array('facility' => $old['id'], 'start' => '15:00', 'end' => '16:00') + $base)[0], '予約を止めた施設は予約できない');
ok(!kvgw_res_create($yamada, array('facility' => 'nothing', 'start' => '15:00', 'end' => '16:00') + $base)[0], '無い施設は予約できない');

echo "\n[8] 施設予約 — 変更・取り消しの権限\n";
ok(kvgw_fac_can($yamada, 'update', $r1) && kvgw_fac_can($suzuki, 'update', $r1) && kvgw_fac_can($sato, 'update', $r1), '本人・同じ部署の責任者・管理者は変更できる');
ok(!kvgw_fac_can($kato, 'update', $r1) && !kvgw_fac_can($ito, 'delete', $r1), '別の人・別の部署の責任者は変更できない');
$u = kvgw_res_update($kato, $r1['id'], array('title' => '乗っ取り') + $base);
ok(!$u[0] && kvgw_res_find($r1['id'])['title'] === 'A社 定例', '人の予約は書き換えられない');
$u = kvgw_res_update($yamada, $r1['id'], array('start' => '10:30', 'end' => '11:30') + $base);
ok(!$u[0], '変更で、ほかの予約に重ねることはできない');
$u = kvgw_res_update($yamada, $r1['id'], array('start' => '09:30', 'end' => '10:30') + $base);
ok($u[0] && $u[1]['start'] === '09:30', '自分の予約は、空いている時間へ変更できる(自分自身とは重ならない扱い)', $u[1]);
ok(!kvgw_res_delete($kato, $r1['id'])[0], '人の予約は取り消せない');
ok(kvgw_res_delete($suzuki, $r1['id'])[0] && kvgw_res_find($r1['id']) === null, '同じ部署の責任者は取り消せる');

echo "\n[9] 施設予約 — 繰り返し\n";
$mon = date('Y-m-d', strtotime('next monday'));
ok(kvgw_res_dates($mon, 'weekly', 3) === array($mon, date('Y-m-d', strtotime($mon . ' +7 days')), date('Y-m-d', strtotime($mon . ' +14 days'))), '毎週の日付');
$wd = kvgw_res_dates($mon, 'weekday', 7);
ok(count($wd) === 7 && $wd[4] === date('Y-m-d', strtotime($mon . ' +4 days')) && $wd[5] === date('Y-m-d', strtotime($mon . ' +7 days')), '平日だけの日付(土日を飛ばす)', $wd);
ok(count(kvgw_res_dates($mon, 'daily', 99)) === 30, '回数は30回まで');
$before = count(kvgw_res_all());
$w = kvgw_res_create($yamada, array('facility' => $car['id'], 'date' => $mon, 'start' => '13:00', 'end' => '14:00', 'title' => '週次の納品', 'repeat' => 'weekly', 'count' => 3));
ok($w[0] && count($w[1]) === 3 && $w[1][0]['series'] !== '' && $w[1][0]['series'] === $w[1][2]['series'], '毎週3回の予約が入る', $w[1]);
$w2 = kvgw_res_create($kato, array('facility' => $car['id'], 'date' => date('Y-m-d', strtotime($mon . ' -1 day')), 'start' => '13:30', 'end' => '14:30', 'title' => '毎日', 'repeat' => 'daily', 'count' => 4));
ok(!$w2[0] && count(kvgw_res_all()) === $before + 3, '繰り返しのうち1日でも重なれば、1件も入らない', count(kvgw_res_all()));
ok(!kvgw_res_create($yamada, array('facility' => $room['id'], 'date' => date('Y-m-d', strtotime('+50 days')), 'start' => '08:00', 'end' => '09:00', 'title' => '先すぎる', 'repeat' => 'weekly', 'count' => 4))[0],
   '繰り返しの最後が期間を越えるなら入らない');
$gonecount = kvgw_res_delete($yamada, $w[1][1]['id'], true);
ok($gonecount[0] && $gonecount[1] === 2 && kvgw_res_find($w[1][0]['id']) !== null, '「この日以降」をまとめて取り消せる(前の回は残る)', $gonecount[1]);

echo "\n[10] 施設予約 — 共有スケジュールとつながる\n";
$app = kv_app('schedule');
ok($app !== null, '共有スケジュール(Core同梱)が読める');
$s = kvgw_res_create($yamada, array('facility' => $room['id'], 'date' => $day, 'start' => '15:00', 'end' => '16:00', 'title' => 'B社 来社', 'to_schedule' => 1));
$sr = kvgw_res_find($s[1][0]['id']);
$rec = kv_record_find($app, $sr['sched']);
ok($sr['sched'] !== '' && $rec && $rec['title'] === 'B社 来社' && $rec['place'] === '第1会議室' && $rec['_owner'] === $yamada['id'], '予約が共有スケジュールにも載る', $rec);
kvgw_res_update($sato, $sr['id'], array('facility' => $room['id'], 'date' => $day, 'start' => '16:00', 'end' => '17:00', 'title' => 'B社 来社（時間変更）'));
$rec = kv_record_find($app, $sr['sched']);
ok($rec['start'] === '16:00' && $rec['title'] === 'B社 来社（時間変更）', '予約を変えると、予定も変わる', $rec);
kvgw_res_delete($yamada, $sr['id']);
ok(kv_record_find($app, $sr['sched']) === null, '予約を取り消すと、予定も消える');
ok(!kvgw_fac_delete($sato, $room['id'])[0], 'これからの予約が残る施設は消せない');
ok(!kvgw_fac_delete($yamada, $old['id'])[0] && kvgw_fac_delete($sato, $old['id'])[0], '予約の無い施設は、管理者だけが消せる');

echo "\n[11] ToDo — 見えるのは作った人と担当の人だけ\n";
ok(!kvgw_todo_save($yamada, '', array('title' => ''))[0], '名前の無い ToDo は作れない');
ok(!kvgw_todo_save($yamada, '', array('title' => 'x', 'due' => '来週'))[0], '期日が日付でなければ作れない');
ok(!kvgw_todo_save($yamada, '', array('title' => 'x', 'to' => $gone['id']))[0], '退職した人には頼めない');
$t1 = kvgw_todo_save($yamada, '', array('title' => '見積書を出す', 'due' => date('Y-m-d', strtotime('-1 day')), 'priority' => '1', 'category' => '見積'));
ok($t1[0] && $t1[1]['to'] === $yamada['id'] && $t1[1]['done'] === false, '自分の ToDo を作れる', $t1[1]);
$t1 = $t1[1];
$t2 = kvgw_todo_save($yamada, '', array('title' => '日報を書く', 'priority' => '9'))[1];
ok($t2['priority'] === '2', 'おかしな優先度は「中」になる');
$t3 = kvgw_todo_save($suzuki, '', array('title' => 'A社へ訪問日程を連絡', 'due' => date('Y-m-d', strtotime('+2 days')), 'to' => $yamada['id']))[1];
ok($t3['to'] === $yamada['id'] && $t3['by'] === $suzuki['id'] && $t3['to_name'] === '山田太郎', '人に頼める');
$mine = kvgw_todo_list($yamada, 'open', '');
ok(count($mine) === 3 && $mine[0]['id'] === $t1['id'] && $mine[2]['id'] === $t2['id'], '自分の ToDo は期日の近い順(期日なしは後ろ)', array_column($mine, 'title'));
ok(kvgw_todo_open_count($yamada) === 3 && kvgw_todo_open_count($suzuki) === 0, '件数は自分がやる分だけ');
ok(count(kvgw_todo_list($suzuki, 'asked', '')) === 1 && count(kvgw_todo_list($yamada, 'asked', '')) === 0, '頼んだ側には「人に頼んだToDo」に出る');
ok(count(kvgw_todo_list($kato, 'open', '')) === 0 && count(kvgw_todo_list($sato, 'open', '')) === 0 && count(kvgw_todo_list($sato, 'asked', '')) === 0,
   '関係ない人・管理者には見えない');
ok(!kvgw_todo_can($suzuki, $t1, 'read') && !kvgw_todo_can($sato, $t1, 'read'), '責任者・管理者でも、人の ToDo は見えない');
ok(count(kvgw_todo_list($yamada, 'open', '見積')) === 1 && kvgw_todo_categories($yamada) === array('見積') && kvgw_todo_categories($kato) === array(), 'カテゴリで絞れる(人のカテゴリは見えない)');

echo "\n[12] ToDo — 完了・変更・削除\n";
ok(!kvgw_todo_done($kato, $t1['id'], true)[0], '関係ない人は完了にできない');
ok(!kvgw_todo_save($yamada, $t3['id'], array('title' => '書き換え'))[0] && kvgw_todo_find($t3['id'])['title'] === 'A社へ訪問日程を連絡', '頼まれた側は中身を変えられない');
ok(!kvgw_todo_delete($yamada, $t3['id'])[0], '頼まれた側は消せない');
$dn = kvgw_todo_done($yamada, $t3['id'], true);
ok($dn[0] && $dn[1]['done'] === true && $dn[1]['done_by'] === '山田太郎', '頼まれた側が完了にできる', $dn[1]);
ok(count(kvgw_todo_list($yamada, 'open', '')) === 2 && count(kvgw_todo_list($suzuki, 'asked', '')) === 0 && count(kvgw_todo_list($suzuki, 'done', '')) === 1, '完了は頼んだ側にも見える');
ok(kvgw_todo_done($suzuki, $t3['id'], false)[0] && count(kvgw_todo_list($yamada, 'open', '')) === 3, '頼んだ側が未完了に戻せる');
$up = kvgw_todo_save($suzuki, $t3['id'], array('title' => 'A社へ訪問日程を連絡', 'to' => $kato['id']));
ok($up[0] && count(kvgw_todo_list($kato, 'open', '')) === 1 && count(kvgw_todo_list($yamada, 'open', '')) === 2, '作った人は担当を替えられる');
ok(!kvgw_todo_can($yamada, kvgw_todo_find($t3['id']), 'read'), '担当から外れた人には見えなくなる');
ok(!kvgw_todo_delete($sato, $t1['id'])[0] && kvgw_todo_delete($yamada, $t1['id'])[0] && kvgw_todo_find($t1['id']) === null, '消せるのは作った人だけ');

echo "\n[13] Core と一緒に動く\n";
ob_start(); kvgw_home($yamada); $home = ob_get_clean();
ok(strpos($home, '未読メッセージ') !== false && strpos($home, '今日の予定') !== false && strpos($home, 'ToDo') !== false, 'ホームに3つの枠が出る');
$nav = kvgw_nav($suzuki, 'gw:message');
ok(strpos($nav, 'm=message') !== false && strpos($nav, 'm=facility') !== false && strpos($nav, 'm=todo') !== false && strpos($nav, 'class="on"') !== false, 'タブに3つの機能が出る');
$src = file_get_contents(dirname(__DIR__) . '/public/kvgwc.php');
ok(substr_count($src, "function_exists('kvgw_") === 6 && strpos($src, "file_exists(__DIR__ . '/kvgw.php')") !== false, 'Core の拡張点が7か所そろっている(kvgw.php が無くても Core は動く)', substr_count($src, "function_exists('kvgw_"));
ok(strpos(kvgw_css(), '<') === false, 'CSS にタグが混ざっていない');
foreach (array('gw_messages', 'gw_facilities', 'gw_reservations', 'gw_todos') as $ledger) {
    ok(file_exists(kv_path($ledger)), '台帳 ' . $ledger . ' が Core と同じ置き場所にできる');
}

/* 後片づけ */
function rm_all($dir) {
    foreach (array_merge(glob($dir . '/*'), glob($dir . '/.ht*')) as $p) { is_dir($p) ? rm_all($p) : @unlink($p); }
    @rmdir($dir);
}
rm_all(KVGWC_DATA_DIR);

echo "\n================================\n  成功 $pass 件 / 失敗 $fail 件\n================================\n";
exit($fail > 0 ? 1 : 0);
