<?php
/* Espace de gestion Foyo : commandes, statuts et e-mails de suivi, service client */
require dirname(__DIR__) . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('foyo_admin');
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$hash = (string)cfg('admin_password_hash');
$self = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function check_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Session expirée, rechargez la page.'); } }
function go(string $q = ''): void { global $self; header('Location: ' . $self . ($q ? '?' . $q : '')); exit; }

/* ---------- Connexion ---------- */
if (isset($_GET['logout'])) { session_destroy(); go(); }
if (empty($_SESSION['ok'])) {
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    throttle('admin', 8, 900);
    if ($hash && password_verify((string)($_POST['pw'] ?? ''), $hash)) { session_regenerate_id(true); $_SESSION['ok'] = 1; go(); }
    $err = $hash ? 'Mot de passe incorrect.' : 'Aucun mot de passe n\'est configuré (admin_password_hash dans config.php).';
  }
  page('Connexion', '<form method="post" class="card narrow"><h1>Espace de gestion</h1>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
    . '<label>Mot de passe<input type="password" name="pw" autofocus required></label><button class="btn">Se connecter</button></form>', false);
}

$db = db();
$view = (string)($_GET['v'] ?? 'commandes');

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $a = (string)($_POST['a'] ?? '');
  if ($a === 'status') {
    $q = $db->prepare('SELECT * FROM orders WHERE id=?'); $q->execute([(int)$_POST['id']]); $o = $q->fetch();
    if ($o) {
      $up = [];
      foreach (['delivery_date', 'delivery_slot', 'carrier', 'tracking', 'note'] as $k) if (isset($_POST[$k])) $up[$k] = mb_substr(trim((string)$_POST[$k]), 0, 500);
      if ($up) { $db->prepare('UPDATE orders SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($up))) . ', updated_at=? WHERE id=?')->execute([...array_values($up), now(), $o['id']]); $o = $up + $o; }
      $st = (string)($_POST['status'] ?? '');
      if (isset(STATUSES[$st]) && ($st !== $o['status'] || !empty($_POST['resend']))) set_status($o, $st, trim((string)($_POST['detail'] ?? '')), !empty($_POST['email']));
    }
    go('v=commande&id=' . (int)$_POST['id'] . '&ok=1');
  }
  if ($a === 'reply') {
    $q = $db->prepare('SELECT * FROM tickets WHERE id=?'); $q->execute([(int)$_POST['id']]); $t = $q->fetch();
    $body = trim((string)($_POST['body'] ?? ''));
    if ($t && $body !== '') {
      $db->prepare('INSERT INTO ticket_messages(ticket_id,at,author,body) VALUES(?,?,?,?)')->execute([$t['id'], now(), 'conseiller', $body]);
      $st = !empty($_POST['close']) ? 'resolu' : 'repondu';
      $db->prepare('UPDATE tickets SET status=?, updated_at=? WHERE id=?')->execute([$st, now(), $t['id']]);
      $c = p('Bonjour ' . h($t['name']) . ',') . '<div style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3C475C;white-space:pre-wrap">' . h($body) . '</div>'
         . p('Cordialement,<br>Le service client Foyo') . p('<span style="color:#6B7488">Demande n° ' . h($t['number']) . ' · répondez à cet e-mail pour nous écrire.</span>');
      send_mail($t['email'], "Re: votre demande $t[number]", mail_layout('Réponse du service client Foyo', 'Réponse à votre demande', $c), "Bonjour $t[name],\n\n$body\n\nCordialement,\nLe service client Foyo\nDemande n° $t[number]");
    } elseif ($t && !empty($_POST['close'])) {
      $db->prepare('UPDATE tickets SET status=?, updated_at=? WHERE id=?')->execute(['resolu', now(), $t['id']]);
    }
    go('v=demande&id=' . (int)$_POST['id'] . '&ok=1');
  }
}

/* ---------- Vues ---------- */
$badge = fn($s) => '<span class="st st-' . h($s) . '">' . h(status_label($s)) . '</span>';
$ok = isset($_GET['ok']) ? '<p class="okmsg">Enregistré.</p>' : '';

if ($view === 'commande') {
  $q = $db->prepare('SELECT * FROM orders WHERE id=?'); $q->execute([(int)($_GET['id'] ?? 0)]); $o = $q->fetch();
  if (!$o) go();
  $ev = $db->prepare('SELECT * FROM order_events WHERE order_id=? ORDER BY id DESC'); $ev->execute([$o['id']]);
  $items = '';
  foreach (json_decode($o['items'], true) as $i) $items .= '<tr><td>' . h($i['brand'] . ' ' . $i['name']) . ($i['color'] ? ' <span class="mut">· ' . h($i['color']) . '</span>' : '') . '</td><td class="r">' . eur((float)$i['monthly']) . '</td></tr>';
  $opts = ''; foreach (STATUSES as $k => [$l]) $opts .= '<option value="' . $k . '"' . ($k === $o['status'] ? ' selected' : '') . '>' . h($l) . '</option>';
  $slots = ''; foreach (['8 h - 13 h', '13 h - 18 h'] as $s) $slots .= '<option' . ($s === $o['delivery_slot'] ? ' selected' : '') . '>' . $s . '</option>';
  $hist = ''; foreach ($ev->fetchAll() as $e) $hist .= '<tr><td>' . h($e['at']) . '</td><td>' . $badge($e['status']) . '</td><td>' . h($e['detail']) . '</td><td>' . ($e['emailed'] ? 'E-mail envoyé' : '<span class="mut">sans e-mail</span>') . '</td></tr>';
  page('Commande ' . $o['number'], '<p><a href="?v=commandes">← Commandes</a></p>' . $ok
    . '<div class="head"><h1>Commande ' . h($o['number']) . '</h1>' . $badge($o['status']) . '</div>'
    . '<div class="grid2"><div class="card"><h2>Client</h2><p>' . h($o['first_name'] . ' ' . $o['last_name']) . '<br><a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a><br>' . h($o['phone']) . '</p>'
    . '<p>' . h($o['address']) . ($o['address2'] ? '<br>' . h($o['address2']) : '') . '<br>' . h($o['zip'] . ' ' . $o['city']) . '<br><span class="mut">Étage : ' . h($o['floor'] ?: '-') . ' · Ascenseur : ' . ($o['elevator'] ? 'oui' : 'non') . ' · Reprise anciens appareils : ' . ($o['takeback'] ? 'oui' : 'non') . '</span></p>'
    . '<h2>Contrat</h2><table class="t">' . $items . '<tr><td><b>Mensualité · ' . (int)$o['duration'] . ' mois</b></td><td class="r"><b>' . eur((float)$o['monthly']) . '</b></td></tr><tr><td>Total des loyers</td><td class="r">' . eur((float)$o['total']) . '</td></tr><tr><td>Prix de rachat</td><td class="r">' . eur((float)$o['buyback']) . '</td></tr></table>'
    . '<p class="mut">Créée le ' . h($o['created_at']) . ($o['partner_ref'] ? ' · Réf. partenaire ' . h($o['partner_ref']) : '') . '</p></div>'
    . '<form method="post" class="card"><h2>Faire avancer la commande</h2><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="a" value="status"><input type="hidden" name="id" value="' . (int)$o['id'] . '">'
    . '<label>Statut<select name="status">' . $opts . '</select></label>'
    . '<div class="two"><label>Date de livraison<input type="date" name="delivery_date" value="' . h($o['delivery_date']) . '"></label><label>Créneau<select name="delivery_slot">' . $slots . '</select></label></div>'
    . '<div class="two"><label>Transporteur<input name="carrier" value="' . h($o['carrier']) . '"></label><label>N° de suivi<input name="tracking" value="' . h($o['tracking']) . '"></label></div>'
    . '<label>Message ajouté à l\'e-mail (facultatif)<input name="detail"></label>'
    . '<label>Note interne<textarea name="note" rows="2">' . h($o['note']) . '</textarea></label>'
    . '<label class="ck"><input type="checkbox" name="email" value="1" checked> Envoyer l\'e-mail de suivi au client</label>'
    . '<label class="ck"><input type="checkbox" name="resend" value="1"> Renvoyer l\'e-mail même si le statut ne change pas</label>'
    . '<button class="btn">Enregistrer</button></form></div>'
    . '<div class="card"><h2>Historique</h2><table class="t"><tr><th>Date</th><th>Statut</th><th>Message</th><th>E-mail</th></tr>' . $hist . '</table></div>');
}

if ($view === 'demandes' || $view === 'demande') {
  if ($view === 'demande') {
    $q = $db->prepare('SELECT * FROM tickets WHERE id=?'); $q->execute([(int)($_GET['id'] ?? 0)]); $t = $q->fetch();
    if (!$t) go('v=demandes');
    $m = $db->prepare('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY id'); $m->execute([$t['id']]);
    $thread = ''; foreach ($m->fetchAll() as $x) $thread .= '<div class="msg ' . ($x['author'] === 'client' ? 'cl' : 'co') . '"><div class="mut">' . ($x['author'] === 'client' ? h($t['name']) : 'Service client') . ' · ' . h($x['at']) . '</div>' . nl2br(h($x['body'])) . '</div>';
    page('Demande ' . $t['number'], '<p><a href="?v=demandes">← Service client</a></p>' . $ok . '<div class="head"><h1>Demande ' . h($t['number']) . '</h1><span class="st">' . h($t['status']) . '</span></div>'
      . '<div class="card"><p><b>' . h($t['name']) . '</b> · <a href="mailto:' . h($t['email']) . '">' . h($t['email']) . '</a> · ' . h($t['phone']) . ($t['order_number'] ? ' · Commande ' . h($t['order_number']) : '') . '<br><span class="mut">Sujet : ' . h($t['topic']) . '</span></p>' . $thread . '</div>'
      . '<form method="post" class="card"><h2>Répondre par e-mail</h2><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="a" value="reply"><input type="hidden" name="id" value="' . (int)$t['id'] . '">'
      . '<textarea name="body" rows="7"></textarea><label class="ck"><input type="checkbox" name="close" value="1"> Marquer la demande comme résolue</label><button class="btn">Envoyer</button></form>');
  }
  $rows = '';
  foreach ($db->query('SELECT * FROM tickets ORDER BY (status="ouvert") DESC, id DESC LIMIT 300') as $t)
    $rows .= '<tr><td><a href="?v=demande&id=' . (int)$t['id'] . '">' . h($t['number']) . '</a></td><td>' . h($t['created_at']) . '</td><td>' . h($t['name']) . '</td><td>' . h($t['topic']) . '</td><td>' . h($t['order_number']) . '</td><td><span class="st">' . h($t['status']) . '</span></td></tr>';
  page('Service client', '<div class="head"><h1>Service client</h1></div><div class="card"><table class="t"><tr><th>N°</th><th>Reçue le</th><th>Client</th><th>Sujet</th><th>Commande</th><th>Statut</th></tr>' . ($rows ?: '<tr><td colspan="6" class="mut">Aucune demande.</td></tr>') . '</table></div>');
}

/* Liste des commandes */
$f = (string)($_GET['s'] ?? '');
$sql = 'SELECT * FROM orders' . (isset(STATUSES[$f]) ? ' WHERE status=' . $db->quote($f) : '') . ' ORDER BY id DESC LIMIT 300';
$rows = '';
foreach ($db->query($sql) as $o)
  $rows .= '<tr><td><a href="?v=commande&id=' . (int)$o['id'] . '">' . h($o['number']) . '</a></td><td>' . h(substr($o['created_at'], 0, 16)) . '</td><td>' . h($o['first_name'] . ' ' . $o['last_name']) . '</td><td>' . h($o['zip'] . ' ' . $o['city']) . '</td><td>' . h($o['delivery_date']) . '</td><td class="r">' . eur((float)$o['monthly']) . '</td><td>' . $badge($o['status']) . '</td></tr>';
$tabs = '<a href="?v=commandes"' . ($f === '' ? ' class="on"' : '') . '>Toutes</a>';
foreach (STATUSES as $k => [$l]) $tabs .= '<a href="?v=commandes&s=' . $k . '"' . ($f === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
page('Commandes', '<div class="head"><h1>Commandes</h1></div><div class="tabs">' . $tabs . '</div><div class="card"><table class="t"><tr><th>N°</th><th>Créée</th><th>Client</th><th>Ville</th><th>Livraison</th><th class="r">/mois</th><th>Statut</th></tr>' . ($rows ?: '<tr><td colspan="7" class="mut">Aucune commande.</td></tr>') . '</table></div>');

/* ---------- Gabarit ---------- */
function page(string $title, string $body, bool $nav = true): void {
  $open = (int)db()->query("SELECT COUNT(*) FROM tickets WHERE status='ouvert'")->fetchColumn();
  echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . h($title) . ' · Foyo gestion</title><style>'
  . '*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#F3F4F7;color:#101A2C;font-size:15px}a{color:#183BCC}'
  . 'header{background:#101A2C;color:#fff;padding:0 20px;display:flex;gap:22px;align-items:center;height:56px}header b{margin-right:10px}header a{color:#C9D0DE;text-decoration:none}header a:hover{color:#fff}header .sp{flex:1}'
  . 'main{max-width:1180px;margin:0 auto;padding:24px 20px}.head{display:flex;gap:14px;align-items:center;margin-bottom:16px}h1{font-size:24px;margin:0}h2{font-size:16px;margin:0 0 12px}'
  . '.card{background:#fff;border:1px solid #DFE2E9;border-radius:8px;padding:20px;margin-bottom:16px;overflow-x:auto}.narrow{max-width:380px;margin:80px auto}.grid2{display:grid;gap:16px}@media(min-width:900px){.grid2{grid-template-columns:1fr 1fr}}'
  . '.t{width:100%;border-collapse:collapse}.t th,.t td{text-align:left;padding:9px 10px;border-bottom:1px solid #DFE2E9;vertical-align:top}.t th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6B7488}.r{text-align:right!important}.mut{color:#6B7488}'
  . 'label{display:block;font-size:13px;font-weight:600;color:#3C475C;margin-bottom:12px}input,select,textarea{display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #C9CEDA;border-radius:6px;font:inherit}.ck{display:flex;gap:8px;align-items:center}.ck input{width:auto;margin:0}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
  . '.btn{background:#183BCC;color:#fff;border:0;border-radius:6px;padding:11px 18px;font-weight:700;font-size:15px;cursor:pointer}.err{color:#B42318}.okmsg{background:#E3F3EC;color:#0F7A55;padding:10px 14px;border-radius:6px}'
  . '.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.tabs a{padding:6px 10px;border-radius:6px;text-decoration:none;color:#3C475C;font-size:13px;background:#fff;border:1px solid #DFE2E9}.tabs a.on{background:#101A2C;color:#fff;border-color:#101A2C}'
  . '.st{display:inline-block;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700;background:#E9EDFB;color:#183BCC;white-space:nowrap}.st-financement_refuse,.st-annulee{background:#FDECEC;color:#B42318}.st-livree{background:#E3F3EC;color:#0F7A55}.st-dossier_en_attente,.st-dossier_en_etude{background:#FFF4E5;color:#A3570A}'
  . '.msg{border-radius:8px;padding:12px 14px;margin:10px 0;line-height:1.5}.msg.cl{background:#F3F4F7}.msg.co{background:#E9EDFB}.msg .mut{font-size:12px;margin-bottom:4px}'
  . '</style></head><body>'
  . ($nav ? '<header><b>Foyo · gestion</b><a href="?v=commandes">Commandes</a><a href="?v=demandes">Service client' . ($open ? ' (' . $open . ')' : '') . '</a><span class="sp"></span><a href="?logout=1">Déconnexion</a></header>' : '')
  . '<main>' . $body . '</main></body></html>';
  exit;
}
