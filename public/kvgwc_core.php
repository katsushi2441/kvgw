<?php
/**
 * kvgwc Core — 社員マスタ・ユーザー(アカウント)・部署・役割・権限・台帳。
 *
 * 社員(人事の実体)とユーザー(ログインする権利)は別物として持つ。
 * 部署は社員が、役割はユーザーが持つ。合成は kv_user_compose() 1か所だけ。
 *
 * この製品の心臓は kv_can() ひとつ。「誰が」「どのアプリの」「どの操作を」
 * 「どの範囲まで」できるかを、ここだけで決める。画面やアプリ定義の側で
 * 権限を判定してはいけない。判定する場所が増えると、必ずどこかに穴が開く。
 *
 * データベースは使わない。JSON + flock で書く(kreserve/kbillingと同じ方式)。
 * 更新は必ず kv_update() を通すこと。読んでから書くまでを同じロックの中に
 * 収めないと、同時に保存したとき片方が消える。
 *
 * PHP 5.6でも動く書き方(array()・??なし)にしている。レンタルサーバーの
 * 既定PHPが古くても、設置しただけで動くことを優先した。
 */

date_default_timezone_set('Asia/Tokyo');

if (!defined('KVGWC_DATA_DIR')) { define('KVGWC_DATA_DIR', __DIR__ . '/kvgwc_data'); }
if (!defined('KVGWC_APPS_DIR')) { define('KVGWC_APPS_DIR', __DIR__ . '/apps'); }

function kv_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function kv_random_hex($bytes) {
    if (function_exists('random_bytes')) { return bin2hex(random_bytes($bytes)); }
    return bin2hex(openssl_random_pseudo_bytes($bytes));
}

function kv_now() { return date('Y-m-d H:i:s'); }

function kv_is_demo() { return defined('KVGWC_DEMO') && KVGWC_DEMO; }

/* ============================================================
 * 台帳(JSONファイル) — 読み書きはすべてここを通す
 * ============================================================ */

function kv_path($name) { return KVGWC_DATA_DIR . '/' . $name . '.json'; }

/**
 * 台帳の置き場所を作る。同時に .htaccess を書いて、ブラウザから
 * users.json や app_*.json を直接ダウンロードできないようにする。
 * ここを忘れると、URLを推測するだけで社内の記録が全部読まれる。
 * (Apache以外のサーバーでは効かないので、設置先に応じて別途塞ぐこと)
 */
function kv_ensure_data_dir() {
    if (!is_dir(KVGWC_DATA_DIR)) { @mkdir(KVGWC_DATA_DIR, 0700, true); }
    $ht = KVGWC_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "# 台帳(個人情報)を直接読ませない\n"
          . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

/** 読むだけ。共有ロックで読むので、書き込み中の半端なJSONを掴まない。 */
function kv_load($name, $key) {
    $path = kv_path($name);
    if (!file_exists($path)) { return array(); }
    $fp = fopen($path, 'rb');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($json, true);
    return (is_array($data) && isset($data[$key]) && is_array($data[$key])) ? $data[$key] : array();
}

/**
 * 排他ロックの中で更新する。$fn には配列が参照で渡り、
 * 文字列を返すとエラー(保存しない)、それ以外は保存して成功。
 * 戻り値: array(true, $fnの戻り値) または array(false, エラーメッセージ)
 *
 * 「空きを判定してから書く」「重複を確認してから書く」は、必ずこの $fn の
 * 中でやること。ロックの外で判定してから書くと、同時アクセスで破れる。
 */
function kv_update($name, $key, $fn) {
    kv_ensure_data_dir();
    $path = kv_path($name);
    $fp = fopen($path, 'c+b');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    $json = stream_get_contents($fp);
    $data = json_decode($json, true);
    if (!is_array($data)) { $data = array(); }
    if (!isset($data[$key]) || !is_array($data[$key])) { $data[$key] = array(); }

    $result = $fn($data[$key]);
    if (is_string($result)) {                 // エラー: 1バイトも書かずに戻す
        flock($fp, LOCK_UN); fclose($fp);
        return array(false, $result);
    }
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return array(true, $result);
}

/* ============================================================
 * 組織 — 部署と役割は設定で決める(コードに埋めない)
 * ============================================================ */

function kv_depts() {
    return function_exists('kvgwc_depts') ? kvgwc_depts() : array('main' => '全社');
}

function kv_dept_name($key) {
    $d = kv_depts();
    return isset($d[$key]) ? $d[$key] : $key;
}

/** 役割は3つ固定。増やすと権限表が読めなくなるので、増やさない。 */
function kv_roles() {
    return array('staff' => '担当', 'chief' => '責任者', 'admin' => '管理者');
}

/* ============================================================
 * 社員マスタ — 人事の実体
 *
 * ユーザー(ログインする権利)とは別に持つ。理由は3つ。
 *   1. アカウントを持たない社員がいる(現場・パート・アルバイト)
 *   2. 退職してもデータは残す。消すと過去の日報の作成者が消える
 *   3. 部署・役職は人事の情報であって、ログインの情報ではない
 *
 * 部署は社員が持ち、役割(権限)はユーザーが持つ。この線を曖昧にすると、
 * 「退職したのにログインできる」「アカウントを消したら部署も消えた」が起きる。
 * ============================================================ */

function kv_employees() { return kv_load('employees', 'employees'); }

function kv_employee_find($id) {
    if ($id === '' || $id === null) { return null; }
    foreach (kv_employees() as $e) { if ($e['id'] === $id) { return $e; } }
    return null;
}

function kv_employee_by_email($email) {
    $email = strtolower(trim($email));
    if ($email === '') { return null; }
    foreach (kv_employees() as $e) {
        if (isset($e['email']) && strtolower($e['email']) === $email) { return $e; }
    }
    return null;
}

function kv_emp_statuses() {
    return array('active' => '在籍', 'leave' => '休職', 'retired' => '退職');
}

function kv_emp_status_label($s) {
    $m = kv_emp_statuses();
    return isset($m[$s]) ? $m[$s] : $s;
}

/**
 * 社員の入力を検証する。戻り値: array($values, $errors)
 * $selfId を渡すと、その社員自身は重複チェックから除く(自分の編集時)。
 */
function kv_employee_validate($in, $selfId = '') {
    $v = array();
    $e = array();
    $depts = kv_depts();
    $stat = kv_emp_statuses();

    $v['name'] = isset($in['name']) ? trim((string)$in['name']) : '';
    if ($v['name'] === '') { $e['name'] = '氏名を入力してください'; }
    else if (mb_strlen($v['name'], 'UTF-8') > 60) { $e['name'] = '氏名が長すぎます'; }

    $v['kana'] = isset($in['kana']) ? trim((string)$in['kana']) : '';
    $v['no'] = isset($in['no']) ? trim((string)$in['no']) : '';
    if ($v['no'] !== '' && !preg_match('/^[A-Za-z0-9\-_]{1,20}$/', $v['no'])) {
        $e['no'] = '社員番号は英数字・ハイフン20文字までです';
    }

    $v['dept'] = isset($in['dept']) ? (string)$in['dept'] : '';
    if (!isset($depts[$v['dept']])) { $e['dept'] = '部署を選んでください'; }

    $v['title'] = isset($in['title']) ? trim((string)$in['title']) : '';
    $v['email'] = isset($in['email']) ? strtolower(trim((string)$in['email'])) : '';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $e['email'] = 'メールアドレスの形式が正しくありません';
    }
    $v['tel'] = isset($in['tel']) ? trim((string)$in['tel']) : '';
    $v['mobile'] = isset($in['mobile']) ? trim((string)$in['mobile']) : '';

    foreach (array('joined', 'left') as $k) {
        $v[$k] = isset($in[$k]) ? trim((string)$in[$k]) : '';
        if ($v[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v[$k])) {
            $e[$k] = ($k === 'joined' ? '入社日' : '退職日') . 'は日付で入力してください';
        }
    }
    if (!isset($e['joined']) && !isset($e['left'])
        && $v['joined'] !== '' && $v['left'] !== '' && $v['left'] < $v['joined']) {
        $e['left'] = '退職日が入社日より前になっています';
    }

    $v['status'] = isset($in['status']) ? (string)$in['status'] : 'active';
    if (!isset($stat[$v['status']])) { $e['status'] = '在籍状態が不正です'; }

    $v['note'] = isset($in['note']) ? trim((string)$in['note']) : '';
    if (mb_strlen($v['note'], 'UTF-8') > 2000) { $e['note'] = '備考が長すぎます'; }

    // 社員番号とメールは会社の中で一意。重複すると誰の記録か分からなくなる
    foreach (kv_employees() as $other) {
        if ($other['id'] === $selfId) { continue; }
        if ($v['no'] !== '' && isset($other['no']) && $other['no'] === $v['no']) {
            $e['no'] = 'その社員番号は既に使われています';
        }
        if ($v['email'] !== '' && isset($other['email']) && strtolower($other['email']) === $v['email']) {
            $e['email'] = 'そのメールアドレスは既に登録されています';
        }
    }
    return array($v, $e);
}

function kv_employee_create($values) {
    return kv_update('employees', 'employees', function (&$emps) use ($values) {
        $new = $values;
        $new['id'] = 'e' . kv_random_hex(8);
        $new['created'] = kv_now();
        $new['updated'] = kv_now();
        $emps[] = $new;
        return $new;
    });
}

function kv_employee_update($id, $values) {
    return kv_update('employees', 'employees', function (&$emps) use ($id, $values) {
        foreach ($emps as $i => $e) {
            if ($e['id'] !== $id) { continue; }
            foreach ($values as $k => $v) { $emps[$i][$k] = $v; }
            $emps[$i]['updated'] = kv_now();
            return $emps[$i];
        }
        return '社員が見つかりません';
    });
}

/** その社員のアカウントが作った記録の件数。削除してよいかの判断に使う。 */
function kv_employee_record_count($empId) {
    $ids = array();
    foreach (kv_users() as $u) {
        if (isset($u['employee_id']) && $u['employee_id'] === $empId) { $ids[] = $u['id']; }
    }
    if (!$ids) { return 0; }
    $n = 0;
    foreach (kv_apps() as $app) {
        foreach (kv_load(kv_ledger_name($app['key']), 'records') as $r) {
            if (isset($r['_owner']) && in_array($r['_owner'], $ids, true)) { $n++; }
        }
    }
    return $n;
}

/**
 * 社員を消す。記録が1件でも残っていたら消さない。
 * 消すと過去の日報や点検記録の作成者が分からなくなるため、退職にしてもらう。
 */
function kv_employee_delete($id) {
    $n = kv_employee_record_count($id);
    if ($n > 0) {
        return array(false, 'この社員の記録が' . $n . '件あるため削除できません。'
                          . '在籍状態を「退職」にしてください');
    }
    foreach (kv_users() as $u) {
        if (isset($u['employee_id']) && $u['employee_id'] === $id) {
            return array(false, 'アカウントが残っています。先にアカウントを削除してください');
        }
    }
    return kv_update('employees', 'employees', function (&$emps) use ($id) {
        foreach ($emps as $i => $e) {
            if ($e['id'] !== $id) { continue; }
            array_splice($emps, $i, 1);
            return true;
        }
        return '社員が見つかりません';
    });
}

/* ============================================================
 * ユーザー — ログインする権利
 *
 * 社員1人につきアカウントは0個か1個。持たない社員がいてよい。
 * 画面や権限判定が使う $user は、社員の情報(氏名・部署)を合成したもの。
 * kv_user_compose() が唯一の合成場所で、ここで退職者を締め出している。
 * ============================================================ */

function kv_users() { return kv_load('users', 'users'); }

/**
 * アカウントに社員の情報を合わせて、画面と権限判定が使う形にする。
 * **退職・休職の社員はここで active=false になる。** 人事の状態変更だけで
 * ログインが止まるようにするため、締め出しの判断をここ1か所に集めている。
 */
function kv_user_compose($u) {
    $e = kv_employee_find(isset($u['employee_id']) ? $u['employee_id'] : '');
    $u['employee'] = $e;
    $u['name']  = $e ? $e['name'] : (isset($u['email']) ? $u['email'] : '');
    $u['dept']  = $e ? $e['dept'] : '';
    $u['emp_no'] = $e && isset($e['no']) ? $e['no'] : '';
    $u['title'] = $e && isset($e['title']) ? $e['title'] : '';
    $u['active'] = !empty($u['active']) && $e && isset($e['status']) && $e['status'] === 'active';
    return $u;
}

function kv_user_find($id) {
    foreach (kv_users() as $u) { if ($u['id'] === $id) { return kv_user_compose($u); } }
    return null;
}

function kv_user_by_email($email) {
    $email = strtolower(trim($email));
    foreach (kv_users() as $u) {
        if (strtolower($u['email']) === $email) { return kv_user_compose($u); }
    }
    return null;
}

function kv_user_by_employee($empId) {
    foreach (kv_users() as $u) {
        if (isset($u['employee_id']) && $u['employee_id'] === $empId) { return kv_user_compose($u); }
    }
    return null;
}

/**
 * アカウントを作る、または既にあれば返す(初回ログイン時の自動登録)。
 *
 * Google Workspaceで入った人は、管理者が事前に登録しなくてもここで生まれる。
 * 社員マスタに同じメールの人がいればその人に紐づけ、いなければ社員も作る
 * (Workspaceに居る=在籍している、と見なせるため)。
 * ただし役割は必ず最下位(staff)から。昇格は管理者が明示的に行う。
 */
function kv_user_upsert($email, $name, $provider, $sub, $defaults = array()) {
    $email = strtolower(trim($email));
    if ($email === '') { return array(false, 'メールアドレスがありません'); }

    $existing = null;
    foreach (kv_users() as $u) {
        if (strtolower($u['email']) === $email) { $existing = $u; break; }
    }

    if (!$existing) {
        // 社員が先にいればそこへ紐づける(管理者が入社時に登録しておく運用)
        $emp = kv_employee_by_email($email);
        if (!$emp) {
            $depts = kv_depts();
            $dept = isset($defaults['dept']) && isset($depts[$defaults['dept']])
                    ? $defaults['dept'] : key($depts);
            list($eok, $emp) = kv_employee_create(array(
                'name' => $name !== '' ? $name : $email, 'kana' => '', 'no' => '',
                'dept' => $dept, 'title' => '', 'email' => $email,
                'tel' => '', 'mobile' => '', 'joined' => date('Y-m-d'), 'left' => '',
                'status' => 'active', 'note' => '初回ログイン時に自動登録',
            ));
            if (!$eok) { return array(false, $emp); }
        } else if ($emp['status'] !== 'active') {
            return array(false, '在籍中の社員ではありません');
        }
        $empId = $emp['id'];
    } else {
        $empId = isset($existing['employee_id']) ? $existing['employee_id'] : '';
    }

    $roles = kv_roles();
    $wantRole = isset($defaults['role']) && isset($roles[$defaults['role']]) ? $defaults['role'] : null;

    list($ok, $res) = kv_update('users', 'users',
        function (&$users) use ($email, $provider, $sub, $empId, $wantRole) {
            foreach ($users as $i => $u) {
                if (strtolower($u['email']) !== $email) { continue; }
                $users[$i]['sub'] = $sub;
                $users[$i]['last_login'] = kv_now();
                return $users[$i];
            }
            $first = count($users) === 0;
            $emp0 = kv_employee_find($empId);
            $new = array(
                'id' => 'u' . kv_random_hex(8),
                'employee_id' => $empId,
                // 社員番号があればログインIDにする(あとで password 方式へ移っても同じIDで入れる)
                'login_id' => ($emp0 && isset($emp0['no']) && $emp0['no'] !== '') ? $emp0['no'] : '',
                'email' => $email,
                'password_hash' => '',
                'password_set' => '',
                'must_change' => false,
                // 最初の1人だけ管理者。誰も管理者がいない状態を作らないための例外。
                'role' => $wantRole !== null ? $wantRole : ($first ? 'admin' : 'staff'),
                'provider' => $provider,
                'sub' => $sub,
                'active' => true,
                'created' => kv_now(),
                'last_login' => kv_now(),
            );
            $users[] = $new;
            return $new;
        });
    if (!$ok) { return array(false, $res); }

    // 名前が空のまま作られた社員に、認証プロバイダの表示名を補う
    if ($name !== '' && $empId !== '') {
        $emp = kv_employee_find($empId);
        if ($emp && ($emp['name'] === '' || $emp['name'] === $email)) {
            kv_employee_update($empId, array('name' => $name));
        }
    }
    return array(true, kv_user_compose($res));
}

/**
 * 社員にアカウントを作る(管理者が画面から行う)。
 *
 * ログインIDは社員番号を既定にする。パスワード方式では、社員が毎日打つのは
 * メールアドレスではなく社員番号だからで、退職者のメールを再利用した際に
 * 別人のアカウントへ入ってしまう事故も避けられる。
 * Google/Microsoft方式ではメールアドレスで本人を特定するので、両方持つ。
 */
function kv_account_create($empId, $loginId, $email, $role) {
    $emp = kv_employee_find($empId);
    if (!$emp) { return array(false, '社員が見つかりません'); }
    $roles = kv_roles();
    if (!isset($roles[$role])) { return array(false, '役割が不正です'); }

    $loginId = trim((string)$loginId);
    $email = strtolower(trim((string)$email));
    if ($loginId === '' && $email === '') {
        return array(false, 'ログインID（社員番号）を入力してください');
    }
    if ($loginId !== '' && !preg_match('/^[A-Za-z0-9\-_.@]{1,40}$/', $loginId)) {
        return array(false, 'ログインIDは英数字・ハイフン・アンダースコア40文字までです');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array(false, 'メールアドレスの形式が正しくありません');
    }
    if (kv_user_by_employee($empId)) { return array(false, 'この社員には既にアカウントがあります'); }

    return kv_update('users', 'users', function (&$users) use ($empId, $loginId, $email, $role) {
        foreach ($users as $u) {
            if ($loginId !== '' && isset($u['login_id'])
                && strcasecmp($u['login_id'], $loginId) === 0) {
                return 'そのログインIDは既に使われています';
            }
            if ($email !== '' && isset($u['email']) && $u['email'] !== ''
                && strcasecmp($u['email'], $email) === 0) {
                return 'そのメールアドレスは既に使われています';
            }
        }
        $new = array(
            'id' => 'u' . kv_random_hex(8),
            'employee_id' => $empId,
            'login_id' => $loginId,
            'email' => $email,
            'password_hash' => '',        // 発行するまでパスワードでは入れない
            'password_set' => '',
            'must_change' => false,
            'role' => $role,
            'provider' => kv_auth_provider_name(),
            'sub' => '',
            'active' => true,
            'created' => kv_now(),
            'last_login' => '',
        );
        $users[] = $new;
        return $new;
    });
}

/** ログインID（社員番号）またはメールアドレスからアカウントを引く。 */
function kv_account_by_login($loginId) {
    $loginId = trim((string)$loginId);
    if ($loginId === '') { return null; }
    foreach (kv_users() as $u) {
        if (isset($u['login_id']) && $u['login_id'] !== ''
            && strcasecmp($u['login_id'], $loginId) === 0) { return kv_user_compose($u); }
    }
    // 社員番号で見つからなければメールアドレスでも探す(会社によって呼び方が違う)
    foreach (kv_users() as $u) {
        if (isset($u['email']) && $u['email'] !== ''
            && strcasecmp($u['email'], $loginId) === 0) { return kv_user_compose($u); }
    }
    return null;
}

/** パスワードの決まり。短いものを許すと、総当たりで社内の記録が全部読まれる。 */
function kv_password_policy($plain) {
    $plain = (string)$plain;
    if (strlen($plain) < 8) { return 'パスワードは8文字以上にしてください'; }
    if (strlen($plain) > 200) { return 'パスワードが長すぎます'; }
    if (preg_match('/^[0-9]+$/', $plain)) { return '数字だけのパスワードは使えません'; }
    return '';
}

/** 初期パスワードを作る。紙に書いて渡すので、読み違えない文字だけを使う。 */
function kv_password_generate() {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // i l o 0 1 を除く
    $n = strlen($alphabet);
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        if ($i === 4 || $i === 8) { $out .= '-'; }
        $out .= $alphabet[random_int(0, $n - 1)];
    }
    return $out;
}

/**
 * パスワードを設定する。$mustChange を立てると、次のログインで変更を求める
 * (管理者が発行した初期パスワードは、本人しか知らない状態にしてから使わせる)。
 */
function kv_account_set_password($userId, $plain, $mustChange) {
    $why = kv_password_policy($plain);
    if ($why !== '') { return array(false, $why); }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    return kv_update('users', 'users', function (&$users) use ($userId, $hash, $mustChange) {
        foreach ($users as $i => $u) {
            if ($u['id'] !== $userId) { continue; }
            $users[$i]['password_hash'] = $hash;
            $users[$i]['password_set'] = kv_now();
            $users[$i]['must_change'] = (bool)$mustChange;
            return $users[$i];
        }
        return 'アカウントが見つかりません';
    });
}

function kv_account_clear_password($userId) {
    return kv_update('users', 'users', function (&$users) use ($userId) {
        foreach ($users as $i => $u) {
            if ($u['id'] !== $userId) { continue; }
            $users[$i]['password_hash'] = '';
            $users[$i]['password_set'] = '';
            $users[$i]['must_change'] = false;
            return $users[$i];
        }
        return 'アカウントが見つかりません';
    });
}

/** 認証方式の名前。kvgwc_auth.php が読み込まれていない場面でも落ちないようにする。 */
function kv_auth_provider_name() {
    return function_exists('kv_auth_provider') ? kv_auth_provider() : 'password';
}

/** 役割と有効/無効の変更。部署は社員の情報なので、ここでは扱わない。 */
function kv_user_edit($id, $role, $active) {
    $roles = kv_roles();
    if (!isset($roles[$role])) { return array(false, '役割が不正です'); }
    return kv_update('users', 'users', function (&$users) use ($id, $role, $active) {
        $admins = 0;
        foreach ($users as $u) { if ($u['role'] === 'admin' && !empty($u['active'])) { $admins++; } }
        foreach ($users as $i => $u) {
            if ($u['id'] !== $id) { continue; }
            // 最後の管理者を降格・停止できないようにする。閉め出されると復旧手段がない。
            $losing = ($u['role'] === 'admin' && !empty($u['active']))
                      && ($role !== 'admin' || !$active);
            if ($losing && $admins <= 1) { return '最後の管理者は変更できません'; }
            $users[$i]['role'] = $role;
            $users[$i]['active'] = (bool)$active;
            return $users[$i];
        }
        return 'ユーザーが見つかりません';
    });
}

/** アカウントを消す(社員は残る)。最後の管理者は消せない。 */
function kv_account_delete($id) {
    return kv_update('users', 'users', function (&$users) use ($id) {
        $admins = 0;
        foreach ($users as $u) { if ($u['role'] === 'admin' && !empty($u['active'])) { $admins++; } }
        foreach ($users as $i => $u) {
            if ($u['id'] !== $id) { continue; }
            if ($u['role'] === 'admin' && !empty($u['active']) && $admins <= 1) {
                return '最後の管理者のアカウントは削除できません';
            }
            array_splice($users, $i, 1);
            return true;
        }
        return 'アカウントが見つかりません';
    });
}

/* ============================================================
 * アプリ — apps/ に置いたファイルが、そのままアプリになる
 * ============================================================ */

/**
 * apps/*.php を読み込む。各ファイルは定義の配列を return するだけ。
 * ここが「アプリを置くだけで増える」の実体。登録作業も再起動も要らない。
 */
function kv_apps() {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = array();
    foreach (glob(KVGWC_APPS_DIR . '/*.php') as $file) {
        $def = include $file;
        if (!is_array($def) || !isset($def['key'])) { continue; }
        $def['key'] = preg_replace('/[^a-z0-9_]/', '', strtolower($def['key']));
        if ($def['key'] === '') { continue; }
        if (!isset($def['fields']) || !is_array($def['fields'])) { continue; }
        if (!isset($def['name'])) { $def['name'] = $def['key']; }
        if (!isset($def['icon'])) { $def['icon'] = '📄'; }
        // 画面の名前の横に出す短い札。「別売り」「試験運用中」などを示すのに使う
        if (!isset($def['badge'])) { $def['badge'] = ''; }
        if (!isset($def['permissions'])) { $def['permissions'] = array(); }
        if (!isset($def['approval'])) { $def['approval'] = false; }
        $cache[$def['key']] = $def;
    }
    uasort($cache, function ($a, $b) {
        $oa = isset($a['order']) ? $a['order'] : 100;
        $ob = isset($b['order']) ? $b['order'] : 100;
        if ($oa === $ob) { return strcmp($a['key'], $b['key']); }
        return $oa < $ob ? -1 : 1;
    });
    return $cache;
}

function kv_app($key) {
    $apps = kv_apps();
    return isset($apps[$key]) ? $apps[$key] : null;
}

/* ============================================================
 * 権限 — この製品の心臓。判定はここだけ
 * ============================================================ */

/**
 * $user は $op を $app に対してできるか。$record を渡すと、その1件について判定する。
 *
 * scope の意味:
 *   'own'  自分が作ったものだけ
 *   'dept' 同じ部署の人が作ったものだけ
 *   'all'  全部
 *   false  できない
 *
 * $record を渡さない場合は「1件でもできる可能性があるか」を返す。
 * 一覧画面のボタンを出すかどうかの判断に使う。
 */
function kv_can($user, $app, $op, $record = null) {
    if (!$user || empty($user['active'])) { return false; }
    if (!is_array($app)) { return false; }

    $role = isset($user['role']) ? $user['role'] : 'staff';
    $perm = isset($app['permissions'][$role]) ? $app['permissions'][$role] : null;
    if ($perm === null) { return false; }
    if (isset($perm['*'])) { $scope = $perm['*']; }
    else if (!isset($perm[$op])) { return false; }
    else { $scope = $perm[$op]; }

    if ($scope === false || $scope === null) { return false; }
    // create は範囲の概念がない(まだレコードが無い)ので true/false だけ
    if ($op === 'create') { return $scope === true || $scope === 'all' || $scope === 'own' || $scope === 'dept'; }
    if ($scope === true) { $scope = 'all'; }

    if ($record === null) { return true; }              // 「可能性があるか」の問い合わせ
    if ($scope === 'all') { return true; }
    if ($scope === 'dept') {
        return isset($record['_dept']) && $record['_dept'] === $user['dept'];
    }
    if ($scope === 'own') {
        return isset($record['_owner']) && $record['_owner'] === $user['id'];
    }
    return false;
}

/** kv_can と同じ判定で、読める範囲('own'/'dept'/'all'/false)そのものを返す。 */
function kv_scope($user, $app, $op) {
    if (!$user || empty($user['active'])) { return false; }
    $role = isset($user['role']) ? $user['role'] : 'staff';
    $perm = isset($app['permissions'][$role]) ? $app['permissions'][$role] : null;
    if ($perm === null) { return false; }
    $scope = isset($perm['*']) ? $perm['*'] : (isset($perm[$op]) ? $perm[$op] : false);
    if ($scope === false || $scope === null) { return false; }
    if ($scope === true) { return 'all'; }
    return $scope;
}

/* ============================================================
 * レコード
 * ============================================================ */

function kv_ledger_name($appkey) { return 'app_' . $appkey; }

/** 読める範囲だけを返す。画面側でフィルタしないこと(漏れの原因になる)。 */
function kv_records($user, $app) {
    $scope = kv_scope($user, $app, 'read');
    if ($scope === false) { return array(); }
    $all = kv_load(kv_ledger_name($app['key']), 'records');
    if ($scope === 'all') { return $all; }
    $out = array();
    foreach ($all as $r) {
        if ($scope === 'dept' && isset($r['_dept']) && $r['_dept'] === $user['dept']) { $out[] = $r; }
        else if ($scope === 'own' && isset($r['_owner']) && $r['_owner'] === $user['id']) { $out[] = $r; }
    }
    return $out;
}

function kv_record_find($app, $id) {
    foreach (kv_load(kv_ledger_name($app['key']), 'records') as $r) {
        if ($r['_id'] === $id) { return $r; }
    }
    return null;
}

/** 新規作成。$values は kv_validate を通した後の値だけを渡すこと。 */
function kv_record_create($user, $app, $values) {
    if (!kv_can($user, $app, 'create')) { return array(false, 'この操作の権限がありません'); }
    return kv_update(kv_ledger_name($app['key']), 'records', function (&$rs) use ($user, $app, $values) {
        $r = $values;
        $r['_id'] = 'r' . kv_random_hex(8);
        $r['_owner'] = $user['id'];
        $r['_owner_name'] = $user['name'];
        $r['_dept'] = $user['dept'];
        $r['_status'] = $app['approval'] ? 'draft' : 'done';
        $r['_created'] = kv_now();
        $r['_updated'] = kv_now();
        $rs[] = $r;
        return $r;
    });
}

function kv_record_update($user, $app, $id, $values) {
    return kv_update(kv_ledger_name($app['key']), 'records', function (&$rs) use ($user, $app, $id, $values) {
        foreach ($rs as $i => $r) {
            if ($r['_id'] !== $id) { continue; }
            // 権限はロックの中で確認する。外で確認すると、その間に持ち主が変わりうる。
            if (!kv_can($user, $app, 'update', $r)) { return 'この記録を編集する権限がありません'; }
            if ($app['approval'] && isset($r['_status']) && $r['_status'] === 'approved'
                && kv_scope($user, $app, 'approve') === false) {
                return '承認済みの記録は編集できません';
            }
            foreach ($values as $k => $v) { $rs[$i][$k] = $v; }
            $rs[$i]['_updated'] = kv_now();
            return $rs[$i];
        }
        return '記録が見つかりません';
    });
}

function kv_record_delete($user, $app, $id) {
    return kv_update(kv_ledger_name($app['key']), 'records', function (&$rs) use ($user, $app, $id) {
        foreach ($rs as $i => $r) {
            if ($r['_id'] !== $id) { continue; }
            if (!kv_can($user, $app, 'delete', $r)) { return 'この記録を削除する権限がありません'; }
            array_splice($rs, $i, 1);
            return true;
        }
        return '記録が見つかりません';
    });
}

/** 申請・承認・差戻し。承認フローを持つアプリ(approval=true)だけで動く。 */
function kv_record_status($user, $app, $id, $to) {
    if (!$app['approval']) { return array(false, 'このアプリは承認を使いません'); }
    $allowed = array('draft', 'submitted', 'approved', 'rejected');
    if (!in_array($to, $allowed, true)) { return array(false, '状態が不正です'); }
    return kv_update(kv_ledger_name($app['key']), 'records', function (&$rs) use ($user, $app, $id, $to) {
        foreach ($rs as $i => $r) {
            if ($r['_id'] !== $id) { continue; }
            if ($to === 'submitted') {
                // 申請は自分の記録に対してだけ。他人の記録を勝手に申請させない。
                if (!kv_can($user, $app, 'update', $r)) { return '申請する権限がありません'; }
            } else {
                if (!kv_can($user, $app, 'approve', $r)) { return '承認する権限がありません'; }
                if ($r['_owner'] === $user['id'] && $to === 'approved'
                    && kv_scope($user, $app, 'approve') !== 'all') {
                    return '自分の申請は承認できません';
                }
            }
            $rs[$i]['_status'] = $to;
            $rs[$i]['_status_by'] = $user['name'];
            $rs[$i]['_updated'] = kv_now();
            return $rs[$i];
        }
        return '記録が見つかりません';
    });
}

function kv_status_label($s) {
    $m = array('draft' => '下書き', 'submitted' => '申請中', 'approved' => '承認済み',
               'rejected' => '差戻し', 'done' => '—');
    return isset($m[$s]) ? $m[$s] : $s;
}

/* ============================================================
 * アカウントの棚卸し
 *
 * 「誰がログインできるのか」を一覧で見られないと、退職者のアカウントが
 * 残っていることに気づけない。権限の棚卸しは事故が起きてからでは遅いので、
 * 注意すべきアカウントを土台の側から名指しする。
 * ============================================================ */

/**
 * 設定ファイルの初期管理者か。このアカウントは台帳にパスワードを持たない
 * (設定側のハッシュで入る)ので、「未発行」として警告しない。
 */
function kv_is_bootstrap_admin($raw) {
    $bootHash = defined('KVGWC_PASSWORD_HASH') ? KVGWC_PASSWORD_HASH : '';
    if ($bootHash === '') { return false; }
    $bootId = (defined('KVGWC_ADMIN_LOGIN') && KVGWC_ADMIN_LOGIN !== '') ? KVGWC_ADMIN_LOGIN : 'admin';
    return isset($raw['login_id']) && strcasecmp($raw['login_id'], $bootId) === 0;
}

function kv_account_audit() {
    $out = array();
    $now = time();
    foreach (kv_users() as $raw) {
        $u = kv_user_compose($raw);
        $e = $u['employee'];
        $warn = array();
        if (!$e) {
            $warn[] = '社員に紐づいていません';
        } else if ($e['status'] !== 'active') {
            $warn[] = '社員が' . kv_emp_status_label($e['status']) . 'です';
        }
        if (kv_auth_provider_name() === 'password' && empty($raw['password_hash'])
            && !kv_is_bootstrap_admin($raw)) {
            $warn[] = 'パスワードが未発行です';
        }
        if (empty($raw['last_login'])) {
            $warn[] = '一度もログインしていません';
        } else {
            $ts = strtotime($raw['last_login']);
            if ($ts && ($now - $ts) > 90 * 86400) { $warn[] = '90日以上ログインがありません'; }
        }
        $out[] = array('raw' => $raw, 'user' => $u, 'warn' => $warn);
    }
    // 注意のあるものを先頭に。次に役割の重い順(管理者→責任者→担当)
    usort($out, function ($a, $b) {
        $wa = count($a['warn']) > 0 ? 0 : 1;
        $wb = count($b['warn']) > 0 ? 0 : 1;
        if ($wa !== $wb) { return $wa < $wb ? -1 : 1; }
        $ord = array('admin' => 0, 'chief' => 1, 'staff' => 2);
        $ra = isset($ord[$a['raw']['role']]) ? $ord[$a['raw']['role']] : 3;
        $rb = isset($ord[$b['raw']['role']]) ? $ord[$b['raw']['role']] : 3;
        if ($ra !== $rb) { return $ra < $rb ? -1 : 1; }
        return strcmp($a['user']['name'], $b['user']['name']);
    });
    return $out;
}

/** 役割ごとの人数と、ログインできる人数。権限が広がりすぎていないかを見る。 */
function kv_account_summary() {
    $s = array('total' => 0, 'usable' => 0, 'stopped' => 0, 'warn' => 0);
    foreach (kv_roles() as $k => $n) { $s[$k] = 0; }
    foreach (kv_account_audit() as $row) {
        $s['total']++;
        if (!empty($row['user']['active'])) { $s['usable']++; } else { $s['stopped']++; }
        if ($row['warn']) { $s['warn']++; }
        $r = $row['raw']['role'];
        if (isset($s[$r])) { $s[$r]++; }
    }
    return $s;
}
