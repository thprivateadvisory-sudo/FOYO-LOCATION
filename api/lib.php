<?php
/* Foyo — fonctions communes du serveur (commandes, e-mails, service client) */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');

const FOYO_DIR = __DIR__;

function cfg(string $k, $default = null) {
  static $c = null;
  if ($c === null) {
    $f = FOYO_DIR . '/config.php';
    $c = is_file($f) ? require $f : require FOYO_DIR . '/config.sample.php';
  }
  return $c[$k] ?? $default;
}

/* ---------- Base de données (SQLite, dossier api/data protégé) ---------- */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = FOYO_DIR . '/data';
  if (!is_dir($dir)) mkdir($dir, 0750, true);
  if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");
  $pdo = new PDO('sqlite:' . $dir . '/foyo.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
  $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS orders(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      number TEXT UNIQUE NOT NULL, token TEXT NOT NULL,
      status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
      email TEXT NOT NULL, first_name TEXT, last_name TEXT, phone TEXT,
      address TEXT, address2 TEXT, zip TEXT, city TEXT,
      floor TEXT, elevator INTEGER DEFAULT 0, takeback INTEGER DEFAULT 0,
      delivery_date TEXT, delivery_slot TEXT,
      duration INTEGER NOT NULL, monthly REAL NOT NULL, total REAL NOT NULL, buyback REAL NOT NULL,
      items TEXT NOT NULL, partner_ref TEXT, carrier TEXT, tracking TEXT, note TEXT
    );
    CREATE TABLE IF NOT EXISTS order_events(
      id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id),
      status TEXT NOT NULL, at TEXT NOT NULL, detail TEXT, emailed INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS tickets(
      id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT UNIQUE NOT NULL,
      created_at TEXT NOT NULL, updated_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'ouvert',
      topic TEXT NOT NULL, name TEXT, email TEXT NOT NULL, phone TEXT, order_number TEXT
    );
    CREATE TABLE IF NOT EXISTS ticket_messages(
      id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL REFERENCES tickets(id),
      at TEXT NOT NULL, author TEXT NOT NULL, body TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS hits(ip TEXT, k TEXT, at INTEGER);
  SQL);
  return $pdo;
}

function api_url(): string { return rtrim((string)(cfg('api_url') ?: rtrim((string)cfg('site_url'), '/') . '/api'), '/'); }
function now(): string { return date('Y-m-d H:i:s'); }

/* ---------- Réponses JSON ---------- */
function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $msg, int $code = 400): void { json_out(['ok' => false, 'error' => $msg], $code); }
function body_json(): array {
  $raw = file_get_contents('php://input') ?: '';
  if (strlen($raw) > 20000) fail('Requête trop volumineuse.', 413);
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}
function cors(): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $site = rtrim((string)cfg('site_url'), '/');
  if ($origin && ($origin === $site || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
/* Limite simple : $max requêtes par $sec secondes et par adresse IP */
function throttle(string $key, int $max, int $sec): void {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  $t = time();
  $db = db();
  $db->prepare('DELETE FROM hits WHERE at < ?')->execute([$t - 3600]);
  $q = $db->prepare('SELECT COUNT(*) FROM hits WHERE ip=? AND k=? AND at>=?');
  $q->execute([$ip, $key, $t - $sec]);
  if ((int)$q->fetchColumn() >= $max) fail('Trop de tentatives. Réessayez dans quelques minutes.', 429);
  $db->prepare('INSERT INTO hits(ip,k,at) VALUES(?,?,?)')->execute([$ip, $key, $t]);
}

/* ---------- Catalogue et prix (calculés côté serveur, jamais repris du navigateur) ---------- */
function catalog(): array {
  static $c = null;
  return $c ??= json_decode((string)file_get_contents(FOYO_DIR . '/catalog.json'), true);
}
function monthly(float $price, int $d): float { return ceil($price * catalog()['coef'][(string)$d] * 100) / 100; }
function buyback(float $price, int $d): float { return $price * catalog()['res'][(string)$d]; }
function eur(float $v): string {
  $r = round($v, 2);
  $dec = fmod($r, 1.0) != 0.0 ? 2 : 0;
  return number_format($r, $dec, ',', "\u{202F}") . "\u{00A0}€";
}

function rnd(int $n, string $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string {
  $s = ''; for ($i = 0; $i < $n; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)]; return $s;
}
function order_by(string $number, string $token): ?array {
  $q = db()->prepare('SELECT * FROM orders WHERE number=?');
  $q->execute([strtoupper(trim($number))]);
  $o = $q->fetch();
  return ($o && hash_equals($o['token'], $token)) ? $o : null;
}

/* ---------- Statuts de commande ---------- */
const STATUSES = [
  'dossier_en_attente' => ['Dossier à finaliser', 'Votre commande est enregistrée. Il reste à finaliser votre dossier de financement.'],
  'dossier_en_etude'   => ['Dossier en cours d\'étude', 'Votre dossier est en cours d\'étude par notre partenaire financier.'],
  'financement_accepte'=> ['Commande confirmée', 'Votre dossier est accepté et votre contrat signé. Nous préparons votre commande.'],
  'financement_refuse' => ['Dossier non accepté', 'Notre partenaire financier n\'a pas pu donner une suite favorable à votre dossier.'],
  'en_preparation'     => ['En préparation', 'Vos appareils sont préparés et contrôlés dans notre entrepôt.'],
  'livraison_planifiee'=> ['Livraison planifiée', 'Votre créneau de livraison est confirmé.'],
  'en_livraison'       => ['En cours de livraison', 'Notre équipe est en route vers chez vous.'],
  'livree'             => ['Livrée et installée', 'Vos appareils sont livrés. Bonne installation !'],
  'annulee'            => ['Commande annulée', 'Votre commande a été annulée.'],
];
const TIMELINE = ['dossier_en_attente', 'financement_accepte', 'en_preparation', 'livraison_planifiee', 'en_livraison', 'livree'];
function status_label(string $s): string { return STATUSES[$s][0] ?? $s; }

/* Change le statut, journalise et envoie l'e-mail correspondant */
function set_status(array $o, string $status, string $detail = '', bool $email = true): array {
  $db = db();
  $db->prepare('UPDATE orders SET status=?, updated_at=? WHERE id=?')->execute([$status, now(), $o['id']]);
  $sent = 0;
  $o['status'] = $status;
  if ($email) $sent = (int)send_status_email($o, $detail);
  $db->prepare('INSERT INTO order_events(order_id,status,at,detail,emailed) VALUES(?,?,?,?,?)')
     ->execute([$o['id'], $status, now(), $detail, $sent]);
  return $o;
}

/* =========================================================
   E-MAILS
   ========================================================= */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function send_mail(string $to, string $subject, string $html, string $text, ?string $replyTo = null): bool {
  $from = (string)cfg('mail_from'); $fromName = (string)cfg('mail_from_name', 'Foyo');
  $boundary = 'b' . bin2hex(random_bytes(8));
  $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $headers = [
    'From' => '=?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
    'Reply-To' => $replyTo ?: (string)cfg('support_email', $from),
    'MIME-Version' => '1.0',
    'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
    'Date' => date('r'),
    'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'foyo.fr') . '>',
  ];
  $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "--$boundary--\r\n";

  // Copie locale de chaque e-mail (utile en test et en cas de litige)
  $out = FOYO_DIR . '/data/outbox';
  if (!is_dir($out)) mkdir($out, 0750, true);
  file_put_contents($out . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . preg_replace('/\W+/', '_', $to) . '.eml',
    "To: $to\r\nSubject: $encSubject\r\n" . implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body);

  $smtp = cfg('smtp', []);
  if (!empty($smtp['host']) && !empty($smtp['pass'])) {
    try { return smtp_send($smtp, $from, $to, $encSubject, $headers, $body); }
    catch (Throwable $e) { error_log('Foyo SMTP: ' . $e->getMessage()); return false; }
  }
  if (getenv('FOYO_NO_MAIL')) return true;
  $hdr = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers));
  return @mail($to, $encSubject, $body, $hdr, '-f' . $from);
}

function smtp_send(array $s, string $from, string $to, string $subject, array $headers, string $body): bool {
  $port = (int)($s['port'] ?? 587);
  $fp = stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $s['host'] . ':' . $port, $en, $es, 15);
  if (!$fp) throw new RuntimeException("connexion $es");
  stream_set_timeout($fp, 15);
  $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
  $cmd = function (string $c, array $ok) use ($fp, $read) { if ($c !== '') fwrite($fp, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(trim($c) . ' => ' . trim($r)); return $r; };
  $cmd('', [220]);
  $host = gethostname() ?: 'localhost';
  $cmd("EHLO $host", [250]);
  if ($port !== 465) { $cmd('STARTTLS', [220]); stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd("EHLO $host", [250]); }
  $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($s['user']), [334]); $cmd(base64_encode($s['pass']), [235]);
  $cmd("MAIL FROM:<$from>", [250]); $cmd("RCPT TO:<$to>", [250, 251]); $cmd('DATA', [354]);
  $msg = "To: $to\r\nSubject: $subject\r\n" . implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body;
  $msg = preg_replace('/^\./m', '..', $msg);
  $cmd($msg . "\r\n.", [250]); $cmd('QUIT', [221]);
  fclose($fp);
  return true;
}

/* Gabarit commun des e-mails (mise en page sobre, compatible messageries) */
function mail_layout(string $preheader, string $title, string $content): string {
  $site = rtrim((string)cfg('site_url'), '/');
  $phone = (string)cfg('support_phone');
  $help = '<a href="' . h($site) . '/#aide" style="color:#183BCC">Service client</a>'
        . ($phone ? ' · ' . h($phone) . ' (' . h((string)cfg('support_hours')) . ')' : '');
  return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . h($title) . '</title></head>'
  . '<body style="margin:0;padding:0;background:#F3F4F7;font-family:Arial,Helvetica,sans-serif;color:#101A2C">'
  . '<div style="display:none;max-height:0;overflow:hidden">' . h($preheader) . '</div>'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F7"><tr><td align="center" style="padding:24px 12px">'
  . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #DFE2E9;border-radius:8px">'
  . '<tr><td style="padding:22px 28px;border-bottom:1px solid #DFE2E9"><a href="' . h($site) . '"><img src="' . h($site) . '/img/logo-foyo.png" width="96" alt="Foyo" style="display:block;border:0;height:auto"></a></td></tr>'
  . '<tr><td style="padding:28px 28px 8px"><h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#101A2C">' . h($title) . '</h1>' . $content . '</td></tr>'
  . '<tr><td style="padding:20px 28px 26px;border-top:1px solid #DFE2E9;font-size:13px;line-height:1.6;color:#6B7488">Une question ? ' . $help . '<br>'
  . h((string)cfg('company_line')) . '<br>Un crédit vous engage et doit être remboursé. Vérifiez vos capacités de remboursement avant de vous engager.</td></tr>'
  . '</table></td></tr></table></body></html>';
}
function p(string $t): string { return '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3C475C">' . $t . '</p>'; }
function button(string $label, string $url): string {
  return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 20px"><tr><td style="background:#183BCC;border-radius:6px">'
    . '<a href="' . h($url) . '" style="display:inline-block;padding:13px 22px;font-size:15px;font-weight:bold;color:#FFFFFF;text-decoration:none">' . h($label) . '</a></td></tr></table>';
}
function order_table(array $o): string {
  $rows = '';
  foreach (json_decode($o['items'], true) as $it) {
    $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #DFE2E9;font-size:14px;color:#101A2C">' . h($it['brand'] . ' ' . $it['name'])
      . ($it['color'] ? '<br><span style="color:#6B7488">Coloris : ' . h($it['color']) . '</span>' : '')
      . '</td><td align="right" style="padding:10px 0;border-bottom:1px solid #DFE2E9;font-size:14px;white-space:nowrap">' . eur((float)$it['monthly']) . ' /mois</td></tr>';
  }
  $sum = fn($l, $v, $b = false) => '<tr><td style="padding:6px 0;font-size:14px;color:#3C475C">' . $l . '</td><td align="right" style="padding:6px 0;font-size:14px;' . ($b ? 'font-weight:bold;color:#101A2C' : 'color:#3C475C') . '">' . $v . '</td></tr>';
  return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px">' . $rows
    . $sum('Mensualité', eur((float)$o['monthly']) . ' x ' . (int)$o['duration'] . ' mois', true)
    . $sum('Total des loyers', eur((float)$o['total']))
    . $sum('Prix de rachat en fin de contrat', eur((float)$o['buyback'])) . '</table>';
}
function delivery_block(array $o): string {
  $d = $o['delivery_date'] ? fr_date($o['delivery_date']) : 'à convenir';
  return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#F3F4F7;border-radius:6px"><tr><td style="padding:14px 16px;font-size:14px;line-height:1.6;color:#3C475C">'
    . '<b style="color:#101A2C">Livraison</b><br>' . h($d) . ($o['delivery_slot'] ? ', ' . h($o['delivery_slot']) : '') . '<br>'
    . h($o['first_name'] . ' ' . $o['last_name']) . '<br>' . h($o['address']) . ($o['address2'] ? ', ' . h($o['address2']) : '') . '<br>' . h($o['zip'] . ' ' . $o['city'])
    . '</td></tr></table>';
}
function fr_date(string $ymd): string {
  $t = strtotime($ymd); if (!$t) return $ymd;
  $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  return $j[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}
function track_url(array $o): string {
  return rtrim((string)cfg('site_url'), '/') . '/#suivi/' . rawurlencode($o['number']) . '/' . $o['token'];
}

/* Contenu de l'e-mail selon le statut */
function send_status_email(array $o, string $detail = ''): bool {
  $n = h($o['number']); $prenom = h($o['first_name']);
  $track = track_url($o);
  $partner = h((string)cfg('partner_name'));
  $t = ''; $subject = ''; $pre = ''; $c = '';
  switch ($o['status']) {
    case 'dossier_en_attente':
      $subject = "Votre commande $o[number] est enregistrée";
      $t = 'Votre commande est enregistrée';
      $pre = 'Il reste à finaliser votre dossier pour confirmer la livraison.';
      $c = p("Bonjour $prenom,") . p("Nous avons bien reçu votre commande n° <b>$n</b>. Pour la confirmer, il reste à finaliser votre dossier auprès de $partner : vérification d'identité, signature électronique du contrat et premier loyer.")
        . button('Finaliser mon dossier', api_url() . '/partner/start.php?o=' . rawurlencode($o['number']) . '&t=' . $o['token'])
        . order_table($o) . delivery_block($o)
        . p('Votre créneau de livraison est réservé pendant 48 heures.');
      break;
    case 'financement_accepte':
      $subject = "Commande $o[number] confirmée";
      $t = 'Votre commande est confirmée';
      $pre = 'Dossier accepté, contrat signé : nous préparons votre livraison.';
      $c = p("Bonjour $prenom,") . p("Bonne nouvelle : votre dossier est accepté et votre contrat est signé. Votre commande n° <b>$n</b> est confirmée.")
        . order_table($o) . delivery_block($o)
        . p('Vous recevrez un e-mail à chaque étape : préparation, confirmation du créneau, puis le matin de la livraison.')
        . button('Suivre ma commande', $track)
        . p('Vous disposez d\'un délai de rétractation de 14 jours à compter de la signature du contrat. Votre contrat et sa fiche d\'information vous ont été transmis par ' . $partner . '.');
      break;
    case 'financement_refuse':
      $subject = "Votre commande $o[number]";
      $t = 'Votre dossier n\'a pas été accepté';
      $pre = 'Notre partenaire financier n\'a pas pu donner une suite favorable.';
      $c = p("Bonjour $prenom,") . p("Nous sommes désolés : $partner n'a pas pu donner une suite favorable à votre dossier pour la commande n° <b>$n</b>. Aucun prélèvement n'a été effectué et votre commande est annulée.")
        . p('Notre service client peut regarder avec vous une formule plus adaptée, par exemple une durée plus longue ou moins d\'appareils.')
        . button('Contacter le service client', rtrim((string)cfg('site_url'), '/') . '/#aide');
      break;
    case 'en_preparation':
      $subject = "Commande $o[number] : en préparation";
      $t = 'Votre commande est en préparation';
      $pre = 'Vos appareils sont préparés et contrôlés.';
      $c = p("Bonjour $prenom,") . p("Vos appareils sont en cours de préparation dans notre entrepôt. Chaque appareil est contrôlé avant le départ.") . delivery_block($o) . button('Suivre ma commande', $track);
      break;
    case 'livraison_planifiee':
      $subject = "Commande $o[number] : livraison le " . ($o['delivery_date'] ? fr_date($o['delivery_date']) : 'à confirmer');
      $t = 'Votre livraison est planifiée';
      $pre = 'Votre créneau de livraison est confirmé.';
      $c = p("Bonjour $prenom,") . p('Votre créneau de livraison est confirmé. Merci de prévoir une personne majeure sur place et de libérer l\'accès aux emplacements des appareils.')
        . delivery_block($o)
        . ($o['takeback'] ? p('Nous reprendrons vos anciens appareils le même jour. Pensez à les débrancher et à vider réfrigérateur et congélateur la veille.') : '')
        . button('Suivre ma commande', $track);
      break;
    case 'en_livraison':
      $subject = "Commande $o[number] : livraison aujourd'hui";
      $t = 'Nous sommes en route';
      $pre = 'Notre équipe arrive aujourd\'hui.';
      $c = p("Bonjour $prenom,") . p('Notre équipe est en route. Le livreur vous appellera environ 30 minutes avant son arrivée.')
        . ($o['carrier'] || $o['tracking'] ? p('Transporteur : <b>' . h($o['carrier']) . '</b>' . ($o['tracking'] ? ' · n° de suivi <b>' . h($o['tracking']) . '</b>' : '')) : '')
        . delivery_block($o) . button('Suivre ma commande', $track);
      break;
    case 'livree':
      $subject = "Commande $o[number] : livrée";
      $t = 'Vos appareils sont livrés';
      $pre = 'Merci pour votre confiance.';
      $c = p("Bonjour $prenom,") . p('Vos appareils sont livrés et installés. Ils sont couverts contre les pannes pendant toute la durée de votre contrat : en cas de problème, contactez-nous, nous réparons ou remplaçons l\'appareil, déplacement compris.')
        . button('Signaler un problème', rtrim((string)cfg('site_url'), '/') . '/#aide');
      break;
    case 'annulee':
      $subject = "Commande $o[number] annulée";
      $t = 'Votre commande est annulée';
      $pre = 'Votre commande a été annulée.';
      $c = p("Bonjour $prenom,") . p("Votre commande n° <b>$n</b> est annulée." . ($detail ? ' ' . h($detail) : '')) . p('Si vous n\'êtes pas à l\'origine de cette annulation, contactez notre service client.');
      break;
    default: return false;
  }
  if ($detail && !in_array($o['status'], ['annulee'], true)) $c .= p('<i>' . h($detail) . '</i>');
  $text = strip_tags(str_replace(['<br>', '</p>', '</tr>'], ["\n", "\n\n", "\n"], $c)) . "\nSuivi : $track\n";
  return send_mail($o['email'], $subject, mail_layout($pre, $t, $c), html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
}

/* Alerte interne au service client */
function notify_team(string $subject, string $text): void {
  $to = (string)cfg('support_email'); if (!$to) return;
  send_mail($to, $subject, mail_layout($subject, $subject, p(nl2br(h($text)))), $text);
}
