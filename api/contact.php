<?php
/* POST /api/contact.php — message au service client */
require __DIR__ . '/lib.php';
cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
throttle('contact', 5, 600);
$in = body_json();
if (!empty($in['website'])) json_out(['ok' => true, 'number' => 'SC-0']); // piège à robots
$topics = ['commande' => 'Ma commande', 'livraison' => 'Livraison', 'panne' => 'Panne ou appareil abîmé', 'retractation' => 'Rétractation ou annulation',
           'contrat' => 'Contrat, loyers, rachat', 'demenagement' => 'Changement d\'adresse', 'autre' => 'Autre question'];
$topic = (string)($in['topic'] ?? '');
$name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
$email = mb_strtolower(mb_substr(trim((string)($in['email'] ?? '')), 0, 160));
$phone = mb_substr(preg_replace('/[^\d+ ]/', '', (string)($in['phone'] ?? '')), 0, 20);
$ord = strtoupper(mb_substr(trim((string)($in['order_number'] ?? '')), 0, 20));
$msg = trim((string)($in['message'] ?? ''));
if (!isset($topics[$topic])) fail('Choisissez le sujet de votre demande.');
if ($name === '') fail('Indiquez votre nom.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('L\'adresse e-mail n\'est pas valide.');
if (mb_strlen($msg) < 10) fail('Décrivez votre demande en quelques mots.');
$msg = mb_substr($msg, 0, 4000);

$num = 'SC' . date('ymd') . '-' . rnd(4);
$db = db();
$db->prepare('INSERT INTO tickets(number,created_at,updated_at,topic,name,email,phone,order_number) VALUES(?,?,?,?,?,?,?,?)')
   ->execute([$num, now(), now(), $topic, $name, $email, $phone, $ord]);
$tid = (int)$db->lastInsertId();
$db->prepare('INSERT INTO ticket_messages(ticket_id,at,author,body) VALUES(?,?,?,?)')->execute([$tid, now(), 'client', $msg]);

$c = p('Bonjour ' . h($name) . ',') . p('Nous avons bien reçu votre demande « ' . h($topics[$topic]) . ' ». Un conseiller vous répond par e-mail sous 24 heures ouvrées.')
   . p('Votre numéro de demande : <b>' . h($num) . '</b>. Pour compléter votre demande, répondez simplement à cet e-mail.')
   . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#F3F4F7;border-radius:6px"><tr><td style="padding:14px 16px;font-size:14px;line-height:1.6;color:#3C475C;white-space:pre-wrap">' . h($msg) . '</td></tr></table>';
send_mail($email, "Votre demande $num", mail_layout('Nous avons bien reçu votre demande.', 'Demande reçue', $c),
  "Bonjour $name,\n\nNous avons bien reçu votre demande « {$topics[$topic]} ». Un conseiller vous répond sous 24 heures ouvrées.\nNuméro de demande : $num\n\n$msg");
notify_team("[$num] {$topics[$topic]} — $name", "De : $name <$email> $phone\nCommande : " . ($ord ?: '-') . "\n\n$msg");
json_out(['ok' => true, 'number' => $num]);
