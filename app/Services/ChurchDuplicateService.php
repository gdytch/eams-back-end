<?php

namespace App\Services;

use App\Models\Church;
use Illuminate\Support\Collection;

/**
 * A church is identified by normalized name plus normalized address within one mission.
 */
class ChurchDuplicateService
{
    public static function normalize(?string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $value)));
    }

    /**
     * Churches of the mission that clash with the given name and address.
     *
     * @return array{exact: ?Church, same_name: Collection<int, Church>, similar: Collection<int, Church>}
     */
    public function check(int $missionId, string $name, ?string $address, ?int $ignoreId = null): array
    {
        $name = self::normalize($name);
        $address = self::normalize($address);

        $others = Church::withoutGlobalScopes()
            ->where('mission_id', $missionId)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();

        $sameName = $others->filter(fn (Church $church) => self::normalize($church->name) === $name);
        $exact = $sameName->first(fn (Church $church) => self::normalize($church->address) === $address);

        $similar = $others->reject(fn (Church $church) => self::normalize($church->name) === $name)
            ->filter(function (Church $church) use ($name) {
                $other = self::normalize($church->name);

                return str_contains($other, $name) || str_contains($name, $other)
                    || levenshtein(mb_substr($other, 0, 255), mb_substr($name, 0, 255)) <= 2;
            });

        return [
            'exact' => $exact,
            'same_name' => $sameName->reject(fn (Church $church) => $church->is($exact))->values(),
            'similar' => $similar->values(),
        ];
    }
}
