<?php
/*
 * Notification serveur à serveur du partenaire (mode 'api' ou 'redirect').
 * TODO partenaire : adapter la lecture du message et la vérification de signature à sa documentation.
 * Format attendu par défaut : JSON {"reference":"FY...","resultat":"accepte|refuse|en_etude"}
 * signé en HMAC-SHA256 (en-tête X-Signature) avec partner_api.webhook_secret.
 */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/partner.php';
$raw = file_get_contents('php://input') ?: '';
$secret = (string)(cfg('partner_api')['webhook_secret'] ?? '');
if (!$secret || !hash_equals(hash_hmac('sha256', $raw, $secret), (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''))) fail('Signature invalide.', 401);
$m = json_decode($raw, true) ?: [];
$q = db()->prepare('SELECT * FROM orders WHERE number=?'); $q->execute([(string)($m['reference'] ?? '')]);
$o = $q->fetch();
if (!$o) fail('Commande inconnue.', 404);
$r = (string)($m['resultat'] ?? '');
if (!in_array($r, ['accepte', 'refuse', 'en_etude'], true)) fail('Résultat inconnu.');
apply_partner_result($o, $r);
json_out(['ok' => true]);
