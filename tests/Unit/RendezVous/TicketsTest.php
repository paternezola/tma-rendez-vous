<?php

namespace Tests\Unit\RendezVous;

use App\Domain\RendezVous\RefusMetier;
use App\Domain\RendezVous\RendezVous;
use App\Domain\RendezVous\ReglesRendezVous;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Un ou plusieurs tests par ticket : chacun rejoue la trace du support
 * (données tirées de base_rendez_vous.sql) et échouerait avec le comportement signalé.
 */
class TicketsTest extends TestCase
{
    private ReglesRendezVous $regles;

    protected function setUp(): void
    {
        $this->regles = new ReglesRendezVous();
    }

    /* ---------- TMA-101 : rendez-vous accepté un samedi (anomalie) ---------- */

    public function test_tma101_un_rendez_vous_le_samedi_est_refuse(): void
    {
        $this->assertRefus(409, 'Le centre est fermé le week-end.', fn () => $this->regles->controlerPrise(
            '0614253647', 'PASSEPORT', '2026-11-14T10:00', [], $this->heure('2026-11-09 23:59'),
        ));
    }

    public function test_tma101_un_rendez_vous_le_dimanche_est_refuse(): void
    {
        $this->assertRefus(409, 'Le centre est fermé le week-end.', fn () => $this->regles->controlerPrise(
            '0614253647', 'PASSEPORT', '2026-11-15T10:00', [], $this->heure('2026-11-09 23:59'),
        ));
    }

    /* ---------- TMA-102 : 4 usagers sur un créneau de 3 (anomalie) ---------- */

    public function test_tma102_un_quatrieme_rendez_vous_sur_le_creneau_est_refuse(): void
    {
        $existants = [
            $this->rdv(632, '0638958971', 'PASSEPORT', '2026-11-10 09:00'),
            $this->rdv(644, '0671968463', 'CNI', '2026-11-10 09:00'),
            $this->rdv(681, '0609394161', 'PERMIS', '2026-11-10 09:00'),
        ];

        $this->assertRefus(409, 'Ce créneau est complet.', fn () => $this->regles->controlerPrise(
            '0620214467', 'CNI', '2026-11-10T09:00', $existants, $this->heure('2026-11-04 23:43'),
        ));
    }

    public function test_tma102_un_rendez_vous_annule_libere_sa_place(): void
    {
        $existants = [
            $this->rdv(632, '0638958971', 'PASSEPORT', '2026-11-10 09:00'),
            $this->rdv(644, '0671968463', 'CNI', '2026-11-10 09:00'),
            $this->rdv(681, '0609394161', 'PERMIS', '2026-11-10 09:00', RendezVous::ANNULE),
        ];

        $rdv = $this->regles->controlerPrise(
            '0620214467', 'CNI', '2026-11-10T09:00', $existants, $this->heure('2026-11-04 23:43'),
        );

        $this->assertSame(RendezVous::CONFIRME, $rdv->statut);
    }

    /* ---------- TMA-103 : lettre dans le NPI -> erreur 500 (anomalie) ---------- */

    public function test_tma103_un_npi_contenant_une_lettre_est_refuse_avec_le_message_prevu(): void
    {
        $this->assertRefus(400, 'Le NPI doit comporter exactement 10 chiffres.', fn () => $this->regles->controlerPrise(
            '06142A3647', 'CNI', '2026-11-23T09:00', [], $this->heure('2026-11-20 18:00'),
        ));
    }

    public function test_tma103_les_npi_mal_formes_sont_tous_refuses_sans_erreur_interne(): void
    {
        foreach (['061425364', '06142536470', '', ' 0614253647', "0614253647\n", null, 614253647] as $npi) {
            $this->assertRefus(400, 'Le NPI doit comporter exactement 10 chiffres.', fn () => $this->regles->controlerPrise(
                $npi, 'CNI', '2026-11-23T09:00', [], $this->heure('2026-11-20 18:00'),
            ));
        }
    }

    /* ---------- TMA-104 : annulation refusée 40 h avant (anomalie) ---------- */

    public function test_tma104_annuler_40_heures_avant_est_accepte(): void
    {
        $rdv = $this->rdv(709, '0339205992', 'PERMIS', '2026-11-19 10:00');

        $annule = $this->regles->controlerAnnulation($rdv, $this->heure('2026-11-17 18:00'));

        $this->assertSame(RendezVous::ANNULE, $annule->statut);
    }

    public function test_tma104_la_borne_des_24_heures(): void
    {
        $rdv = $this->rdv(709, '0339205992', 'PERMIS', '2026-11-19 10:00');

        $this->assertSame(
            RendezVous::ANNULE,
            $this->regles->controlerAnnulation($rdv, $this->heure('2026-11-18 10:00'))->statut,
        );
        $this->assertRefus(409, 'Annulation impossible moins de 24 h avant le rendez-vous.',
            fn () => $this->regles->controlerAnnulation($rdv, $this->heure('2026-11-18 10:01')));
    }

    /* ---------- TMA-105 : un rendez-vous annulé passé à honoré (anomalie) ---------- */

    public function test_tma105_un_rendez_vous_annule_ne_peut_pas_devenir_honore(): void
    {
        $rdv = $this->rdv(292, '0330677555', 'PASSEPORT', '2026-11-12 10:00', RendezVous::ANNULE);

        $this->assertRefus(409, 'Ce rendez-vous est déjà clôturé.',
            fn () => $this->regles->controlerChangementStatut($rdv, 'honore'));
    }

    public function test_tma105_seuls_honore_et_absent_sont_acceptes(): void
    {
        $rdv = $this->rdv(292, '0330677555', 'PASSEPORT', '2026-11-12 10:00');

        $this->assertRefus(400, 'Statut invalide : honore ou absent.',
            fn () => $this->regles->controlerChangementStatut($rdv, 'annule'));
        $this->assertSame(RendezVous::HONORE, $this->regles->controlerChangementStatut($rdv, 'honore')->statut);
    }

    /* ---------- TMA-106 : prochain rendez-vous absent de la première page (anomalie) ---------- */

    public function test_tma106_le_prochain_rendez_vous_est_en_tete_puis_du_plus_recent_au_plus_ancien(): void
    {
        $resultat = $this->regles->listerPourUsager($this->rendezVousUsager0203040506(), 1, $this->heure('2026-11-20 18:00'));

        $this->assertSame(13, $resultat['total']);
        $this->assertCount(10, $resultat['rendezVous']);
        $this->assertSame([
            '2026-12-02 08:30', '2026-09-25 11:00', '2026-08-11 14:00', '2026-07-28 09:30', '2026-07-07 10:30',
            '2026-06-16 13:30', '2026-06-02 08:00', '2026-05-19 15:00', '2026-05-08 11:30', '2026-04-21 09:00',
        ], $this->dates($resultat['rendezVous']));
    }

    public function test_tma106_la_deuxieme_page_contient_les_trois_plus_anciens(): void
    {
        $resultat = $this->regles->listerPourUsager($this->rendezVousUsager0203040506(), 2, $this->heure('2026-11-20 18:00'));

        $this->assertSame(['2026-03-03 14:30', '2026-02-12 10:00', '2026-01-05 08:30'], $this->dates($resultat['rendezVous']));
    }

    /* ---------- TMA-107 : un seul rendez-vous à venir, tous services confondus (CONFORME) ---------- */

    public function test_tma107_conforme_un_second_rendez_vous_a_venir_est_refuse_meme_pour_un_autre_service(): void
    {
        $existants = [$this->rdv(800, '0102030405', 'PASSEPORT', '2026-11-26 10:00')];

        $this->assertRefus(409, 'Vous avez déjà un rendez-vous à venir.', fn () => $this->regles->controlerPrise(
            '0102030405', 'PERMIS', '2026-11-27T09:00', $existants, $this->heure('2026-11-20 18:00'),
        ));
    }

    public function test_tma107_un_nouveau_rendez_vous_est_possible_une_fois_le_premier_cloture(): void
    {
        $existants = [$this->rdv(800, '0102030405', 'PASSEPORT', '2026-11-26 10:00', RendezVous::HONORE)];

        $rdv = $this->regles->controlerPrise(
            '0102030405', 'PERMIS', '2026-11-27T09:00', $existants, $this->heure('2026-11-26 12:00'),
        );

        $this->assertSame('PERMIS', $rdv->service);
    }

    /* ---------- Outils ---------- */

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

    private function heure(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, ReglesRendezVous::fuseau());
    }

    private function rdv(int $id, string $npi, string $service, string $date, string $statut = RendezVous::CONFIRME): RendezVous
    {
        return new RendezVous($id, $npi, $service, $this->heure($date), $statut);
    }

    /** Les 13 rendez-vous de l'usager 0203040506 dans l'export, volontairement mélangés. */
    private function rendezVousUsager0203040506(): array
    {
        return [
            $this->rdv(10, '0203040506', 'PASSEPORT', '2026-07-28 09:30', RendezVous::ABSENT),
            $this->rdv(826, '0203040506', 'PASSEPORT', '2026-12-02 08:30'),
            $this->rdv(1, '0203040506', 'PASSEPORT', '2026-01-05 08:30', RendezVous::HONORE),
            $this->rdv(259, '0203040506', 'PERMIS', '2026-09-25 11:00', RendezVous::HONORE),
            $this->rdv(4, '0203040506', 'PASSEPORT', '2026-04-21 09:00', RendezVous::HONORE),
            $this->rdv(6, '0203040506', 'PERMIS', '2026-05-19 15:00', RendezVous::ABSENT),
            $this->rdv(8, '0203040506', 'CNI', '2026-06-16 13:30', RendezVous::HONORE),
            $this->rdv(2, '0203040506', 'CNI', '2026-02-12 10:00', RendezVous::HONORE),
            $this->rdv(11, '0203040506', 'CNI', '2026-08-11 14:00', RendezVous::HONORE),
            $this->rdv(5, '0203040506', 'CNI', '2026-05-08 11:30', RendezVous::HONORE),
            $this->rdv(9, '0203040506', 'PERMIS', '2026-07-07 10:30', RendezVous::HONORE),
            $this->rdv(3, '0203040506', 'PERMIS', '2026-03-03 14:30', RendezVous::ABSENT),
            $this->rdv(7, '0203040506', 'PASSEPORT', '2026-06-02 08:00', RendezVous::HONORE),
        ];
    }

    private function dates(array $rdvs): array
    {
        return array_map(fn (RendezVous $rdv) => $rdv->dateHeure->format('Y-m-d H:i'), $rdvs);
    }
}
