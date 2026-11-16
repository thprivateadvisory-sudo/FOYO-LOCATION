<?php
/*
 * Branchement du partenaire financier (location avec option d'achat).
 * Trois modes, réglés dans config.php :
 *  - test     : simulateur intégré (api/partner/test.php), aucun paiement réel
 *  - redirect : lien de souscription du partenaire ; il doit rappeler api/partner/return.php
 *  - api      : appels API à compléter avec la documentation du partenaire (voir TODO)
 */
function partner_start_url(array $o): string {
  $site = rtrim((string)cfg('site_url'), '/');
  $back = $site . '/api/partner/return.php?o=' . rawurlencode($o['number']) . '&t=' . $o['token'];
  switch (cfg('partner_mode')) {
    case 'redirect':
      $u = (string)cfg('partner_url');
      if (!$u) throw new RuntimeException('partner_url manquant');
      return $u . (str_contains($u, '?') ? '&' : '?') . http_build_query([
        'reference' => $o['number'], 'montant_mensuel' => $o['monthly'], 'duree' => $o['duration'],
        'montant_total' => $o['total'], 'email' => $o['email'], 'retour' => $back,
      ]);
    case 'api':
      // TODO partenaire : créer le dossier via l'API (montant, durée, client, référence),
      // enregistrer la référence renvoyée dans orders.partner_ref et renvoyer l'URL de souscription.
      throw new RuntimeException('Intégration API du partenaire à compléter');
    default:
      return $site . '/api/partner/test.php?o=' . rawurlencode($o['number']) . '&t=' . $o['token'];
  }
}

/* Résultat du dossier au retour du client : 'accepte', 'refuse' ou 'en_etude' */
function partner_result(array $o, array $q): string {
  switch (cfg('partner_mode')) {
    case 'api':
      // TODO partenaire : interroger l'API avec orders.partner_ref ; ne jamais se fier aux paramètres de l'URL.
      return 'en_etude';
    case 'redirect':
      // Sans API, le résultat définitif arrive par le webhook ou est saisi dans l'espace de gestion.
      return 'en_etude';
    default:
      $r = (string)($q['resultat'] ?? '');
      $sig = hash_hmac('sha256', $o['number'] . '|' . $r, $o['token']);
      return (in_array($r, ['accepte', 'refuse', 'en_etude'], true) && hash_equals($sig, (string)($q['sig'] ?? ''))) ? $r : 'en_etude';
  }
}

function apply_partner_result(array $o, string $r): array {
  if (!in_array($o['status'], ['dossier_en_attente', 'dossier_en_etude'], true)) return $o;
  return match ($r) {
    'accepte' => set_status($o, 'financement_accepte'),
    'refuse'  => set_status($o, 'financement_refuse'),
    default   => $o['status'] === 'dossier_en_etude' ? $o : set_status($o, 'dossier_en_etude', '', false),
  };
}
