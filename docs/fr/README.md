# GN MCP Bridge

🇬🇧 [English version](../../README.md)

Expose les abilities de contenu WordPress en tant que serveur MCP, consommable par Claude Code en HTTP, authentifié via les Application Passwords WordPress.

Conçu pour être copié à l'identique sur plusieurs sites WordPress — rien de spécifique à un site n'est jamais modifié dans ce plugin. Le comportement propre à chaque site (quelles abilities sont exposées, quelle capacité chacune requiert) est défini depuis le thème consommateur via trois filtres.

## Pourquoi ce plugin existe

Claude Code peut déjà inspecter et modifier un site WordPress directement via WP-CLI dans un shell (`wp eval`, `wp post create`, ...) dès qu'il a un accès local ou SSH à la machine hébergeant le site. Cet accès est sans restriction (il contourne entièrement les vérifications de permissions de WordPress), lié à la machine d'un seul opérateur, et non authentifié — aucun compte WordPress ne se trouve derrière.

Ce plugin est un canal différent, plus restreint :

| | WP-CLI direct via un shell | Ce plugin (MCP) |
|---|---|---|
| Accès | Sans restriction — exécution PHP directe, contourne les permissions WordPress | Limité aux abilities explicitement enregistrées ici |
| Authentification | Aucune — l'accès shell vaut de facto admin | Application Password WordPress liée à un compte précis |
| Limites par rôle | Aucune | Filtrable par ability, par site (`gn_mcp_bridge_ability_capability`) |
| Portée | Uniquement les sites joignables en shell/SSH depuis la machine de l'opérateur | Tout site exposant l'endpoint, y compris ceux sans accès shell |
| Qui peut l'utiliser | Le seul opérateur ayant cet accès shell | N'importe quel client MCP, chacun avec son propre compte WordPress et sa propre Application Password |

Utilise ce plugin quand l'appelant ne devrait pas (ou ne peut pas) avoir d'accès shell au site — un coéquipier, un autre client IA, ou un site distant sans SSH — et n'a besoin que d'un ensemble d'actions précis et auditable.

## Quand c'est réellement le bon outil (pas juste "possible aussi")

Une tâche que Claude Code peut déjà faire via WP-CLI dans un shell n'est pas un bon exemple d'usage de MCP — ça ne montre pas ce que MCP apporte en plus. C'est le bon outil précisément quand :

1. **Aucun accès shell au site** — un hébergement géré par le client, un hébergement mutualisé, tout ce qui n'a pas de SSH. MCP est alors le *seul* moyen d'accès, pas une alternative au shell.
2. **Une personne non technique doit agir sur le site** — un client ou un coéquipier qui rédige du contenu via Claude Desktop/mobile, avec sa propre Application Password et des droits volontairement restreints (brouillons uniquement, pas de publication, aucun accès serveur).
3. **Un pipeline automatisé a besoin d'un accès en écriture avec une identité scopée** — ex. un workflow n8n qui transforme une transcription en brouillon d'article. On lui donne `create-post` avec une capacité "brouillons uniquement", pas une clé SSH.
4. **Toi, depuis un appareil autre que ta machine de dev** — téléphone, autre ordinateur — pour chercher ou rédiger sans ton terminal.
5. **Un accès qui doit être révocable individuellement** — l'Application Password d'un prestataire peut être supprimée seule, sans toucher à ton propre accès admin/SSH.

### Exemples de prompts

Lecture seule :

- "Cherche sur ce site les articles mentionnant [sujet]"
- "Liste les catégories de ce site avec leur nombre d'articles"
- "Récupère le contenu complet de l'article avec le slug [slug]"

Écriture, mais sans risque (brouillons uniquement) :

- "Rédige un brouillon d'article intitulé [titre] résumant ce dont on vient de parler"
- "Vérifie si un article sur [sujet] existe déjà avant d'en créer un nouveau"

Combinant les deux (le genre de tâche qui justifie de passer par MCP — faite par un compte scopé, pas par un shell admin) :

- "Cherche tous les articles de la catégorie [catégorie], résume-les, et rédige un nouvel article qui les synthétise" — pertinent quand c'est un assistant de contenu avec un compte brouillons-uniquement qui le fait, pas quand le même opérateur a déjà un accès shell complet
- "En tant que compte contributeur, essaie de publier un article directement et confirme que c'est refusé" — une vérification de limite de permission, pertinente précisément parce que le compte est restreint

## Modèle de sécurité et limites

Tout ce qui précède suppose que l'appelant n'a **que** le transport MCP — une Application Password atteignant `/wp-json/gn-mcp/mcp` en HTTPS, rien d'autre. C'est la frontière sur laquelle repose tout le modèle de restriction de capacités (par rôle, par ability, révocable).

**Cette frontière s'effondre si le même appelant a aussi Claude Code (ou tout agent) connecté directement au code du site** — SSH, un checkout local, un runner CI avec accès en écriture au dépôt, etc. Quelqu'un avec ce type d'accès a déjà la ligne « WP-CLI direct via un shell » du tableau plus haut (sans restriction, contourne les permissions WordPress) — restreindre son compte *MCP* n'apporte rien, puisqu'il peut agir en dehors de MCP tout court.

Pire, ça peut lui faciliter la tâche : les skills bundlées de ce plugin (`.claude/skills/gnmcp-*`, voir `CLAUDE.md`) voyagent avec lui sur chaque site où il est copié, y compris chez un client. `gnmcp-add-ability` en particulier existe pour scaffolder de nouvelles abilities côté serveur — du nouveau PHP, avec son propre `permission_callback` — et pour bien faire ce travail, elle a justement besoin du type d'accès au code décrit ci-dessus. Entre les mains de quelqu'un ayant cet accès, c'est un chemin documenté et prêt à l'emploi pour s'enregistrer une nouvelle capacité directement dans le plugin partagé (et, en cas d'inattention, affaiblir un `permission_callback` au passage) — pas quelque chose qu'un client devrait exécuter lui-même.

**Là où le modèle de ce plugin tient réellement** : l'appelant n'a **aucun** accès shell/SSH/fichiers au site — le premier scénario listé dans "Quand c'est réellement le bon outil" ci-dessus (hébergement géré par le client, hébergement mutualisé, ou simplement un client/coéquipier qui n'a jamais eu d'accès serveur). Dans ce cas, il n'y a aucun code à atteindre pour lui, skills bundlées comprises, et les filtres de capacité sont la seule porte.

**En bref** : ne jamais donner à un client ou un coéquipier une Application Password *et* un accès au code/Claude Code sur le même site — choisir l'un ou l'autre. Les skills `gnmcp-*` bundlées sont des outils d'opérateur (le mainteneur du site, ex. Grégoire) — ne jamais les exécuter pour le compte d'un client, ni les exposer à la session Claude Code propre d'un client.

## Prérequis

- WordPress avec l'Abilities API (core, WP ≥ 7.0)
- [`mcp-adapter`](https://wordpress.org/plugins/mcp-adapter/) ≥ 0.7.0, actif
- En cas de synchronisation de contenu (ex. blocs réutilisables) entre environnements via `get-post`/`update-post` : lire [cette note sur le sujet](https://gist.github.com/gregoirenoyelle/36033c44cf42ad5b35f377de5a163e59) d'abord — l'ID d'un article, et parfois même son slug, n'est pas portable entre un environnement local et la production

L'Abilities API (native) permet seulement d'*enregistrer* des capacités — elle ne sait pas parler le protocole MCP. `mcp-adapter` transforme les abilities enregistrées en un véritable serveur MCP (transport HTTP, `initialize`/`tools/list`/`tools/call`, gestion de session). Sans lui, les abilities existent mais aucun client MCP ne peut les atteindre — d'où la dépendance stricte (`Requires Plugins: mcp-adapter`), pas une extension optionnelle.

**Ce que fait chaque couche :**

- **Abilities API** — un registre générique de capacités, indépendant de tout transport. `wp_register_ability()` déclare un nom, un `input_schema`/`output_schema` (JSON Schema), un `execute_callback` et un `permission_callback`. Conceptuellement proche de `register_rest_route()` : ça décrit *ce qu'on peut faire* et *qui a le droit*, sans définir de format réseau.
- **Protocole MCP** — un contrat JSON-RPC 2.0 fixe, sans rapport avec WordPress : `initialize` (négociation des capacités), `tools/list` (liste les outils), `tools/call` (exécute un outil), plus une mécanique de session (`Mcp-Session-Id` renvoyé par `initialize`, à répéter sur chaque appel suivant avec `MCP-Protocol-Version: 2025-11-25` ; la révision plus récente `2026-07-28` n'a pas de session).
- **`mcp-adapter`** — le pont entre les deux : `McpAdapter::create_server()` transforme les abilities exposées en définitions d'outils MCP (`tools/list` reflète le schéma de chaque ability), route `tools/call` vers le bon `execute_callback`, enveloppe le `permission_callback` de chaque ability dans le flux d'autorisation MCP, et gère lui-même la mécanique de session/transport.

### Pourquoi ce plugin n'est pas redondant avec `mcp-adapter`

`mcp-adapter` est de la pure plomberie protocole. Il n'enregistre aucune ability, ne définit aucun contenu, ne prend aucune décision de politique par site — donné zéro ability, il n'expose zéro tool. Ce plugin est la couche qui lui donne réellement quelque chose à servir, plus la politique par site dont `mcp-adapter` lui-même n'a aucune notion :

1. **Enregistre les abilities réelles** — `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta`, `update-post-meta`, `list-categories` (voir [`docs/fr/mcp-abilities.md`](mcp-abilities.md)) : la logique WordPress concrète, la sanitization, les schémas.
2. **Déclare un serveur `gn-mcp` dédié** plutôt que de s'appuyer sur le serveur par défaut auto-créé par `mcp-adapter` — `Server.php` désactive explicitement ce défaut (filtre `mcp_adapter_create_default_server`) pour éviter une seconde surface non maîtrisée.
3. **Fournit les cinq filtres par site** (`gn_mcp_bridge_exposed_abilities`, `gn_mcp_bridge_ability_capability`, `gn_mcp_bridge_transport_capability`, `gn_mcp_bridge_meta_key_allowed`, `gn_mcp_bridge_allowed_post_types`, ci-dessous) — `mcp-adapter` ne fait qu'appliquer le `permission_callback` qu'on lui donne ; il n'a aucun système de filtres propre pour ajuster l'exposition ou les capacités par site.

En bref : sans `mcp-adapter`, ce plugin ne peut rien exposer (dépendance stricte, `Requires Plugins: mcp-adapter`). Sans ce plugin, `mcp-adapter` n'a rien à exposer.

**Piège : `mcp-adapter` désactivé après coup.** `Requires Plugins: mcp-adapter` bloque uniquement l'*activation* de ce plugin si `mcp-adapter` n'est pas déjà actif — cela n'empêche pas de désactiver `mcp-adapter` ensuite via WP-CLI ou un panneau d'hébergement (l'interface de la page Extensions bloque bien ce cas, mais tous les chemins de désactivation ne passent pas par elle). Si cela arrive, `gn-mcp-bridge` reste « actif » mais inerte : `mcp_adapter_init` ne se déclenche jamais, donc `Server.php` n'enregistre jamais le serveur `gn-mcp`, et `/wp-json/gn-mcp/mcp` n'existe tout simplement pas. `check_mcp_adapter_dependency()` affiche bien une notice dans `wp-admin`, mais un client MCP qui tape sur l'endpoint mort reçoit juste le `rest_no_route` 404 générique de WordPress — rien qui pointe vers la cause. L'étape 1 de `gnmcp-check` vérifie explicitement cette route pour cette raison.

## Ce qui est exposé

Onze abilities, exposées comme tools MCP sur le serveur `gn-mcp` : `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta`, `update-post-meta`, `list-categories`.

`get-post-outline` + `get-block` + `update-block` forment un chemin de lecture/écriture ciblé pour les articles longs : un outline (résumé de l'arbre de blocs, bien en dessous de la limite de sortie MCP) au lieu du contenu complet de `get-post`, puis le markup brut d'un bloc, puis une écriture `replace`/`insert_before`/`insert_after`/`delete` qui ne modifie que la plage d'octets de ce bloc — `update-post` (remplacement complet de `post_content`) reste le bon outil pour les articles courts ou une réécriture totale.

Référence complète (schémas d'entrée/sortie, capacités par défaut, notes) : [`docs/fr/mcp-abilities.md`](mcp-abilities.md).

Endpoint : `/wp-json/gn-mcp/mcp`.

## Installation sur un nouveau site

1. Installer `mcp-adapter` ≥ 0.7.0 depuis son zip wordpress.org (il embarque `vendor/`) : `wp plugin install https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip --activate`, puis `wp plugin auto-updates disable mcp-adapter` (la version est épinglée volontairement).
2. Copier ce plugin (`gn-mcp-bridge/`) tel quel, `wp plugin activate gn-mcp-bridge`.
3. Créer `inc/core/mcp-abilities.php` dans le thème consommateur (voir "Réglages par site" ci-dessous), l'inclure depuis `functions.php`.
4. Générer une Application Password pour le compte WordPress qui utilisera Claude Code :

   ```bash
   wp user application-password create <login> "Claude Code MCP" --porcelain
   ```

5. Vérifier le handshake avec `curl` avant de toucher à la configuration de tout client :

   ```bash
   USER=<login>
   PASS="<application-password>"
   AUTH=$(printf "%s:%s" "$USER" "$PASS" | base64)
   curl -si -X POST "https://<site>.test/wp-json/gn-mcp/mcp" \
     -H "Authorization: Basic $AUTH" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json, text/event-stream" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl-test","version":"1.0"}}}'
   ```

   On attend un `HTTP/2 200` avec `serverInfo` dans le corps de la réponse et un en-tête `Mcp-Session-Id`. Chaque appel suivant (`tools/list`, `tools/call`) doit répéter cet en-tête et ajouter `MCP-Protocol-Version: 2025-11-25`.

6. Connecter Claude Code, sur la machine qui l'utilisera :

   ```bash
   claude mcp add --transport http --scope user wp-mcp-<nom-court-du-site> \
     "https://<site>.test/wp-json/gn-mcp/mcp" \
     --header "Authorization: Basic <base64(login:application-password)>"
   ```

7. Redémarrer la session Claude Code (ou `/mcp`) — un serveur ajouté en cours de session n'expose pas ses tools tant qu'il n'y a pas de rechargement.

### Convention de nommage

`wp-mcp-[nom-court-du-site]`, ex. `wp-mcp-gregoirenoyelle` pour `gregoirenoyelle.test`. Choisir un slug réellement distinctif dès le départ — un nom comme `wp-mcp-mod` paraît correct jusqu'à ce qu'un second site en `mod`-quelque-chose apparaisse. Une date/un identifiant de projet court vieillit mieux qu'un fragment de nom de dossier.

Plusieurs entrées MCP dans Claude Code peuvent pointer vers le **même site** avec des **comptes WordPress différents** — utile pour vérifier concrètement qu'une restriction de capacité par ability (`gn_mcp_bridge_ability_capability`) tient réellement pour un rôle donné, plutôt que de le supposer en théorie. Étendre le slug avec le compte/rôle : `wp-mcp-[nom-court-du-site]-[rôle-ou-compte]`, ex. `wp-mcp-gregoirenoyelle-author` (site `gregoirenoyelle.test`, un compte distinct avec le rôle `author`) à côté de l'entrée admin `wp-mcp-gregoirenoyelle`. Chaque entrée nécessite sa propre Application Password (voir "Installation sur un nouveau site" ci-dessus, répétée par compte) — aucun conflit tant que les noms complets diffèrent, puisque Claude Code namespace chaque tool par nom de serveur (voir plus bas).

**Pourquoi il n'y a jamais de conflit de nommage** : chaque serveur MCP obtient une clé unique dans `~/.claude.json` (scope `user`), une Application Password distincte (révocable indépendamment), et un jeu d'abilities isolé — même si deux serveurs exposent des abilities au nom identique (`gn-mcp/create-post`) sur deux sites ou comptes différents, ils n'entrent pas en collision, chacun étant scopé à son propre serveur.

**Identifier quel serveur est réellement utilisé** : Claude Code expose chaque tool MCP préfixé par le nom de son serveur — `mcp__<nom-serveur>__<ability>`. Avec `wp-mcp-gregoirenoyelle` et `wp-mcp-gregoirenoyelle-author` connectés en même temps, on verrait côte à côte `mcp__wp-mcp-gregoirenoyelle__gn-mcp-create-post` et `mcp__wp-mcp-gregoirenoyelle-author__gn-mcp-create-post`. Deux façons de s'assurer que c'est le bon qui s'exécute :

- Lancer `/mcp` pour voir les serveurs connectés et confirmer la liste de tools sous celui visé.
- Nommer explicitement le serveur dans le prompt (ex. « utilise `wp-mcp-gregoirenoyelle-author` pour créer un article ») — quand plusieurs serveurs connectés exposent un tool équivalent, une demande non qualifiée laisse le modèle choisir l'un ou l'autre, ce qui annule l'intérêt d'un test restreint par rôle.

## Configuration client : Claude Desktop pour les utilisateurs non techniques

Le flag `--header` de Claude Code (étape 6 ci-dessus) n'a pas d'équivalent dans l'interface de Claude Desktop : **Réglages → Connecteurs** n'accepte que des serveurs MCP distants via OAuth, et refuse tout serveur distant (HTTP/SSE) déclaré directement dans `claude_desktop_config.json`. Il n'existe aucun champ pour un header brut `Authorization: Basic ...`.

La solution de contournement est [`mcp-remote`](https://github.com/geelen/mcp-remote), un petit proxy local : il tourne comme une commande **locale (stdio)** classique — ce que `claude_desktop_config.json` accepte bien — et transmet chaque requête vers l'endpoint WordPress en y injectant le header. Côté client, c'est invisible : la personne ne voit jamais de terminal, elle ouvre simplement Claude Desktop et les tools sont là.

Cette configuration technique est faite **une seule fois, par toi (Grégoire), sur la machine du client**, ce n'est pas quelque chose que le client configure lui-même.

### Étapes (à faire une fois, sur l'ordinateur du client)

1. **Vérifier que Node.js est installé** (`mcp-remote` a besoin de `npx`) :

   ```bash
   node -v
   ```

   S'il est absent, l'installer d'abord (ex. depuis [nodejs.org](https://nodejs.org) — version LTS).

2. **Générer une Application Password dédiée** pour ce client précis, sur le compte WordPress avec lequel il agira (idéalement avec une capacité "brouillons uniquement" via `gn_mcp_bridge_ability_capability` — voir plus bas) :

   ```bash
   wp user application-password create <login-client> "Claude Desktop - <nom du client>" --porcelain
   ```

3. **Vérifier le handshake avec `curl`** d'abord (même commande qu'à l'étape 5 de "Installation sur un nouveau site" ci-dessus) — confirmer un `HTTP/2 200` avant de toucher à la configuration de Desktop.

4. **Localiser `claude_desktop_config.json`** sur la machine du client :
   - macOS : `~/Library/Application Support/Claude/claude_desktop_config.json`
   - Windows : `%APPDATA%\Claude\claude_desktop_config.json`

5. **Ajouter l'entrée du serveur**, avec la chaîne Basic Auth intégrée directement (pas d'indirection par variable d'environnement — reste simple et évite un bug connu, voir la note ci-dessous) :

   ```json
   {
     "mcpServers": {
       "wp-mcp-<nom-court-du-site>": {
         "command": "npx",
         "args": [
           "mcp-remote",
           "https://<site>.test/wp-json/gn-mcp/mcp",
           "--header",
           "Authorization: Basic <base64(login:application-password)>"
         ]
       }
     }
   }
   ```

   Si le fichier contient déjà d'autres entrées `mcpServers`, ajouter celle-ci à côté plutôt que de remplacer le fichier.

6. **Redémarrer Claude Desktop** complètement (quitter, pas juste fermer la fenêtre).

7. **Vérifier côté client** : ouvrir une nouvelle conversation, vérifier que l'icône outils/prise affiche `wp-mcp-<nom-court-du-site>` connecté, et lancer un prompt de lecture seule sans risque (ex. "Liste les catégories de ce site").

Convention de nommage : identique à Claude Code, `wp-mcp-[nom-court-du-site]`.

### Pièges connus

- **Windows** : un bug documenté dans certaines versions de `mcp-remote`/Desktop corrompt les valeurs `--header` contenant des espaces sous Windows. Si le header est silencieusement perdu ou que les requêtes reviennent en `401`, tester une autre méthode de quoting ou chercher une note de version `mcp-remote` à ce sujet avant de suspecter l'Application Password.
- **L'Application Password se trouve en clair** dans `claude_desktop_config.json` sur la machine du client — même modèle de confiance qu'une clé SSH sur un laptop. Si la machine du client est partagée, perdue, ou que la mission se termine, révoquer cette Application Password précise depuis WordPress (`wp user application-password delete <login-client> <uuid>` ou via son profil utilisateur) plutôt que de faire tourner un identifiant partagé.
- **Ceci contourne entièrement le flux OAuth officiel des Connecteurs.** C'est un pont pragmatique, pas le chemin sanctionné par Anthropic — si Claude Desktop supporte un jour des headers personnalisés nativement dans les Connecteurs, préférer cette solution.
- Restreindre étroitement le compte de l'Application Password (`gn_mcp_bridge_ability_capability`, brouillons uniquement) — ce canal a plus de chances de finir sur la machine d'une personne non technique que sur ton propre poste de dev, donc l'impact d'un identifiant qui fuite doit être limité par conception.
- **Un pull de la base locale depuis la prod (`wp-sync-db.sh pull`) efface les Application Passwords locales.** Elles vivent dans `wp_usermeta` (`_application_passwords`), et un pull complet écrase cette table avec celle du serveur distant — tout serveur MCP local enregistré dans `~/.claude.json` se met alors à échouer en `401`, son identifiant stocké ne correspondant plus à rien en base locale. Correctif : définir `MCP_PRESERVE_USERS="<login> <login2>"` dans le `.local-working/wp-sync.conf` du projet — `wp-sync-db.sh` sauvegarde et restaure alors la valeur `_application_passwords` de ces logins autour du pull, ce qui préserve l'identifiant existant sans avoir à le régénérer.

## Réglages par site (cinq filtres)

À définir depuis le thème consommateur, jamais en modifiant ce plugin :

| Filtre | Rôle |
|---|---|
| `gn_mcp_bridge_exposed_abilities` | Quelles abilities sont exposées comme tools MCP sur ce site |
| `gn_mcp_bridge_ability_capability( $capability, $ability_name )` | Capacité WordPress requise pour exécuter une ability |
| `gn_mcp_bridge_transport_capability` | Capacité requise pour atteindre l'endpoint, tout court |
| `gn_mcp_bridge_meta_key_allowed( $allowed, $key )` | Quelles clés de meta `get-post-meta`/`update-post-meta` peuvent lire/écrire |
| `gn_mcp_bridge_allowed_post_types` | Sur quels types de contenu les abilities CRUD peuvent opérer (défaut `post`, `page`, et `wp_block`) |

Référence complète avec exemples pour chacun : [`CLAUDE.md`](../../CLAUDE.md).

## Ajouter une nouvelle ability

Voir la section "Adding a new ability" dans [`CLAUDE.md`](../../CLAUDE.md). Démarrer Claude Code depuis le dossier de ce plugin pour ce travail — voir la note "Bundled skills" de `CLAUDE.md` pour le pourquoi.

## Développement

```bash
composer install
composer test
```

Les tests unitaires (`tests/Unit/`) tournent avec les mocks de fonctions WordPress de Brain Monkey — pas de base de données ni de `wp-load.php` nécessaires. GitHub Actions exécute une vérification de syntaxe PHP et la suite PHPUnit sous PHP 8.2 à chaque push sur `main`/`dev` et à chaque pull request vers `main`.

Composer ne sert qu'aux outils de développement : le plugin charge ses classes via son propre `src/autoload.php`, donc `vendor/` n'est jamais nécessaire à l'exécution et n'est pas livré avec le plugin.

## Licence

GPL-2.0-or-later — voir [`LICENSE`](../../LICENSE).
