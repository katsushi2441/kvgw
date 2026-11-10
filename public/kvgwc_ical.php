<?php
/**
 * kvgwc カレンダー配信 — iPhone / Android / Google カレンダーで見えるようにする。
 *
 * 仕組みは「購読URL(.ics)を配る」。スマホ側の標準カレンダーに登録すると、
 * 以後は端末が定期的に取りに来て自動更新される。専用アプリは要らない。
 *
 * 【なぜフルCalDAVサーバーにしないか】
 * スマホから予定を「書き込む」にはCalDAV(PROPFIND/REPORT/PUT)が要るが、
 * 共有スケジュールの用途は圧倒的に「見る」側。読み取り専用の購読なら
 * 標準カレンダーがそのまま対応していて、設置も1URLで済む。
 * 書き込みまで要るお客様には kcaldav を併設する。
 *
 * 【権限はここでも効かせる】
 * 購読URLはセッションを持たない(カレンダーアプリはCookieを送らない)ので、
 * ユーザーごとの合言葉(token)で本人を特定し、その人が読める範囲だけを流す。
 * ここを手抜きすると、URLを知っている人に全社の予定が漏れる。
 */

require_once __DIR__ . '/kvgwc_core.php';

/** そのアプリがカレンダー配信に対応しているか(定義に 'caldav' があるか)。 */
function kv_cal_map($app) {
    if (empty($app['caldav']) || !is_array($app['caldav'])) { return null; }
    $m = $app['caldav'];
    if (empty($m['date'])) { return null; }      // 日付が無いとカレンダーにならない
    return array(
        'date'     => $m['date'],
        'start'    => isset($m['start']) ? $m['start'] : '',
        'end'      => isset($m['end']) ? $m['end'] : '',
        'title'    => isset($m['title']) ? $m['title'] : '',
        'location' => isset($m['location']) ? $m['location'] : '',
        'note'     => isset($m['note']) ? $m['note'] : '',
    );
}

/**
 * その人の購読用の合言葉。無ければ作って台帳に保存する。
 * 漏れたと分かったら kv_cal_reset() で作り直せる(古いURLは即座に無効になる)。
 */
function kv_cal_token($userId) {
    foreach (kv_users() as $u) {
        if ($u['id'] === $userId && !empty($u['cal_token'])) { return $u['cal_token']; }
    }
    $token = kv_random_hex(16);
    kv_update('users', 'users', function (&$users) use ($userId, &$token) {
        foreach ($users as $i => $u) {
            if ($u['id'] !== $userId) { continue; }
            if (!empty($u['cal_token'])) { $token = $u['cal_token']; return $u; }
            $users[$i]['cal_token'] = $token;
            return $users[$i];
        }
        return 'ユーザーが見つかりません';
    });
    return $token;
}

function kv_cal_reset($userId) {
    $token = kv_random_hex(16);
    kv_update('users', 'users', function (&$users) use ($userId, $token) {
        foreach ($users as $i => $u) {
            if ($u['id'] !== $userId) { continue; }
            $users[$i]['cal_token'] = $token;
            return $users[$i];
        }
        return 'ユーザーが見つかりません';
    });
    return $token;
}

/**
 * 合言葉から本人を引く。一致しなければ null(=配信しない)。
 *
 * 必ず kv_user_find() で引くこと。台帳の生の値を見ると、退職した社員の
 * 購読URLが生き続ける(アカウントの active は true のままなので)。
 * 在籍状態の反映は kv_user_compose() だけが持っている。
 */
function kv_cal_user($userId, $token) {
    if ($userId === '' || $token === '') { return null; }
    $u = kv_user_find($userId);
    if (!$u || empty($u['active'])) { return null; }
    if (empty($u['cal_token']) || !hash_equals($u['cal_token'], $token)) { return null; }
    return $u;
}

/* ============================================================
 * iCalendar の組み立て
 * ============================================================ */

/** RFC5545 のエスケープ。ここを抜かすと , や ; で予定が分裂する。 */
function kv_ics_escape($v) {
    $v = str_replace("\\", "\\\\", (string)$v);
    $v = str_replace(array("\r\n", "\r", "\n"), "\\n", $v);
    $v = str_replace(array(',', ';'), array('\\,', '\\;'), $v);
    return $v;
}

/**
 * 75オクテットで折り返す(RFC5545の必須要件)。折り返し行の頭は空白1つ。
 * マルチバイトの途中で切ると文字化けするので、バイトではなく文字単位で数える。
 */
function kv_ics_fold($line) {
    if (strlen($line) <= 73) { return $line . "\r\n"; }
    $out = '';
    $cur = '';
    $len = mb_strlen($line, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($line, $i, 1, 'UTF-8');
        if (strlen($cur) + strlen($ch) > 73) {
            $out .= $cur . "\r\n ";
            $cur = '';
        }
        $cur .= $ch;
    }
    return $out . $cur . "\r\n";
}

/** JSTの「日付＋時刻」をUTCの基本形式(YYYYMMDDTHHMMSSZ)にする。 */
function kv_ics_utc($date, $time) {
    $ts = strtotime($date . ' ' . $time . ' +0900');
    if ($ts === false) { return ''; }
    return gmdate('Ymd\THis\Z', $ts);
}

/**
 * 1件を VEVENT にする。
 * 時刻が無ければ終日予定。終日の DTEND は「翌日」を書く決まりなので +1日する
 * (ここを当日にすると、多くのカレンダーで予定が消える)。
 */
function kv_ics_event($app, $map, $r) {
    $date = isset($r[$map['date']]) ? (string)$r[$map['date']] : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return ''; }

    $start = ($map['start'] !== '' && !empty($r[$map['start']])) ? (string)$r[$map['start']] : '';
    $end   = ($map['end'] !== '' && !empty($r[$map['end']]))   ? (string)$r[$map['end']]   : '';
    $title = ($map['title'] !== '' && isset($r[$map['title']])) ? (string)$r[$map['title']] : $app['name'];
    $loc   = ($map['location'] !== '' && isset($r[$map['location']])) ? (string)$r[$map['location']] : '';
    $note  = ($map['note'] !== '' && isset($r[$map['note']])) ? (string)$r[$map['note']] : '';

    $lines = array();
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $r['_id'] . '-' . $app['key'] . '@kvgwc';
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');

    if ($start === '') {
        $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $date);
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($date . ' +1 day'));
    } else {
        $lines[] = 'DTSTART:' . kv_ics_utc($date, $start);
        if ($end === '' || $end <= $start) {
            $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', strtotime($date . ' ' . $start . ' +0900') + 3600);
        } else {
            $lines[] = 'DTEND:' . kv_ics_utc($date, $end);
        }
    }

    // 誰の予定かが分からないと共有カレンダーとして役に立たない
    $who = isset($r['_owner_name']) ? $r['_owner_name'] : '';
    $lines[] = 'SUMMARY:' . kv_ics_escape($who !== '' ? $title . '（' . $who . '）' : $title);
    if ($loc !== '')  { $lines[] = 'LOCATION:' . kv_ics_escape($loc); }

    $desc = array();
    if ($note !== '') { $desc[] = $note; }
    if ($who !== '')  { $desc[] = '登録: ' . $who . '（' . kv_dept_name($r['_dept']) . '）'; }
    if ($desc)        { $lines[] = 'DESCRIPTION:' . kv_ics_escape(implode("\n", $desc)); }

    if (!empty($r['_updated'])) {
        $lines[] = 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', strtotime($r['_updated'] . ' +0900'));
    }
    $lines[] = 'END:VEVENT';

    $out = '';
    foreach ($lines as $l) { $out .= kv_ics_fold($l); }
    return $out;
}

/** そのユーザーが読める範囲の記録だけで、購読用の .ics を作る。 */
function kv_ics_build($app, $user) {
    $map = kv_cal_map($app);
    if (!$map) { return ''; }
    $site = defined('KVGWC_SITE_NAME') ? KVGWC_SITE_NAME : 'グループウェア';

    $head = array(
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//EXBRIDGE//Kurage Vibe Groupware Core//JA',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . kv_ics_escape($site . ' ' . $app['name']),
        'X-WR-TIMEZONE:Asia/Tokyo',
        // 端末が取りに来る間隔の希望。短くしすぎるとサーバーに負荷がかかる
        'REFRESH-INTERVAL;VALUE=DURATION:PT2H',
        'X-PUBLISHED-TTL:PT2H',
    );
    $out = '';
    foreach ($head as $l) { $out .= kv_ics_fold($l); }
    foreach (kv_records($user, $app) as $r) { $out .= kv_ics_event($app, $map, $r); }
    $out .= kv_ics_fold('END:VCALENDAR');
    return $out;
}
