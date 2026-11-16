<?php
/* POST /api/order.php — crée une commande et renvoie l'adresse du dossier de financement */
require __DIR__ . '/lib.php';
require __DIR__ . '/partner.php';
cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
throttle('order', 8, 600);

$in = body_json();
$cat = catalog()['products'];
$d = (int)($in['duration'] ?? 0);
if (!in_array($d, [12, 24, 36], true)) fail('Durée de contrat invalide.');

$items = [];
foreach (array_slice((array)($in['items'] ?? []), 0, 12) as $it) {
  $id = (string)($it['id'] ?? '');
  if (!isset($cat[$id])) fail('Un produit du panier n\'existe plus. Actualisez la page.');
  $p = $cat[$id];
  $items[] = ['id' => $id, 'brand' => $p['brand'], 'name' => $p['name'], 'price' => $p['price'],
              'color' => mb_substr(trim((string)($it['color'] ?? '')), 0, 40), 'monthly' => monthly((float)$p['price'], $d)];
}
if (!$items) fail('Votre panier est vide.');

$c = (array)($in['customer'] ?? []);
$v = fn($k, $n = 120) => mb_substr(trim((string)($c[$k] ?? '')), 0, $n);
$o = [
  'first_name' => $v('first_name', 60), 'last_name' => $v('last_name', 60), 'email' => mb_strtolower($v('email', 160)),
  'phone' => preg_replace('/[^\d+]/', '', $v('phone', 20)), 'address' => $v('address', 160), 'address2' => $v('address2', 160),
  'zip' => $v('zip', 5), 'city' => $v('city', 80), 'floor' => $v('floor', 20),
  'elevator' => !empty($c['elevator']) ? 1 : 0, 'takeback' => !empty($c['takeback']) ? 1 : 0,
  'delivery_date' => $v('delivery_date', 10), 'delivery_slot' => $v('delivery_slot', 20),
];
$err = match (true) {
  $o['first_name'] === '' || $o['last_name'] === '' => 'Indiquez votre prénom et votre nom.',
  !filter_var($o['email'], FILTER_VALIDATE_EMAIL) => 'L\'adresse e-mail n\'est pas valide.',
  !preg_match('/^(\+33|0)[67]\d{8}$/', $o['phone']) => 'Indiquez un numéro de mobile français (06 ou 07).',
  $o['address'] === '' || $o['city'] === '' => 'Complétez l\'adresse de livraison.',
  !preg_match('/^(0[1-9]|[1-8]\d|9[0-5])\d{3}$/', $o['zip']) => 'La livraison est assurée en France métropolitaine : vérifiez le code postal.',
  !in_array($o['delivery_slot'], ['8 h - 13 h', '13 h - 18 h'], true) => 'Choisissez un créneau de livraison.',
  empty($in['consents']['cgl']) || empty($in['consents']['partner']) => 'Acceptez les conditions pour continuer.',
  default => '',
};
if ($err) fail($err);
$t = strtotime($o['delivery_date']);
if (!$t || $t < strtotime('today +2 days') || $t > strtotime('today +90 days') || date('w', $t) === '0') fail('Choisissez une date de livraison entre J+2 et 3 mois, hors dimanche.');

$monthly = array_sum(array_column($items, 'monthly'));
$order = $o + [
  'number' => 'FY' . date('ymd') . '-' . rnd(4), 'token' => bin2hex(random_bytes(16)),
  'status' => 'dossier_en_attente', 'created_at' => now(), 'updated_at' => now(),
  'duration' => $d, 'monthly' => round($monthly, 2), 'total' => round($monthly * $d, 2),
  'buyback' => round(array_sum(array_map(fn($i) => buyback((float)$i['price'], $d), $items))),
  'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
];
$cols = array_keys($order);
db()->prepare('INSERT INTO orders(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')')
    ->execute(array_values($order));
$order['id'] = (int)db()->lastInsertId();
set_status($order, 'dossier_en_attente');
notify_team("Nouvelle commande $order[number]",
  "Commande $order[number]\n$order[first_name] $order[last_name] — $order[email] — $order[phone]\n"
  . implode("\n", array_map(fn($i) => "- $i[brand] $i[name]" . ($i['color'] ? " ($i[color])" : ''), $items))
  . "\n$d mois — " . eur($order['monthly']) . "/mois\nLivraison : $order[delivery_date], $order[delivery_slot], $order[zip] $order[city]");

json_out(['ok' => true, 'number' => $order['number'], 'token' => $order['token'],
          'redirect' => rtrim((string)cfg('site_url'), '/') . '/api/partner/start.php?o=' . rawurlencode($order['number']) . '&t=' . $order['token']]);
