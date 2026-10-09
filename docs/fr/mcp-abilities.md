# Abilities MCP exposées

🇬🇧 [English version](../mcp-abilities.md)

Référence complète de chaque ability enregistrée et exposée par ce plugin sur le serveur MCP `gn-mcp` (`/wp-json/gn-mcp/mcp`). Générée à partir de l'état actuel de `src/Abilities/*.php` et `src/Config.php` — à tenir à jour à chaque ajout, suppression ou modification d'ability (voir l'étape 8 de `gnmcp-add-ability`).

Seuls des tools sont exposés — aucune resource ni prompt MCP n'est déclarée (`Server.php` ne passe que `Config::exposed_abilities()` à `create_server()`).

## `gn-mcp/search-posts`

Trouve des articles existants par mot-clé, catégorie ou tag.

- **Capacité par défaut** : `read`
- **Source** : `src/Abilities/SearchPostsAbility.php`

**Entrée**

| Paramètre | Type | Requis | Défaut | Description |
|---|---|---|---|---|
| `keyword` | string | non | — | Mot-clé recherché dans le titre et le contenu de l'article |
| `category` | string | non | — | Slug de catégorie pour filtrer les articles |
| `tag` | string | non | — | Slug de tag pour filtrer les articles |
| `limit` | integer | non | `5` | Nombre maximum d'articles retournés |
| `post_type` | string | non | `post` | Type de contenu recherché. Doit faire partie des types autorisés sur ce site |

**Sortie** : tableau d'objets — `id`, `title`, `excerpt`, `url`, `date`, `categories` (string[]), `tags` (string[])

**Notes** : seuls les articles avec `post_status = publish` sont recherchés. Les filtres catégorie/tag se combinent avec `relation: AND` quand les deux sont fournis. Un `post_type` hors de la liste blanche du site (`gn_mcp_bridge_allowed_post_types`, défaut `post`, `page`, et `wp_block`) échoue avec `gn_mcp_post_type_not_allowed` (HTTP 400).

## `gn-mcp/get-post`

Récupère le contenu complet d'un article par ID ou par slug.

- **Capacité par défaut** : `edit_others_posts`
- **Source** : `src/Abilities/GetPostAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | non* | ID de l'article |
| `slug` | string | non* | Slug de l'article |
| `post_type` | string | non | Type de contenu pour la recherche par `slug` uniquement (défaut `post`). Ignoré lors d'une recherche par `id` |

\* Au moins un des deux (`id`/`slug`) devrait être fourni ; `id` est prioritaire si les deux sont présents. Aucun des deux n'est requis par le schéma, donc un appel vide se résout en « introuvable » plutôt qu'en erreur de validation.

**Sortie** : objet — `id`, `title`, `content`, `excerpt`, `url`, `date`, `categories` (string[]), `tags` (string[]), `status`, `comment_status`, `ping_status`

**Notes** : la recherche par `slug` inclut les statuts `publish`, `draft` et `private` (pas uniquement les articles publiés) — cette ability peut lire des brouillons si la capacité de l'appelant le permet. Il n'y a pas de vérification de propriété par article (contrairement à `update-post`), donc la capacité par défaut est délibérément relevée à `edit_others_posts` (palier Éditeur) : avec un défaut plus bas, n'importe quel appelant authentifié pourrait lire le brouillon ou l'article privé de n'importe quel auteur par ID ou slug, pas seulement les siens. Quel que soit l'article résolu (par `id` ou `slug`), son type réel doit faire partie de la liste blanche du site (`gn_mcp_bridge_allowed_post_types`, défaut `post`, `page`, et `wp_block`), sinon l'appel se résout en « introuvable » — ce qui empêche aussi une recherche par `id` d'atteindre un type de contenu hors liste blanche.

## `gn-mcp/get-post-outline`

Résume l'arbre de blocs d'un article (path, nom de bloc, nom de metadata, occurrence, taille) sans son contenu, plus un `content_hash` à transmettre à `update-block`. À utiliser avant `get-block`/`update-block` sur un article long, à la place de `get-post`.

- **Capacité par défaut** : `edit_others_posts`
- **Source** : `src/Abilities/GetPostOutlineAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article |
| `max_depth` | integer | non | Liste chaque bloc jusqu'à cette profondeur (`0` = niveau racine uniquement). Par défaut : les blocs de niveau racine, tout bloc ayant un nom de metadata (champ « Renommer » de l'éditeur), et leurs enfants directs |

**Sortie** : objet — `id`, `title`, `content_hash`, `bytes`, `block_count`, `listed_count`, `blocks` (tableau de `path`, `blockName`, `name`, `occurrence`, `depth`, `bytes`, `children` — `name`/`occurrence` présents uniquement sur les blocs nommés)

**Notes** : utilise `BlockLocator::scan()` — un scan par regex du `post_content` brut réutilisant le pattern de délimiteur de bloc du cœur (`WP_Block_Parser::next_token()`), jamais `parse_blocks()`/`serialize_blocks()`. `path` ne compte que les vrais blocs (les intervalles freeform entre blocs ne sont pas indexés — ce qui diffère des index de niveau racine de `parse_blocks()`, qui comptent le freeform). Même verrou `load_post()` que `get-block`/`update-block` : le type de contenu doit être dans `gn_mcp_bridge_allowed_post_types`, et l'appelant a besoin de `current_user_can( 'edit_post', $id )` — lire le markup d'un bloc expose la même donnée qu'éditer l'article, donc ce défaut générique `edit_others_posts` (même palier que `get-post`) s'ajoute à la vérification par article, contrairement à `get-post-meta`/`update-post-meta` qui ne demandent que la vérification par article. La profondeur de liste par défaut garde la sortie bien en dessous de la limite de sortie MCP même sur un article long (une page réelle de 48 Ko / 194 blocs a listé 114 blocs en ~10,6 Ko de JSON) ; passer `max_depth` pour l'élargir ou la réduire.

## `gn-mcp/get-block`

Récupère le markup brut d'un bloc d'un article (blocs internes inclus), localisé par `path` ou par nom de metadata `name` + `occurrence` comme listé par `get-post-outline`.

- **Capacité par défaut** : `edit_others_posts`
- **Source** : `src/Abilities/GetBlockAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article |
| `path` | string | non* | Path du bloc issu de `get-post-outline`, ex. `"4/1/0"` (index d'enfants, intervalles freeform non comptés) |
| `name` | string | non* | Nom de metadata du bloc. Refusé comme ambigu si plusieurs blocs le partagent et qu'aucun `occurrence` n'est fourni |
| `occurrence` | integer | non | Index 0-based parmi les blocs partageant le même `name`, dans l'ordre du document. Uniquement avec `name` |

\* Exactement un des deux (`path`/`name`) est requis — fournir les deux, ou aucun, est refusé (`gn_mcp_block_target_invalid`, HTTP 400).

**Sortie** : objet — `id`, `content_hash`, `block` (résumé : `path`, `blockName`, `name`, `occurrence`, `depth`, `bytes`, `children`), `markup` (markup brut du bloc, délimiteurs inclus)

**Notes** : `content_hash` correspond à celui de `get-post-outline` — le transmettre comme `expected_hash` à un appel `update-block` suivant pour que l'écriture soit refusée si l'article a changé entre-temps. Un `name` ambigu sans `occurrence` échoue avec `gn_mcp_block_ambiguous` (HTTP 409) et liste chaque candidat (`occurrence`/`path`/`blockName`) — jamais « premier trouvé ». Un `path`/`name` qui ne se résout pas échoue avec `gn_mcp_block_not_found` (HTTP 404). Même verrou `load_post()` que `get-post-outline` (liste blanche de types de contenu + `edit_post` par article).

## `gn-mcp/create-post`

Crée un nouvel article.

- **Capacité par défaut** : `edit_posts`
- **Source** : `src/Abilities/CreatePostAbility.php`

**Entrée**

| Paramètre | Type | Requis | Défaut | Description |
|---|---|---|---|---|
| `title` | string | oui | — | Titre de l'article |
| `content` | string | oui | — | Corps de l'article en markup de bloc Gutenberg (`<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->`) ; du HTML simple s'ouvre en bloc Classique |
| `excerpt` | string | non | — | Extrait de l'article |
| `categories` | string[] | non | — | Noms ou slugs de catégories (créées si elles n'existent pas) |
| `tags` | string[] | non | — | Noms de tags |
| `status` | string | non | `draft` | Statut : `draft`, `publish`, `pending`, `private` — retombe sur `draft` pour toute autre valeur |
| `post_type` | string | non | `post` | Type de contenu à créer. Doit faire partie des types autorisés sur ce site |
| `comment_status` | string | non | `default_comment_status` du site | `open` ou `closed`. Toute autre valeur est ignorée (défaut du site conservé) |
| `ping_status` | string | non | `default_ping_status` du site | `open` ou `closed`. Toute autre valeur est ignorée (défaut du site conservé) |

**Sortie** : objet — `id`, `url`, `edit_url`, `status`, `comment_status`, `ping_status`

**Notes** : `content` suit la règle propre au cœur de WordPress : il est enregistré tel quel pour un appelant disposant de la capacité `unfiltered_html` (Administrateur/Éditeur en single-site, Super Admin en multisite, jamais quand `DISALLOW_UNFILTERED_HTML` est défini), et sinon assaini par `filter_block_content()`, la fonction du cœur consciente des blocs (kses sur chaque valeur d'attribut de bloc, re-sérialisée avec le HTML échappé en séquences unicode), suivie de `wp_kses_post()` (le reste du markup). `wp_kses_post()` seul cassait les commentaires de bloc Gutenberg dont les attributs JSON contiennent du HTML brut (ex. le contenu d'un pattern override de bloc synchronisé avec un lien) : `wp_pre_kses_less_than()` du cœur s'exécute avant sa propre gestion des attributs de bloc, voit le `<` brut, et encode tout le commentaire `<!-- wp:... -->` en entités HTML, affiché ensuite en texte brut. Le contenu est aussi « slashé » avant `wp_insert_post()`, qui retire les antislashs de son entrée — sans quoi les échappements du JSON de bloc (`\u003c`, `\"`) seraient supprimés. Les catégories données par nom ou slug sont d'abord recherchées parmi les termes existants, créées seulement en l'absence de correspondance. Un `post_type` hors de la liste blanche du site (`gn_mcp_bridge_allowed_post_types`, défaut `post`, `page`, et `wp_block`) échoue avec `gn_mcp_post_type_not_allowed` (HTTP 400). Une demande de création directe en `status: publish` exige en plus la capacité de publication propre à ce type de contenu, au-delà de la capacité par défaut de l'ability — résolue depuis l'enregistrement du type de contenu ciblé (`publish_posts` pour `post`, `publish_pages` pour `page`, `publish_blocks` pour `wp_block`), et non figée sur `publish_posts`, car un appelant peut détenir la capacité générique sans détenir celle propre à un type de contenu donné. Sans cela, un défaut de palier Contributeur (`edit_posts`) permettrait à un Contributeur de publier directement, contournant la règle propre à WordPress selon laquelle un Contributeur ne publie jamais lui-même. Ce contrôle supplémentaire est refusé avec l'erreur de permission standard de l'ability (HTTP 403), comme un échec de la capacité par défaut.

## `gn-mcp/update-post`

Met à jour un article existant (titre, contenu, extrait, statut, catégories, tags).

- **Capacité par défaut** : `edit_posts`
- **Source** : `src/Abilities/UpdatePostAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article à mettre à jour |
| `title` | string | non | Titre de l'article |
| `content` | string | non | Corps de l'article en markup de bloc Gutenberg (`<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->`) ; du HTML simple s'ouvre en bloc Classique |
| `excerpt` | string | non | Extrait de l'article |
| `categories` | string[] | non | Noms ou slugs de catégories (créées si elles n'existent pas). Remplace les catégories actuelles de l'article |
| `tags` | string[] | non | Noms de tags. Remplace les tags actuels de l'article |
| `status` | string | non | Statut : `draft`, `publish`, `pending`, `private` — toute autre valeur est ignorée (statut inchangé) |
| `comment_status` | string | non | `open` ou `closed`. Toute autre valeur est ignorée (statut inchangé) |
| `ping_status` | string | non | `open` ou `closed`. Toute autre valeur est ignorée (statut inchangé) |

**Sortie** : objet — `id`, `url`, `edit_url`, `status`, `comment_status`, `ping_status`

**Notes** : `content` reçoit le même assainissement conditionné à `unfiltered_html` et le même slashing que `create-post`. Seuls les champs réellement présents dans l'entrée sont modifiés — omettre un champ le laisse inchangé (ex. omettre `status` ne le réinitialise pas à draft). `categories`/`tags`, quand fournis, remplacent entièrement l'ensemble existant plutôt que de le fusionner. Retourne une `WP_Error` `gn_mcp_post_not_found` (HTTP 404) si `id` ne correspond à aucun article existant, ou s'il existe mais que son type de contenu n'est pas dans la liste blanche du site (`gn_mcp_bridge_allowed_post_types`). Contrairement aux autres abilities, le verrou générique `edit_posts` de `check_permission()` ne suffit pas ici — `execute()` effectue aussi une vérification par article `current_user_can( 'edit_post', $post_id )` (et `publish_post` si le `status` demandé est `publish`/`private`), retournant `gn_mcp_forbidden` (HTTP 403) sinon. Sans cela, un appelant n'ayant que la capacité générique `edit_posts` (ex. Contributeur) pourrait modifier ou publier n'importe quel article, pas seulement les siens. `check_permission()` est elle-même consciente du type de contenu, et de façon générique (pas seulement pour `page`) : les rôles par défaut de WordPress n'associent pas nécessairement les capacités génériques `edit_posts`/`publish_posts` aux capacités propres à un type de contenu donné, donc quand l'`id` cible résout vers un type de contenu autre que le `post` natif, les noms de capacités réels sont résolus depuis l'enregistrement de ce type de contenu (`get_post_type_object()->cap`) — `edit_pages`/`publish_pages` pour une `page`, `edit_blocks`/`publish_blocks` pour un `wp_block` — avant même que l'appel puisse atteindre `execute()`.

## `gn-mcp/update-block`

Remplace, insère avant/après, ou supprime un bloc d'un article, localisé par `path` ou par metadata `name` + `occurrence` (voir `get-post-outline`). Seule la plage d'octets ciblée du contenu brut change ; une révision normale est créée.

- **Capacité par défaut** : `edit_posts`
- **Source** : `src/Abilities/UpdateBlockAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article |
| `operation` | string | oui | `replace`, `insert_before`, `insert_after`, `delete` |
| `path` / `name` / `occurrence` | — | non* | Même ciblage que `get-block` |
| `markup` | string | oui sauf pour `delete` | Exactement un bloc en markup de bloc Gutenberg (blocs internes autorisés), ex. `<!-- wp:paragraph --><p>Texte</p><!-- /wp:paragraph -->`. Envoyer les `<`/`>`/`"` bruts dans le JSON du commentaire de bloc, jamais d'échappements `\uXXXX` |
| `expected_hash` | string | non | `content_hash` issu de `get-post-outline`/`get-block`. Refusé (`gn_mcp_stale_content`, HTTP 409) si le contenu de l'article ne correspond plus. Fortement recommandé — requis en pratique lors d'un ciblage par `path`, puisqu'un insert/delete précédent décale tous les paths qui suivent |

\* Exactement un des deux (`path`/`name`) requis, même règle que `get-block`.

**Sortie** : objet — `id`, `operation`, `block` (résumé du bloc nouveau/ciblé, `null` pour `delete`), `markup` (`null` pour `delete`), `valid` (le résultat enregistré se reparse comme un seul vrai bloc, non-freeform), `saved_as_sent` (`true` si le contenu enregistré correspond octet pour octet à ce qui a été inséré — `false` signale une modification du cœur au moment de l'enregistrement, ex. kses ayant altéré quelque chose), `new_hash`, `bytes_before`, `bytes_after`

**Notes** : découpe le `post_content` brut par décalage d'octets (`substr_replace()`) — ne re-sérialise jamais l'arbre de blocs avec `serialize_blocks()` — les octets hors de la plage ciblée ne sont donc jamais touchés par cette ability. Le `block.path` retourné pour `insert_after` est le path de la cible avec son dernier segment incrémenté (la position réelle du nouveau frère) ; `delete` retire aussi un séparateur d'espace adjacent (fin, sinon début) pour qu'un insert/delete suivant redonne un résultat identique octet pour octet. `markup` est validé comme exactement un bloc fermé (le scan du locator et `parse_blocks()` du cœur doivent tous deux être d'accord : un seul bloc de niveau racine, non `core/freeform`, rien d'autre autour) avant et après sanitization — HTML classique/freeform, plusieurs blocs frères, ou du texte autour du bloc échouent tous avec `gn_mcp_invalid_fragment` (HTTP 422). La sanitization suit la même règle conditionnée à `unfiltered_html` que `update-post`/`create-post` (`filter_block_content()` + `wp_kses_post()`) mais appliquée **au fragment uniquement**, jamais à l'article entier. Les filtres kses `content_save_pre` du cœur s'exécutent tout de même sur le contenu *entier* à l'enregistrement pour un appelant sans `unfiltered_html` — une sanitization limitée au fragment ne peut pas l'empêcher, donc l'écriture est d'abord simulée via `wp_filter_post_kses()` et refusée avec `gn_mcp_kses_would_alter` (HTTP 422) si quoi que ce soit changerait, plutôt que de corrompre silencieusement un bloc non ciblé (typiquement un bloc dont le JSON contient du HTML brut, ex. un override de pattern synchronisé enregistré plus tôt par un utilisateur `unfiltered_html`). Cibler un bloc sans commentaire de fermeture (`closed: false` dans `get-post-outline`/`get-block`, c.-à-d. que le parseur du cœur l'a fait courir jusqu'à la fin du document) est refusé avec `gn_mcp_block_unclosed` (HTTP 422) — corriger le markup dans l'éditeur d'abord. `check_permission()` est consciente du type de contenu comme celle d'`update-post` (`edit_pages`/`edit_blocks`/... résolues depuis l'enregistrement du type de contenu ciblé quand ce n'est pas le `post` natif), plus la même vérification par article `current_user_can( 'edit_post', $post_id )` dans `execute()` qu'`update-post`.

## `gn-mcp/delete-post`

Met un article existant à la corbeille.

- **Capacité par défaut** : `delete_posts`
- **Source** : `src/Abilities/DeletePostAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article à mettre à la corbeille |

**Sortie** : objet — `id`, `status`

**Notes** : passe par `wp_trash_post()` (réversible) plutôt qu'une suppression permanente — aucune option de suppression permanente n'est exposée. Retourne une `WP_Error` `gn_mcp_post_not_found` (HTTP 404) si `id` ne correspond à aucun article existant, ou s'il existe mais que son type de contenu n'est pas dans la liste blanche du site (`gn_mcp_bridge_allowed_post_types`). Même schéma que `update-post` : le verrou générique `delete_posts` au niveau de l'ability ne suffit pas seul — `execute()` effectue aussi une vérification par article `current_user_can( 'delete_post', $post_id )`, retournant `gn_mcp_forbidden` (HTTP 403) sinon, afin que la propriété et les capacités propres à un type de contenu (ex. `delete_page`, `delete_others_posts`) soient appliquées via le `map_meta_cap()` natif de WordPress plutôt que la seule capacité générique.

## `gn-mcp/get-post-meta`

Lit les champs personnalisés (post meta) d'un article existant.

- **Capacité par défaut** : `edit_posts`
- **Source** : `src/Abilities/GetPostMetaAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article dont on lit les meta |
| `keys` | string[] | non | Clés de meta à retourner. Omettre pour retourner toutes les clés autorisées (non protégées) |

**Sortie** : objet — `id`, `meta` (objet clé → valeur ; une clé avec plusieurs valeurs stockées retourne un tableau)

**Notes** : même vérification par article `current_user_can( 'edit_post', $post_id )` que `update-post` (retourne `gn_mcp_forbidden`, HTTP 403, sinon), et la même vérification de liste blanche par type de contenu (`gn_mcp_post_not_found`, HTTP 404, si le type de l'article n'est pas autorisé). Contrairement à `update-post`, `check_permission()` n'est pas ici consciente du type de contenu — le mécanisme `edit_post`/`map_meta_cap()` de WordPress utilisé dans la vérification par article résout déjà vers `edit_page` pour une cible `page`, donc le défaut `edit_posts` au niveau de l'ability ne crée pas le même écart pour une ability en lecture seule. Les clés de meta « protégées » (préfixées `_`, ex. `_thumbnail_id`, `_edit_lock`) sont exclues par défaut — voir `gn_mcp_bridge_meta_key_allowed` dans `CLAUDE.md`.

## `gn-mcp/update-post-meta`

Définit, met à jour ou supprime des champs personnalisés (post meta) d'un article existant.

- **Capacité par défaut** : `edit_posts`
- **Source** : `src/Abilities/UpdatePostMetaAbility.php`

**Entrée**

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `id` | integer | oui | ID de l'article dont on met à jour les meta |
| `meta` | object | non | Map clé → valeur à définir ou mettre à jour |
| `delete` | string[] | non | Clés de meta à supprimer |

**Sortie** : objet — `id`, `meta` (l'ensemble complet des meta autorisées après modification)

**Notes** : même vérification par article `current_user_can( 'edit_post', $post_id )` que `update-post`, et la même vérification de liste blanche par type de contenu (`gn_mcp_post_not_found`, HTTP 404, si le type de l'article n'est pas autorisé). Toute clé de `meta` ou `delete` non autorisée par `gn_mcp_bridge_meta_key_allowed` (protégée par défaut) fait échouer l'appel entier avec `gn_mcp_meta_key_forbidden` (HTTP 403) — rien n'est partiellement appliqué. Les valeurs string sont assainies avec `sanitize_text_field()` ; les valeurs non-string (nombres, booléens, tableaux) sont transmises telles quelles.

## `gn-mcp/list-categories`

Liste les catégories d'articles existantes avec leur nombre d'articles.

- **Capacité par défaut** : `read`
- **Source** : `src/Abilities/ListCategoriesAbility.php`

**Entrée** : aucune

**Sortie** : tableau d'objets — `name`, `slug`, `count`

**Notes** : inclut les catégories vides (`hide_empty: false`). Reste délibérément au défaut `read` — les nombres d'articles par terme ne comptent que les articles au statut `publish`, donc aucune fuite de contenu brouillon/privé ne justifierait de le relever comme pour `get-post`.

## Les niveaux de capacité, en un coup d'œil

1. **Transport** — `gn_mcp_bridge_transport_capability` (défaut `read`) : requis juste pour atteindre `/wp-json/gn-mcp/mcp`, vérifié avant toute exécution d'ability.
2. **Exposition** — `gn_mcp_bridge_exposed_abilities` : lesquelles des abilities ci-dessus sont même listées sur le serveur `gn-mcp` de ce site. Une ability filtrée ici est invisible pour les clients MCP, quelle que soit la capacité.
3. **Capacité par ability** — `gn_mcp_bridge_ability_capability( $capability, $ability_name )` : la capacité WordPress précise requise pour exécuter une ability donnée (valeurs par défaut dans `Config::DEFAULT_CAPABILITIES`, indiquées ci-dessus).
4. **Liste blanche des meta** — `gn_mcp_bridge_meta_key_allowed( $allowed, $key )` : pour `get-post-meta`/`update-post-meta` uniquement, quelles clés de meta individuelles peuvent être lues/écrites, indépendamment de la capacité par ability ci-dessus (exclut par défaut les clés protégées par WordPress).
5. **Liste blanche des types de contenu** — `gn_mcp_bridge_allowed_post_types` : sur quels types de contenu `search-posts`, `get-post`, `get-post-outline`, `get-block`, `create-post`, `update-post`, `update-block`, `delete-post`, `get-post-meta` et `update-post-meta` peuvent opérer (défaut `post`, `page`, et `wp_block`).

Les cinq sont définis depuis le thème consommateur (voir la section « Réglages par site » de `README.md` et `CLAUDE.md` pour des exemples de filtres) — jamais en modifiant ce plugin.
