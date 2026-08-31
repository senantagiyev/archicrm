<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111111; font-size: 12px; }
        h1 { font-size: 20px; border-bottom: 4px solid #fdfe00; padding-bottom: 8px; }
        h2 { font-size: 14px; margin-top: 22px; background: #111111; color: #ffffff; padding: 6px 10px; }
        table { width: 100%; border-collapse: collapse; }
        td { border-bottom: 1px solid #e5e5e5; padding: 6px 8px; vertical-align: top; }
        td.q { width: 45%; font-weight: bold; }
        .delegated { color: #c88200; font-style: italic; }
        .meta { color: #777; font-size: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>BRİF — {{ $project->name }}</h1>
    <p class="meta">
        {{ $project->type->label() }}
        @if ($project->address) · {{ $project->address }} @endif
        · Tamamlanma: {{ $brief->completed_at?->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i') }}
    </p>

    @foreach ($map as $entry)
        @php
            $section = $entry['section'];
            $room = $entry['room'];
            $sectionAnswers = $answers
                ->where('brief_room_id', $room?->id)
                ->filter(fn ($a) => $a->question && $a->question->brief_section_id === $section->id);
        @endphp

        <h2>{{ $room?->label ?? $section->getTranslation('name', 'az') }}</h2>
        <table>
            @foreach ($section->questions as $question)
                @php $answer = $sectionAnswers->firstWhere('brief_question_id', $question->id); @endphp
                <tr>
                    <td class="q">{{ $question->getTranslation('label', 'az') }}</td>
                    <td>
                        @if ($answer?->delegated_to_designer)
                            <span class="delegated">Dizaynerin tövsiyəsi</span>
                        @elseif (is_array($answer?->value))
                            {{ collect($answer->value)->map(fn ($v) => $question->optionLabel((string) $v))->implode(', ') }}
                        @elseif ($answer?->value !== null && $answer->value !== '')
                            @if ($question->type === 'boolean')
                                {{ $answer->value === '1' || $answer->value === true ? 'Bəli' : 'Xeyr' }}
                            @elseif ($question->type === 'select')
                                {{ $question->optionLabel((string) $answer->value) }}
                            @else
                                {{ $answer->value }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach
</body>
</html>
