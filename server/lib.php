<?php
// Kratoskais Furzkalender – gemeinsame Funktionen (Datenbank, Datum, Mail)
declare(strict_types=1);

const KK_CONFIG = '/etc/kratoskai-kalender/config.php';

function kk_config(): array {
    static $c = null;
    if ($c === null) {
        $file = getenv('KK_CONFIG') ?: KK_CONFIG;
        $c = require $file;
    }
    return $c;
}

function kk_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . kk_config()['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS kunden (email TEXT PRIMARY KEY, name TEXT, stripe_session TEXT UNIQUE, quelle TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')));
CREATE TABLE IF NOT EXISTS tuerchen (tag INTEGER PRIMARY KEY CHECK (tag BETWEEN 1 AND 24), titel TEXT, video_url TEXT);
CREATE TABLE IF NOT EXISTS individuell (email TEXT PRIMARY KEY, video_url TEXT);
CREATE TABLE IF NOT EXISTS wuensche (email TEXT PRIMARY KEY, name_empfaenger TEXT NOT NULL, anlass TEXT, nachricht TEXT, upload_ok INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT (datetime('now')));
CREATE TABLE IF NOT EXISTS logins (hash TEXT PRIMARY KEY, email TEXT NOT NULL, art TEXT NOT NULL, expires INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS ratelimit (schluessel TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS rl_idx ON ratelimit(schluessel, ts);
CREATE TABLE IF NOT EXISTS warteliste (email TEXT PRIMARY KEY, token_hash TEXT, bestaetigt INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')), bestaetigt_at TEXT);
INSERT OR IGNORE INTO tuerchen(tag) WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<24) SELECT x FROM n;
SQL);
    // Unbestätigte Wartelisten-Einträge nach 7 Tagen löschen
    $db->exec("DELETE FROM warteliste WHERE bestaetigt = 0 AND created_at < datetime('now', '-7 days')");
    return $db;
}

function kk_verkauf_offen(): bool { return !empty(kk_config()['verkauf_offen']); }

/** Höchstes Türchen, das heute (deutsche Zeit) offen sein darf. KK_TODAY=YYYY-MM-DD nur für Tests. */
function kk_offen_bis(): int {
    $year = (int) kk_config()['jahr'];
    $today = getenv('KK_TODAY') ?: (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    [$y, $m, $d] = array_map('intval', explode('-', $today));
    if ($y < $year || ($y === $year && $m < 12)) return 0;
    if ($y > $year) return 24;
    return min($d, 24);
}

/** Wünsche für den 6.12. sind bis einschließlich 4.12. änderbar. */
function kk_wunsch_offen(): bool {
    $year = (int) kk_config()['jahr'];
    $today = getenv('KK_TODAY') ?: (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    return $today <= sprintf('%04d-12-04', $year);
}

function kk_norm_email(string $e): string { return strtolower(trim($e)); }
function kk_valid_email(string $e): bool { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 200; }
function kk_hash(string $token): string { return hash('sha256', $token); }
function kk_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }

function kk_is_kunde(string $email): bool {
    $s = kk_db()->prepare('SELECT 1 FROM kunden WHERE email = ?');
    $s->execute([kk_norm_email($email)]);
    return (bool) $s->fetchColumn();
}

function kk_add_kunde(string $email, ?string $name, ?string $session, string $quelle): void {
    $s = kk_db()->prepare('INSERT INTO kunden(email, name, stripe_session, quelle) VALUES (?,?,?,?)
        ON CONFLICT(email) DO UPDATE SET name = COALESCE(excluded.name, kunden.name), stripe_session = COALESCE(excluded.stripe_session, kunden.stripe_session)');
    $s->execute([kk_norm_email($email), $name, $session, $quelle]);
}

/** true, wenn unter dem Limit (und zählt den Versuch). */
function kk_rate_ok(string $key, int $max, int $seconds): bool {
    $db = kk_db();
    $now = time();
    $db->prepare('DELETE FROM ratelimit WHERE ts < ?')->execute([$now - 86400]);
    $s = $db->prepare('SELECT COUNT(*) FROM ratelimit WHERE schluessel = ? AND ts > ?');
    $s->execute([$key, $now - $seconds]);
    if ((int) $s->fetchColumn() >= $max) return false;
    $db->prepare('INSERT INTO ratelimit(schluessel, ts) VALUES (?, ?)')->execute([$key, $now]);
    return true;
}

/** Minimaler SMTP-Versand (SSL/465 oder STARTTLS/587) mit AUTH LOGIN. */
function kk_send_mail(string $to, string $subject, string $text): void {
    $c = kk_config()['smtp'];
    if (!empty($c['testlog'])) { file_put_contents($c['testlog'], "TO: $to\nSUBJECT: $subject\n\n$text\n----\n", FILE_APPEND); return; }
    $secure = $c['port'] == 465 ? 'ssl://' : 'tcp://';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host']]]);
    $fp = stream_socket_client($secure . $c['host'] . ':' . $c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: $errstr");
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (string $line, array $ok) use ($fp, $read) {
        if ($line !== '') fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array(substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim(preg_replace('/AUTH.*/', 'AUTH …', $line)) . ' -> ' . trim($r));
        return $r;
    };
    $cmd('', ['220']);
    $cmd('EHLO kratoskai.de', ['250']);
    if ($c['port'] != 465) {
        $cmd('STARTTLS', ['220']);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('STARTTLS fehlgeschlagen');
        $cmd('EHLO kratoskai.de', ['250']);
    }
    $cmd('AUTH LOGIN', ['334']);
    $cmd(base64_encode($c['user']), ['334']);
    $cmd(base64_encode($c['pass']), ['235']);
    $cmd('MAIL FROM:<' . $c['from'] . '>', ['250']);
    $cmd('RCPT TO:<' . $to . '>', ['250', '251']);
    $cmd('DATA', ['354']);
    $headers = [
        'From: =?UTF-8?B?' . base64_encode($c['from_name']) . '?= <' . $c['from'] . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@kratoskai.de>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $body = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
    $cmd($body . "\r\n.", ['250']);
    $cmd('QUIT', ['221']);
    fclose($fp);
}
