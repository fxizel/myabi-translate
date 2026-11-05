<?php

// Frozen synthetic annotations v1, authored before calibration. The final half uses different nouns.
// Each context is a fully annotated candidate pool; it is not a list of hardcoded search responses.
$nouns = [
    'fr' => ['dossier', 'adresse', 'personne', 'rapport', 'incident', 'document', 'véhicule', 'message', 'formulaire', 'décision', 'réception', 'évaluation', 'commentaire', 'organisation', 'notification', 'fichier', 'publication', 'campagne', 'traduction', 'utilisateur'],
    'de' => ['Dossier', 'Adresse', 'Person', 'Bericht', 'Vorfall', 'Dokument', 'Fahrzeug', 'Nachricht', 'Formular', 'Entscheidung', 'Empfang', 'Bewertung', 'Kommentar', 'Organisation', 'Mitteilung', 'Datei', 'Publikation', 'Kampagne', 'Übersetzung', 'Benutzer'],
    'it' => ['fascicolo', 'indirizzo', 'persona', 'rapporto', 'incidente', 'documento', 'veicolo', 'messaggio', 'formulario', 'decisione', 'ricezione', 'valutazione', 'commento', 'organizzazione', 'notifica', 'archivio', 'pubblicazione', 'campagna', 'traduzione', 'utente'],
    'en' => ['folder', 'address', 'person', 'report', 'incident', 'document', 'vehicle', 'message', 'application', 'decision', 'reception', 'evaluation', 'comment', 'organisation', 'notification', 'archive', 'publication', 'campaign', 'translation', 'operator'],
];
$actions = [
    'fr' => [['ouvrir', 'ouverture'], ['fermer', 'fermeture'], ['créer', 'création'], ['valider', 'validation'], ['imprimer', 'impression']],
    'de' => [['öffnen', 'Öffnung'], ['schliessen', 'Schliessung'], ['erstellen', 'Erstellung'], ['speichern', 'Speicherung'], ['drucken', 'Druck']],
    'it' => [['aprire', 'apertura'], ['chiudere', 'chiusura'], ['creare', 'creazione'], ['validare', 'validazione'], ['stampare', 'stampa']],
    'en' => [['open', 'opening'], ['close', 'closure'], ['create', 'creation'], ['validate', 'validation'], ['print', 'printing']],
];
$typo = static function (string $word, bool $transpose = false): string {
    $chars = mb_str_split($word);
    if ($transpose || count($chars) < 6) {
        // Prefer unequal neighbours, so an inversion is a real spelling error.
        for ($at = count($chars) - 2; $at >= 0; $at--) {
            if ($chars[$at] !== $chars[$at + 1]) {
                [$chars[$at], $chars[$at + 1]] = [$chars[$at + 1], $chars[$at]];
                break;
            }
        }
    } else {
        array_splice($chars, 2, 1);
    }

    return implode('', $chars);
};
$cases = [];
foreach ($nouns as $language => $words) {
    foreach ($words as $i => $noun) {
        [$verb, $family] = $actions[$language][$i % 5];
        $otherVerb = $actions[$language][($i + 1) % 5][0];
        foreach (['deletion', 'transposition', 'family', 'order', 'boundary'] as $scenario => $kind) {
            $text = $verb.' '.$noun;
            $query = match ($kind) {
                'deletion' => $typo($noun), 'transposition' => $verb.' '.$typo($noun, true),
                'family' => $verb.' '.$noun, 'order' => $noun.' '.$verb, default => '',
            };
            if ($kind === 'family') {
                $text = $family.' '.$noun;
            }
            $expected = true;
            $candidateLanguage = $language;
            if ($kind === 'boundary') {
                switch ($i % 5) {
                    case 0: $query = '"'.$typo($noun).'"';
                        $expected = false;
                        break;
                    case 1: $query = mb_substr($noun, 0, 2);
                        break;
                    case 2: $query = $typo($noun);
                        $candidateLanguage = $language === 'fr' ? 'it' : 'fr';
                        $expected = false;
                        break;
                    case 3: $query = $verb.' '.$noun.' {field} 13';
                        $text .= ' {field} 14';
                        $expected = false;
                        break;
                    case 4:
                        $without = ['fr' => 'sans erreur', 'de' => 'ohne Fehler', 'it' => 'senza errore', 'en' => 'without error'][$language];
                        $with = ['fr' => 'avec erreur', 'de' => 'mit Fehler', 'it' => 'con errore', 'en' => 'with error'][$language];
                        $query = $verb.' '.$noun.' '.$without;
                        $text .= ' '.$with;
                        $expected = false;
                        break;
                }
            }
            $candidates = [
                ['text' => $text, 'language' => $candidateLanguage, 'origin' => $i % 3 === 0 ? 'proposal' : 'current', 'relevant' => $expected],
                ['text' => $otherVerb.' '.$words[($i + 7) % 20], 'language' => $language, 'origin' => 'current', 'relevant' => false],
                ['text' => $verb.' '.$noun, 'language' => $language, 'origin' => 'rejected', 'relevant' => false],
            ];
            if ($language === 'de' && $i % 3 === 1 && $candidateLanguage === 'de') {
                $candidates[0]['origin'] = 'reference';
            }
            $cases[] = ['id' => $language.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'-'.$kind, 'split' => $i < 10 ? 'calibration' : 'final',
                'language' => $language, 'query' => $query, 'kind' => $kind, 'secondary' => $i % 4 === 0,
                'required' => $expected, 'candidates' => $candidates];
        }
    }
}

return $cases;
