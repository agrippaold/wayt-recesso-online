=== WAYT Recesso Online — EU Withdrawal Button for WooCommerce (art. 54-bis) ===
Contributors: wayt
Tags: woocommerce, withdrawal, gdpr, recesso, eu-directive
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conformità all'obbligo UE di recesso elettronico (Direttiva 2023/2673 / art. 54-bis Cod. Consumo) per WooCommerce: pulsante, dichiarazione, avviso su supporto durevole, PDF, registro. Gratis, senza limiti.

== Description ==

Dal **19 giugno 2026** i negozi online che vendono a consumatori nell'Unione Europea devono offrire una **funzione di recesso elettronica** sempre accessibile. È il recepimento italiano (D.Lgs 209/2025, **art. 54-bis del Codice del Consumo**) della **Direttiva (UE) 2023/2673**.

**WAYT Recesso Online** aggiunge a WooCommerce un **pulsante "Recedi dal contratto qui"** conforme, un flusso a due passaggi (dichiarazione → conferma) interamente server-side e senza login, l'invio di un **avviso di ricevimento su supporto durevole** con **PDF attestato**, e un **registro** completo delle richieste. Tutte le funzioni sono **gratuite**: nessun piano a pagamento, nessuna funzione bloccata.

= Funzioni principali =

* Pulsante di recesso sempre accessibile (account, ordine, email, pagina dedicata).
* Dichiarazione con i tre campi di legge (nome, identificativo ordine, mezzo elettronico) + comando di conferma separato.
* Avviso di ricevimento via email con data/ora e **PDF attestato** allegato (generato senza servizi esterni).
* Recesso totale o parziale per singoli articoli.
* Esclusione di prodotti/categorie dal recesso (eccezioni **art. 59**).
* Motivi di recesso configurabili (sempre facoltativi).
* Rimborso WooCommerce opzionale (articoli o totale), disattivato di default.
* Nota precontrattuale su checkout classico e a blocchi.
* Registro/audit con export CSV e note sull'ordine.
* Pannello di conformità con verifica rapida dei requisiti.
* Personalizzazione di colore, CSS, mittente e testo dell'email.
* Compatibile **HPOS**, multisite-aware, pronto per la traduzione.

= Nota legale =

Il plugin implementa la parte **tecnica** dell'art. 54-bis. Condizioni generali di vendita, informativa precontrattuale completa (art. 49) e modulo tipo (Allegato I, parte B) restano responsabilità del professionista e del suo consulente legale.

= English =

Adds a compliant **electronic withdrawal button** to WooCommerce as required across the EU from 19 June 2026 (Directive (EU) 2023/2673 / Italian art. 54-bis). Server-side two-step flow with no customer login, durable-medium acknowledgement email with an attached PDF certificate, partial withdrawal, configurable reasons, art. 59 exclusions, optional refunds, full audit log with CSV export, HPOS-compatible, dependency-free. Every feature is free.

== Installation ==

1. Carica la cartella `wayt-recesso-online` in `/wp-content/plugins/`, oppure installa lo ZIP da **Plugin → Aggiungi nuovo → Carica plugin**.
2. Attiva il plugin.
3. Vai su **WooCommerce → Recesso online** per configurare finestra, decorrenza e testi.

All'attivazione vengono creati la tabella del registro, una pagina "Recesso" con lo shortcode `[wayt_recesso]` e le impostazioni di default.

== Frequently Asked Questions ==

= Il plugin mi rende automaticamente conforme alla legge? =

Copre la parte tecnica (pulsante, dichiarazione, conferma, avviso su supporto durevole, tracciabilità). Le condizioni generali, l'informativa precontrattuale e il modulo tipo restano a cura tua e del tuo legale.

= Da quando decorrono i 14 giorni? =

Dipende dal modello di business. Per i beni il termine parte dalla consegna: se il sito traccia la consegna in un meta dell'ordine, imposta il trigger su "meta di consegna" e indica la chiave.

= Funziona con il checkout a blocchi? =

Sì. La nota precontrattuale viene iniettata lato server anche nel checkout a blocchi, oltre che nel checkout classico.

= È compatibile con HPOS (High-Performance Order Storage)? =

Sì, è dichiarato compatibile con l'archiviazione ordini ad alte prestazioni di WooCommerce.

= Il rimborso è automatico? =

È opzionale e disattivato di default. Se attivo, crea un rimborso in WooCommerce ma non contatta automaticamente il gateway di pagamento: il rimborso effettivo va processato dal pannello o dal PSP.

= Posso tradurlo nella mia lingua? =

Sì. Le stringhe sono pronte per la traduzione e il file `languages/wayt-recesso.pot` è incluso.

== Screenshots ==

1. Pulsante di recesso nell'area "Il mio account".
2. Step di dichiarazione del recesso.
3. Comando di conferma.
4. Registro delle richieste con export CSV e download del PDF.
5. Pannello di conformità.
6. Impostazioni.

== Changelog ==

= 0.2.0 =
* PDF attestato del recesso allegato all'avviso e scaricabile dal registro (generatore interno, senza dipendenze).
* Esclusioni art. 59 per prodotto, categoria o checkbox di prodotto.
* Motivi di recesso configurabili (menu a tendina con «Altro»).
* Rimborso WooCommerce opzionale (articoli o totale), senza contatto col gateway.
* Nota precontrattuale sul checkout a blocchi (iniezione server-side).
* Email avviso personalizzabile: nome mittente, Reply-To, testo introduttivo.
* Personalizzazione grafica: colore principale + CSS.
* File di traduzione `.pot` incluso.

= 0.1.0 =
* Prima release: pulsante di recesso, flusso dichiarazione + conferma, recesso totale/parziale, avviso di ricevimento su supporto durevole, registro + export CSV, stato ordine dedicato, nota precontrattuale al checkout, pannello di conformità, compatibilità HPOS.

== Upgrade Notice ==

= 0.2.0 =
Aggiunge PDF attestato, esclusioni art. 59, rimborso opzionale, motivi configurabili e supporto al checkout a blocchi. Aggiornamento consigliato.
