# {{SITE_NAME}} : travail de contenu

Ce dossier sert à modifier le contenu de https://{{DOMAIN}} via le serveur MCP
`{{SERVER}}`. Il n'y a pas de code ici et il ne faut pas en écrire : pas de
PHP, pas de fichiers de thème ou de plugin, pas de WP-CLI, aucune modification
de `.mcp.json` ni de `.claude/`.

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

- Langue : {{LANGUAGE}}. Ton : {{TONE}}.
- Catégories utilisées : {{CATEGORIES}}. Ne jamais en créer sans demander.
{{EDITORIAL_RULES}}

## En cas d'erreur

Ne pas essayer de réparer le serveur, le plugin, la connexion MCP ou la
configuration du site. Noter le message d'erreur et contacter
{{OPERATOR_CONTACT}}.
