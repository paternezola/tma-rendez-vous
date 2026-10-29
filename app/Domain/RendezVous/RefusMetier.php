<?php

namespace App\Domain\RendezVous;

use DomainException;

/**
 * Refus d'une saisie invalide ou d'une action interdite (section 2 du sujet).
 * Porte le code HTTP et le message exact à renvoyer à l'usager.
 */
final class RefusMetier extends DomainException
{
    public function __construct(public readonly int $codeHttp, string $message)
    {
        parent::__construct($message);
    }

    public static function npiInvalide(): self
    {
        return new self(400, 'Le NPI doit comporter exactement 10 chiffres.');
    }

    public static function serviceInconnu(): self
    {
        return new self(400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.');
    }

    public static function dateIllisible(): self
    {
        return new self(400, 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).');
    }

    public static function datePassee(): self
    {
        return new self(409, 'Impossible de prendre un rendez-vous dans le passé.');
    }

    public static function weekEnd(): self
    {
        return new self(409, 'Le centre est fermé le week-end.');
    }

    public static function horsGrille(): self
    {
        return new self(409, 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.');
    }

    public static function dejaUnRendezVous(): self
    {
        return new self(409, 'Vous avez déjà un rendez-vous à venir.');
    }

    public static function creneauComplet(): self
    {
        return new self(409, 'Ce créneau est complet.');
    }

    public static function nonAnnulable(): self
    {
        return new self(409, 'Ce rendez-vous ne peut plus être annulé.');
    }

    public static function annulationTardive(): self
    {
        return new self(409, 'Annulation impossible moins de 24 h avant le rendez-vous.');
    }

    public static function statutInvalide(): self
    {
        return new self(400, 'Statut invalide : honore ou absent.');
    }

    public static function dejaCloture(): self
    {
        return new self(409, 'Ce rendez-vous est déjà clôturé.');
    }
}
