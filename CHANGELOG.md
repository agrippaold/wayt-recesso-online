# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate qui.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/) e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

## [0.2.1] - 2026-06-19

### Corretto
- Throttle del lookup ospite calcolato per ordine ed email anziché per indirizzo IP: dietro reverse proxy o CDN (dove molti utenti condividono lo stesso IP) non vengono più bloccate richieste legittime di clienti diversi (N1).
- PDF: i valori di riga molto lunghi non producono più testo fuori dalla pagina né file sovradimensionati; aggiunta la stessa guardia di fine pagina già usata dal corpo della dichiarazione (N2).

### Modificato
- Aggiunto un backstop anti-doppione a livello di database per il recesso totale: nuova colonna `full_order_id` con indice UNIQUE, rete di sicurezza atomica oltre al lock a transient (race TOCTOU). **Cambio di schema** con migrazione idempotente; DB version 1.0.0 → 1.1.0 (N3).
- Dichiarata compatibilità con WooCommerce fino alla 10.8 e WordPress fino alla 7.0 (verificato su stack reale).

## [0.2.0] - 2026-06-18

### Aggiunto
- PDF attestato del recesso allegato all'avviso di ricevimento e scaricabile dal registro (generatore PDF interno, senza dipendenze esterne).
- Esclusioni dal recesso per le eccezioni dell'art. 59: per ID prodotto, slug categoria o checkbox sulla scheda prodotto.
- Motivi di recesso configurabili (menu a tendina con opzione «Altro»), sempre facoltativi.
- Rimborso WooCommerce opzionale (articoli oggetto di recesso o totale) alla conferma; non contatta automaticamente il gateway.
- Nota precontrattuale anche sul checkout a blocchi, iniettata lato server senza build JavaScript.
- Personalizzazione dell'email di avviso: nome mittente, Reply-To, testo introduttivo.
- Personalizzazione grafica: colore principale e CSS personalizzato.
- File di traduzione `languages/wayt-recesso.pot`.

## [0.1.0] - 2026-06-17

### Aggiunto
- Prima release: pulsante di recesso, flusso dichiarazione + conferma, recesso totale/parziale, avviso di ricevimento su supporto durevole, registro con export CSV, stato ordine dedicato, nota precontrattuale al checkout, pannello di conformità, compatibilità HPOS.
