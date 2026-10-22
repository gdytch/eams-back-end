<?php

namespace App\Exports;

use App\Models\Event;
use App\Models\Form;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * One workbook per event, one sheet per published form.
 */
class FormAnswersExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(private Event $event) {}

    public function sheets(): array
    {
        $forms = Form::withoutGlobalScopes()->where('event_id', $this->event->id)->published()->orderBy('sort_order')->orderBy('id')->get();

        // A workbook needs at least one sheet.
        if ($forms->isEmpty()) {
            return [new FormAnswersSheetExport(null, 'Forms')];
        }

        $titles = [];

        return $forms->map(function (Form $form) use (&$titles) {
            // Sheet titles are unique, at most 31 characters, and free of []:*?/\.
            $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $form->title) ?? '') ?: 'Form', 0, 28);
            $title = $base;
            for ($n = 2; in_array(mb_strtolower($title), $titles, true); $n++) {
                $title = mb_substr($base, 0, 27)." {$n}";
            }
            $titles[] = mb_strtolower($title);

            return new FormAnswersSheetExport($form, $title);
        })->all();
    }
}
