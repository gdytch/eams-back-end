<?php

namespace App\Enums;

enum FormFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Number = 'number';
    case File = 'file';

    public function hasOptions(): bool
    {
        return $this === self::SingleChoice || $this === self::MultipleChoice;
    }
}
