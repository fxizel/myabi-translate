<?php

return [
    'title' => 'Das myABI-Register nutzen',
    'description' => 'Begriffe finden, Übersetzungen vorschlagen und ihren Weg bis zur Publikation verfolgen.',
    'start' => 'Ein gemeinsames Register',
    'common' => 'Die Begriffe und validierten Übersetzungen sind für alle Organisationen gemeinsam. Die Organisation kennzeichnet Personen, die Herkunft von Vorschlägen und die Empfänger von Publikationen; sie erzeugt keine eigene Übersetzungsvariante.',
    'permissions' => 'Die verfügbaren Aktionen hängen von Ihren Rollen und freigegebenen Sprachen ab: Lesende konsultieren das Register, Übersetzende machen Vorschläge, Validierende entscheiden und die Verwaltung organisiert Gruppen, Kampagnen, Importe und Publikationen. Die Administration verwaltet Konten und Organisationen; diese Rolle allein berechtigt weder zum Übersetzen noch zum Validieren.',
    'contents' => 'Inhaltsverzeichnis der Hilfe',
    'rules' => 'Wichtige Regeln',
    'sections' => [
        'catalogue' => [
            'title' => 'Suchen und nachschlagen',
            'action' => 'Übersetzungen öffnen',
            'paragraphs' => [
                'Wählen Sie unter Übersetzungen die Zielsprache: DE, FR, IT oder EN. Sie ist unabhängig von der Sprache der Oberfläche, die Sie in der Kopfleiste auf DE, FR oder IT einstellen können.',
                'Suchen Sie nach einem Schlüssel, Referenztext oder einer Übersetzung. Filtern Sie nach DEVCONF-Typ, Version, Zustand, Gruppe oder Kampagne; Ohne Gruppe zeigt nicht zugeordnete Begriffe. Zurücksetzen entfernt die Filter.',
                'Öffnen Sie den Begriff über den Schlüssel oder die Referenz, um Attribute, Vorschläge, Verlauf und Vorkommen in den Versionen anzusehen. Gültiger Wert und Vorschlag werden getrennt angezeigt.',
            ],
        ],
        'translate' => [
            'title' => 'Eine Übersetzung vorschlagen',
            'action' => 'Katalog öffnen',
            'paragraphs' => [
                'Geben Sie den Text in der Spalte Vorschlag oder in der Begriffsansicht ein. Übernehmen Sie die Variablen der Referenz unverändert und wählen Sie Vorschlagen oder Strg + Enter (⌘ + Enter auf dem Mac). Für mehrere Eingaben im Katalog markieren Sie die betreffenden Zeilen und wählen Vorschlagen in der Auswahlleiste.',
                'Eine nicht gesendete Eingabe ist noch kein gespeicherter Vorschlag. Der Browser warnt vor dem Verlassen einer Seite mit Änderungen; senden Sie Ihre Eingaben, bevor Sie die Seite oder Sprache wechseln.',
                'Ist bereits ein Vorschlag ausstehend, öffnen Sie die Details, um ihn zu prüfen. Die Bestätigung des aktuellen Werts ändert ihn nicht und ersetzt keine Validierung eines Vorschlags.',
            ],
        ],
        'groups' => [
            'title' => 'Begriffe in Gruppen einordnen',
            'action' => 'Begriffsgruppen öffnen',
            'paragraphs' => [
                'Eine Gruppe fasst Begriffe zu einem Thema zusammen und hat einen Titel, eine Farbe und eine Beschreibung. Jeder Begriff gehört zu keiner oder genau einer Gruppe. Diese Einordnung ändert weder seine Identität noch seine Übersetzung.',
                'Die Verwaltung erstellt Gruppen und ordnet Begriffe in der Begriffsansicht oder über die Auswahlfelder im Katalog zu. Ohne Gruppe hebt die Zuordnung auf. Gruppen dienen als Filter und als Grundlage für Kampagnen.',
            ],
        ],
        'campaigns' => [
            'title' => 'In einer Kampagne arbeiten',
            'action' => 'Übersetzungskampagnen öffnen',
            'paragraphs' => [
                'Die Verwaltung bereitet eine Kampagne für eine Zielsprache mit Anweisungen, zugewiesenen Personen und einem Termin vor. Das Ziel Übersetzung wählt Begriffe ohne validierte Übersetzung; Revision wählt Begriffe mit einer validierten Übersetzung zur Prüfung. Der Umfang kombiniert synchronisierte Gruppen und direkt hinzugefügte Begriffe.',
                'Nach dem Start bearbeiten die zugewiesenen Personen die Begriffe: Zu bearbeiten, Zur Klärung, Vorschlag eingereicht oder Übersetzung bestätigt. Schlagen Sie eine Korrektur vor, bestätigen Sie eine korrekte Übersetzung unverändert oder fordern Sie eine begründete Klärung an. Validierende der Sprache oder die Verwaltung lösen Klärungen; Vorschläge durchlaufen weiterhin die übliche Validierung.',
                'Der Arbeitsfortschritt zählt gesendete Vorschläge und bestätigte Übersetzungen. Der bestätigte Anteil zählt nur Bestätigungen: 100 % Arbeitsfortschritt bedeutet daher nicht, dass alle Übersetzungen validiert sind. Eine gestartete Kampagne endet, sobald alle ihre Begriffe bestätigt sind.',
                'Gruppen werden synchronisiert, solange die Kampagne Zu erledigen oder In Arbeit ist. Eine abgeschlossene Kampagne behält ihren Umfang und ihr Ergebnis; eine ausdrückliche erneute Prüfung durch Validierende der Sprache oder die Verwaltung öffnet sie wieder.',
            ],
        ],
        'validate' => [
            'title' => 'Prüfen und entscheiden',
            'action' => 'Validierung öffnen',
            'paragraphs' => [
                'Vergleichen Sie unter Validierung die Referenz, den gültigen Wert und jeden Vorschlag. Validieren aktualisiert die Übersetzung und erstellt eine Revision. Ablehnen verlangt eine Begründung und lässt den gültigen Wert unverändert. Blockierende Fehler müssen vor der Validierung behoben werden.',
                'Über die Auswahlfelder entscheiden Sie über die markierten Zeilen, höchstens 500 Vorschläge pro Vorgang. Die Validierung aller gefilterten Vorschläge erfasst auch nicht angezeigte Ergebnisse: prüfen Sie die Filter und die angegebene Anzahl und lesen Sie anschliessend den Bericht. Fehlerhafte oder konkurrierende Vorschläge für dieselbe Zelle werden ausgeschlossen.',
                'Sie validieren nicht Ihren eigenen Vorschlag. Nur die Verwaltung kann eine ausdrückliche Ausnahme beantragen, die im Protokoll aufgezeichnet wird.',
            ],
        ],
        'import' => [
            'title' => 'Eine DEVCONF-Datei importieren',
            'action' => 'Importe öffnen',
            'paragraphs' => [
                'Die Verwaltung wählt die CSV-Datei, ihren Typ, die myABI-Version und ihre Herkunft. Die Vorabprüfung erstellt einen Bericht, ohne Begriffe zu ändern. Prüfen Sie Fehler und Warnungen, bevor Sie den Import anwenden.',
                'Die Anwendung übernimmt die Begriffe ins gemeinsame Register und erstellt Vorschläge für übersetzte Werte. Sie validiert diese nicht automatisch. Die initiale Massenvalidierung ist eine separate Aktion, die die Rechte und Prüfungen für jeden Vorschlag berücksichtigt.',
                'Die Verarbeitung läuft im Hintergrund weiter; die Seite zeigt Fortschritt und Bericht. Mehrdeutige Identitäten bleiben gesperrt und müssen vor dem Fortfahren geklärt werden.',
            ],
        ],
        'deliver' => [
            'title' => 'Exportieren oder eine Publikation herunterladen',
            'action' => 'Publikationen öffnen',
            'paragraphs' => [
                'Unter Exporte kann die Verwaltung eine DEVCONF-Datei mit allen bekannten Begriffen des gewählten Typs erstellen. Validierte Übersetzungen haben Vorrang; Zellen ohne validierte Übersetzung können einen importierten Ersatzwert übernehmen. Dieser Export erstellt keine Publikation.',
                'Eine Publikation ist ein eingefrorener Katalog, den die Verwaltung für berechtigte Empfänger erstellt. Sie enthält Begriffe mit mindestens einer validierten Übersetzung und verwendet ausschliesslich validierte Übersetzungen; andere Zielzellen bleiben leer, ohne Vorschläge oder importierte Werte zu übernehmen.',
                'Öffnen Sie unter Publikationen eine Publikation, um ihre Abdeckung anzusehen und einzelne Dateien oder das vollständige Archiv herunterzuladen. Spätere Validierungen ändern diese Dateien nicht: dafür muss eine neue Publikation erstellt werden.',
            ],
        ],
        'profile' => [
            'title' => 'Profil und Anzeige einstellen',
            'action' => 'Mein Profil öffnen',
            'paragraphs' => [
                'Sehen Sie unter Mein Profil Ihre Angaben ein und ändern Sie die Sprache der Oberfläche, tägliche Benachrichtigungen und Ihr Passwort. Richten Sie den zweiten Faktor ein, wenn Ihre Rolle ihn verlangt, und bewahren Sie die Wiederherstellungscodes sicher auf.',
                'Die Schaltfläche Kompakt in der Kopfleiste passt die Abstände in Tabellen an. Sichtbare Spalten erlaubt das Ausblenden bestimmter Katalogspalten. Diese Anzeigeeinstellungen ändern keine Daten.',
                'Wenn MyAB Translation Inspector aktiviert und in Ihrem Browser installiert ist, erstellen Sie unter Mein Profil einen persönlichen Token und kopieren Sie ihn sofort in die Einstellungen der Erweiterung: er wird nur einmal angezeigt. Konfigurieren Sie die API-Adresse und die erlaubten MyAB-Domains, aktivieren Sie den Inspektormodus und verwenden Sie Alt + Klick auf einen Text, um seinen Schlüssel zu finden und seine Übersetzung zu öffnen. Sie können den Token in Ihrem Profil widerrufen.',
            ],
        ],
    ],
];
