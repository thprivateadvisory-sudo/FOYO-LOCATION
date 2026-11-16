<?php
/* Envoie le client vers le dossier de financement de sa commande */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/partner.php';
$o = order_by((string)($_GET['o'] ?? ''), (string)($_GET['t'] ?? ''));
$site = rtrim((string)cfg('site_url'), '/');
if (!$o) { header('Location: ' . $site . '/#aide'); exit; }
if (!in_array($o['status'], ['dossier_en_attente', 'dossier_en_etude'], true)) {
  header('Location: ' . $site . '/#suivi/' . rawurlencode($o['number']) . '/' . $o['token']); exit;
}
try { header('Location: ' . partner_start_url($o)); }
catch (Throwable $e) { error_log('Foyo partenaire: ' . $e->getMessage()); header('Location: ' . $site . '/#commande/retour/' . rawurlencode($o['number']) . '/' . $o['token'] . '/indisponible'); }
