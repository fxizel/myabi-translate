<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationLocalizationTest extends TestCase
{
    #[DataProvider('validationMessages')]
    public function test_exposed_validation_errors_are_fully_translated(string $locale, array $expected): void
    {
        app()->setLocale($locale);

        $messages = validator([
            'preview_token' => 'short', 'password' => 'not-allowed',
            'from' => '2026-09-23', 'to' => '2026-09-22',
            'recovery_code' => ['invalid'], 'decision' => 'reject',
        ], [
            'preview_token' => 'required|string|size:64', 'password' => 'prohibited',
            'password_confirmation' => 'required|string',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'recovery_code' => 'nullable|string', 'reason' => 'required_if:decision,reject|nullable|string',
        ])->errors();

        foreach ($expected as $field => $message) {
            $this->assertSame($message, $messages->first($field), $locale.' '.$field);
        }
    }

    public static function validationMessages(): array
    {
        return [
            'French' => ['fr', [
                'preview_token' => 'Le champ jeton de prévisualisation doit contenir exactement 64 caractères.',
                'password' => 'Le champ mot de passe est interdit.',
                'password_confirmation' => 'Le champ confirmation du mot de passe est obligatoire.',
                'to' => 'Le champ date de fin doit être une date postérieure ou égale à date de début.',
                'recovery_code' => 'Le champ code de récupération doit être un texte.',
                'reason' => 'Le champ motif est obligatoire lorsque décision vaut rejet.',
            ]],
            'German' => ['de', [
                'preview_token' => 'Das Feld Vorschau-Schlüssel muss genau 64 Zeichen enthalten.',
                'password' => 'Das Feld Passwort ist nicht erlaubt.',
                'password_confirmation' => 'Das Feld Passwortbestätigung ist erforderlich.',
                'to' => 'Das Feld Enddatum muss ein Datum nach oder gleich Startdatum sein.',
                'recovery_code' => 'Das Feld Wiederherstellungscode muss Text enthalten.',
                'reason' => 'Das Feld Begründung ist erforderlich, wenn Entscheidung den Wert Ablehnung hat.',
            ]],
            'Italian' => ['it', [
                'preview_token' => 'Il campo token di anteprima deve contenere esattamente 64 caratteri.',
                'password' => 'Il campo password non è consentito.',
                'password_confirmation' => 'Il campo conferma della password è obbligatorio.',
                'to' => 'Il campo data di fine deve essere una data successiva o uguale a data di inizio.',
                'recovery_code' => 'Il campo codice di recupero deve contenere un testo.',
                'reason' => 'Il campo motivo è obbligatorio quando decisione vale rifiuto.',
            ]],
        ];
    }
}
