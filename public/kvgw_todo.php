<?php
/**
 * ToDo — 自分のやること、人に頼んだこと。
 *
 * 見えるのは「作った人」と「担当の人」だけ。人に頼んだ ToDo は、頼んだ側にも進み具合が見える。
 *
 * 台帳: gw_todos … ToDo(名前・期日・優先度・カテゴリ・メモ・作った人・担当の人・完了)
 */

/* ============================================================
 * 権限 — ToDo についての判定はここだけ
 * ============================================================ */

function kvgw_todo_can($user, $t, $op) {
    if (!$user || !$t) { return false; }
    $isBy = ($t['by'] === $user['id']);
    $isTo = ($t['to'] === $user['id']);
    switch ($op) {
        case 'read':
        case 'done':   return $isBy || $isTo;   // 完了にする・未完了に戻す
        case 'update':
        case 'delete': return $isBy;            // 中身を変える・消すのは作った人
    }
    return false;
}

function kvgw_todo_priorities() { return array('1' => '高', '2' => '中', '3' => '低'); }

/* ============================================================
 * 台帳
 * ============================================================ */

function kvgw_todo_all() { return kv_load('gw_todos', 'todos'); }

function kvgw_todo_find($id) {
    foreach (kvgw_todo_all() as $t) { if ($t['id'] === $id) { return $t; } }
    return null;
}

function kvgw_todo_check($user, $in) {
    $v = array(
        'title' => kvgw_in($in, 'title', 100), 'due' => kvgw_in($in, 'due', 10),
        'priority' => kvgw_in($in, 'priority', 1), 'category' => kvgw_in($in, 'category', 30),
        'memo' => kvgw_in($in, 'memo', 2000), 'to' => kvgw_in($in, 'to', 20),
    );
    $e = array();
    if ($v['title'] === '') { $e['title'] = 'ToDo の名前を入れてください'; }
    if ($v['due'] !== '' && !kvgw_date_ok($v['due'])) { $e['due'] = '期日は日付で入れてください'; }
    $p = kvgw_todo_priorities();
    if (!isset($p[$v['priority']])) { $v['priority'] = '2'; }
    if ($v['to'] === '') { $v['to'] = $user['id']; }
    $people = kvgw_people();
    if ($v['to'] !== $user['id'] && !isset($people[$v['to']])) { $e['to'] = '担当の人を選び直してください'; }
    return array($v, $e);
}

function kvgw_todo_save($user, $id, $in) {
    list($v, $e) = kvgw_todo_check($user, $in);
    if ($e) { return array(false, $e); }
    $toName = $v['to'] === $user['id'] ? $user['name'] : kvgw_person_name($v['to']);
    $res = kv_update('gw_todos', 'todos', function (&$ts) use ($user, $id, $v, $toName) {
        if ($id === '') {
            $t = $v + array('id' => 't' . kv_random_hex(8), 'by' => $user['id'], 'by_name' => $user['name'],
                            'to_name' => $toName, 'done' => false, 'done_at' => '', 'done_by' => '',
                            'created' => kv_now(), 'updated' => kv_now());
            $ts[] = $t;
            return $t;
        }
        foreach ($ts as $i => $t) {
            if ($t['id'] !== $id) { continue; }
            if (!kvgw_todo_can($user, $t, 'update')) { return 'この ToDo を変更できるのは、作った人だけです'; }
            foreach ($v as $k => $val) { $ts[$i][$k] = $val; }
            $ts[$i]['to_name'] = $toName;
            $ts[$i]['updated'] = kv_now();
            return $ts[$i];
        }
        return 'ToDo が見つかりません';
    });
    if (!$res[0]) { return array(false, array('_' => $res[1])); }
    return $res;
}

function kvgw_todo_done($user, $id, $done) {
    return kv_update('gw_todos', 'todos', function (&$ts) use ($user, $id, $done) {
        foreach ($ts as $i => $t) {
            if ($t['id'] !== $id) { continue; }
            if (!kvgw_todo_can($user, $t, 'done')) { return 'この ToDo を完了にする権限がありません'; }
            $ts[$i]['done'] = (bool)$done;
            $ts[$i]['done_at'] = $done ? kv_now() : '';
            $ts[$i]['done_by'] = $done ? $user['name'] : '';
            $ts[$i]['updated'] = kv_now();
            return $ts[$i];
        }
        return 'ToDo が見つかりません';
    });
}

function kvgw_todo_delete($user, $id) {
    return kv_update('gw_todos', 'todos', function (&$ts) use ($user, $id) {
        foreach ($ts as $i => $t) {
            if ($t['id'] !== $id) { continue; }
            if (!kvgw_todo_can($user, $t, 'delete')) { return 'この ToDo を削除できるのは、作った人だけです'; }
            array_splice($ts, $i, 1);
            return true;
        }
        return 'ToDo が見つかりません';
    });
}

/**
 * 一覧。$filter: open(自分がやる・未完了) / asked(人に頼んだ・未完了) / done(完了)
 * 並びは 期日の近い順(期日なしは後ろ)→ 優先度の高い順。完了は、終えたのが新しい順。
 */
function kvgw_todo_list($user, $filter, $category) {
    $out = array();
    foreach (kvgw_todo_all() as $t) {
        if (!kvgw_todo_can($user, $t, 'read')) { continue; }
        $mine = ($t['to'] === $user['id']);
        if ($filter === 'done') { if (empty($t['done'])) { continue; } }
        else if (!empty($t['done'])) { continue; }
        else if ($filter === 'asked') { if ($mine) { continue; } }
        else if (!$mine) { continue; }
        if ($category !== '' && $t['category'] !== $category) { continue; }
        $out[] = $t;
    }
    if ($filter === 'done') {
        usort($out, function ($a, $b) { return strcmp($b['done_at'], $a['done_at']); });
    } else {
        usort($out, function ($a, $b) {
            $da = $a['due'] === '' ? '9999-99-99' : $a['due'];
            $db = $b['due'] === '' ? '9999-99-99' : $b['due'];
            if ($da !== $db) { return strcmp($da, $db); }
            if ($a['priority'] !== $b['priority']) { return strcmp($a['priority'], $b['priority']); }
            return strcmp($a['created'], $b['created']);
        });
    }
    return $out;
}

function kvgw_todo_open_count($user) { return count(kvgw_todo_list($user, 'open', '')); }

/** 自分に見える ToDo で使われているカテゴリ(入力の候補と絞り込みに使う)。 */
function kvgw_todo_categories($user) {
    $c = array();
    foreach (kvgw_todo_all() as $t) {
        if ($t['category'] !== '' && kvgw_todo_can($user, $t, 'read')) { $c[$t['category']] = true; }
    }
    $c = array_keys($c);
    sort($c);
    return $c;
}

/* ============================================================
 * 画面と操作
 * ============================================================ */

function kvgw_todo_route($user) {
    $a = isset($_GET['a']) ? preg_replace('/[^a-z]/', '', (string)$_GET['a']) : '';
    $id = isset($_REQUEST['id']) ? preg_replace('/[^a-z0-9]/', '', (string)$_REQUEST['id']) : '';
    $back = array('f' => kvgw_in($_REQUEST, 'f', 10), 'c' => kvgw_in($_REQUEST, 'c', 30));

    if ($a === 'save') {
        kvgw_need_post();
        $res = kvgw_todo_save($user, $id, $_POST);
        if (!$res[0]) {
            http_response_code(400);
            if (!empty($_POST['quick'])) { kv_fail(400, implode(' ', $res[1])); }
            kvgw_todo_form($user, $id, $_POST, $res[1]);
            return;
        }
        $asked = ($res[1]['to'] !== $user['id']);
        kvgw_go('todo', array('f' => $asked ? 'asked' : '', 'msg' => 'saved'));
    }
    if ($a === 'done' || $a === 'undone') {
        kvgw_need_post();
        $res = kvgw_todo_done($user, $id, $a === 'done');
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('todo', $back + array('msg' => $a));
    }
    if ($a === 'delete') {
        kvgw_need_post();
        $res = kvgw_todo_delete($user, $id);
        if (!$res[0]) { kv_fail(400, $res[1]); }
        kvgw_go('todo', array('msg' => 'deleted'));
    }
    if ($a === 'new' || $a === 'edit') {
        $in = array('title' => '', 'due' => '', 'priority' => '2', 'category' => '', 'memo' => '', 'to' => $user['id']);
        if ($a === 'edit') {
            $t = kvgw_todo_find($id);
            if (!kvgw_todo_can($user, $t, 'read')) { kv_fail(404, 'ToDo が見つからないか、見る権限がありません。'); }
            $in = $t;
        } else { $id = ''; }
        kvgw_todo_form($user, $id, $in, array());
        return;
    }
    kvgw_todo_index($user);
}

function kvgw_todo_index($user) {
    $filters = array('open' => '自分のToDo', 'asked' => '人に頼んだToDo', 'done' => '完了');
    $f = isset($_GET['f']) && isset($filters[$_GET['f']]) ? (string)$_GET['f'] : 'open';
    $cat = kvgw_in($_GET, 'c', 30);
    $list = kvgw_todo_list($user, $f, $cat);
    $cats = kvgw_todo_categories($user);
    $today = kvgw_today();
    $pri = kvgw_todo_priorities();

    kv_head('ToDo', $user);
    kv_nav($user, 'gw:todo');
    echo '<main class="kv-wrap" style="max-width:860px"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow">TODO</p><h1 class="kv-title"><span class="kv-emoji">✅</span>ToDo</h1>'
       . '<p class="kv-lead">自分のやることと、人に頼んだことを、期日の近い順に並べます。</p>';
    echo '<form class="gw-quick" method="post" style="margin-top:16px" action="' . kv_h(kvgw_url('todo', array('a' => 'save'))) . '">' . kvgw_csrf()
       . '<input type="hidden" name="quick" value="1">'
       . '<input type="text" name="title" maxlength="100" required placeholder="やることを書いて、足す" aria-label="ToDo の名前">'
       . '<input type="date" name="due" aria-label="期日">'
       . '<button class="kv-btn" type="submit">足す</button>'
       . '<a class="kv-btn sub" href="' . kv_h(kvgw_url('todo', array('a' => 'new'))) . '">くわしく書く・人に頼む</a></form>';
    echo '<div class="gw-tabs">';
    foreach ($filters as $k => $label) {
        echo '<a href="' . kv_h(kvgw_url('todo', array('f' => $k))) . '"' . ($k === $f ? ' class="on"' : '') . '>' . kv_h($label) . '</a>';
    }
    echo '</div>';
    if ($cats) {
        echo '<div class="gw-tabs" style="margin-top:-4px"><a href="' . kv_h(kvgw_url('todo', array('f' => $f))) . '"' . ($cat === '' ? ' class="on"' : '') . '>すべて</a>';
        foreach ($cats as $c) {
            echo '<a href="' . kv_h(kvgw_url('todo', array('f' => $f, 'c' => $c))) . '"' . ($cat === $c ? ' class="on"' : '') . '>' . kv_h($c) . '</a>';
        }
        echo '</div>';
    }

    if (!$list) {
        $none = array('open' => '未完了の ToDo はありません。', 'asked' => '人に頼んでいる ToDo はありません。', 'done' => '完了した ToDo はまだありません。');
        echo '<div class="kv-empty"><span class="kv-emoji">✅</span>' . kv_h($none[$f]) . '</div>';
    } else {
        echo '<div class="gw-rows">';
        foreach ($list as $t) {
            $done = !empty($t['done']);
            $late = (!$done && $t['due'] !== '' && $t['due'] < $today);
            $bits = array();
            if ($done) { $bits[] = '完了 ' . substr($t['done_at'], 5, 11) . ($t['done_by'] !== '' ? '（' . $t['done_by'] . '）' : ''); }
            else if ($t['due'] !== '') { $bits[] = '期日 ' . kvgw_day_label($t['due']) . ($late ? '　期限切れ' : ($t['due'] === $today ? '　今日まで' : '')); }
            if ($t['category'] !== '') { $bits[] = $t['category']; }
            if ($t['to'] !== $user['id']) { $bits[] = '担当 ' . $t['to_name']; }
            else if ($t['by'] !== $user['id']) { $bits[] = $t['by_name'] . 'さんから'; }
            echo '<div class="gw-todo' . ($done ? ' done' : '') . '">'
               . '<form method="post" action="' . kv_h(kvgw_url('todo', array('a' => $done ? 'undone' : 'done', 'id' => $t['id'], 'f' => $f, 'c' => $cat))) . '">'
               . kvgw_csrf() . '<button class="gw-check' . ($done ? ' on' : '') . '" type="submit" aria-label="'
               . ($done ? '未完了に戻す' : '完了にする') . '" title="' . ($done ? '未完了に戻す' : '完了にする') . '">' . ($done ? '✓' : '') . '</button></form>'
               . '<a href="' . kv_h(kvgw_url('todo', array('a' => 'edit', 'id' => $t['id']))) . '"><b>' . kv_h($t['title']) . '</b>'
               . ($bits ? '<small' . ($late ? ' class="gw-late"' : '') . '>' . kv_h(implode('　・　', $bits)) . '</small>' : '') . '</a>'
               . '<span class="gw-pri p' . kv_h($t['priority']) . '">' . kv_h($pri[$t['priority']]) . '</span></div>';
        }
        echo '</div>';
    }
    echo '</div></main>';
    kv_foot();
}

function kvgw_todo_form($user, $id, $in, $errors) {
    $t = $id !== '' ? kvgw_todo_find($id) : null;
    if ($id !== '' && !kvgw_todo_can($user, $t, 'read')) { kv_fail(404, 'ToDo が見つからないか、見る権限がありません。'); }
    $canEdit = !$t || kvgw_todo_can($user, $t, 'update');
    $val = function ($k) use ($in) { return isset($in[$k]) && !is_array($in[$k]) ? (string)$in[$k] : ''; };
    $err = function ($k) use ($errors) { return isset($errors[$k]) ? '<p class="kv-error">' . kv_h($errors[$k]) . '</p>' : ''; };
    $ro = $canEdit ? '' : ' disabled';
    $to = $val('to') !== '' ? $val('to') : $user['id'];

    kv_head(($t ? 'ToDo の内容' : 'ToDo を足す') . '｜ToDo', $user);
    kv_nav($user, 'gw:todo');
    echo '<main class="kv-wrap kv-narrow" style="max-width:680px"><div class="kv-card">'
       . '<p class="kv-eyebrow">TODO</p><h1 class="kv-title" style="margin-bottom:18px"><span class="kv-emoji">✅</span>'
       . ($t ? ($canEdit ? 'ToDo を変更する' : 'ToDo の内容') : 'ToDo を足す') . '</h1>';
    if (isset($errors['_'])) { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($errors['_']) . '</p>'; }
    else if ($errors) { echo '<p class="kv-error" style="margin-bottom:14px">入力に誤りがあります。赤字の項目を直してください。</p>'; }
    if ($t && !$canEdit) {
        echo '<p class="kv-hint" style="margin:0 0 14px">' . kv_h($t['by_name']) . 'さんから頼まれた ToDo です。中身を変えられるのは頼んだ人で、あなたは完了にできます。</p>';
    }
    echo '<form method="post" action="' . kv_h(kvgw_url('todo', array('a' => 'save'))) . '">' . kvgw_csrf()
       . '<input type="hidden" name="id" value="' . kv_h($id) . '">';
    echo '<div class="kv-field"><label for="gw-tt">ToDo の名前<span class="kv-req">必須</span></label>'
       . '<input id="gw-tt" type="text" name="title" maxlength="100" required value="' . kv_h($val('title')) . '"' . $ro . '>' . $err('title') . '</div>';
    echo '<div class="gw-two"><div class="kv-field"><label for="gw-td">期日</label>'
       . '<input id="gw-td" type="date" name="due" value="' . kv_h($val('due')) . '"' . $ro . '>' . $err('due') . '</div>'
       . '<div class="kv-field"><label for="gw-tp">優先度</label><select id="gw-tp" name="priority"' . $ro . '>';
    foreach (kvgw_todo_priorities() as $k => $label) {
        echo '<option value="' . kv_h($k) . '"' . ($val('priority') === (string)$k ? ' selected' : '') . '>' . kv_h($label) . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="gw-two"><div class="kv-field"><label for="gw-tc">カテゴリ</label>'
       . '<input id="gw-tc" type="text" name="category" maxlength="30" list="gw-cats" placeholder="例) 見積・月次・採用" value="' . kv_h($val('category')) . '"' . $ro . '>'
       . '<datalist id="gw-cats">';
    foreach (kvgw_todo_categories($user) as $c) { echo '<option value="' . kv_h($c) . '">'; }
    echo '</datalist></div><div class="kv-field"><label for="gw-to">担当</label><select id="gw-to" name="to"' . $ro . '>'
       . '<option value="' . kv_h($user['id']) . '"' . ($to === $user['id'] ? ' selected' : '') . '>自分</option>';
    $dept = null;
    foreach (kvgw_people() as $p) {
        if ($p['id'] === $user['id']) { continue; }
        if ($p['dept'] !== $dept) {
            if ($dept !== null) { echo '</optgroup>'; }
            $dept = $p['dept'];
            echo '<optgroup label="' . kv_h(kv_dept_name($dept)) . '">';
        }
        echo '<option value="' . kv_h($p['id']) . '"' . ($to === $p['id'] ? ' selected' : '') . '>' . kv_h($p['name']) . '</option>';
    }
    if ($dept !== null) { echo '</optgroup>'; }
    echo '</select>' . $err('to') . '<p class="kv-hint">人を選ぶと、その人の ToDo に入ります</p></div></div>';
    echo '<div class="kv-field"><label for="gw-tm">メモ</label><textarea id="gw-tm" name="memo" rows="4" maxlength="2000"' . $ro . '>'
       . kv_h($val('memo')) . '</textarea></div>';
    echo '<div class="kv-bar" style="margin-bottom:0">';
    if ($canEdit) { echo '<button class="kv-btn" type="submit">' . ($t ? '保存' : '足す') . '</button>'; }
    echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('todo')) . '">' . ($canEdit ? 'やめる' : '戻る') . '</a></div></form>';

    if ($t) {
        $done = !empty($t['done']);
        echo '</div><div class="kv-card"><p class="kv-hint" style="margin:0 0 14px">作った人: ' . kv_h($t['by_name']) . '　'
           . kv_h(substr($t['created'], 0, 16)) . '　／　担当: ' . kv_h($t['to_name'])
           . ($done ? '　／　完了 ' . kv_h(substr($t['done_at'], 0, 16)) : '') . '</p><div class="kv-bar" style="margin:0">';
        if (kvgw_todo_can($user, $t, 'done')) {
            echo '<form method="post" style="flex:0 0 auto;min-width:0" action="' . kv_h(kvgw_url('todo', array('a' => $done ? 'undone' : 'done', 'id' => $id))) . '">'
               . kvgw_csrf() . '<button class="kv-btn' . ($done ? ' sub' : '') . '" type="submit">' . ($done ? '未完了に戻す' : '完了にする') . '</button></form>';
        }
        if (kvgw_todo_can($user, $t, 'delete')) {
            echo '<form method="post" style="flex:0 0 auto;min-width:0" action="' . kv_h(kvgw_url('todo', array('a' => 'delete', 'id' => $id)))
               . '" onsubmit="return confirm(\'この ToDo を削除します。よろしいですか\')">' . kvgw_csrf()
               . '<button class="kv-btn danger" type="submit">削除</button></form>';
        }
        echo '</div>';
    }
    echo '</div></main>';
    kv_foot();
}
