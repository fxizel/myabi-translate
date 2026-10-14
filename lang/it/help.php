<?php

return [
    'title' => 'Utilizzare il repertorio myABI',
    'description' => 'Trovare un termine, proporre una traduzione e seguirne il percorso fino alla pubblicazione.',
    'start' => 'Un repertorio comune',
    'common' => 'I termini e le traduzioni convalidate sono comuni alle organizzazioni. L’organizzazione identifica le persone, l’origine delle proposte e i destinatari delle pubblicazioni; non crea una variante di traduzione.',
    'permissions' => 'Le azioni disponibili dipendono dai ruoli e dalle lingue autorizzate: il Lettore consulta, il Traduttore propone, il Validatore decide e il Gestore organizza gruppi, campagne, importazioni e pubblicazioni. L’Amministratore gestisce account e organizzazioni; questo ruolo da solo non dà diritti di traduzione o convalida.',
    'contents' => 'Sommario dell’aiuto',
    'rules' => 'Regole da ricordare',
    'sections' => [
        'catalogue' => [
            'title' => 'Cercare e consultare',
            'action' => 'Apri le traduzioni',
            'paragraphs' => [
                'In Traduzioni, scelga la lingua di destinazione: DE, FR, IT o EN. È indipendente dalla lingua dell’interfaccia, impostabile nella barra superiore in DE, FR o IT.',
                'Cerchi una chiave, un testo di riferimento o una traduzione. Utilizzi i filtri per tipo DEVCONF, versione, stato, gruppo o campagna; Senza gruppo trova i termini non classificati. Reimposta rimuove i filtri.',
                'Apra la scheda dalla chiave o dal riferimento per consultare attributi, proposte, cronologia e presenza nelle versioni. Il valore in vigore e la proposta sono visualizzati separatamente.',
            ],
        ],
        'translate' => [
            'title' => 'Proporre una traduzione',
            'action' => 'Apri il catalogo',
            'paragraphs' => [
                'Inserisca il testo nella colonna Proposta o nella scheda del termine. Conservi esattamente le variabili del riferimento, poi utilizzi Proponi o Ctrl + Invio (⌘ + Invio su Mac). Per inviare più testi dal catalogo, selezioni le righe interessate e utilizzi Proponi nella barra di selezione.',
                'Un testo non inviato non è registrato come proposta. Il browser avverte prima di lasciare una pagina contenente modifiche; invii i testi prima di cambiare pagina o lingua.',
                'Se una proposta è già in attesa, apra i dettagli per esaminarla. La conferma del valore attuale non lo modifica e non sostituisce la convalida di una proposta.',
            ],
        ],
        'groups' => [
            'title' => 'Classificare i termini in gruppi',
            'action' => 'Apri i gruppi di termini',
            'paragraphs' => [
                'Un gruppo riunisce i termini di uno stesso argomento, con titolo, colore e descrizione. Ogni termine appartiene a zero o un gruppo. La classificazione non modifica la sua identità né la traduzione.',
                'Il Gestore crea i gruppi e assegna i termini dalla loro scheda o dalle caselle di selezione del catalogo. La scelta Senza gruppo rimuove l’assegnazione. I gruppi servono poi come filtri e come base per le campagne.',
            ],
        ],
        'campaigns' => [
            'title' => 'Lavorare in una campagna',
            'action' => 'Apri le campagne di traduzione',
            'paragraphs' => [
                'Il Gestore prepara una campagna per una lingua di destinazione, con istruzioni, persone assegnate e scadenza. L’obiettivo Traduzione seleziona i termini senza traduzione convalidata; Revisione seleziona quelli con una traduzione convalidata da esaminare. L’ambito combina gruppi sincronizzati e termini aggiunti direttamente.',
                'Dopo l’avvio, i partecipanti assegnati trattano i termini: Da trattare, Da decidere, Proposta inviata o Traduzione confermata. Proponga una correzione, confermi una traduzione corretta senza modifiche o richieda una decisione motivata. Il Validatore della lingua o il Gestore risolve le richieste di decisione; le proposte mantengono il normale circuito di convalida.',
                'L’avanzamento del lavoro conta le proposte inviate e le traduzioni confermate. La quota confermata conta solo le conferme: il 100 % di avanzamento non significa quindi che tutte le traduzioni siano convalidate. Una campagna avviata termina quando tutti i suoi termini sono confermati.',
                'I gruppi restano sincronizzati finché la campagna è Da fare o In corso. Una campagna terminata conserva il suo ambito e risultato; un riesame esplicito da parte del Validatore della lingua o del Gestore la riapre.',
            ],
        ],
        'validate' => [
            'title' => 'Esaminare e decidere',
            'action' => 'Apri la convalida',
            'paragraphs' => [
                'In Convalida, confronti il riferimento, il valore in vigore e ogni proposta. Convalida aggiorna la traduzione e crea una revisione. Respingi richiede un motivo e lascia invariato il valore in vigore. Le anomalie bloccanti devono essere corrette prima della convalida.',
                'Le caselle di selezione consentono di decidere sulle righe scelte, fino a 500 proposte per invio. La convalida di tutte le proposte filtrate include anche i risultati non visualizzati: verifichi i filtri e il numero indicato, poi consulti il rapporto. Le proposte non conformi o concorrenti per una stessa cella sono escluse.',
                'Non può convalidare la propria proposta. Solo il Gestore può richiedere una deroga esplicita, registrata nel giornale.',
            ],
        ],
        'import' => [
            'title' => 'Importare un file DEVCONF',
            'action' => 'Apri le importazioni',
            'paragraphs' => [
                'Il Gestore sceglie il file CSV, il tipo, la versione myABI e l’origine. L’analisi preliminare produce un rapporto senza modificare i termini. Esamini errori e avvertenze prima di applicare l’importazione.',
                'L’applicazione integra i termini nel repertorio comune e crea proposte per i valori tradotti. Non li convalida automaticamente. La convalida iniziale in massa è un’azione distinta, soggetta ai diritti e ai controlli di ogni proposta.',
                'L’elaborazione continua in background; la pagina mostra l’avanzamento e il rapporto. Le identità ambigue restano bloccate e devono essere risolte prima di proseguire.',
            ],
        ],
        'deliver' => [
            'title' => 'Esportare o scaricare una pubblicazione',
            'action' => 'Apri le pubblicazioni',
            'paragraphs' => [
                'Esportazioni permette al Gestore di generare un file DEVCONF con tutti i termini noti del tipo scelto. Le traduzioni convalidate hanno la priorità; le celle senza traduzione convalidata possono riprendere un valore importato di ripiego. Questa esportazione non crea una pubblicazione.',
                'Una pubblicazione è un catalogo fissato creato dal Gestore per i destinatari autorizzati. Include i termini con almeno una traduzione convalidata e utilizza solo le traduzioni convalidate; le altre celle di destinazione restano vuote, senza riprendere proposte o valori importati.',
                'In Pubblicazioni, apra la pubblicazione per consultarne la copertura e scaricare i file o l’archivio completo. Le convalide successive non modificano questi file: occorre creare una nuova pubblicazione per integrarle.',
            ],
        ],
        'profile' => [
            'title' => 'Impostare il profilo e la visualizzazione',
            'action' => 'Apri il mio profilo',
            'paragraphs' => [
                'In Il mio profilo, consulti i dati personali e imposti la lingua dell’interfaccia, le notifiche quotidiane e la password. Configuri il secondo fattore quando il ruolo lo richiede e conservi i codici di recupero in un luogo sicuro.',
                'Il pulsante Compatto nella barra superiore adatta la spaziatura delle tabelle. Colonne visualizzate consente di nascondere alcune colonne del catalogo. Queste impostazioni di visualizzazione non modificano i dati.',
                'Se MyAB Translation Inspector è attivato e installato nel browser, crei un token personale in Il mio profilo e lo copi subito nelle impostazioni dell’estensione: viene visualizzato una sola volta. Configuri l’indirizzo API e i domini MyAB autorizzati, attivi la modalità ispettore e utilizzi Alt + clic su un testo per trovarne la chiave e aprire la traduzione. Può revocare il token nel profilo.',
            ],
        ],
    ],
];
