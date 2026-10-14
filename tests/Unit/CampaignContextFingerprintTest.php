<?php

namespace Tests\Unit;

use App\Models\Term;
use App\Services\CampaignService;
use App\Services\OperationLock;
use App\Services\SourceRecord;
use PHPUnit\Framework\TestCase;

class CampaignContextFingerprintTest extends TestCase
{
    public function test_historical_digest_preserves_the_exact_previous_json_formula_including_null_origin(): void
    {
        $service = new CampaignService(new OperationLock, new SourceRecord);
        $term = new Term(['type' => 'server', 'key' => ['Clé '], 'context' => 'Contexte']);
        $row = ['Name' => 'Clé ', 'de_CH' => 'Quelle {}', 'fr_CH' => 'Traduction {}', 'sa_IN' => '#Synthetic',
            'Create User' => 'original', 'Update Time' => '2026-10-13'];
        $attributes = ['label' => ['reference' => 'Quelle {}', 'reference_language' => 'de',
            'columns' => ['de' => 'de_CH', 'fr' => 'fr_CH'], 'translations' => ['de' => 'Quelle {}', 'fr' => 'Traduction {}']]];
        $technical = ['Name' => 'Clé ', 'sa_IN' => '#Synthetic'];

        foreach ([null, 42] as $organizationId) {
            $oldJson = json_encode(['server', ['Clé '], $organizationId, 'Contexte', $technical,
                'label', 'Quelle {}', 'de', 'fr_CH'], JSON_THROW_ON_ERROR);
            self::assertSame(hash('sha256', $oldJson), $service->historicalContextFingerprint($term, 'label', 'fr', $organizationId, $attributes, $row));
        }
        $common = $service->contextFingerprint($term, 'label', 'fr', $attributes, $row);
        $newJson = json_encode(['server', ['Clé '], 'Contexte', $technical, 'label', 'Quelle {}', 'de', 'fr_CH'], JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $newJson), $common);
        self::assertNotSame($common, $service->historicalContextFingerprint($term, 'label', 'fr', null, $attributes, $row));

        $term->forceFill(['organization_id' => 42]);
        self::assertSame($common, $service->contextFingerprint($term, 'label', 'fr', $attributes, $row));
        $row['fr_CH'] = 'Valeur corrigée {}';
        $row['Update Time'] = '2026-10-14';
        self::assertSame($common, $service->contextFingerprint($term, 'label', 'fr', $attributes, $row));
        $row['sa_IN'] = '#Changed';
        self::assertNotSame($common, $service->contextFingerprint($term, 'label', 'fr', $attributes, $row));
    }
}
