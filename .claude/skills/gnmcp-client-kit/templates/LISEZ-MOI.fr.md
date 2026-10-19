# Claude Code pour {{SITE_NAME}} : démarrage

Ce dossier connecte Claude Code à votre site, https://{{DOMAIN}}. Il contient
votre accès au site : gardez-le privé, ne le partagez pas, ne le copiez pas et
ne l'envoyez pas en ligne.

## Une seule fois : installation

1. **Ouvrir le Terminal.**
   - Mac : appuyer sur `Cmd + Espace`, taper `Terminal`, appuyer sur `Entrée`.
   - Windows : ouvrir le menu Démarrer, taper `PowerShell`, appuyer sur `Entrée`.
2. **Installer Claude Code.** Copier cette ligne, la coller dans le Terminal et
   appuyer sur `Entrée` :
   - Mac : `curl -fsSL https://claude.ai/install.sh | bash`
   - Windows : `irm https://claude.ai/install.ps1 | iex`
3. **Placer ce dossier dans votre dossier personnel**, pas dans Documents, sur
   le Bureau, ni dans Dropbox, iCloud Drive ou OneDrive (souvent synchronisés
   en ligne).
   - Mac : dans le Finder, menu *Aller → Départ*, puis y déplacer le dossier
     `{{KIT_NAME}}`.
   - Windows : le déplacer dans `C:\Users\<votre nom>\`.

## À chaque fois : démarrer une session

Dans le Terminal, taper ces deux lignes, en appuyant sur `Entrée` après
chacune :

```text
cd ~/{{KIT_NAME}}
claude
```

La première fois seulement :

- Claude Code ouvre votre navigateur : connectez-vous avec **votre propre**
  compte Claude.
- Il demande si vous faites confiance à ce dossier : accepter.
- Il indique avoir trouvé le serveur MCP `{{SERVER}}` : choisir l'option qui
  utilise ce serveur (les écrans sont en anglais).

Puis faire un premier test : `Liste les catégories de mon site`. Les catégories
de votre site doivent s'afficher.

Toujours démarrer depuis ce dossier (`cd ~/{{KIT_NAME}}`). Lancé ailleurs,
Claude Code n'a pas accès à votre site.

## Travailler

Demander en langage courant : « réécris l'introduction de la page À propos »,
« prépare un brouillon d'article sur X dans la catégorie Actualités ». Claude
lit d'abord, montre ce qu'il va changer et demande avant d'écrire. Répondez à
ses questions ; rien n'est publié sans vous.

Pour quitter : taper `/exit`.

## En cas de problème

Ne pas essayer de réparer, et ne pas laisser Claude tenter de réparer la
connexion. Faire une capture d'écran et contacter {{OPERATOR_CONTACT}}.
