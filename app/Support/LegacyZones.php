<?php

namespace App\Support;

use RuntimeException;

/**
 * Translates the old system's zone names into undilot's.
 *
 * WHY THIS HAS TO EXIST, and why getting it wrong is silent. A state admin sees
 * a company only when `companies.state` equals `admins.state` EXACTLY, as
 * strings — AdminZones says so in its own docblock, and that makes a zone name
 * an access-control value rather than a label.
 *
 * The dump carries 18 distinct zone names and 7 of them are not undilot zones.
 * Importing them raw leaves the database holding two vocabularies at once:
 * scoping still works between imported rows, because they are wrong in the same
 * way, but every zone dropdown in the office is built from AdminZones — so a
 * company on 'P.Penang' opens its edit form with NO option selected, and the
 * next save moves it to whichever zone happens to be first in the list. The
 * company changes hands with no error, no confirmation and no audit of intent.
 * That was measured on a real imported row, not imagined.
 *
 * THE OFFICE WAS ALREADY INCONSISTENT WITH ITSELF, which is the other half of
 * the argument: the dump holds BOTH 'N.Sembilan' and 'Negeri Sembilan', and
 * BOTH 'P.Penang' and 'Pulau Pinang'. There is no raw value to be faithful to.
 *
 * TARGETS ARE RESOLVED AGAINST THE LIVE LIST, never a copy of it. Zones are a
 * ManagedList the office can rename, so a hardcoded target would rot into the
 * exact bug this class exists to prevent. An unknown target throws.
 */
class LegacyZones
{
    /**
     * Old name => undilot zone.
     *
     * Two legacy zones collapse into one here: the old system ran 'Selangor'
     * and 'Kuala Lumpur' as separate offices, and undilot's zone covers
     * Selangor, Kuala Lumpur and Putrajaya together. Likewise Langkawi, which
     * the dump writes two different ways, both of them hanging off Kedah.
     */
    private const MAP = [
        'Selangor' => 'Selangor, Kuala Lumpur dan Putrajaya',
        'Kuala Lumpur' => 'Selangor, Kuala Lumpur dan Putrajaya',
        'N.Sembilan' => 'Negeri Sembilan',
        'P.Penang' => 'Pulau Pinang',
        'Sabah' => 'Sabah dan Labuan',
        'Kedah(Langkawi)' => 'Langkawi',
        'P.Langkawi (Kedah)' => 'Langkawi',
    ];

    /**
     * The zone an imported row belongs in, or null when the dump gave none.
     *
     * Throws rather than guesses on a name it does not recognise. A zone that
     * silently falls through lands a company somewhere no admin is looking,
     * which is the failure this whole class is about.
     */
    public static function translate(?string $legacy): ?string
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $legacy));

        if ($name === '') {
            return null;
        }

        $target = self::MAP[$name] ?? $name;

        if (! AdminZones::has($target)) {
            throw new RuntimeException(
                "Legacy zone '{$name}' maps to '{$target}', which is not a zone in this installation. "
                .'Either the office renamed the zone — in which case update LegacyZones::MAP — or the '
                .'dump holds a zone nobody has set up yet.'
            );
        }

        return $target;
    }

    /**
     * Every legacy name this class knows how to move, for a report that wants
     * to say what it changed rather than only that it changed something.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return self::MAP;
    }
}
