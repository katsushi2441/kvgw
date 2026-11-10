<?php
/**
 * メッセージ — 宛先を決めて送る、社内の連絡と話し合い。
 *
 * 見られるのは差出人と宛先だけ。管理者でも、宛先に入っていないメッセージは読めない。
 *
 * 台帳:
 *   gw_messages            … メッセージ本体(件名・本文・宛先・コメント・添付の目録)
 *   gw_msgstate_<利用者ID>  … その人の「どこまで読んだか」と「ごみ箱」。
 *                            読むたびに本体を書き換えないよう、人ごとに分けてある。
 *   gw_files/              … 添付の実体。名前は乱数。必ず kvgw_msg_can() を通してから渡す。
 *
 * 未読の決め方: メッセージは動き(送信・書き直し・コメント)のたびに rev が1つ増える。
 *   (宛先の追加では増やさない。)自分が最後に開いたときの rev より大きく、最後に動かしたのが自分でなければ未読。
 */

/* ============================================================
 * 権限 — メッセージについての判定はここだけ
 * ============================================================ */

function kvgw_msg_can($user, $m, $op) {
    if (!$user || !$m) { return false; }
    $isFrom = ($m['from'] === $user['id']);
    $isTo = in_array($user['id'], $m['to'], true);
    if (!empty($m['draft'])) { return $isFrom && ($op === 'read' || $op === 'edit' || $op === 'delete'); }
    switch ($op) {
        case 'read':
        case 'comment': return $isFrom || $isTo;
        case 'edit':
        case 'delete':  return $isFrom;
        case 'addto':   return $isFrom || ($isTo && !empty($m['allow_add']));
        case 'trash':   return $isTo;
    }
    return false;
}

/* ============================================================
 * 台帳
 * ============================================================ */

function kvgw_msg_all() { return kv_load('gw_messages', 'messages'); }

function kvgw_msg_find($id) {
    foreach (kvgw_msg_all() as $m) { if ($m['id'] === $id) { return $m; } }
    return null;
}

function kvgw_msg_state_name($uid) { return 'gw_msgstate_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$uid); }

function kvgw_msg_state($uid) { return kv_load(kvgw_msg_state_name($uid), 'state'); }

function kvgw_msg_state_set($uid, $mid, $fields) {
    return kv_update(kvgw_msg_state_name($uid), 'state', function (&$st) use ($mid, $fields) {
        if (!isset($st[$mid]) || !is_array($st[$mid])) { $st[$mid] = array(); }
        foreach ($fields as $k => $v) { $st[$mid][$k] = $v; }
        return true;
    });
}

function kvgw_msg_is_unread($user, $m, $state) {
    if (!empty($m['draft']) || $m['last_by'] === $user['id']) { return false; }
    $seen = isset($state[$m['id']]['rev']) ? (int)$state[$m['id']]['rev'] : 0;
    return $seen < (int)$m['rev'];
}

/** 宛先に使える人だけを残す(在籍・重複なし・差出人本人は除く)。 */
function kvgw_msg_clean_to($ids, $fromId) {
    $people = kvgw_people();
    $out = array();
    if (!is_array($ids)) { return $out; }
    foreach ($ids as $id) {
        $id = (string)$id;
        if ($id === $fromId || !isset($people[$id]) || in_array($id, $out, true)) { continue; }
        $out[] = $id;
    }
    return $out;
}

/**
 * 新しく送る・下書きに置く・書き直す。$id が空なら新規。
 * $in: subject, body, to(利用者IDの配列), allow_add, files(添付の目録), remove(外す添付のID)
 */
function kvgw_msg_save($user, $id, $in, $draft) {
    $subject = kvgw_in($in, 'subject', 100);
    $body = kvgw_in($in, 'body', 20000);
    $to = kvgw_msg_clean_to(isset($in['to']) ? $in['to'] : array(), $user['id']);
    $files = isset($in['files']) && is_array($in['files']) ? $in['files'] : array();
    $remove = isset($in['remove']) && is_array($in['remove']) ? $in['remove'] : array();
    $allow = !empty($in['allow_add']);

    if ($subject === '') { return array(false, '件名を入れてください'); }
    /* 送ったあとの書き直しは宛先を選び直さない(減らさない)ので、宛先が空でもよい。確かめは台帳を開いてから。 */
    if ($id === '' && !$draft && !$to) { return array(false, '宛先を1人以上選んでください'); }
    if (!$draft && $body === '' && !$files) { return array(false, '本文を入れてください'); }

    $gone = array();
    $res = kv_update('gw_messages', 'messages', function (&$ms) use ($user, $id, $subject, $body, $to, $files, $remove, $allow, $draft, &$gone) {
        if ($id === '') {
            $m = array(
                'id' => 'm' . kv_random_hex(8), 'subject' => $subject, 'body' => $body,
                'from' => $user['id'], 'from_name' => $user['name'], 'to' => $to,
                'allow_add' => $allow, 'draft' => $draft, 'files' => $files, 'comments' => array(),
                'created' => kv_now(), 'updated' => kv_now(), 'edited' => '',
                'rev' => 1, 'last' => kv_now(), 'last_by' => $user['id'], 'last_name' => $user['name'],
            );
            $ms[] = $m;
            return $m;
        }
        foreach ($ms as $i => $m) {
            if ($m['id'] !== $id) { continue; }
            if (!kvgw_msg_can($user, $m, 'edit')) { return 'このメッセージを書き直す権限がありません'; }
            $wasDraft = !empty($m['draft']);
            if (!$wasDraft && $draft) { return '送信したメッセージは下書きに戻せません'; }
            if ($wasDraft && !$draft && !$to) { return '宛先を1人以上選んでください'; }
            $keep = array();
            foreach ($m['files'] as $f) {
                if (in_array($f['id'], $remove, true)) { $gone[] = $f['id']; } else { $keep[] = $f; }
            }
            $m['subject'] = $subject;
            $m['body'] = $body;
            $m['allow_add'] = $allow;
            $m['files'] = array_merge($keep, $files);
            if ($wasDraft) {
                $m['to'] = $to;
                $m['draft'] = $draft;
                if (!$draft) { $m['created'] = kv_now(); }
            } else {
                /* 送ったあとは宛先を減らさない(読んだ人から急に見えなくなるのを防ぐ)。足すだけ。 */
                foreach ($to as $t) { if (!in_array($t, $m['to'], true)) { $m['to'][] = $t; } }
                $m['edited'] = kv_now();
            }
            $m['updated'] = kv_now();
            $m['rev'] = (int)$m['rev'] + 1;
            $m['last'] = kv_now(); $m['last_by'] = $user['id']; $m['last_name'] = $user['name'];
            $ms[$i] = $m;
            return $m;
        }
        return 'メッセージが見つかりません';
    });
    if ($res[0]) { foreach ($gone as $fid) { kvgw_file_remove($fid); } }
    return $res;
}

/** 差出人が消すと、全員の画面から消える。 */
function kvgw_msg_delete($user, $id) {
    $gone = array();
    $res = kv_update('gw_messages', 'messages', function (&$ms) use ($user, $id, &$gone) {
        foreach ($ms as $i => $m) {
            if ($m['id'] !== $id) { continue; }
            if (!kvgw_msg_can($user, $m, 'delete')) { return 'このメッセージを削除する権限がありません'; }
            foreach ($m['files'] as $f) { $gone[] = $f['id']; }
            array_splice($ms, $i, 1);
            return true;
        }
        return 'メッセージが見つかりません';
    });
    if ($res[0]) { foreach ($gone as $fid) { kvgw_file_remove($fid); } }
    return $res;
}

function kvgw_msg_comment($user, $id, $body) {
    $body = kvgw_in(array('b' => $body), 'b', 5000);
    if ($body === '') { return array(false, 'コメントを入れてください'); }
    return kv_update('gw_messages', 'messages', function (&$ms) use ($user, $id, $body) {
        foreach ($ms as $i => $m) {
            if ($m['id'] !== $id) { continue; }
            if (!kvgw_msg_can($user, $m, 'comment')) { return 'このメッセージに書き込む権限がありません'; }
            $c = array('id' => 'c' . kv_random_hex(6), 'by' => $user['id'], 'by_name' => $user['name'],
                       'body' => $body, 'at' => kv_now());
            $ms[$i]['comments'][] = $c;
            $ms[$i]['rev'] = (int)$m['rev'] + 1;
            $ms[$i]['last'] = kv_now(); $ms[$i]['last_by'] = $user['id']; $ms[$i]['last_name'] = $user['name'];
            return $c;
        }
        return 'メッセージが見つかりません';
    });
}

/** コメントを消せるのは書いた本人だけ。 */
function kvgw_msg_comment_delete($user, $id, $cid) {
    return kv_update('gw_messages', 'messages', function (&$ms) use ($user, $id, $cid) {
        foreach ($ms as $i => $m) {
            if ($m['id'] !== $id) { continue; }
            if (!kvgw_msg_can($user, $m, 'comment')) { return 'この操作の権限がありません'; }
            foreach ($m['comments'] as $j => $c) {
                if ($c['id'] !== $cid) { continue; }
                if ($c['by'] !== $user['id']) { return '自分のコメントだけ削除できます'; }
                array_splice($ms[$i]['comments'], $j, 1);
                return true;
            }
            return 'コメントが見つかりません';
        }
        return 'メッセージが見つかりません';
    });
}

/** 宛先を足す。差出人はいつでも。宛先の人は、差出人が許したメッセージだけ。 */
function kvgw_msg_add_to($user, $id, $ids) {
    return kv_update('gw_messages', 'messages', function (&$ms) use ($user, $id, $ids) {
        foreach ($ms as $i => $m) {
            if ($m['id'] !== $id) { continue; }
            if (!kvgw_msg_can($user, $m, 'addto')) { return '宛先を追加する権限がありません'; }
            $add = array();
            foreach (kvgw_msg_clean_to($ids, $m['from']) as $t) {
                if (!in_array($t, $m['to'], true)) { $add[] = $t; }
            }
            if (!$add) { return '追加する人を選んでください'; }
            /* rev は上げない。足された人には最初から未読で、ほかの人を未読に戻さない。 */
            $ms[$i]['to'] = array_merge($m['to'], $add);
            return $add;
        }
        return 'メッセージが見つかりません';
    });
}

/**
 * 一覧。$folder: inbox(受信箱) / sent(送信箱) / draft(下書き) / trash(ごみ箱)
 * 自分が読めるものだけを返す。各行に _unread を付ける。新しい動きが上。
 */
function kvgw_msg_list($user, $folder, $q) {
    $state = kvgw_msg_state($user['id']);
    $out = array();
    foreach (kvgw_msg_all() as $m) {
        if (!kvgw_msg_can($user, $m, 'read')) { continue; }
        $isFrom = ($m['from'] === $user['id']);
        $trashed = !empty($state[$m['id']]['trash']);
        if ($folder === 'draft') { if (empty($m['draft'])) { continue; } }
        else if (!empty($m['draft'])) { continue; }
        else if ($folder === 'sent') { if (!$isFrom) { continue; } }
        else if ($folder === 'trash') { if ($isFrom || !$trashed) { continue; } }
        else {
            /* 受信箱: 自分宛て。自分が送ったものは、相手からコメントが付いたら出す。 */
            if ($isFrom) { if (!$m['comments']) { continue; } }
            else if ($trashed) { continue; }
        }
        if ($q !== '') {
            $hay = $m['subject'] . "\n" . $m['body'] . "\n" . $m['from_name'];
            foreach ($m['comments'] as $c) { $hay .= "\n" . $c['body'] . "\n" . $c['by_name']; }
            if (mb_stripos($hay, $q, 0, 'UTF-8') === false) { continue; }
        }
        $m['_unread'] = kvgw_msg_is_unread($user, $m, $state);
        $out[] = $m;
    }
    usort($out, function ($a, $b) { return strcmp($b['last'], $a['last']); });
    return $out;
}

function kvgw_msg_unread_count($user) {
    $n = 0;
    foreach (kvgw_msg_list($user, 'inbox', '') as $m) { if ($m['_unread']) { $n++; } }
    return $n;
}

/* ============================================================
 * 添付ファイル
 * ============================================================ */

function kvgw_upload_on() { return kvgw_conf('KVGW_UPLOAD', true) && !kv_is_demo(); }
function kvgw_upload_max_mb() { return max(1, (int)kvgw_conf('KVGW_UPLOAD_MAX_MB', 10)); }
function kvgw_file_dir() { return KVGWC_DATA_DIR . '/gw_files'; }
function kvgw_file_path($fid) { return kvgw_file_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$fid); }
function kvgw_file_remove($fid) { $p = kvgw_file_path($fid); if (is_file($p)) { @unlink($p); } }

/** サーバーの上で動いてしまう種類・開くだけで危ない種類は受け取らない。 */
function kvgw_file_name_ok($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $deny = array('php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh',
                  'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'vbs', 'js', 'jse', 'wsf', 'ps1', 'jar', 'lnk',
                  'htaccess', 'html', 'htm', 'shtml', 'svg');
    return $name !== '' && $name[0] !== '.' && !in_array($ext, $deny, true);
}

/**
 * 画面から届いた添付を受け取り、乱数の名前で置く。戻り値は array(成否, 目録 か 理由)。
 * 目録だけをメッセージに書き、実体は外から読めない台帳フォルダの中に置く。
 */
function kvgw_files_take($field) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) { return array(true, array()); }
    $names = $_FILES[$field]['name'];
    $list = array();
    foreach ($names as $i => $name) {
        $err = $_FILES[$field]['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) { continue; }
        $list[] = array((string)$name, $_FILES[$field]['tmp_name'][$i], (int)$_FILES[$field]['size'][$i], $err);
    }
    if (!$list) { return array(true, array()); }
    if (!kvgw_upload_on()) { return array(false, 'この環境ではファイルを添付できません'); }
    if (count($list) > 5) { return array(false, '添付は1回に5個までです'); }
    $max = kvgw_upload_max_mb();
    foreach ($list as $f) {
        $name = basename(str_replace('\\', '/', $f[0]));
        if ($f[3] !== UPLOAD_ERR_OK || $f[2] > $max * 1048576) {
            return array(false, '「' . $name . '」が大きすぎます（1個 ' . $max . 'MB まで）');
        }
        if (!kvgw_file_name_ok($name)) { return array(false, '「' . $name . '」は添付できない種類のファイルです'); }
    }
    kv_ensure_data_dir();
    if (!is_dir(kvgw_file_dir())) { @mkdir(kvgw_file_dir(), 0700, true); }
    $out = array();
    foreach ($list as $f) {
        $fid = kv_random_hex(16);
        if (!@move_uploaded_file($f[1], kvgw_file_path($fid))) {
            foreach ($out as $o) { kvgw_file_remove($o['id']); }
            return array(false, 'ファイルを保存できませんでした');
        }
        $out[] = array('id' => $fid, 'name' => mb_substr(basename(str_replace('\\', '/', $f[0])), 0, 120, 'UTF-8'),
                       'size' => $f[2]);
    }
    return array(true, $out);
}

function kvgw_size_label($bytes) {
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . 'MB'; }
    return max(1, (int)round($bytes / 1024)) . 'KB';
}

/* ============================================================
 * 画面と操作
 * ============================================================ */

function kvgw_message_route($user) {
    $a = isset($_GET['a']) ? preg_replace('/[^a-z]/', '', (string)$_GET['a']) : '';
    $id = isset($_REQUEST['id']) ? preg_replace('/[^a-z0-9]/', '', (string)$_REQUEST['id']) : '';

    if ($a === 'save') {
        kvgw_need_post();
        $draft = isset($_POST['as']) && $_POST['as'] === 'draft';
        $in = array('subject' => isset($_POST['subject']) ? $_POST['subject'] : '',
                    'body' => isset($_POST['body']) ? $_POST['body'] : '',
                    'to' => isset($_POST['to']) ? $_POST['to'] : array(),
                    'allow_add' => !empty($_POST['allow_add']),
                    'remove' => isset($_POST['remove']) && is_array($_POST['remove']) ? array_map('strval', $_POST['remove']) : array());
        $took = kvgw_files_take('files');
        if (!$took[0]) { http_response_code(400); kvgw_message_form($user, $id, $in, $took[1]); return; }
        $in['files'] = $took[1];
        $res = kvgw_msg_save($user, $id, $in, $draft);
        if (!$res[0]) {
            foreach ($took[1] as $f) { kvgw_file_remove($f['id']); }
            http_response_code(400);
            kvgw_message_form($user, $id, $in, $res[1]);
            return;
        }
        if ($draft) { kvgw_go('message', array('f' => 'draft', 'msg' => 'draft')); }
        kvgw_go('message', array('a' => 'view', 'id' => $res[1]['id'], 'msg' => $id === '' ? 'sent' : 'saved'));
    }
    if ($a === 'delete') {
        kvgw_need_post();
        $res = kvgw_msg_delete($user, $id);
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('message', array('f' => 'sent', 'msg' => 'deleted'));
    }
    if ($a === 'comment') {
        kvgw_need_post();
        $res = kvgw_msg_comment($user, $id, isset($_POST['body']) ? $_POST['body'] : '');
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('message', array('a' => 'view', 'id' => $id, 'msg' => 'comment'));
    }
    if ($a === 'uncomment') {
        kvgw_need_post();
        $cid = isset($_POST['cid']) ? preg_replace('/[^a-z0-9]/', '', (string)$_POST['cid']) : '';
        $res = kvgw_msg_comment_delete($user, $id, $cid);
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('message', array('a' => 'view', 'id' => $id, 'msg' => 'deleted'));
    }
    if ($a === 'addto') {
        kvgw_need_post();
        $res = kvgw_msg_add_to($user, $id, isset($_POST['to']) ? $_POST['to'] : array());
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('message', array('a' => 'view', 'id' => $id, 'msg' => 'added'));
    }
    if ($a === 'trash' || $a === 'restore') {
        kvgw_need_post();
        $m = kvgw_msg_find($id);
        if (!kvgw_msg_can($user, $m, 'trash')) { kv_fail(400, 'この操作はできません。'); }
        kvgw_msg_state_set($user['id'], $id, array('trash' => $a === 'trash'));
        kvgw_go('message', array('f' => $a === 'trash' ? '' : 'trash', 'msg' => $a));
    }
    if ($a === 'file') { kvgw_message_file($user, $id); return; }
    if ($a === 'new' || $a === 'edit') {
        $in = array('subject' => '', 'body' => '', 'to' => array(), 'allow_add' => false);
        if ($a === 'edit') {
            $m = kvgw_msg_find($id);
            if (!kvgw_msg_can($user, $m, 'edit')) { kv_fail(404, 'メッセージが見つからないか、書き直す権限がありません。'); }
            $in = array('subject' => $m['subject'], 'body' => $m['body'], 'to' => $m['to'], 'allow_add' => !empty($m['allow_add']));
        } else { $id = ''; }
        kvgw_message_form($user, $id, $in, '');
        return;
    }
    if ($a === 'view') { kvgw_message_view($user, $id); return; }
    kvgw_message_index($user);
}

function kvgw_message_index($user) {
    $folders = array('inbox' => '受信箱', 'sent' => '送信箱', 'draft' => '下書き', 'trash' => 'ごみ箱');
    $f = isset($_GET['f']) && isset($folders[$_GET['f']]) ? (string)$_GET['f'] : 'inbox';
    $q = kvgw_in($_GET, 'q', 60);
    $list = kvgw_msg_list($user, $f, $q);
    $per = 30;
    $page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
    $pages = max(1, (int)ceil(count($list) / $per));
    if ($page > $pages) { $page = $pages; }
    $rows = array_slice($list, ($page - 1) * $per, $per);

    kv_head('メッセージ', $user);
    kv_nav($user, 'gw:message');
    echo '<main class="kv-wrap"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow">MESSAGE</p>'
       . '<h1 class="kv-title"><span class="kv-emoji">✉️</span>メッセージ</h1>'
       . '<p class="kv-lead">宛先に入れた人だけが読めます。返事はコメントで続けます。</p>';
    echo '<div class="kv-bar"><a class="kv-btn" href="' . kv_h(kvgw_url('message', array('a' => 'new'))) . '">＋ メッセージを書く</a>'
       . '<form method="get" action="' . kv_h(kvgw_self()) . '"><input type="hidden" name="m" value="message">'
       . '<input type="hidden" name="f" value="' . kv_h($f) . '">'
       . '<input type="search" name="q" value="' . kv_h($q) . '" placeholder="件名・本文・差出人・コメントから探す" aria-label="メッセージを探す">'
       . '<button class="kv-btn sub" type="submit">探す</button></form></div>';
    echo '<div class="gw-tabs">';
    foreach ($folders as $k => $label) {
        echo '<a href="' . kv_h(kvgw_url('message', array('f' => $k))) . '"' . ($k === $f ? ' class="on"' : '') . '>'
           . kv_h($label) . '</a>';
    }
    echo '</div>';

    if (!$rows) {
        $none = array('inbox' => '受信箱は空です。', 'sent' => '送ったメッセージはまだありません。',
                      'draft' => '下書きはありません。', 'trash' => 'ごみ箱は空です。');
        echo '<div class="kv-empty"><span class="kv-emoji">✉️</span>'
           . kv_h($q !== '' ? '「' . $q . '」に合うメッセージはありません。' : $none[$f]) . '</div>';
    } else {
        echo '<div class="gw-rows">';
        foreach ($rows as $m) {
            $href = !empty($m['draft']) ? kvgw_url('message', array('a' => 'edit', 'id' => $m['id']))
                                        : kvgw_url('message', array('a' => 'view', 'id' => $m['id']));
            $who = ($m['from'] === $user['id'])
                 ? '宛先 ' . count($m['to']) . '人'
                 : $m['from_name'];
            $bits = array($who);
            if ($m['comments']) { $bits[] = 'コメント ' . count($m['comments']); }
            if ($m['files']) { $bits[] = '添付 ' . count($m['files']); }
            echo '<a class="gw-row' . ($m['_unread'] ? ' unread' : '') . '" href="' . kv_h($href) . '">'
               . '<span class="gw-dot"></span><span class="gw-rowmain"><b>' . kv_h($m['subject'])
               . ($m['_unread'] ? ' <span class="kv-tag gw-tagnew">未読</span>' : '')
               . (!empty($m['draft']) ? ' <span class="kv-tag">下書き</span>' : '') . '</b>'
               . '<small>' . kv_h(implode('　・　', $bits)) . '</small></span>'
               . '<span class="gw-rowside">' . kv_h(substr($m['last'], 5, 11)) . '</span></a>';
        }
        echo '</div>';
        if ($pages > 1) {
            echo '<div class="kv-bar" style="margin-bottom:0">';
            if ($page > 1) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('message', array('f' => $f, 'q' => $q, 'p' => $page - 1))) . '">前へ</a>'; }
            echo '<span class="kv-hint" style="margin:0">' . $page . ' / ' . $pages . ' ページ（' . count($list) . ' 件）</span>';
            if ($page < $pages) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('message', array('f' => $f, 'q' => $q, 'p' => $page + 1))) . '">次へ</a>'; }
            echo '</div>';
        }
    }
    echo '</div></main>';
    kv_foot();
}

function kvgw_message_view($user, $id) {
    $m = kvgw_msg_find($id);
    /* 無いのか、読めないのかを区別して返さない(宛先外の人に存在を教えない) */
    if (!kvgw_msg_can($user, $m, 'read') || !empty($m['draft'])) { kv_fail(404, 'メッセージが見つからないか、宛先に入っていません。'); }
    kvgw_msg_state_set($user['id'], $id, array('rev' => (int)$m['rev'], 'read' => kv_now()));
    $state = kvgw_msg_state($user['id']);
    $trashed = !empty($state[$id]['trash']);

    kv_head($m['subject'] . '｜メッセージ', $user);
    kv_nav($user, 'gw:message');
    echo '<main class="kv-wrap" style="max-width:820px"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('message')) . '" style="text-decoration:none">← メッセージ</a></p>'
       . '<h1 class="kv-title" style="overflow-wrap:anywhere">' . kv_h($m['subject']) . '</h1>'
       . '<p class="gw-meta">差出人 <b>' . kv_h($m['from_name']) . '</b>　' . kv_h(substr($m['created'], 0, 16))
       . ($m['edited'] !== '' ? '　（' . kv_h(substr($m['edited'], 5, 11)) . ' に書き直し）' : '') . '</p>';
    echo '<div class="gw-body">' . kv_h($m['body']) . '</div>';
    if ($m['files']) {
        echo '<div class="gw-files">';
        foreach ($m['files'] as $f) {
            echo '<a href="' . kv_h(kvgw_url('message', array('a' => 'file', 'id' => $id, 'fid' => $f['id']))) . '">📎 '
               . kv_h($f['name']) . '（' . kv_h(kvgw_size_label($f['size'])) . '）</a>';
        }
        echo '</div>';
    }

    /* 閲覧状況 — だれが読んだかを全員に見せる。読んだかどうかの確認の電話を減らす。 */
    $seen = '';
    $read = 0;
    foreach ($m['to'] as $t) {
        $st = kvgw_msg_state($t);
        $on = isset($st[$id]['rev']);
        if ($on) { $read++; }
        $seen .= '<span' . ($on ? ' class="on"' : '') . '>' . kv_h(kvgw_person_name($t)) . ($on ? ' 既読' : ' 未読') . '</span>';
    }
    echo '<details class="gw-fold" style="margin-top:18px"' . (count($m['to']) <= 8 ? ' open' : '') . '>'
       . '<summary>宛先 ' . count($m['to']) . '人（既読 ' . $read . '人）</summary><div class="gw-seen">' . $seen . '</div></details>';

    echo '<div class="kv-bar" style="margin-bottom:0">';
    if (kvgw_msg_can($user, $m, 'edit')) {
        echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('message', array('a' => 'edit', 'id' => $id))) . '">書き直す</a>';
    }
    if (kvgw_msg_can($user, $m, 'trash')) {
        echo '<form method="post" class="gw-inline" style="flex:0 0 auto;min-width:0" action="'
           . kv_h(kvgw_url('message', array('a' => $trashed ? 'restore' : 'trash', 'id' => $id))) . '">' . kvgw_csrf()
           . '<button class="kv-btn sub" type="submit">' . ($trashed ? '受信箱に戻す' : 'ごみ箱へ') . '</button></form>';
    }
    if (kvgw_msg_can($user, $m, 'delete')) {
        echo '<form method="post" class="gw-inline" style="flex:0 0 auto;min-width:0" action="' . kv_h(kvgw_url('message', array('a' => 'delete', 'id' => $id)))
           . '" onsubmit="return confirm(\'このメッセージを削除します。宛先の人の画面からも消えます。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn danger" type="submit">削除</button></form>';
    }
    echo '</div></div>';

    /* コメント */
    echo '<div class="kv-card"><h2 style="font-size:16px;margin-bottom:6px">コメント（' . count($m['comments']) . '）</h2>';
    foreach ($m['comments'] as $c) {
        echo '<article class="gw-comment"><header><b>' . kv_h($c['by_name']) . '</b><span>' . kv_h(substr($c['at'], 0, 16)) . '</span>';
        if ($c['by'] === $user['id']) {
            echo '<form method="post" class="gw-inline" action="' . kv_h(kvgw_url('message', array('a' => 'uncomment', 'id' => $id)))
               . '" onsubmit="return confirm(\'このコメントを削除します。よろしいですか\')">' . kvgw_csrf()
               . '<input type="hidden" name="cid" value="' . kv_h($c['id']) . '"><button class="gw-textbtn" type="submit">削除</button></form>';
        }
        echo '</header><p>' . kv_h($c['body']) . '</p></article>';
    }
    if (kvgw_msg_can($user, $m, 'comment')) {
        echo '<form method="post" action="' . kv_h(kvgw_url('message', array('a' => 'comment', 'id' => $id))) . '" style="margin-top:12px">'
           . kvgw_csrf() . '<div class="kv-field"><label for="gw-c">コメントを書く</label>'
           . '<textarea id="gw-c" name="body" rows="3" maxlength="5000" required></textarea></div>'
           . '<button class="kv-btn" type="submit">書き込む</button></form>';
    }
    echo '</div>';

    if (kvgw_msg_can($user, $m, 'addto')) {
        echo '<div class="kv-card"><details class="gw-fold"><summary>宛先を追加する</summary>'
           . '<form method="post" action="' . kv_h(kvgw_url('message', array('a' => 'addto', 'id' => $id))) . '" style="margin-top:14px">'
           . kvgw_csrf() . kvgw_people_checks('to', $m['to'], $m['from'])
           . '<p class="kv-hint">追加された人は、これまでの本文とコメントをすべて読めます。</p>'
           . '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">追加する</button></div></form></details></div>';
    }
    echo '</main>';
    kv_foot();
}

function kvgw_message_form($user, $id, $in, $error) {
    $m = $id !== '' ? kvgw_msg_find($id) : null;
    $sent = $m && empty($m['draft']);
    $to = kvgw_msg_clean_to(isset($in['to']) ? $in['to'] : array(), $user['id']);
    kv_head(($sent ? '書き直す' : 'メッセージを書く') . '｜メッセージ', $user);
    kv_nav($user, 'gw:message');
    echo '<main class="kv-wrap" style="max-width:760px"><div class="kv-card">'
       . '<p class="kv-eyebrow">MESSAGE</p><h1 class="kv-title" style="margin-bottom:18px"><span class="kv-emoji">✉️</span>'
       . ($sent ? 'メッセージを書き直す' : 'メッセージを書く') . '</h1>';
    if ($error !== '') { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($error) . '</p>'; }
    echo '<form method="post" enctype="multipart/form-data" action="' . kv_h(kvgw_url('message', array('a' => 'save'))) . '">'
       . kvgw_csrf() . '<input type="hidden" name="id" value="' . kv_h($id) . '">';
    echo '<div class="kv-field"><label for="gw-s">件名<span class="kv-req">必須</span></label>'
       . '<input id="gw-s" type="text" name="subject" maxlength="100" required value="' . kv_h(kvgw_in($in, 'subject', 100)) . '"></div>';
    echo '<div class="kv-field"><label>宛先<span class="kv-req">必須</span></label>'
       . kvgw_people_checks('to', $to, $user['id'])
       . ($sent ? '<p class="kv-hint">送ったあとは、宛先を足すことだけできます。</p>' : '') . '</div>';
    echo '<div class="kv-field"><label for="gw-b">本文</label>'
       . '<textarea id="gw-b" name="body" rows="10" maxlength="20000">' . kv_h(kvgw_in($in, 'body', 20000)) . '</textarea></div>';
    if ($m && $m['files']) {
        echo '<div class="kv-field"><label>いまの添付</label><div class="kv-choices">';
        foreach ($m['files'] as $f) {
            echo '<label class="kv-choice"><input type="checkbox" name="remove[]" value="' . kv_h($f['id']) . '">'
               . kv_h($f['name']) . ' を外す</label>';
        }
        echo '</div></div>';
    }
    if (kvgw_upload_on()) {
        echo '<div class="kv-field"><label for="gw-f">添付ファイル</label><input id="gw-f" type="file" name="files[]" multiple>'
           . '<p class="kv-hint">1回に5個まで・1個 ' . kvgw_upload_max_mb() . 'MB まで。宛先の人だけが受け取れます。</p></div>';
    } else if (kv_is_demo()) {
        echo '<p class="kv-hint" style="margin-bottom:17px">デモではファイルの添付を止めています。製品では、宛先の人だけが受け取れる形で添付できます。</p>';
    }
    echo '<div class="kv-field"><label class="kv-choice" style="display:inline-flex"><input type="checkbox" name="allow_add" value="1"'
       . (!empty($in['allow_add']) ? ' checked' : '') . '>宛先の人にも、宛先の追加を許す</label></div>';
    echo '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit" name="as" value="send">'
       . ($sent ? '保存する' : '送信する') . '</button>';
    if (!$sent) { echo '<button class="kv-btn sub" type="submit" name="as" value="draft" formnovalidate>下書きに保存</button>'; }
    echo '<a class="kv-btn sub" href="' . kv_h($sent ? kvgw_url('message', array('a' => 'view', 'id' => $id)) : kvgw_url('message')) . '">やめる</a></div></form>';
    if ($m && !$sent) {
        echo '<form method="post" style="margin-top:16px" action="' . kv_h(kvgw_url('message', array('a' => 'delete', 'id' => $id)))
           . '" onsubmit="return confirm(\'この下書きを削除します。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn danger" type="submit">下書きを削除</button></form>';
    }
    echo '</div></main>';
    kv_foot();
}

/** 添付を渡す。読める人かどうかを確かめてから、必ず「保存するファイル」として送る。 */
function kvgw_message_file($user, $id) {
    $m = kvgw_msg_find($id);
    if (!kvgw_msg_can($user, $m, 'read')) { kv_fail(404, 'ファイルが見つからないか、受け取る権限がありません。'); }
    $fid = isset($_GET['fid']) ? preg_replace('/[^a-f0-9]/', '', (string)$_GET['fid']) : '';
    foreach ($m['files'] as $f) {
        if ($f['id'] !== $fid) { continue; }
        $path = kvgw_file_path($fid);
        if (!is_file($path)) { break; }
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="file"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        return;
    }
    kv_fail(404, 'ファイルが見つかりません。');
}
