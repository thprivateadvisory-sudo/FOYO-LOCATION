# Intro et outro TikTok Foyo

Vidéos verticales 1080 × 1920, 30 images/seconde, sans son (la musique s'ajoute dans TikTok).

- `../foyo-tiktok-intro.mp4` : 2,5 s, logo sur fond blanc, puis un volet bleu fait la transition vers la vidéo.
- `../foyo-tiktok-outro.mp4` : 5,4 s, à placer en fin de vidéo. Le volet bleu ouvre sur « Tout votre logement, en location. » écrit mot à mot, pendant que les produits défilent en coupes rapides ; puis le logo, « Livré et installé la veille de votre arrivée. » et « Lien en bio ».

Pour modifier un texte (accroche, arguments, « Lien en bio ») : éditez `anim.html` (fonction `build`), puis depuis ce dossier :

```
node render.js intro 2.5
node render.js outro2 5.4
ffmpeg -framerate 30 -i f_outro2/%04d.png -c:v libx264 -pix_fmt yuv420p -crf 17 ../foyo-tiktok-outro.mp4
```

`l0`–`l3.png` (lettres foncées), `lw0`–`lw3.png` (lettres blanches) et `roof.png` (toit bleu) sont les calques du logo.

Les produits de l'outro sont les fichiers `p_*.jpg` (photos officielles des marques). La liste se modifie dans `build`, partie `outro2`.
