<?php
// Kratoskais Furzkalender – API (api.kratoskai.de)
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cfg = kk_config();
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $cfg['origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$path = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

function out(int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function body(): array {
    $raw = file_get_contents('php://input', false, null, 0, 20000) ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '?'; }

/** E-Mail der angemeldeten Sitzung oder 401. */
function session_email(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{20,})$/', $h, $m)) out(401, ['fehler' => 'nicht angemeldet']);
    $s = kk_db()->prepare("SELECT email FROM logins WHERE hash = ? AND art = 'sitzung' AND expires > ?");
    $s->execute([kk_hash($m[1]), time()]);
    $e = $s->fetchColumn();
    if (!$e) out(401, ['fehler' => 'Sitzung abgelaufen']);
    return $e;
}

try {
    switch ("$method $path") {

        case 'GET /':
        case 'GET /status':
            out(200, ['ok' => true, 'offen_bis' => kk_offen_bis(), 'verkauf_offen' => kk_verkauf_offen()]);

        // 0a) Warteliste: eintragen (Double-Opt-in)
        case 'POST /warteliste': {
            $b = body();
            $email = kk_norm_email((string) ($b['email'] ?? ''));
            if (!kk_valid_email($email)) out(400, ['fehler' => 'Bitte gib eine gültige E-Mail-Adresse an.']);
            if (empty($b['einwilligung'])) out(400, ['fehler' => 'Bitte bestätige, dass wir dich benachrichtigen dürfen.']);
            if (!kk_rate_ok('wl-ip:' . client_ip(), 10, 3600) || !kk_rate_ok('wl:' . $email, 3, 86400))
                out(429, ['fehler' => 'Zu viele Versuche – bitte später nochmal.']);
            $db = kk_db();
            $s = $db->prepare('SELECT bestaetigt FROM warteliste WHERE email = ?'); $s->execute([$email]);
            $schon = (int) $s->fetchColumn() === 1;
            if (!$schon) {
                $token = kk_token();
                $db->prepare("INSERT INTO warteliste(email, token_hash, bestaetigt, created_at) VALUES (?, ?, 0, datetime('now'))
                    ON CONFLICT(email) DO UPDATE SET token_hash = excluded.token_hash, created_at = excluded.created_at")->execute([$email, kk_hash($token)]);
                kk_send_mail($email, 'Bitte bestätigen: Benachrichtigung zum Furzkalender 💨',
                    "Hallo!\n\nDu möchtest benachrichtigt werden, sobald Kratoskais Furzkalender zu kaufen ist. Bitte bestätige das mit einem Klick:\n\n" . $cfg['seite'] . "#w=$token\n\nOhne Bestätigung wird deine Adresse nach 7 Tagen automatisch gelöscht.\nNach der Benachrichtigung löschen wir deine Adresse. Du hast dich nicht eingetragen? Dann ignoriere diese Mail einfach.\n\nKratoskai\n\n--\nnetpd innovations · support@netpd.de · kratoskai.de");
            }
            out(200, ['ok' => true]);
        }

        // 0b) Warteliste: bestätigen
        case 'POST /warteliste/bestaetigen': {
            $token = (string) (body()['token'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $token)) out(400, ['fehler' => 'Ungültiger Link']);
            $s = kk_db()->prepare("UPDATE warteliste SET bestaetigt = 1, bestaetigt_at = datetime('now'), token_hash = NULL WHERE token_hash = ?");
            $s->execute([kk_hash($token)]);
            if ($s->rowCount() !== 1) out(404, ['fehler' => 'Dieser Bestätigungslink ist abgelaufen oder wurde schon benutzt.']);
            out(200, ['ok' => true]);
        }

        // 1) Anmelde-Link anfordern
        case 'POST /login': {
            $email = kk_norm_email((string) (body()['email'] ?? ''));
            if (!kk_valid_email($email)) out(400, ['fehler' => 'Bitte gib eine gültige E-Mail-Adresse an.']);
            if (!kk_rate_ok('ip:' . client_ip(), 20, 3600) || !kk_rate_ok('mail:' . $email, 5, 3600))
                out(429, ['fehler' => 'Zu viele Versuche – bitte in einer Stunde nochmal.']);
            if (kk_is_kunde($email)) {
                $token = kk_token();
                kk_db()->prepare("INSERT INTO logins(hash, email, art, expires) VALUES (?, ?, 'link', ?)")->execute([kk_hash($token), $email, time() + 1800]);
                $link = $cfg['seite'] . '#t=' . $token;
                kk_send_mail($email, 'Dein Zugang zu Kratoskais Furzkalender 💨',
                    "Hallo!\n\nHier ist dein Anmelde-Link für Kratoskais Furzkalender:\n\n$link\n\nDer Link ist 30 Minuten gültig und funktioniert einmal. Danach bleibst du auf diesem Gerät angemeldet.\n\nDu hast den Link nicht angefordert? Dann ignoriere diese Mail einfach.\n\nViel Spaß beim Türchenöffnen!\nKratoskai\n\n--\nnetpd innovations · support@netpd.de · kratoskai.de");
            }
            // Immer dieselbe Antwort, damit niemand ausprobieren kann, wer gekauft hat.
            out(200, ['ok' => true]);
        }

        // 2) Link-Token gegen Sitzung tauschen
        case 'POST /sitzung': {
            $token = (string) (body()['token'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $token)) out(400, ['fehler' => 'Ungültiger Link']);
            if (!kk_rate_ok('tok:' . client_ip(), 30, 3600)) out(429, ['fehler' => 'Zu viele Versuche']);
            $db = kk_db();
            $s = $db->prepare("SELECT email FROM logins WHERE hash = ? AND art = 'link' AND expires > ?");
            $s->execute([kk_hash($token), time()]);
            $email = $s->fetchColumn();
            if (!$email) out(401, ['fehler' => 'Der Anmelde-Link ist abgelaufen oder wurde schon benutzt. Fordere einfach einen neuen an.']);
            $db->prepare('DELETE FROM logins WHERE hash = ?')->execute([kk_hash($token)]);
            $db->prepare('DELETE FROM logins WHERE expires < ?')->execute([time()]);
            $sess = kk_token();
            $db->prepare("INSERT INTO logins(hash, email, art, expires) VALUES (?, ?, 'sitzung', ?)")->execute([kk_hash($sess), $email, time() + 60 * 86400]);
            out(200, ['sitzung' => $sess, 'email' => $email]);
        }

        // 3) Kalenderinhalt (nur freigeschaltete Türchen)
        case 'GET /kalender': {
            $email = session_email();
            if (!kk_is_kunde($email)) out(403, ['fehler' => 'kein Kalender', 'email' => $email]);
            $upto = kk_offen_bis();
            $db = kk_db();
            $s = $db->prepare('SELECT tag, titel, video_url FROM tuerchen WHERE tag <= ? ORDER BY tag');
            $s->execute([$upto]);
            $doors = $s->fetchAll();
            $personal = null;
            if ($upto >= 6) {
                $p = $db->prepare('SELECT video_url FROM individuell WHERE email = ?');
                $p->execute([$email]);
                $personal = $p->fetchColumn() ?: null;
            }
            $w = $db->prepare('SELECT name_empfaenger, anlass, nachricht, upload_ok FROM wuensche WHERE email = ?');
            $w->execute([$email]);
            $wish = $w->fetch() ?: null;
            if ($wish) $wish['upload_ok'] = (bool) $wish['upload_ok'];
            out(200, ['email' => $email, 'offen_bis' => $upto, 'tuerchen' => $doors, 'persoenlich' => $personal, 'wunsch' => $wish, 'wunsch_offen' => kk_wunsch_offen()]);
        }

        // 4) Wunsch für den 6.12. speichern
        case 'POST /wunsch': {
            $email = session_email();
            if (!kk_is_kunde($email)) out(403, ['fehler' => 'kein Kalender']);
            if (!kk_wunsch_offen()) out(409, ['fehler' => 'Wünsche können nur bis zum 4.12. geändert werden.']);
            $b = body();
            $name = trim((string) ($b['name_empfaenger'] ?? ''));
            $anlass = trim((string) ($b['anlass'] ?? ''));
            $msg = trim((string) ($b['nachricht'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80 || mb_strlen($anlass) > 80 || mb_strlen($msg) > 800) out(400, ['fehler' => 'Bitte Eingaben prüfen.']);
            kk_db()->prepare("INSERT INTO wuensche(email, name_empfaenger, anlass, nachricht, upload_ok, updated_at) VALUES (?,?,?,?,?, datetime('now'))
                ON CONFLICT(email) DO UPDATE SET name_empfaenger=excluded.name_empfaenger, anlass=excluded.anlass, nachricht=excluded.nachricht, upload_ok=excluded.upload_ok, updated_at=excluded.updated_at")
                ->execute([$email, $name, $anlass, $msg, !empty($b['upload_ok']) ? 1 : 0]);
            out(200, ['ok' => true]);
        }

        // 5) Abmelden
        case 'POST /abmelden': {
            $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Bearer ([A-Za-z0-9_-]{20,})$/', $h, $m)) kk_db()->prepare('DELETE FROM logins WHERE hash = ?')->execute([kk_hash($m[1])]);
            out(200, ['ok' => true]);
        }

        // 6) Stripe-Webhook: Käufer freischalten
        case 'POST /stripe': {
            $payload = file_get_contents('php://input') ?: '';
            $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
            $parts = [];
            foreach (explode(',', $sig) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $parts[$k][] = $v; }
            $t = (int) ($parts['t'][0] ?? 0);
            $expected = hash_hmac('sha256', $t . '.' . $payload, $cfg['stripe_webhook_secret']);
            $valid = false;
            foreach ($parts['v1'] ?? [] as $v1) if (hash_equals($expected, $v1)) $valid = true;
            if (!$valid || abs(time() - $t) > 300) out(400, ['fehler' => 'Signatur ungültig']);
            $ev = json_decode($payload, true);
            $type = $ev['type'] ?? '';
            if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                $s = $ev['data']['object'] ?? [];
                $email = kk_norm_email((string) ($s['customer_details']['email'] ?? ''));
                if (($s['payment_link'] ?? '') === $cfg['stripe_payment_link'] && ($s['payment_status'] ?? '') === 'paid' && kk_valid_email($email)) {
                    kk_add_kunde($email, $s['customer_details']['name'] ?? null, $s['id'] ?? null, 'stripe');
                }
            }
            out(200, ['ok' => true]);
        }

        default:
            out(404, ['fehler' => 'nicht gefunden']);
    }
} catch (Throwable $e) {
    error_log('kalender-api: ' . $e->getMessage());
    out(500, ['fehler' => 'Serverfehler – bitte später nochmal versuchen.']);
}
