<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;
use App\Support\AccessMatrix;

/**
 * Layihə sənədləri «Fayllar / Sənədlər» domeninə aiddir. Müqavilə və aktlar isə
 * mühasibatlıq sənədidir (TZ «Layihə naviqasiyası» §7, §9): onlar «Maliyyə →
 * Müqavilələr və sənədlər»də aparılır və «Ödənişlər» domeninə tabedir — mühasib
 * (Ödənişlər = Tam, Fayllar = Baxış) müqavilə yükləyə bilsin, dizayner/vizualizator
 * (Ödənişlər = Yoxdur) maliyyə sənədlərini görməsin.
 */
class DocumentPolicy
{
    use ScopesProjectDomain;

    private ?Domain $recordDomain = null;

    protected function domain(): Domain
    {
        return $this->recordDomain ?? Domain::FilesDocuments;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->allowsOnDocument($user, $document, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->allowsOnDocument($user, $document, AccessLevel::Edit);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->allowsOnDocument($user, $document, AccessLevel::Edit);
    }

    /** Mühasibatlıq sənədləri siyahısı (Maliyyə) — «Ödənişlər» domeni. */
    public static function allowsAccounting(User $user, AccessLevel $minimum): bool
    {
        return AccessMatrix::allows($user, Domain::Payments, $minimum);
    }

    private function allowsOnDocument(User $user, Document $document, AccessLevel $minimum): bool
    {
        $this->recordDomain = $document->type?->isAccounting() ? Domain::Payments : null;

        try {
            return $this->allowsOn($user, $document->project, $minimum, $document->project_id);
        } finally {
            $this->recordDomain = null;
        }
    }
}
