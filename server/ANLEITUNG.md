# Furzkalender-Server (api.kratoskai.de) auf der testvm einrichten

Läuft neben der Handwerkerwelt auf demselben Ubuntu-Server (Caddy + PHP 8.3). Bestehende Seiten werden nicht verändert.

1. **DNS bei IONOS:** A-Record `api` (für kratoskai.de) → IP der testvm.
2. **Auf der testvm als root:**
   ```
   cd /root && git clone https://github.com/xVonSteinx/kratoskai-shop.git || (cd kratoskai-shop && git pull)
   bash /root/kratoskai-shop/server/install.sh
   ```
   Das Skript gibt am Ende Benutzer und Passwort für die Verwaltung aus (auch in `/root/kratoskai-kalender-admin.txt`).
3. **Einstellungen** in `/etc/kratoskai-kalender/config.php` eintragen:
   - `stripe_webhook_secret`: in Stripe → Entwickler → Webhooks → Endpunkt `https://api.kratoskai.de/stripe`, Ereignisse `checkout.session.completed` und `checkout.session.async_payment_succeeded` → „Signaturgeheimnis“ (whsec_…)
   - `smtp`: Postfach für die Anmelde-Mails (IONOS: smtp.ionos.de, Port 465, Benutzer = Mailadresse, Passwort)
4. **Prüfen:** `curl https://api.kratoskai.de/status` → `{"ok":true,...}`. Ab dann ist der Kaufbutton auf kratoskai.de/kalender aktiv.

**Verwaltung:** https://api.kratoskai.de/admin – Käufer, Wünsche (als CSV), Video-Links für die 24 Türchen und die persönlichen Videos zum 6.12., Käufer von Hand freischalten.

**Updates:** `cd /root/kratoskai-shop && git pull && bash server/install.sh` (Einstellungen und Datenbank bleiben erhalten).

**Datensicherung:** `/var/lib/kratoskai-kalender/kalender.sqlite`

**Achtung:** Das Installationsskript der Handwerkerwelt schreibt `/etc/caddy/Caddyfile` komplett neu. Danach einmal `bash /root/kratoskai-shop/server/install.sh` ausführen, damit die Zeile `import /etc/caddy/kratoskai-api.caddy` wieder drin ist.
