<?php

declare(strict_types=1);

namespace App\Domain\Devconf;

use InvalidArgumentException;

final class FormatRegistry
{
    /** @return array<string, FormatDefinition> */
    public static function all(): array
    {
        $nls = ['Name', 'Default', 'Caption', 'de_CH', 'it_CH', 'fr_CH', 'en_US', 'sa_IN', 'Description', 'Create Time', 'Create User', 'Update Time', 'Update User', 'Application'];
        $pairs = ['Default Language', 'Default Label', 'Language 1', 'Translation 1', 'Language 2', 'Translation 2', 'Language 3', 'Translation 3', 'Language 4', 'Translation 4'];
        $formKey = ['Form Type', 'Form Template Name', 'Control Name', 'Column Name'];
        $workflowKey = ['Workflow', 'Control type', 'Control Name', 'Column'];
        $lawAttributes = ['Cause Text', 'Short Justification', 'Legal Remedies'];
        $law = ['ID#', 'Name', 'BU External ID', 'Cause Id'];
        foreach ($lawAttributes as $attribute) {
            foreach (['Default', 'de_CH', 'it_CH', 'fr_CH', 'en_US'] as $language) {
                $law[] = "{$attribute} {$language}";
            }
        }
        $incident = ['ACTIVE', 'ALTERNATIVE_TEXT_1_DE', 'ALTERNATIVE_TEXT_1_EN', 'ALTERNATIVE_TEXT_1_FR', 'ALTERNATIVE_TEXT_1_IT', 'ALTERNATIVE_TEXT_2_DE', 'ALTERNATIVE_TEXT_2_EN', 'ALTERNATIVE_TEXT_2_FR', 'ALTERNATIVE_TEXT_2_IT', 'BUEXTERNALID', 'CODEVALUE', 'CREATETIME', 'CREATEUSER', 'GROUPTYPE', 'ID', 'MASTERTYPE', 'MASTERVALUE', 'SHORTTEXT_DE', 'SHORTTEXT_EN', 'SHORTTEXT_FR', 'SHORTTEXT_IT', 'SHOWONMOT', 'SHOWSEQUENCE', 'SOURCECODE', 'TEXT_DE', 'TEXT_EN', 'TEXT_FR', 'TEXT_IT', 'UPDATE_DT', 'UPDATE_USER', 'VALIDFROMDT', 'VALIDTODT'];
        foreach (['ADDINFO', 'ADDINFO1', 'ADDINFO2', 'ADDINFO3', 'ADDINFO4', 'ADDINFO5', 'ADDINFO6', 'USAGE_POLICY'] as $attribute) {
            foreach (['DE', 'EN', 'FR', 'IT'] as $language) {
                $incident[] = $attribute.'_'.$language;
            }
        }

        return [
            'mric' => new FormatDefinition('mric', 'mRic-js', '01-DEVCONF-NLS-MRIC.csv', $nls, ['Name'], ['label'], 'nls'),
            'monomot' => new FormatDefinition('monomot', 'MonoMOT', '02-DEVCONF-NLS-MONOMOT.csv', $nls, ['Name'], ['label'], 'nls'),
            'server' => new FormatDefinition('server', 'Server', '03-DEVCONF-NLS-SERVER.csv', $nls, ['Name'], ['label'], 'nls'),
            'form' => new FormatDefinition('form', 'Form', '04-DEVCONF-NLS-FORM.csv', [...$formKey, ...$pairs, 'Create Dt', 'Create User', 'Update Dt', 'Update User', 'Application', 'Active'], $formKey, ['label'], 'pairs'),
            'workflow' => new FormatDefinition('workflow', 'Incident Workflow', '05-DEVCONF-NLS-INCIDENTWORKFLOW.csv', [...$workflowKey, ...$pairs, 'Update Dt', 'Update User', 'Application'], $workflowKey, ['label'], 'pairs'),
            'law' => new FormatDefinition('law', 'Law Catalog', '06-DEVCONF-NLS-LAWCATALOG.csv', [...$law, 'PHV', 'UpdateUser', 'UpdateDt'], ['ID#'], $lawAttributes, 'law'),
            'incident' => new FormatDefinition('incident', 'Incident code', '07-DEVCONF-INCIDENTCODE.csv', [...$incident, 'CODE_ICON_NAME', 'CODE_FILL_COLOR', 'CODE_BORDER_COLOR', 'ATTRIBUTES'], ['GROUPTYPE', 'CODEVALUE', 'MASTERTYPE', 'MASTERVALUE'], ['TEXT', 'SHORTTEXT', 'ALTERNATIVE_TEXT_1', 'ALTERNATIVE_TEXT_2', 'ADDINFO', 'ADDINFO1', 'ADDINFO2', 'ADDINFO3', 'ADDINFO4', 'ADDINFO5', 'ADDINFO6', 'USAGE_POLICY'], 'incident', false, false),
        ];
    }

    public static function get(string $code): FormatDefinition
    {
        return self::all()[$code] ?? throw new InvalidArgumentException('Type DEVCONF inconnu.');
    }

    /** NLS has three identical layouts: a filename or explicit selection disambiguates them. */
    public static function detect(array $headers, ?string $filename = null): FormatDefinition
    {
        $matches = array_filter(self::all(), fn ($format) => $format->headers === $headers);
        if ($filename !== null) {
            foreach ($matches as $format) {
                if (strcasecmp(basename($filename), $format->filename) === 0) {
                    return $format;
                }
            }
        }
        if (count($matches) !== 1) {
            throw new InvalidArgumentException(count($matches) === 0 ? 'Format DEVCONF non reconnu.' : 'Les formats NLS partagent leur en-tête : sélectionner le type.');
        }

        return reset($matches);
    }
}
