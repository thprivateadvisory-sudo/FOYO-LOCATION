<?php
/* Retour du client après le dossier de financement */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/partner.php';
$o = order_by((string)($_GET['o'] ?? ''), (string)($_GET['t'] ?? ''));
$site = rtrim((string)cfg('site_url'), '/');
if (!$o) { header('Location: ' . $site . '/#aide'); exit; }
$o = apply_partner_result($o, partner_result($o, $_GET));
header('Location: ' . $site . '/#commande/retour/' . rawurlencode($o['number']) . '/' . $o['token']);
