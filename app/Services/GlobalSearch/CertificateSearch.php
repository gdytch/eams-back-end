<?php

namespace App\Services\GlobalSearch;

use App\Models\Certificate;
use App\Models\IssuedCertificate;
use App\Models\User;
use App\Services\AttendeeCertificates;
use Illuminate\Database\Eloquent\Builder;

/** Global search over Certificates and, for an Attendee, their own Issued certificates. */
class CertificateSearch
{
    use BuildsSearchItems;

    /**
     * An Attendee's own Issued certificates, exactly those My Certificates lists (one batched service call across the
     * accessible events), filtered by Certificate title in memory.
     */
    /** @return array{total: int, items: array<int, array<string, mixed>>} */
    public function issued(User $user, Builder $events, string $term, int $page, int $perPage): array
    {
        $eventsById = (clone $events)->get(['events.id', 'events.name'])->keyBy('id');
        $needle = mb_strtolower($term);
        $rows = app(AttendeeCertificates::class)->availableForEvents($user, $eventsById->keys()->all())
            ->filter(fn (IssuedCertificate $issued) => str_contains(mb_strtolower((string) $issued->certificate->title), $needle))
            ->values();

        $items = $rows->forPage($page, $perPage)->map(fn (IssuedCertificate $issued) => $this->item(
            'issued_certificate', $issued->id, $issued->certificate->title, null, $eventsById[$issued->certificate->event_id]->name, $issued->certificate->event_id,
            ['certificate_id' => $issued->certificate_id],
        ))->values()->all();

        return ['total' => $rows->count(), 'items' => $items];
    }

    public function subtitle(Certificate $certificate): string
    {
        if (! $certificate->isPublished()) {
            return 'Draft';
        }

        return $certificate->release_at ? 'Published · releases '.$certificate->release_at->format('Y-m-d H:i') : 'Published';
    }
}
