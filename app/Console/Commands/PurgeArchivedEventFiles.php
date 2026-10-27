<?php

namespace App\Console\Commands;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\FormAnswer;
use App\Models\IssuedCertificate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('app:purge-archived-event-files')]
#[Description('Delete form upload files, generated ID cards and Issued certificate files of events archived long enough ago')]
class PurgeArchivedEventFiles extends Command
{
    private const CHUNK = 200;

    public function handle(): int
    {
        $answers = 0;
        $cards = 0;
        $certificates = 0;
        $cutoff = now()->subDays(Event::FILE_RETENTION_DAYS);

        Event::withoutGlobalScopes()->where('archived_at', '<=', $cutoff)->pluck('id')->each(function (int $eventId) use (&$answers, &$cards, &$certificates, $cutoff) {
            $answers += $this->purgeInChunks(
                $eventId,
                $cutoff,
                FormAnswer::query()->whereNotNull('file_path')
                    ->whereHas('submission.attendee', fn ($q) => $q->withoutGlobalScopes()->where('event_id', $eventId)),
                function (FormAnswer $answer) {
                    Storage::disk('local')->delete($answer->file_path);
                    // The row keeps its file name, and still counts as answered through file_deleted_at.
                    $answer->forceFill(['file_path' => null, 'file_deleted_at' => now()])->save();
                },
            );

            $cards += $this->purgeInChunks(
                $eventId,
                $cutoff,
                Attendee::withoutGlobalScopes()->where('event_id', $eventId)
                    ->where(fn ($q) => $q->whereNotNull('id_card_path')->orWhereNotNull('id_card_images_path')),
                function (Attendee $attendee) {
                    Storage::disk('local')->delete(array_filter([$attendee->id_card_path, ...($attendee->id_card_images_path ?? [])]));
                    $attendee->forceFill(['id_card_path' => null, 'id_card_images_path' => null, 'id_card_generated_at' => null])->saveQuietly();
                },
            );

            // The Certificate, its Template and font stay, so Regenerate can rebuild the files.
            $certificates += $this->purgeInChunks(
                $eventId,
                $cutoff,
                IssuedCertificate::query()->whereNotNull('file_path')
                    ->whereHas('certificate', fn ($q) => $q->withoutGlobalScopes()->where('event_id', $eventId)),
                function (IssuedCertificate $issued) {
                    Storage::disk('local')->delete($issued->file_path);
                    $issued->forceFill(['file_path' => null, 'file_deleted_at' => now()])->saveQuietly();
                },
            );
        });

        $this->info("Deleted {$answers} form files and {$cards} ID cards.");
        $this->info("Deleted {$certificates} issued certificate files.");

        return self::SUCCESS;
    }

    /**
     * Runs `$purge` on every row of the query, a chunk per transaction. Each chunk re-checks the event under lock,
     * so an unarchive since the select keeps its files. Rows leave the query once purged, hence chunkById.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): void  $purge
     */
    private function purgeInChunks(int $eventId, \DateTimeInterface $cutoff, Builder $query, callable $purge): int
    {
        $count = 0;

        $query->chunkById(self::CHUNK, function (Collection $rows) use ($eventId, $cutoff, $purge, &$count) {
            return DB::transaction(function () use ($rows, $eventId, $cutoff, $purge, &$count) {
                $due = Event::withoutGlobalScopes()->whereKey($eventId)->where('archived_at', '<=', $cutoff)->lockForUpdate()->exists();
                if (! $due) {
                    return false;
                }
                $rows->each($purge);
                $count += $rows->count();

                return true;
            });
        });

        return $count;
    }
}
