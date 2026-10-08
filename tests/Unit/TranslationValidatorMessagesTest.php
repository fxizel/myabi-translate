<?php

namespace Tests\Unit;

use App\Domain\Devconf\TranslationValidator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class TranslationValidatorMessagesTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_standalone_validation_retains_french_messages_without_laravel(): void
    {
        $result = TranslationValidator::validate('Bonjour {name}', '');
        self::assertSame(['empty', 'placeholders'], $result['error_codes']);
        self::assertSame('La proposition ne peut pas être vide.', $result['errors'][0]);
        self::assertSame('Les placeholders et leur nombre doivent être identiques à la référence.', $result['errors'][1]);
        self::assertSame(['encoding'], TranslationValidator::validate('Texte', '🚓')['error_codes']);
        self::assertSame(['identical'], TranslationValidator::validate('Texte', 'Texte')['warning_codes']);
        self::assertSame(['maximum_length', 'relative_length'], TranslationValidator::validate('A', 'Plus long', 2)['warning_codes']);
        self::assertSame('La traduction est identique à la référence.', TranslationValidator::displayMessage('identical'));
        self::assertSame('Diagnostic inconnu', TranslationValidator::displayMessage('Diagnostic inconnu'));
    }
}
