<?php
/**
 * ワークフロー — 稟議・休暇・経費などの申請を、決めた順番で承認していく。
 *
 * Core の業務アプリにある「承認」は、責任者が1回押すだけのもの。こちらは、申請フォームごとに
 * 経路(1〜5段)を決めて、順番に回す。
 *
 * 台帳:
 *   gw_wf_forms    … 申請フォーム(名前・入力項目・経路)。管理者が決める。
 *   gw_wf_requests … 申請(入力した内容・経路の進み具合・履歴・添付の目録)
 *   gw_files/      … 添付の実体。必ず kvgw_wf_can() を通してから渡す。
 *
 * 申請は、出した時点のフォーム(項目と経路)を写して持つ。あとでフォームを直しても、出した申請は変わらない。
 *
 * 状態: draft(下書き) → open(承認待ち) → approved(承認済み) / rejected(却下)
 *                          └ returned(差し戻し) → 書き直して、もう一度 open へ
 */

/* ============================================================
 * 権限 — ワークフローについての判定はここだけ
 * ============================================================ */

function kvgw_wf_can($user, $op, $r = null) {
    if (!$user) { return false; }
    $admin = ($user['role'] === 'admin');
    if ($op === 'manage' || $op === 'all') { return $admin; }     // フォームを決める／全員の申請を見る
    if ($op === 'apply') { return true; }
    if (!$r) { return false; }
    $isFrom = ($r['from'] === $user['id']);
    switch ($op) {
        case 'read':                                              // 申請した人・経路に入っている人・管理者
            if ($isFrom) { return true; }
            if ($r['status'] === 'draft') { return false; }        // 下書きは、書いた本人だけ
            if ($admin) { return true; }
            foreach ($r['steps'] as $s) { if ($s['who'] === $user['id']) { return true; } }
            return false;
        case 'edit':
        case 'delete':   return $isFrom && ($r['status'] === 'draft' || $r['status'] === 'returned');
        case 'withdraw': return $isFrom && $r['status'] === 'open';
        case 'act':                                               // いまの段の承認者だけ
            return $r['status'] === 'open' && isset($r['steps'][$r['cur']]) && $r['steps'][$r['cur']]['who'] === $user['id'];
        case 'reassign': return $admin && $r['status'] === 'open';
    }
    return false;
}

function kvgw_wf_status_labels() {
    return array('draft' => '下書き', 'open' => '承認待ち', 'returned' => '差し戻し', 'approved' => '承認済み', 'rejected' => '却下');
}
function kvgw_wf_status_tag($st) {
    $l = kvgw_wf_status_labels();
    $cls = array('draft' => 'draft', 'open' => 'submitted', 'returned' => 'gw-tagback', 'approved' => 'approved', 'rejected' => 'rejected');
    return '<span class="kv-tag ' . $cls[$st] . '">' . kv_h($l[$st]) . '</span>';
}
function kvgw_wf_types() {
    return array('text' => '1行の文字', 'textarea' => '長い文章', 'number' => '数字', 'date' => '日付', 'select' => '選ぶ');
}
function kvgw_wf_no($r) { return !empty($r['no']) ? 'No.' . (int)$r['no'] : '下書き'; }

/* ============================================================
 * 申請フォーム(管理者が決める)
 * ============================================================ */

function kvgw_wf_forms() {
    $fs = kv_load('gw_wf_forms', 'forms');
    usort($fs, function ($a, $b) {
        if ($a['order'] !== $b['order']) { return $a['order'] < $b['order'] ? -1 : 1; }
        return strcmp($a['name'], $b['name']);
    });
    return $fs;
}

function kvgw_wf_form_find($id) {
    foreach (kvgw_wf_forms() as $f) { if ($f['id'] === $id) { return $f; } }
    return null;
}

/** 経路の1段が「だれ」かを、言葉にする。 */
function kvgw_wf_step_who_label($step) {
    if ($step['kind'] === 'chief') { return '申請した人の部署の責任者'; }
    if ($step['kind'] === 'pick') { return '申請する人が選ぶ'; }
    return kvgw_person_name($step['who']);
}

/**
 * フォームを保存する。$in は画面の入力そのまま:
 *   name, note, order, active, item_key[], item_label[], item_type[], item_req[番号], item_opt[],
 *   step_name[], step_who[]('' / '@chief' / '@pick' / 利用者ID)
 */
function kvgw_wf_form_save($user, $id, $in) {
    if (!kvgw_wf_can($user, 'manage')) { return array(false, '申請フォームを決められるのは管理者だけです'); }
    $name = kvgw_in($in, 'name', 40);
    $note = kvgw_in($in, 'note', 400);
    $order = isset($in['order']) ? max(0, min(999, (int)$in['order'])) : 0;
    if ($name === '') { return array(false, 'フォームの名前を入れてください'); }
    $arr = function ($k) use ($in) { return isset($in[$k]) && is_array($in[$k]) ? $in[$k] : array(); };
    $types = kvgw_wf_types();

    $items = array();
    $labels = $arr('item_label'); $keys = $arr('item_key'); $tps = $arr('item_type'); $opts = $arr('item_opt'); $reqs = $arr('item_req');
    $used = array();
    foreach ($labels as $i => $label) {
        $label = kvgw_in(array('v' => $label), 'v', 40);
        if ($label === '') { continue; }
        $type = isset($tps[$i]) && isset($types[$tps[$i]]) ? (string)$tps[$i] : 'text';
        $options = array();
        if ($type === 'select') {
            $raw = isset($opts[$i]) ? (string)$opts[$i] : '';
            foreach (preg_split('/[,、\n]/u', $raw) as $o) {
                $o = kvgw_in(array('v' => $o), 'v', 40);
                if ($o !== '' && !in_array($o, $options, true)) { $options[] = $o; }
            }
            if (count($options) < 2) { return array(false, '「' . $label . '」は「選ぶ」の項目です。選択肢を2つ以上、「、」で区切って入れてください'); }
        }
        $key = isset($keys[$i]) ? preg_replace('/[^a-z0-9]/', '', (string)$keys[$i]) : '';
        if ($key === '' || isset($used[$key])) { $key = 'f' . kv_random_hex(4); }
        $used[$key] = true;
        $items[] = array('key' => $key, 'label' => $label, 'type' => $type, 'required' => !empty($reqs[$i]), 'options' => $options);
    }
    if (count($items) > 12) { return array(false, '入力項目は12個までです'); }

    $route = array();
    $people = kvgw_people();
    $sn = $arr('step_name'); $sw = $arr('step_who');
    foreach ($sw as $i => $who) {
        $who = (string)$who;
        if ($who === '') { continue; }
        $stepName = kvgw_in(array('v' => isset($sn[$i]) ? $sn[$i] : ''), 'v', 20);
        if ($stepName === '') { $stepName = '承認'; }
        if ($who === '@chief') { $route[] = array('name' => $stepName, 'kind' => 'chief', 'who' => ''); }
        else if ($who === '@pick') { $route[] = array('name' => $stepName, 'kind' => 'pick', 'who' => ''); }
        else if (isset($people[$who])) { $route[] = array('name' => $stepName, 'kind' => 'person', 'who' => $who); }
        else { return array(false, '経路の承認者を選び直してください'); }
    }
    if (!$route) { return array(false, '経路を1段以上、決めてください'); }
    if (count($route) > 5) { return array(false, '経路は5段までです'); }
    $active = !empty($in['active']);

    return kv_update('gw_wf_forms', 'forms', function (&$fs) use ($user, $id, $name, $note, $order, $items, $route, $active) {
        if (!kvgw_wf_can($user, 'manage')) { return '申請フォームを決められるのは管理者だけです'; }
        foreach ($fs as $f) { if ($f['id'] !== $id && $f['name'] === $name) { return '同じ名前のフォームがすでにあります'; } }
        $data = array('name' => $name, 'note' => $note, 'order' => $order, 'items' => $items, 'route' => $route,
                      'active' => $active, 'updated' => kv_now());
        if ($id === '') {
            $f = array_merge(array('id' => 'w' . kv_random_hex(6), 'created' => kv_now()), $data);
            $fs[] = $f;
            return $f;
        }
        foreach ($fs as $i => $f) {
            if ($f['id'] !== $id) { continue; }
            $fs[$i] = array_merge($f, $data);
            return $fs[$i];
        }
        return 'フォームが見つかりません';
    });
}

/** フォームを消しても、出した申請は残る(申請がフォームの写しを持っているため)。 */
function kvgw_wf_form_delete($user, $id) {
    return kv_update('gw_wf_forms', 'forms', function (&$fs) use ($user, $id) {
        if (!kvgw_wf_can($user, 'manage')) { return '申請フォームを決められるのは管理者だけです'; }
        foreach ($fs as $i => $f) {
            if ($f['id'] === $id) { array_splice($fs, $i, 1); return true; }
        }
        return 'フォームが見つかりません';
    });
}

/* ============================================================
 * 申請
 * ============================================================ */

function kvgw_wf_all() { return kv_load('gw_wf_requests', 'requests'); }

function kvgw_wf_find($id) {
    foreach (kvgw_wf_all() as $r) { if ($r['id'] === $id) { return $r; } }
    return null;
}

/**
 * その段の承認者に選べる人。申請した本人は入れない。
 *   person … 決めてある1人        chief … 申請した人の部署の責任者(居なければ管理者)
 *   pick   … 申請した人以外の全員
 */
function kvgw_wf_candidates($applicant, $step) {
    $people = kvgw_people();
    $out = array();
    if ($step['kind'] === 'person') {
        if (isset($people[$step['who']]) && $step['who'] !== $applicant['id']) { $out[] = $people[$step['who']]; }
        return $out;
    }
    if ($step['kind'] === 'chief') {
        foreach ($people as $p) {
            if ($p['id'] !== $applicant['id'] && $p['role'] === 'chief' && $p['dept'] === $applicant['dept']) { $out[] = $p; }
        }
        if (!$out) {
            foreach ($people as $p) { if ($p['id'] !== $applicant['id'] && $p['role'] === 'admin') { $out[] = $p; } }
        }
        return $out;
    }
    foreach ($people as $p) { if ($p['id'] !== $applicant['id']) { $out[] = $p; } }
    return $out;
}

/**
 * 経路を、人の名前まで決める。$picks[段の番号] = 選んだ利用者ID。
 * 決めてある承認者が申請した本人のときは、その段を飛ばす(自分の申請を自分で承認させない)。
 * 返り値: array(true, 段の並び) / array(false, 理由)
 */
function kvgw_wf_resolve($applicant, $route, $picks) {
    $steps = array();
    $real = 0;
    foreach ($route as $i => $st) {
        $cands = kvgw_wf_candidates($applicant, $st);
        $step = array('name' => $st['name'], 'who' => '', 'who_name' => '', 'result' => '', 'at' => '', 'comment' => '');
        if ($st['kind'] === 'person') {
            if ($st['who'] === $applicant['id']) {
                $step['who_name'] = $applicant['name']; $step['result'] = 'skipped';
                $step['comment'] = '申請した本人のため、この段は飛ばしました';
                $steps[] = $step;
                continue;
            }
            if (!$cands) { return array(false, '「' . $st['name'] . '」の承認者が、いまは居ません。管理者に経路を直してもらってください'); }
            $who = $cands[0];
        } else {
            if (!$cands) { return array(false, '「' . $st['name'] . '」の承認者に選べる人が居ません。管理者に経路を直してもらってください'); }
            $want = isset($picks[$i]) ? (string)$picks[$i] : '';
            $who = null;
            foreach ($cands as $c) { if ($c['id'] === $want) { $who = $c; } }
            if (!$who && count($cands) === 1) { $who = $cands[0]; }
            if (!$who) { return array(false, '「' . $st['name'] . '」の承認者を選んでください'); }
        }
        $step['who'] = $who['id']; $step['who_name'] = $who['name'];
        $steps[] = $step;
        $real++;
    }
    if ($real === 0) { return array(false, 'この申請には、承認する人が1人も居ません。管理者に経路を直してもらってください'); }
    return array(true, $steps);
}

/** 入力した値を確かめる。$strict が false(下書き)なら、必須は見ない。 */
function kvgw_wf_values($items, $raw, $strict) {
    $vals = array();
    foreach ($items as $it) {
        $v = isset($raw[$it['key']]) && !is_array($raw[$it['key']]) ? (string)$raw[$it['key']] : '';
        $v = kvgw_in(array('v' => $v), 'v', $it['type'] === 'textarea' ? 5000 : 200);
        if ($v === '') {
            if ($strict && !empty($it['required'])) { return array(false, '「' . $it['label'] . '」を入れてください'); }
            $vals[$it['key']] = '';
            continue;
        }
        if ($it['type'] === 'number') {
            $n = str_replace(array(',', '，', ' '), '', mb_convert_kana($v, 'n', 'UTF-8'));
            if (!preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $n)) { return array(false, '「' . $it['label'] . '」は数字で入れてください'); }
            $v = $n;
        } else if ($it['type'] === 'date') {
            if (!kvgw_date_ok($v)) { return array(false, '「' . $it['label'] . '」は日付で入れてください'); }
        } else if ($it['type'] === 'select') {
            if (!in_array($v, $it['options'], true)) { return array(false, '「' . $it['label'] . '」を選び直してください'); }
        }
        $vals[$it['key']] = $v;
    }
    return array(true, $vals);
}

function kvgw_wf_value_label($it, $v) {
    if ($v === '') { return ''; }
    if ($it['type'] === 'number' && preg_match('/^-?\d+$/', $v)) { return number_format((int)$v); }
    if ($it['type'] === 'date') { return $v . '（' . kvgw_wday($v) . '）'; }
    return $v;
}

function kvgw_wf_log(&$r, $user, $what, $comment) {
    $r['log'][] = array('at' => kv_now(), 'by' => $user['id'], 'by_name' => $user['name'], 'what' => $what, 'comment' => $comment);
    $r['updated'] = kv_now();
}

/** 次に承認する段へ進める。もう段が無ければ承認済みにする。 */
function kvgw_wf_advance(&$r, $from) {
    for ($i = $from; $i < count($r['steps']); $i++) {
        if ($r['steps'][$i]['result'] === '') { $r['cur'] = $i; $r['status'] = 'open'; return; }
    }
    $r['cur'] = count($r['steps']);
    $r['status'] = 'approved';
    $r['closed'] = kv_now();
}

/**
 * 申請を保存する。$send が true なら申請する、false なら下書き。$id が空なら新規($formId のフォームで作る)。
 * $in: title, values[項目キー], picks[段], files, remove
 */
function kvgw_wf_save($user, $id, $formId, $in, $send) {
    $title = kvgw_in($in, 'title', 100);
    $files = isset($in['files']) && is_array($in['files']) ? $in['files'] : array();
    $remove = isset($in['remove']) && is_array($in['remove']) ? $in['remove'] : array();
    $picks = isset($in['picks']) && is_array($in['picks']) ? $in['picks'] : array();
    $raw = isset($in['values']) && is_array($in['values']) ? $in['values'] : array();
    if ($title === '') { return array(false, '件名を入れてください'); }

    $old = $id !== '' ? kvgw_wf_find($id) : null;
    if ($id !== '' && !kvgw_wf_can($user, 'edit', $old)) { return array(false, 'この申請は、いまは書き直せません'); }
    if ($old) { $form = array('id' => $old['form'], 'name' => $old['form_name'], 'items' => $old['items'], 'route' => $old['route']); }
    else {
        $form = kvgw_wf_form_find($formId);
        if (!$form || empty($form['active'])) { return array(false, 'この申請フォームは、いまは使えません'); }
    }
    $vals = kvgw_wf_values($form['items'], $raw, $send);
    if (!$vals[0]) { return $vals; }
    $steps = array();
    if ($send) {
        $rs = kvgw_wf_resolve($user, $form['route'], $picks);
        if (!$rs[0]) { return $rs; }
        $steps = $rs[1];
    }

    $gone = array();
    $res = kv_update('gw_wf_requests', 'requests', function (&$rs) use ($user, $id, $form, $title, $vals, $picks, $steps, $files, $remove, $send, &$gone) {
        $idx = -1;
        if ($id === '') {
            $r = array(
                'id' => 'q' . kv_random_hex(8), 'no' => 0, 'form' => $form['id'], 'form_name' => $form['name'],
                'items' => $form['items'], 'route' => $form['route'],
                'title' => '', 'values' => array(), 'picks' => array(),
                'from' => $user['id'], 'from_name' => $user['name'], 'dept' => $user['dept'],
                'status' => 'draft', 'steps' => array(), 'cur' => 0, 'files' => array(), 'log' => array(),
                'created' => kv_now(), 'updated' => kv_now(), 'sent' => '', 'closed' => '',
            );
        } else {
            foreach ($rs as $i => $x) { if ($x['id'] === $id) { $idx = $i; } }
            if ($idx < 0) { return '申請が見つかりません'; }
            $r = $rs[$idx];
            if (!kvgw_wf_can($user, 'edit', $r)) { return 'この申請は、いまは書き直せません'; }
        }
        $keep = array();
        foreach ($r['files'] as $f) {
            if (in_array($f['id'], $remove, true)) { $gone[] = $f['id']; } else { $keep[] = $f; }
        }
        $r['title'] = $title; $r['values'] = $vals[1]; $r['picks'] = $picks; $r['files'] = array_merge($keep, $files);
        $r['updated'] = kv_now();
        if ($send) {
            if (empty($r['no'])) {
                $max = 0;
                foreach ($rs as $x) { if ((int)$x['no'] > $max) { $max = (int)$x['no']; } }
                $r['no'] = $max + 1;
            }
            $again = ($r['status'] === 'returned');
            $r['steps'] = $steps; $r['sent'] = kv_now(); $r['closed'] = '';
            kvgw_wf_log($r, $user, $again ? '書き直して、もう一度申請' : '申請', '');
            kvgw_wf_advance($r, 0);
        }
        if ($idx < 0) { $rs[] = $r; } else { $rs[$idx] = $r; }
        return $r;
    });
    if ($res[0]) { foreach ($gone as $fid) { kvgw_file_remove($fid); } }
    return $res;
}

/**
 * いまの段の承認者が決める。$act: approve(承認) / back(差し戻し) / reject(却下)
 * 差し戻しと却下は、理由が要る。
 */
function kvgw_wf_act($user, $id, $act, $comment) {
    $comment = kvgw_in(array('c' => $comment), 'c', 2000);
    if (!in_array($act, array('approve', 'back', 'reject'), true)) { return array(false, '操作を選び直してください'); }
    if ($act !== 'approve' && $comment === '') { return array(false, $act === 'back' ? '差し戻す理由を入れてください' : '却下する理由を入れてください'); }
    return kv_update('gw_wf_requests', 'requests', function (&$rs) use ($user, $id, $act, $comment) {
        foreach ($rs as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            /* 2人が同時に開いていても、いまの段の承認者でなければここで止まる */
            if (!kvgw_wf_can($user, 'act', $r)) { return 'この申請は、いまはあなたの承認待ちではありません'; }
            $c = $r['cur'];
            $r['steps'][$c]['at'] = kv_now(); $r['steps'][$c]['comment'] = $comment;
            if ($act === 'approve') {
                $r['steps'][$c]['result'] = 'approved';
                kvgw_wf_log($r, $user, '「' . $r['steps'][$c]['name'] . '」を承認', $comment);
                kvgw_wf_advance($r, $c + 1);
            } else if ($act === 'back') {
                $r['steps'][$c]['result'] = 'returned';
                $r['status'] = 'returned';
                kvgw_wf_log($r, $user, '差し戻し', $comment);
            } else {
                $r['steps'][$c]['result'] = 'rejected';
                $r['status'] = 'rejected'; $r['closed'] = kv_now();
                kvgw_wf_log($r, $user, '却下', $comment);
            }
            $rs[$i] = $r;
            return $r;
        }
        return '申請が見つかりません';
    });
}

/** 申請した人が取り戻す。承認待ちのあいだだけ。下書きに戻る。 */
function kvgw_wf_withdraw($user, $id) {
    return kv_update('gw_wf_requests', 'requests', function (&$rs) use ($user, $id) {
        foreach ($rs as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            if (!kvgw_wf_can($user, 'withdraw', $r)) { return '取り戻せるのは、承認待ちのあいだだけです'; }
            $r['status'] = 'draft'; $r['steps'] = array(); $r['cur'] = 0;
            kvgw_wf_log($r, $user, '取り戻し', '');
            $rs[$i] = $r;
            return $r;
        }
        return '申請が見つかりません';
    });
}

/** 下書きと差し戻しは消せる。承認済み・却下・承認待ちは記録なので消せない。 */
function kvgw_wf_delete($user, $id) {
    $gone = array();
    $res = kv_update('gw_wf_requests', 'requests', function (&$rs) use ($user, $id, &$gone) {
        foreach ($rs as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            if (!kvgw_wf_can($user, 'delete', $r)) { return '消せるのは、下書きと差し戻された申請だけです'; }
            foreach ($r['files'] as $f) { $gone[] = $f['id']; }
            array_splice($rs, $i, 1);
            return true;
        }
        return '申請が見つかりません';
    });
    if ($res[0]) { foreach ($gone as $fid) { kvgw_file_remove($fid); } }
    return $res;
}

/** 管理者が、いまの段の承認者を替える(退職・長い不在で止まった申請を動かすため)。 */
function kvgw_wf_reassign($user, $id, $to) {
    $people = kvgw_people();
    if (!isset($people[$to])) { return array(false, '替える先の人を選んでください'); }
    return kv_update('gw_wf_requests', 'requests', function (&$rs) use ($user, $id, $to, $people) {
        foreach ($rs as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            if (!kvgw_wf_can($user, 'reassign', $r)) { return '承認者を替えられるのは管理者だけです(承認待ちのあいだ)'; }
            if ($to === $r['from']) { return '申請した本人を、承認者にはできません'; }
            $c = $r['cur'];
            if ($r['steps'][$c]['who'] === $to) { return 'すでにその人が承認者です'; }
            $was = $r['steps'][$c]['who_name'];
            $r['steps'][$c]['who'] = $to; $r['steps'][$c]['who_name'] = $people[$to]['name'];
            kvgw_wf_log($r, $user, '「' . $r['steps'][$c]['name'] . '」の承認者を ' . $was . ' から ' . $people[$to]['name'] . ' に替えた', '');
            $rs[$i] = $r;
            return $r;
        }
        return '申請が見つかりません';
    });
}

/**
 * 一覧。$view: todo(自分の承認待ち) / mine(自分の申請) / draft(下書き) / done(自分が処理した) / all(管理者)
 * 読めるものだけを返す。新しい順。
 */
function kvgw_wf_list($user, $view, $q = '', $status = '') {
    $out = array();
    foreach (kvgw_wf_all() as $r) {
        if (!kvgw_wf_can($user, 'read', $r)) { continue; }
        $mine = ($r['from'] === $user['id']);
        if ($view === 'todo') { if (!kvgw_wf_can($user, 'act', $r)) { continue; } }
        else if ($view === 'mine') { if (!$mine || $r['status'] === 'draft') { continue; } }
        else if ($view === 'draft') { if (!$mine || $r['status'] !== 'draft') { continue; } }
        else if ($view === 'done') {
            $did = false;
            foreach ($r['log'] as $l) { if ($l['by'] === $user['id'] && $r['from'] !== $user['id']) { $did = true; } }
            if (!$did || $r['status'] === 'draft') { continue; }
        }
        else if ($view === 'all') { if (!kvgw_wf_can($user, 'all') || $r['status'] === 'draft') { continue; } }
        else { continue; }
        if ($status !== '' && $r['status'] !== $status) { continue; }
        if ($q !== '') {
            $hay = $r['title'] . "\n" . $r['form_name'] . "\n" . $r['from_name'] . "\n" . implode("\n", $r['values']) . "\nNo." . (int)$r['no'];
            if (mb_stripos($hay, $q, 0, 'UTF-8') === false) { continue; }
        }
        $out[] = $r;
    }
    usort($out, function ($a, $b) { return strcmp($b['updated'], $a['updated']); });
    return $out;
}

/** タブとホームに出す数。自分の承認待ち + 自分に差し戻された申請。 */
function kvgw_wf_wait_count($user) {
    $n = 0;
    foreach (kvgw_wf_all() as $r) {
        if (kvgw_wf_can($user, 'act', $r)) { $n++; }
        else if ($r['from'] === $user['id'] && $r['status'] === 'returned') { $n++; }
    }
    return $n;
}

function kvgw_wf_csv($rows) {
    $l = kvgw_wf_status_labels();
    $out = "\xEF\xBB\xBF" . kv_csv_line(array('番号', 'フォーム', '件名', '申請した人', '部署', '状態', '申請した日時', '決まった日時', '内容', '経路'));
    foreach ($rows as $r) {
        $body = array();
        foreach ($r['items'] as $it) {
            $v = isset($r['values'][$it['key']]) ? $r['values'][$it['key']] : '';
            if ($v !== '') { $body[] = $it['label'] . ': ' . $v; }
        }
        $route = array();
        foreach ($r['steps'] as $s) {
            $res = array('' => '未', 'approved' => '承認', 'returned' => '差し戻し', 'rejected' => '却下', 'skipped' => '飛ばした');
            $route[] = $s['name'] . ' ' . $s['who_name'] . '(' . $res[$s['result']] . ($s['at'] !== '' ? ' ' . substr($s['at'], 0, 16) : '') . ')';
        }
        $out .= kv_csv_line(array((int)$r['no'], $r['form_name'], $r['title'], $r['from_name'], kv_dept_name($r['dept']),
                                  $l[$r['status']], $r['sent'], $r['closed'], implode(' / ', $body), implode(' → ', $route)));
    }
    return $out;
}

/* ============================================================
 * 画面と操作
 * ============================================================ */

function kvgw_workflow_route($user) {
    $a = isset($_GET['a']) ? preg_replace('/[^a-z]/', '', (string)$_GET['a']) : '';
    $id = isset($_REQUEST['id']) ? preg_replace('/[^a-z0-9]/', '', (string)$_REQUEST['id']) : '';

    if ($a === 'save') {
        kvgw_need_post();
        $formId = isset($_POST['form']) ? preg_replace('/[^a-z0-9]/', '', (string)$_POST['form']) : '';
        $send = !isset($_POST['draft']);
        $in = array('title' => isset($_POST['title']) ? $_POST['title'] : '',
                    'values' => isset($_POST['v']) && is_array($_POST['v']) ? $_POST['v'] : array(),
                    'picks' => isset($_POST['pick']) && is_array($_POST['pick']) ? array_map('strval', $_POST['pick']) : array(),
                    'remove' => isset($_POST['remove']) && is_array($_POST['remove']) ? array_map('strval', $_POST['remove']) : array());
        $took = kvgw_files_take('files');
        if (!$took[0]) { http_response_code(400); kvgw_workflow_form($user, $id, $formId, $in, $took[1]); return; }
        $in['files'] = $took[1];
        $res = kvgw_wf_save($user, $id, $formId, $in, $send);
        if (!$res[0]) {
            foreach ($took[1] as $f) { kvgw_file_remove($f['id']); }
            http_response_code(400);
            kvgw_workflow_form($user, $id, $formId, $in, $res[1]);
            return;
        }
        kvgw_go('workflow', array('a' => 'view', 'id' => $res[1]['id'], 'msg' => $send ? 'applied' : 'draft'));
    }
    if ($a === 'act') {
        kvgw_need_post();
        $act = isset($_POST['act']) ? preg_replace('/[^a-z]/', '', (string)$_POST['act']) : '';
        $res = kvgw_wf_act($user, $id, $act, isset($_POST['comment']) ? $_POST['comment'] : '');
        if (!$res[0]) { http_response_code(400); kvgw_workflow_view($user, $id, $res[1]); return; }
        $msg = array('approve' => 'approved', 'back' => 'returned', 'reject' => 'rejected');
        kvgw_go('workflow', array('a' => 'view', 'id' => $id, 'msg' => $msg[$act]));
    }
    if ($a === 'withdraw' || $a === 'delete' || $a === 'reassign') {
        kvgw_need_post();
        if ($a === 'withdraw') { $res = kvgw_wf_withdraw($user, $id); }
        else if ($a === 'delete') { $res = kvgw_wf_delete($user, $id); }
        else { $res = kvgw_wf_reassign($user, $id, kvgw_in($_POST, 'to', 40)); }
        if (!$res[0]) { kv_fail(400, $res[1]); }
        if ($a === 'delete') { kvgw_go('workflow', array('v' => 'draft', 'msg' => 'deleted')); }
        kvgw_go('workflow', array('a' => 'view', 'id' => $id, 'msg' => $a === 'withdraw' ? 'withdrawn' : 'saved'));
    }
    if ($a === 'formsave' || $a === 'formdelete') {
        kvgw_need_post();
        if (!kvgw_wf_can($user, 'manage')) { kv_fail(403, '申請フォームを決められるのは管理者だけです。'); }
        if ($a === 'formdelete') {
            $res = kvgw_wf_form_delete($user, $id);
            if (!$res[0]) { kv_fail(400, $res[1]); }
            kvgw_go('workflow', array('a' => 'forms', 'msg' => 'deleted'));
        }
        $res = kvgw_wf_form_save($user, $id, $_POST);
        if (!$res[0]) { http_response_code(400); kvgw_workflow_formedit($user, $id, $_POST, $res[1]); return; }
        kvgw_go('workflow', array('a' => 'forms', 'msg' => 'saved'));
    }
    if ($a === 'forms' || $a === 'formedit') {
        if (!kvgw_wf_can($user, 'manage')) { kv_fail(403, '申請フォームを決められるのは管理者だけです。'); }
        if ($a === 'forms') { kvgw_workflow_forms($user); return; }
        $f = $id !== '' ? kvgw_wf_form_find($id) : null;
        if ($id !== '' && !$f) { kv_fail(404, 'フォームが見つかりません。'); }
        kvgw_workflow_formedit($user, $id, null, '');
        return;
    }
    if ($a === 'csv') {
        if (!kvgw_wf_can($user, 'all')) { kv_fail(403, 'CSV を出せるのは管理者だけです。'); }
        $st = kvgw_in($_GET, 's', 12);
        $labels = kvgw_wf_status_labels();
        $csv = kvgw_wf_csv(kvgw_wf_list($user, 'all', kvgw_in($_GET, 'q', 60), isset($labels[$st]) ? $st : ''));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="workflow-' . date('Ymd') . '.csv"');
        header('Cache-Control: private, no-store');
        echo $csv;
        return;
    }
    if ($a === 'file') {
        $r = kvgw_wf_find($id);
        if (!kvgw_wf_can($user, 'read', $r)) { kv_fail(404, 'ファイルが見つからないか、受け取る権限がありません。'); }
        kvgw_file_send($r['files'], isset($_GET['fid']) ? $_GET['fid'] : '');
        return;
    }
    if ($a === 'pick') { kvgw_workflow_pick($user); return; }
    if ($a === 'new') {
        $formId = isset($_GET['form']) ? preg_replace('/[^a-z0-9]/', '', (string)$_GET['form']) : '';
        $f = kvgw_wf_form_find($formId);
        if (!$f || empty($f['active'])) { kv_fail(404, 'この申請フォームは、いまは使えません。'); }
        kvgw_workflow_form($user, '', $formId, null, '');
        return;
    }
    if ($a === 'edit') {
        $r = kvgw_wf_find($id);
        if (!kvgw_wf_can($user, 'edit', $r)) { kv_fail(404, '申請が見つからないか、いまは書き直せません。'); }
        kvgw_workflow_form($user, $id, $r['form'], null, '');
        return;
    }
    if ($a === 'view') { kvgw_workflow_view($user, $id, ''); return; }
    kvgw_workflow_index($user);
}

function kvgw_workflow_index($user) {
    $views = array('todo' => '承認待ち', 'mine' => '自分の申請', 'draft' => '下書き', 'done' => '処理した申請');
    if (kvgw_wf_can($user, 'all')) { $views['all'] = 'すべての申請'; }
    $v = isset($_GET['v']) && isset($views[$_GET['v']]) ? (string)$_GET['v'] : 'todo';
    $q = kvgw_in($_GET, 'q', 60);
    $labels = kvgw_wf_status_labels();
    $st = kvgw_in($_GET, 's', 12);
    if (!isset($labels[$st]) || $st === 'draft' || ($v !== 'all' && $v !== 'mine')) { $st = ''; }
    $list = kvgw_wf_list($user, $v, $q, $st);
    $per = 30;
    $page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
    $pages = max(1, (int)ceil(count($list) / $per));
    if ($page > $pages) { $page = $pages; }
    $rows = array_slice($list, ($page - 1) * $per, $per);
    $nTodo = count(kvgw_wf_list($user, 'todo'));

    kv_head('ワークフロー', $user);
    kv_nav($user, 'gw:workflow');
    echo '<main class="kv-wrap"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow">WORKFLOW</p>'
       . '<h1 class="kv-title"><span class="kv-emoji">📝</span>ワークフロー</h1>'
       . '<p class="kv-lead">稟議・休暇・経費などを申請して、決めた順番で承認していきます。</p>';
    echo '<div class="kv-bar"><a class="kv-btn" href="' . kv_h(kvgw_url('workflow', array('a' => 'pick'))) . '">＋ 申請する</a>'
       . '<form method="get" action="' . kv_h(kvgw_self()) . '"><input type="hidden" name="m" value="workflow">'
       . '<input type="hidden" name="v" value="' . kv_h($v) . '">'
       . '<input type="search" name="q" value="' . kv_h($q) . '" placeholder="件名・内容・申請した人から探す" aria-label="申請を探す">'
       . '<button class="kv-btn sub" type="submit">探す</button></form>';
    if (kvgw_wf_can($user, 'manage')) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('workflow', array('a' => 'forms'))) . '">申請フォームを決める</a>'; }
    if ($v === 'all') { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('workflow', array('a' => 'csv', 'q' => $q, 's' => $st))) . '">CSV で出す</a>'; }
    echo '</div><div class="gw-tabs">';
    foreach ($views as $k => $label) {
        echo '<a href="' . kv_h(kvgw_url('workflow', array('v' => $k))) . '"' . ($k === $v ? ' class="on"' : '') . '>' . kv_h($label)
           . ($k === 'todo' && $nTodo > 0 ? '<span class="kv-badge">' . $nTodo . '</span>' : '') . '</a>';
    }
    echo '</div>';
    if ($v === 'all' || $v === 'mine') {
        echo '<div class="gw-tabs" style="margin-top:-4px"><a href="' . kv_h(kvgw_url('workflow', array('v' => $v, 'q' => $q))) . '"' . ($st === '' ? ' class="on"' : '') . '>すべて</a>';
        foreach ($labels as $k => $label) {
            if ($k === 'draft') { continue; }
            echo '<a href="' . kv_h(kvgw_url('workflow', array('v' => $v, 'q' => $q, 's' => $k))) . '"' . ($st === $k ? ' class="on"' : '') . '>' . kv_h($label) . '</a>';
        }
        echo '</div>';
    }

    if (!$rows) {
        $none = array('todo' => 'あなたの承認を待っている申請はありません。', 'mine' => '出した申請はまだありません。',
                      'draft' => '下書きはありません。', 'done' => '処理した申請はまだありません。', 'all' => '申請はまだありません。');
        echo '<div class="kv-empty"><span class="kv-emoji">📝</span>' . kv_h($q !== '' ? '「' . $q . '」に合う申請はありません。' : $none[$v]) . '</div>';
    } else {
        echo '<div class="gw-rows">';
        foreach ($rows as $r) {
            $bits = array(kvgw_wf_no($r), $r['form_name']);
            if ($r['from'] !== $user['id']) { $bits[] = $r['from_name']; }
            if ($r['status'] === 'open' && isset($r['steps'][$r['cur']])) { $bits[] = $r['steps'][$r['cur']]['who_name'] . ' の承認待ち'; }
            $hot = kvgw_wf_can($user, 'act', $r) || ($r['from'] === $user['id'] && $r['status'] === 'returned');
            echo '<a class="gw-row' . ($hot ? ' unread' : '') . '" href="' . kv_h(kvgw_url('workflow', array('a' => 'view', 'id' => $r['id']))) . '">'
               . '<span class="gw-dot"></span><span class="gw-rowmain"><b>' . kv_h($r['title']) . ' ' . kvgw_wf_status_tag($r['status']) . '</b>'
               . '<small>' . kv_h(implode('　・　', $bits)) . '</small></span>'
               . '<span class="gw-rowside">' . kv_h(substr($r['updated'], 5, 11)) . '</span></a>';
        }
        echo '</div>';
        if ($pages > 1) {
            echo '<div class="kv-bar" style="margin-bottom:0">';
            if ($page > 1) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('workflow', array('v' => $v, 'q' => $q, 's' => $st, 'p' => $page - 1))) . '">前へ</a>'; }
            echo '<span class="kv-hint" style="margin:0">' . $page . ' / ' . $pages . ' ページ（' . count($list) . ' 件）</span>';
            if ($page < $pages) { echo '<a class="kv-btn sub" href="' . kv_h(kvgw_url('workflow', array('v' => $v, 'q' => $q, 's' => $st, 'p' => $page + 1))) . '">次へ</a>'; }
            echo '</div>';
        }
    }
    echo '</div></main>';
    kv_foot();
}

/** 申請フォームを選ぶ画面。 */
function kvgw_workflow_pick($user) {
    kv_head('申請する｜ワークフロー', $user);
    kv_nav($user, 'gw:workflow');
    echo '<main class="kv-wrap" style="max-width:760px"><div class="kv-card">'
       . '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('workflow')) . '" style="text-decoration:none">← ワークフロー</a></p>'
       . '<h1 class="kv-title"><span class="kv-emoji">📝</span>申請する</h1>'
       . '<p class="kv-lead">申請フォームを選んでください。</p>';
    $n = 0;
    echo '<div class="gw-rows" style="margin-top:14px">';
    foreach (kvgw_wf_forms() as $f) {
        if (empty($f['active'])) { continue; }
        $n++;
        $route = array();
        foreach ($f['route'] as $s) { $route[] = $s['name'] . '（' . kvgw_wf_step_who_label($s) . '）'; }
        echo '<a class="gw-row" href="' . kv_h(kvgw_url('workflow', array('a' => 'new', 'form' => $f['id']))) . '">'
           . '<span class="gw-rowmain"><b>' . kv_h($f['name']) . '</b><small>' . kv_h(($f['note'] !== '' ? $f['note'] . '　' : '') . '経路: ' . implode(' → ', $route)) . '</small></span></a>';
    }
    echo '</div>';
    if ($n === 0) {
        echo '<div class="kv-empty"><span class="kv-emoji">📝</span>使える申請フォームがまだありません。'
           . (kvgw_wf_can($user, 'manage') ? '「申請フォームを決める」から作ってください。' : '管理者に作ってもらってください。') . '</div>';
    }
    echo '</div></main>';
    kv_foot();
}

/** 申請を書く画面(新規・下書き・差し戻しの書き直し)。$in が null なら保存してある内容から。 */
function kvgw_workflow_form($user, $id, $formId, $in, $error) {
    $r = $id !== '' ? kvgw_wf_find($id) : null;
    if ($id !== '' && !kvgw_wf_can($user, 'edit', $r)) { kv_fail(404, '申請が見つからないか、いまは書き直せません。'); }
    if ($r) { $form = array('id' => $r['form'], 'name' => $r['form_name'], 'note' => '', 'items' => $r['items'], 'route' => $r['route']); }
    else {
        $form = kvgw_wf_form_find($formId);
        if (!$form || empty($form['active'])) { kv_fail(404, 'この申請フォームは、いまは使えません。'); }
    }
    if ($in === null) {
        $in = $r ? array('title' => $r['title'], 'values' => $r['values'], 'picks' => $r['picks'])
                 : array('title' => '', 'values' => array(), 'picks' => array());
    }
    $vals = isset($in['values']) && is_array($in['values']) ? $in['values'] : array();
    $picks = isset($in['picks']) && is_array($in['picks']) ? $in['picks'] : array();

    kv_head($form['name'] . '｜ワークフロー', $user);
    kv_nav($user, 'gw:workflow');
    echo '<main class="kv-wrap" style="max-width:760px"><div class="kv-card">'
       . '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('workflow')) . '" style="text-decoration:none">← ワークフロー</a></p>'
       . '<h1 class="kv-title" style="margin-bottom:6px"><span class="kv-emoji">📝</span>' . kv_h($form['name']) . '</h1>';
    if (!empty($form['note'])) { echo '<p class="kv-lead">' . kv_h($form['note']) . '</p>'; }
    if ($r && $r['status'] === 'returned') {
        $last = $r['log'] ? $r['log'][count($r['log']) - 1] : null;
        echo '<p class="kv-error" style="margin:12px 0 0">差し戻されています。' . ($last && $last['comment'] !== '' ? '理由: ' . kv_h($last['comment']) : '') . '</p>';
    }
    if ($error !== '') { echo '<p class="kv-error" style="margin:12px 0 0">' . kv_h($error) . '</p>'; }
    echo '<form method="post" enctype="multipart/form-data" style="margin-top:18px" action="' . kv_h(kvgw_url('workflow', array('a' => 'save'))) . '">'
       . kvgw_csrf() . '<input type="hidden" name="id" value="' . kv_h($id) . '"><input type="hidden" name="form" value="' . kv_h($form['id']) . '">';
    echo '<div class="kv-field"><label for="gw-wt">件名<span class="kv-req">必須</span></label>'
       . '<input id="gw-wt" type="text" name="title" maxlength="100" required value="' . kv_h(kvgw_in($in, 'title', 100)) . '"></div>';
    foreach ($form['items'] as $it) {
        $k = $it['key'];
        $v = isset($vals[$k]) && !is_array($vals[$k]) ? (string)$vals[$k] : '';
        $fid = 'gw-w-' . $k;
        $name = 'v[' . $k . ']';
        echo '<div class="kv-field"><label for="' . kv_h($fid) . '">' . kv_h($it['label'])
           . (!empty($it['required']) ? '<span class="kv-req">必須</span>' : '') . '</label>';
        if ($it['type'] === 'textarea') {
            echo '<textarea id="' . kv_h($fid) . '" name="' . kv_h($name) . '" rows="5" maxlength="5000">' . kv_h($v) . '</textarea>';
        } else if ($it['type'] === 'select') {
            echo '<select id="' . kv_h($fid) . '" name="' . kv_h($name) . '"><option value="">選んでください</option>';
            foreach ($it['options'] as $o) { echo '<option value="' . kv_h($o) . '"' . ($v === $o ? ' selected' : '') . '>' . kv_h($o) . '</option>'; }
            echo '</select>';
        } else if ($it['type'] === 'date') {
            echo '<input id="' . kv_h($fid) . '" type="date" name="' . kv_h($name) . '" value="' . kv_h($v) . '">';
        } else if ($it['type'] === 'number') {
            echo '<input id="' . kv_h($fid) . '" type="text" inputmode="decimal" name="' . kv_h($name) . '" maxlength="20" value="' . kv_h($v) . '">';
        } else {
            echo '<input id="' . kv_h($fid) . '" type="text" name="' . kv_h($name) . '" maxlength="200" value="' . kv_h($v) . '">';
        }
        echo '</div>';
    }
    if ($r && $r['files']) {
        echo '<div class="kv-field"><label>いまの添付</label><div class="kv-choices">';
        foreach ($r['files'] as $f) {
            echo '<label class="kv-choice"><input type="checkbox" name="remove[]" value="' . kv_h($f['id']) . '">' . kv_h($f['name']) . ' を外す</label>';
        }
        echo '</div></div>';
    }
    if (kvgw_upload_on()) {
        echo '<div class="kv-field"><label for="gw-wf">添付ファイル</label><input id="gw-wf" type="file" name="files[]" multiple>'
           . '<p class="kv-hint">見積書・領収書など。1回に5個まで・1個 ' . kvgw_upload_max_mb() . 'MB まで。</p></div>';
    } else if (kv_is_demo()) {
        echo '<p class="kv-hint" style="margin-bottom:17px">デモではファイルの添付を止めています。製品では、見積書や領収書を添付できます。</p>';
    }

    echo '<h2 style="font-size:15px;margin:22px 0 10px">承認の経路</h2><ol class="gw-route">';
    foreach ($form['route'] as $i => $st) {
        $cands = kvgw_wf_candidates($user, $st);
        echo '<li><b>' . kv_h($st['name']) . '</b>';
        if ($st['kind'] === 'person') {
            echo '<span>' . ($st['who'] === $user['id'] ? kv_h($user['name']) . '（申請した本人のため、この段は飛ばします）'
                 : ($cands ? kv_h($cands[0]['name']) : '承認者が、いまは居ません')) . '</span>';
        } else if (count($cands) === 1) {
            echo '<span>' . kv_h($cands[0]['name']) . '</span>';
        } else if (!$cands) {
            echo '<span>選べる人が居ません</span>';
        } else {
            $want = isset($picks[$i]) ? (string)$picks[$i] : '';
            echo '<select name="pick[' . (int)$i . ']" aria-label="' . kv_h($st['name']) . 'の承認者"><option value="">承認者を選んでください</option>';
            foreach ($cands as $c) {
                echo '<option value="' . kv_h($c['id']) . '"' . ($want === $c['id'] ? ' selected' : '') . '>' . kv_h($c['name'] . '（' . kv_dept_name($c['dept']) . '）') . '</option>';
            }
            echo '</select>';
        }
        echo '</li>';
    }
    echo '</ol>';
    echo '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">' . ($r && $r['status'] === 'returned' ? 'もう一度申請する' : '申請する') . '</button>';
    if (!$r || $r['status'] === 'draft') { echo '<button class="kv-btn sub" type="submit" name="draft" value="1" formnovalidate>下書きに保存</button>'; }
    echo '<a class="kv-btn sub" href="' . kv_h($r ? kvgw_url('workflow', array('a' => 'view', 'id' => $id)) : kvgw_url('workflow')) . '">やめる</a></div></form>';
    echo '</div></main>';
    kv_foot();
}

function kvgw_workflow_view($user, $id, $error) {
    $r = kvgw_wf_find($id);
    /* 無いのか、読めないのかを区別して返さない */
    if (!kvgw_wf_can($user, 'read', $r)) { kv_fail(404, '申請が見つからないか、見られる範囲に入っていません。'); }

    kv_head($r['title'] . '｜ワークフロー', $user);
    kv_nav($user, 'gw:workflow');
    echo '<main class="kv-wrap" style="max-width:820px"><div class="kv-card">';
    kvgw_flash();
    if ($error !== '') { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($error) . '</p>'; }
    echo '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('workflow')) . '" style="text-decoration:none">← ワークフロー</a>　'
       . kv_h($r['form_name']) . '　' . kv_h(kvgw_wf_no($r)) . '</p>'
       . '<h1 class="kv-title" style="overflow-wrap:anywhere">' . kv_h($r['title']) . '</h1>'
       . '<p style="margin:8px 0 0">' . kvgw_wf_status_tag($r['status']) . '</p>'
       . '<p class="gw-meta">申請した人 <b>' . kv_h($r['from_name']) . '</b>（' . kv_h(kv_dept_name($r['dept'])) . '）'
       . ($r['sent'] !== '' ? '　' . kv_h(substr($r['sent'], 0, 16)) . ' に申請' : '') . '</p>';
    echo '<dl class="gw-dl">';
    foreach ($r['items'] as $it) {
        $v = isset($r['values'][$it['key']]) ? $r['values'][$it['key']] : '';
        echo '<dt>' . kv_h($it['label']) . '</dt><dd>' . ($v !== '' ? kv_h(kvgw_wf_value_label($it, $v)) : '<span style="color:var(--muted)">—</span>') . '</dd>';
    }
    echo '</dl>';
    if ($r['files']) {
        echo '<div class="gw-files">';
        foreach ($r['files'] as $f) {
            echo '<a href="' . kv_h(kvgw_url('workflow', array('a' => 'file', 'id' => $id, 'fid' => $f['id']))) . '">📎 '
               . kv_h($f['name']) . '（' . kv_h(kvgw_size_label($f['size'])) . '）</a>';
        }
        echo '</div>';
    }
    echo '<div class="kv-bar" style="margin-bottom:0">';
    if (kvgw_wf_can($user, 'edit', $r)) {
        echo '<a class="kv-btn" href="' . kv_h(kvgw_url('workflow', array('a' => 'edit', 'id' => $id))) . '">'
           . ($r['status'] === 'returned' ? '書き直して、もう一度申請する' : '続きを書く') . '</a>';
    }
    if (kvgw_wf_can($user, 'withdraw', $r)) {
        echo '<form method="post" class="gw-inline" style="flex:0 0 auto;min-width:0" action="' . kv_h(kvgw_url('workflow', array('a' => 'withdraw', 'id' => $id)))
           . '" onsubmit="return confirm(\'この申請を取り戻して、下書きに戻します。これまでの承認は取り消しになります。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn sub" type="submit">取り戻す</button></form>';
    }
    if (kvgw_wf_can($user, 'delete', $r)) {
        echo '<form method="post" class="gw-inline" style="flex:0 0 auto;min-width:0" action="' . kv_h(kvgw_url('workflow', array('a' => 'delete', 'id' => $id)))
           . '" onsubmit="return confirm(\'この申請を削除します。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn danger" type="submit">削除</button></form>';
    }
    echo '</div></div>';

    /* 経路 */
    if ($r['steps']) {
        $res = array('' => 'まだ', 'approved' => '承認', 'returned' => '差し戻し', 'rejected' => '却下', 'skipped' => '飛ばした');
        echo '<div class="kv-card"><h2 style="font-size:16px;margin-bottom:10px">承認の経路</h2><ol class="gw-route">';
        foreach ($r['steps'] as $i => $s) {
            $now = ($r['status'] === 'open' && $i === (int)$r['cur']);
            $cls = $now ? 'now' : ($s['result'] === 'approved' ? 'ok' : ($s['result'] === 'rejected' || $s['result'] === 'returned' ? 'ng' : ''));
            echo '<li class="' . $cls . '"><b>' . kv_h($s['name']) . '</b><span>' . kv_h($s['who_name']) . '　'
               . ($now ? '<span class="kv-tag submitted">いま、ここ</span>' : kv_h($res[$s['result']]))
               . ($s['at'] !== '' ? '　' . kv_h(substr($s['at'], 5, 11)) : '') . '</span>'
               . ($s['comment'] !== '' ? '<p>' . kv_h($s['comment']) . '</p>' : '') . '</li>';
        }
        echo '</ol>';

        if (kvgw_wf_can($user, 'act', $r)) {
            echo '<form method="post" style="margin-top:16px" action="' . kv_h(kvgw_url('workflow', array('a' => 'act', 'id' => $id))) . '">' . kvgw_csrf()
               . '<div class="kv-field"><label for="gw-wc">コメント</label>'
               . '<textarea id="gw-wc" name="comment" rows="3" maxlength="2000"></textarea>'
               . '<p class="kv-hint">差し戻す・却下するときは、理由を入れてください。</p></div>'
               . '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit" name="act" value="approve">承認する</button>'
               . '<button class="kv-btn sub" type="submit" name="act" value="back">差し戻す</button>'
               . '<button class="kv-btn danger" type="submit" name="act" value="reject" onclick="return confirm(\'この申請を却下します。却下すると、もう動かせません。よろしいですか\')">却下する</button></div></form>';
        }
        if (kvgw_wf_can($user, 'reassign', $r)) {
            echo '<details class="gw-fold" style="margin-top:18px"><summary>いまの段の承認者を替える（管理者）</summary>'
               . '<form method="post" style="margin-top:10px" action="' . kv_h(kvgw_url('workflow', array('a' => 'reassign', 'id' => $id))) . '">' . kvgw_csrf()
               . '<div class="kv-field"><label for="gw-wr">替える先</label><select id="gw-wr" name="to"><option value="">選んでください</option>';
            foreach (kvgw_people() as $p) {
                if ($p['id'] === $r['from'] || $p['id'] === $r['steps'][$r['cur']]['who']) { continue; }
                echo '<option value="' . kv_h($p['id']) . '">' . kv_h($p['name'] . '（' . kv_dept_name($p['dept']) . '）') . '</option>';
            }
            echo '</select><p class="kv-hint">退職や長い不在で申請が止まったときに使います。替えたことは履歴に残ります。</p></div>'
               . '<button class="kv-btn sub" type="submit">替える</button></form></details>';
        }
        echo '</div>';
    }

    if ($r['log']) {
        echo '<div class="kv-card"><h2 style="font-size:16px;margin-bottom:6px">履歴</h2>';
        foreach ($r['log'] as $l) {
            echo '<article class="gw-comment"><header><b>' . kv_h($l['by_name']) . '</b><span>' . kv_h(substr($l['at'], 0, 16)) . '</span></header>'
               . '<p>' . kv_h($l['what']) . ($l['comment'] !== '' ? "\n" . kv_h($l['comment']) : '') . '</p></article>';
        }
        echo '</div>';
    }
    echo '</main>';
    kv_foot();
}

/** 申請フォームの一覧(管理者)。 */
function kvgw_workflow_forms($user) {
    $count = array();
    foreach (kvgw_wf_all() as $r) { $count[$r['form']] = isset($count[$r['form']]) ? $count[$r['form']] + 1 : 1; }
    kv_head('申請フォームを決める｜ワークフロー', $user);
    kv_nav($user, 'gw:workflow');
    echo '<main class="kv-wrap" style="max-width:820px"><div class="kv-card">';
    kvgw_flash();
    echo '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('workflow')) . '" style="text-decoration:none">← ワークフロー</a></p>'
       . '<h1 class="kv-title"><span class="kv-emoji">📝</span>申請フォームを決める</h1>'
       . '<p class="kv-lead">フォームごとに、入力する項目と、承認の経路を決めます。フォームを直しても、すでに出した申請は変わりません。</p>'
       . '<div class="kv-bar"><a class="kv-btn" href="' . kv_h(kvgw_url('workflow', array('a' => 'formedit'))) . '">＋ フォームを作る</a></div>';
    $fs = kvgw_wf_forms();
    if (!$fs) { echo '<div class="kv-empty"><span class="kv-emoji">📝</span>申請フォームがまだありません。</div>'; }
    else {
        echo '<div class="gw-rows">';
        foreach ($fs as $f) {
            $route = array();
            foreach ($f['route'] as $s) { $route[] = $s['name'] . '（' . kvgw_wf_step_who_label($s) . '）'; }
            echo '<a class="gw-row" href="' . kv_h(kvgw_url('workflow', array('a' => 'formedit', 'id' => $f['id']))) . '">'
               . '<span class="gw-rowmain"><b>' . kv_h($f['name']) . (empty($f['active']) ? ' <span class="kv-tag draft">止めている</span>' : '') . '</b>'
               . '<small>' . kv_h('項目 ' . count($f['items']) . '個　・　' . implode(' → ', $route)) . '</small></span>'
               . '<span class="gw-rowside">申請 ' . (isset($count[$f['id']]) ? $count[$f['id']] : 0) . ' 件</span></a>';
        }
        echo '</div>';
    }
    echo '</div></main>';
    kv_foot();
}

/** 申請フォームを作る・直す画面(管理者)。$in が null なら保存してある内容から。 */
function kvgw_workflow_formedit($user, $id, $in, $error) {
    $f = $id !== '' ? kvgw_wf_form_find($id) : null;
    if ($id !== '' && !$f) { kv_fail(404, 'フォームが見つかりません。'); }
    $types = kvgw_wf_types();
    $items = array(); $route = array();
    if ($in === null) {
        $name = $f ? $f['name'] : ''; $note = $f ? $f['note'] : ''; $order = $f ? $f['order'] : (count(kvgw_wf_forms()) + 1) * 10;
        $active = $f ? !empty($f['active']) : true;
        if ($f) {
            foreach ($f['items'] as $it) { $items[] = array($it['key'], $it['label'], $it['type'], !empty($it['required']), implode('、', $it['options'])); }
            foreach ($f['route'] as $s) { $route[] = array($s['name'], $s['kind'] === 'person' ? $s['who'] : '@' . $s['kind']); }
        }
    } else {
        $name = kvgw_in($in, 'name', 40); $note = kvgw_in($in, 'note', 400); $order = isset($in['order']) ? (int)$in['order'] : 0;
        $active = !empty($in['active']);
        $g = function ($k, $i) use ($in) { return isset($in[$k][$i]) && !is_array($in[$k][$i]) ? (string)$in[$k][$i] : ''; };
        $labels = isset($in['item_label']) && is_array($in['item_label']) ? $in['item_label'] : array();
        foreach ($labels as $i => $x) {
            $items[] = array($g('item_key', $i), $g('item_label', $i), $g('item_type', $i), !empty($in['item_req'][$i]), $g('item_opt', $i));
        }
        $whos = isset($in['step_who']) && is_array($in['step_who']) ? $in['step_who'] : array();
        foreach ($whos as $i => $x) { $route[] = array($g('step_name', $i), $g('step_who', $i)); }
    }
    $items = array_slice($items, 0, 12);
    while (count($items) < 12) { $items[] = array('', '', 'text', false, ''); }
    $route = array_slice($route, 0, 5);
    while (count($route) < 5) { $route[] = array('', ''); }

    kv_head(($f ? 'フォームを直す' : 'フォームを作る') . '｜ワークフロー', $user);
    kv_nav($user, 'gw:workflow');
    echo '<main class="kv-wrap" style="max-width:820px"><div class="kv-card">'
       . '<p class="kv-eyebrow"><a href="' . kv_h(kvgw_url('workflow', array('a' => 'forms'))) . '" style="text-decoration:none">← 申請フォーム</a></p>'
       . '<h1 class="kv-title" style="margin-bottom:18px"><span class="kv-emoji">📝</span>' . ($f ? 'フォームを直す' : 'フォームを作る') . '</h1>';
    if ($error !== '') { echo '<p class="kv-error" style="margin-bottom:14px">' . kv_h($error) . '</p>'; }
    echo '<form method="post" action="' . kv_h(kvgw_url('workflow', array('a' => 'formsave', 'id' => $id))) . '">' . kvgw_csrf();
    echo '<div class="gw-two"><div class="kv-field"><label for="gw-fn">フォームの名前<span class="kv-req">必須</span></label>'
       . '<input id="gw-fn" type="text" name="name" maxlength="40" required value="' . kv_h($name) . '" placeholder="例: 稟議書"></div>'
       . '<div class="kv-field"><label for="gw-fo">並び順</label><input id="gw-fo" type="number" name="order" min="0" max="999" value="' . kv_h($order) . '"></div></div>';
    echo '<div class="kv-field"><label for="gw-fm">申請する人への説明</label>'
       . '<input id="gw-fm" type="text" name="note" maxlength="400" value="' . kv_h($note) . '" placeholder="例: 10万円以上の購入は、見積書を添付してください"></div>';

    echo '<h2 style="font-size:15px;margin:22px 0 4px">入力する項目</h2>'
       . '<p class="kv-hint" style="margin-bottom:10px">件名は、どのフォームにも初めから付いています。使わない行は、項目名を空のままにしてください。「選ぶ」の選択肢は「、」で区切ります。</p>';
    foreach ($items as $i => $it) {
        echo '<div class="gw-wfitem"><input type="hidden" name="item_key[' . $i . ']" value="' . kv_h($it[0]) . '">'
           . '<input type="text" name="item_label[' . $i . ']" maxlength="40" value="' . kv_h($it[1]) . '" placeholder="項目名 ' . ($i + 1) . '" aria-label="項目名 ' . ($i + 1) . '">'
           . '<select name="item_type[' . $i . ']" aria-label="種類 ' . ($i + 1) . '">';
        foreach ($types as $k => $label) { echo '<option value="' . kv_h($k) . '"' . ($it[2] === $k ? ' selected' : '') . '>' . kv_h($label) . '</option>'; }
        echo '</select><label class="kv-choice"><input type="checkbox" name="item_req[' . $i . ']" value="1"' . ($it[3] ? ' checked' : '') . '>必須</label>'
           . '<input type="text" name="item_opt[' . $i . ']" maxlength="400" value="' . kv_h($it[4]) . '" placeholder="選択肢（「選ぶ」のとき）" aria-label="選択肢 ' . ($i + 1) . '"></div>';
    }

    echo '<h2 style="font-size:15px;margin:22px 0 4px">承認の経路</h2>'
       . '<p class="kv-hint" style="margin-bottom:10px">上から順に回ります。5段まで。使わない段は「使わない」のままにしてください。</p>';
    $people = kvgw_people();
    foreach ($route as $i => $st) {
        echo '<div class="gw-wfstep"><span>' . ($i + 1) . '</span>'
           . '<input type="text" name="step_name[' . $i . ']" maxlength="20" value="' . kv_h($st[0]) . '" placeholder="段の名前（例: 部長承認）" aria-label="段の名前 ' . ($i + 1) . '">'
           . '<select name="step_who[' . $i . ']" aria-label="承認者 ' . ($i + 1) . '"><option value="">使わない</option>'
           . '<option value="@chief"' . ($st[1] === '@chief' ? ' selected' : '') . '>申請した人の部署の責任者</option>'
           . '<option value="@pick"' . ($st[1] === '@pick' ? ' selected' : '') . '>申請する人が選ぶ</option>';
        foreach ($people as $p) {
            echo '<option value="' . kv_h($p['id']) . '"' . ($st[1] === $p['id'] ? ' selected' : '') . '>' . kv_h($p['name'] . '（' . kv_dept_name($p['dept']) . '）') . '</option>';
        }
        echo '</select></div>';
    }
    echo '<p class="kv-hint" style="margin:10px 0 0">「申請した人の部署の責任者」は、その部署に責任者が居ないとき(申請した人が責任者のときも)、管理者が承認者になります。'
       . '決めた承認者が申請した本人のときは、その段を飛ばします。</p>';
    echo '<div class="kv-field" style="margin-top:18px"><label class="kv-choice" style="display:inline-flex"><input type="checkbox" name="active" value="1"'
       . ($active ? ' checked' : '') . '>このフォームを使う（外すと、新しい申請に選べなくなります）</label></div>';
    echo '<div class="kv-bar" style="margin-bottom:0"><button class="kv-btn" type="submit">保存する</button>'
       . '<a class="kv-btn sub" href="' . kv_h(kvgw_url('workflow', array('a' => 'forms'))) . '">やめる</a></div></form>';
    if ($f) {
        echo '<form method="post" style="margin-top:14px" action="' . kv_h(kvgw_url('workflow', array('a' => 'formdelete', 'id' => $id)))
           . '" onsubmit="return confirm(\'このフォームを削除します。すでに出した申請は残ります。よろしいですか\')">' . kvgw_csrf()
           . '<button class="kv-btn danger" type="submit">このフォームを削除</button></form>';
    }
    echo '</div></main>';
    kv_foot();
}
