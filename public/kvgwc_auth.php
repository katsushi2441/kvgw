<?php
/**
 * kvgwc 認証 — プロバイダ差し替え式。
 *
 * 差し替えるのは設定の 'provider' 1語だけ:
 *   password  社員番号とパスワードで入る(Workspaceを使っていない会社向け)
 *   google    Google Workspace のアカウントで入る
 *   microsoft Microsoft 365 のアカウントで入る
 *
 * google と microsoft は中身が同じ OpenID Connect で、endpoint が違うだけ。
 * だから実装は1つ(kv_oidc_*)で、設定で切り替える。
 *
 * 【Workspace連携で一番大事なこと】
 * Google は ID Token に hd(hosted domain)を入れて返す。これが「その人が
 * どの会社のWorkspaceの人か」の証明で、ここを検証すれば個人のgmail.comを
 * 弾ける。検証を省くと、URLを知っている全世界の人が社内システムに入れる。
 * kv_oidc_verify() の hd チェックは絶対に外さないこと。
 *
 * 外部ライブラリは使わない。JWTの署名検証は openssl だけで書いてある
 * (共有レンタルサーバーのPHP 8.3で動作確認済み)。
 */

require_once __DIR__ . '/kvgwc_core.php';

function kv_auth_provider() {
    $p = defined('KVGWC_AUTH') ? strtolower(KVGWC_AUTH) : 'password';
    return in_array($p, array('password', 'google', 'microsoft'), true) ? $p : 'password';
}

function kv_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        // セッションIDをURLに出さない。Cookieが無い相手はログインさせない。
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @session_name('KVGWCSESSID');
        @session_start();
    }
}

/** ログイン中のユーザー。台帳から引き直すので、権限変更や停止が即座に効く。 */
function kv_current_user() {
    kv_session_start();
    if (empty($_SESSION['kv_uid'])) { return null; }
    $u = kv_user_find($_SESSION['kv_uid']);
    if (!$u || empty($u['active'])) { return null; }
    return $u;
}

function kv_login_as($user) {
    kv_session_start();
    session_regenerate_id(true);   // ログイン前のセッションIDを使い回させない
    $_SESSION['kv_uid'] = $user['id'];
}

function kv_logout() {
    kv_session_start();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function kv_csrf_token() {
    kv_session_start();
    if (empty($_SESSION['kv_csrf'])) { $_SESSION['kv_csrf'] = kv_random_hex(16); }
    return $_SESSION['kv_csrf'];
}

function kv_csrf_ok($token) {
    kv_session_start();
    return !empty($_SESSION['kv_csrf']) && hash_equals($_SESSION['kv_csrf'], (string)$token);
}

/** 設置先のURL。設定が無ければリクエストから組み立てる(サブディレクトリ可)。 */
function kv_base_url() {
    if (defined('KVGWC_BASE_URL') && KVGWC_BASE_URL !== '') { return rtrim(KVGWC_BASE_URL, '/'); }
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        $scheme = 'https';
    }
    $host = isset($_SERVER['HTTP_HOST'])
        ? preg_replace('/[^A-Za-z0-9.:\-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME'])
        ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '';
    if ($dir === '/' || $dir === '.') { $dir = ''; }
    return $scheme . '://' . $host . $dir;
}

function kv_redirect_uri() { return kv_base_url() . '/kvgwc.php?do=callback'; }

/* ============================================================
 * password プロバイダ
 * ============================================================ */

/**
 * 社員番号（ログインID）とパスワードで入る。
 *
 * 照合するのは「そのIDのアカウント1件」だけ。全アカウントのハッシュを順に
 * 試す作りにすると、同じパスワードの人がいたときに先に登録された方へ
 * 入ってしまう。ログインは必ず「誰として入るか」を先に決める。
 *
 * パスワードは設定ファイルではなくアカウント台帳に持つ。管理者が画面から
 * 発行・再発行でき、本人が変更できる必要があるため。
 *
 * 例外は「初期管理者」だけ。設置直後は誰もアカウントを持っていないので、
 * 設定に書いたIDとパスワードで最初の管理者になれるようにしてある。
 * 設置が済んだら KVGWC_PASSWORD_HASH を空にしてよい。
 */
function kv_password_login($loginId, $password) {
    $loginId = trim((string)$loginId);
    $password = (string)$password;
    if ($loginId === '' || $password === '') {
        return array(false, 'ログインIDとパスワードを入力してください');
    }

    $bootId = (defined('KVGWC_ADMIN_LOGIN') && KVGWC_ADMIN_LOGIN !== '') ? KVGWC_ADMIN_LOGIN : 'admin';
    $bootHash = defined('KVGWC_PASSWORD_HASH') ? KVGWC_PASSWORD_HASH : '';
    if ($bootHash !== '' && strcasecmp($loginId, $bootId) === 0
        && password_verify($password, $bootHash)) {
        return kv_bootstrap_admin($bootId);
    }

    $u = kv_account_by_login($loginId);
    if (!$u || empty($u['password_hash'])) {
        // 存在しないIDでも同じだけ時間をかける。速さの違いで実在のIDを探られる。
        password_verify($password, '$2y$10$C6UzMDM.H6dfI/f/IKcEeO3Zd0Q9pMrqoK1oBnAJHfKcMg9wLBOxu');
        return array(false, 'ログインIDまたはパスワードが違います');
    }
    if (!password_verify($password, $u['password_hash'])) {
        return array(false, 'ログインIDまたはパスワードが違います');
    }
    // 在籍や停止の確認は、パスワードが合ってから。手前でやると
    // 「そのIDは実在する」ことが文言の違いで分かってしまう。
    if (empty($u['active'])) {
        return array(false, 'このアカウントは現在利用できません。管理者にご確認ください');
    }
    kv_touch_login($u['id']);
    // 記録してから引き直す。先に合成した配列を返すと last_login が古いまま。
    return array(true, kv_user_find($u['id']));
}

/** 設置直後に、設定の初期管理者から最初の社員とアカウントを作る。 */
function kv_bootstrap_admin($loginId) {
    $u = kv_account_by_login($loginId);
    if ($u) {
        if (empty($u['active'])) { return array(false, 'このアカウントは現在利用できません'); }
        kv_touch_login($u['id']);
        return array(true, kv_user_find($u['id']));
    }
    $name = defined('KVGWC_ADMIN_NAME') && KVGWC_ADMIN_NAME !== '' ? KVGWC_ADMIN_NAME : '初期管理者';
    $mail = defined('KVGWC_ADMIN_EMAIL') ? KVGWC_ADMIN_EMAIL : '';
    $depts = kv_depts();
    list($ok, $emp) = kv_employee_create(array(
        'name' => $name, 'kana' => '', 'no' => $loginId, 'dept' => key($depts),
        'title' => '', 'email' => $mail, 'tel' => '', 'mobile' => '',
        'joined' => date('Y-m-d'), 'left' => '', 'status' => 'active',
        'note' => '設定ファイルの初期管理者として自動登録',
    ));
    if (!$ok) { return array(false, $emp); }
    list($ok2, $acct) = kv_account_create($emp['id'], $loginId, $mail, 'admin');
    if (!$ok2) { return array(false, $acct); }
    kv_touch_login($acct['id']);
    return array(true, kv_user_find($acct['id']));
}

function kv_touch_login($userId) {
    kv_update('users', 'users', function (&$users) use ($userId) {
        foreach ($users as $i => $u) {
            if ($u['id'] === $userId) { $users[$i]['last_login'] = kv_now(); return $users[$i]; }
        }
        return true;
    });
}

/** 本人によるパスワード変更。今のパスワードを必ず確認する。 */
function kv_password_change($user, $current, $new1, $new2) {
    if ($new1 !== $new2) { return array(false, '新しいパスワードが一致しません'); }
    $why = kv_password_policy($new1);
    if ($why !== '') { return array(false, $why); }
    if (empty($user['password_hash']) || !password_verify((string)$current, $user['password_hash'])) {
        return array(false, '今のパスワードが違います');
    }
    if (password_verify((string)$new1, $user['password_hash'])) {
        return array(false, '今と同じパスワードには変更できません');
    }
    return kv_account_set_password($user['id'], $new1, false);
}

/* ============================================================
 * OpenID Connect (google / microsoft 共通)
 * ============================================================ */

function kv_oidc_conf() {
    $p = kv_auth_provider();
    if ($p === 'google') {
        return array(
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token'     => 'https://oauth2.googleapis.com/token',
            'jwks'      => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuers'   => array('https://accounts.google.com', 'accounts.google.com'),
            'scope'     => 'openid email profile',
            'label'     => 'Google Workspace',
        );
    }
    if ($p === 'microsoft') {
        $tenant = defined('KVGWC_MS_TENANT') && KVGWC_MS_TENANT !== '' ? KVGWC_MS_TENANT : 'organizations';
        return array(
            'authorize' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize",
            'token'     => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token",
            'jwks'      => "https://login.microsoftonline.com/$tenant/discovery/v2.0/keys",
            'issuers'   => array(),   // テナントIDが入るので発行者は tid で確認する
            'scope'     => 'openid email profile',
            'label'     => 'Microsoft 365',
        );
    }
    return null;
}

/** ログインボタンの飛び先。state と nonce を作ってセッションに預ける。 */
function kv_oidc_authorize_url() {
    $c = kv_oidc_conf();
    if (!$c) { return ''; }
    kv_session_start();
    $state = kv_random_hex(16);
    $nonce = kv_random_hex(16);
    $_SESSION['kv_oidc_state'] = $state;
    $_SESSION['kv_oidc_nonce'] = $nonce;
    $params = array(
        'client_id' => defined('KVGWC_CLIENT_ID') ? KVGWC_CLIENT_ID : '',
        'redirect_uri' => kv_redirect_uri(),
        'response_type' => 'code',
        'scope' => $c['scope'],
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'select_account',
    );
    // 自社ドメインを指定しておくと、Googleのアカウント選択がその会社に絞られる
    if (kv_auth_provider() === 'google' && defined('KVGWC_HD') && KVGWC_HD !== '') {
        $params['hd'] = KVGWC_HD;
    }
    return $c['authorize'] . '?' . http_build_query($params);
}

function kv_http_post($url, $params) {
    $body = http_build_query($params);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
        ));
        $res = curl_exec($ch);
        curl_close($ch);
        return $res === false ? '' : $res;
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'timeout' => 15,
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $body, 'ignore_errors' => true,
    )));
    $res = @file_get_contents($url, false, $ctx);
    return $res === false ? '' : $res;
}

function kv_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15));
        $res = curl_exec($ch);
        curl_close($ch);
        return $res === false ? '' : $res;
    }
    $res = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 15))));
    return $res === false ? '' : $res;
}

function kv_b64url_decode($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) { $s .= str_repeat('=', 4 - $pad); }
    return base64_decode($s);
}

/** DERの1要素を組む(タグ+長さ+中身)。JWKからPEMを作るためだけに使う。 */
function kv_der($tag, $value) {
    $len = strlen($value);
    if ($len < 128) { $lenb = chr($len); }
    else {
        $hex = ltrim(dechex($len), '0');
        if (strlen($hex) % 2) { $hex = '0' . $hex; }
        $bytes = pack('H*', $hex);
        $lenb = chr(0x80 | strlen($bytes)) . $bytes;
    }
    return chr($tag) . $lenb . $value;
}

/**
 * JWK(n,e) から PEM の公開鍵を作る。外部ライブラリを入れずに RS256 を
 * 検証するために必要。openssl が読める SubjectPublicKeyInfo を手で組む。
 */
function kv_jwk_to_pem($n_b64, $e_b64) {
    $n = kv_b64url_decode($n_b64);
    $e = kv_b64url_decode($e_b64);
    if ($n === '' || $e === '') { return ''; }
    // 最上位ビットが立っていると負の数と解釈されるので 0x00 を足す
    if (ord($n[0]) > 0x7f) { $n = "\x00" . $n; }
    if (ord($e[0]) > 0x7f) { $e = "\x00" . $e; }
    $rsa = kv_der(0x30, kv_der(0x02, $n) . kv_der(0x02, $e));
    $algo = kv_der(0x30, kv_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . kv_der(0x05, ''));
    $der = kv_der(0x30, $algo . kv_der(0x03, "\x00" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** 公開鍵は毎回取りに行かない(ログインのたびに外部通信すると遅い)。1時間持つ。 */
function kv_oidc_jwks($force = false) {
    $c = kv_oidc_conf();
    if (!$c) { return array(); }
    $cache = KVGWC_DATA_DIR . '/jwks_' . kv_auth_provider() . '.json';
    if (!$force && file_exists($cache) && (time() - filemtime($cache)) < 3600) {
        $j = json_decode(@file_get_contents($cache), true);
        if (is_array($j) && isset($j['keys'])) { return $j['keys']; }
    }
    $raw = kv_http_get($c['jwks']);
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['keys'])) { return array(); }
    kv_ensure_data_dir();
    @file_put_contents($cache, $raw);
    return $j['keys'];
}

/**
 * ID Token を検証して、中身(claims)を返す。失敗したら array(false, 理由)。
 * ここを緩めると、他人が作ったトークンで社内システムに入れてしまう。
 */
function kv_oidc_verify($id_token, $nonce) {
    $parts = explode('.', $id_token);
    if (count($parts) !== 3) { return array(false, 'トークンの形式が不正です'); }
    $header = json_decode(kv_b64url_decode($parts[0]), true);
    $claims = json_decode(kv_b64url_decode($parts[1]), true);
    if (!is_array($header) || !is_array($claims)) { return array(false, 'トークンを読めません'); }
    if (!isset($header['alg']) || $header['alg'] !== 'RS256') {
        // alg:none や HS256 への差し替えは古典的な攻撃なので、RS256だけ通す
        return array(false, '署名方式が不正です');
    }

    $kid = isset($header['kid']) ? $header['kid'] : '';
    $sig = kv_b64url_decode($parts[2]);
    $signed = $parts[0] . '.' . $parts[1];

    $verified = false;
    foreach (array(false, true) as $force) {      // 鍵が入れ替わった直後は取り直す
        foreach (kv_oidc_jwks($force) as $k) {
            if ($kid !== '' && isset($k['kid']) && $k['kid'] !== $kid) { continue; }
            if (!isset($k['n']) || !isset($k['e'])) { continue; }
            $pem = kv_jwk_to_pem($k['n'], $k['e']);
            if ($pem === '') { continue; }
            if (openssl_verify($signed, $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $verified = true; break 2; }
        }
    }
    if (!$verified) { return array(false, '署名を検証できません'); }

    $now = time();
    if (!isset($claims['exp']) || $claims['exp'] < $now - 60) { return array(false, 'トークンの期限が切れています'); }
    if (isset($claims['iat']) && $claims['iat'] > $now + 300) { return array(false, 'トークンの発行時刻が不正です'); }

    $cid = defined('KVGWC_CLIENT_ID') ? KVGWC_CLIENT_ID : '';
    $aud = isset($claims['aud']) ? $claims['aud'] : '';
    if ($cid === '' || $aud !== $cid) { return array(false, '発行先が一致しません'); }

    $c = kv_oidc_conf();
    if (!empty($c['issuers'])) {
        $iss = isset($claims['iss']) ? $claims['iss'] : '';
        if (!in_array($iss, $c['issuers'], true)) { return array(false, '発行者が一致しません'); }
    }
    if ($nonce !== '' && (!isset($claims['nonce']) || !hash_equals($nonce, $claims['nonce']))) {
        return array(false, 'nonceが一致しません');
    }
    if (empty($claims['email'])) { return array(false, 'メールアドレスを取得できません'); }
    if (isset($claims['email_verified']) && $claims['email_verified'] === false) {
        return array(false, 'メールアドレスが未確認です');
    }

    /* ---- ここが Workspace 連携の要 ---- */
    $hd = defined('KVGWC_HD') ? trim(KVGWC_HD) : '';
    if ($hd !== '') {
        $got = isset($claims['hd']) ? strtolower($claims['hd']) : '';
        if ($got === '') {
            // hd が無い = Workspaceではない個人アカウント。社内システムには入れない。
            $dom = strtolower(substr(strrchr($claims['email'], '@'), 1));
            if ($dom !== strtolower($hd)) { return array(false, '許可された組織のアカウントではありません'); }
        } else if ($got !== strtolower($hd)) {
            return array(false, '許可された組織のアカウントではありません');
        }
    }
    return array(true, $claims);
}

/** Googleから戻ってきたところ。code を渡してユーザーを確定する。 */
function kv_oidc_callback($code, $state) {
    kv_session_start();
    $want = isset($_SESSION['kv_oidc_state']) ? $_SESSION['kv_oidc_state'] : '';
    $nonce = isset($_SESSION['kv_oidc_nonce']) ? $_SESSION['kv_oidc_nonce'] : '';
    unset($_SESSION['kv_oidc_state'], $_SESSION['kv_oidc_nonce']);   // 1回きり
    if ($want === '' || !hash_equals($want, (string)$state)) {
        return array(false, 'ログインの状態を確認できません。もう一度お試しください');
    }
    $c = kv_oidc_conf();
    if (!$c) { return array(false, '認証プロバイダの設定がありません'); }

    $res = kv_http_post($c['token'], array(
        'code' => $code,
        'client_id' => defined('KVGWC_CLIENT_ID') ? KVGWC_CLIENT_ID : '',
        'client_secret' => defined('KVGWC_CLIENT_SECRET') ? KVGWC_CLIENT_SECRET : '',
        'redirect_uri' => kv_redirect_uri(),
        'grant_type' => 'authorization_code',
    ));
    $tok = json_decode($res, true);
    if (!is_array($tok) || empty($tok['id_token'])) {
        return array(false, 'アクセストークンを取得できませんでした');
    }
    list($ok, $claims) = kv_oidc_verify($tok['id_token'], $nonce);
    if (!$ok) { return array(false, $claims); }

    $name = '';
    if (!empty($claims['name'])) { $name = $claims['name']; }
    return kv_user_upsert($claims['email'], $name, kv_auth_provider(),
                          isset($claims['sub']) ? $claims['sub'] : '');
}
