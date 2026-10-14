<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UploadLimitTest extends TestCase
{
    public static function languages(): array
    {
        return [
            ['fr', 'Fichier trop volumineux', 'aucun import n’a été créé'],
            ['de', 'Datei zu gross', 'Es wurde kein Import erstellt'],
            ['it', 'File troppo grande', 'non è stata creata alcuna importazione'],
        ];
    }

    #[DataProvider('languages')]
    public function test_oversized_upload_has_a_localized_error_before_the_session_starts(string $locale, string $title, string $message): void
    {
        $this->call('POST', '/imports', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => $locale,
            'CONTENT_LENGTH' => ini_parse_quantity(ini_get('post_max_size')) + 1,
        ])->assertStatus(413)->assertSee('lang="'.$locale.'"', false)
            ->assertSee($title)->assertSee($message)->assertSee('href="'.url('imports').'"', false);
    }

    public function test_oversized_json_request_keeps_a_json_response(): void
    {
        $this->call('POST', '/imports', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_LENGTH' => ini_parse_quantity(ini_get('post_max_size')) + 1,
        ])->assertStatus(413)->assertJsonStructure(['message']);
    }
}
