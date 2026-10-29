# Étude de cas TMA : prise de rendez-vous au guichet

Ce dépôt contient la correction des tickets TMA-101 à TMA-107 :

- un module de règles de gestion en PHP pur, indépendant de toute base de données et de tout framework : [`app/Domain/RendezVous/`](app/Domain/RendezVous) ;
- des tests automatisés, un ou plusieurs par ticket : [`tests/Unit/RendezVous/TicketsTest.php`](tests/Unit/RendezVous/TicketsTest.php) ;
- les requêtes d'impact : [`requetes.sql`](requetes.sql) ;
- l'export fourni : [`database/export/base_rendez_vous.sql`](database/export/base_rendez_vous.sql).

Le projet est un squelette Laravel 13, mais le module n'utilise aucune classe de Laravel. Il peut être appelé depuis un contrôleur, une commande ou un autre framework.

## Lancer les tests

Prérequis : PHP 8.3 ou plus récent (testé avec PHP 8.5) et Composer.

```bash
composer install
php artisan test --testsuite=Unit
```

Sans passer par Laravel :

```bash
vendor/bin/phpunit tests/Unit/RendezVous
```

Résultat attendu : 23 tests, tous verts. `TicketsTest.php` rejoue les 7 tickets ; `ReglesTest.php` couvre les autres cas refusés du tableau (service inconnu, date illisible, date passée, grille horaire, statuts définitifs).

## Le module

| Fichier | Rôle |
|---|---|
| `ReglesRendezVous.php` | Les quatre contrôles demandés. L'heure courante est toujours passée en paramètre. |
| `RefusMetier.php` | Exception qui porte le code HTTP et le message exact de la section 2, un constructeur par cas refusé. |
| `RendezVous.php` | Objet rendez-vous immuable (id, NPI, service, date et heure, statut). |

```php
$regles = new ReglesRendezVous();
$maintenant = new DateTimeImmutable('2026-11-20 18:00', ReglesRendezVous::fuseau());

try {
    $rdv = $regles->controlerPrise('0102030405', 'PERMIS', '2026-11-27T09:00', $existants, $maintenant);
} catch (RefusMetier $refus) {
    // $refus->codeHttp : 409 ; $refus->getMessage() : « Vous avez déjà un rendez-vous à venir. »
}

$regles->controlerAnnulation($rdv, $maintenant);            // renvoie le rendez-vous au statut annule
$regles->controlerChangementStatut($rdv, 'honore');         // renvoie le rendez-vous au statut honore
$regles->listerPourUsager($rdvsUsager, 1, $maintenant);     // ['page', 'parPage', 'total', 'rendezVous']
```

Choix d'implémentation :

- Fuseau : toutes les dates sont lues et comparées à l'heure du Bénin, `+01:00` fixe, sans heure d'été.
- Ordre des contrôles d'une prise : NPI, service, format de la date, date passée, week-end, grille horaire, rendez-vous déjà à venir, créneau plein. Les erreurs de saisie (400) passent avant les refus métier (409).
- Date : format strict `AAAA-MM-JJTHH:MM`. Une date inexistante comme le 31/02 est refusée avec le message prévu, jamais avec une erreur interne.
- Rendez-vous « à venir » : statut `confirme` et date postérieure ou égale à maintenant.
- Annulation : autorisée jusqu'à 24 h pile avant le rendez-vous. Le calcul se fait en secondes sur des horodatages.
- Liste : le prochain rendez-vous à venir en tête, puis tri sur la date du plus récent au plus ancien. Pages de 10, `page < 1` ramenée à 1.

## Tickets

| Ticket | Qualification | Règle (section 2) |
|---|---|---|
| TMA-101 | Anomalie | « Le centre reçoit du lundi au vendredi » |
| TMA-102 | Anomalie | « Un créneau accueille au plus 3 usagers » |
| TMA-103 | Anomalie | « Une saisie invalide […] est refusée avec l'un des messages ci-dessous, jamais par une erreur interne » |
| TMA-104 | Anomalie | « L'usager peut annuler son rendez-vous jusqu'à 24 heures avant l'heure prévue » |
| TMA-105 | Anomalie | « honore, absent ou annule. Ces trois statuts sont définitifs » |
| TMA-106 | Anomalie | « listés du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de 10 » |
| TMA-107 | Conforme | « Un usager ne peut avoir qu'un seul rendez-vous à venir, tous services confondus » |

### TMA-101 : rendez-vous accepté un samedi

- Cause probable : le contrôle du week-end ne teste que le dimanche. L'export le confirme : 8 rendez-vous un samedi, aucun un dimanche. Il s'agit typiquement d'un test sur le seul jour 0 ou 7 (selon la convention de numérotation), sans le samedi.
- Correction : refus si le jour ISO (`N`, 1 = lundi à 7 = dimanche) vaut 6 ou plus.
- Tests : `test_tma101_un_rendez_vous_le_samedi_est_refuse` rejoue la trace (samedi 14/11 10:00) ; un second test vérifie le dimanche.
- Impact (requête 1) : voir [Résultats des requêtes](#résultats-des-requêtes).

### TMA-102 : quatre usagers sur un créneau de trois

- Cause probable : erreur de borne. Le code refuse quand il y a plus de 3 rendez-vous existants (`> 3`) au lieu de 3 ou plus (`>= 3`), ce qui laisse passer un 4e. Sur les 3 créneaux concernés de l'export, c'est toujours le 4e réservé qui est en trop, jamais un 5e.
- Correction : le contrôle compte les rendez-vous non annulés du créneau et refuse dès que le compte atteint 3. Un rendez-vous annulé libère sa place.
- Tests : `test_tma102_un_quatrieme_rendez_vous_sur_le_creneau_est_refuse` rejoue la réservation du n° 697 face aux n° 632, 644 et 681 ; `test_tma102_un_rendez_vous_annule_libere_sa_place` vérifie qu'un rendez-vous annulé libère sa place.

### TMA-103 : lettre dans le NPI, erreur 500

- Cause probable : contrôle de format absent. Le NPI est utilisé tel quel, sans doute converti en nombre ou envoyé à une étape qui suppose des chiffres, et l'exception remonte en 500. L'export montre aussi des NPI de 11 et 12 chiffres acceptés (n° 459 et 642) : la longueur exacte n'est pas vérifiée non plus.
- Correction : `preg_match('/^[0-9]{10}$/D', $npi)` sur une chaîne, avant tout autre traitement. Toute autre valeur (lettre, longueur, espace, retour à la ligne, `null`, entier) donne un refus 400 avec le message prévu.
- Tests : `test_tma103_un_npi_contenant_une_lettre_est_refuse_avec_le_message_prevu` rejoue la trace (`06142A3647`) ; un second test parcourt les formes invalides.

### TMA-104 : annulation refusée 40 h avant

- Constat : le 17/11 à 18:00, il reste 40 h avant le rendez-vous du 19/11 à 10:00. L'annulation doit être acceptée.
- Cause probable : erreur de calcul du délai. L'export montre 35 annulations en août et septembre, 2 entre le 1er et le 3 octobre, puis plus aucune (requête B8). Le refus touche donc presque tout le monde depuis un déploiement début octobre. L'hypothèse la plus cohérente est une erreur d'unité : un écart exprimé en minutes, ici 2 400, comparé à un seuil en secondes (86 400). Une annulation ne passerait alors que plus de 60 jours avant le rendez-vous.
- Correction : écart calculé en secondes entre deux horodatages et comparé à `24 * 3600` ; refus seulement si l'écart est strictement inférieur.
- Tests : `test_tma104_annuler_40_heures_avant_est_accepte` rejoue la trace (n° 709) ; `test_tma104_la_borne_des_24_heures` vérifie que 24 h pile passe et que 23 h 59 est refusé.

### TMA-105 : un rendez-vous annulé passé à « honoré »

- Cause probable : contrôle absent. L'application vérifie la valeur demandée (`honore` ou `absent`), mais pas le statut actuel du rendez-vous. Un statut définitif peut donc être écrasé.
- Correction : la valeur demandée est vérifiée d'abord (400 sinon), puis le rendez-vous doit être `confirme` (409 « déjà clôturé » sinon).
- Tests : `test_tma105_un_rendez_vous_annule_ne_peut_pas_devenir_honore` rejoue la trace (n° 292) ; un second test vérifie les valeurs acceptées.
- Impact (requête 3) : 4 rendez-vous dans ce cas.

### TMA-106 : prochain rendez-vous absent de la première page

- Cause probable : tri sur la date affichée `JJ/MM/AAAA`, comme sur du texte. L'ordre renvoyé (28/07, 25/09, 21/04, 19/05, 16/06, 12/02, 11/08, 08/05, 07/07) est exactement l'ordre décroissant du jour du mois. Le rendez-vous du 02/12 tombe donc en fin de liste.
- Seconde anomalie : la page 1 ne contient que 9 rendez-vous alors que `parPage` vaut 10 et `total` vaut 13. Il y a sans doute une erreur d'une unité dans le décalage ou la limite.
- Correction : tri sur l'objet date, le prochain rendez-vous à venir placé en tête, puis découpage `array_slice(($page - 1) * 10, 10)`.
- Tests : `test_tma106_le_prochain_rendez_vous_est_en_tete_puis_du_plus_recent_au_plus_ancien` rejoue la liste des 13 rendez-vous de l'usager 0203040506 et vérifie les 10 dates de la page 1, dans l'ordre ; `test_tma106_la_deuxieme_page_contient_les_trois_plus_anciens` vérifie la page 2.

### TMA-107 : impossible de prendre un 2e rendez-vous pour un autre service (conforme)

- Qualification : comportement conforme. L'usager 0102030405 a déjà un rendez-vous confirmé à venir (n° 800, passeport, 26/11 à 10:00). La règle limite à un seul rendez-vous à venir tous services confondus.
- Réponse au support : l'usager doit attendre que son rendez-vous du 26/11 soit passé, ou l'annuler, avant d'en prendre un pour son permis.
- Tests : `test_tma107_conforme_un_second_rendez_vous_a_venir_est_refuse_meme_pour_un_autre_service` rejoue la trace et vérifie que le refus est maintenu ; `test_tma107_un_nouveau_rendez_vous_est_possible_une_fois_le_premier_cloture` vérifie qu'une fois le premier clôturé, la prise redevient possible.

## Résultats des requêtes

Export chargé dans MariaDB 10.4 (XAMPP). « Maintenant » correspond à l'heure de l'export : vendredi 20/11/2026 à 18:00.

```bash
mysql -u root -e "CREATE DATABASE tma_rdv CHARACTER SET utf8mb4"
mysql -u root --init-command="SET SESSION sql_mode=''" tma_rdv < database/export/base_rendez_vous.sql
mysql -u root --table tma_rdv < requetes.sql
```

Le mode strict est désactivé uniquement pour le chargement : MySQL refuse sinon les colonnes `TIMESTAMP NOT NULL` sans valeur par défaut de l'export.

### 1. Rendez-vous confirmés à venir un samedi ou un dimanche (usagers à prévenir)

| id | NPI | service | date et heure | créé le |
|---|---|---|---|---|
| 680 | 0669147625 | PASSEPORT | sam. 21/11/2026 08:30 (demain) | 02/11 18:45 |
| 730 | 0679617916 | CNI | sam. 28/11/2026 09:00 | 08/11 13:45 |
| 834 | 0610588823 | CNI | sam. 05/12/2026 10:00 | 19/11 07:16 |
| 857 | 0671987446 | PERMIS | sam. 05/12/2026 10:00 | 20/11 12:38 |
| 874 | 0648953073 | PASSEPORT | sam. 12/12/2026 11:00 | 20/11 16:39 |

Ce sont tous des samedis. Deux rendez-vous ont été créés le jour de l'export : l'anomalie est toujours active.

### 2. Créneaux qui comptent plus de 3 rendez-vous non annulés

| créneau | nombre | rendez-vous, dans l'ordre de réservation |
|---|---|---|
| 22/10/2026 10:30 | 4 | 502, 511, 517, 519 |
| 10/11/2026 09:00 | 4 | 632, 644, 681, 697 |
| 24/11/2026 14:00 | 4 | 728, 750, 763, 783 (à venir) |

### 3. Rendez-vous passés du statut « annulé » à un autre statut

| rendez-vous | date | changement | modifié le | par |
|---|---|---|---|---|
| 208 | 07/10 10:00 | annule → honore | 07/10 10:20 | AG-01 |
| 231 | 15/10 10:00 | annule → honore | 15/10 10:26 | AG-02 |
| 262 | 29/10 10:00 | annule → absent | 29/10 10:21 | AG-03 |
| 292 | 12/11 10:00 | annule → honore | 12/11 10:13 | AG-04 |

## Bonus : anomalies non signalées

| | Constat (requête) | Cause probable | Correction dans le module |
|---|---|---|---|
| B1 | NPI de 11 et 12 chiffres enregistrés : n° 459 (`01234567890`) et 642 (`061425364711`) | Longueur minimale seulement, pas de longueur exacte | Expression `^[0-9]{10}$` (voir TMA-103) |
| B2 | Rendez-vous à 16:00 et 16:30 acceptés : n° 490, 698 et 842 (08/12 16:00, confirmé, à prévenir) | Borne de la grille fausse : 15:30 traité comme le début de la dernière heure ouverte, non comme le dernier créneau | Heure entre 8 et 15, minutes à 0 ou 30 |
| TMA-106 | Page 1 à 9 résultats pour `parPage` = 10 | Erreur d'une unité dans la pagination | `array_slice(($page - 1) * 10, 10)` |
| B8 | Plus aucune annulation depuis le 3 octobre | Régression du calcul du délai (voir TMA-104) | Délai en secondes |

Vérifications sans résultat, donc sans anomalie : aucun rendez-vous pris dans le passé (B3), aucun usager avec deux rendez-vous à venir (B4), aucune annulation à moins de 24 h (B5), aucun pointage avant l'heure du rendez-vous (B7).

## Limites

- Le code de l'application n'étant pas fourni, les causes sont des hypothèses déduites des traces et de l'export.
- Le module contrôle les règles mais ne les enregistre pas : l'appelant doit faire le contrôle de capacité et l'insertion dans une même transaction, pour éviter qu'une réservation simultanée crée un 4e rendez-vous.
- Les rendez-vous signalés par les requêtes 1 à 3 et par B2 doivent être corrigés en base, après validation métier, et les usagers concernés prévenus.
