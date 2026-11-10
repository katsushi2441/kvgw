<?php
/**
 * 施設予約 — 会議室・社用車・備品を、重ならないように押さえる。
 *
 * 台帳:
 *   gw_facilities   … 施設(名前・グループ・定員や備考・並び順・使用中か)。管理者が登録する。
 *   gw_reservations … 予約(施設・日付・開始・終了・利用目的・メモ・予約した人)
 *
 * 重なりの確認は kv_update() のロックの中で行う。2人が同時に押しても、通るのは1人だけ。
 * 繰り返しの予約は、1日でも重なれば1件も入れない(半端に入ると、後始末が人の仕事になる)。
 */

/* ============================================================
 * 権限 — 施設予約についての判定はここだけ
 * ============================================================ */

function kvgw_fac_can($user, $op, $r = null) {
    if (!$user) { return false; }
    switch ($op) {
        case 'read':
        case 'create': return true;                       // 予約は全員に見えてこそ、空きが分かる
        case 'manage': return $user['role'] === 'admin';  // 施設の登録・変更
        case 'update':
        case 'delete':
            if (!$r) { return false; }
            if ($user['role'] === 'admin' || $r['by'] === $user['id']) { return true; }
            return $user['role'] === 'chief' && $r['dept'] !== '' && $r['dept'] === $user['dept'];
    }
    return false;
}

/* ============================================================
 * 施設
 * ============================================================ */

function kvgw_fac_all() {
    $fs = kv_load('gw_facilities', 'facilities');
    /* 分類は、その中でいちばん小さい並び順の番号で並べる(会議室 → 社用車 → 備品 のように、決めた順で出す) */
    $first = array();
    foreach ($fs as $f) {
        if (!isset($first[$f['group']]) || (int)$f['order'] < $first[$f['group']]) { $first[$f['group']] = (int)$f['order']; }
    }
    usort($fs, function ($a, $b) use ($first) {
        if ($a['group'] !== $b['group']) {
            return $first[$a['group']] !== $first[$b['group']] ? $first[$a['group']] - $first[$b['group']] : strcmp($a['group'], $b['group']);
        }
        if ((int)$a['order'] !== (int)$b['order']) { return (int)$a['order'] - (int)$b['order']; }
        return strcmp($a['name'], $b['name']);
    });
    return $fs;
}

function kvgw_fac_find($id) {
    foreach (kv_load('gw_facilities', 'facilities') as $f) { if ($f['id'] === $id) { return $f; } }
    return null;
}

function kvgw_fac_save($user, $id, $in) {
    if (!kvgw_fac_can($user, 'manage')) { return array(false, '施設を登録できるのは管理者だけです'); }
    $name = kvgw_in($in, 'name', 40);
    $group = kvgw_in($in, 'group', 30);
    $note = kvgw_in($in, 'note', 80);
    $order = isset($in['order']) ? max(0, min(999, (int)$in['order'])) : 0;
    $active = !isset($in['active']) || !empty($in['active']);
    if ($name === '') { return array(false, '施設の名前を入れてください'); }
    if ($group === '') { $group = '施設'; }
    return kv_update('gw_facilities', 'facilities', function (&$fs) use ($user, $id, $name, $group, $note, $order, $active) {
        if (!kvgw_fac_can($user, 'manage')) { return '施設を登録できるのは管理者だけです'; }
        foreach ($fs as $f) {
            if ($f['id'] !== $id && $f['name'] === $name && $f['group'] === $group) { return '同じ名前の施設がすでにあります'; }
        }
        if ($id === '') {
            $f = array('id' => 'f' . kv_random_hex(6), 'name' => $name, 'group' => $group, 'note' => $note,
                       'order' => $order, 'active' => $active, 'created' => kv_now());
            $fs[] = $f;
            return $f;
        }
        foreach ($fs as $i => $f) {
            if ($f['id'] !== $id) { continue; }
            $fs[$i]['name'] = $name; $fs[$i]['group'] = $group; $fs[$i]['note'] = $note;
            $fs[$i]['order'] = $order; $fs[$i]['active'] = $active;
            return $fs[$i];
        }
        return '施設が見つかりません';
    });
}

/** これからの予約が残っている施設は消せない(「使用をやめる」にして新しい予約だけ止める)。 */
function kvgw_fac_delete($user, $id) {
    if (!kvgw_fac_can($user, 'manage')) { return array(false, '施設を削除できるのは管理者だけです'); }
    foreach (kvgw_res_all() as $r) {
        if ($r['facility'] === $id && $r['date'] >= kvgw_today()) {
            return array(false, 'これからの予約が残っています。先に「使用をやめる」にして、予約が終わってから削除してください');
        }
    }
    $res = kv_update('gw_facilities', 'facilities', function (&$fs) use ($user, $id) {
        if (!kvgw_fac_can($user, 'manage')) { return '施設を削除できるのは管理者だけです'; }
        foreach ($fs as $i => $f) {
            if ($f['id'] === $id) { array_splice($fs, $i, 1); return true; }
        }
        return '施設が見つかりません';
    });
    if ($res[0]) {   // 過去の予約も一緒に片づける(施設が無い予約を残さない)
        kv_update('gw_reservations', 'reservations', function (&$rs) use ($id) {
            $keep = array();
            foreach ($rs as $r) { if ($r['facility'] !== $id) { $keep[] = $r; } }
            $rs = $keep;
            return true;
        });
    }
    return $res;
}

/* ============================================================
 * 予約
 * ============================================================ */

function kvgw_res_all() { return kv_load('gw_reservations', 'reservations'); }

function kvgw_res_find($id) {
    foreach (kvgw_res_all() as $r) { if ($r['id'] === $id) { return $r; } }
    return null;
}

/** その日の予約。開始の早い順。 */
function kvgw_res_on($date) {
    $out = array();
    foreach (kvgw_res_all() as $r) { if ($r['date'] === $date) { $out[] = $r; } }
    usort($out, function ($a, $b) { return strcmp($a['start'], $b['start']); });
    return $out;
}

/** 何日先まで予約できるか(設定 KVGW_RESERVE_DAYS。既定は180日)。 */
function kvgw_res_horizon() { return max(1, (int)kvgw_conf('KVGW_RESERVE_DAYS', 180)); }

function kvgw_res_repeats() {
    return array('' => '繰り返さない', 'daily' => '毎日', 'weekday' => '平日（月〜金）', 'weekly' => '毎週');
}

/** 繰り返しの指定から、予約する日付を並べる。$count は予約の回数(1〜30)。 */
function kvgw_res_dates($date, $repeat, $count) {
    $count = max(1, min(30, (int)$count));
    if ($repeat === '' || $count === 1) { return array($date); }
    $out = array();
    $t = strtotime($date . ' 12:00:00');
    $guard = 0;
    while (count($out) < $count && $guard++ < 400) {
        $d = date('Y-m-d', $t);
        $w = (int)date('w', $t);
        if ($repeat !== 'weekday' || ($w >= 1 && $w <= 5)) { $out[] = $d; }
        $t = strtotime($repeat === 'weekly' ? '+7 days' : '+1 day', $t);
    }
    return $out;
}

/** 入力を確かめる。戻り値は array(整えた値, 誤りの一覧)。 */
function kvgw_res_check($in) {
    $v = array(
        'facility' => kvgw_in($in, 'facility', 20), 'date' => kvgw_in($in, 'date', 10),
        'start' => kvgw_in($in, 'start', 5), 'end' => kvgw_in($in, 'end', 5),
        'title' => kvgw_in($in, 'title', 80), 'memo' => kvgw_in($in, 'memo', 500),
    );
    $e = array();
    $f = kvgw_fac_find($v['facility']);
    if (!$f) { $e['facility'] = '施設を選んでください'; }
    else if (empty($f['active'])) { $e['facility'] = 'この施設は、いま予約を受け付けていません'; }
    if (!kvgw_date_ok($v['date'])) { $e['date'] = '日付を入れてください'; }
    else if ($v['date'] < kvgw_today()) { $e['date'] = '過ぎた日は予約できません'; }
    else if ($v['date'] > date('Y-m-d', strtotime('+' . kvgw_res_horizon() . ' days'))) {
        $e['date'] = '予約できるのは ' . kvgw_res_horizon() . ' 日先までです';
    }
    if (!kvgw_time_ok($v['start'])) { $e['start'] = '開始の時刻を入れてください'; }
    if (!kvgw_time_ok($v['end'])) { $e['end'] = '終了の時刻を入れてください'; }
    if (!isset($e['start']) && !isset($e['end']) && $v['start'] >= $v['end']) { $e['end'] = '終了は開始より後にしてください'; }
    if ($v['title'] === '') { $e['title'] = '利用目的を入れてください'; }
    return array($v, $e);
}

/** 同じ施設・同じ日で、時間が重なる予約を探す。$skipId は自分自身(変更のとき)。 */
function kvgw_res_clash($rs, $facility, $date, $start, $end, $skipId = '') {
    foreach ($rs as $r) {
        if ($r['id'] === $skipId || $r['facility'] !== $facility || $r['date'] !== $date) { continue; }
        if ($start < $r['end'] && $r['start'] < $end) { return $r; }
    }
    return null;
}

/**
 * 予約する。$in に repeat(繰り返し)・count(回数)・to_schedule(共有スケジュールにも載せる)。
 * 戻り値は array(true, 入った予約の一覧) か array(false, 誤り)。誤りは 文字 か 項目ごとの配列。
 */
function kvgw_res_create($user, $in) {
    if (!kvgw_fac_can($user, 'create')) { return array(false, '予約する権限がありません'); }
    list($v, $e) = kvgw_res_check($in);
    $repeat = isset($in['repeat']) && isset(kvgw_res_repeats()[$in['repeat']]) ? (string)$in['repeat'] : '';
    $dates = $e ? array() : kvgw_res_dates($v['date'], $repeat, isset($in['count']) ? (int)$in['count'] : 1);
    $limit = date('Y-m-d', strtotime('+' . kvgw_res_horizon() . ' days'));
    foreach ($dates as $d) {
        if ($d > $limit) { $e['count'] = '繰り返しの最後が、予約できる期間（' . kvgw_res_horizon() . ' 日先まで）を越えます'; break; }
    }
    if ($e) { return array(false, $e); }

    $series = count($dates) > 1 ? 's' . kv_random_hex(6) : '';
    $res = kv_update('gw_reservations', 'reservations', function (&$rs) use ($user, $v, $dates, $series) {
        $hit = array();
        foreach ($dates as $d) {
            $c = kvgw_res_clash($rs, $v['facility'], $d, $v['start'], $v['end']);
            if ($c) { $hit[] = kvgw_day_label($d) . ' ' . $c['start'] . '–' . $c['end'] . '（' . $c['by_name'] . '）'; }
        }
        if ($hit) { return 'ほかの予約と重なっています: ' . implode('、', array_slice($hit, 0, 5)) . (count($hit) > 5 ? ' ほか' : ''); }
        $made = array();
        foreach ($dates as $d) {
            $r = array('id' => 'v' . kv_random_hex(8), 'facility' => $v['facility'], 'date' => $d,
                       'start' => $v['start'], 'end' => $v['end'], 'title' => $v['title'], 'memo' => $v['memo'],
                       'by' => $user['id'], 'by_name' => $user['name'], 'dept' => $user['dept'],
                       'series' => $series, 'sched' => '', 'created' => kv_now(), 'updated' => kv_now());
            $rs[] = $r;
            $made[] = $r;
        }
        return $made;
    });
    if (!$res[0]) { return array(false, array('clash' => $res[1])); }

    if (!empty($in['to_schedule'])) { kvgw_res_to_schedule($user, $res[1]); }
    return $res;
}

/** 予約を共有スケジュールにも載せ、どの予定とつながっているかを予約に書く。 */
function kvgw_res_to_schedule($user, $made) {
    $app = kv_app('schedule');
    if (!$app || !kv_can($user, $app, 'create')) { return; }
    $link = array();
    foreach ($made as $r) {
        $f = kvgw_fac_find($r['facility']);
        $s = kv_record_create($user, $app, array(
            'date' => $r['date'], 'start' => $r['start'], 'end' => $r['end'], 'title' => $r['title'],
            'kind' => '打合せ', 'place' => $f ? $f['name'] : '', 'memo' => $r['memo']));
        if ($s[0]) { $link[$r['id']] = $s[1]['_id']; }
    }
    if (!$link) { return; }
    kv_update('gw_reservations', 'reservations', function (&$rs) use ($link) {
        foreach ($rs as $i => $r) { if (isset($link[$r['id']])) { $rs[$i]['sched'] = $link[$r['id']]; } }
        return true;
    });
}

/** つながっている共有スケジュールの予定を、予約に合わせて直す・消す。予約した本人の予定として扱う。 */
function kvgw_res_sync_schedule($r, $delete) {
    if ($r['sched'] === '') { return; }
    $app = kv_app('schedule');
    $owner = kv_user_find($r['by']);
    if (!$app || !$owner) { return; }
    if ($delete) { kv_record_delete($owner, $app, $r['sched']); return; }
    $f = kvgw_fac_find($r['facility']);
    kv_record_update($owner, $app, $r['sched'], array(
        'date' => $r['date'], 'start' => $r['start'], 'end' => $r['end'], 'title' => $r['title'],
        'place' => $f ? $f['name'] : '', 'memo' => $r['memo']));
}

/** 予約を1件直す(繰り返しで入れた予約も、直すのは1件ずつ)。 */
function kvgw_res_update($user, $id, $in) {
    list($v, $e) = kvgw_res_check($in);
    if ($e) { return array(false, $e); }
    $res = kv_update('gw_reservations', 'reservations', function (&$rs) use ($user, $id, $v) {
        foreach ($rs as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            if (!kvgw_fac_can($user, 'update', $r)) { return 'この予約を変更する権限がありません'; }
            $c = kvgw_res_clash($rs, $v['facility'], $v['date'], $v['start'], $v['end'], $id);
            if ($c) { return 'ほかの予約と重なっています: ' . $c['start'] . '–' . $c['end'] . '（' . $c['by_name'] . '）'; }
            foreach ($v as $k => $val) { $rs[$i][$k] = $val; }
            $rs[$i]['updated'] = kv_now();
            return $rs[$i];
        }
        return '予約が見つかりません';
    });
    if (!$res[0]) { return array(false, array('clash' => $res[1])); }
    kvgw_res_sync_schedule($res[1], false);
    return $res;
}

/** 取り消す。$series が true なら、同じ繰り返しの「この日以降」をまとめて取り消す。 */
function kvgw_res_delete($user, $id, $series = false) {
    $gone = array();
    $res = kv_update('gw_reservations', 'reservations', function (&$rs) use ($user, $id, $series, &$gone) {
        $target = null;
        foreach ($rs as $r) { if ($r['id'] === $id) { $target = $r; break; } }
        if (!$target) { return '予約が見つかりません'; }
        if (!kvgw_fac_can($user, 'delete', $target)) { return 'この予約を取り消す権限がありません'; }
        $keep = array();
        foreach ($rs as $r) {
            $same = ($r['id'] === $id)
                 || ($series && $target['series'] !== '' && $r['series'] === $target['series']
                     && $r['date'] >= $target['date'] && kvgw_fac_can($user, 'delete', $r));
            if ($same) { $gone[] = $r; } else { $keep[] = $r; }
        }
        $rs = $keep;
        return count($gone);
    });
    if ($res[0]) { foreach ($gone as $r) { kvgw_res_sync_schedule($r, true); } }
    return $res;
}

/* ============================================================
 * 画面と操作
 * ============================================================ */

function kvgw_facility_route($user) {
    $a = isset($_GET['a']) ? preg_replace('/[^a-z]/', '', (string)$_GET['a']) : '';
    $id = isset($_REQUEST['id']) ? preg_replace('/[^a-z0-9]/', '', (string)$_REQUEST['id']) : '';

    if ($a === 'save') {
        kvgw_need_post();
        $res = $id === '' ? kvgw_res_create($user, $_POST) : kvgw_res_update($user, $id, $_POST);
        if (!$res[0]) { http_response_code(400); kvgw_facility_form($user, $id, $_POST, $res[1]); return; }
        $date = $id === '' ? $res[1][0]['date'] : $res[1]['date'];
        kvgw_go('facility', array('d' => $date, 'msg' => $id === '' ? 'reserved' : 'saved'));
    }
    if ($a === 'delete') {
        kvgw_need_post();
        $r = kvgw_res_find($id);
        $res = kvgw_res_delete($user, $id, !empty($_POST['series']));
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('facility', array('d' => $r ? $r['date'] : '', 'msg' => 'canceled'));
    }
    if ($a === 'new' || $a === 'edit') {
        $in = array('facility' => kvgw_in($_GET, 'f', 20), 'date' => kvgw_in($_GET, 'd', 10),
                    'start' => kvgw_in($_GET, 's', 5), 'end' => '', 'title' => '', 'memo' => '');
        if (!kvgw_date_ok($in['date'])) { $in['date'] = kvgw_today(); }
        if ($a === 'edit') {
            $r = kvgw_res_find($id);
            if (!$r) { kv_fail(404, '予約が見つかりません。'); }
            $in = $r;
        } else {
            $id = '';
            if (!kvgw_time_ok($in['start'])) { $in['start'] = '10:00'; }
            $in['end'] = date('H:i', strtotime($in['start']) + 3600);
        }
        kvgw_facility_form($user, $id, $in, array());
        return;
    }
    if ($a === 'master' || $a === 'fsave' || $a === 'fdelete') {
        if (!kvgw_fac_can($user, 'manage')) { kv_fail(403, '施設を登録できるのは管理者だけです。'); }
        $fid = isset($_REQUEST['fid']) ? preg_replace('/[^a-z0-9]/', '', (string)$_REQUEST['fid']) : '';
        if ($a === 'fsave') {
            kvgw_need_post();
            $in = $_POST;
            $in['active'] = !empty($_POST['active']);
            $res = kvgw_fac_save($user, $fid, $in);
            if (!$res[0]) { http_response_code(400); kvgw_facility_master($user, $fid, $_POST, $res[1]); return; }
            kvgw_go('facility', array('a' => 'master', 'msg' => 'saved'));
        }
        if ($a === 'fdelete') {
            kvgw_need_post();
            $res = kvgw_fac_delete($user, $fid);
            if (!$res[0]) { http_response_code(400); kvgw_facility_master($user, '', array(), $res[1]); return; }
            kvgw_go('facility', array('a' => 'master', 'msg' => 'deleted'));
        }
        kvgw_facility_master($user, $fid, array(), '');
        return;
    }
    kvgw_facility_index($user);
}

function kvgw_minutes($t) { return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); }

function kvgw_facility_index($user) {
    $view = isset($_GET['v']) && $_GET['v'] === 'week' ? 'week' : 'day';
    $date = kvgw_in($_GET, 'd', 10);
    if (!kvgw_date_ok($date)) { $date = kvgw_today(); }
    $facs = kvgw_fac_all();
    $step = $view === 'week' ? 7 : 1;
    $prev = date('Y-m-d', strtotime($date . ' -' . $step . ' days'));
    $next = date('Y-m-d', strtotime($date . ' +' . $step . ' days'));

    kv_head('施設予約', $user);
    kv_nav($user, 'gw:facility');
    echo '<main class="kv-wrap"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow">FACILITY</p><h1 class="kv-title"><span class="kv-emoji">🏢</span>施設予約</h1>'
       . '<p class="kv-lead">会議室・社用車・備品の空きを見て、その場で押さえます。同じ時間に2つの予約は入りません。</p>';
    echo '<div class="kv-bar"><a class="kv-btn" href="' . kv_h(kvgw_url('facility', array('a' => 'new', 'd' => $date))) . '">＋ 予約する</a>';
    if (kvgw_fac_can($user, 'manage')) {
        echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('facility', array('a' => 'master'))) . '">施設の登録</a>';
    }
    echo '</div>';
    echo '<div class="gw-tabs"><a href="' . kv_h(kvgw_url('facility', array('d' => $date))) . '"' . ($view === 'day' ? ' class="on"' : '') . '>1日</a>'
       . '<a href="' . kv_h(kvgw_url('facility', array('v' => 'week', 'd' => $date))) . '"' . ($view === 'week' ? ' class="on"' : '') . '>1週間</a></div>';

    $vp = $view === 'week' ? array('v' => 'week') : array();
    echo '<div class="gw-datebar"><h2>' . kv_h(substr($date, 0, 4) . '年 ' . kvgw_day_label($date)) . ($view === 'week' ? ' の週' : '') . '</h2>'
       . '<a class="kv-btn sub" href="' . kv_h(kvgw_url('facility', $vp + array('d' => $prev))) . '">前へ</a>'
       . '<a class="kv-btn sub" href="' . kv_h(kvgw_url('facility', $vp + array('d' => kvgw_today()))) . '">今日</a>'
       . '<a class="kv-btn sub" href="' . kv_h(kvgw_url('facility', $vp + array('d' => $next))) . '">次へ</a>'
       . '<form method="get" action="' . kv_h(kvgw_self()) . '"><input type="hidden" name="m" value="facility">'
       . ($view === 'week' ? '<input type="hidden" name="v" value="week">' : '')
       . '<input type="date" name="d" value="' . kv_h($date) . '" aria-label="日付を選ぶ"><button class="kv-btn sub" type="submit">表示</button></form></div>';

    if (!$facs) {
        echo '<div class="kv-empty"><span class="kv-emoji">🏢</span>施設がまだ登録されていません。'
           . (kvgw_fac_can($user, 'manage') ? '「施設の登録」から、会議室や社用車を登録してください。' : '管理者に登録を頼んでください。') . '</div>';
    } else if ($view === 'day') {
        kvgw_facility_day($user, $facs, $date);
    } else {
        kvgw_facility_week($user, $facs, $date);
    }
    echo '</div></main>';
    kv_foot();
}

function kvgw_facility_day($user, $facs, $date) {
    $res = kvgw_res_on($date);
    $from = (int)kvgw_conf('KVGW_DAY_START', 8) * 60;
    $to = (int)kvgw_conf('KVGW_DAY_END', 20) * 60;
    foreach ($res as $r) {   // 枠の外の予約があれば、その日だけ枠を広げる
        $from = min($from, (int)floor(kvgw_minutes($r['start']) / 60) * 60);
        $to = max($to, (int)ceil(kvgw_minutes($r['end']) / 60) * 60);
    }
    $span = max(60, $to - $from);
    $hours = (int)($span / 60);
    echo '<p class="gw-legend"><span><i></i>予約あり</span><span><i class="mine"></i>自分の予約</span>'
       . '<span>目盛りは ' . (int)($from / 60) . '時〜' . (int)($to / 60) . '時</span></p>';
    $group = null;
    foreach ($facs as $f) {
        $mine = array();
        foreach ($res as $r) { if ($r['facility'] === $f['id']) { $mine[] = $r; } }
        if (empty($f['active']) && !$mine) { continue; }
        if ($f['group'] !== $group) { $group = $f['group']; echo '<h3 class="gw-facgroup">' . kv_h($group) . '</h3>'; }
        echo '<div class="gw-fac"><div class="gw-fachead"><b>' . kv_h($f['name']) . '</b>'
           . ($f['note'] !== '' ? '<small>' . kv_h($f['note']) . '</small>' : '');
        if (!empty($f['active']) && $date >= kvgw_today()) {
            echo '<a class="kv-link" href="' . kv_h(kvgw_url('facility', array('a' => 'new', 'f' => $f['id'], 'd' => $date))) . '">ここを予約</a>';
        } else if (empty($f['active'])) {
            echo '<span class="kv-tag" style="margin-left:auto">予約停止中</span>';
        }
        echo '</div><div class="gw-track" style="--h:' . round(100 / $hours, 4) . '%">';
        foreach ($mine as $r) {
            $l = (kvgw_minutes($r['start']) - $from) / $span * 100;
            $w = (kvgw_minutes($r['end']) - kvgw_minutes($r['start'])) / $span * 100;
            echo '<span class="gw-busy' . ($r['by'] === $user['id'] ? ' mine' : '') . '" style="left:' . round($l, 3) . '%;width:'
               . round($w, 3) . '%" title="' . kv_h($r['start'] . '–' . $r['end'] . ' ' . $r['title']) . '"></span>';
        }
        echo '</div><div class="gw-scale">';
        $tick = $hours > 8 ? 2 : 1;
        for ($h = (int)($from / 60); $h <= (int)($to / 60); $h += $tick) { echo '<span>' . $h . '</span>'; }
        echo '</div>';
        foreach ($mine as $r) {
            echo '<a class="gw-res" href="' . kv_h(kvgw_url('facility', array('a' => 'edit', 'id' => $r['id']))) . '">'
               . '<time>' . kv_h($r['start'] . '–' . $r['end']) . '</time><span>' . kv_h($r['title']) . '</span>'
               . '<small>' . kv_h($r['by_name']) . '</small></a>';
        }
        if (!$mine) { echo '<p class="gw-none" style="margin:0">この日は空いています</p>'; }
        echo '</div>';
    }
}

function kvgw_facility_week($user, $facs, $date) {
    $w = (int)date('w', strtotime($date));
    $mon = date('Y-m-d', strtotime($date . ' -' . (($w + 6) % 7) . ' days'));
    $days = array();
    for ($i = 0; $i < 7; $i++) { $days[] = date('Y-m-d', strtotime($mon . ' +' . $i . ' days')); }
    $by = array();
    foreach (kvgw_res_all() as $r) {
        if ($r['date'] < $days[0] || $r['date'] > $days[6]) { continue; }
        $by[$r['facility']][$r['date']][] = $r;
    }
    echo '<div class="kv-tablewrap"><table class="gw-week"><thead><tr><th>施設</th>';
    foreach ($days as $d) {
        echo '<th' . ($d === kvgw_today() ? ' class="today"' : '') . '><a style="text-decoration:none;color:inherit" href="'
           . kv_h(kvgw_url('facility', array('d' => $d))) . '">' . kv_h(kvgw_day_label($d)) . '</a></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($facs as $f) {
        if (empty($f['active']) && empty($by[$f['id']])) { continue; }
        echo '<tr><th style="white-space:normal;min-width:96px">' . kv_h($f['name']) . '<br><small style="font-weight:500;color:var(--muted)">'
           . kv_h($f['group']) . '</small></th>';
        foreach ($days as $d) {
            echo '<td>';
            $list = isset($by[$f['id']][$d]) ? $by[$f['id']][$d] : array();
            usort($list, function ($a, $b) { return strcmp($a['start'], $b['start']); });
            foreach ($list as $r) {
                echo '<a href="' . kv_h(kvgw_url('facility', array('a' => 'edit', 'id' => $r['id']))) . '"><b>'
                   . kv_h($r['start'] . '–' . $r['end']) . '</b>' . kv_h($r['title']) . '<br><small style="color:var(--muted)">'
                   . kv_h($r['by_name']) . '</small></a>';
            }
            if (!empty($f['active']) && $d >= kvgw_today()) {
                echo '<a class="gw-add" href="' . kv_h(kvgw_url('facility', array('a' => 'new', 'f' => $f['id'], 'd' => $d))) . '">＋予約</a>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function kvgw_facility_form($user, $id, $in, $errors) {
    $r = $id !== '' ? kvgw_res_find($id) : null;
    if ($id !== '' && !$r) { kv_fail(404, '予約が見つかりません。'); }
    $canEdit = !$r || kvgw_fac_can($user, 'update', $r);
    $e = is_array($errors) ? $errors : array('clash' => (string)$errors);
    $val = function ($k) use ($in) { return isset($in[$k]) && !is_array($in[$k]) ? (string)$in[$k] : ''; };
    $err = function ($k) use ($e) { return isset($e[$k]) ? '<p class="kv-error">' . kv_h($e[$k]) . '</p>' : ''; };
    $ro = $canEdit ? '' : ' disabled';

    kv_head(($r ? '予約の内容' : '予約する') . '｜施設予約', $user);
    kv_nav($user, 'gw:facility');
    echo '<main class="kv-wrap kv-narrow" style="max-width:680px"><div class="kv-card">'
       . '<p class="kv-eyebrow">FACILITY</p><h1 class="kv-title" style="margin-bottom:18px"><span class="kv-emoji">🏢</span>'
       . ($r ? ($canEdit ? '予約を変更する' : '予約の内容') : '予約する') . '</h1>';
    if (isset($e['clash'])) { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($e['clash']) . '</p>'; }
    else if ($e) { echo '<p class="kv-error" style="margin-bottom:14px">入力に誤りがあります。赤字の項目を直してください。</p>'; }
    if ($r && !$canEdit) {
        echo '<p class="kv-hint" style="margin:0 0 14px">' . kv_h($r['by_name']) . 'さんの予約です。変更・取り消しは、本人か部署の責任者・管理者ができます。</p>';
    }

    echo '<form method="post" action="' . kv_h(kvgw_url('facility', array('a' => 'save'))) . '">' . kvgw_csrf()
       . '<input type="hidden" name="id" value="' . kv_h($id) . '">';
    echo '<div class="kv-field"><label for="gw-fac">施設<span class="kv-req">必須</span></label><select id="gw-fac" name="facility"' . $ro . '>';
    $group = null;
    foreach (kvgw_fac_all() as $f) {
        if (empty($f['active']) && $f['id'] !== $val('facility')) { continue; }
        if ($f['group'] !== $group) {
            if ($group !== null) { echo '</optgroup>'; }
            $group = $f['group'];
            echo '<optgroup label="' . kv_h($group) . '">';
        }
        echo '<option value="' . kv_h($f['id']) . '"' . ($f['id'] === $val('facility') ? ' selected' : '') . '>'
           . kv_h($f['name'] . ($f['note'] !== '' ? '（' . $f['note'] . '）' : '')) . '</option>';
    }
    if ($group !== null) { echo '</optgroup>'; }
    echo '</select>' . $err('facility') . '</div>';
    echo '<div class="kv-field"><label for="gw-d">日付<span class="kv-req">必須</span></label>'
       . '<input id="gw-d" type="date" name="date" required value="' . kv_h($val('date')) . '"' . $ro . '>' . $err('date') . '</div>';
    echo '<div class="gw-two"><div class="kv-field"><label for="gw-st">開始<span class="kv-req">必須</span></label>'
       . '<input id="gw-st" type="time" name="start" step="300" required value="' . kv_h($val('start')) . '"' . $ro . '>' . $err('start') . '</div>'
       . '<div class="kv-field"><label for="gw-en">終了<span class="kv-req">必須</span></label>'
       . '<input id="gw-en" type="time" name="end" step="300" required value="' . kv_h($val('end')) . '"' . $ro . '>' . $err('end') . '</div></div>';
    echo '<div class="kv-field"><label for="gw-t">利用目的<span class="kv-req">必須</span></label>'
       . '<input id="gw-t" type="text" name="title" maxlength="80" required placeholder="例) A社 定例打合せ" value="' . kv_h($val('title')) . '"' . $ro . '>'
       . $err('title') . '</div>';
    echo '<div class="kv-field"><label for="gw-m">メモ</label><textarea id="gw-m" name="memo" rows="2" maxlength="500"' . $ro . '>'
       . kv_h($val('memo')) . '</textarea></div>';
    if (!$r) {
        echo '<div class="gw-two"><div class="kv-field"><label for="gw-r">繰り返し</label><select id="gw-r" name="repeat">';
        foreach (kvgw_res_repeats() as $k => $label) {
            echo '<option value="' . kv_h($k) . '"' . ($val('repeat') === $k ? ' selected' : '') . '>' . kv_h($label) . '</option>';
        }
        echo '</select></div><div class="kv-field"><label for="gw-n">回数</label>'
           . '<input id="gw-n" type="number" name="count" min="1" max="30" value="' . kv_h($val('count') !== '' ? $val('count') : '1') . '">'
           . $err('count') . '<p class="kv-hint">繰り返すときの回数（30回まで）</p></div></div>';
        $app = kv_app('schedule');
        if ($app && kv_can($user, $app, 'create')) {
            echo '<div class="kv-field"><label class="kv-choice" style="display:inline-flex"><input type="checkbox" name="to_schedule" value="1"'
               . (!isset($in['csrf']) || !empty($in['to_schedule']) ? ' checked' : '') . '>共有スケジュールにも自分の予定として載せる</label></div>';
        }
    } else if ($r['sched'] !== '') {
        echo '<p class="kv-hint" style="margin-bottom:17px">この予約は共有スケジュールにも載っています。ここで変更・取り消しをすると、予定も一緒に変わります。</p>';
    }
    echo '<div class="kv-bar" style="margin-bottom:0">';
    if ($canEdit) { echo '<button class="kv-btn" type="submit">' . ($r ? '変更する' : '予約する') . '</button>'; }
    echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('facility', array('d' => $val('date')))) . '">' . ($canEdit ? 'やめる' : '戻る') . '</a></div></form>';

    if ($r) {
        echo '</div><div class="kv-card"><p class="kv-hint" style="margin:0 0 14px">予約した人: ' . kv_h($r['by_name']) . '（'
           . kv_h(kv_dept_name($r['dept'])) . '）' . kv_h(substr($r['created'], 0, 16))
           . ($r['series'] !== '' ? '　／　繰り返しで入れた予約です' : '') . '</p>';
        if (kvgw_fac_can($user, 'delete', $r)) {
            echo '<form method="post" action="' . kv_h(kvgw_url('facility', array('a' => 'delete', 'id' => $id)))
               . '" onsubmit="return confirm(\'この予約を取り消します。よろしいですか\')">' . kvgw_csrf();
            if ($r['series'] !== '') {
                echo '<div class="kv-field"><label class="kv-choice" style="display:inline-flex"><input type="checkbox" name="series" value="1">'
                   . 'この日以降の繰り返しも、まとめて取り消す</label></div>';
            }
            echo '<button class="kv-btn danger" type="submit">予約を取り消す</button></form>';
        }
    }
    echo '</div></main>';
    kv_foot();
}

/** 施設の登録(管理者)。一覧と入力欄を1画面に置く。 */
function kvgw_facility_master($user, $fid, $in, $error) {
    $edit = $fid !== '' ? kvgw_fac_find($fid) : null;
    $src = $in ? $in : ($edit ? $edit : array('order' => 10, 'active' => true));
    $val = function ($k) use ($src) { return isset($src[$k]) && !is_array($src[$k]) ? (string)$src[$k] : ''; };
    $active = $in ? !empty($in['active']) : (!$edit || !empty($edit['active']));
    $facs = kvgw_fac_all();
    $groups = array();
    foreach ($facs as $f) { $groups[$f['group']] = true; }

    kv_head('施設の登録｜施設予約', $user);
    kv_nav($user, 'gw:facility');
    echo '<main class="kv-wrap" style="max-width:860px"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('facility')) . '" style="text-decoration:none">← 施設予約</a></p>'
       . '<h1 class="kv-title" style="margin-bottom:16px"><span class="kv-emoji">🏢</span>施設の登録</h1>';
    if ($error !== '') { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($error) . '</p>'; }
    if (!$facs) { echo '<p class="kv-hint">まだ1つも登録されていません。下の欄から登録してください。</p>'; }
    else {
        echo '<div class="gw-rows">';
        foreach ($facs as $f) {
            echo '<a class="gw-row" href="' . kv_h(kvgw_url('facility', array('a' => 'master', 'fid' => $f['id']))) . '">'
               . '<span class="gw-rowmain"><b style="font-weight:800">' . kv_h($f['name'])
               . (empty($f['active']) ? ' <span class="kv-tag">予約停止中</span>' : '') . '</b><small>'
               . kv_h($f['group'] . ($f['note'] !== '' ? '　・　' . $f['note'] : '')) . '</small></span>'
               . '<span class="gw-rowside">変更</span></a>';
        }
        echo '</div>';
    }
    echo '</div><div class="kv-card"><h2 style="font-size:16px;margin-bottom:14px">' . ($edit ? '「' . kv_h($edit['name']) . '」を変更' : '施設を足す') . '</h2>'
       . '<form method="post" action="' . kv_h(kvgw_url('facility', array('a' => 'fsave'))) . '">' . kvgw_csrf()
       . '<input type="hidden" name="fid" value="' . kv_h($edit ? $edit['id'] : '') . '">'
       . '<div class="gw-two"><div class="kv-field"><label for="gw-fn">名前<span class="kv-req">必須</span></label>'
       . '<input id="gw-fn" type="text" name="name" maxlength="40" required placeholder="例) 第1会議室" value="' . kv_h($val('name')) . '"></div>'
       . '<div class="kv-field"><label for="gw-fg">グループ</label>'
       . '<input id="gw-fg" type="text" name="group" maxlength="30" list="gw-groups" placeholder="例) 会議室・社用車・備品" value="' . kv_h($val('group')) . '">'
       . '<datalist id="gw-groups">';
    foreach (array_keys($groups) as $g) { echo '<option value="' . kv_h($g) . '">'; }
    echo '</datalist></div></div>'
       . '<div class="gw-two"><div class="kv-field"><label for="gw-fo">定員・備考</label>'
       . '<input id="gw-fo" type="text" name="note" maxlength="80" placeholder="例) 8名・モニターあり" value="' . kv_h($val('note')) . '"></div>'
       . '<div class="kv-field"><label for="gw-fr">並び順</label>'
       . '<input id="gw-fr" type="number" name="order" min="0" max="999" value="' . kv_h($val('order') !== '' ? $val('order') : '10') . '">'
       . '<p class="kv-hint">小さい数字が上</p></div></div>'
       . '<div class="kv-field"><label class="kv-choice" style="display:inline-flex"><input type="checkbox" name="active" value="1"'
       . ($active ? ' checked' : '') . '>予約を受け付ける</label>'
       . '<p class="kv-hint">外すと新しい予約だけを止めます。入っている予約は残ります。</p></div>'
       . '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">' . ($edit ? '保存' : '登録') . '</button>';
    if ($edit) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('facility', array('a' => 'master'))) . '">やめる</a>'; }
    echo '</div></form>';
    if ($edit) {
        echo '<form method="post" style="margin-top:16px" action="' . kv_h(kvgw_url('facility', array('a' => 'fdelete')))
           . '" onsubmit="return confirm(\'この施設を削除します。過去の予約も消えます。よろしいですか\')">' . kvgw_csrf()
           . '<input type="hidden" name="fid" value="' . kv_h($edit['id']) . '"><button class="kv-btn danger" type="submit">この施設を削除</button></form>';
    }
    echo '</div></main>';
    kv_foot();
}
