# ROADMAP — WAYT Recesso Online

Proposte di evoluzione del plugin, **ordinate per rapporto valore/rischio**
(prima ciò che rende di più con il minor rischio). Per ognuna: valore utente,
complessità (S/M/L), rischio, impatto sulla conformità all'art. 54-bis.

Vincoli di prodotto da rispettare in ogni proposta: **zero dipendenze runtime**,
**nessuna chiamata di rete** nelle funzioni core, **flusso utente senza
JavaScript**, **compatibilità HPOS e PHP 8.0+**, software **libero e gratuito**.

Legenda complessità: **S** ≤1 g · **M** 2-4 g · **L** ≥1 settimana.

---

## Priorità 1 — Alto valore, basso rischio

### R1. Traduzioni complete it / en / de
> **Stato (0.2.0):** spedite traduzioni attive per **en/de/fr/es** (it = sorgente);
> le altre lingue UE restano in fallback all'italiano o via PR della community,
> per non rimuovere la rete di sicurezza del fallback sulle stringhe legali.

- **Valore**: ★★★★★ — il plugin è IT-first ma la norma è UE; EN e DE aprono i
  mercati con più e-commerce dell'area. Aumenta installazioni e fiducia.
- **Complessità**: S/M (workflow `.po/.mo` + revisione terminologica legale).
- **Rischio**: basso (solo file di lingua; nessun impatto sul codice).
- **Conformità**: ↑ indiretto — l'informativa e l'avviso nella lingua del
  consumatore rafforzano la trasparenza richiesta. *Nota legale*: le traduzioni
  dei testi precontrattuali restano responsabilità del professionista.

### R2. `WC_Email` registrata nel pannello WooCommerce → Email
- **Valore**: ★★★★★ — i merchant si aspettano di gestire l'avviso di ricevimento
  dal pannello Email standard (oggetto/heading/preview/destinatari, template
  override da tema). Sostituisce l'attuale `wp_mail` ad hoc.
- **Complessità**: M (classe che estende `WC_Email`, registrazione via
  `woocommerce_email_classes`, trigger sull'hook `wayt_recesso/confermato`).
- **Rischio**: basso/medio (mantenere il PDF in allegato e i dati durevoli;
  retro-compatibilità con le opzioni email esistenti).
- **Conformità**: ↑ — template più solido e tracciabile per il **supporto
  durevole** (c.6); preview riduce errori di configurazione.

### R3. Suite di test PHPUnit + `wp-env`
- **Valore**: ★★★★☆ — per un plugin di **conformità** la regressione è un costo
  alto; test su scadenze/fusi, eleggibilità, esclusioni art. 59, idempotenza
  rimborso, generatore PDF (parsing con asserzioni sui byte).
- **Complessità**: M (scaffolding `wp-env` + WC + casi core).
- **Rischio**: basso (solo dev-dependencies; nessun impatto runtime).
- **Conformità**: ↑ indiretto — blinda i requisiti di legge contro le regressioni.
  Si integra con i job CI esistenti (`ci.yml`).

### R4. Statistiche nel cruscotto admin
- **Valore**: ★★★★☆ — KPI utili (recessi/mese, % su ordini, totale/parziale,
  tempo medio di gestione, top categorie) nel tab "Richieste".
- **Complessità**: S/M (query aggregate sulla tabella audit, già indicizzata).
- **Rischio**: basso (sola lettura; attenzione alle performance → query cache).
- **Conformità**: neutro (operativo/gestionale).

---

## Priorità 2 — Valore medio-alto, rischio contenuto

### R5. PDF multipagina e brandizzato
- **Valore**: ★★★★☆ — logo del negozio, dati professionista, dichiarazioni
  lunghe su più pagine, eventuale lista articoli completa.
- **Complessità**: M/L — estende il generatore custom (paginazione, oggetti
  `Page` multipli, embedding immagine logo PNG/JPEG senza librerie).
- **Rischio**: medio — il writer PDF è scritto a mano; ogni estensione va
  validata con un parser (pypdf/qpdf) per non rompere la struttura.
- **Conformità**: ↑ — attestato su supporto durevole più completo e professionale
  (resta valida anche la sola email).

### R6. Export PDF in blocco (bulk) dal registro
- **Valore**: ★★★☆☆ — scaricare in un colpo gli attestati di un periodo
  (audit/ispezioni). Naturale complemento dell'export CSV.
- **Complessità**: S/M — ZIP via `ZipArchive` (estensione PHP core diffusa, con
  fallback) generato a stream; selezione per intervallo di date.
- **Rischio**: basso/medio — memoria/tempo su grandi volumi → batch/stream.
- **Conformità**: ↑ operativo — facilita la produzione delle prove.

### R7. Integrazione con i principali plugin "data di consegna"
- **Valore**: ★★★★☆ — la decorrenza corretta del termine per i beni parte dalla
  **consegna**. Riconoscere automaticamente le meta-key dei plugin di consegna
  più diffusi evita configurazioni errate.
- **Complessità**: M — mappatura di meta-key note + filtro `wayt_recesso/...`
  per estendere; rilevamento condizionale (niente dipendenze hard).
- **Rischio**: medio — dipende da formati di terzi; servono fallback robusti.
- **Conformità**: ↑↑ — incide **direttamente** sulla correttezza della scadenza
  (c.7) e quindi sulla validità del recesso.

---

## Priorità 3 — Valore medio, rischio/complessità maggiori

### R8. Blocco Gutenberg per il form di recesso
- **Valore**: ★★★☆☆ — inserimento del form via editor a blocchi invece dello
  shortcode; migliore UX editoriale.
- **Complessità**: L — introduce un toolchain JS/build, in **tensione** con la
  filosofia "no JavaScript / no build".
- **Rischio**: medio — mitigabile con un **blocco dinamico server-rendered**
  (`register_block_type` + `render_callback` che riusa lo shortcode): nessun JS
  lato front, build minimale solo per l'editor. Da valutare se vale la
  dipendenza di build.
- **Conformità**: neutro (la funzione resta identica; cambia solo l'inserimento).

### R9. Endpoint REST per gestionali/ERP
- **Valore**: ★★★☆☆ — esposizione read-only del registro recessi (e webhook su
  `wayt_recesso/confermato`) per sincronizzare gestionali esterni.
- **Complessità**: M/L — `register_rest_route` con permessi, paginazione,
  filtri; eventuale firma HMAC dei webhook.
- **Rischio**: **medio-alto** — nuova superficie d'attacco: serve autenticazione
  robusta (capability/application password/chiavi), rate limiting, e attenzione
  a non esporre dati personali oltre il necessario (minimizzazione GDPR).
- **Conformità**: neutro sull'art. 54-bis; **attenzione privacy/GDPR** sui dati
  del registro.

---

## Traccia trasversale — Hardening (dal backlog dell'audit)

Da pianificare insieme alle feature (molti sono P2/P3 dell'`AUDIT_REPORT.md`),
valore alto sulla qualità, rischio basso:

- **H-S1** anti-CSV-injection nell'export (P2).
- **H-S2** hardening cartella PDF temporanea + cleanup garantito (P2).
- **H-S3** avviso vincolato alla `billing_email` (P2, privacy/anti-relay).
  *Stato:* implementato e poi **rinviato**: l'art. 54-bis c.3 lett. c contempla
  il "mezzo elettronico fornito dal consumatore", quindi vincolare il
  destinatario all'email dell'ordine restringe quella flessibilità. Da
  reintrodurre come **opzione opt-in** previa conferma legale, mantenendo il
  default conforme (email indicata dal consumatore). Il throttling (H-S4) resta
  attivo come misura anti-abuso che non tocca tale flessibilità.
- **H-S4** throttling del lookup ospite (P2).
- **H-P1f** memoizzazione delle query di esclusione/registro (P2, performance).
- **H-I1/2/3** rifiniture i18n e accessibilità (P3): commenti translators,
  contrasto del colore accent, `aria-required`/focus errori.

---

## Quadro sintetico (ordine consigliato)

| # | Feature | Valore | Compl. | Rischio | Conformità |
|---|---------|:------:|:------:|:-------:|:----------:|
| R1 | Traduzioni it/en/de | ★★★★★ | S/M | basso | ↑ |
| R2 | `WC_Email` nel pannello Email | ★★★★★ | M | basso/medio | ↑ |
| R3 | Test PHPUnit + wp-env | ★★★★☆ | M | basso | ↑ (anti-regressione) |
| R4 | Statistiche dashboard | ★★★★☆ | S/M | basso | neutro |
| R5 | PDF multipagina/brandizzato | ★★★★☆ | M/L | medio | ↑ |
| R6 | Export PDF in blocco | ★★★☆☆ | S/M | basso/medio | ↑ operativo |
| R7 | Integrazione "data di consegna" | ★★★★☆ | M | medio | ↑↑ (decorrenza) |
| R8 | Blocco Gutenberg (server-rendered) | ★★★☆☆ | L | medio | neutro |
| R9 | Endpoint REST per gestionali | ★★★☆☆ | M/L | medio-alto | neutro (privacy ⚠) |

> Raccomandazione: aprire la serie **0.3.x** con R1+R2+R3 (massimo valore/rischio
> e allineamento alla conformità), assorbendo nel frattempo l'hardening P2.
> R5/R7 per la 0.4.x; R8/R9 valutate a parte per il loro rischio specifico.
