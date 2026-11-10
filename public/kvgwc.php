<?php
/**
 * kvgwc — Kurage Vibe Groupware Core
 *
 * 画面とルーター。ここは「見せ方」だけを持ち、権限の判定は一切しない。
 * 判定は kv_can()(Core)にしかない。画面側で条件分岐を足すと、そこが穴になる。
 *
 * アプリを増やすときに、このファイルを触る必要はない。apps/ に定義を置くだけ。
 */

/* 拡張点(1) kvgw.php があれば先に読む。製品名・版と、メッセージ・施設予約・ToDo を足す。
   無ければ Core 単体として動く(このファイルの拡張点は全部 function_exists で守ってある)。 */
if (file_exists(__DIR__ . '/kvgw.php')) { require_once __DIR__ . '/kvgw.php'; }
if (!defined('KVGWC_PRODUCT')) { define('KVGWC_PRODUCT', 'Kurage Vibe Groupware Core'); }
if (!defined('KVGWC_VERSION')) { define('KVGWC_VERSION', 'v1.0'); }

$conf = __DIR__ . '/kvgwc_config.php';
if (!file_exists($conf)) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>設定がありません</h1><p>kvgwc_config.php.example をコピーして '
       . 'kvgwc_config.php を作ってください。</p>';
    exit;
}
require_once $conf;
require_once __DIR__ . '/kvgwc_core.php';
require_once __DIR__ . '/kvgwc_auth.php';
require_once __DIR__ . '/kvgwc_form.php';
require_once __DIR__ . '/kvgwc_ical.php';

kv_session_start();

$do   = isset($_GET['do']) ? preg_replace('/[^a-z]/', '', $_GET['do']) : '';
$key  = isset($_GET['app']) ? preg_replace('/[^a-z0-9_]/', '', $_GET['app']) : '';
$id   = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']) : '';
$q    = isset($_GET['q']) ? (string)$_GET['q'] : '';
$user = kv_current_user();
$self = basename(__FILE__);

/* ============================================================
 * 認証（ログインしていない人が通れるのはここまで）
 * ============================================================ */

if ($do === 'logout') { kv_logout(); header('Location: ' . $self); exit; }

if ($do === 'oauth' && kv_auth_provider() !== 'password') {
    $url = kv_oidc_authorize_url();
    if ($url === '') { kv_fail(500, '認証プロバイダの設定が不完全です'); }
    header('Location: ' . $url); exit;
}

if ($do === 'callback') {
    if (!empty($_GET['error'])) { kv_login_screen('ログインがキャンセルされました'); exit; }
    $code = isset($_GET['code']) ? (string)$_GET['code'] : '';
    if ($code === '') { kv_login_screen('認証コードがありません'); exit; }
    list($ok, $res) = kv_oidc_callback($code, isset($_GET['state']) ? $_GET['state'] : '');
    if (!$ok) { kv_login_screen($res); exit; }
    kv_login_as($res);
    header('Location: ' . $self); exit;
}

/* ---- カレンダー購読(.ics)。セッションを使わないので認証チェックより前に置く ---- */
if ($do === 'ics') {
    $capp = kv_app(isset($_GET['app']) ? preg_replace('/[^a-z0-9_]/', '', $_GET['app']) : '');
    $cuser = kv_cal_user(isset($_GET['u']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['u']) : '',
                         isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', $_GET['t']) : '');
    if (!$capp || !kv_cal_map($capp) || !$cuser || !kv_can($cuser, $capp, 'read')) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "このカレンダーは配信されていません\n";
        exit;
    }
    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $capp['key'] . '.ics"');
    header('Cache-Control: private, max-age=600');
    echo kv_ics_build($capp, $cuser);
    exit;
}

if (!$user) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do === 'login') {
        if (!kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            $err = '画面が古くなっています。もう一度お試しください';
        } else {
            list($ok, $res) = kv_password_login(
                isset($_POST['login_id']) ? $_POST['login_id'] : '',
                isset($_POST['password']) ? $_POST['password'] : '');
            if ($ok) { kv_login_as($res); header('Location: ' . $self); exit; }
            $err = $res;
        }
    }
    kv_login_screen($err);
    exit;
}

/* ============================================================
 * ここから先はログイン済み
 * ============================================================ */

$apps = kv_apps();
$app  = $key !== '' ? kv_app($key) : null;
if ($key !== '' && !$app) { kv_fail(404, 'そのアプリはありません'); }
if ($app && !kv_can($user, $app, 'read') && !kv_can($user, $app, 'create')) {
    kv_fail(403, 'このアプリを使う権限がありません');
}

/* ---- 書き込み(POST)は必ず PRG。リロードで二重登録させない ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app) {
    if (!kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        kv_fail(400, '画面が古くなっています。前の画面に戻ってやり直してください');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'delete') {
        list($ok, $res) = kv_record_delete($user, $app, isset($_POST['id']) ? $_POST['id'] : '');
        if (!$ok) { kv_fail(403, $res); }
        header('Location: ' . $self . '?app=' . $app['key'] . '&msg=deleted'); exit;
    }

    if ($action === 'status') {
        list($ok, $res) = kv_record_status($user, $app, isset($_POST['id']) ? $_POST['id'] : '',
                                           isset($_POST['to']) ? $_POST['to'] : '');
        if (!$ok) { kv_fail(403, $res); }
        header('Location: ' . $self . '?app=' . $app['key'] . '&msg=status'); exit;
    }

    if ($action === 'save') {
        list($values, $errors) = kv_validate($app, $_POST);
        $rid = isset($_POST['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_POST['id']) : '';
        if ($errors) {
            // 入力ミスは 400。500 で包むと外形監視が「サーバー障害」と誤判定する。
            http_response_code(400);
            kv_page_form($app, $user, $rid, $values, $errors);
            exit;
        }
        if ($rid !== '') { list($ok, $res) = kv_record_update($user, $app, $rid, $values); }
        else            { list($ok, $res) = kv_record_create($user, $app, $values); }
        if (!$ok) { kv_fail(403, $res); }
        header('Location: ' . $self . '?app=' . $app['key'] . '&msg=saved'); exit;
    }
    kv_fail(400, '不明な操作です');
}


/* ---- パスワードの変更（本人） ---- */
if ($do === 'password' || !empty($user['must_change'])) {
    if (kv_auth_provider() !== 'password') {
        if ($do === 'password') { kv_fail(404, 'この設置ではパスワードを使いません'); }
    } else {
        $perr = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do === 'password') {
            if (!kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
                kv_fail(400, '画面が古くなっています');
            }
            list($ok, $res) = kv_password_change($user,
                isset($_POST['current']) ? $_POST['current'] : '',
                isset($_POST['new1']) ? $_POST['new1'] : '',
                isset($_POST['new2']) ? $_POST['new2'] : '');
            if ($ok) { header('Location: ' . $self . '?msg=pwchanged'); exit; }
            http_response_code(400);
            $perr = $res;
        }
        if ($do === 'password' || !empty($user['must_change'])) {
            kv_page_password($user, $perr, !empty($user['must_change']));
            exit;
        }
    }
}

/* 拡張点(2) 追加機能の画面と操作。ログイン済み・パスワード変更待ちでない人だけがここへ来る。 */
if (function_exists('kvgw_dispatch')) { kvgw_dispatch($user); }

/* ---- 管理者：アカウント一覧（権限の棚卸し） ---- */
if ($do === 'users') {
    if ($user['role'] !== 'admin') { kv_fail(403, '管理者だけが開けます'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            kv_fail(400, '画面が古くなっています。前の画面に戻ってやり直してください');
        }
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $uid = isset($_POST['user_id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_POST['user_id']) : '';
        if ($action === 'acct_save') {
            list($ok2, $res2) = kv_user_edit($uid,
                isset($_POST['role']) ? $_POST['role'] : '', !empty($_POST['active']));
            if (!$ok2) { kv_fail(400, $res2); }
            header('Location: ' . $self . '?do=users&msg=saved'); exit;
        }
        if ($action === 'acct_delete') {
            list($ok2, $res2) = kv_account_delete($uid);
            if (!$ok2) { kv_fail(409, $res2); }
            header('Location: ' . $self . '?do=users&msg=acctdel'); exit;
        }
        kv_fail(400, '不明な操作です');
    }
    kv_page_users($user); exit;
}

/* ---- 管理者：社員マスタとアカウント ---- */
if ($do === 'admin') {
    if ($user['role'] !== 'admin') { kv_fail(403, '管理者だけが開けます'); }
    $emp = isset($_GET['emp']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['emp']) : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            kv_fail(400, '画面が古くなっています。前の画面に戻ってやり直してください');
        }
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $eid = isset($_POST['emp_id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_POST['emp_id']) : '';

        if ($action === 'emp_save') {
            list($values, $errors) = kv_employee_validate($_POST, $eid);
            if ($errors) {
                // 入力ミスは400。500で包むと外形監視がサーバー障害と誤判定する。
                http_response_code(400);
                kv_page_employee($user, $eid, $values, $errors);
                exit;
            }
            if ($eid !== '') { list($ok2, $res2) = kv_employee_update($eid, $values); }
            else             { list($ok2, $res2) = kv_employee_create($values); }
            if (!$ok2) { kv_fail(400, $res2); }
            header('Location: ' . $self . '?do=admin&emp=' . $res2['id'] . '&msg=saved'); exit;
        }
        if ($action === 'emp_delete') {
            list($ok2, $res2) = kv_employee_delete($eid);
            if (!$ok2) { kv_fail(409, $res2); }
            header('Location: ' . $self . '?do=admin&msg=deleted'); exit;
        }
        if ($action === 'acct_create') {
            list($ok2, $res2) = kv_account_create($eid,
                isset($_POST['login_id']) ? $_POST['login_id'] : '',
                isset($_POST['email']) ? $_POST['email'] : '',
                isset($_POST['role']) ? $_POST['role'] : 'staff');
            if ($ok2 && kv_auth_provider() === 'password') {
                // 作った直後に初期パスワードを発行し、1回だけ画面に出す
                $pw = kv_password_generate();
                kv_account_set_password($res2['id'], $pw, true);
                header('Location: ' . $self . '?do=admin&emp=' . $eid . '&msg=acct&pw='
                     . rawurlencode($pw)); exit;
            }
            if (!$ok2) { kv_fail(400, $res2); }
            header('Location: ' . $self . '?do=admin&emp=' . $eid . '&msg=acct'); exit;
        }
        if ($action === 'acct_save') {
            list($ok2, $res2) = kv_user_edit(
                isset($_POST['user_id']) ? $_POST['user_id'] : '',
                isset($_POST['role']) ? $_POST['role'] : '',
                !empty($_POST['active']));
            if (!$ok2) { kv_fail(400, $res2); }
            header('Location: ' . $self . '?do=admin&emp=' . $eid . '&msg=saved'); exit;
        }
        if ($action === 'acct_delete') {
            list($ok2, $res2) = kv_account_delete(isset($_POST['user_id']) ? $_POST['user_id'] : '');
            if (!$ok2) { kv_fail(409, $res2); }
            header('Location: ' . $self . '?do=admin&emp=' . $eid . '&msg=acctdel'); exit;
        }
        if ($action === 'acct_pw') {
            // 管理者は「今のパスワード」を知らないので、新しいものを発行して渡す。
            // 自動生成が既定。入社時に決めた仮パスワードを使いたい会社のために、
            // 指定もできるようにしてある。どちらも既定で「本人の変更待ち」にする。
            $manual = isset($_POST['pw_manual']) ? trim((string)$_POST['pw_manual']) : '';
            $pw = $manual !== '' ? $manual : kv_password_generate();
            $must = !empty($_POST['must_change']);
            list($ok2, $res2) = kv_account_set_password(
                isset($_POST['user_id']) ? $_POST['user_id'] : '', $pw, $must);
            if (!$ok2) { kv_fail(400, $res2); }
            header('Location: ' . $self . '?do=admin&emp=' . $eid . '&msg=pwissued&pw='
                 . rawurlencode($pw)); exit;
        }
        kv_fail(400, '不明な操作です');
    }

    if ($emp !== '') {
        if ($emp === 'new') { kv_page_employee($user, '', array(), array()); exit; }
        if (!kv_employee_find($emp)) { kv_fail(404, 'その社員は登録されていません'); }
        kv_page_employee($user, $emp, array(), array()); exit;
    }
    kv_page_admin($user); exit;
}

/* ---- カレンダー購読の案内 ---- */
if ($app && ($do === 'cal' || $do === 'calreset')) {
    if (!kv_cal_map($app)) { kv_fail(404, 'このアプリはカレンダー配信に対応していません'); }
    if ($do === 'calreset') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST'
            || !kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            kv_fail(400, '画面が古くなっています');
        }
        kv_cal_reset($user['id']);
        header('Location: ' . $self . '?app=' . $app['key'] . '&do=cal&msg=calreset'); exit;
    }
    kv_page_cal($app, $user); exit;
}

/* ---- CSV ---- */
if ($do === 'csv' && $app) {
    $rows = kv_sort($app, kv_search($app, kv_records($user, $app), $q));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $app['key'] . '_' . date('Ymd') . '.csv"');
    echo kv_csv($app, $rows);
    exit;
}

/* ---- 画面 ---- */
if ($app && ($do === 'new' || $do === 'edit')) {
    $values = array();
    if ($do === 'edit') {
        $r = kv_record_find($app, $id);
        if (!$r) { kv_fail(404, '記録が見つかりません'); }
        if (!kv_can($user, $app, 'update', $r)) { kv_fail(403, 'この記録を編集する権限がありません'); }
        $values = $r;
    } else if (!kv_can($user, $app, 'create')) {
        kv_fail(403, '作成する権限がありません');
    }
    kv_page_form($app, $user, $do === 'edit' ? $id : '', $values, array());
    exit;
}

if ($app) { kv_page_list($app, $user, $q); exit; }
kv_page_home($user);

/* ============================================================
 * 画面部品
 *
 * CSSはこのファイルの中に持つ。外部ファイルを増やさないのは、お客様が
 * FTPで上げるファイルを増やさないため(1つ上げ忘れると画面が崩れる)。
 * ============================================================ */

function kv_fail($code, $msg) {
    http_response_code($code);
    kv_head('エラー');
    echo '<main class="kv-wrap kv-narrow"><div class="kv-card kv-center">'
       . '<div class="kv-bigcode">' . (int)$code . '</div>'
       . '<p class="kv-lead">' . kv_h($msg) . '</p>'
       . '<p><a class="kv-btn" href="' . kv_h(basename(__FILE__)) . '">最初の画面に戻る</a></p>'
       . '</div></main>';
    kv_foot();
    exit;
}

function kv_css() {
    $accent = defined('KVGWC_ACCENT') ? KVGWC_ACCENT : '#3B5BDB';
    return ':root{--a:' . kv_h($accent) . ';--a-dark:#2B3F9E;--a-pale:#eef2fd;'
      . '--ink:#16202e;--muted:#5a6b7d;--line:#dde5ee;--bg:#f6f8fb;'
      . '--shadow:0 14px 40px rgba(20,35,60,.09);--shadow-sm:0 2px 10px rgba(20,35,60,.06);'
      . '--radius:20px}'
    . '@supports (color:color-mix(in srgb,red 50%,blue)){:root{'
      . '--a-dark:color-mix(in srgb,var(--a) 70%,#000);'
      . '--a-pale:color-mix(in srgb,var(--a) 7%,#fff)}}'
    . '*{box-sizing:border-box}'
    . 'body{margin:0;color:var(--ink);background:linear-gradient(180deg,var(--a-pale) 0,var(--bg) 340px);'
      . 'font-family:"Zen Kaku Gothic New","Noto Sans JP","Hiragino Sans",system-ui,sans-serif;'
      . 'line-height:1.75;font-size:15px;min-height:100vh;display:flex;flex-direction:column}'
    . 'a{color:var(--a-dark)}button,input,select,textarea{font:inherit}'
    . 'h1,h2,h3{font-family:"Zen Maru Gothic","Zen Kaku Gothic New","Hiragino Sans",sans-serif;'
      . 'font-weight:900;letter-spacing:-.02em;margin:0}'

    /* ---- 上部バー ---- */
    . '.kv-top{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:16px;flex-wrap:wrap;'
      . 'padding:0 max(18px,calc((100vw - 1120px)/2));min-height:72px;'
      . 'background:rgba(255,255,255,.88);backdrop-filter:blur(14px);'
      . '-webkit-backdrop-filter:blur(14px);border-bottom:1px solid rgba(214,226,238,.9)}'
    . '.kv-brand{display:flex;gap:12px;align-items:center;text-decoration:none;color:var(--ink)}'
    . '.kv-mark{display:grid;place-items:center;width:42px;height:42px;border-radius:14px;flex:0 0 auto;'
      . 'background:linear-gradient(135deg,var(--a),var(--a-dark));color:#fff;'
      . 'font:900 22px/1 "Zen Maru Gothic","Hiragino Sans",sans-serif;'
      . 'box-shadow:0 8px 22px rgba(40,60,150,.24)}'
    . '.kv-brandtext b{display:block;font:900 18px/1.3 "Zen Maru Gothic","Hiragino Sans",sans-serif}'
    . '.kv-brandtext small{display:block;color:var(--muted);font-size:10px;letter-spacing:.11em;font-weight:700}'
    . '.kv-tools{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap}'
    . '.kv-who{display:flex;align-items:center;gap:9px}'
    . '.kv-avatar{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;flex:0 0 auto;'
      . 'background:var(--a-pale);color:var(--a-dark);font-weight:800;font-size:14px;'
      . 'border:1.5px solid rgba(120,150,220,.28)}'
    . '.kv-who b{display:block;font-size:13px;line-height:1.35}'
    . '.kv-who small{display:block;color:var(--muted);font-size:11px;line-height:1.35}'
    . '.kv-link{font-size:12.5px;font-weight:700;color:var(--muted);text-decoration:none;'
      . 'padding:7px 13px;border-radius:999px;border:1.5px solid var(--line);background:#fff;white-space:nowrap}'
    . '.kv-link:hover{border-color:var(--a);color:var(--a-dark)}'

    /* ---- タブ ---- */
    . '.kv-nav{background:transparent;padding:14px max(18px,calc((100vw - 1120px)/2)) 0}'
    . '.kv-navinner{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px}'
    . '.kv-navinner a{display:inline-flex;align-items:center;gap:7px;padding:9px 17px;border-radius:999px;'
      . 'text-decoration:none;color:var(--muted);white-space:nowrap;font-weight:700;font-size:14px;'
      . 'background:#fff;border:1.5px solid var(--line);box-shadow:var(--shadow-sm)}'
    . '.kv-navinner a:hover{border-color:var(--a);color:var(--a-dark)}'
    . '.kv-navinner a.on{background:linear-gradient(135deg,var(--a),var(--a-dark));color:#fff;'
      . 'border-color:transparent;box-shadow:0 8px 20px rgba(40,60,150,.22)}'

    /* ---- 枠 ---- */
    . '.kv-wrap{width:100%;max-width:1120px;margin:0 auto;padding:22px max(18px,calc((100vw - 1120px)/2)) 48px;flex:1}'
    . '.kv-narrow{max-width:560px}.kv-center{text-align:center}'
    . '.kv-card{background:rgba(255,255,255,.95);border:1px solid var(--line);border-radius:var(--radius);'
      . 'box-shadow:var(--shadow);padding:26px 28px;margin-bottom:18px}'
    . '.kv-eyebrow{font-weight:800;font-size:11px;letter-spacing:.18em;color:var(--a-dark);margin:0 0 6px}'
    . '.kv-title{font-size:24px;display:flex;align-items:center;gap:11px;flex-wrap:wrap}'
    . '.kv-title .kv-emoji{font-size:26px}'
    . '.kv-lead{color:var(--muted);margin:6px 0 0}'
    . '.kv-bigcode{font:900 64px/1 "Zen Maru Gothic",sans-serif;color:var(--a);opacity:.35}'

    /* ---- 表 ---- */
    . '.kv-tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:#fff}'
    . 'table{border-collapse:collapse;width:100%;font-size:14px}'
    . 'th,td{padding:12px 14px;text-align:left;vertical-align:top;border-bottom:1px solid #eef2f7}'
    . 'th{background:var(--a-pale);font-weight:800;white-space:nowrap;font-size:12.5px;'
      . 'letter-spacing:.02em;color:var(--a-dark)}'
    . 'tr:last-child td{border-bottom:0}'
    . 'tbody tr:hover td{background:#fafcff}'

    /* ---- 入力 ---- */
    . '.kv-field{margin-bottom:17px}'
    . '.kv-field>label{display:block;font-weight:800;margin-bottom:6px;font-size:13.5px}'
    . 'input,select,textarea{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:11px;'
      . 'background:#fff;color:var(--ink);transition:border-color .15s,box-shadow .15s}'
    . 'input:focus,select:focus,textarea:focus{outline:0;border-color:var(--a);'
      . 'box-shadow:0 0 0 4px rgba(60,90,220,.13)}'
    . 'textarea{resize:vertical;line-height:1.7}'
    . '.kv-choices{display:flex;gap:10px;flex-wrap:wrap}'
    . '.kv-choice{display:inline-flex;align-items:center;gap:7px;margin:0;font-weight:600!important;'
      . 'padding:8px 14px;border:1.5px solid var(--line);border-radius:999px;background:#fff;cursor:pointer}'
    . '.kv-choice:hover{border-color:var(--a)}'
    . '.kv-choice input{width:auto;padding:0;accent-color:var(--a)}'
    . '.kv-req{background:#c0392b;color:#fff;font-size:10px;padding:2px 7px;border-radius:999px;'
      . 'margin-left:8px;font-weight:800;letter-spacing:.04em;vertical-align:middle}'
    . '.kv-hint{color:var(--muted);font-size:12.5px;margin:5px 0 0}'
    . '.kv-error{color:#c0392b;font-size:13px;margin:5px 0 0;font-weight:700}'

    /* ---- ボタン ---- */
    . '.kv-btn{display:inline-flex;align-items:center;gap:7px;border:0;cursor:pointer;text-decoration:none;'
      . 'padding:11px 22px;border-radius:999px;font-weight:800;font-size:14px;'
      . 'background:linear-gradient(135deg,var(--a),var(--a-dark));color:#fff;white-space:nowrap;'
      . 'box-shadow:0 8px 20px rgba(40,60,150,.22);transition:transform .12s,box-shadow .12s}'
    . '.kv-btn:hover{transform:translateY(-1px);box-shadow:0 11px 26px rgba(40,60,150,.28)}'
    . '.kv-btn.sub{background:#fff;color:var(--a-dark);border:1.5px solid var(--line);box-shadow:var(--shadow-sm)}'
    . '.kv-btn.sub:hover{border-color:var(--a)}'
    . '.kv-btn.danger{background:#fff;color:#c0392b;border:1.5px solid #f0c8c2;box-shadow:var(--shadow-sm)}'

    /* ---- 部品 ---- */
    . '.kv-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:16px 0 14px}'
    . '.kv-bar form{display:flex;gap:8px;flex:1;min-width:190px;margin:0}'
    . '.kv-msg{display:flex;align-items:center;gap:9px;background:#eafaf0;border:1.5px solid #b7e6c8;'
      . 'color:#1d6b3c;padding:12px 17px;border-radius:14px;margin-bottom:16px;font-size:14px;font-weight:700}'
    . '.kv-demo{background:linear-gradient(135deg,#fff6da,#fff2c9);border-bottom:1px solid #f0dda3;'
      . 'padding:9px 16px;font-size:12.5px;text-align:center;color:#7a5a06;font-weight:700}'
    . '.kv-tag{display:inline-block;font-size:11.5px;font-weight:800;padding:3px 11px;border-radius:999px;'
      . 'background:#eef2f7;color:#54637a;white-space:nowrap}'
    . '.kv-tag.submitted{background:#fff3df;color:#96590a}'
    . '.kv-tag.approved{background:#e6f8ec;color:#1d6b3c}'
    . '.kv-tag.rejected{background:#fdecea;color:#b3261e}'
    . '.kv-tag.draft{background:#eef2f7;color:#54637a}'
    . '.kv-badge{display:inline-block;margin-left:7px;font-size:10.5px;font-weight:800;'
      . 'padding:2px 9px;border-radius:999px;background:#fff1d6;color:#8a5200;'
      . 'border:1px solid #f0dda3;vertical-align:middle;letter-spacing:.02em}'
    . '.kv-navinner a.on .kv-badge{background:rgba(255,255,255,.9);border-color:transparent}'
    . '.kv-scope{display:inline-flex;align-items:center;gap:7px;background:var(--a-pale);color:var(--a-dark);'
      . 'font-size:12.5px;font-weight:700;padding:6px 14px;border-radius:999px}'
    . '.kv-empty{text-align:center;padding:52px 20px;color:var(--muted)}'
    . '.kv-empty .kv-emoji{font-size:44px;display:block;margin-bottom:10px;opacity:.55}'

    /* ---- アプリカード ---- */
    . '.kv-apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px}'
    . '.kv-appcard{display:block;text-decoration:none;color:inherit;padding:24px;border-radius:var(--radius);'
      . 'background:rgba(255,255,255,.95);border:1px solid var(--line);box-shadow:var(--shadow);'
      . 'transition:transform .14s,box-shadow .14s,border-color .14s}'
    . '.kv-appcard:hover{transform:translateY(-3px);border-color:var(--a);'
      . 'box-shadow:0 18px 44px rgba(20,35,60,.14)}'
    . '.kv-appcard .kv-emoji{font-size:30px;display:block}'
    . '.kv-appcard b{display:block;font:900 17px/1.4 "Zen Maru Gothic",sans-serif;margin:10px 0 2px}'
    . '.kv-appcard span{color:var(--muted);font-size:12.5px;font-weight:700}'

    /* ---- ログイン ---- */
    . '.kv-login{max-width:430px;margin:0 auto;padding:44px 18px 60px}'
    . '.kv-loginmark{display:grid;place-items:center;width:62px;height:62px;border-radius:20px;margin:0 auto 18px;'
      . 'background:linear-gradient(135deg,var(--a),var(--a-dark));color:#fff;'
      . 'font:900 32px/1 "Zen Maru Gothic",sans-serif;box-shadow:0 12px 30px rgba(40,60,150,.28)}'

    /* ---- 下部 ---- */
    . '.kv-foot{border-top:1px solid var(--line);background:rgba(255,255,255,.7);'
      . 'padding:22px max(18px,calc((100vw - 1120px)/2));font-size:12.5px;color:var(--muted)}'
    . '.kv-foot b{font-family:"Zen Maru Gothic",sans-serif;color:var(--ink)}'
    . '@media(max-width:620px){.kv-top{min-height:64px;padding-top:9px;padding-bottom:9px}'
      . '.kv-who small{display:none}.kv-card{padding:20px 17px}.kv-title{font-size:20px}}'
    . (function_exists('kvgw_css') ? kvgw_css() : '');   /* 拡張点(3) */
}

function kv_head($title, $user = null) {
    $site = defined('KVGWC_SITE_NAME') ? KVGWC_SITE_NAME : 'グループウェア';
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $self = basename(__FILE__);
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<meta name="generator" content="' . kv_h(KVGWC_PRODUCT) . ' ' . kv_h(KVGWC_VERSION) . '">'
       . '<title>' . kv_h($title) . '｜' . kv_h($site) . '</title>'
    /* 社内ネットワークで外部フォントが取れなくても、下の system-ui 系に落ちて崩れない */
       . '<link rel="preconnect" href="https://fonts.googleapis.com">'
       . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
       . 'family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap">'
       . '<style>' . kv_css() . '</style></head><body>';
    if (kv_is_demo()) {
        echo '<div class="kv-demo">デモ環境です。入力された内容は誰でも見られます。'
           . '個人情報・実データは入れないでください</div>';
    }
    echo '<header class="kv-top">'
       . '<a class="kv-brand" href="' . kv_h($self) . '">'
       . '<span class="kv-mark">K</span><span class="kv-brandtext">'
       . '<b>' . kv_h($site) . '</b><small>' . kv_h(KVGWC_PRODUCT) . '</small>'
       . '</span></a>';
    if ($user) {
        $initial = mb_substr($user['name'], 0, 1, 'UTF-8');
        echo '<div class="kv-tools"><span class="kv-who">'
           . '<span class="kv-avatar">' . kv_h($initial) . '</span><span>'
           . '<b>' . kv_h($user['name']) . '</b>'
           . '<small>' . kv_h(kv_dept_name($user['dept'])) . '・' . kv_h(kv_roles_label($user['role'])) . '</small>'
           . '</span></span>';
        if (function_exists('kvgw_tools')) { echo kvgw_tools($user); }   /* 拡張点(7) */
        if ($user['role'] === 'admin') {
            echo '<a class="kv-link" href="' . kv_h($self) . '?do=admin">社員マスタ</a>'
               . '<a class="kv-link" href="' . kv_h($self) . '?do=users">アカウント</a>';
        }
        if (kv_auth_provider() === 'password') {
            echo '<a class="kv-link" href="' . kv_h($self) . '?do=password">パスワード</a>';
        }
        echo '<a class="kv-link" href="' . kv_h($self) . '?do=logout">ログアウト</a></div>';
    }
    echo '</header>';
}

function kv_roles_label($r) { $m = kv_roles(); return isset($m[$r]) ? $m[$r] : $r; }

function kv_nav($user, $current) {
    $self = basename(__FILE__);
    $items = '';
    foreach (kv_apps() as $a) {
        if (!kv_can($user, $a, 'read') && !kv_can($user, $a, 'create')) { continue; }
        $on = ($current === $a['key']) ? ' class="on"' : '';
        $items .= '<a href="' . kv_h($self) . '?app=' . kv_h($a['key']) . '"' . $on . '>'
                . '<span class="kv-emoji">' . kv_h($a['icon']) . '</span>' . kv_h($a['name'])
                . ($a['badge'] !== '' ? '<span class="kv-badge">' . kv_h($a['badge']) . '</span>' : '')
                . '</a>';
    }
    if (function_exists('kvgw_nav')) { $items = kvgw_nav($user, $current) . $items; }   /* 拡張点(4) */
    if ($items === '') { return; }
    echo '<nav class="kv-nav"><div class="kv-navinner">' . $items . '</div></nav>';
}

function kv_foot() {
    if (function_exists('kvgw_foot')) { kvgw_foot(); return; }   /* 拡張点(6) */
    echo '<footer class="kv-foot"><b>' . kv_h(KVGWC_PRODUCT) . '</b> '
       . kv_h(KVGWC_VERSION) . '　—　拡張可能なグループウェアCore</footer></body></html>';
}

function kv_msg() {
    if (empty($_GET['msg'])) { return; }
    $m = array('saved' => '保存しました', 'deleted' => '削除しました', 'status' => '状態を更新しました',
               'acct' => 'アカウントを作成しました', 'acctdel' => 'アカウントを削除しました',
               'calreset' => '購読URLを作り直しました',
               'pwchanged' => 'パスワードを変更しました', 'pwissued' => 'パスワードを発行しました');
    $k = (string)$_GET['msg'];
    if (isset($m[$k])) { echo '<div class="kv-msg"><span>✓</span>' . kv_h($m[$k]) . '</div>'; }
}

/* ---- ログイン画面 ---- */
function kv_login_screen($err) {
    $self = basename(__FILE__);
    $provider = kv_auth_provider();
    $site = defined('KVGWC_SITE_NAME') ? KVGWC_SITE_NAME : 'グループウェア';
    kv_head('ログイン');
    echo '<main class="kv-login"><div class="kv-center" style="margin-bottom:22px">'
       . '<div class="kv-loginmark">K</div>'
       . '<h1 style="font-size:22px">' . kv_h($site) . '</h1>'
       . '<p class="kv-lead" style="font-size:12px;letter-spacing:.1em;font-weight:700">'
       . kv_h(KVGWC_PRODUCT) . '</p></div>';
    echo '<div class="kv-card">';
    if ($err !== '') { echo '<p class="kv-error">' . kv_h($err) . '</p>'; }
    if ($provider === 'password') {
        echo '<form method="post" action="' . kv_h($self) . '?do=login">'
           . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
           . '<div class="kv-field"><label for="lid">社員番号</label>'
           . '<input type="text" name="login_id" id="lid" required autofocus '
           . 'autocomplete="username" inputmode="latin"></div>'
           . '<div class="kv-field"><label for="pw">パスワード</label>'
           . '<input type="password" name="password" id="pw" required '
           . 'autocomplete="current-password"></div>'
           . '<button class="kv-btn" type="submit" style="width:100%;justify-content:center">'
           . 'ログイン</button></form>'
           . '<p class="kv-hint" style="margin-top:14px">'
           . 'パスワードが分からないときは、社内の管理者に再発行を依頼してください。</p>';
    } else {
        $c = kv_oidc_conf();
        echo '<p class="kv-lead" style="margin:0 0 18px">'
           . kv_h($c['label']) . ' のアカウントでログインします。</p>'
           . '<a class="kv-btn" style="width:100%;justify-content:center" href="'
           . kv_h($self) . '?do=oauth">' . kv_h($c['label']) . ' でログイン</a>';
        if (defined('KVGWC_HD') && KVGWC_HD !== '') {
            echo '<p class="kv-hint" style="margin-top:14px">@' . kv_h(KVGWC_HD)
               . ' のアカウントだけが利用できます</p>';
        }
    }
    echo '</div></main>';
    kv_foot();
}

/* ---- ホーム(アプリ一覧) ---- */
function kv_page_home($user) {
    $self = basename(__FILE__);
    kv_head('ホーム', $user);
    kv_nav($user, '');
    echo '<main class="kv-wrap">';
    kv_msg();
    echo '<p class="kv-eyebrow">WORKSPACE</p>';
    echo '<h1 class="kv-title" style="margin-bottom:18px">'
       . kv_h($user['name']) . 'さん、おつかれさまです</h1>';
    if (function_exists('kvgw_home')) { kvgw_home($user); }   /* 拡張点(5) */
    $cards = '';
    foreach (kv_apps() as $a) {
        if (!kv_can($user, $a, 'read') && !kv_can($user, $a, 'create')) { continue; }
        $cnt = count(kv_records($user, $a));
        $cards .= '<a class="kv-appcard" href="' . kv_h($self) . '?app=' . kv_h($a['key']) . '">'
                . '<span class="kv-emoji">' . kv_h($a['icon']) . '</span>'
                . '<b>' . kv_h($a['name']) . '</b><span>' . $cnt . '件'
                . ($a['badge'] !== '' ? '　<span class="kv-badge">' . kv_h($a['badge']) . '</span>' : '')
                . '</span></a>';
    }
    if ($cards === '') {
        echo '<div class="kv-card"><div class="kv-empty"><span class="kv-emoji">🔒</span>'
           . '使えるアプリがありません。管理者に権限を確認してください。</div></div>';
    } else {
        echo '<div class="kv-apps">' . $cards . '</div>';
    }
    echo '</main>';
    kv_foot();
}

/* ---- 一覧 ---- */
function kv_page_list($app, $user, $q) {
    $self = basename(__FILE__);
    $rows = kv_sort($app, kv_search($app, kv_records($user, $app), $q));
    $cols = kv_list_columns($app);
    kv_head($app['name'], $user);
    kv_nav($user, $app['key']);
    echo '<main class="kv-wrap"><div class="kv-card">';
    kv_msg();
    echo '<h1 class="kv-title"><span class="kv-emoji">' . kv_h($app['icon']) . '</span>'
       . kv_h($app['name'])
       . ($app['badge'] !== '' ? '<span class="kv-badge">' . kv_h($app['badge']) . '</span>' : '')
       . '</h1>';
    if (!empty($app['badge_note'])) {
        echo '<p class="kv-lead">' . kv_h($app['badge_note']) . '</p>';
    }

    echo '<div class="kv-bar">';
    if (kv_can($user, $app, 'create')) {
        echo '<a class="kv-btn" href="' . kv_h($self) . '?app=' . kv_h($app['key'])
           . '&amp;do=new"><span>＋</span>新規作成</a>';
    }
    echo '<form method="get"><input type="hidden" name="app" value="' . kv_h($app['key']) . '">'
       . '<input type="search" name="q" value="' . kv_h($q) . '" placeholder="絞り込み">'
       . '<button class="kv-btn sub" type="submit">検索</button></form>';
    echo '<a class="kv-btn sub" href="' . kv_h($self) . '?app=' . kv_h($app['key'])
       . '&amp;do=csv&amp;q=' . rawurlencode($q) . '">CSV</a>';
    if (!empty($app['caldav'])) {
        echo '<a class="kv-btn sub" href="' . kv_h($self) . '?app=' . kv_h($app['key'])
           . '&amp;do=cal">スマホに登録</a>';
    }
    echo '</div>';

    $scope = kv_scope($user, $app, 'read');
    $note = array('own' => '自分の記録だけが表示されています',
                  'dept' => '同じ部署の記録が表示されています',
                  'all' => '全員の記録が表示されています');
    echo '<p style="margin:0 0 16px"><span class="kv-scope">👁 '
       . kv_h(isset($note[$scope]) ? $note[$scope] : '') . '</span>'
       . '<span class="kv-hint" style="display:inline;margin-left:10px">' . count($rows) . '件</span></p>';

    if (!$rows) {
        // 作成できない人に「新規作成から」と案内しない(押せるボタンが無い)
        if ($q !== '') {
            $empty = '「' . kv_h($q) . '」に一致する記録はありません。';
        } else if (kv_can($user, $app, 'create')) {
            $empty = 'まだ記録がありません。「新規作成」から始めてください。';
        } else {
            $empty = 'まだ何も登録されていません。';
        }
        echo '<div class="kv-empty"><span class="kv-emoji">' . kv_h($app['icon']) . '</span>'
           . $empty . '</div>';
    } else {
        echo '<div class="kv-tablewrap"><table><thead><tr>';
        foreach ($cols as $c) { echo '<th>' . kv_h(kv_column_label($app, $c)) . '</th>'; }
        echo '<th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($cols as $c) {
                $v = kv_display($app, $r, $c);
                if ($c === '_status') {
                    $s = isset($r['_status']) ? $r['_status'] : '';
                    echo '<td><span class="kv-tag ' . kv_h($s) . '">' . kv_h($v) . '</span></td>';
                } else {
                    if (mb_strlen($v, 'UTF-8') > 40) { $v = mb_substr($v, 0, 40, 'UTF-8') . '…'; }
                    echo '<td>' . nl2br(kv_h($v)) . '</td>';
                }
            }
            echo '<td style="white-space:nowrap;text-align:right">';
            if (kv_can($user, $app, 'update', $r)) {
                echo '<a href="' . kv_h($self) . '?app=' . kv_h($app['key']) . '&amp;do=edit&amp;id='
                   . kv_h($r['_id']) . '" style="font-weight:700;text-decoration:none">編集 →</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></main>';
    kv_foot();
}

/* ---- 作成・編集 ---- */
function kv_page_form($app, $user, $rid, $values, $errors) {
    $self = basename(__FILE__);
    $rec = $rid !== '' ? kv_record_find($app, $rid) : null;
    kv_head(($rid === '' ? '新規' : '編集') . '｜' . $app['name'], $user);
    kv_nav($user, $app['key']);
    echo '<main class="kv-wrap kv-narrow" style="max-width:680px"><div class="kv-card">';
    echo '<p class="kv-eyebrow">' . kv_h($app['name']) . '</p>';
    echo '<h1 class="kv-title" style="margin-bottom:20px"><span class="kv-emoji">'
       . kv_h($app['icon']) . '</span>' . ($rid === '' ? '新規作成' : '編集') . '</h1>';
    if ($errors) { echo '<p class="kv-error">入力に誤りがあります。赤字の項目を直してください。</p>'; }

    echo '<form method="post" action="' . kv_h($self) . '?app=' . kv_h($app['key']) . '">'
       . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
       . '<input type="hidden" name="action" value="save">'
       . '<input type="hidden" name="id" value="' . kv_h($rid) . '">';
    echo kv_form_fields($app, $values, $errors);
    echo '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">保存</button>'
       . '<a class="kv-btn sub" href="' . kv_h($self) . '?app=' . kv_h($app['key'])
       . '">やめる</a></div></form>';

    if ($rec) {
        echo '</div>';
        echo '<div class="kv-card">';
        echo '<p class="kv-hint" style="margin:0 0 14px">作成: ' . kv_h($rec['_owner_name']) . '（'
           . kv_h(kv_dept_name($rec['_dept'])) . '）' . kv_h($rec['_created'])
           . '　／　更新: ' . kv_h($rec['_updated']) . '</p>';

        if ($app['approval']) {
            $st = isset($rec['_status']) ? $rec['_status'] : 'draft';
            echo '<p style="margin:0 0 12px">状態　<span class="kv-tag ' . kv_h($st) . '">'
               . kv_h(kv_status_label($st)) . '</span></p>';
            echo '<div class="kv-bar" style="margin:0">';
            if (($st === 'draft' || $st === 'rejected') && kv_can($user, $app, 'update', $rec)) {
                kv_status_button($app, $rid, 'submitted', '申請する', '');
            }
            if ($st === 'submitted' && kv_can($user, $app, 'approve', $rec)) {
                kv_status_button($app, $rid, 'approved', '承認する', '');
                kv_status_button($app, $rid, 'rejected', '差し戻す', 'sub');
            }
            echo '</div>';
        }
        if (kv_can($user, $app, 'delete', $rec)) {
            echo '<form method="post" action="' . kv_h($self) . '?app=' . kv_h($app['key']) . '" '
               . 'style="margin-top:16px" '
               . 'onsubmit="return confirm(\'この記録を削除します。よろしいですか\')">'
               . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
               . '<input type="hidden" name="action" value="delete">'
               . '<input type="hidden" name="id" value="' . kv_h($rid) . '">'
               . '<button class="kv-btn danger" type="submit">削除</button></form>';
        }
    }
    echo '</div></main>';
    kv_foot();
}

function kv_status_button($app, $rid, $to, $label, $cls) {
    $self = basename(__FILE__);
    echo '<form method="post" action="' . kv_h($self) . '?app=' . kv_h($app['key']) . '" style="margin:0">'
       . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
       . '<input type="hidden" name="action" value="status">'
       . '<input type="hidden" name="id" value="' . kv_h($rid) . '">'
       . '<input type="hidden" name="to" value="' . kv_h($to) . '">'
       . '<button class="kv-btn ' . kv_h($cls) . '" type="submit">' . kv_h($label) . '</button></form>';
}

/* ---- カレンダー購読の案内 ---- */
function kv_page_cal($app, $user) {
    $self = basename(__FILE__);
    $token = kv_cal_token($user['id']);
    $url = kv_base_url() . '/' . $self . '?do=ics&app=' . rawurlencode($app['key'])
         . '&u=' . rawurlencode($user['id']) . '&t=' . rawurlencode($token);
    // webcal: にすると、iPhoneでは開いた瞬間に購読の確認が出る
    $webcal = preg_replace('#^https?://#', 'webcal://', $url);

    kv_head('スマホに登録｜' . $app['name'], $user);
    kv_nav($user, $app['key']);
    echo '<main class="kv-wrap kv-narrow" style="max-width:720px"><div class="kv-card">';
    kv_msg();
    echo '<p class="kv-eyebrow">CALENDAR</p>';
    echo '<h1 class="kv-title"><span class="kv-emoji">📱</span>スマホのカレンダーで見る</h1>';
    echo '<p class="kv-lead">下のボタンから登録すると、<b>iPhone・Androidの標準カレンダー</b>に'
       . kv_h($app['name']) . 'が表示されます。以後は自動で更新されます（専用アプリは不要です）。</p>';

    echo '<div class="kv-bar" style="margin:22px 0 8px">'
       . '<a class="kv-btn" href="' . kv_h($webcal) . '">カレンダーに登録する</a>'
       . '<a class="kv-btn sub" href="' . kv_h($url) . '">.ics をダウンロード</a></div>';

    echo '<div class="kv-field" style="margin-top:20px">'
       . '<label for="calurl">購読URL（手動で登録する場合）</label>'
       . '<input type="text" id="calurl" readonly onclick="this.select()" value="' . kv_h($url) . '">'
       . '<p class="kv-hint">iPhone: 設定 → アプリ → カレンダー → アカウント → '
       . 'アカウントを追加 → その他 → 照会するカレンダーを追加<br>'
       . 'Google カレンダー: 他のカレンダー → URLで追加</p></div>';

    echo '<div class="kv-msg" style="background:#fff6da;border-color:#f0dda3;color:#7a5a06">'
       . '<span>⚠</span>このURLには合言葉が入っています。他の人に渡すと、'
       . 'あなたが見られる予定がそのまま見られます。</div>';

    $scope = kv_scope($user, $app, 'read');
    $note = array('own' => '自分の記録だけ', 'dept' => '同じ部署の記録', 'all' => '全員の記録');
    echo '<p style="margin:0 0 18px"><span class="kv-scope">👁 このURLで配信されるのは'
       . kv_h(isset($note[$scope]) ? $note[$scope] : '') . 'です</span></p>';

    echo '<form method="post" action="' . kv_h($self) . '?app=' . kv_h($app['key']) . '&amp;do=calreset" '
       . 'onsubmit="return confirm(\'今のURLは使えなくなります。よろしいですか\')">'
       . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
       . '<button class="kv-btn danger" type="submit">URLを作り直す（今のURLを無効にする）</button></form>';
    echo '</div></main>';
    kv_foot();
}

/* ============================================================
 * 管理者：社員マスタ
 *
 * 画面も「社員(人事)」と「アカウント(ログイン)」を分けて出す。
 * 同じ枠に混ぜると、退職処理とアカウント停止の区別が付かなくなる。
 * ============================================================ */

function kv_page_admin($user) {
    $self = basename(__FILE__);
    $emps = kv_employees();
    // 在籍→休職→退職の順、その中は社員番号・氏名カナ順
    usort($emps, function ($a, $b) {
        $ord = array('active' => 0, 'leave' => 1, 'retired' => 2);
        $oa = isset($ord[$a['status']]) ? $ord[$a['status']] : 3;
        $ob = isset($ord[$b['status']]) ? $ord[$b['status']] : 3;
        if ($oa !== $ob) { return $oa < $ob ? -1 : 1; }
        $ka = ($a['no'] !== '' ? '0' . $a['no'] : '1' . (isset($a['kana']) ? $a['kana'] : $a['name']));
        $kb = ($b['no'] !== '' ? '0' . $b['no'] : '1' . (isset($b['kana']) ? $b['kana'] : $b['name']));
        return strcmp($ka, $kb);
    });

    kv_head('社員マスタ', $user);
    kv_nav($user, '');
    echo '<main class="kv-wrap"><div class="kv-card">';
    kv_msg();
    echo '<p class="kv-eyebrow">ADMIN</p>';
    echo '<h1 class="kv-title"><span class="kv-emoji">👥</span>社員マスタ</h1>';
    echo '<p class="kv-lead">社員（人事の情報）と、アカウント（ログインする権利）は別々に管理します。'
       . '<b>アカウントを持たない社員も登録できます。</b></p>';

    echo '<div class="kv-bar">'
       . '<a class="kv-btn" href="' . kv_h($self) . '?do=admin&amp;emp=new"><span>＋</span>社員を登録</a>'
       . '</div>';

    if (!$emps) {
        echo '<div class="kv-empty"><span class="kv-emoji">👥</span>'
           . 'まだ社員が登録されていません。「社員を登録」から始めてください。<br>'
           . 'Google Workspaceでログインした人は、ここに自動で追加されます。</div>';
    } else {
        echo '<div class="kv-tablewrap"><table><thead><tr>'
           . '<th>社員番号</th><th>氏名</th><th>部署</th><th>役職</th>'
           . '<th>在籍</th><th>アカウント</th><th>役割</th><th></th></tr></thead><tbody>';
        foreach ($emps as $e) {
            $acct = kv_user_by_employee($e['id']);
            $dim = ($e['status'] !== 'active') ? ' style="opacity:.55"' : '';
            echo '<tr' . $dim . '>';
            echo '<td class="kv-hint">' . kv_h($e['no']) . '</td>';
            echo '<td style="font-weight:700">' . kv_h($e['name'])
               . (!empty($e['kana']) ? '<br><span class="kv-hint">' . kv_h($e['kana']) . '</span>' : '')
               . '</td>';
            echo '<td>' . kv_h(kv_dept_name($e['dept'])) . '</td>';
            echo '<td>' . kv_h($e['title']) . '</td>';
            $sc = $e['status'] === 'active' ? 'approved' : ($e['status'] === 'leave' ? 'submitted' : 'draft');
            echo '<td><span class="kv-tag ' . $sc . '">' . kv_h(kv_emp_status_label($e['status'])) . '</span></td>';
            if (!$acct) {
                echo '<td class="kv-hint">なし</td><td></td>';
            } else {
                $on = !empty($acct['active']);
                echo '<td><span class="kv-tag ' . ($on ? 'approved' : 'rejected') . '">'
                   . ($on ? '有効' : '停止') . '</span><br><span class="kv-hint">'
                   . kv_h($acct['email']) . '</span></td>';
                echo '<td>' . kv_h(kv_roles_label($acct['role'])) . '</td>';
            }
            echo '<td style="text-align:right;white-space:nowrap">'
               . '<a href="' . kv_h($self) . '?do=admin&amp;emp=' . kv_h($e['id']) . '" '
               . 'style="font-weight:700;text-decoration:none">開く →</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="kv-hint" style="margin-top:14px">在籍状態を「退職」にすると、'
           . 'アカウントが有効のままでも<b>ログインできなくなります</b>。'
           . '記録は残るので、過去の日報の作成者が消えることはありません。</p>';
    }
    echo '</div></main>';
    kv_foot();
}

/* ---- 社員1人の画面（人事情報 ＋ アカウント） ---- */
function kv_page_employee($user, $eid, $values, $errors) {
    $self = basename(__FILE__);
    $emp = $eid !== '' ? kv_employee_find($eid) : null;
    if ($emp && !$values) { $values = $emp; }
    if (!$values) {
        $values = array('name' => '', 'kana' => '', 'no' => '', 'dept' => key(kv_depts()),
                        'title' => '', 'email' => '', 'tel' => '', 'mobile' => '',
                        'joined' => '', 'left' => '', 'status' => 'active', 'note' => '');
    }
    $acct = $emp ? kv_user_by_employee($emp['id']) : null;

    kv_head(($emp ? $values['name'] : '社員の登録') . '｜社員マスタ', $user);
    kv_nav($user, '');
    echo '<main class="kv-wrap kv-narrow" style="max-width:720px">';
    kv_msg();

    // 発行したパスワードは、この1回しか表示しない(台帳にはハッシュしか残らない)
    if (!empty($_GET['pw'])) {
        echo '<div class="kv-card" style="border-color:#f0dda3;background:#fffdf5">'
           . '<p class="kv-eyebrow" style="color:#96590a">発行したパスワード（この画面にしか出ません）</p>'
           . '<p style="font:800 26px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;'
           . 'letter-spacing:.06em;margin:6px 0 10px;user-select:all">'
           . kv_h((string)$_GET['pw']) . '</p>'
           . '<p class="kv-hint">本人に伝えてください。<b>最初のログインで本人が変更します。</b>'
           . 'この画面を離れると二度と表示されません（忘れたら再発行してください）。</p></div>';
    }

    /* --- 人事の情報 --- */
    echo '<div class="kv-card">';
    echo '<p class="kv-eyebrow">社員（人事の情報）</p>';
    echo '<h1 class="kv-title" style="margin-bottom:18px"><span class="kv-emoji">🧑‍💼</span>'
       . ($emp ? kv_h($values['name']) : '社員を登録') . '</h1>';
    if ($errors) { echo '<p class="kv-error">入力に誤りがあります。赤字の項目を直してください。</p>'; }

    echo '<form method="post" action="' . kv_h($self) . '?do=admin">'
       . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
       . '<input type="hidden" name="action" value="emp_save">'
       . '<input type="hidden" name="emp_id" value="' . kv_h($eid) . '">';

    kv_ef('no', '社員番号', 'text', $values, $errors, '英数字・ハイフン。空でも登録できます');
    kv_ef('name', '氏名', 'text', $values, $errors, '', true);
    kv_ef('kana', '氏名カナ', 'text', $values, $errors, '一覧の並び順に使います');

    echo '<div class="kv-field"><label for="f_dept">部署<span class="kv-req">必須</span></label>'
       . '<select name="dept" id="f_dept" required>';
    foreach (kv_depts() as $k => $n) {
        echo '<option value="' . kv_h($k) . '"' . ($values['dept'] === $k ? ' selected' : '') . '>'
           . kv_h($n) . '</option>';
    }
    echo '</select>';
    if (isset($errors['dept'])) { echo '<p class="kv-error">' . kv_h($errors['dept']) . '</p>'; }
    echo '<p class="kv-hint">部署は社員が持ちます。記録の公開範囲（同じ部署だけ見える等）'
       . 'はここで決まります。</p></div>';

    kv_ef('title', '役職', 'text', $values, $errors, '例) 課長・主任');
    kv_ef('email', 'メールアドレス（会社）', 'email', $values, $errors,
          'Google Workspaceでログインする場合、このアドレスで社員と結び付きます');
    kv_ef('tel', '内線・電話', 'tel', $values, $errors);
    kv_ef('mobile', '携帯', 'tel', $values, $errors);
    kv_ef('joined', '入社日', 'date', $values, $errors);
    kv_ef('left', '退職日', 'date', $values, $errors);

    echo '<div class="kv-field"><label for="f_status">在籍状態<span class="kv-req">必須</span></label>'
       . '<select name="status" id="f_status" required>';
    foreach (kv_emp_statuses() as $k => $n) {
        echo '<option value="' . kv_h($k) . '"' . ($values['status'] === $k ? ' selected' : '') . '>'
           . kv_h($n) . '</option>';
    }
    echo '</select>';
    if (isset($errors['status'])) { echo '<p class="kv-error">' . kv_h($errors['status']) . '</p>'; }
    echo '<p class="kv-hint"><b>「在籍」以外にすると、その人はログインできなくなります。</b>'
       . '退職者のアカウントを消し忘れても締め出せるようにしてあります。</p></div>';

    echo '<div class="kv-field"><label for="f_note">備考</label>'
       . '<textarea name="note" id="f_note" rows="3">' . kv_h($values['note']) . '</textarea>';
    if (isset($errors['note'])) { echo '<p class="kv-error">' . kv_h($errors['note']) . '</p>'; }
    echo '</div>';

    echo '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">保存</button>'
       . '<a class="kv-btn sub" href="' . kv_h($self) . '?do=admin">一覧に戻る</a></div></form>';
    echo '</div>';

    if (!$emp) { echo '</main>'; kv_foot(); return; }

    /* --- アカウント --- */
    echo '<div class="kv-card">';
    echo '<p class="kv-eyebrow">アカウント（ログインする権利）</p>';
    echo '<h2 style="font-size:18px;margin-bottom:6px">ログインの設定</h2>';

    if (!$acct) {
        echo '<p class="kv-lead">この社員はまだログインできません。'
           . 'アカウントを作ると、上のメールアドレスでログインできるようになります。</p>';
        echo '<form method="post" action="' . kv_h($self) . '?do=admin" style="margin-top:16px">'
           . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
           . '<input type="hidden" name="action" value="acct_create">'
           . '<input type="hidden" name="emp_id" value="' . kv_h($emp['id']) . '">';
        echo '<div class="kv-field"><label for="a_lid">ログインID（社員番号）'
           . '<span class="kv-req">必須</span></label>'
           . '<input type="text" name="login_id" id="a_lid" required value="'
           . kv_h($emp['no']) . '">'
           . '<p class="kv-hint">この人が毎日入力するIDです。社員番号をそのまま使うのが分かりやすい。</p></div>';
        echo '<div class="kv-field"><label for="a_mail">メールアドレス</label>'
           . '<input type="email" name="email" id="a_mail" value="' . kv_h($emp['email']) . '">'
           . '<p class="kv-hint">Google Workspace方式では本人の特定に使います。'
           . 'パスワード方式でも、このアドレスでログインできます。</p></div>';
        echo '<div class="kv-field"><label for="a_role">役割</label><select name="role" id="a_role">';
        foreach (kv_roles() as $k => $n) {
            echo '<option value="' . kv_h($k) . '"' . ($k === 'staff' ? ' selected' : '') . '>'
               . kv_h($n) . '</option>';
        }
        echo '</select><p class="kv-hint">担当＝自分の記録だけ／責任者＝部署ぶんと承認／'
           . '管理者＝全社と社員マスタ</p></div>';
        echo '<button class="kv-btn" type="submit">アカウントを作る</button></form>';
    } else {
        echo '<form method="post" action="' . kv_h($self) . '?do=admin">'
           . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
           . '<input type="hidden" name="action" value="acct_save">'
           . '<input type="hidden" name="emp_id" value="' . kv_h($emp['id']) . '">'
           . '<input type="hidden" name="user_id" value="' . kv_h($acct['id']) . '">';
        echo '<div class="kv-field"><label>ログインID</label>'
           . '<input type="text" value="'
           . kv_h($acct['login_id'] !== '' ? $acct['login_id'] : $acct['email']) . '" readonly>'
           . '<p class="kv-hint">'
           . ($acct['email'] !== '' ? 'メールアドレス: ' . kv_h($acct['email']) . '　' : '')
           . '認証方式: ' . kv_h($acct['provider']) . '<br>'
           . 'IDを変えるには、いったんアカウントを削除して作り直してください'
           . '（社員の情報と過去の記録は残ります）。</p></div>';
        echo '<div class="kv-field"><label for="a_role2">役割</label>'
           . '<select name="role" id="a_role2">';
        foreach (kv_roles() as $k => $n) {
            echo '<option value="' . kv_h($k) . '"' . ($acct['role'] === $k ? ' selected' : '') . '>'
               . kv_h($n) . '</option>';
        }
        echo '</select></div>';
        // active は台帳の値をそのまま出す(合成後の値だと退職者が常に「停止」に見えてしまう)
        $raw = null;
        foreach (kv_users() as $u) { if ($u['id'] === $acct['id']) { $raw = $u; } }
        $rawActive = $raw && !empty($raw['active']);
        echo '<div class="kv-field"><label class="kv-choice" style="font-weight:800!important">'
           . '<input type="checkbox" name="active" value="1"' . ($rawActive ? ' checked' : '') . '>'
           . 'このアカウントを有効にする</label>';
        if ($emp['status'] !== 'active') {
            echo '<p class="kv-hint" style="color:#96590a"><b>在籍状態が「'
               . kv_h(kv_emp_status_label($emp['status'])) . '」なので、'
               . 'アカウントが有効でもログインできません。</b></p>';
        }
        echo '</div>';
        echo '<p class="kv-hint">最終ログイン: '
           . kv_h($acct['last_login'] !== '' ? $acct['last_login'] : '未ログイン') . '</p>';
        echo '<div class="kv-bar"><button class="kv-btn" type="submit">保存</button></div></form>';

        if (kv_auth_provider() === 'password') {
            echo '<hr style="border:0;border-top:1px solid #e6ebf0;margin:20px 0">';
            echo '<h3 style="font-size:15px;margin:0 0 6px">パスワード</h3>';
            $pset = isset($acct['password_set']) ? $acct['password_set'] : '';
            $boot = kv_is_bootstrap_admin($acct);
            echo '<p class="kv-hint">'
               . ($boot ? '<b>設定ファイルの初期管理者です。</b>'
                        . 'パスワードは kvgwc_config.php の KVGWC_PASSWORD_HASH にあります。'
                  : ($pset === '' ? '<b>まだ発行されていません。</b>この人はログインできません。'
                                  : '最後に設定: ' . kv_h($pset)))
               . (!empty($acct['must_change']) ? '　<span class="kv-tag submitted">本人の変更待ち</span>' : '')
               . '<br>管理者は今のパスワードを見られません。忘れた場合は再発行してください。</p>';
            echo '<form method="post" action="' . kv_h($self) . '?do=admin" '
               . 'onsubmit="return confirm(\'新しいパスワードを発行します。今のパスワードは使えなくなります。よろしいですか\')">'
               . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
               . '<input type="hidden" name="action" value="acct_pw">'
               . '<input type="hidden" name="emp_id" value="' . kv_h($emp['id']) . '">'
               . '<input type="hidden" name="user_id" value="' . kv_h($acct['id']) . '">'
               . '<div class="kv-field"><label for="pwm">パスワードを指定する（空なら自動生成）</label>'
               . '<input type="text" name="pw_manual" id="pwm" autocomplete="off" '
               . 'placeholder="空のままなら読み上げやすいものを自動で作ります">'
               . '<p class="kv-hint">8文字以上・数字だけは不可</p></div>'
               . '<label class="kv-choice" style="margin-bottom:12px">'
               . '<input type="checkbox" name="must_change" value="1" checked>'
               . '最初のログインで本人に変更させる</label><br>'
               . '<button class="kv-btn sub" type="submit">'
               . ($pset === '' ? 'パスワードを発行する' : 'パスワードを再発行する') . '</button></form>';
        }

        echo '<form method="post" action="' . kv_h($self) . '?do=admin" style="margin-top:8px" '
           . 'onsubmit="return confirm(\'このアカウントを削除します。社員の情報と過去の記録は残ります。よろしいですか\')">'
           . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
           . '<input type="hidden" name="action" value="acct_delete">'
           . '<input type="hidden" name="emp_id" value="' . kv_h($emp['id']) . '">'
           . '<input type="hidden" name="user_id" value="' . kv_h($acct['id']) . '">'
           . '<button class="kv-btn danger" type="submit">アカウントを削除（社員は残す）</button></form>';
    }
    echo '</div>';

    /* --- 社員の削除 --- */
    $n = kv_employee_record_count($emp['id']);
    echo '<div class="kv-card"><h2 style="font-size:16px;margin-bottom:8px">社員の削除</h2>';
    if ($n > 0 || $acct) {
        echo '<p class="kv-hint">';
        if ($n > 0) { echo 'この社員の記録が<b>' . $n . '件</b>あります。'; }
        if ($acct) { echo 'アカウントが残っています。'; }
        echo '削除できません。会社を離れた場合は、'
           . '<b>在籍状態を「退職」</b>にしてください（記録は残り、ログインは止まります）。</p>';
    } else {
        echo '<p class="kv-hint">記録もアカウントもないので削除できます。'
           . '在籍していた社員は、削除せず「退職」にしてください。</p>'
           . '<form method="post" action="' . kv_h($self) . '?do=admin" '
           . 'onsubmit="return confirm(\'この社員を完全に削除します。よろしいですか\')">'
           . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
           . '<input type="hidden" name="action" value="emp_delete">'
           . '<input type="hidden" name="emp_id" value="' . kv_h($emp['id']) . '">'
           . '<button class="kv-btn danger" type="submit">この社員を削除</button></form>';
    }
    echo '</div></main>';
    kv_foot();
}

/** 社員フォームの1項目。ラベル・入力・ヒント・エラーをまとめて出す。 */
function kv_ef($key, $label, $type, $values, $errors, $hint = '', $required = false) {
    $v = isset($values[$key]) ? $values[$key] : '';
    echo '<div class="kv-field"><label for="f_' . kv_h($key) . '">' . kv_h($label)
       . ($required ? '<span class="kv-req">必須</span>' : '') . '</label>'
       . '<input type="' . kv_h($type) . '" name="' . kv_h($key) . '" id="f_' . kv_h($key)
       . '" value="' . kv_h($v) . '"' . ($required ? ' required' : '') . '>';
    if ($hint !== '') { echo '<p class="kv-hint">' . kv_h($hint) . '</p>'; }
    if (isset($errors[$key])) { echo '<p class="kv-error">' . kv_h($errors[$key]) . '</p>'; }
    echo '</div>';
}

/* ---- 管理者：アカウント一覧（権限の棚卸し） ---- */
function kv_page_users($user) {
    $self = basename(__FILE__);
    $rows = kv_account_audit();
    $sum = kv_account_summary();

    kv_head('アカウント', $user);
    kv_nav($user, '');
    echo '<main class="kv-wrap"><div class="kv-card">';
    kv_msg();
    echo '<p class="kv-eyebrow">ADMIN</p>';
    echo '<h1 class="kv-title"><span class="kv-emoji">🔑</span>アカウント</h1>';
    echo '<p class="kv-lead">いま<b>ログインできる人</b>の一覧です。'
       . '人の情報（部署・役職・在籍）は<a href="' . kv_h($self) . '?do=admin">社員マスタ</a>で管理します。</p>';

    /* --- 内訳 --- */
    echo '<div class="kv-bar" style="margin:18px 0 6px">';
    echo '<span class="kv-scope">👤 ログインできる ' . (int)$sum['usable'] . '人</span>';
    foreach (kv_roles() as $k => $n) {
        echo '<span class="kv-tag">' . kv_h($n) . ' ' . (int)$sum[$k] . '</span>';
    }
    if ($sum['stopped'] > 0) {
        echo '<span class="kv-tag rejected">停止 ' . (int)$sum['stopped'] . '</span>';
    }
    if ($sum['warn'] > 0) {
        echo '<span class="kv-tag submitted">要確認 ' . (int)$sum['warn'] . '</span>';
    }
    echo '</div>';

    if ($sum['warn'] > 0) {
        echo '<div class="kv-msg" style="background:#fff6da;border-color:#f0dda3;color:#7a5a06">'
           . '<span>⚠</span>確認が必要なアカウントが' . (int)$sum['warn']
           . '件あります（上に表示しています）</div>';
    }

    if (!$rows) {
        echo '<div class="kv-empty"><span class="kv-emoji">🔑</span>'
           . 'アカウントがありません。<a href="' . kv_h($self) . '?do=admin">社員マスタ</a>'
           . 'から作成してください。</div>';
        echo '</div></main>'; kv_foot(); return;
    }

    echo '<div class="kv-tablewrap"><table><thead><tr>'
       . '<th>氏名</th><th>ログインID</th><th>部署</th><th>役割</th>'
       . '<th>状態</th><th>最終ログイン</th><th></th></tr></thead><tbody>';

    foreach ($rows as $row) {
        $raw = $row['raw'];
        $u = $row['user'];
        $isMe = ($raw['id'] === $user['id']);
        echo '<form method="post" action="' . kv_h($self) . '?do=users"><tr'
           . ($row['warn'] ? ' style="background:#fffcf2"' : '') . '>';

        echo '<td style="font-weight:700">';
        if ($u['employee']) {
            echo '<a href="' . kv_h($self) . '?do=admin&amp;emp=' . kv_h($u['employee']['id'])
               . '" style="text-decoration:none">' . kv_h($u['name']) . '</a>';
            if ($u['emp_no'] !== '') { echo '<br><span class="kv-hint">' . kv_h($u['emp_no']) . '</span>'; }
        } else {
            echo kv_h($u['name']);
        }
        if ($isMe) { echo ' <span class="kv-tag">自分</span>'; }
        foreach ($row['warn'] as $w) {
            echo '<br><span class="kv-tag submitted" style="margin-top:4px">' . kv_h($w) . '</span>';
        }
        echo '</td>';

        $lid = isset($raw['login_id']) && $raw['login_id'] !== '' ? $raw['login_id'] : $raw['email'];
        echo '<td><span style="font-weight:700">' . kv_h($lid) . '</span>'
           . '<br><span class="kv-hint">' . kv_h($raw['provider']);
        if (kv_is_bootstrap_admin($raw)) {
            echo '・<span class="kv-tag">設定ファイルの初期管理者</span>';
        } else if (kv_auth_provider() === 'password' && empty($raw['password_hash'])) {
            echo '・<span class="kv-tag rejected">パスワード未発行</span>';
        } else if (!empty($raw['must_change'])) {
            echo '・<span class="kv-tag submitted">変更待ち</span>';
        }
        echo '</span></td>';
        echo '<td>' . kv_h($u['dept'] !== '' ? kv_dept_name($u['dept']) : '—') . '</td>';

        echo '<td><select name="role">';
        foreach (kv_roles() as $k => $n) {
            echo '<option value="' . kv_h($k) . '"' . ($raw['role'] === $k ? ' selected' : '') . '>'
               . kv_h($n) . '</option>';
        }
        echo '</select></td>';

        echo '<td><label class="kv-choice" style="padding:6px 11px">'
           . '<input type="checkbox" name="active" value="1"'
           . (!empty($raw['active']) ? ' checked' : '') . '>有効</label>';
        if (!empty($raw['active']) && empty($u['active'])) {
            // アカウントは有効なのに、社員が退職・休職・不在で実際は入れない状態
            echo '<br><span class="kv-tag rejected" style="margin-top:5px">実際は不可</span>';
        }
        echo '</td>';

        echo '<td class="kv-hint">' . kv_h($raw['last_login'] !== '' ? $raw['last_login'] : '—') . '</td>';

        echo '<td style="white-space:nowrap;text-align:right">'
           . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
           . '<input type="hidden" name="user_id" value="' . kv_h($raw['id']) . '">'
           . '<button class="kv-btn sub" type="submit" name="action" value="acct_save">保存</button>';
        if (!$isMe) {
            echo ' <button class="kv-btn danger" type="submit" name="action" value="acct_delete" '
               . 'onclick="return confirm(\'このアカウントを削除します。社員の情報と過去の記録は残ります。よろしいですか\')"'
               . '>削除</button>';
        }
        echo '</td></tr></form>';
    }
    echo '</tbody></table></div>';

    echo '<p class="kv-hint" style="margin-top:16px">'
       . '「実際は不可」は、アカウントは有効でも<b>社員が退職・休職のためログインできない</b>状態です。'
       . '削除しても過去の記録は残ります（作成者の名前も消えません）。<br>'
       . '自分のアカウントは削除できません。最後の管理者も、降格・停止・削除ができません。</p>';
    echo '</div></main>';
    kv_foot();
}

/* ---- パスワードの変更（本人） ---- */
function kv_page_password($user, $err, $forced) {
    $self = basename(__FILE__);
    kv_head('パスワードの変更', $user);
    if (!$forced) { kv_nav($user, ''); }
    echo '<main class="kv-wrap kv-narrow"><div class="kv-card">';
    kv_msg();
    echo '<p class="kv-eyebrow">ACCOUNT</p>';
    echo '<h1 class="kv-title" style="margin-bottom:14px"><span class="kv-emoji">🔒</span>'
       . 'パスワードの変更</h1>';
    if ($forced) {
        echo '<div class="kv-msg" style="background:#fff6da;border-color:#f0dda3;color:#7a5a06">'
           . '<span>⚠</span>管理者が発行したパスワードのままです。'
           . 'ご自身のパスワードに変更してください。</div>';
    }
    echo '<p class="kv-hint">ログインID（社員番号）: <b>'
       . kv_h($user['login_id'] !== '' ? $user['login_id'] : $user['email']) . '</b></p>';
    if ($err !== '') { echo '<p class="kv-error">' . kv_h($err) . '</p>'; }

    echo '<form method="post" action="' . kv_h($self) . '?do=password">'
       . '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">'
       . '<div class="kv-field"><label for="c">今のパスワード</label>'
       . '<input type="password" name="current" id="c" required autocomplete="current-password"></div>'
       . '<div class="kv-field"><label for="n1">新しいパスワード</label>'
       . '<input type="password" name="new1" id="n1" required autocomplete="new-password">'
       . '<p class="kv-hint">8文字以上。数字だけは使えません。</p></div>'
       . '<div class="kv-field"><label for="n2">新しいパスワード（確認）</label>'
       . '<input type="password" name="new2" id="n2" required autocomplete="new-password"></div>'
       . '<button class="kv-btn" type="submit">変更する</button>';
    if (!$forced) {
        echo ' <a class="kv-btn sub" href="' . kv_h($self) . '">やめる</a>';
    } else {
        echo ' <a class="kv-btn sub" href="' . kv_h($self) . '?do=logout">ログアウト</a>';
    }
    echo '</form></div></main>';
    kv_foot();
}
