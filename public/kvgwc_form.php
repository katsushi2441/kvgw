<?php
/**
 * kvgwc フォーム — フィールド定義から、入力欄・一覧・検証を作る。
 *
 * アプリを増やす人が書くのは apps/*.php の 'fields' 配列だけ。HTMLも検証も
 * ここが引き受ける。だから「新しいアプリを作る」= 「配列を1枚書く」になる。
 *
 * 検証で落ちた入力は、呼び出し側が 400 で返すこと。500で包むと外形監視が
 * 「サーバーが落ちた」と誤判定する。入力ミスはサーバーの障害ではない。
 */

require_once __DIR__ . '/kvgwc_core.php';

function kv_field_types() {
    return array('text', 'textarea', 'number', 'date', 'time', 'datetime',
                 'select', 'radio', 'checkbox', 'email', 'tel', 'url');
}

function kv_field_default($f) {
    if (isset($f['default'])) { return $f['default']; }
    if ($f['type'] === 'checkbox') { return array(); }
    return '';
}

/* ============================================================
 * 検証
 * ============================================================ */

/**
 * $post を定義に照らして検証する。
 * 戻り値: array($values, $errors)  — $errors が空なら通過。
 * 定義に無いキーは捨てる(画面にない項目を勝手に保存させない)。
 */
function kv_validate($app, $post) {
    $values = array();
    $errors = array();
    foreach ($app['fields'] as $f) {
        $key = $f['key'];
        $type = isset($f['type']) ? $f['type'] : 'text';
        $label = isset($f['label']) ? $f['label'] : $key;
        $required = !empty($f['required']);

        if ($type === 'checkbox') {
            $raw = isset($post[$key]) && is_array($post[$key]) ? $post[$key] : array();
            $opts = isset($f['options']) ? $f['options'] : array();
            $picked = array();
            foreach ($raw as $v) { if (in_array((string)$v, $opts, true)) { $picked[] = (string)$v; } }
            if ($required && count($picked) === 0) { $errors[$key] = $label . 'を選んでください'; }
            $values[$key] = $picked;
            continue;
        }

        $v = isset($post[$key]) ? trim((string)$post[$key]) : '';
        if ($v === '') {
            if ($required) { $errors[$key] = $label . 'を入力してください'; }
            $values[$key] = '';
            continue;
        }

        if ($type === 'select' || $type === 'radio') {
            $opts = isset($f['options']) ? $f['options'] : array();
            if (!in_array($v, $opts, true)) { $errors[$key] = $label . 'の選択が不正です'; }
        } else if ($type === 'number') {
            if (!is_numeric($v)) { $errors[$key] = $label . 'は数字で入力してください'; }
            else {
                $n = $v + 0;
                if (isset($f['min']) && $n < $f['min']) { $errors[$key] = $label . 'が小さすぎます'; }
                if (isset($f['max']) && $n > $f['max']) { $errors[$key] = $label . 'が大きすぎます'; }
                $v = (string)$n;
            }
        } else if ($type === 'date') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $errors[$key] = $label . 'は日付で入力してください'; }
        } else if ($type === 'time') {
            if (!preg_match('/^\d{2}:\d{2}$/', $v)) { $errors[$key] = $label . 'は時刻で入力してください'; }
        } else if ($type === 'datetime') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v)) { $errors[$key] = $label . 'は日時で入力してください'; }
            $v = str_replace('T', ' ', $v);
        } else if ($type === 'email') {
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[$key] = $label . 'の形式が正しくありません'; }
        } else if ($type === 'url') {
            if (!filter_var($v, FILTER_VALIDATE_URL)) { $errors[$key] = $label . 'の形式が正しくありません'; }
        }

        $max = isset($f['maxlength']) ? (int)$f['maxlength'] : ($type === 'textarea' ? 5000 : 500);
        if (mb_strlen($v, 'UTF-8') > $max) { $errors[$key] = $label . 'が長すぎます(' . $max . '文字まで)'; }

        $values[$key] = $v;
    }
    return array($values, $errors);
}

/* ============================================================
 * 入力欄
 * ============================================================ */

function kv_field_input($f, $value) {
    $key = kv_h($f['key']);
    $type = isset($f['type']) ? $f['type'] : 'text';
    $req = !empty($f['required']) ? ' required' : '';
    $ph = isset($f['placeholder']) ? ' placeholder="' . kv_h($f['placeholder']) . '"' : '';

    if ($type === 'textarea') {
        $rows = isset($f['rows']) ? (int)$f['rows'] : 4;
        return '<textarea name="' . $key . '" id="f_' . $key . '" rows="' . $rows . '"' . $req . $ph . '>'
             . kv_h($value) . '</textarea>';
    }
    if ($type === 'select') {
        $h = '<select name="' . $key . '" id="f_' . $key . '"' . $req . '>';
        $h .= '<option value="">選択してください</option>';
        foreach ($f['options'] as $o) {
            $sel = ((string)$value === (string)$o) ? ' selected' : '';
            $h .= '<option value="' . kv_h($o) . '"' . $sel . '>' . kv_h($o) . '</option>';
        }
        return $h . '</select>';
    }
    if ($type === 'radio') {
        $h = '<div class="kv-choices">';
        foreach ($f['options'] as $i => $o) {
            $chk = ((string)$value === (string)$o) ? ' checked' : '';
            $id = 'f_' . $key . '_' . $i;
            $h .= '<label class="kv-choice"><input type="radio" name="' . $key . '" id="' . $id
                . '" value="' . kv_h($o) . '"' . $chk . '> ' . kv_h($o) . '</label>';
        }
        return $h . '</div>';
    }
    if ($type === 'checkbox') {
        $cur = is_array($value) ? $value : array();
        $h = '<div class="kv-choices">';
        foreach ($f['options'] as $i => $o) {
            $chk = in_array((string)$o, $cur, true) ? ' checked' : '';
            $id = 'f_' . $key . '_' . $i;
            $h .= '<label class="kv-choice"><input type="checkbox" name="' . $key . '[]" id="' . $id
                . '" value="' . kv_h($o) . '"' . $chk . '> ' . kv_h($o) . '</label>';
        }
        return $h . '</div>';
    }

    $map = array('number' => 'number', 'date' => 'date', 'time' => 'time',
                 'datetime' => 'datetime-local', 'email' => 'email', 'tel' => 'tel', 'url' => 'url');
    $html_type = isset($map[$type]) ? $map[$type] : 'text';
    $extra = '';
    if ($type === 'number') {
        if (isset($f['min'])) { $extra .= ' min="' . kv_h($f['min']) . '"'; }
        if (isset($f['max'])) { $extra .= ' max="' . kv_h($f['max']) . '"'; }
        if (isset($f['step'])) { $extra .= ' step="' . kv_h($f['step']) . '"'; }
    }
    if ($type === 'datetime') { $value = str_replace(' ', 'T', (string)$value); }
    return '<input type="' . $html_type . '" name="' . $key . '" id="f_' . $key
         . '" value="' . kv_h($value) . '"' . $req . $ph . $extra . '>';
}

/** フォーム本体。$errors があればその欄の下に赤字で出す。 */
function kv_form_fields($app, $values, $errors) {
    $h = '';
    foreach ($app['fields'] as $f) {
        $key = $f['key'];
        $label = isset($f['label']) ? $f['label'] : $key;
        $v = isset($values[$key]) ? $values[$key] : kv_field_default($f);
        $h .= '<div class="kv-field">';
        $h .= '<label for="f_' . kv_h($key) . '">' . kv_h($label)
            . (!empty($f['required']) ? '<span class="kv-req">必須</span>' : '') . '</label>';
        $h .= kv_field_input($f, $v);
        if (!empty($f['hint'])) { $h .= '<p class="kv-hint">' . kv_h($f['hint']) . '</p>'; }
        if (isset($errors[$key])) { $h .= '<p class="kv-error">' . kv_h($errors[$key]) . '</p>'; }
        $h .= '</div>';
    }
    return $h;
}

/* ============================================================
 * 表示・一覧
 * ============================================================ */

function kv_field_by_key($app, $key) {
    foreach ($app['fields'] as $f) { if ($f['key'] === $key) { return $f; } }
    return null;
}

function kv_display($app, $record, $key) {
    if ($key === '_owner') { return isset($record['_owner_name']) ? $record['_owner_name'] : ''; }
    if ($key === '_dept') { return kv_dept_name(isset($record['_dept']) ? $record['_dept'] : ''); }
    if ($key === '_status') { return kv_status_label(isset($record['_status']) ? $record['_status'] : ''); }
    if ($key === '_created' || $key === '_updated') { return isset($record[$key]) ? $record[$key] : ''; }

    $f = kv_field_by_key($app, $key);
    $v = isset($record[$key]) ? $record[$key] : '';
    if (is_array($v)) { return implode('、', $v); }
    if ($f && $f['type'] === 'number' && $v !== '') { return number_format($v + 0); }
    return (string)$v;
}

/** 一覧に出す列。定義になければ、先頭3項目＋作成者＋状態で組み立てる。 */
function kv_list_columns($app) {
    if (!empty($app['list_columns'])) { return $app['list_columns']; }
    $cols = array();
    foreach ($app['fields'] as $f) {
        $cols[] = $f['key'];
        if (count($cols) >= 3) { break; }
    }
    $cols[] = '_owner';
    if ($app['approval']) { $cols[] = '_status'; }
    return $cols;
}

function kv_column_label($app, $key) {
    $fixed = array('_owner' => '作成者', '_dept' => '部署', '_status' => '状態',
                   '_created' => '作成日時', '_updated' => '更新日時');
    if (isset($fixed[$key])) { return $fixed[$key]; }
    $f = kv_field_by_key($app, $key);
    return $f && isset($f['label']) ? $f['label'] : $key;
}

/** 一覧の絞り込み。全項目を横断して部分一致で探す(項目を選ばせない)。 */
function kv_search($app, $records, $q) {
    $q = trim((string)$q);
    if ($q === '') { return $records; }
    $out = array();
    foreach ($records as $r) {
        $hay = '';
        foreach ($app['fields'] as $f) {
            $v = isset($r[$f['key']]) ? $r[$f['key']] : '';
            $hay .= (is_array($v) ? implode(' ', $v) : $v) . ' ';
        }
        $hay .= isset($r['_owner_name']) ? $r['_owner_name'] : '';
        if (mb_stripos($hay, $q, 0, 'UTF-8') !== false) { $out[] = $r; }
    }
    return $out;
}

/** 新しい順に並べる。並べ替えの基準は定義の sort_key、なければ作成日時。 */
function kv_sort($app, $records) {
    $key = isset($app['sort_key']) ? $app['sort_key'] : '_created';
    $desc = !isset($app['sort_asc']) || !$app['sort_asc'];
    usort($records, function ($a, $b) use ($key, $desc) {
        $va = isset($a[$key]) ? (string)$a[$key] : '';
        $vb = isset($b[$key]) ? (string)$b[$key] : '';
        $c = strcmp($va, $vb);
        return $desc ? -$c : $c;
    });
    return $records;
}

/** CSV出力。Excelで開くので BOM 付き Shift_JIS ではなく UTF-8 BOM にする。 */
function kv_csv($app, $records) {
    $cols = array();
    foreach ($app['fields'] as $f) { $cols[] = $f['key']; }
    $cols = array_merge($cols, array('_owner', '_dept', '_status', '_created', '_updated'));
    $out = "\xEF\xBB\xBF";
    $head = array();
    foreach ($cols as $c) { $head[] = kv_column_label($app, $c); }
    $out .= kv_csv_line($head);
    foreach ($records as $r) {
        $line = array();
        foreach ($cols as $c) { $line[] = kv_display($app, $r, $c); }
        $out .= kv_csv_line($line);
    }
    return $out;
}

function kv_csv_line($cells) {
    $esc = array();
    foreach ($cells as $c) { $esc[] = '"' . str_replace('"', '""', (string)$c) . '"'; }
    return implode(',', $esc) . "\r\n";
}
