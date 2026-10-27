<?php

namespace App\Http\Resources;

use App\Enums\IssuedCertificateStatus;
use App\Models\IssuedCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A Certificate's generation run: counts plus who failed and whose name did not fit. Expects visible `issuedCertificates` with `attendee`. */
class CertificateRunStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $identity = fn (IssuedCertificate $row) => [
            'attendee_id' => $row->attendee_id,
            'full_name' => $row->attendee?->full_name,
            'email_address' => $row->attendee?->email_address,
        ];

        return [
            ...$this->generationCounts(),
            'failures' => $this->issuedCertificates->where('status', IssuedCertificateStatus::Failed)
                ->map(fn (IssuedCertificate $row) => [...$identity($row), 'reason' => $row->failure_reason])->values(),
            'name_too_long_attendees' => $this->issuedCertificates->where('name_too_long', true)
                ->map($identity)->values(),
        ];
    }
}
