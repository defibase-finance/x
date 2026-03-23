<?php
/**
 * CryptoScope Backend API
 * ─────────────────────────────────────────────────────────────
 * Configured for Hostinger shared hosting
 * Database: u921987559_Clawfi
 *
 * Deploy: Upload to clawfi.space/api.php
 * Table prefix: cs_ (all tables use cs_ prefix)
 */

declare(strict_types=1);
error_reporting(0);

// ─── Config — UPDATE THESE ────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'u921987559_Clawfi');   // ← your DB name
define('DB_USER', 'u921987559_Clawfi');   // ← your DB user
define('DB_PASS', 'Clawfi@1333');    // ← set your real password here
define('DB_PORT', 3306);

define('SESSION_HOURS', 720);             // 30 days
define('CORS_ORIGIN',   'https://clawfi.space');

// ─── CORS & Headers ───────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: '  . CORS_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// ─── Helpers ──────────────────────────────────────────────────
function ok(mixed $data = null, string $msg = 'ok'): never {
    echo json_encode(['ok' => true, 'msg' => $msg, 'data' => $data]);
    exit;
}

function err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function body(): array {
    return json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
}

function ip(): string {
    return $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function hash_pass(string $email, string $pw): string {
    return hash('sha256', "cryptoscope:{$email}:{$pw}");
}

function gen_token(): string {
    return bin2hex(random_bytes(32));
}

function clean_addr(string $a): string {
    return preg_match('/^0x[0-9a-fA-F]{40}$/', $a) ? strtolower($a) : '';
}

// ─── Database ─────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
         . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        err('DB connection failed. Check DB_PASS in api.php → ' . $e->getMessage(), 503);
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

// ─── Session Auth ─────────────────────────────────────────────
function session_user(): ?array {
    /* Check header first, then cookie */
    $t = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if (strlen($t) < 32) {
        $t = $_COOKIE['cs_token'] ?? '';
    }
    if (strlen($t) < 32) return null;
    return q("SELECT u.* FROM cs_sessions s
               JOIN cs_users u ON u.id = s.user_id
               WHERE s.token = ? AND s.expires_at > NOW()", [$t])->fetch() ?: null;
}

function auth(): array {
    $u = session_user();
    if (!$u) err('Unauthorized', 401);
    return $u;
}

// ─── Router ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {

    // ── Health Check ──────────────────────────────────────────
    case 'health':
        try {
            db()->query('SELECT 1');
            ok(['status' => 'ok', 'db' => DB_NAME, 'php' => PHP_VERSION, 'ts' => time()]);
        } catch (Exception $e) {
            err('DB error: ' . $e->getMessage(), 503);
        }

    // ── Register / Sign Up ────────────────────────────────────
    case 'register': {
        $b      = body();
        $email  = strtolower(trim($b['email'] ?? ''));
        $pass   = $b['password'] ?? '';
        $secret = trim($b['secret'] ?? '');
        $name   = trim($b['name'] ?? explode('@', $email)[0]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Invalid email.');
        if (strlen($pass) < 8)  err('Password must be at least 8 characters.');
        if (!$secret)           err('Wallet secret required.');

        /* Allow client to specify a user_id (used for Google/guest flows) */
        $uid = !empty($b['user_id'])
            ? preg_replace('/[^a-zA-Z0-9_\-]/', '', substr($b['user_id'], 0, 128))
            : 'email_' . hash('sha256', $email);
        $existing = q("SELECT id, pass_hash FROM cs_users WHERE id = ?", [$uid])->fetch();

        if ($existing) {
            /* Google/guest users identified by special ID prefix — allow re-register */
            $isGoogleOrGuest = str_starts_with($uid, 'google_') || str_starts_with($uid, 'guest_');
            if (!$isGoogleOrGuest && $existing['pass_hash'] !== hash_pass($email, $pass)) {
                err('Incorrect password.');
            }
        } else {
            q("INSERT INTO cs_users (id,email,name,pass_hash,secret,provider)
               VALUES (?,?,?,?,?,'email')",
              [$uid, $email, $name, hash_pass($email, $pass), $secret]);
            q("INSERT INTO cs_airdrop_points (user_id,action,points) VALUES (?,?,?)",
              [$uid, 'signup', 50]);
        }

        $token   = gen_token();
        $expires = date('Y-m-d H:i:s', strtotime('+' . SESSION_HOURS . ' hours'));
        q("INSERT INTO cs_sessions (token,user_id,expires_at,ip) VALUES (?,?,?,?)",
          [$token, $uid, $expires, ip()]);
        q("UPDATE cs_users SET last_login=NOW() WHERE id=?", [$uid]);

        $user = q("SELECT id,email,name,picture,evm_address,sol_address,ton_address FROM cs_users WHERE id=?", [$uid])->fetch();
        /* Set 30-day cookie for auto-login */
        setcookie('cs_token', $token, [
            'expires'  => time() + SESSION_HOURS * 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        ok(['token' => $token, 'user' => $user, 'new' => !$existing]);
    }

    // ── Login ─────────────────────────────────────────────────
    case 'login': {
        $b    = body();
        $email = strtolower(trim($b['email'] ?? ''));
        $pass  = $b['password'] ?? '';
        $uid   = 'email_' . hash('sha256', $email);

        $user = q("SELECT * FROM cs_users WHERE id=?", [$uid])->fetch();
        if (!$user || $user['pass_hash'] !== hash_pass($email, $pass)) err('Invalid email or password.');

        $token   = gen_token();
        $expires = date('Y-m-d H:i:s', strtotime('+' . SESSION_HOURS . ' hours'));
        q("INSERT INTO cs_sessions (token,user_id,expires_at,ip) VALUES (?,?,?,?)",
          [$token, $uid, $expires, ip()]);
        q("UPDATE cs_users SET last_login=NOW() WHERE id=?", [$uid]);

        unset($user['pass_hash'], $user['secret']);
        /* Set 30-day cookie */
        setcookie('cs_token', $token, [
            'expires'  => time() + SESSION_HOURS * 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        ok(['token' => $token, 'user' => $user]);
    }

    // ── Logout ────────────────────────────────────────────────
    case 'logout': {
        $t = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        if ($t) q("DELETE FROM cs_sessions WHERE token=?", [$t]);
        /* Clear cookie */
        setcookie('cs_token', '', ['expires' => time()-3600, 'path' => '/', 'samesite' => 'Lax']);
        ok(null, 'Logged out');
    }

    // ── Current User ──────────────────────────────────────────
    case 'me': {
        $u = auth();
        unset($u['pass_hash'], $u['secret']);
        $pts = q("SELECT COALESCE(SUM(points),0) as total FROM cs_airdrop_points WHERE user_id=?", [$u['id']])->fetch();
        $u['airdrop_points'] = intval($pts['total']);
        ok($u);
    }

    // ── Update Wallet Addresses ───────────────────────────────
    case 'update_wallet': {
        $u   = auth();
        $b   = body();
        $evm = clean_addr($b['evm_address'] ?? '');
        $sol = substr(trim($b['sol_address'] ?? ''), 0, 64);
        $ton = substr(trim($b['ton_address'] ?? ''), 0, 80);
        q("UPDATE cs_users SET evm_address=?,sol_address=?,ton_address=? WHERE id=?",
          [$evm ?: null, $sol ?: null, $ton ?: null, $u['id']]);
        ok(['evm_address' => $evm, 'sol_address' => $sol, 'ton_address' => $ton]);
    }

    // ── Deployed Tokens ───────────────────────────────────────
    case 'deployed': {
        $u = auth();
        $rows = q("SELECT * FROM cs_deployed_tokens WHERE user_id=? ORDER BY deployed_at DESC LIMIT 50", [$u['id']])->fetchAll();
        ok($rows);
    }

    case 'save_deployed': {
        $u    = auth();
        $b    = body();
        $addr = clean_addr($b['contract_addr'] ?? '');
        if (!$addr) err('Invalid contract address.');
        q("INSERT IGNORE INTO cs_deployed_tokens (user_id,contract_addr,token_name,symbol,decimals,total_supply,chain,tx_hash)
           VALUES (?,?,?,?,?,?,?,?)", [
            $u['id'], $addr,
            substr($b['token_name'] ?? '', 0, 128),
            substr(strtoupper($b['symbol'] ?? ''), 0, 20),
            intval($b['decimals'] ?? 18),
            substr($b['total_supply'] ?? '', 0, 64),
            $b['chain'] ?? 'base',
            $b['tx_hash'] ?? null,
        ]);
        q("INSERT INTO cs_airdrop_points (user_id,action,points,ref_data) VALUES (?,?,?,?)",
          [$u['id'], 'deploy_token', 100, $addr]);
        ok(['contract_addr' => $addr]);
    }

    // ── Imported Tokens ───────────────────────────────────────
    case 'imported': {
        $u     = auth();
        $chain = preg_replace('/[^a-z]/', '', $_GET['chain'] ?? 'base');
        $rows  = q("SELECT * FROM cs_imported_tokens WHERE user_id=? AND chain=? ORDER BY imported_at DESC", [$u['id'], $chain])->fetchAll();
        ok($rows);
    }

    case 'save_imported': {
        $u    = auth();
        $b    = body();
        $addr = clean_addr($b['contract_addr'] ?? '');
        $chain = preg_replace('/[^a-z]/', '', $b['chain'] ?? 'base');
        if (!$addr) err('Invalid contract address.');
        q("INSERT IGNORE INTO cs_imported_tokens (user_id,contract_addr,token_name,symbol,decimals,chain)
           VALUES (?,?,?,?,?,?)", [
            $u['id'], $addr,
            substr($b['token_name'] ?? '', 0, 128),
            substr(strtoupper($b['symbol'] ?? ''), 0, 20),
            intval($b['decimals'] ?? 18), $chain,
        ]);
        ok(['saved' => true]);
    }

    // ── Airdrop Points ────────────────────────────────────────
    case 'airdrop': {
        $u = auth();
        $total = q("SELECT COALESCE(SUM(points),0) as t FROM cs_airdrop_points WHERE user_id=?", [$u['id']])->fetch()['t'];
        $rows  = q("SELECT action, SUM(points) as pts, COUNT(*) as cnt FROM cs_airdrop_points WHERE user_id=? GROUP BY action ORDER BY pts DESC", [$u['id']])->fetchAll();
        ok(['total' => intval($total), 'breakdown' => $rows, 'wallet' => $u['evm_address']]);
    }

    case 'track_action': {
        $u    = auth();
        $b    = body();
        $name = preg_replace('/[^a-z_]/', '', strtolower($b['action'] ?? ''));
        $ref  = substr($b['ref_data'] ?? '', 0, 256);
        $pts  = ['analyze_token'=>5,'wallet_lookup'=>3,'market_search'=>3,
                 'deploy_token'=>100,'import_token'=>10,'connect_wallet'=>20,
                 'share_airdrop'=>15,'signup'=>50][$name] ?? 1;
        $cnt = q("SELECT COUNT(*) as c FROM cs_airdrop_points WHERE user_id=? AND action=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$u['id'], $name])->fetch()['c'];
        if ($cnt < 10) {
            q("INSERT INTO cs_airdrop_points (user_id,action,points,ref_data) VALUES (?,?,?,?)", [$u['id'], $name, $pts, $ref]);
        }
        ok(['points_earned' => $pts]);
    }

    // ── Price Cache ───────────────────────────────────────────
    case 'price_cache': {
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['key'] ?? body()['key'] ?? '');
        if (!$key) err('Cache key required.');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $row = q("SELECT data, TIMESTAMPDIFF(SECOND, cached_at, NOW()) as age, ttl_seconds FROM cs_price_cache WHERE cache_key=?", [$key])->fetch();
            if (!$row || $row['age'] > $row['ttl_seconds']) err('Cache miss', 404);
            ok(json_decode($row['data'], true));
        } else {
            $b   = body();
            $ttl = max(10, min(3600, intval($b['ttl'] ?? 60)));
            if (empty($b['data'])) err('data required.');
            $enc = json_encode($b['data']);
            q("INSERT INTO cs_price_cache (cache_key,data,ttl_seconds) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE data=?, ttl_seconds=?, cached_at=NOW()",
              [$key, $enc, $ttl, $enc, $ttl]);
            ok(['cached' => true, 'ttl' => $ttl]);
        }
    }

    // ── Clean expired sessions (call via cron) ─────────────────
    case 'cleanup': {
        $del1 = q("DELETE FROM cs_sessions WHERE expires_at < NOW()")->rowCount();
        $del2 = q("DELETE FROM cs_price_cache WHERE TIMESTAMPDIFF(SECOND, cached_at, NOW()) > ttl_seconds * 2")->rowCount();
        ok(['sessions_deleted' => $del1, 'cache_deleted' => $del2]);
    }

    default:
        err("Unknown action: {$action}", 404);
}
