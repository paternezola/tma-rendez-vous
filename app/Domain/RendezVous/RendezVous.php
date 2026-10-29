<?php

namespace App\Domain\RendezVous;

use DateTimeImmutable;

final class RendezVous
{
    public const CONFIRME = 'confirme';
    public const HONORE = 'honore';
    public const ABSENT = 'absent';
    public const ANNULE = 'annule';

    public function __construct(
        public readonly ?int $id,
        public readonly string $npi,
        public readonly string $service,
        public readonly DateTimeImmutable $dateHeure,
        public readonly string $statut = self::CONFIRME,
    ) {
    }

    /** Fabrique depuis une ligne de la base (date_heure « AAAA-MM-JJ HH:MM:SS », heure du Bénin). */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            (int) $ligne['id'],
            (string) $ligne['npi'],
            (string) $ligne['service'],
            new DateTimeImmutable($ligne['date_heure'], ReglesRendezVous::fuseau()),
            (string) $ligne['statut'],
        );
    }

    public function avecStatut(string $statut): self
    {
        return new self($this->id, $this->npi, $this->service, $this->dateHeure, $statut);
    }
}
