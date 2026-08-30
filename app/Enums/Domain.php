<?php

namespace App\Enums;

/**
 * Permission domains from the TZ §5.4 access matrix.
 */
enum Domain: string
{
    case Clients = 'clients';
    case Projects = 'projects';
    case Brief = 'brief';
    case StagesTasks = 'stages_tasks';
    case FilesDocuments = 'files_documents';
    case Budget = 'budget';
    case Procurement = 'procurement';
    case Payments = 'payments';
    case OwnerDashboard = 'owner_dashboard';
    case Analytics = 'analytics';
}
