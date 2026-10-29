<?php

namespace App\Domain\RendezVous;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Règles de gestion de la prise de rendez-vous au guichet (section 2 du sujet).
 *
 * Indépendant de toute base de données et de tout framework : les rendez-vous
 * existants et l'heure courante sont passés en paramètre.
 * Chaque refus lève une RefusMetier portant le code HTTP et le message prévus.
 */
final class ReglesRendezVous
{
    public const SERVICES = ['PASSEPORT', 'CNI', 'PERMIS'];
    public const CAPACITE_CRENEAU = 3;
    public const PAR_PAGE = 10;
    private const DELAI_ANNULATION_SECONDES = 24 * 3600;

    /** Heure du Bénin : GMT+1, sans heure d'été. */
    public static function fuseau(): DateTimeZone
    {
        return new DateTimeZone('+01:00');
    }

    /**
     * Contrôle une prise de rendez-vous.
     *
     * @param RendezVous[] $existants tous les rendez-vous connus (au moins ceux de l'usager et du créneau)
     * @return RendezVous le rendez-vous à enregistrer, au statut « confirme »
     */
    public function controlerPrise(
        mixed $npi,
        mixed $service,
        mixed $dateHeure,
        array $existants,
        DateTimeImmutable $maintenant,
    ): RendezVous {
        $this->controlerNpi($npi);

        if (!is_string($service) || !in_array($service, self::SERVICES, true)) {
            throw RefusMetier::serviceInconnu();
        }

        $date = $this->lireDateHeure($dateHeure);
        $maintenant = $maintenant->setTimezone(self::fuseau());

        if ($date < $maintenant) {
            throw RefusMetier::datePassee();
        }

        // 'N' : 1 (lundi) à 7 (dimanche)
        if ((int) $date->format('N') >= 6) {
            throw RefusMetier::weekEnd();
        }

        $heure = (int) $date->format('G');
        $minute = (int) $date->format('i');
        if ($heure < 8 || $heure > 15 || ($minute !== 0 && $minute !== 30)) {
            throw RefusMetier::horsGrille();
        }

        foreach ($existants as $rdv) {
            if ($rdv->npi === $npi && $this->estAVenir($rdv, $maintenant)) {
                throw RefusMetier::dejaUnRendezVous();
            }
        }

        $occupes = 0;
        foreach ($existants as $rdv) {
            if ($rdv->statut !== RendezVous::ANNULE && $rdv->dateHeure == $date) {
                $occupes++;
            }
        }
        if ($occupes >= self::CAPACITE_CRENEAU) {
            throw RefusMetier::creneauComplet();
        }

        return new RendezVous(null, $npi, $service, $date, RendezVous::CONFIRME);
    }

    /** Contrôle l'annulation par l'usager et renvoie le rendez-vous annulé. */
    public function controlerAnnulation(RendezVous $rdv, DateTimeImmutable $maintenant): RendezVous
    {
        if ($rdv->statut !== RendezVous::CONFIRME) {
            throw RefusMetier::nonAnnulable();
        }

        // Annulation possible jusqu'à 24 h avant, 24 h pile comprises
        $secondesRestantes = $rdv->dateHeure->getTimestamp() - $maintenant->getTimestamp();
        if ($secondesRestantes < self::DELAI_ANNULATION_SECONDES) {
            throw RefusMetier::annulationTardive();
        }

        return $rdv->avecStatut(RendezVous::ANNULE);
    }

    /** Contrôle le pointage par un agent (honore ou absent) et renvoie le rendez-vous clôturé. */
    public function controlerChangementStatut(RendezVous $rdv, mixed $nouveauStatut): RendezVous
    {
        if (!in_array($nouveauStatut, [RendezVous::HONORE, RendezVous::ABSENT], true)) {
            throw RefusMetier::statutInvalide();
        }

        // honore, absent et annule sont définitifs
        if ($rdv->statut !== RendezVous::CONFIRME) {
            throw RefusMetier::dejaCloture();
        }

        return $rdv->avecStatut($nouveauStatut);
    }

    /**
     * Liste les rendez-vous d'un usager : le prochain en tête, puis du plus récent
     * au plus ancien, par pages de 10.
     *
     * @param RendezVous[] $rdvs
     * @return array{page: int, parPage: int, total: int, rendezVous: RendezVous[]}
     */
    public function listerPourUsager(array $rdvs, int $page, DateTimeImmutable $maintenant): array
    {
        $page = max(1, $page);

        // Tri sur la date elle-même, jamais sur sa forme affichée (JJ/MM/AAAA)
        usort($rdvs, fn (RendezVous $a, RendezVous $b) => $b->dateHeure <=> $a->dateHeure);

        $prochain = null;
        foreach ($rdvs as $index => $rdv) {
            if ($this->estAVenir($rdv, $maintenant)
                && ($prochain === null || $rdv->dateHeure < $rdvs[$prochain]->dateHeure)) {
                $prochain = $index;
            }
        }
        if ($prochain !== null) {
            $enTete = array_splice($rdvs, $prochain, 1);
            $rdvs = array_merge($enTete, $rdvs);
        }

        return [
            'page' => $page,
            'parPage' => self::PAR_PAGE,
            'total' => count($rdvs),
            'rendezVous' => array_slice($rdvs, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE),
        ];
    }

    private function controlerNpi(mixed $npi): void
    {
        if (!is_string($npi) || preg_match('/^[0-9]{10}$/D', $npi) !== 1) {
            throw RefusMetier::npiInvalide();
        }
    }

    /** Lit « AAAA-MM-JJTHH:MM » à l'heure du Bénin ; refuse aussi les dates inexistantes (31/02…). */
    private function lireDateHeure(mixed $valeur): DateTimeImmutable
    {
        if (!is_string($valeur)) {
            throw RefusMetier::dateIllisible();
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valeur, self::fuseau());
        if ($date === false || $date->format('Y-m-d\TH:i') !== $valeur) {
            throw RefusMetier::dateIllisible();
        }

        return $date;
    }

    private function estAVenir(RendezVous $rdv, DateTimeImmutable $maintenant): bool
    {
        return $rdv->statut === RendezVous::CONFIRME && $rdv->dateHeure >= $maintenant;
    }
}
