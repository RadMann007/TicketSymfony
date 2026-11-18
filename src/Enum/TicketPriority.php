<?php

namespace App\Enum;

/**
 * Priorité d'un ticket, stockée en base sous forme de chaîne courte.
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Basse',
            self::Normal => 'Normale',
            self::High => 'Haute',
        };
    }

    /**
     * Variante de badge shadcn associée à la priorité.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Low => 'secondary',
            self::Normal => 'outline',
            self::High => 'destructive',
        };
    }

    /**
     * Poids utilisé pour trier les priorités de la plus haute à la plus basse.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 2,
            self::High => 3,
        };
    }
}
