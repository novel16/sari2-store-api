<?php

namespace App\Services\Admin\Website;

use App\Models\User;
use App\Models\WebsiteDocument;
use App\Models\WebsiteRevision;
use App\Services\Admin\AdministratorAccess;
use Illuminate\Support\Facades\DB;

class SaveWebsite
{
    public function handle(User $actor, array $input, string $action, ?int $revision = null): WebsiteDocument
    {
        return DB::transaction(function () use ($actor, $input, $action, $revision) {
            $document = WebsiteDocument::whereKey(1)->lockForUpdate()->firstOrFail();
            $actor = AdministratorAccess::lock($actor);
            abort_unless($document->version === (int) $input['expected_version'], 409,
                'Website content changed. Reload the latest version before saving.');
            if ($action === 'restored') {
                $source = WebsiteRevision::where('website_document_id', $document->id)->findOrFail($revision);
                $document->draft = $source->content;
            } elseif ($action === 'saved') {
                $document->draft = $input['content'];
            }
            $document->version++;
            if ($action === 'published') {
                $document->published = $document->draft;
                $document->published_version = $document->version;
                $document->published_at = now();
            }
            $document->save();
            WebsiteRevision::create(['website_document_id' => $document->id, 'version' => $document->version,
                'action' => $action, 'content' => $document->draft, 'actor_user_id' => $actor->id,
                'actor_name_snapshot' => $actor->name, 'change_note' => $input['change_note']]);

            return $document;
        }, 3);
    }
}
