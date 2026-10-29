<?php

namespace Tests\Unit\RendezVous;

use App\Domain\RendezVous\RefusMetier;
use App\Domain\RendezVous\RendezVous;
use App\Domain\RendezVous\ReglesRendezVous;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Les règles de la section 2 qui ne font l'objet d'aucun ticket :
 * chaque cas refusé du tableau renvoie son code et son message.
 */
class ReglesTest extends TestCase
{
    private ReglesRendezVous $regles;
    private DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        $this->regles = new ReglesRendezVous();
        $this->maintenant = new DateTimeImmutable('2026-11-20 18:00', ReglesRendezVous::fuseau());
    }

    public function test_une_prise_valide_est_acceptee(): void
    {
        $rdv = $this->regles->controlerPrise('0614253647', 'CNI', '2026-11-23T09:00', [], $this->maintenant);

        $this->assertSame(RendezVous::CONFIRME, $rdv->statut);
        $this->assertSame('2026-11-23 09:00', $rdv->dateHeure->format('Y-m-d H:i'));
    }

    public function test_un_service_inconnu_est_refuse(): void
    {
        foreach (['VISA', 'cni', '', null] as $service) {
            $this->assertRefus(400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.',
                fn () => $this->prendre(service: $service));
        }
    }

    public function test_une_date_illisible_est_refusee(): void
    {
        foreach (['23/11/2026 09:00', '2026-11-23 09:00', '2026-11-23T09:00:00', '2026-02-31T09:00', '2026-11-23T25:00', 'demain', '', null] as $date) {
            $this->assertRefus(400, 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).',
                fn () => $this->prendre(date: $date));
        }
    }

    public function test_une_date_passee_est_refusee(): void
    {
        $this->assertRefus(409, 'Impossible de prendre un rendez-vous dans le passé.',
            fn () => $this->prendre(date: '2026-11-20T15:30'));
    }

    public function test_la_grille_horaire_va_de_08h00_a_15h30_toutes_les_30_minutes(): void
    {
        foreach (['2026-11-23T08:00', '2026-11-23T12:30', '2026-11-23T15:30'] as $date) {
            $this->assertSame(RendezVous::CONFIRME, $this->prendre(date: $date)->statut);
        }
        foreach (['2026-11-23T07:30', '2026-11-23T16:00', '2026-11-23T16:30', '2026-11-23T09:15'] as $date) {
            $this->assertRefus(409, 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.',
                fn () => $this->prendre(date: $date));
        }
    }

    public function test_un_rendez_vous_deja_cloture_ne_peut_pas_etre_annule(): void
    {
        foreach ([RendezVous::HONORE, RendezVous::ABSENT, RendezVous::ANNULE] as $statut) {
            $this->assertRefus(409, 'Ce rendez-vous ne peut plus être annulé.',
                fn () => $this->regles->controlerAnnulation($this->rdv('2026-11-30 10:00', $statut), $this->maintenant));
        }
    }

    public function test_un_rendez_vous_absent_ou_honore_ne_peut_plus_changer_de_statut(): void
    {
        $this->assertRefus(409, 'Ce rendez-vous est déjà clôturé.',
            fn () => $this->regles->controlerChangementStatut($this->rdv('2026-11-19 10:00', RendezVous::ABSENT), 'honore'));
        $this->assertRefus(409, 'Ce rendez-vous est déjà clôturé.',
            fn () => $this->regles->controlerChangementStatut($this->rdv('2026-11-19 10:00', RendezVous::HONORE), 'absent'));
    }

    public function test_une_page_inferieure_a_1_renvoie_la_premiere_page(): void
    {
        $resultat = $this->regles->listerPourUsager([$this->rdv('2026-11-19 10:00', RendezVous::HONORE)], 0, $this->maintenant);

        $this->assertSame(1, $resultat['page']);
        $this->assertCount(1, $resultat['rendezVous']);
    }

    private function prendre(mixed $service = 'CNI', mixed $date = '2026-11-23T09:00'): RendezVous
    {
        return $this->regles->controlerPrise('0614253647', $service, $date, [], $this->maintenant);
    }

    private function rdv(string $date, string $statut): RendezVous
    {
        return new RendezVous(1, '0614253647', 'CNI', new DateTimeImmutable($date, ReglesRendezVous::fuseau()), $statut);
    }

    private function assertRefus(int $code, string $message, callable $action): void
    {
        try {
            $action();
        } catch (RefusMetier $refus) {
            $this->assertSame($code, $refus->codeHttp);
            $this->assertSame($message, $refus->getMessage());

            return;
        }
        $this->fail("Refus attendu : $message");
    }
}
