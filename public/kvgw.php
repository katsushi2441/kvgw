<?php
/**
 * Kurage Vibe Groupware (kvgw) — Core に足す「グループウェアの本機能」。
 *
 *   メッセージ(kvgw_message.php)・施設予約(kvgw_facility.php)・ToDo(kvgw_todo.php)
 *
 * Core(kvgwc.php)は、このファイルがあれば読み込み、7か所の拡張点から下の関数を呼ぶ。
 * このファイルを消せば Core 単体に戻る。台帳は Core と同じ kv_load / kv_update を通す。
 *
 * 決まり:
 *   - 誰が何をできるかは、機能ごとに1つの関数で決める
 *       kvgw_msg_can() / kvgw_fac_can() / kvgw_todo_can()
 *     画面でボタンを隠すだけにしない。保存の直前(kv_update のロックの中)でもう一度確かめる。
 *   - 画面の URL は kvgwc.php?m=<機能>&a=<操作>。Core の ?do= ?app= とぶつからない。
 *   - 入力の誤りは 400 で返す。画面の文字は必ず kv_h() で出す。
 */

define('KVGWC_PRODUCT', 'Kurage Vibe Groupware');
define('KVGWC_VERSION', 'v1.0');

require_once __DIR__ . '/kvgw_message.php';
require_once __DIR__ . '/kvgw_facility.php';
require_once __DIR__ . '/kvgw_todo.php';

/* ============================================================
 * 共通の小さな部品
 * ============================================================ */

function kvgw_self() { return basename($_SERVER['SCRIPT_NAME']); }

/** 設定(kvgwc_config.php の define)を読む。書いていなければ既定値。 */
function kvgw_conf($name, $default) { return defined($name) ? constant($name) : $default; }

function kvgw_url($m, $params = array()) {
    $q = 'm=' . rawurlencode($m);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) { continue; }
        $q .= '&' . rawurlencode($k) . '=' . rawurlencode((string)$v);
    }
    return kvgw_self() . '?' . $q;
}

function kvgw_go($m, $params = array()) {
    header('Location: ' . kvgw_url($m, $params));
    exit;
}

/** 書き換えの操作は POST と CSRF トークンがそろったときだけ通す。 */
function kvgw_need_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || !kv_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        kv_fail(400, '画面の有効期限が切れました。もう一度やり直してください。');
    }
}

function kvgw_csrf() {
    return '<input type="hidden" name="csrf" value="' . kv_h(kv_csrf_token()) . '">';
}

/** 入力から文字を取り出す。前後の空白を落とし、長すぎる分は切る。 */
function kvgw_in($arr, $key, $max) {
    $v = isset($arr[$key]) && !is_array($arr[$key]) ? (string)$arr[$key] : '';
    $v = trim(str_replace(array("\r\n", "\r"), "\n", $v));
    return mb_substr($v, 0, $max, 'UTF-8');
}

function kvgw_date_ok($d) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) { return false; }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function kvgw_time_ok($t) {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t);
}

function kvgw_today() { return date('Y-m-d'); }

function kvgw_wday($date) {
    $w = array('日', '月', '火', '水', '木', '金', '土');
    return $w[(int)date('w', strtotime($date))];
}

/** 2026-11-10 → 11/10(火) */
function kvgw_day_label($date) {
    if (!kvgw_date_ok($date)) { return ''; }
    return (int)substr($date, 5, 2) . '/' . (int)substr($date, 8, 2) . '(' . kvgw_wday($date) . ')';
}

/**
 * いま在籍していてログインできる人の一覧。宛先・担当者の候補に使う。
 * Core の kv_user_compose() を通すので、退職・休職の人はここで外れる。
 */
function kvgw_people($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) { return $cache; }
    $out = array();
    foreach (kv_users() as $raw) {
        $u = kv_user_compose($raw);
        if (empty($u['active'])) { continue; }
        $out[$u['id']] = array('id' => $u['id'], 'name' => $u['name'], 'dept' => $u['dept'], 'role' => $u['role']);
    }
    uasort($out, function ($a, $b) {
        if ($a['dept'] !== $b['dept']) { return strcmp($a['dept'], $b['dept']); }
        return strcmp($a['name'], $b['name']);
    });
    $cache = $out;
    return $out;
}

/** 人の名前。いま居ない人は、記録に残した名前を使う。 */
function kvgw_person_name($id, $fallback = '') {
    $p = kvgw_people();
    if (isset($p[$id])) { return $p[$id]['name']; }
    return $fallback !== '' ? $fallback : '（退職・停止中の人）';
}

/** 人を選ぶ欄。部署ごとにまとめ、「部署の全員」をまとめて選べる。 */
function kvgw_people_checks($name, $selected, $excludeId = '') {
    $by = array();
    foreach (kvgw_people() as $p) {
        if ($p['id'] === $excludeId) { continue; }
        $by[$p['dept']][] = $p;
    }
    if (!$by) { return '<p class="kv-hint">選べる人がいません。社員マスタでアカウントを作ってください。</p>'; }
    $h = '';
    foreach ($by as $dept => $list) {
        $h .= '<fieldset class="gw-group"><legend>' . kv_h(kv_dept_name($dept))
            . '<button type="button" class="gw-all" data-gw-all>全員</button></legend><div class="kv-choices">';
        foreach ($list as $p) {
            $on = in_array($p['id'], $selected, true) ? ' checked' : '';
            $h .= '<label class="kv-choice"><input type="checkbox" name="' . kv_h($name) . '[]" value="'
                . kv_h($p['id']) . '"' . $on . '>' . kv_h($p['name']) . '</label>';
        }
        $h .= '</div></fieldset>';
    }
    /* 「全員」は押すたびに、その部署の全員を選ぶ／外す。JS が無くても1人ずつ選べる。 */
    $h .= '<script>document.querySelectorAll("[data-gw-all]").forEach(function(b){b.addEventListener("click",function(){'
        . 'var c=b.closest("fieldset").querySelectorAll("input[type=checkbox]"),on=false;'
        . 'c.forEach(function(x){if(!x.checked)on=true});c.forEach(function(x){x.checked=on})})});</script>';
    return $h;
}

function kvgw_flash() {
    if (empty($_GET['msg'])) { return; }
    $m = array(
        'sent' => '送信しました', 'draft' => '下書きに保存しました', 'saved' => '保存しました',
        'deleted' => '削除しました', 'comment' => 'コメントを書き込みました',
        'trash' => 'ごみ箱に移しました', 'restore' => '受信箱に戻しました',
        'added' => '宛先を追加しました', 'reserved' => '予約しました', 'canceled' => '予約を取り消しました',
        'done' => '完了にしました', 'undone' => '未完了に戻しました',
    );
    $k = (string)$_GET['msg'];
    if (isset($m[$k])) { echo '<div class="kv-msg"><span>✓</span>' . kv_h($m[$k]) . '</div>'; }
}

/* ============================================================
 * 拡張点(2) 画面の振り分け
 * ============================================================ */

function kvgw_dispatch($user) {
    if (!isset($_GET['m'])) { return; }
    $m = preg_replace('/[^a-z]/', '', (string)$_GET['m']);
    if ($m === 'message')       { kvgw_message_route($user); }
    else if ($m === 'facility') { kvgw_facility_route($user); }
    else if ($m === 'todo')     { kvgw_todo_route($user); }
    else { kv_fail(404, 'その画面はありません。'); }
    exit;
}

/* ============================================================
 * 拡張点(4) タブ　(7) 上部バー
 * ============================================================ */

function kvgw_nav($user, $current) {
    $unread = kvgw_msg_unread_count($user);
    $open = kvgw_todo_open_count($user);
    $tabs = array(
        array('message',  '✉️', 'メッセージ', $unread > 0 ? '未読 ' . $unread : ''),
        array('facility', '🏢', '施設予約', ''),
        array('todo',     '✅', 'ToDo', $open > 0 ? (string)$open : ''),
    );
    $h = '';
    foreach ($tabs as $t) {
        $on = ($current === 'gw:' . $t[0]) ? ' class="on"' : '';
        $h .= '<a href="' . kv_h(kvgw_url($t[0])) . '"' . $on . '><span class="kv-emoji">' . $t[1] . '</span>'
            . kv_h($t[2]) . ($t[3] !== '' ? '<span class="kv-badge">' . kv_h($t[3]) . '</span>' : '') . '</a>';
    }
    return $h;
}

function kvgw_tools($user) { return ''; }

/* ============================================================
 * 拡張点(5) ホーム — 今日見るものを1画面に
 * ============================================================ */

function kvgw_home($user) {
    $today = kvgw_today();

    /* 未読メッセージ */
    $msgs = '';
    $n = 0;
    foreach (kvgw_msg_list($user, 'inbox', '') as $m) {
        if (!$m['_unread']) { continue; }
        if (++$n > 5) { continue; }
        $msgs .= '<a class="gw-line" href="' . kv_h(kvgw_url('message', array('a' => 'view', 'id' => $m['id']))) . '">'
               . '<b>' . kv_h($m['subject']) . '</b><small>' . kv_h($m['last_name']) . '　'
               . kv_h(substr($m['last'], 5, 11)) . '</small></a>';
    }
    if ($msgs === '') { $msgs = '<p class="gw-none">未読はありません</p>'; }
    else if ($n > 5) { $msgs .= '<p class="gw-none">ほか ' . ($n - 5) . ' 件</p>'; }

    /* 今日の予定(共有スケジュール)と施設の予約 */
    $plan = array();
    $app = kv_app('schedule');
    if ($app && kv_can($user, $app, 'read')) {
        foreach (kv_records($user, $app) as $r) {
            if (!isset($r['date']) || $r['date'] !== $today) { continue; }
            if (!isset($r['_owner']) || $r['_owner'] !== $user['id']) { continue; }
            $plan[] = array(isset($r['start']) ? $r['start'] : '', isset($r['end']) ? $r['end'] : '',
                            isset($r['title']) ? $r['title'] : '', isset($r['place']) ? $r['place'] : '',
                            kvgw_self() . '?app=schedule&do=edit&id=' . rawurlencode($r['_id']),
                            isset($r['_id']) ? $r['_id'] : '');
        }
    }
    $linked = array();
    foreach ($plan as $p) { $linked[$p[5]] = true; }
    foreach (kvgw_res_on($today) as $r) {
        if ($r['by'] !== $user['id']) { continue; }
        if ($r['sched'] !== '' && isset($linked[$r['sched']])) { continue; }   // 同じ予定を2行にしない
        $f = kvgw_fac_find($r['facility']);
        $plan[] = array($r['start'], $r['end'], $r['title'], $f ? $f['name'] : '',
                        kvgw_url('facility', array('a' => 'edit', 'id' => $r['id'])), '');
    }
    usort($plan, function ($a, $b) { return strcmp($a[0], $b[0]); });
    $plans = '';
    foreach ($plan as $p) {
        $time = $p[0] !== '' ? $p[0] . ($p[1] !== '' ? '–' . $p[1] : '') : '終日';
        $plans .= '<a class="gw-line" href="' . kv_h($p[4]) . '"><b>' . kv_h($p[2]) . '</b><small>'
                . kv_h($time) . ($p[3] !== '' ? '　' . kv_h($p[3]) : '') . '</small></a>';
    }
    if ($plans === '') { $plans = '<p class="gw-none">今日の予定はありません</p>'; }

    /* 期限の近いToDo */
    $todos = '';
    $n2 = 0;
    foreach (kvgw_todo_list($user, 'open', '') as $t) {
        if (++$n2 > 5) { continue; }
        $late = ($t['due'] !== '' && $t['due'] < $today);
        $todos .= '<a class="gw-line" href="' . kv_h(kvgw_url('todo', array('a' => 'edit', 'id' => $t['id']))) . '">'
                . '<b>' . kv_h($t['title']) . '</b><small' . ($late ? ' class="gw-late"' : '') . '>'
                . ($t['due'] !== '' ? '期日 ' . kv_h(kvgw_day_label($t['due'])) . ($late ? '　期限切れ' : '') : '期日なし')
                . '</small></a>';
    }
    if ($todos === '') { $todos = '<p class="gw-none">未完了のToDoはありません</p>'; }
    else if ($n2 > 5) { $todos .= '<p class="gw-none">ほか ' . ($n2 - 5) . ' 件</p>'; }

    echo '<div class="gw-portal">'
       . '<section class="kv-card"><h2><span class="kv-emoji">✉️</span>未読メッセージ</h2>' . $msgs
       . '<a class="gw-more" href="' . kv_h(kvgw_url('message')) . '">メッセージを開く</a></section>'
       . '<section class="kv-card"><h2><span class="kv-emoji">📅</span>今日の予定　<small>'
       . kv_h(kvgw_day_label($today)) . '</small></h2>' . $plans
       . '<a class="gw-more" href="' . kv_h(kvgw_url('facility')) . '">施設の空きを見る</a></section>'
       . '<section class="kv-card"><h2><span class="kv-emoji">✅</span>ToDo</h2>' . $todos
       . '<a class="gw-more" href="' . kv_h(kvgw_url('todo')) . '">ToDoを開く</a></section>'
       . '</div>';
    echo '<p class="kv-eyebrow" style="margin-top:8px">業務アプリ</p>';
}

/* ============================================================
 * 拡張点(6) 下部
 * ============================================================ */

function kvgw_foot() {
    echo '<footer class="kv-foot"><b>' . kv_h(KVGWC_PRODUCT) . '</b> ' . kv_h(KVGWC_VERSION)
       . '　—　メッセージ・施設予約・ToDo・共有スケジュールと、足していける業務アプリ';
    if (function_exists('kvgw_demo_foot')) { echo kvgw_demo_foot(); }   // デモの案内は設定ファイル側に置く
    echo '</footer></body></html>';
}

/* ============================================================
 * 拡張点(3) 見た目
 * ============================================================ */

function kvgw_css() {
    return ''
    /* スマホではタブを折り返す(横に流すと、後ろのタブに気づけない) */
    . '@media(max-width:640px){.kv-navinner{flex-wrap:wrap;overflow-x:visible}.kv-navinner a{flex:1 1 calc(50% - 8px);justify-content:center}}'
    /* ホーム */
    . '.gw-portal{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:6px}'
    . '.gw-portal .kv-card{margin:0;padding:20px 20px 16px;display:flex;flex-direction:column;min-width:0}'
    . '.gw-portal h2{font-size:15.5px;display:flex;align-items:center;gap:8px;margin-bottom:8px}'
    . '.gw-portal h2 small{font-size:12px;color:var(--muted);font-weight:700}'
    . '.gw-line{display:block;text-decoration:none;color:inherit;padding:9px 0;border-bottom:1px solid #eef2f7;min-width:0}'
    . '.gw-line b{display:block;font-size:14px;line-height:1.5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    . '.gw-line small{display:block;color:var(--muted);font-size:12px}'
    . '.gw-line:hover b{color:var(--a-dark)}'
    . '.gw-late{color:#c0392b!important;font-weight:800}'
    . '.gw-none{color:var(--muted);font-size:13px;margin:8px 0}'
    . '.gw-more{margin-top:auto;padding-top:12px;font-size:12.5px;font-weight:800;text-decoration:none}'
    /* 一覧の行 */
    . '.gw-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}'
    . '.gw-tabs a{font-size:13px;font-weight:800;text-decoration:none;color:var(--muted);padding:6px 14px;'
      . 'border-radius:999px;border:1.5px solid var(--line);background:#fff;white-space:nowrap}'
    . '.gw-tabs a.on{background:var(--a-pale);border-color:var(--a);color:var(--a-dark)}'
    . '.gw-rows{border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden}'
    . '.gw-row{display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-bottom:1px solid #eef2f7;'
      . 'text-decoration:none;color:inherit;min-width:0}'
    . '.gw-row:last-child{border-bottom:0}.gw-row:hover{background:#fafcff}'
    . '.gw-rowmain{flex:1;min-width:0}'
    . '.gw-rowmain b{display:block;font-size:14.5px;line-height:1.5;font-weight:500;overflow-wrap:anywhere}'
    . '.gw-row.unread .gw-rowmain b{font-weight:900}'
    . '.gw-rowmain small{display:block;color:var(--muted);font-size:12.5px;overflow-wrap:anywhere}'
    . '.gw-rowside{flex:0 0 auto;text-align:right;font-size:12px;color:var(--muted);white-space:nowrap}'
    . '.gw-dot{flex:0 0 auto;width:9px;height:9px;border-radius:50%;background:transparent;margin-top:8px}'
    . '.gw-row.unread .gw-dot{background:var(--a)}'
    . '.gw-tagnew{background:var(--a);color:#fff}'
    /* メッセージの本文 */
    . '.gw-body{white-space:pre-wrap;overflow-wrap:anywhere;font-size:15px;line-height:1.85;margin:16px 0 4px}'
    . '.gw-meta{color:var(--muted);font-size:12.5px;margin:6px 0 0;overflow-wrap:anywhere}'
    . '.gw-files{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 0}'
    . '.gw-files a{font-size:13px;font-weight:700;text-decoration:none;padding:6px 13px;border-radius:10px;'
      . 'border:1.5px solid var(--line);background:#fff;overflow-wrap:anywhere}'
    . '.gw-comment{border-top:1px solid #eef2f7;padding:14px 0}'
    . '.gw-comment:first-of-type{border-top:0}'
    . '.gw-comment header{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;font-size:12.5px;color:var(--muted)}'
    . '.gw-comment header b{color:var(--ink);font-size:13.5px}'
    . '.gw-comment p{margin:5px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}'
    . '.gw-inline{display:inline;margin:0}'
    . '.gw-textbtn{background:none;border:0;padding:0;color:var(--muted);font-size:12px;cursor:pointer;'
      . 'text-decoration:underline;width:auto}'
    . '.gw-seen{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}'
    . '.gw-seen span{font-size:12px;font-weight:700;padding:4px 11px;border-radius:999px;background:#eef2f7;color:#54637a}'
    . '.gw-seen span.on{background:#e6f8ec;color:#1d6b3c}'
    . '.gw-group{border:1.5px solid var(--line);border-radius:14px;padding:10px 14px 14px;margin:0 0 10px;min-width:0}'
    . '.gw-group legend{font-weight:800;font-size:13px;padding:0 6px;display:flex;align-items:center;gap:8px}'
    . '.gw-all{width:auto;font-size:11.5px;font-weight:800;padding:2px 10px;border-radius:999px;'
      . 'border:1.5px solid var(--line);background:#fff;color:var(--a-dark);cursor:pointer}'
    . 'details.gw-fold>summary{cursor:pointer;font-weight:800;font-size:13.5px;color:var(--a-dark)}'
    /* 施設予約 */
    . '.gw-datebar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0}'
    . '.gw-datebar form{display:flex;gap:8px;margin:0}.gw-datebar input[type=date]{width:auto}'
    . '.gw-datebar h2{font-size:18px;margin-right:auto}'
    . '.gw-facgroup{font:900 14px/1.5 "Zen Maru Gothic",sans-serif;color:var(--a-dark);margin:20px 0 8px}'
    . '.gw-fac{border:1px solid var(--line);border-radius:14px;background:#fff;padding:13px 15px;margin-bottom:10px}'
    . '.gw-fachead{display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
    . '.gw-fachead b{font-size:15px}.gw-fachead small{color:var(--muted);font-size:12px}'
    . '.gw-fachead a{margin-left:auto}'
    . '.gw-track{position:relative;height:30px;border-radius:9px;margin:10px 0 2px;overflow:hidden;'
      . 'background:repeating-linear-gradient(90deg,#f3f6fa 0,#f3f6fa calc(var(--h) - 1px),#dfe6ee calc(var(--h) - 1px),#dfe6ee var(--h))}'
    . '.gw-busy{position:absolute;top:3px;bottom:3px;border-radius:7px;background:var(--a);opacity:.9;min-width:3px}'
    . '.gw-busy.mine{background:#e8890c}'
    . '.gw-scale{display:flex;justify-content:space-between;font-size:10.5px;color:var(--muted);margin-bottom:6px}'
    . '.gw-res{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;font-size:13.5px;padding:5px 0;'
      . 'border-top:1px dashed #e6ecf3;text-decoration:none;color:inherit}'
    . '.gw-res time{font-weight:800;white-space:nowrap}.gw-res small{color:var(--muted)}'
    . '.gw-res span{overflow-wrap:anywhere}'
    . '.gw-legend{font-size:12px;color:var(--muted);display:flex;gap:14px;flex-wrap:wrap}'
    . '.gw-legend i{display:inline-block;width:11px;height:11px;border-radius:3px;background:var(--a);margin-right:5px;vertical-align:-1px}'
    . '.gw-legend i.mine{background:#e8890c}'
    . '.gw-week td{min-width:104px;font-size:12.5px}.gw-week th.today{background:#fff1d6;color:#8a5200}'
    . '.gw-week td a{display:block;text-decoration:none;color:inherit;padding:3px 0;border-bottom:1px dashed #e6ecf3}'
    . '.gw-week td a:last-of-type{border-bottom:0}.gw-week td a b{display:block}'
    . '.gw-week .gw-add{color:var(--muted);font-size:11.5px;border:0}'
    . '.gw-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
    /* ToDo */
    . '.gw-todo{display:flex;gap:12px;align-items:flex-start;padding:12px 16px;border-bottom:1px solid #eef2f7;min-width:0}'
    . '.gw-todo:last-child{border-bottom:0}'
    . '.gw-todo form{margin:0;flex:0 0 auto}'
    . '.gw-check{width:26px;height:26px;border-radius:50%;border:2px solid #b9c6d6;background:#fff;cursor:pointer;'
      . 'padding:0;display:grid;place-items:center;color:#fff;font-size:14px;line-height:1}'
    . '.gw-check:hover{border-color:var(--a)}.gw-check.on{background:#2f9e5b;border-color:#2f9e5b}'
    . '.gw-todo a{flex:1;min-width:0;text-decoration:none;color:inherit}'
    . '.gw-todo a b{display:block;font-size:14.5px;font-weight:700;overflow-wrap:anywhere}'
    . '.gw-todo.done a b{text-decoration:line-through;color:var(--muted);font-weight:500}'
    . '.gw-todo a small{display:block;font-size:12.5px;color:var(--muted);overflow-wrap:anywhere}'
    . '.gw-pri{flex:0 0 auto;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;margin-top:3px}'
    . '.gw-pri.p1{background:#fdecea;color:#b3261e}.gw-pri.p2{background:#fff3df;color:#96590a}'
    . '.gw-pri.p3{background:#eef2f7;color:#54637a}'
    . '.gw-quick{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 4px}'
    . '.gw-quick input[type=text]{flex:1;min-width:150px}.gw-quick input[type=date]{width:auto;flex:0 0 auto}'
    . '@media(max-width:620px){.gw-two{grid-template-columns:1fr}.gw-rowside{font-size:11px}'
      . '.gw-quick input[type=date]{flex:1}}';
}
