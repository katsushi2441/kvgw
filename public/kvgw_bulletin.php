<?php
/**
 * 掲示板 — 全社・部署へのお知らせ。
 *
 * メッセージは「宛先の人だけ」、掲示板は「カテゴリが見える人の全員」。掲示する期間を決められる。
 *
 * 台帳:
 *   gw_bbs_cats            … カテゴリ(名前・見える範囲・書ける人・並び順)。管理者が決める。
 *   gw_bulletins           … 掲示(件名・本文・カテゴリ・掲示期間・コメント・添付の目録)
 *   gw_bbsstate_<利用者ID>  … その人が、どの掲示をどこまで読んだか。
 *   gw_files/              … 添付の実体(メッセージと同じ置き場所)。必ず kvgw_bbs_can() を通してから渡す。
 *
 * 未読の決め方: 掲示は、掲示と書き直しのたびに rev が1つ増える。自分が最後に開いたときの rev より
 *   大きければ未読。コメントでは全員を未読に戻さない(掲示した本人にだけ「新しいコメント」と出す)。
 */

/* ============================================================
 * 権限 — 掲示板についての判定はここだけ
 * ============================================================ */

function kvgw_bbs_can($user, $op, $b = null, $cat = null) {
    if (!$user) { return false; }
    $admin = ($user['role'] === 'admin');
    if ($op === 'manage') { return $admin; }                      // カテゴリを決める
    if ($op === 'post') {                                         // そのカテゴリに掲示する
        if (!kvgw_bbs_cat_visible($user, $cat)) { return false; }
        if ($cat['post'] === 'all') { return true; }
        if ($cat['post'] === 'chief') { return $admin || $user['role'] === 'chief'; }
        return $admin;
    }
    if (!$b) { return false; }
    $isFrom = ($b['from'] === $user['id']);
    $seen = kvgw_bbs_cat_visible($user, kvgw_bbs_cat_find($b['cat']));
    $live = (kvgw_bbs_phase($b) === 'now');
    switch ($op) {
        case 'read':    return $seen && ($live || $isFrom || $admin);   // 期間の外は、掲示した人と管理者だけ
        case 'comment': return $seen && $live && !empty($b['allow_comment']);
        case 'edit':    return $isFrom && $seen;
        case 'delete':  return ($isFrom || $admin) && $seen;
        case 'readers': return ($isFrom || $admin) && $seen;            // だれが読んだか
    }
    return false;
}

/** カテゴリが見える人か。部署を決めたカテゴリは、その部署の人と管理者だけ。 */
function kvgw_bbs_cat_visible($user, $cat) {
    if (!$user || !$cat) { return false; }
    return $cat['dept'] === '' || $user['role'] === 'admin' || $cat['dept'] === $user['dept'];
}

function kvgw_bbs_post_levels() { return array('all' => '全員', 'chief' => '責任者と管理者', 'admin' => '管理者だけ'); }

/** 掲示の時期。wait(これから) / now(掲示中) / over(期間が終わった) */
function kvgw_bbs_phase($b) {
    $today = kvgw_today();
    if ($b['start'] !== '' && $b['start'] > $today) { return 'wait'; }
    if ($b['end'] !== '' && $b['end'] < $today) { return 'over'; }
    return 'now';
}

/* ============================================================
 * カテゴリ
 * ============================================================ */

function kvgw_bbs_cats() {
    /* はじめて開いたときに、全員が書ける「お知らせ」を1つ作る(空の掲示板で止まらないように)。 */
    if (!file_exists(kv_path('gw_bbs_cats'))) {
        kv_update('gw_bbs_cats', 'cats', function (&$cs) {
            if ($cs) { return true; }
            $cs[] = array('id' => 'k' . kv_random_hex(6), 'name' => 'お知らせ', 'dept' => '', 'post' => 'all',
                          'order' => 10, 'created' => kv_now());
            return true;
        });
    }
    $cs = kv_load('gw_bbs_cats', 'cats');
    usort($cs, function ($a, $b) {
        if ($a['order'] !== $b['order']) { return $a['order'] < $b['order'] ? -1 : 1; }
        return strcmp($a['name'], $b['name']);
    });
    return $cs;
}

function kvgw_bbs_cat_find($id) {
    foreach (kvgw_bbs_cats() as $c) { if ($c['id'] === $id) { return $c; } }
    return null;
}

/** 自分に見えるカテゴリ。 */
function kvgw_bbs_cats_for($user) {
    $out = array();
    foreach (kvgw_bbs_cats() as $c) { if (kvgw_bbs_cat_visible($user, $c)) { $out[] = $c; } }
    return $out;
}

function kvgw_bbs_cat_save($user, $id, $in) {
    if (!kvgw_bbs_can($user, 'manage')) { return array(false, 'カテゴリを決められるのは管理者だけです'); }
    $name = kvgw_in($in, 'name', 30);
    $dept = kvgw_in($in, 'dept', 40);
    $post = kvgw_in($in, 'post', 10);
    $order = isset($in['order']) ? max(0, min(999, (int)$in['order'])) : 0;
    if ($name === '') { return array(false, 'カテゴリの名前を入れてください'); }
    $depts = kv_depts();
    if ($dept !== '' && !isset($depts[$dept])) { return array(false, '見える範囲を選び直してください'); }
    $levels = kvgw_bbs_post_levels();
    if (!isset($levels[$post])) { $post = 'all'; }
    kvgw_bbs_cats();   // 初回の「お知らせ」を先に作っておく
    return kv_update('gw_bbs_cats', 'cats', function (&$cs) use ($user, $id, $name, $dept, $post, $order) {
        if (!kvgw_bbs_can($user, 'manage')) { return 'カテゴリを決められるのは管理者だけです'; }
        foreach ($cs as $c) { if ($c['id'] !== $id && $c['name'] === $name) { return '同じ名前のカテゴリがすでにあります'; } }
        if ($id === '') {
            $c = array('id' => 'k' . kv_random_hex(6), 'name' => $name, 'dept' => $dept, 'post' => $post,
                       'order' => $order, 'created' => kv_now());
            $cs[] = $c;
            return $c;
        }
        foreach ($cs as $i => $c) {
            if ($c['id'] !== $id) { continue; }
            $cs[$i]['name'] = $name; $cs[$i]['dept'] = $dept; $cs[$i]['post'] = $post; $cs[$i]['order'] = $order;
            return $cs[$i];
        }
        return 'カテゴリが見つかりません';
    });
}

/** 掲示が残っているカテゴリは消せない(掲示が、だれにも見えないまま残るのを防ぐ)。 */
function kvgw_bbs_cat_delete($user, $id) {
    if (!kvgw_bbs_can($user, 'manage')) { return array(false, 'カテゴリを決められるのは管理者だけです'); }
    foreach (kvgw_bbs_all() as $b) {
        if ($b['cat'] === $id) { return array(false, 'このカテゴリには掲示が残っています。掲示を消すか、別のカテゴリに移してから削除してください'); }
    }
    return kv_update('gw_bbs_cats', 'cats', function (&$cs) use ($user, $id) {
        if (!kvgw_bbs_can($user, 'manage')) { return 'カテゴリを決められるのは管理者だけです'; }
        foreach ($cs as $i => $c) {
            if ($c['id'] === $id) { array_splice($cs, $i, 1); return true; }
        }
        return 'カテゴリが見つかりません';
    });
}

/* ============================================================
 * 掲示
 * ============================================================ */

function kvgw_bbs_all() { return kv_load('gw_bulletins', 'bulletins'); }

function kvgw_bbs_find($id) {
    foreach (kvgw_bbs_all() as $b) { if ($b['id'] === $id) { return $b; } }
    return null;
}

function kvgw_bbs_state_name($uid) { return 'gw_bbsstate_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$uid); }
function kvgw_bbs_state($uid) { return kv_load(kvgw_bbs_state_name($uid), 'state'); }

function kvgw_bbs_state_set($uid, $bid, $fields) {
    return kv_update(kvgw_bbs_state_name($uid), 'state', function (&$st) use ($bid, $fields) {
        if (!isset($st[$bid]) || !is_array($st[$bid])) { $st[$bid] = array(); }
        foreach ($fields as $k => $v) { $st[$bid][$k] = $v; }
        return true;
    });
}

/** 未読か。掲示・書き直しのあと、まだ開いていない(自分が書いたものは除く)。 */
function kvgw_bbs_is_unread($user, $b, $state) {
    if ($b['from'] === $user['id'] || kvgw_bbs_phase($b) !== 'now') { return false; }
    $seen = isset($state[$b['id']]['rev']) ? (int)$state[$b['id']]['rev'] : 0;
    return $seen < (int)$b['rev'];
}

/** 掲示した本人に、まだ見ていないコメントがあるか。 */
function kvgw_bbs_new_comments($user, $b, $state) {
    if ($b['from'] !== $user['id'] || !$b['comments']) { return 0; }
    $seen = isset($state[$b['id']]['nc']) ? (int)$state[$b['id']]['nc'] : 0;
    $last = $b['comments'][count($b['comments']) - 1];
    if ($last['by'] === $user['id']) { return 0; }
    return max(0, count($b['comments']) - $seen);
}

/**
 * 掲示する・書き直す。$id が空なら新規。
 * $in: cat, title, body, start, end, allow_comment, files(添付の目録), remove(外す添付のID)
 */
function kvgw_bbs_save($user, $id, $in) {
    $title = kvgw_in($in, 'title', 100);
    $body = kvgw_in($in, 'body', 20000);
    $catId = kvgw_in($in, 'cat', 20);
    $start = kvgw_in($in, 'start', 10);
    $end = kvgw_in($in, 'end', 10);
    $files = isset($in['files']) && is_array($in['files']) ? $in['files'] : array();
    $remove = isset($in['remove']) && is_array($in['remove']) ? $in['remove'] : array();
    $allow = !empty($in['allow_comment']);

    if ($title === '') { return array(false, '件名を入れてください'); }
    if ($start === '') { $start = kvgw_today(); }
    if (!kvgw_date_ok($start)) { return array(false, '掲示を始める日は、日付で入れてください'); }
    if ($end !== '' && !kvgw_date_ok($end)) { return array(false, '掲示を終える日は、日付で入れてください'); }
    if ($end !== '' && $end < $start) { return array(false, '掲示を終える日は、始める日より後にしてください'); }
    $cat = kvgw_bbs_cat_find($catId);
    if (!kvgw_bbs_can($user, 'post', null, $cat)) { return array(false, 'このカテゴリには掲示できません。カテゴリを選び直してください'); }

    $gone = array();
    $res = kv_update('gw_bulletins', 'bulletins', function (&$bs) use ($user, $id, $title, $body, $cat, $start, $end, $files, $remove, $allow, &$gone) {
        if (!kvgw_bbs_can($user, 'post', null, $cat)) { return 'このカテゴリには掲示できません'; }
        if ($id === '') {
            if ($body === '' && !$files) { return '本文を入れてください'; }
            $b = array(
                'id' => 'b' . kv_random_hex(8), 'cat' => $cat['id'], 'title' => $title, 'body' => $body,
                'from' => $user['id'], 'from_name' => $user['name'], 'dept' => $user['dept'],
                'start' => $start, 'end' => $end, 'allow_comment' => $allow,
                'files' => $files, 'comments' => array(),
                'created' => kv_now(), 'updated' => kv_now(), 'edited' => '', 'rev' => 1,
            );
            $bs[] = $b;
            return $b;
        }
        foreach ($bs as $i => $b) {
            if ($b['id'] !== $id) { continue; }
            if (!kvgw_bbs_can($user, 'edit', $b)) { return 'この掲示を書き直せるのは、掲示した人だけです'; }
            $keep = array();
            foreach ($b['files'] as $f) {
                if (in_array($f['id'], $remove, true)) { $gone[] = $f['id']; } else { $keep[] = $f; }
            }
            $all = array_merge($keep, $files);
            if ($body === '' && !$all) { $gone = array(); return '本文を入れてください'; }
            $b['cat'] = $cat['id']; $b['title'] = $title; $b['body'] = $body;
            $b['start'] = $start; $b['end'] = $end; $b['allow_comment'] = $allow; $b['files'] = $all;
            $b['updated'] = kv_now(); $b['edited'] = kv_now();
            $b['rev'] = (int)$b['rev'] + 1;   // 書き直すと、読んだ人にももう一度「未読」で出る
            $bs[$i] = $b;
            return $b;
        }
        return '掲示が見つかりません';
    });
    if ($res[0]) { foreach ($gone as $fid) { kvgw_file_remove($fid); } }
    return $res;
}

/** 消せるのは掲示した人と管理者。 */
function kvgw_bbs_delete($user, $id) {
    $gone = array();
    $res = kv_update('gw_bulletins', 'bulletins', function (&$bs) use ($user, $id, &$gone) {
        foreach ($bs as $i => $b) {
            if ($b['id'] !== $id) { continue; }
            if (!kvgw_bbs_can($user, 'delete', $b)) { return 'この掲示を削除する権限がありません'; }
            foreach ($b['files'] as $f) { $gone[] = $f['id']; }
            array_splice($bs, $i, 1);
            return true;
        }
        return '掲示が見つかりません';
    });
    if ($res[0]) { foreach ($gone as $fid) { kvgw_file_remove($fid); } }
    return $res;
}

function kvgw_bbs_comment($user, $id, $body) {
    $body = kvgw_in(array('b' => $body), 'b', 5000);
    if ($body === '') { return array(false, 'コメントを入れてください'); }
    return kv_update('gw_bulletins', 'bulletins', function (&$bs) use ($user, $id, $body) {
        foreach ($bs as $i => $b) {
            if ($b['id'] !== $id) { continue; }
            if (!kvgw_bbs_can($user, 'comment', $b)) { return 'この掲示にはコメントできません'; }
            $c = array('id' => 'c' . kv_random_hex(6), 'by' => $user['id'], 'by_name' => $user['name'],
                       'body' => $body, 'at' => kv_now());
            $bs[$i]['comments'][] = $c;
            return $c;
        }
        return '掲示が見つかりません';
    });
}

/** コメントを消せるのは、書いた本人と管理者。 */
function kvgw_bbs_comment_delete($user, $id, $cid) {
    return kv_update('gw_bulletins', 'bulletins', function (&$bs) use ($user, $id, $cid) {
        foreach ($bs as $i => $b) {
            if ($b['id'] !== $id) { continue; }
            if (!kvgw_bbs_can($user, 'read', $b)) { return 'この操作の権限がありません'; }
            foreach ($b['comments'] as $j => $c) {
                if ($c['id'] !== $cid) { continue; }
                if ($c['by'] !== $user['id'] && $user['role'] !== 'admin') { return '自分のコメントだけ削除できます'; }
                array_splice($bs[$i]['comments'], $j, 1);
                return true;
            }
            return 'コメントが見つかりません';
        }
        return '掲示が見つかりません';
    });
}

/**
 * 一覧。$view: now(掲示中) / mine(自分の掲示・期間の外も) / off(期間の外・管理者だけ)
 * 自分が読めるものだけを返す。各行に _unread・_newc・_phase を付ける。新しい順。
 */
function kvgw_bbs_list($user, $view, $catId, $q) {
    $state = kvgw_bbs_state($user['id']);
    $out = array();
    foreach (kvgw_bbs_all() as $b) {
        if (!kvgw_bbs_can($user, 'read', $b)) { continue; }
        $phase = kvgw_bbs_phase($b);
        if ($view === 'mine') { if ($b['from'] !== $user['id']) { continue; } }
        else if ($view === 'off') { if ($phase === 'now' || $user['role'] !== 'admin') { continue; } }
        else if ($phase !== 'now') { continue; }
        if ($catId !== '' && $b['cat'] !== $catId) { continue; }
        if ($q !== '') {
            $hay = $b['title'] . "\n" . $b['body'] . "\n" . $b['from_name'];
            foreach ($b['comments'] as $c) { $hay .= "\n" . $c['body'] . "\n" . $c['by_name']; }
            if (mb_stripos($hay, $q, 0, 'UTF-8') === false) { continue; }
        }
        $b['_phase'] = $phase;
        $b['_unread'] = kvgw_bbs_is_unread($user, $b, $state);
        $b['_newc'] = kvgw_bbs_new_comments($user, $b, $state);
        $out[] = $b;
    }
    usort($out, function ($a, $b) {
        $ka = max($a['start'] . ' 00:00:00', $a['updated']);
        $kb = max($b['start'] . ' 00:00:00', $b['updated']);
        return strcmp($kb, $ka);
    });
    return $out;
}

function kvgw_bbs_unread_count($user) {
    $n = 0;
    foreach (kvgw_bbs_list($user, 'now', '', '') as $b) { if ($b['_unread'] || $b['_newc'] > 0) { $n++; } }
    return $n;
}

/** その掲示が見える人(カテゴリの範囲)と、開いたかどうか。掲示した人と管理者だけが見る。 */
function kvgw_bbs_readers($b) {
    $cat = kvgw_bbs_cat_find($b['cat']);
    $out = array();
    foreach (kvgw_people() as $p) {
        if ($p['id'] === $b['from'] || !kvgw_bbs_cat_visible($p, $cat)) { continue; }
        $st = kvgw_bbs_state($p['id']);
        $out[] = array('name' => $p['name'], 'read' => isset($st[$b['id']]['rev']) && (int)$st[$b['id']]['rev'] >= (int)$b['rev']);
    }
    return $out;
}

/* ============================================================
 * 画面と操作
 * ============================================================ */

function kvgw_bulletin_route($user) {
    $a = isset($_GET['a']) ? preg_replace('/[^a-z]/', '', (string)$_GET['a']) : '';
    $id = isset($_REQUEST['id']) ? preg_replace('/[^a-z0-9]/', '', (string)$_REQUEST['id']) : '';

    if ($a === 'save') {
        kvgw_need_post();
        $in = array('cat' => kvgw_in($_POST, 'cat', 20), 'title' => isset($_POST['title']) ? $_POST['title'] : '',
                    'body' => isset($_POST['body']) ? $_POST['body'] : '',
                    'start' => kvgw_in($_POST, 'start', 10), 'end' => kvgw_in($_POST, 'end', 10),
                    'allow_comment' => !empty($_POST['allow_comment']),
                    'remove' => isset($_POST['remove']) && is_array($_POST['remove']) ? array_map('strval', $_POST['remove']) : array());
        $took = kvgw_files_take('files');
        if (!$took[0]) { http_response_code(400); kvgw_bulletin_form($user, $id, $in, $took[1]); return; }
        $in['files'] = $took[1];
        $res = kvgw_bbs_save($user, $id, $in);
        if (!$res[0]) {
            foreach ($took[1] as $f) { kvgw_file_remove($f['id']); }
            http_response_code(400);
            kvgw_bulletin_form($user, $id, $in, $res[1]);
            return;
        }
        kvgw_go('bulletin', array('a' => 'view', 'id' => $res[1]['id'], 'msg' => $id === '' ? 'posted' : 'saved'));
    }
    if ($a === 'delete') {
        kvgw_need_post();
        $res = kvgw_bbs_delete($user, $id);
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('bulletin', array('msg' => 'deleted'));
    }
    if ($a === 'comment') {
        kvgw_need_post();
        $res = kvgw_bbs_comment($user, $id, isset($_POST['body']) ? $_POST['body'] : '');
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('bulletin', array('a' => 'view', 'id' => $id, 'msg' => 'comment'));
    }
    if ($a === 'uncomment') {
        kvgw_need_post();
        $cid = isset($_POST['cid']) ? preg_replace('/[^a-z0-9]/', '', (string)$_POST['cid']) : '';
        $res = kvgw_bbs_comment_delete($user, $id, $cid);
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('bulletin', array('a' => 'view', 'id' => $id, 'msg' => 'deleted'));
    }
    if ($a === 'catsave' || $a === 'catdelete') {
        kvgw_need_post();
        if (!kvgw_bbs_can($user, 'manage')) { kv_fail(403, 'カテゴリを決められるのは管理者だけです。'); }
        $res = $a === 'catsave' ? kvgw_bbs_cat_save($user, $id, $_POST) : kvgw_bbs_cat_delete($user, $id);
        if (!$res[0]) { http_response_code(400); kvgw_bulletin_cats($user, $res[1]); return; }
        kvgw_go('bulletin', array('a' => 'cats', 'msg' => $a === 'catsave' ? 'saved' : 'deleted'));
    }
    if ($a === 'cats') {
        if (!kvgw_bbs_can($user, 'manage')) { kv_fail(403, 'カテゴリを決められるのは管理者だけです。'); }
        kvgw_bulletin_cats($user, '');
        return;
    }
    if ($a === 'file') {
        $b = kvgw_bbs_find($id);
        if (!kvgw_bbs_can($user, 'read', $b)) { kv_fail(404, 'ファイルが見つからないか、受け取る権限がありません。'); }
        kvgw_file_send($b['files'], isset($_GET['fid']) ? $_GET['fid'] : '');
        return;
    }
    if ($a === 'new' || $a === 'edit') {
        $in = array('cat' => kvgw_in($_GET, 'c', 20), 'title' => '', 'body' => '', 'start' => kvgw_today(), 'end' => '', 'allow_comment' => true);
        if ($a === 'edit') {
            $b = kvgw_bbs_find($id);
            if (!kvgw_bbs_can($user, 'edit', $b)) { kv_fail(404, '掲示が見つからないか、書き直す権限がありません。'); }
            $in = $b;
        } else { $id = ''; }
        kvgw_bulletin_form($user, $id, $in, '');
        return;
    }
    if ($a === 'view') { kvgw_bulletin_view($user, $id); return; }
    kvgw_bulletin_index($user);
}

function kvgw_bulletin_index($user) {
    $views = array('now' => '掲示中', 'mine' => '自分の掲示');
    if ($user['role'] === 'admin') { $views['off'] = '期間の外'; }
    $v = isset($_GET['v']) && isset($views[$_GET['v']]) ? (string)$_GET['v'] : 'now';
    $cats = kvgw_bbs_cats_for($user);
    $catId = kvgw_in($_GET, 'c', 20);
    $names = array();
    foreach ($cats as $c) { $names[$c['id']] = $c['name']; }
    if (!isset($names[$catId])) { $catId = ''; }
    $q = kvgw_in($_GET, 'q', 60);
    $list = kvgw_bbs_list($user, $v, $catId, $q);
    $per = 30;
    $page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
    $pages = max(1, (int)ceil(count($list) / $per));
    if ($page > $pages) { $page = $pages; }
    $rows = array_slice($list, ($page - 1) * $per, $per);
    $canPost = false;
    foreach ($cats as $c) { if (kvgw_bbs_can($user, 'post', null, $c)) { $canPost = true; } }

    kv_head('掲示板', $user);
    kv_nav($user, 'gw:bulletin');
    echo '<main class="kv-wrap"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow">BULLETIN</p>'
       . '<h1 class="kv-title"><span class="kv-emoji">📌</span>掲示板</h1>'
       . '<p class="kv-lead">全社・部署へのお知らせを貼り出します。掲示する期間を決められます。</p>';
    echo '<div class="kv-bar">';
    if ($canPost) { echo '<a class="kv-btn" href="' . kv_h(kvgw_url('bulletin', array('a' => 'new', 'c' => $catId))) . '">＋ 掲示する</a>'; }
    echo '<form method="get" action="' . kv_h(kvgw_self()) . '"><input type="hidden" name="m" value="bulletin">'
       . '<input type="hidden" name="v" value="' . kv_h($v) . '"><input type="hidden" name="c" value="' . kv_h($catId) . '">'
       . '<input type="search" name="q" value="' . kv_h($q) . '" placeholder="件名・本文・コメントから探す" aria-label="掲示を探す">'
       . '<button class="kv-btn sub" type="submit">探す</button></form>';
    if (kvgw_bbs_can($user, 'manage')) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('bulletin', array('a' => 'cats'))) . '">カテゴリを決める</a>'; }
    echo '</div><div class="gw-tabs">';
    foreach ($views as $k => $label) {
        echo '<a href="' . kv_h(kvgw_url('bulletin', array('v' => $k, 'c' => $catId))) . '"' . ($k === $v ? ' class="on"' : '') . '>' . kv_h($label) . '</a>';
    }
    echo '</div>';
    if (count($cats) > 1) {
        echo '<div class="gw-tabs" style="margin-top:-4px"><a href="' . kv_h(kvgw_url('bulletin', array('v' => $v))) . '"' . ($catId === '' ? ' class="on"' : '') . '>すべて</a>';
        foreach ($cats as $c) {
            echo '<a href="' . kv_h(kvgw_url('bulletin', array('v' => $v, 'c' => $c['id']))) . '"' . ($catId === $c['id'] ? ' class="on"' : '') . '>' . kv_h($c['name']) . '</a>';
        }
        echo '</div>';
    }

    if (!$rows) {
        $none = array('now' => 'いま掲示しているお知らせはありません。', 'mine' => '自分の掲示はまだありません。', 'off' => '期間の外の掲示はありません。');
        echo '<div class="kv-empty"><span class="kv-emoji">📌</span>' . kv_h($q !== '' ? '「' . $q . '」に合う掲示はありません。' : $none[$v]) . '</div>';
    } else {
        echo '<div class="gw-rows">';
        foreach ($rows as $b) {
            $bits = array(isset($names[$b['cat']]) ? $names[$b['cat']] : '', $b['from_name']);
            if ($b['end'] !== '') { $bits[] = kvgw_day_label($b['end']) . ' まで'; }
            if ($b['comments']) { $bits[] = 'コメント ' . count($b['comments']); }
            if ($b['files']) { $bits[] = '添付 ' . count($b['files']); }
            $tag = '';
            if ($b['_unread']) { $tag .= ' <span class="kv-tag gw-tagnew">未読</span>'; }
            if ($b['_newc'] > 0) { $tag .= ' <span class="kv-tag gw-tagnew">新しいコメント ' . (int)$b['_newc'] . '</span>'; }
            if ($b['_phase'] === 'wait') { $tag .= ' <span class="kv-tag">' . kv_h(kvgw_day_label($b['start'])) . ' から掲示</span>'; }
            if ($b['_phase'] === 'over') { $tag .= ' <span class="kv-tag">期間が終わった</span>'; }
            echo '<a class="gw-row' . (($b['_unread'] || $b['_newc'] > 0) ? ' unread' : '') . '" href="'
               . kv_h(kvgw_url('bulletin', array('a' => 'view', 'id' => $b['id']))) . '">'
               . '<span class="gw-dot"></span><span class="gw-rowmain"><b>' . kv_h($b['title']) . $tag . '</b>'
               . '<small>' . kv_h(implode('　・　', array_filter($bits, 'strlen'))) . '</small></span>'
               . '<span class="gw-rowside">' . kv_h(substr(max($b['start'] . ' 00:00:00', $b['created']), 5, 5)) . '</span></a>';
        }
        echo '</div>';
        if ($pages > 1) {
            echo '<div class="kv-bar" style="margin-bottom:0">';
            if ($page > 1) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('bulletin', array('v' => $v, 'c' => $catId, 'q' => $q, 'p' => $page - 1))) . '">前へ</a>'; }
            echo '<span class="kv-hint" style="margin:0">' . $page . ' / ' . $pages . ' ページ（' . count($list) . ' 件）</span>';
            if ($page < $pages) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('bulletin', array('v' => $v, 'c' => $catId, 'q' => $q, 'p' => $page + 1))) . '">次へ</a>'; }
            echo '</div>';
        }
    }
    echo '</div></main>';
    kv_foot();
}

function kvgw_bulletin_view($user, $id) {
    $b = kvgw_bbs_find($id);
    /* 無いのか、読めないのかを区別して返さない */
    if (!kvgw_bbs_can($user, 'read', $b)) { kv_fail(404, '掲示が見つからないか、見られる範囲に入っていません。'); }
    kvgw_bbs_state_set($user['id'], $id, array('rev' => (int)$b['rev'], 'nc' => count($b['comments']), 'read' => kv_now()));
    $cat = kvgw_bbs_cat_find($b['cat']);
    $phase = kvgw_bbs_phase($b);

    kv_head($b['title'] . '｜掲示板', $user);
    kv_nav($user, 'gw:bulletin');
    echo '<main class="kv-wrap" style="max-width:820px"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('bulletin')) . '" style="text-decoration:none">← 掲示板</a>　'
       . kv_h($cat ? $cat['name'] : '') . '</p>'
       . '<h1 class="kv-title" style="overflow-wrap:anywhere">' . kv_h($b['title']) . '</h1>'
       . '<p class="gw-meta">掲示した人 <b>' . kv_h($b['from_name']) . '</b>　' . kv_h(substr($b['created'], 0, 16))
       . ($b['edited'] !== '' ? '　（' . kv_h(substr($b['edited'], 5, 11)) . ' に書き直し）' : '') . '</p>'
       . '<p class="gw-meta">掲示する期間　' . kv_h(kvgw_day_label($b['start'])) . ' から'
       . ($b['end'] !== '' ? ' ' . kv_h(kvgw_day_label($b['end'])) . ' まで' : '（終わりは決めていません）')
       . ($phase === 'wait' ? '　<span class="kv-tag">まだ掲示していません</span>' : '')
       . ($phase === 'over' ? '　<span class="kv-tag">期間が終わりました</span>' : '') . '</p>';
    echo '<div class="gw-body">' . kv_h($b['body']) . '</div>';
    if ($b['files']) {
        echo '<div class="gw-files">';
        foreach ($b['files'] as $f) {
            echo '<a href="' . kv_h(kvgw_url('bulletin', array('a' => 'file', 'id' => $id, 'fid' => $f['id']))) . '">📎 '
               . kv_h($f['name']) . '（' . kv_h(kvgw_size_label($f['size'])) . '）</a>';
        }
        echo '</div>';
    }
    if (kvgw_bbs_can($user, 'readers', $b)) {
        $seen = '';
        $read = 0;
        $rs = kvgw_bbs_readers($b);
        foreach ($rs as $r) {
            if ($r['read']) { $read++; }
            $seen .= '<span' . ($r['read'] ? ' class="on"' : '') . '>' . kv_h($r['name']) . ($r['read'] ? ' 既読' : ' 未読') . '</span>';
        }
        echo '<details class="gw-fold" style="margin-top:18px"><summary>見える人 ' . count($rs) . '人（既読 ' . $read . '人）</summary>'
           . '<div class="gw-seen">' . $seen . '</div><p class="kv-hint">だれが読んだかは、掲示した人と管理者にだけ見えます。</p></details>';
    }
    echo '<div class="kv-bar" style="margin-bottom:0">';
    if (kvgw_bbs_can($user, 'edit', $b)) {
        echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('bulletin', array('a' => 'edit', 'id' => $id))) . '">書き直す</a>';
    }
    if (kvgw_bbs_can($user, 'delete', $b)) {
        echo '<form method="post" class="gw-inline" style="flex:0 0 auto;min-width:0" action="' . kv_h(kvgw_url('bulletin', array('a' => 'delete', 'id' => $id)))
           . '" onsubmit="return confirm(\'この掲示を削除します。全員の画面から消えます。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn danger" type="submit">削除</button></form>';
    }
    echo '</div></div>';

    if (!empty($b['allow_comment']) || $b['comments']) {
        echo '<div class="kv-card"><h2 style="font-size:16px;margin-bottom:6px">コメント（' . count($b['comments']) . '）</h2>';
        foreach ($b['comments'] as $c) {
            echo '<article class="gw-comment"><header><b>' . kv_h($c['by_name']) . '</b><span>' . kv_h(substr($c['at'], 0, 16)) . '</span>';
            if ($c['by'] === $user['id'] || $user['role'] === 'admin') {
                echo '<form method="post" class="gw-inline" action="' . kv_h(kvgw_url('bulletin', array('a' => 'uncomment', 'id' => $id)))
                   . '" onsubmit="return confirm(\'このコメントを削除します。よろしいですか\')">' . kvgw_csrf()
                   . '<input type="hidden" name="cid" value="' . kv_h($c['id']) . '"><button class="gw-textbtn" type="submit">削除</button></form>';
            }
            echo '</header><p>' . kv_h($c['body']) . '</p></article>';
        }
        if (kvgw_bbs_can($user, 'comment', $b)) {
            echo '<form method="post" action="' . kv_h(kvgw_url('bulletin', array('a' => 'comment', 'id' => $id))) . '" style="margin-top:12px">'
               . kvgw_csrf() . '<div class="kv-field"><label for="gw-c">コメントを書く</label>'
               . '<textarea id="gw-c" name="body" rows="3" maxlength="5000" required></textarea></div>'
               . '<button class="kv-btn" type="submit">書き込む</button></form>';
        }
        echo '</div>';
    }
    echo '</main>';
    kv_foot();
}

function kvgw_bulletin_form($user, $id, $in, $error) {
    $b = $id !== '' ? kvgw_bbs_find($id) : null;
    if ($id !== '' && !kvgw_bbs_can($user, 'edit', $b)) { kv_fail(404, '掲示が見つからないか、書き直す権限がありません。'); }
    $cats = array();
    foreach (kvgw_bbs_cats_for($user) as $c) { if (kvgw_bbs_can($user, 'post', null, $c)) { $cats[] = $c; } }
    if (!$cats) { kv_fail(403, '掲示できるカテゴリがありません。管理者にカテゴリを作ってもらってください。'); }
    $val = function ($k) use ($in) { return isset($in[$k]) && !is_array($in[$k]) ? (string)$in[$k] : ''; };
    $depts = kv_depts();

    kv_head(($b ? '書き直す' : '掲示する') . '｜掲示板', $user);
    kv_nav($user, 'gw:bulletin');
    echo '<main class="kv-wrap" style="max-width:760px"><div class="kv-card">'
       . '<p class="kv-eyebrow">BULLETIN</p><h1 class="kv-title" style="margin-bottom:18px"><span class="kv-emoji">📌</span>'
       . ($b ? '掲示を書き直す' : '掲示する') . '</h1>';
    if ($error !== '') { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($error) . '</p>'; }
    echo '<form method="post" enctype="multipart/form-data" action="' . kv_h(kvgw_url('bulletin', array('a' => 'save'))) . '">'
       . kvgw_csrf() . '<input type="hidden" name="id" value="' . kv_h($id) . '">';
    echo '<div class="kv-field"><label for="gw-bc">カテゴリ<span class="kv-req">必須</span></label><select id="gw-bc" name="cat">';
    foreach ($cats as $c) {
        echo '<option value="' . kv_h($c['id']) . '"' . ($val('cat') === $c['id'] ? ' selected' : '') . '>' . kv_h($c['name'])
           . '（' . kv_h($c['dept'] === '' ? '全員に見える' : kv_dept_name($c['dept']) . 'だけに見える') . '）</option>';
    }
    echo '</select></div>';
    echo '<div class="kv-field"><label for="gw-bt">件名<span class="kv-req">必須</span></label>'
       . '<input id="gw-bt" type="text" name="title" maxlength="100" required value="' . kv_h(kvgw_in($in, 'title', 100)) . '"></div>';
    echo '<div class="kv-field"><label for="gw-bb">本文</label>'
       . '<textarea id="gw-bb" name="body" rows="10" maxlength="20000">' . kv_h(kvgw_in($in, 'body', 20000)) . '</textarea></div>';
    echo '<div class="gw-two"><div class="kv-field"><label for="gw-bs">掲示を始める日</label>'
       . '<input id="gw-bs" type="date" name="start" value="' . kv_h($val('start')) . '"></div>'
       . '<div class="kv-field"><label for="gw-be">掲示を終える日</label>'
       . '<input id="gw-be" type="date" name="end" value="' . kv_h($val('end')) . '">'
       . '<p class="kv-hint">空のままなら、消すまで掲示します</p></div></div>';
    if ($b && $b['files']) {
        echo '<div class="kv-field"><label>いまの添付</label><div class="kv-choices">';
        foreach ($b['files'] as $f) {
            echo '<label class="kv-choice"><input type="checkbox" name="remove[]" value="' . kv_h($f['id']) . '">' . kv_h($f['name']) . ' を外す</label>';
        }
        echo '</div></div>';
    }
    if (kvgw_upload_on()) {
        echo '<div class="kv-field"><label for="gw-bf">添付ファイル</label><input id="gw-bf" type="file" name="files[]" multiple>'
           . '<p class="kv-hint">1回に5個まで・1個 ' . kvgw_upload_max_mb() . 'MB まで。</p></div>';
    } else if (kv_is_demo()) {
        echo '<p class="kv-hint" style="margin-bottom:17px">デモではファイルの添付を止めています。製品では、掲示にファイルを添付できます。</p>';
    }
    echo '<div class="kv-field"><label class="kv-choice" style="display:inline-flex"><input type="checkbox" name="allow_comment" value="1"'
       . (!empty($in['allow_comment']) ? ' checked' : '') . '>コメントを受け付ける</label></div>';
    if ($b) { echo '<p class="kv-hint" style="margin-bottom:14px">書き直すと、すでに読んだ人にも、もう一度「未読」で出ます。</p>'; }
    echo '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">' . ($b ? '保存する' : '掲示する') . '</button>'
       . '<a class="kv-btn sub" href="' . kv_h($b ? kvgw_url('bulletin', array('a' => 'view', 'id' => $id)) : kvgw_url('bulletin')) . '">やめる</a></div></form>';
    echo '</div></main>';
    kv_foot();
}

/** カテゴリを決める画面(管理者)。 */
function kvgw_bulletin_cats($user, $error) {
    $cats = kvgw_bbs_cats();
    $depts = kv_depts();
    $levels = kvgw_bbs_post_levels();
    $count = array();
    foreach (kvgw_bbs_all() as $b) { $count[$b['cat']] = isset($count[$b['cat']]) ? $count[$b['cat']] + 1 : 1; }
    $row = function ($c) use ($depts, $levels) {
        $h = '<div class="gw-two"><div class="kv-field"><label>名前<span class="kv-req">必須</span></label>'
           . '<input type="text" name="name" maxlength="30" required value="' . kv_h($c['name']) . '"></div>'
           . '<div class="kv-field"><label>並び順</label><input type="number" name="order" min="0" max="999" value="' . kv_h($c['order']) . '"></div></div>'
           . '<div class="gw-two"><div class="kv-field"><label>見える範囲</label><select name="dept">'
           . '<option value="">全員</option>';
        foreach ($depts as $k => $name) {
            $h .= '<option value="' . kv_h($k) . '"' . ($c['dept'] === (string)$k ? ' selected' : '') . '>' . kv_h($name) . 'だけ</option>';
        }
        $h .= '</select></div><div class="kv-field"><label>掲示できる人</label><select name="post">';
        foreach ($levels as $k => $name) {
            $h .= '<option value="' . kv_h($k) . '"' . ($c['post'] === $k ? ' selected' : '') . '>' . kv_h($name) . '</option>';
        }
        return $h . '</select></div></div>';
    };

    kv_head('カテゴリを決める｜掲示板', $user);
    kv_nav($user, 'gw:bulletin');
    echo '<main class="kv-wrap" style="max-width:760px"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('bulletin')) . '" style="text-decoration:none">← 掲示板</a></p>'
       . '<h1 class="kv-title"><span class="kv-emoji">📌</span>カテゴリを決める</h1>'
       . '<p class="kv-lead">カテゴリごとに、見える範囲と、掲示できる人を決めます。部署を選んだカテゴリは、その部署の人と管理者にだけ見えます。</p>';
    if ($error !== '') { echo '<p class="kv-error" style="margin:14px 0 0">' . kv_h($error) . '</p>'; }
    echo '</div>';
    foreach ($cats as $c) {
        $n = isset($count[$c['id']]) ? $count[$c['id']] : 0;
        echo '<div class="kv-card"><form method="post" action="' . kv_h(kvgw_url('bulletin', array('a' => 'catsave', 'id' => $c['id']))) . '">'
           . kvgw_csrf() . $row($c) . '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">保存</button>'
           . '<span class="kv-hint" style="margin:0">掲示 ' . $n . ' 件</span></div></form>';
        echo '<form method="post" style="margin-top:12px" action="' . kv_h(kvgw_url('bulletin', array('a' => 'catdelete', 'id' => $c['id'])))
           . '" onsubmit="return confirm(\'このカテゴリを削除します。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn danger" type="submit"' . ($n > 0 ? ' disabled title="掲示が残っているカテゴリは削除できません"' : '') . '>削除</button></form></div>';
    }
    echo '<div class="kv-card"><h2 style="font-size:16px;margin-bottom:12px">カテゴリを足す</h2>'
       . '<form method="post" action="' . kv_h(kvgw_url('bulletin', array('a' => 'catsave'))) . '">' . kvgw_csrf()
       . $row(array('name' => '', 'order' => (count($cats) + 1) * 10, 'dept' => '', 'post' => 'all'))
       . '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">足す</button></div></form></div>';
    echo '</main>';
    kv_foot();
}
