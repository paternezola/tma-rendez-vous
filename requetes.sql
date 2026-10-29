-- Requêtes d'impact sur l'export base_rendez_vous.sql
-- Export réalisé le vendredi 20/11/2026 à 18:00 (heure du Bénin) : c'est le « maintenant » ci-dessous.
-- Écrites pour MySQL / MariaDB (XAMPP). Équivalent SQLite du jour de la semaine indiqué en commentaire.
--
-- Chargement :
--   mysql -u root -e "CREATE DATABASE tma_rdv CHARACTER SET utf8mb4"
--   mysql -u root --init-command="SET SESSION sql_mode=''" tma_rdv < database/export/base_rendez_vous.sql
--   mysql -u root tma_rdv < requetes.sql
--
-- Résultats sur l'export (MariaDB 10.4, XAMPP) :
--   1. 5 rendez-vous à prévenir, tous un samedi (aucun dimanche dans toute la base) :
--      680 (21/11 08:30, demain !), 730 (28/11), 834 et 857 (05/12), 874 (12/12).
--      857 et 874 ont été créés le 20/11 : l'anomalie est toujours active.
--   2. 3 créneaux à 4 rendez-vous : 22/10 10:30 (502,511,517,519),
--      10/11 09:00 (632,644,681,697), 24/11 14:00 (728,750,763,783, à venir).
--      À chaque fois, c'est le 4e réservé qui est en trop : borne « > 3 » au lieu de « >= 3 ».
--   3. 4 rendez-vous passés de « annule » à un autre statut : 208, 231, 292 -> honore ; 262 -> absent.
--   B1. 2 NPI invalides : 459 (11 chiffres), 642 (12 chiffres).
--   B2. 3 rendez-vous hors grille : 490 (16:00), 698 (16:30), 842 (08/12 16:00, confirmé, à prévenir).
--   B3, B4, B5, B7 : aucun résultat.
--   B6. Les 4 mêmes lignes que la requête 3.
--   B8. 35 annulations en août-septembre, 2 le 1er-3 octobre, puis plus aucune : cohérent avec TMA-104.


-- =====================================================================
-- 1. TMA-101 : rendez-vous confirmés à venir pris un samedi ou un dimanche
--    (usagers à prévenir). DAYOFWEEK : 1 = dimanche, 7 = samedi.
--    SQLite : strftime('%w', date_heure) IN ('0', '6')
-- =====================================================================
SELECT r.id, r.npi, r.service, r.date_heure,
       CASE DAYOFWEEK(r.date_heure) WHEN 1 THEN 'dimanche' ELSE 'samedi' END AS jour,
       r.cree_le
FROM rendez_vous r
WHERE r.statut = 'confirme'
  AND r.date_heure > '2026-11-20 18:00:00'
  AND DAYOFWEEK(r.date_heure) IN (1, 7)
ORDER BY r.date_heure;


-- =====================================================================
-- 2. TMA-102 : créneaux qui comptent plus de 3 rendez-vous non annulés
-- =====================================================================
SELECT r.date_heure AS creneau,
       COUNT(*) AS nb_non_annules,
       GROUP_CONCAT(r.id ORDER BY r.cree_le) AS ids_par_ordre_de_reservation
FROM rendez_vous r
WHERE r.statut <> 'annule'
GROUP BY r.date_heure
HAVING COUNT(*) > 3
ORDER BY r.date_heure;


-- =====================================================================
-- 3. TMA-105 : rendez-vous passés du statut « annulé » à un autre statut
-- =====================================================================
SELECT h.rendez_vous_id, r.npi, r.date_heure,
       h.ancien_statut, h.nouveau_statut, h.modifie_le, h.modifie_par,
       r.statut AS statut_actuel
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut <> 'annule'
ORDER BY h.modifie_le;


-- =====================================================================
-- Bonus : anomalies que personne n'a signalées
-- =====================================================================

-- B1. NPI qui ne font pas exactement 10 chiffres
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE npi NOT REGEXP '^[0-9]{10}$';

-- B2. Rendez-vous hors grille (avant 08:00, après 15:30, ou pas sur :00 / :30)
SELECT id, npi, date_heure, statut
FROM rendez_vous
WHERE HOUR(date_heure) < 8
   OR HOUR(date_heure) > 15
   OR MINUTE(date_heure) NOT IN (0, 30)
   OR SECOND(date_heure) <> 0
ORDER BY date_heure;

-- B3. Rendez-vous pris dans le passé (date du rendez-vous antérieure à sa création)
SELECT id, npi, date_heure, cree_le, statut
FROM rendez_vous
WHERE date_heure < cree_le
ORDER BY date_heure;

-- B4. Usagers ayant plus d'un rendez-vous confirmé à venir
SELECT npi, COUNT(*) AS nb_a_venir, GROUP_CONCAT(id ORDER BY date_heure) AS ids
FROM rendez_vous
WHERE statut = 'confirme' AND date_heure > '2026-11-20 18:00:00'
GROUP BY npi
HAVING COUNT(*) > 1;

-- B5. Annulations par l'usager moins de 24 h avant le rendez-vous
SELECT h.rendez_vous_id, r.date_heure, h.modifie_le,
       TIMESTAMPDIFF(MINUTE, h.modifie_le, r.date_heure) / 60 AS heures_avant
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.nouveau_statut = 'annule'
  AND TIMESTAMPDIFF(MINUTE, h.modifie_le, r.date_heure) < 24 * 60
ORDER BY h.modifie_le;

-- B6. Changements de statut partant d'un statut définitif (honore, absent, annule)
SELECT h.id, h.rendez_vous_id, h.ancien_statut, h.nouveau_statut, h.modifie_le, h.modifie_par
FROM historique_statuts h
WHERE h.ancien_statut <> 'confirme'
ORDER BY h.modifie_le;

-- B7. Pointage « honore » ou « absent » enregistré avant l'heure du rendez-vous
SELECT h.rendez_vous_id, r.date_heure, h.nouveau_statut, h.modifie_le, h.modifie_par
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.nouveau_statut IN ('honore', 'absent')
  AND h.modifie_le < r.date_heure
ORDER BY h.modifie_le;

-- B8. Annulations par mois (TMA-104 : depuis quand l'annulation est-elle bloquée ?)
SELECT DATE_FORMAT(modifie_le, '%Y-%m') AS mois, COUNT(*) AS annulations, MAX(modifie_le) AS derniere
FROM historique_statuts
WHERE nouveau_statut = 'annule'
GROUP BY mois
ORDER BY mois;
