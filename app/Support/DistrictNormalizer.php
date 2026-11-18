<?php

namespace App\Support;

final class DistrictNormalizer
{
    /**
     * @var array<string, string>
     */
    private const ALIASES = [
        'downtown dubai' => 'Downtown',
        'burj khalifa' => 'Downtown',
        'al jaddaf' => 'Al Jadaf',
        'beach front' => 'Beachfront',
        'discovery garden' => 'Discovery Gardens',
        'dubai creek harbour' => 'Creek Harbour',
        'creek' => 'Creek Harbour',
        'jvc' => 'Jumeirah Village Circle',
        'jumeirah lakes towers' => 'JLT',
        'meydan city' => 'Meydan',
        'shobha' => 'Sobha',
        'the palm' => 'Palm Jumeirah',
        'the palm jumeirah' => 'Palm Jumeirah',
        'prodaction city' => 'Production City',
        'dubai international financial center difc' => 'DIFC',
    ];

    public static function normalize(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === false) {
            return null;
        }

        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }

        return self::ALIASES[mb_strtolower($name)] ?? $name;
    }
}
