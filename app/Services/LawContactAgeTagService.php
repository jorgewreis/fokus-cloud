<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LawContactAgeTagService
{
    public const TAGS = [
        'idoso +80' => 'IDOSO +80',
        'idoso' => 'IDOSO',
        'criança ou adolescente' => 'CRIANÇA OU ADOLESCENTE',
    ];

    public function syncContact(string $companyId, string $contactId): void
    {
        $contact = DB::table('law_contacts')->where('company_id', $companyId)->where('id', $contactId)
            ->whereNull('deleted_at')->whereNull('merged_into_id')->first(['id', 'legal_nature', 'record_kind', 'sensitive_details_encrypted', 'created_by', 'updated_by']);
        if (! $contact) return;

        $desiredTags = [];
        if (($contact->record_kind ?? 'contact') === 'contact' && $contact->legal_nature === 'pf') {
            $birthDate = $this->birthDate($contact->sensitive_details_encrypted);
            if ($birthDate) {
                try {
                    $age = Carbon::parse($birthDate)->age;
                    if ($age < 18) $desiredTags[] = 'criança ou adolescente';
                    else {
                        if ($age > 60) $desiredTags[] = 'idoso';
                        if ($age > 80) $desiredTags[] = 'idoso +80';
                    }
                } catch (\Throwable) {
                    // An unreadable legacy date must not prevent saving a contact.
                }
            }
        }

        $desiredIds = [];
        foreach ($desiredTags as $normalized) {
            $tag = DB::table('law_contact_tags')->where('company_id', $companyId)->where('normalized_name', $normalized)->first();
            if ($tag) {
                if ($tag->name !== self::TAGS[$normalized]) {
                    DB::table('law_contact_tags')->where('id', $tag->id)->update(['name' => self::TAGS[$normalized], 'updated_at' => now()]);
                }
            } else {
                $tagId = PrefixedUlid::make('LTG');
                DB::table('law_contact_tags')->insertOrIgnore([
                    'id' => $tagId, 'company_id' => $companyId, 'name' => self::TAGS[$normalized],
                    'normalized_name' => $normalized, 'created_by' => $contact->created_by ?? $contact->updated_by,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $tag = DB::table('law_contact_tags')->where('company_id', $companyId)->where('normalized_name', $normalized)->first();
                if (! $tag) throw new \RuntimeException('Não foi possível registrar a etiqueta automática de idade.');
            }
            $desiredIds[] = (string) $tag->id;
        }

        $automaticTagIds = DB::table('law_contact_tags')->where('company_id', $companyId)->whereIn('normalized_name', array_keys(self::TAGS))->pluck('id');
        $removals = DB::table('law_contact_tag_assignments')->where('company_id', $companyId)->where('law_contact_id', $contactId);
        if ($desiredIds !== []) $removals->whereIn('law_contact_tag_id', $automaticTagIds)->whereNotIn('law_contact_tag_id', $desiredIds)->delete();
        elseif ($automaticTagIds->isNotEmpty()) $removals->whereIn('law_contact_tag_id', $automaticTagIds)->delete();

        foreach ($desiredIds as $tagId) {
            DB::table('law_contact_tag_assignments')->insertOrIgnore([
                'company_id' => $companyId, 'law_contact_id' => $contactId, 'law_contact_tag_id' => $tagId,
            ]);
        }
    }

    public function syncAll(): int
    {
        if (! Schema::hasTable('law_contacts') || ! Schema::hasTable('law_contact_tags') || ! Schema::hasTable('law_contact_tag_assignments')) return 0;

        $processed = 0;
        DB::table('law_contacts')->where('legal_nature', 'pf')->where('record_kind', 'contact')
            ->whereNull('deleted_at')->whereNull('merged_into_id')->orderBy('id')->chunkById(200, function ($contacts) use (&$processed): void {
                foreach ($contacts as $contact) {
                    $this->syncContact((string) $contact->company_id, (string) $contact->id);
                    $processed++;
                }
            });

        return $processed;
    }

    public static function isAutomaticTag(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), array_keys(self::TAGS), true);
    }

    private function birthDate(?string $encryptedDetails): ?string
    {
        if (! $encryptedDetails) return null;
        try {
            $details = json_decode(Crypt::decryptString($encryptedDetails), true, 512, JSON_THROW_ON_ERROR);
            $birthDate = $details['birth_date'] ?? null;
            return is_string($birthDate) && $birthDate !== '' ? $birthDate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
