# LITECLAW v2 — AI Web Intelligence Loop

## Déploiement Hostinger

### Fichiers à uploader dans public_html/liteclaw/ (ou sous-dossier choisi)

```
index.php       ← Backend PHP + router AJAX
interface.html  ← Frontend (servi par index.php)
.htaccess       ← Config Apache / LiteSpeed
```

Le dossier `data/` est créé automatiquement au premier appel (SQLite + logs).

### Vérification permissions après upload
```
data/          → 0755
data/*.sqlite  → 0644
data/error.log → 0644 (créé auto)
```

### Architecture
- `index.php` sert le HTML ET gère les requêtes AJAX (`action=scrape`, `action=ask`)
- Tout en **cURL** (jamais file_get_contents sur URL externe)
- SQLite pour persister les sessions de conversation
- Rotation automatique des 3 clés Mistral
- Modèle scrape : `mistral-small-2603` (rapide)
- Modèle chat :  `mistral-medium-2505` (plus précis pour les réponses)

### Flux utilisateur
1. Saisir une URL → cURL scrape → nettoyage HTML → Mistral analyse + génère 5 questions
2. Clic sur une question → Mistral répond avec contexte → génère 4 nouvelles questions
3. Boucle infinie jusqu'à 10 tours (mémoire tronquée automatiquement)
