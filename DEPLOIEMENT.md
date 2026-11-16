# Mise en ligne de Foyo

## Situation actuelle : site sur GitHub Pages, sans serveur

Le site est publié par GitHub Pages à partir de la branche `main`. GitHub Pages n'affiche que des pages : il ne peut pas faire tourner le dossier `api/`. Dans ce mode (`API:""` dans `CONFIG`, en haut du script de `index.html`) :

- le client va jusqu'au bout de la commande et envoie une **demande de location** ;
- les demandes de location et les messages du service client vous parviennent par e-mail ;
- le client voit un message clair : un conseiller le recontacte sous 24 heures ouvrées pour finaliser le dossier avec le partenaire financier ;
- il n'y a pas d'e-mails automatiques au client, pas de suivi en ligne ni d'espace de gestion : c'est vous qui répondez et tenez le client informé.

**Recevoir les demandes (5 minutes, recommandé)** : créez un compte gratuit sur formspree.io, puis un formulaire relié à votre adresse e-mail. Copiez l'adresse fournie (par exemple `https://formspree.io/f/abcdwxyz`) dans `CONFIG.FORMSPREE`. Sans Formspree, le site ouvre la messagerie du client avec la demande déjà rédigée, à envoyer à `CONFIG.SUPPORT_EMAIL` : ça fonctionne, mais une partie des clients n'iront pas jusqu'à l'envoi.

**Relier le domaine IONOS à GitHub Pages** (une fois le domaine acheté) :

1. Dans GitHub : *Settings → Pages → Custom domain*, saisissez `www.votre-domaine.fr` et enregistrez. GitHub ajoute un fichier `CNAME` au dépôt.
2. Chez IONOS : *Domaines → DNS* :
   - un enregistrement **CNAME** `www` vers `thprivateadvisory-sudo.github.io` ;
   - quatre enregistrements **A** pour `@` vers `185.199.108.153`, `185.199.109.153`, `185.199.110.153` et `185.199.111.153`.
3. Une fois le DNS pris en compte (de quelques minutes à 24 h), cochez **Enforce HTTPS** dans GitHub Pages.

## Plus tard : activer le serveur (e-mails automatiques, suivi, espace de gestion)

Le serveur est déjà écrit et testé, dans le dossier `api/`. Il lui faut un hébergement PHP, par exemple une offre d'hébergement web IONOS sur `api.votre-domaine.fr`, pendant que le site reste sur GitHub. Une fois en place, indiquez son adresse dans `CONFIG.API` (par exemple `https://api.votre-domaine.fr/api/`) : le site bascule automatiquement sur le parcours complet. La suite de ce guide décrit cette installation. Dans ce cas, `site_url` dans `config.php` doit rester l'adresse du site (`https://www.votre-domaine.fr`), et le serveur accepte les requêtes venant de cette adresse.

## 1. Prérequis chez IONOS

- Un hébergement web avec **PHP 8.1 ou plus récent** (Espace client IONOS → Hébergement → Paramètres PHP).
- Votre nom de domaine relié à cet hébergement, avec le **certificat SSL activé** (https).
- Deux adresses e-mail sur votre domaine, par exemple :
  - `commandes@votre-domaine.fr` : expéditeur des e-mails envoyés aux clients ;
  - `service-client@votre-domaine.fr` : reçoit les demandes du service client et les alertes de nouvelle commande.

## 2. Envoyer les fichiers

Copiez le dossier `api/` sur l'hébergement PHP, par SFTP avec FileZilla. Si le serveur n'est pas à l'adresse `site_url` + `/api`, renseignez `api_url` dans `config.php` (par exemple `https://api.votre-domaine.fr/api`). Le logo des e-mails est chargé depuis le site.

Les fichiers `.htaccess` du dossier `api/` doivent être copiés aussi : ils empêchent l'accès aux fichiers internes et aux données des clients.

## 3. Créer le fichier de réglages

Sur le serveur, copiez `api/config.sample.php` en **`api/config.php`** et complétez-le :

| Réglage | À mettre |
|---|---|
| `site_url` | `https://www.votre-domaine.fr` (sans `/` à la fin) |
| `mail_from`, `support_email` | les deux adresses créées à l'étape 1 |
| `support_phone`, `support_hours` | votre numéro et vos horaires (laissez le numéro vide pour le masquer) |
| `smtp` | `smtp.ionos.fr`, port `587`, l'adresse `commandes@…` et **son mot de passe** |
| `admin_password_hash` | voir ci-dessous |
| `partner_mode` | `test` tant que le partenaire financier n'est pas branché |
| `company_line` | la raison sociale et l'adresse de la société |

Pour le mot de passe de l'espace de gestion, générez un « hash » (le mot de passe n'est jamais enregistré en clair) :

```
php -r "echo password_hash('VotreMotDePasseSolide', PASSWORD_DEFAULT);"
```

Si vous n'avez pas PHP sur votre ordinateur, envoyez temporairement sur le serveur un fichier `hash.php` contenant `<?php echo password_hash('VotreMotDePasseSolide', PASSWORD_DEFAULT);`, ouvrez-le dans le navigateur, copiez le résultat, puis **supprimez ce fichier**.

`api/config.php` contient des mots de passe : ne l'envoyez jamais sur GitHub (il est déjà exclu par `.gitignore`).

## 4. Réglages dans `index.html`

En haut du script, dans `CONFIG` :

- `PARTNER_NAME` : nom du partenaire financier, une fois le contrat signé ;
- `SUPPORT_PHONE`, `SUPPORT_HOURS`, `SUPPORT_EMAIL` : affichés sur la page Service client.

## 5. Vérifier

1. Passez une commande sur le site avec votre propre adresse e-mail.
2. Sur la page du simulateur (mode test), choisissez « dossier accepté ».
3. Vérifiez la réception de l'e-mail de confirmation, et celle de l'alerte sur l'adresse du service client.
4. Ouvrez **`https://www.votre-domaine.fr/api/admin/`**, connectez-vous, puis faites avancer la commande (« En préparation », « Livraison planifiée »…) : le client reçoit un e-mail à chaque étape.
5. Envoyez un message depuis la page Service client et répondez-y depuis l'espace de gestion.
6. Vérifiez que `https://www.votre-domaine.fr/api/data/` et `https://www.votre-domaine.fr/api/config.php` affichent bien une erreur 403 (accès interdit).

Une copie de chaque e-mail envoyé est conservée dans `api/data/outbox/`.

## 6. Brancher le partenaire financier

Quand le contrat avec l'organisme de crédit est signé :

- **S'il fournit un lien de souscription** : `partner_mode = 'redirect'` et `partner_url = '…'`. Le partenaire doit renvoyer le client vers `https://www.votre-domaine.fr/api/partner/return.php`. Le résultat du dossier arrive ensuite par notification (`api/partner/webhook.php`), ou vous le saisissez dans l'espace de gestion.
- **S'il fournit une API** : `partner_mode = 'api'`, puis compléter les deux parties marquées `TODO partenaire` dans `api/partner.php` et `api/partner/webhook.php` avec sa documentation technique et ses accès de test.

**Passez en mode `redirect` ou `api` avant d'ouvrir le site au public** : en mode `test`, aucun contrat n'est signé et aucun loyer n'est prélevé.

## 7. Avant d'ouvrir au public

- Compléter les mentions légales dans `index.html` (forme juridique, capital, SIREN, n° ORIAS, nom du partenaire, hébergeur : IONOS).
- Remplacer les coefficients d'exemple `COEF` et `RES` par ceux du partenaire, à la fois dans `index.html` et dans `api/catalog.json`.
- Après toute modification du catalogue (produits, prix), mettre aussi à jour `api/catalog.json` : c'est lui que le serveur utilise pour calculer les prix, jamais le navigateur du client.
