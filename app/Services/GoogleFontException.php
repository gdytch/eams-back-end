<?php

namespace App\Services;

use RuntimeException;

/** A Google Fonts lookup or download failed; the message is safe to show to the admin. */
class GoogleFontException extends RuntimeException {}
