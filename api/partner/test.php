<?php
/* Simulateur du partenaire financier — mode test uniquement, aucun paiement réel */
require dirname(__DIR__) . '/lib.php';
if (cfg('partner_mode') !== 'test') { http_response_code(404); exit; }
$o = order_by((string)($_GET['o'] ?? ''), (string)($_GET['t'] ?? ''));
if (!$o) { http_response_code(404); exit('Commande introuvable.'); }
$back = fn($r) => 'return.php?' . http_build_query(['o' => $o['number'], 't' => $o['token'], 'resultat' => $r, 'sig' => hash_hmac('sha256', $o['number'] . '|' . $r, $o['token'])]);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Simulateur de financement (test)</title>
<style>body{margin:0;font-family:system-ui,Arial,sans-serif;background:#F3F4F7;color:#101A2C}.bar{background:#A3570A;color:#fff;text-align:center;padding:10px 16px;font-weight:700;font-size:14px}
main{max-width:520px;margin:32px auto;padding:0 16px}.card{background:#fff;border:1px solid #DFE2E9;border-radius:8px;padding:24px}h1{font-size:22px;margin:0 0 6px}p{color:#3C475C;line-height:1.55}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #DFE2E9;font-size:15px}a.b{display:block;text-align:center;padding:14px;border-radius:6px;font-weight:700;text-decoration:none;margin-top:12px}
.ok{background:#0F7A55;color:#fff}.ko{background:#fff;color:#101A2C;border:1px solid #101A2C}.wait{color:#3C475C;font-size:14px}</style></head><body>
<div class="bar">Environnement de test : aucune vérification ni aucun prélèvement réel</div>
<main><div class="card"><h1>Simulateur du partenaire financier</h1>
<p>Cette page remplace, pendant les essais, le parcours du partenaire (identité, signature électronique, premier loyer). Choisissez le résultat à simuler.</p>
<div class="row"><span>Commande</span><b><?= h($o['number']) ?></b></div>
<div class="row"><span>Client</span><b><?= h($o['first_name'] . ' ' . $o['last_name']) ?></b></div>
<div class="row"><span>Mensualité</span><b><?= eur((float)$o['monthly']) ?> x <?= (int)$o['duration'] ?> mois</b></div>
<a class="b ok" href="<?= h($back('accepte')) ?>">Simuler : dossier accepté et contrat signé</a>
<a class="b ko" href="<?= h($back('refuse')) ?>">Simuler : dossier refusé</a>
<a class="b wait" href="<?= h($back('en_etude')) ?>">Simuler : dossier en cours d'étude</a>
</div></main></body></html>
