<?php
// Kratoskais Furzkalender – Einstellungen. Liegt auf dem Server unter /etc/kratoskai-kalender/config.php
return [
    'jahr'   => 2026,
    'verkauf_offen' => false,   // auf true stellen, sobald der Kalender verkauft werden soll
    'db'     => '/var/lib/kratoskai-kalender/kalender.sqlite',
    'seite'  => 'https://kratoskai.de/kalender/',              // Ziel der Anmelde-Links
    'origins'=> ['https://kratoskai.de', 'https://www.kratoskai.de'],

    // Stripe: Zahlungslink des Kalenders + Signatur-Geheimnis des Webhooks (whsec_…)
    'stripe_payment_link'   => 'plink_1Uf7XG80pcohsaY4syPPjPTH',
    'stripe_webhook_secret' => 'HIER_WHSEC_EINTRAGEN',

    // Postfach, über das die Anmelde-Links verschickt werden (IONOS: smtp.ionos.de, Port 465)
    'smtp' => [
        'host'      => 'smtp.ionos.de',
        'port'      => 465,
        'user'      => 'kalender@netpd.de',
        'pass'      => 'HIER_PASSWORT_EINTRAGEN',
        'from'      => 'kalender@netpd.de',
        'from_name' => 'Kratoskais Furzkalender',
    ],
];
