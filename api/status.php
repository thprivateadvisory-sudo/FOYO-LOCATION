<?php
/* GET /api/status.php?o=&t=  ou  POST {number,email} — suivi de commande */
require __DIR__ . '/lib.php';
cors();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  throttle('status', 10, 600);
  $in = body_json();
  $q = db()->prepare('SELECT * FROM orders WHERE number=? AND email=?');
  $q->execute([strtoupper(trim((string)($in['number'] ?? ''))), mb_strtolower(trim((string)($in['email'] ?? '')))]);
  $o = $q->fetch() ?: null;
} else {
  $o = order_by((string)($_GET['o'] ?? ''), (string)($_GET['t'] ?? ''));
}
if (!$o) fail('Aucune commande ne correspond à ces informations.', 404);
$ev = db()->prepare('SELECT status, at, detail FROM order_events WHERE order_id=? ORDER BY id');
$ev->execute([$o['id']]);
$events = array_map(fn($e) => ['status' => $e['status'], 'label' => status_label($e['status']), 'at' => $e['at'], 'detail' => $e['detail']], $ev->fetchAll());
json_out(['ok' => true, 'order' => [
  'number' => $o['number'], 'token' => $o['token'], 'status' => $o['status'], 'label' => status_label($o['status']),
  'message' => STATUSES[$o['status']][1] ?? '', 'created_at' => $o['created_at'],
  'first_name' => $o['first_name'], 'email' => $o['email'],
  'delivery_date' => $o['delivery_date'], 'delivery_slot' => $o['delivery_slot'], 'city' => $o['city'], 'zip' => $o['zip'],
  'duration' => (int)$o['duration'], 'monthly' => (float)$o['monthly'], 'total' => (float)$o['total'], 'buyback' => (float)$o['buyback'],
  'items' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['brand'] . ' ' . $i['name'], 'color' => $i['color'], 'monthly' => $i['monthly']], json_decode($o['items'], true)),
  'carrier' => $o['carrier'], 'tracking' => $o['tracking'],
  'can_finalize' => in_array($o['status'], ['dossier_en_attente', 'dossier_en_etude'], true),
], 'timeline' => TIMELINE, 'labels' => array_map(fn($s) => $s[0], STATUSES), 'events' => $events]);
