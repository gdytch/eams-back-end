<?php

namespace App\Services;

/** A rendered Issued certificate: the PDF and whether the name stayed too wide at the minimum size. */
final readonly class RenderedCertificate
{
    public function __construct(
        public string $pdf,
        public bool $nameTooLong,
    ) {}
}
