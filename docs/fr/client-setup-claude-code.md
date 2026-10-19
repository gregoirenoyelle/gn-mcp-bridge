# Configuration client : Claude Code sur l'ordinateur du client

🇬🇧 [English version](../client-setup-claude-code.md)

Comment connecter le Claude Code d'un client à son site WordPress via `gn-mcp-bridge`. Deux façons de faire :

- **[L'opérateur sur la machine (terminal)](#sur-la-machine-du-client)** : l'opérateur (le mainteneur du site) configure tout sur la machine du client avec le CLI `claude`. Le client lance ensuite Claude Code depuis un dossier dédié.
- **[Le client seul (kit prêt à l'emploi)](#le-client-seul-kit-prêt-à-lemploi)** : l'opérateur construit un dossier prêt à l'emploi avec le skill `gnmcp-client-kit` et l'envoie. Le client installe Claude Code, place le dossier dans son dossier personnel et y lance `claude`, sans aucune configuration MCP à taper.

Dans les deux cas, on arrive au même résultat : un dossier de contenu dédié, un serveur MCP actif uniquement dans ce dossier, et un `CLAUDE.md` centré sur le contenu.

**Aucun SSH côté client.** Le Claude Code du client ne communique qu'en HTTPS avec `/wp-json/gn-mcp/mcp`, authentifié par l'Application Password. C'est exactement la configuration pour laquelle le modèle de sécurité est conçu : le client n'a jamais d'accès shell au serveur. Côté opérateur non plus : on ne sait jamais ce que l'hébergement du client permet, donc l'Application Password est créé, vérifié et révoqué **uniquement depuis l'admin WordPress**. Ça fonctionne chez n'importe quel hébergeur.

Pour un client qui préfère ne pas utiliser de terminal du tout, voir la configuration Claude Desktop dans le [README](README.md#configuration-client--claude-desktop-pour-les-utilisateurs-non-techniques).

## La règle : jamais depuis le dossier du plugin

Le Claude Code du client ne se lance **jamais** depuis `wp-content/plugins/gn-mcp-bridge/`, et la machine du client ne contient jamais de copie de ce plugin ni du code du site.

Ce dossier, c'est la partie dev. Y lancer Claude Code charge automatiquement :

- `CLAUDE.md` : les instructions de maintenance du plugin (modifier `src/`, versions, releases).
- `.claude/skills/gnmcp-*` et `/gnmcp-start` : des outils d'opérateur qui écrivent du PHP, modifient les capacités, lancent WP-CLI et gèrent les Application Passwords.

Une session client avec ce contexte est poussée vers la modification du plugin au lieu du contenu. Elle casse aussi le modèle de sécurité : les filtres de capacités ne tiennent que si l'appelant a le transport MCP et **rien d'autre** (voir « Modèle de sécurité et limites » dans le [README](README.md#modèle-de-sécurité-et-limites)).

Le client reçoit :

- un Application Password pour son propre compte WordPress,
- un dossier de travail dédié (`~/Documents/claude-<nom-court-du-site>/`) avec son propre `CLAUDE.md`, centré sur le contenu, pas le code,
- une entrée de serveur MCP limitée à ce dossier.

## Avant d'y aller : côté serveur (machine de l'opérateur)

À faire sur le site de production avant de s'installer devant la machine du client.

1. **Plugins à jour en prod.** `mcp-adapter` à la version figée, `gn-mcp-bridge` actif : vérifier dans *Extensions* de l'admin WordPress.
2. **Choisir le compte WordPress du client et son rôle.** Le compte personnel du client (pas un compte partagé ni admin), avec le rôle le plus bas qui couvre son travail : en général `author` (ses propres articles) ou `editor` (tout le contenu). Jamais `administrator`.
3. **Restreindre les abilities pour ce site** depuis le thème (`inc/core/mcp-abilities.php`, via `gnmcp-setup-site` de votre côté). Restrictions client typiques :
   - retirer `gn-mcp/delete-post` et `gn-mcp/update-term` de `gn_mcp_bridge_exposed_abilities`,
   - relever `gn_mcp_bridge_ability_capability` pour toute ability d'écriture que le client ne doit pas avoir.

   Les filtres côté serveur sont le vrai contrôle. Les permissions côté client, plus bas, ajoutent seulement une étape de confirmation, que le client peut désactiver.
4. **Déployer la modification du thème en prod** et vérifier que le fichier de filtres est bien chargé.

## Sur la machine du client

### 1. Installer Claude Code

macOS / Linux :

```bash
curl -fsSL https://claude.ai/install.sh | bash
```

Windows (PowerShell) :

```powershell
irm https://claude.ai/install.ps1 | iex
```

Vérifier avec `claude --version`. L'installation native se met à jour toute seule, rien à maintenir.

### 2. Se connecter avec le compte Claude du client

```bash
claude
```

Le navigateur s'ouvre : le **client** se connecte avec **son** abonnement Claude (Pro, Max, Team ou Enterprise), jamais le vôtre. Quitter avec `/exit` une fois connecté.

### 3. Créer l'Application Password

Un mot de passe par client **et par machine**, pour pouvoir révoquer un portable perdu sans toucher au reste. Toujours depuis l'admin WordPress :

1. Se connecter à l'admin WordPress avec le compte du client (*Utilisateurs → Profil*), ou en administrateur (*Utilisateurs → Tous les utilisateurs → modifier l'utilisateur du client*).
2. Descendre jusqu'à **Mots de passe d'application**.
3. Le nommer `Claude Code - <nom de la machine>`, cliquer sur **Ajouter un nouveau mot de passe d'application**.
4. Copier le mot de passe affiché : il n'apparaît qu'une fois.

Les Application Passwords exigent HTTPS en production. Si la section n'apparaît pas dans le profil, vérifier le SSL du site et tout plugin de sécurité qui désactive les Application Passwords.

### 4. Encoder les identifiants sans les laisser dans l'historique du shell

Dans le terminal du client (zsh, le shell par défaut de macOS) :

```zsh
read -r "WP_USER?Login WordPress : "
read -rs "WP_PASS?Application Password : "; echo
AUTH=$(printf '%s:%s' "$WP_USER" "$WP_PASS" | base64)
```

Coller l'Application Password tel que WordPress l'affiche (espaces compris, ils sont ignorés). Garder le terminal ouvert : `$AUTH` sert aux deux étapes suivantes.

### 5. Vérifier le handshake avec `curl`

```bash
curl -si -X POST "https://<domaine>/wp-json/gn-mcp/mcp" \
  -A 'claude-code/2' \
  -H "Authorization: Basic $AUTH" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl-test","version":"1.0"}}}'
```

Attendu : `HTTP/2 200`, `serverInfo` dans le corps, un en-tête `Mcp-Session-Id`.

| Résultat | Signification |
|---|---|
| `401` JSON | Login ou mot de passe incorrect : recréer le mot de passe |
| `403` HTML brut | Un plugin de sécurité bloque le user agent ou l'IP (fréquent chez les hébergeurs clients) ; autoriser l'IP du client ou l'endpoint |
| `403` JSON | Le compte n'a pas la capacité de transport (`gn_mcp_bridge_transport_capability`) |
| `500` | Erreur côté serveur : consulter les logs PHP, lancer `gnmcp-check` de votre côté |

Ne pas aller plus loin tant que ça ne renvoie pas `200`.

### 6. Créer le dossier de travail

```bash
mkdir -p ~/Documents/claude-<nom-court-du-site>
cd ~/Documents/claude-<nom-court-du-site>
```

Toute session Claude Code pour ce site démarre d'ici. Le dossier ne contient pas de code, seulement des instructions et, au besoin, les fichiers de travail du client (brouillons, notes, images à décrire).

### 7. Déclarer le serveur MCP, limité à ce dossier

Depuis le dossier de travail :

```bash
claude mcp add --transport http --scope local wp-mcp-<nom-court-du-site> \
  "https://<domaine>/wp-json/gn-mcp/mcp" \
  --header "Authorization: Basic $AUTH"
unset WP_USER WP_PASS AUTH
```

Pourquoi `--scope local` : le serveur, et son identifiant, n'existent que dans les sessions lancées depuis ce dossier. Une session lancée ailleurs sur la machine (un autre projet, le dossier personnel) n'a aucun accès au site de production.

Ne pas utiliser `--scope project` : il écrit l'identifiant dans un fichier `.mcp.json` du dossier, en clair, où il risque d'être synchronisé, partagé ou commité.

L'identifiant est stocké dans `~/.claude.json` (Windows : `%USERPROFILE%\.claude.json`), en clair, lisible uniquement par le compte utilisateur du client. Même modèle de confiance qu'une clé SSH sur un portable.

Nommage : `wp-mcp-<nom-court-du-site>`, même convention que dans le [README](README.md#convention-de-nommage).

### 8. Ajouter le `CLAUDE.md` du dossier

Créer `~/Documents/claude-<nom-court-du-site>/CLAUDE.md`. Il donne le contexte éditorial aux sessions du client. Partir de ce modèle, dans la langue de travail du client :

```markdown
# <Nom du site> : travail de contenu

Ce dossier sert à modifier le contenu de https://<domaine> via le serveur MCP
`wp-mcp-<nom-court-du-site>`. Il n'y a pas de code ici et il ne faut pas en
écrire : pas de PHP, pas de fichiers de thème ou de plugin, pas de WP-CLI.

## Méthode de travail

- Lire avant d'écrire : trouver l'article avec search-posts, le lire avec
  get-post (ou get-post-outline + get-block pour les pages longues) avant de
  le modifier.
- Les nouveaux articles sont créés en brouillon ; la publication est faite par
  un humain dans l'admin WordPress.
- Pour une page longue, modifier un seul bloc avec update-block plutôt que
  réécrire tout le contenu avec update-post.
- Garder la structure de blocs existante ; changer le texte, pas la mise en page.
- Avant toute modification, montrer ce qui va changer et attendre la
  confirmation.

## Règles éditoriales

- Langue : <langue>. Ton : <ton>.
- Catégories utilisées : <liste>, ne jamais en créer sans demander.
- <Règles propres au site : longueur du titre SEO, extrait, liens, images...>

## En cas d'erreur

Ne pas essayer de réparer le serveur, le plugin ou la configuration du site.
Noter le message d'erreur et contacter <nom / email de l'opérateur>.
```

### 9. Ajouter des permissions côté client (facultatif, recommandé)

`~/Documents/claude-<nom-court-du-site>/.claude/settings.json` :

```json
{
  "permissions": {
    "allow": [
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-search-posts",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-get-post",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-get-post-outline",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-get-block",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-get-post-meta",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-list-categories"
    ],
    "ask": [
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-create-post",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-update-post",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-update-block",
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-update-post-meta"
    ],
    "deny": [
      "mcp__wp-mcp-<nom-court-du-site>__gn-mcp-delete-post"
    ]
  }
}
```

Les lectures passent sans demande ; chaque écriture demande d'abord une confirmation. Adapter la liste aux abilities réellement exposées sur ce site (étape 3 côté serveur). C'est une couche de confort : les filtres côté serveur restent la vraie limite.

### 10. Vérifier côté client

```bash
cd ~/Documents/claude-<nom-court-du-site>
claude mcp list
```

`wp-mcp-<nom-court-du-site>` doit afficher `✓ Connected`. Lancer ensuite `claude`, taper `/mcp` pour voir la liste des outils, et poser une question inoffensive en lecture seule, par exemple « Liste les catégories du site » ou « Trouve les trois derniers articles sur <sujet> ».

Enfin, depuis un autre dossier (par exemple `cd ~ && claude mcp list`), vérifier que le serveur n'apparaît **pas**. Ça confirme le scope local.

## Le client seul (kit prêt à l'emploi)

Pour un client qui installe tout seul. L'opérateur construit un dossier prêt à l'emploi (le kit) avec le skill bundlé `gnmcp-client-kit` et l'envoie. Le client installe Claude Code, place le dossier dans son dossier personnel et y lance `claude` : pas de `claude mcp add`, pas de base64, aucun fichier de configuration à modifier.

Le kit :

```text
claude-<nom-court-du-site>/
├── CLAUDE.md               ← instructions centrées sur le contenu, dans la langue du client
├── LISEZ-MOI.md            ← (START-HERE.md en anglais) étapes de démarrage du client
├── .mcp.json               ← le serveur HTTP gn-mcp, avec l'identifiant
└── .claude/settings.json   ← lectures autorisées, écritures sur confirmation, suppression interdite
```

Compromis par rapport au chemin « opérateur sur la machine » :

- L'identifiant est stocké en clair dans `.mcp.json` **dans le dossier** (au lieu de `~/.claude.json`) : le dossier lui-même est sensible, dossier personnel uniquement, jamais un dossier synchronisé.
- L'opérateur manipule l'Application Password en clair pour construire le kit, qui parvient donc au client par un canal chiffré.
- Claude Code demande au client d'approuver une fois le serveur de `.mcp.json`, au premier lancement.

### Côté opérateur : construire le kit

1. **Côté serveur** : identique à [Avant d'y aller](#avant-dy-aller--côté-serveur-machine-de-lopérateur) (compte, rôle, filtres, déploiement).
2. **Lancer `/gnmcp-client-kit`** depuis votre copie dev du plugin. Il demande le nom court du site, le domaine de production, la langue du client et vos coordonnées, puis écrit le kit dans `.local-working/client-kits/` du plugin (ignoré par git, jamais publié). Il ne demande jamais le mot de passe.
3. **Créer l'Application Password** pour le compte du client, nommé `Claude Code - <nom de la machine du client>` depuis l'admin WordPress, comme à l'[étape 3](#3-créer-lapplication-password).
4. **Dans un terminal séparé**, lancer le script indiqué par le skill (`.claude/skills/gnmcp-client-kit/scripts/fill-credential.sh <dossier du kit>`). Il demande le login et le mot de passe (masqué), vérifie le handshake et n'écrit rien sans réponse HTTP 200. Il affiche ensuite les outils visibles par ce compte, injecte l'identifiant dans `.mcp.json` et compresse le kit.
5. **Comparer la liste d'outils affichée** avec le `.claude/settings.json` du kit.
6. **Envoyer le zip par un partage chiffré avec expiration** (par exemple un partage d'élément 1Password), jamais par email simple, Slack ou un lien de transfert public. Coller le contenu de `LISEZ-MOI.md` dans le message pour que le client puisse le lire avant de décompresser. Supprimer ensuite le kit et le zip locaux.

### Côté client

Le `LISEZ-MOI.md` / `START-HERE.md` du kit guide le client :

1. Ouvrir le Terminal (Mac) ou PowerShell (Windows), coller la ligne d'installation de Claude Code.
2. Décompresser le kit et déplacer le dossier dans son **dossier personnel** (pas Documents, le Bureau, Dropbox, iCloud Drive ni OneDrive).
3. `cd ~/claude-<nom-court-du-site>` puis `claude`.
4. Premier lancement seulement : se connecter avec **son propre** compte Claude, faire confiance au dossier, approuver le serveur `wp-mcp-<nom-court-du-site>`.
5. Tester : « Liste les catégories de mon site ».

Les sessions suivantes : `cd ~/claude-<nom-court-du-site>` puis `claude`, toujours depuis ce dossier.

L'onglet Code de l'application Claude lit aussi le kit (choisir le dossier comme dossier de projet). Le tester une fois vous-même avant de compter dessus : son invite d'approbation et le comportement des permissions peuvent légèrement différer du CLI.

### Quand ça ne marche pas (côté client)

Le client envoie une capture d'écran à l'opérateur. Causes habituelles :

- **Aucune réponse du site, serveur en attente ou désactivé** : l'approbation a été sautée au premier lancement. Taper `/mcp` dans Claude Code et activer `wp-mcp-<nom-court-du-site>`.
- **Outils absents** : Claude Code a été lancé depuis un autre dossier (`cd ~/claude-<nom-court-du-site>` d'abord).
- **Les fichiers cachés ont été perdus** pendant le déplacement (certains outils de décompression ou de synchronisation les suppriment) : renvoyer le zip et faire déplacer le dossier entier d'un coup.
- **Une erreur d'authentification** : l'Application Password a été révoqué. Relancer le skill et envoyer un nouveau kit.

## Passation au client

À laisser au client, par écrit :

- **Comment démarrer** : ouvrir le Terminal, `cd ~/Documents/claude-<nom-court-du-site>`, `claude`. Toujours depuis ce dossier.
- **Quoi demander** : du travail de contenu en langage courant (« réécris l'intro de la page À propos », « prépare un brouillon d'article sur X dans la catégorie Actualités »). Claude lit d'abord, propose, et demande avant d'écrire.
- **Ce qu'il ne faut pas faire** : pas d'installation de plugin ou de thème, pas de copie des fichiers du site sur la machine, pas de lancement de Claude Code depuis un autre dossier pour ce site.
- **Qui appeler** quand un outil échoue, plutôt que laisser Claude essayer de « réparer ».

Faire une première vraie tâche ensemble avant de partir.

## Maintenance et fin de mission

- **Rotation du mot de passe, machine perdue ou remplacée** : révoquer l'ancien mot de passe (admin WordPress, utilisateur du client → *Mots de passe d'application* → **Révoquer** à côté du nom de cette machine), en créer un nouveau, puis sur la machine du client, depuis le dossier de travail : `claude mcp remove wp-mcp-<nom-court-du-site> -s local` et refaire les étapes 4, 5 et 7. Avec le chemin kit, construire et envoyer un nouveau kit ; le client remplace le dossier entier.
- **Changer ce que le client peut faire** : modifier les filtres du thème en prod. Rien à toucher sur la machine du client, sauf les listes de `settings.json` si une ability a été ajoutée ou retirée.
- **Nouvelles abilities du plugin** : elles apparaissent automatiquement après un redémarrage de Claude Code, sauf si elles sont filtrées. Relire les filtres du thème avant de déployer une mise à jour du plugin qui ajoute une ability d'écriture.
- **Fin de mission** : révoquer d'abord l'Application Password dans l'admin WordPress (ça suffit à couper l'accès), puis retirer l'entrée MCP et le dossier de travail de la machine du client si vous y avez encore accès.

## Pièges connus

- **Un serveur ajouté en cours de session n'apparaît pas** : quitter et relancer `claude` (ou taper `/mcp`).
- **Outils absents dans une session** : Claude Code a été lancé depuis un autre dossier. Le scope local est lié au chemin exact du dossier.
- **`401` alors que ça marchait** : l'Application Password a été révoqué, ou le compte WordPress a été supprimé/recréé. Vérifier dans l'admin WordPress, utilisateur du client → *Mots de passe d'application* : la liste indique la date de *Dernière utilisation* de chaque mot de passe.
- **Hébergement client avec un WAF** (Cloudflare, Wordfence, pare-feu de l'hébergeur) : il peut se mettre à bloquer l'endpoint après une rafale de requêtes. Autoriser `/wp-json/gn-mcp/mcp` ou l'IP du client plutôt que d'assouplir la règle globalement.
