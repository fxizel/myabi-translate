<?php

// Frozen supplementary annotations: complete insertion/substitution coverage
// without changing the original 400 queries or tuning the lexical profile.
$words = [
    'de' => ['Kalender', 'Abteilung', 'Genehmigung', 'Zeitraum', 'Anmeldung', 'Bearbeitung', 'Unterschrift', 'Berechtigung'],
    'fr' => ['calendrier', 'attestation', 'permission', 'registre', 'inventaire', 'certificat', 'rendezvous', 'validation'],
    'it' => ['calendario', 'attestato', 'permesso', 'registro', 'inventario', 'certificato', 'appuntamento', 'conferma'],
    'en' => ['calendar', 'certificate', 'permission', 'registry', 'inventory', 'appointment', 'signature', 'validation'],
];
$cases = [];
foreach ($words as $language => $nouns) {
    foreach ($nouns as $index => $word) {
        foreach (['insertion', 'substitution', 'two_edits'] as $kind) {
            $characters = mb_str_split($word);
            if ($kind === 'insertion') {
                array_splice($characters, 2, 0, ['q']);
            } elseif ($kind === 'substitution') {
                $characters[2] = 'q';
            } else {
                $characters[2] = 'q';
                // Keep both words at least eight characters long for two edits.
                $characters[count($characters) - 2] = 'x';
            }
            $query = implode('', $characters);
            $cases[] = ['id' => 'supp-'.$language.'-'.$index.'-'.$kind, 'split' => $index < 4 ? 'calibration' : 'final',
                'language' => $language, 'query' => $query, 'kind' => $kind, 'secondary' => $index % 4 === 0, 'required' => true,
                'candidates' => [
                    ['text' => $word, 'language' => $language, 'origin' => $language === 'de' && $index % 3 === 0 ? 'reference' : ($index % 2 ? 'proposal' : 'current'), 'relevant' => true],
                    ['text' => 'Neutral unrelated content', 'language' => $language, 'origin' => 'current', 'relevant' => false],
                    ['text' => $query, 'language' => $language, 'origin' => 'rejected', 'relevant' => false],
                ]];
        }
    }
}

return $cases;
