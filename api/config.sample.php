<?php
/*
 * Réglages Foyo — copiez ce fichier en « config.php » sur le serveur IONOS
 * et complétez-le. Ne publiez jamais config.php sur GitHub (il est ignoré par git).
 */
return [
  // Adresse publique du site, sans « / » final
  'site_url'      => 'https://www.foyo.fr',
  // Adresse du dossier api/ s'il est hébergé ailleurs que le site (ex. 'https://api.foyo.fr/api'). Vide = site_url + /api
  'api_url'       => '',

  // E-mails
  'mail_from'      => 'commandes@foyo.fr',      // expéditeur des e-mails clients
  'mail_from_name' => 'Foyo',
  'support_email'  => 'service-client@foyo.fr', // reçoit les messages du service client et les alertes de commande
  'support_phone'  => '01 00 00 00 00',          // affiché dans les e-mails ; laisser vide pour masquer
  'support_hours'  => 'du lundi au samedi, de 9 h à 19 h',

  // Envoi SMTP (recommandé chez IONOS). Laisser 'host' vide pour utiliser mail() de PHP.
  'smtp' => [
    'host'   => 'smtp.ionos.fr',
    'port'   => 587,          // 587 = STARTTLS, 465 = SSL
    'user'   => 'commandes@foyo.fr',
    'pass'   => '',
  ],

  // Espace de gestion : /api/admin/
  // Générez le hash avec : php -r "echo password_hash('VotreMotDePasse', PASSWORD_DEFAULT);"
  'admin_password_hash' => '',

  // Partenaire financier
  //  'test'     : simulateur intégré, aucun paiement réel (pour essayer le parcours)
  //  'redirect' : lien de souscription fourni par le partenaire (voir 'partner_url')
  //  'api'      : intégration API (à compléter dans api/partner.php avec la documentation du partenaire)
  'partner_mode'   => 'test',
  'partner_name'   => 'notre partenaire financier',
  'partner_url'    => '',   // mode 'redirect' : URL de souscription
  'partner_api'    => ['base_url' => '', 'key' => '', 'secret' => '', 'webhook_secret' => ''],

  // Société (affiché en pied des e-mails)
  'company_line' => 'Foyo, marque du Groupe Cohesif · 200 rue de la Croix-Nivert, 75015 Paris',
];
