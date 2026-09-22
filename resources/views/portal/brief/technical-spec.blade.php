<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111111; font-size: 12px; }
        h1 { font-size: 20px; border-bottom: 4px solid #fdfe00; padding-bottom: 8px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 22px; background: #111111; color: #ffffff; padding: 6px 10px; }
        h3 { font-size: 12px; margin: 18px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        td { border-bottom: 1px solid #e5e5e5; padding: 6px 8px; vertical-align: top; }
        td.q { width: 45%; font-weight: bold; }
        .delegated { color: #c88200; font-style: italic; }
        .meta { color: #777; font-size: 10px; margin-bottom: 18px; }
        .risk { padding: 6px 10px; margin-bottom: 4px; border-left: 4px solid #cccccc; }
        .risk-critical { border-color: #c0392b; background: #fdecea; }
        .risk-important { border-color: #c88200; background: #fdf6e3; }
        .risk-missing { border-color: #777777; background: #f3f3f3; }
        .sign { margin-top: 34px; border-top: 1px solid #cccccc; padding-top: 14px; }
        .sign td { border: 0; padding-top: 30px; width: 50%; }
        .line { border-bottom: 1px solid #111111; display: block; height: 1px; margin-bottom: 4px; }
        .note { color: #777; font-size: 10px; }
    </style>
</head>
<body>
    <h1>TEXNİKİ TAPŞIRIQ v{{ $version }}</h1>
    <p class="meta">
        {{ $project->name }} · {{ $project->type->label() }}
        @if ($project->address) · {{ $project->address }} @endif
        · Hazırlandı: {{ now()->format('d.m.Y') }}
    </p>

    {{-- Texniki tapşırıq brifin surəti deyil: burada YALNIZ cavablanmış tələblər
         var. Boş sətirlər tapşırığı oxunmaz edir və icra üçün məna daşımır —
         onlar brif ixracında qalır. --}}
    @if (! empty($risks))
        <h3>Diqqət tələb edən məqamlar</h3>
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

            $rows = $section->questions
                ->filter(fn ($q) => $q->shouldShow($entry['values']))
                ->map(fn ($q) => [
                    'question' => $q,
                    'answer' => $sectionAnswers->firstWhere('brief_question_id', $q->id),
                ])
                ->filter(fn (array $r) => $r['answer']?->delegated_to_designer
                    || ($r['answer']?->isAnswered() ?? false));
        @endphp

        @continue($rows->isEmpty())

        <h2>{{ $room?->label ?? $section->getTranslation('name', 'az') }}</h2>
        <table>
            @foreach ($rows as $row)
                <tr>
                    <td class="q">{{ $row['question']->getTranslation('label', 'az') }}</td>
                    <td>
                        @if ($row['answer']->delegated_to_designer)
                            <span class="delegated">Dizaynerin ixtiyarına buraxılıb</span>
                        @else
                            {{ $row['question']->displayValue($row['answer']->value) ?: '—' }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    {{-- Roomix axınının son addımı «Signing»dir — tapşırıq imza yeri olmadan
         razılaşdırma sənədi sayılmır. --}}
    <div class="sign">
        <p class="note">Bu texniki tapşırıq brifdəki cavablar əsasında hazırlanıb və razılaşdırıldıqdan sonra layihələndirmə üçün əsas sayılır.</p>
        <table>
            <tr>
                <td><span class="line"></span>Sifarişçi</td>
                <td><span class="line"></span>İcraçı</td>
            </tr>
        </table>
    </div>
</body>
</html>
