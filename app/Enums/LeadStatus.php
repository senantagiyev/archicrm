<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case ConsultationScheduled = 'consultation_scheduled';
    case ConsultationCompleted = 'consultation_completed';
    case ProposalSent = 'proposal_sent';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case OnHold = 'on_hold';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Yeni',
            self::Contacted => 'Əlaqə saxlanılıb',
            self::ConsultationScheduled => 'Konsultasiya planlanıb',
            self::ConsultationCompleted => 'Konsultasiya keçirilib',
            self::ProposalSent => 'Təklif göndərilib',
            self::Negotiation => 'Danışıqlar',
            self::Won => 'Qazanılıb',
            self::Lost => 'İtirilib',
            self::OnHold => 'Gözləmədə',
            self::Archived => 'Arxiv',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'primary',
            self::ConsultationScheduled => 'warning',
            self::ConsultationCompleted => 'warning',
            self::ProposalSent => 'info',
            self::Negotiation => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
            self::OnHold => 'gray',
            self::Archived => 'gray',
        };
    }
}
