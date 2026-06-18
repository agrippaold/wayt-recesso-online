<!--
  WAYT Recesso Online — WooCommerce withdrawal button (EU Directive 2023/2673 / art. 54-bis)
  Maintained by WAYT — https://wayt.it
-->

<h1 align="center">WAYT Recesso Online</h1>

<p align="center">
  <strong>Il pulsante di recesso per WooCommerce conforme all'art. 54-bis del Codice del Consumo.</strong><br>
  Obbligo UE in vigore dal <strong>19 giugno 2026</strong> (Direttiva UE 2023/2673). Gratuito, completo, senza piani a pagamento.
</p>

<p align="center">
  <em>The EU electronic withdrawal button for WooCommerce — Directive (EU) 2023/2673. Free and full‑featured, no paywall.</em>
</p>

<p align="center">
  <img alt="License" src="https://img.shields.io/badge/license-GPLv2%2B-blue.svg">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg">
  <img alt="WooCommerce" src="https://img.shields.io/badge/WooCommerce-7.0%2B-96588a.svg">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.0%2B-777bb3.svg">
  <img alt="HPOS" src="https://img.shields.io/badge/HPOS-compatible-2e7d32.svg">
</p>

---

## Perché esiste

Dal **19 giugno 2026** i negozi online che vendono a consumatori nell'Unione Europea devono mettere a disposizione una **funzione di recesso elettronica**: un pulsante sempre accessibile, etichettato *"Recedere dal contratto qui"*, che porti a una dichiarazione online e generi un avviso di ricevimento su supporto durevole. È il recepimento italiano (D.Lgs 209/2025, **art. 54-bis del Codice del Consumo**) della **Direttiva UE 2023/2673**.

Sul mercato la soluzione "pronta" arriva quasi sempre come app a canone mensile con le funzioni utili chiuse dietro il piano più caro. **WAYT Recesso Online** nasce per dare alla community WooCommerce italiana lo stesso risultato — anzi, più completo — **gratis e senza limiti di funzionalità**.

> **Nota legale.** Questo plugin implementa la parte **tecnica** dell'art. 54-bis. Le condizioni generali di vendita, l'informativa precontrattuale completa (art. 49) e il modulo tipo di recesso (Allegato I, parte B) restano responsabilità del professionista e del suo consulente legale. Il plugin fornisce i ganci per pubblicarli, non li redige.

## Cosa fa

- **Pulsante "Recedi dal contratto qui"** sempre accessibile: area *Il mio account*, dettaglio ordine, email d'ordine e pagina pubblica dedicata.
- **Flusso a due passaggi** — dichiarazione → comando di conferma separato — interamente **server‑side, senza JavaScript** e senza login del cliente.
- **Avviso di ricevimento su supporto durevole** via email, con il testo della dichiarazione, **data e ora** di trasmissione e **PDF attestato allegato** (generato senza servizi esterni).
- **Recesso totale o parziale** con selezione dei singoli articoli.
- **Eccezioni art. 59**: escludi dal recesso prodotti o categorie (beni su misura, sigillati aperti, deperibili…).
- **Motivi di recesso configurabili** (menu a tendina con opzione «Altro»), sempre facoltativi.
- **Rimborso WooCommerce opzionale** (articoli o totale) alla conferma — disattivato di default.
- **Nota precontrattuale** sia sul checkout **classico** sia su quello **a blocchi**.
- **Registro/audit** completo con **export CSV** e note private sull'ordine.
- **Pannello di conformità** con check rapido dei requisiti dell'art. 54-bis.
- **Personalizzazione**: colore principale, CSS, mittente/Reply‑To e testo dell'email.
- **Compatibile HPOS**, multisite‑aware, pronto per la traduzione (file `.pot` incluso).

## 100% gratuito

Tutte le funzioni elencate sono incluse. Nessun piano *Pro*, nessuna funzione bloccata, nessun branding "powered by". Rilasciato sotto licenza **GPL v2 o successiva**.

| | Tipiche app a canone | WAYT Recesso Online |
|---|---|---|
| Pulsante + modulo conforme | ✓ | ✓ |
| Avviso di ricevimento via email | ✓ | ✓ |
| Recesso parziale per articoli | piano a pagamento | ✓ |
| Motivi configurabili | piano a pagamento | ✓ |
| Esclusione articoli (art. 59) | piano top | ✓ |
| PDF attestato per ogni recesso | piano top | ✓ |
| Rimborso automatico | piano top | ✓ |
| Registro audit + export | piano top | ✓ |
| **Prezzo** | da ~9–25 $/mese | **gratis** |

## Requisiti

- WordPress ≥ 6.2
- WooCommerce ≥ 7.0 (testato fino alle versioni recenti)
- PHP ≥ 8.0

## Installazione

**Da release (consigliato per chi non usa Git)**

1. Scarica `wayt-recesso-online.zip` dall'ultima [Release](../../releases).
2. WordPress → **Plugin → Aggiungi nuovo → Carica plugin** → seleziona lo ZIP → **Installa** → **Attiva**.

**Da sorgente**

```bash
cd wp-content/plugins
git clone https://github.com/agrippaold/wayt-recesso-online.git
# attiva da Plugin nel pannello WordPress
```

All'attivazione vengono creati automaticamente la tabella del registro, una pagina **"Recesso"** con lo shortcode `[wayt_recesso]` e le impostazioni di default. La configurazione è in **WooCommerce → Recesso online**.

## Configurazione rapida

| Impostazione | Default | Note |
|---|---|---|
| Giorni di recesso | `14` | Durata della finestra. |
| Decorrenza | Completamento ordine | Alternative: pagamento, creazione, **meta data di consegna**. |
| Stati ordine ammessi | In lavorazione, Completato | Solo questi stati mostrano il pulsante. |
| PDF attestato | Attivo | Allegato all'avviso + scaricabile dal registro. |
| Rimborso automatico | Disattivato | `off` / articoli / totale. Non contatta il gateway. |
| Esclusioni art. 59 | — | Per ID prodotto, slug categoria o checkbox prodotto. |
| Colore / CSS | `#111111` | Personalizzazione grafica del form. |

> La **decorrenza** dei 14 giorni dipende dal modello di business: per i beni il termine parte dalla **consegna**. Se il sito traccia la consegna in un campo dedicato, imposta il trigger su "meta di consegna" e indica la chiave corretta.

## Shortcode

- `[wayt_recesso]` — la funzione di recesso completa (pagina dedicata, creata in automatico).
- `[wayt_recesso_info]` — nota informativa precontrattuale + link al modulo tipo.

## Conformità all'art. 54-bis (sintesi)

| Requisito | Comma | Come è coperto |
|---|---|---|
| Funzione sempre visibile, etichetta "Recedere dal contratto qui" | c. 1–2 | Pulsante in account/ordine/email + pagina pubblica, attivo solo entro la finestra. |
| Dichiarazione: nome, identificativo ordine, mezzo elettronico | c. 3 | Step "dichiarazione" con i tre campi di legge. |
| Comando di conferma separato | c. 4 | Secondo step con pulsante dedicato, nonce e anti‑doppio‑invio. |
| Avviso di ricevimento su supporto durevole (contenuto + data/ora) | c. 6 | Email + PDF attestato, con data e ora di trasmissione. |
| Validità se trasmesso entro la scadenza | c. 7 | Flusso disponibile solo nella finestra; data/ora registrate. |
| Informativa precontrattuale + modulo tipo | art. 49 lett. h | Nota al checkout (classico e a blocchi) + shortcode + URL modulo tipo. |
| Eccezioni al recesso | art. 59 | Esclusione per prodotto/categoria. |

La mappatura estesa è nel file [`readme.txt`](readme.txt).

## Hook per sviluppatori

```php
// Dopo una conferma di recesso andata a buon fine.
do_action( 'wayt_recesso/confermato', int $request_id, WC_Order $order, array $data );

// Allegati all'avviso di ricevimento (oltre al PDF attestato).
apply_filters( 'wayt_recesso/attachments', array $files, WC_Order $order, array $data );
```

## Contribuire

Issue e pull request sono benvenute: vedi [`CONTRIBUTING.md`](CONTRIBUTING.md). Per le segnalazioni di sicurezza, [`SECURITY.md`](SECURITY.md).

## English summary

**WAYT Recesso Online** adds a fully compliant **electronic withdrawal button** to WooCommerce, as required across the EU from **19 June 2026** under **Directive (EU) 2023/2673** (implemented in Italy as art. 54-bis of the Consumer Code). It provides an always‑accessible *"Withdraw from the contract here"* button, a two‑step server‑side declaration → confirmation flow with no customer login, a **durable‑medium acknowledgement email with an attached PDF certificate**, partial withdrawal, configurable reasons, art. 59 product exclusions, optional WooCommerce refunds, a full audit log with CSV export, and a compliance checklist. It is **HPOS‑compatible** and **dependency‑free**. Unlike the typical paid apps, **every feature is free** under the GPLv2‑or‑later license. UI strings ship in Italian and the plugin is translation‑ready (a `.pot` template is included — translations welcome).

## Licenza

[GPL v2 o successiva](LICENSE). Sei libero di usarlo, modificarlo e ridistribuirlo, anche commercialmente, mantenendo la stessa licenza.

---

<p align="center">
  Realizzato e mantenuto da <a href="https://wayt.it"><strong>WAYT</strong></a> — performance digitale e sviluppo WordPress/WooCommerce per le PMI.<br>
  Se ti è utile, lascia una ⭐ al repository.
</p>
