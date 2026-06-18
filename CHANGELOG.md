# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate qui.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/) e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

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
