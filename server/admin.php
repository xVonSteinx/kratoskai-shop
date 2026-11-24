<?php
// Kratoskais Furzkalender – Verwaltung (api.kratoskai.de/admin, Passwortschutz über Caddy)
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
$db = kk_db();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$msg = '';

if (($_GET['export'] ?? '') === 'wuensche') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="furzgruss-wuensche.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['E-Mail', 'Für wen', 'Anlass', 'Nachricht', 'Upload erlaubt', 'Geändert'], ';');
    foreach ($db->query('SELECT * FROM wuensche ORDER BY updated_at') as $w)
        fputcsv($out, [$w['email'], $w['name_empfaenger'], $w['anlass'], $w['nachricht'], $w['upload_ok'] ? 'ja' : 'nein', $w['updated_at']], ';');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Schutz gegen fremde Seiten: Formular muss von hier kommen
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $orig = parse_url($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    if (!$orig || $orig !== $host) { http_response_code(403); exit('Ungültige Anfrage'); }

    $a = $_POST['aktion'] ?? '';
    if ($a === 'tuerchen') {
        $s = $db->prepare('UPDATE tuerchen SET titel = ?, video_url = ? WHERE tag = ?');
        for ($t = 1; $t <= 24; $t++) $s->execute([trim($_POST["titel_$t"] ?? '') ?: null, trim($_POST["video_$t"] ?? '') ?: null, $t]);
        $msg = 'Türchen gespeichert.';
    } elseif ($a === 'kunde') {
        $e = kk_norm_email($_POST['email'] ?? '');
        if (kk_valid_email($e)) { kk_add_kunde($e, trim($_POST['name'] ?? '') ?: null, null, 'manuell'); $msg = "$e freigeschaltet."; }
        else $msg = 'Ungültige E-Mail-Adresse.';
    } elseif ($a === 'benachrichtigen') {
        if (!kk_verkauf_offen()) { $msg = 'Erst den Verkauf öffnen (verkauf_offen => true in /etc/kratoskai-kalender/config.php).'; }
        else {
            $ok = 0; $fail = 0;
            foreach ($db->query('SELECT email FROM warteliste WHERE bestaetigt = 1')->fetchAll(PDO::FETCH_COLUMN) as $e) {
                try {
                    kk_send_mail($e, '🎄 Kratoskais Furzkalender ist da!',
                        "Hallo!\n\nDu wolltest Bescheid bekommen: Kratoskais Furzkalender ist ab sofort zu haben – 24 Türchen, 24 Videos, am 6.12. dein persönlicher Furzgruß und am 24.12. eine Überraschung.\n\nHier geht's lang: " . kk_config()['seite'] . "\n\nDas war die einzige Mail – deine Adresse ist jetzt von der Warteliste gelöscht.\n\nKratoskai\n\n--\nnetpd innovations · support@netpd.de · kratoskai.de");
                    $db->prepare('DELETE FROM warteliste WHERE email = ?')->execute([$e]); $ok++;
                } catch (Throwable $t) { $fail++; error_log('warteliste: ' . $t->getMessage()); }
            }
            $msg = "$ok Benachrichtigungen verschickt" . ($fail ? ", $fail fehlgeschlagen (bleiben auf der Liste)" : '') . '.';
        }
    } elseif ($a === 'individuell') {
        $s = $db->prepare('INSERT INTO individuell(email, video_url) VALUES (?, ?) ON CONFLICT(email) DO UPDATE SET video_url = excluded.video_url');
        foreach ($_POST['ind'] ?? [] as $e => $url) $s->execute([kk_norm_email((string) $e), trim((string) $url) ?: null]);
        $msg = 'Persönliche Videos gespeichert.';
    }
}

$kunden = $db->query('SELECT k.email, k.name, k.quelle, k.created_at, w.name_empfaenger, w.anlass, w.nachricht, w.upload_ok, i.video_url
    FROM kunden k LEFT JOIN wuensche w ON w.email = k.email LEFT JOIN individuell i ON i.email = k.email ORDER BY k.created_at DESC')->fetchAll();
$doors = $db->query('SELECT * FROM tuerchen ORDER BY tag')->fetchAll();
$mitWunsch = count(array_filter($kunden, fn($k) => $k['name_empfaenger']));
$mitVideo = count(array_filter($kunden, fn($k) => $k['video_url']));
$tuerVideos = count(array_filter($doors, fn($d) => $d['video_url']));
$wl = $db->query('SELECT SUM(bestaetigt = 1) AS b, SUM(bestaetigt = 0) AS u FROM warteliste')->fetch();
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Furzkalender – Verwaltung</title>
<style>
 body{font:15px/1.5 system-ui,sans-serif;margin:0;background:#fff8ef;color:#1d1a17}
 main{max-width:1100px;margin:0 auto;padding:16px}
 h1{margin:8px 0}h2{margin:28px 0 8px}
 .stats{display:flex;gap:10px;flex-wrap:wrap}.stat{background:#fff;border:2px solid #1d1a17;border-radius:12px;padding:10px 14px}.stat b{font-size:22px;display:block}
 table{width:100%;border-collapse:collapse;background:#fff;font-size:14px}td,th{border:1px solid #ecdcc8;padding:6px;text-align:left;vertical-align:top}
 input[type=text],input[type=email],input[type=url]{width:100%;box-sizing:border-box;padding:6px;border:1px solid #ccc;border-radius:6px;font:inherit}
 button{background:#ff5a1f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;margin-top:8px}
 .msg{background:#e9f7e4;border:2px solid #3aa655;border-radius:10px;padding:8px 12px}
 .wrapx{overflow-x:auto}.muted{color:#6b6259}
</style></head><body><main>
<h1>💨 Furzkalender – Verwaltung</h1>
<?php if ($msg): ?><p class="msg"><?= $h($msg) ?></p><?php endif; ?>
<div class="stats">
 <div class="stat"><b><?= count($kunden) ?></b>Käufer</div>
 <div class="stat"><b><?= $mitWunsch ?></b>Wünsche für den 6.12.</div>
 <div class="stat"><b><?= $mitVideo ?> / <?= count($kunden) ?></b>persönliche Videos fertig</div>
 <div class="stat"><b><?= $tuerVideos ?> / 24</b>Türchen mit Video</div>
 <div class="stat"><b><?= kk_offen_bis() ?></b>Türchen heute offen</div>
 <div class="stat"><b><?= kk_verkauf_offen() ? 'offen' : 'zu' ?></b>Verkauf</div>
</div>

<h2>📬 Warteliste</h2>
<p><b><?= (int) $wl['b'] ?></b> bestätigte Adressen warten auf Bescheid<?= (int) $wl['u'] ? ' (' . (int) $wl['u'] . ' noch unbestätigt)' : '' ?>.</p>
<form method="post"><input type="hidden" name="aktion" value="benachrichtigen">
<?php if (kk_verkauf_offen()): ?><button>Alle jetzt benachrichtigen (einmalig, danach wird die Liste gelöscht)</button>
<?php else: ?><p class="muted">Der Verkauf ist noch zu. Zum Öffnen in <code>/etc/kratoskai-kalender/config.php</code> <code>'verkauf_offen' => true</code> setzen, dann hier benachrichtigen.</p><?php endif; ?>
</form>

<h2>🎅 Käufer, Wünsche und persönliche Videos (6.12.)</h2>
<p class="muted">Trag bei jedem Käufer den Link zu seinem persönlichen Video ein (YouTube „nicht gelistet“ oder MP4-Link). <a href="?export=wuensche">Wünsche als Excel/CSV herunterladen</a></p>
<form method="post"><input type="hidden" name="aktion" value="individuell"><div class="wrapx"><table>
<tr><th>Käufer</th><th>Für wen</th><th>Anlass / Nachricht</th><th>Upload ok?</th><th style="min-width:260px">Link zum persönlichen Video</th></tr>
<?php foreach ($kunden as $k): ?>
<tr><td><?= $h($k['email']) ?><br><span class="muted"><?= $h($k['name']) ?> · <?= $h($k['quelle']) ?> · <?= $h($k['created_at']) ?></span></td>
<td><?= $k['name_empfaenger'] ? $h($k['name_empfaenger']) : '<span class="muted">noch kein Wunsch</span>' ?></td>
<td><?= $h($k['anlass']) ?><br><?= nl2br($h($k['nachricht'])) ?></td>
<td><?= $k['name_empfaenger'] ? ($k['upload_ok'] ? 'ja' : 'nein') : '' ?></td>
<td><input type="url" name="ind[<?= $h($k['email']) ?>]" value="<?= $h($k['video_url']) ?>" placeholder="https://youtu.be/…"></td></tr>
<?php endforeach; if (!$kunden): ?><tr><td colspan="5" class="muted">Noch keine Käufer.</td></tr><?php endif; ?>
</table></div><button>Persönliche Videos speichern</button></form>

<h2>📅 Die 24 Türchen</h2>
<form method="post"><input type="hidden" name="aktion" value="tuerchen"><div class="wrapx"><table>
<tr><th>Tag</th><th>Titel (optional)</th><th style="min-width:300px">Link zum Video</th></tr>
<?php foreach ($doors as $d): ?>
<tr><td><b><?= (int) $d['tag'] ?></b><?= $d['tag'] == 6 ? ' 🎅' : ($d['tag'] == 24 ? ' 🎁' : '') ?></td>
<td><input type="text" name="titel_<?= (int) $d['tag'] ?>" value="<?= $h($d['titel']) ?>" maxlength="120"></td>
<td><input type="url" name="video_<?= (int) $d['tag'] ?>" value="<?= $h($d['video_url']) ?>" placeholder="<?= $d['tag'] == 6 ? 'nur Ersatz, falls kein persönliches Video da ist' : 'https://youtu.be/…' ?>"></td></tr>
<?php endforeach; ?>
</table></div><button>Türchen speichern</button></form>

<h2>➕ Käufer von Hand freischalten</h2>
<p class="muted">Z. B. wenn jemand mit einer anderen E-Mail bezahlt hat oder du den Kalender verschenkst.</p>
<form method="post" style="max-width:480px"><input type="hidden" name="aktion" value="kunde">
<input type="email" name="email" placeholder="E-Mail-Adresse" required><br><br><input type="text" name="name" placeholder="Name (optional)">
<button>Freischalten</button></form>
</main></body></html>
