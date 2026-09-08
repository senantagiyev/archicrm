<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111111; font-size: 12px; }
        h1 { font-size: 20px; border-bottom: 4px solid #fdfe00; padding-bottom: 8px; }
        h2 { font-size: 14px; margin-top: 22px; background: #111111; color: #ffffff; padding: 6px 10px; }
        h3 { font-size: 12px; margin: 18px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        td { border-bottom: 1px solid #e5e5e5; padding: 6px 8px; vertical-align: top; }
        td.q { width: 45%; font-weight: bold; }
        .delegated { color: #c88200; font-style: italic; }
        .meta { color: #777; font-size: 10px; margin-bottom: 20px; }
        .risk { padding: 6px 10px; margin-bottom: 4px; border-left: 4px solid #cccccc; }
        .risk-critical { border-color: #c0392b; background: #fdecea; }
        .risk-important { border-color: #c88200; background: #fdf6e3; }
        .risk-missing { border-color: #777777; background: #f3f3f3; }
    </style>
</head>
<body>
    <h1>BRİF — {{ $project->name }}</h1>
    <p class="meta">
        {{ $project->type->label() }}
        @if ($project->address) · {{ $project->address }} @endif
        · Tamamlanma: {{ $brief->completed_at?->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i') }}
    </p>

    {{-- Part 14 — dizayner üçün avtomatik xəbərdarlıqlar --}}
    @if (! empty($risks))
        <h3>Aşkarlanmış risklər</h3>
        @foreach ($risks as $risk)
            <div class="risk risk-{{ $risk['level'] }}"><strong>{{ $risk['code'] }}</strong> — {{ $risk['message'] }}</div>
        @endforeach
    @endif

    @foreach ($map as $entry)
        @php
            $section = $entry['section'];
            $room = $entry['room'];
            $sectionAnswers = $answers
                ->where('brief_room_id', $room?->id)
                ->filter(fn ($a) => $a->question && $a->question->brief_section_id === $section->id);
            $visible = $section->questions->filter(fn ($q) => $q->shouldShow($entry['values']));
        @endphp

        <h2>{{ $room?->label ?? $section->getTranslation('name', 'az') }}</h2>
        <table>
            @foreach ($visible as $question)
                @php $answer = $sectionAnswers->firstWhere('brief_question_id', $question->id); @endphp
                <tr>
                    <td class="q">{{ $question->getTranslation('label', 'az') }}</td>
                    <td>
                        @if ($answer?->delegated_to_designer)
                            <span class="delegated">Dizaynerin tövsiyəsi</span>
                        @elseif ($answer && $answer->isAnswered())
                            {{ $question->displayValue($answer->value) ?: '—' }}
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
