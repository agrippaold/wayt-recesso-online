# AUDIT_REPORT — WAYT Recesso Online v0.2.0

Audit tecnico, di sicurezza e di conformità del plugin **WAYT Recesso Online**
(art. 54-bis Cod. Consumo / Dir. UE 2023/2673). Data: 2026-06-18.
Ambiente di analisi: PHP 8.4.19, PHPCS 3.13.5 (WPCS 3.3.0 + PHPCompatibilityWP),
PHPStan 1.12.33 (livello 5, stub WooCommerce 9.9).

---

## 1. Sintesi esecutiva

Il plugin è **architetturalmente solido e ben strutturato**. I fondamentali di
sicurezza WordPress sono rispettati: nonce e `current_user_can('manage_woocommerce')`
su **tutti** gli handler `admin-post`, `$wpdb->prepare()` con placeholder per i
valori utente, escape sistematico dell'output, verifica dell'`order_key` con
`hash_equals()`, niente dipendenze runtime né chiamate di rete. La conformità
all'art. 54-bis (commi 1-7, art. 49 lett. h, art. 59) è implementata in modo
completo e coerente. **Nessun finding P0**: non sono stati individuati IDOR,
path traversal, SQL injection o XSS sfruttabili.

I problemi principali sono di **due nature**:

1. **Quality gate rossi (P1).** Il codice, con il ruleset committato, **non passa**
   né `composer run lint` (178 errori / 83 warning) né `composer run analyze`
   (9 errori; per giunta PHPStan va in crash con il `--memory-limit=1G` usato in
   CI). I job CI `coding-standards` e `static-analysis` sono quindi **falliti**,
   in contrasto con la "Definition of done" dichiarata in `CLAUDE.md`. La gran
   parte è cosmetica e auto-correggibile (`phpcbf` sistema 196 violazioni su 261,
   in primis le 107 short-array che WordPress vieta).
2. **Idempotenza del flusso di recesso (P1).** `process_confirm()` non ri-verifica
   `already_withdrawn()` e il token anti-doppio-invio è rigenerato a ogni render:
   un re-invio in due sessioni distinte può registrare un doppio recesso e — con
   `auto_refund` attivo (non default) — generare un **doppio rimborso**.

Il resto sono irrobustimenti (P2) e rifiniture (P3): injection di formule nel CSV,
hardening della cartella PDF temporanea, invio dell'avviso a indirizzo
arbitrario, throttling del lookup ospite, memoizzazione delle query di
esclusione, e rifiniture i18n/accessibilità.

### Conteggio finding per severità

| Severità | Definizione | N. |
|---|---|---|
| **P0** | Sicurezza / conformità / perdita dati | **0** |
| **P1** | Bug funzionale o gate di release rosso | **3** |
| **P2** | Robustezza / compatibilità / hardening | **8** |
| **P3** | Stile / nice-to-have | **13** |
| | **Totale** | **24** |

---

## 2. Tabella dei finding

| ID | Area | Sev. | File:riga | Descrizione | Fix consigliato |
|----|------|------|-----------|-------------|-----------------|
| C1 | Correttezza | **P1** | `wayt-recesso-online.php:1028-1059`, `:951`, `:2239-2310` | `process_confirm()` non ri-controlla `already_withdrawn()`; il `wayt_form_token` è rigenerato a ogni render (`:951`), quindi il transient-lock blocca solo il doppio-click dello **stesso** form. Doppio recesso totale/parziale possibile tra re-invii; con `auto_refund='items'` → over-refund (WC non deduplica i line-item refund). | Ri-verificare `already_withdrawn()` per scope `full`; introdurre un lock per-ordine (transient `wayt_recesso_proc_{order_id}`) attorno a registrazione+rimborso; per i parziali dedurre la qty già rimborsata. |
| T1 | Coding standards | **P1** | progetto (3 file) | `composer run lint` fallisce: **178 errori / 83 warning**. Job CI `coding-standards` rosso. 196 auto-fix (`phpcbf`): short-array (107), allineamenti, commenti. Non-auto: nonce-verification noise (29), escape-output (4), prepared-SQL interpolato (5), capability sconosciuta (5), prefissi globali (3), translators comment (6). | `phpcbf` per i 196; poi `phpcs:ignore` mirati con motivazione sui falsi positivi (routing `$_POST`, table-name interpolato, capability WC); aggiungere `custom_capabilities` + esclusioni `FileName`/`FileComment` per il file principale al ruleset. |
| T2 | Static analysis | **P1** | `wayt-recesso-online.php:255,887,942,1268,1271,2256,2303` | `composer run analyze` fallisce: **9 errori** (livello 5). Inoltre PHPStan **va in crash** con `--memory-limit=1G` (valore usato in CI) → job `static-analysis` rosso anche a prescindere dagli errori. | Correggere: cast `(string)` per `esc_attr( $order->get_id() )` (887,942); `instanceof WC_Order_Item_Product` prima di `get_total()` (2256); `wp_insert_post(..., true)` o rimozione del check morto (255); `treatPhpDocTypesAsCertain: false` per il noise stub (1268,2303); ignore mirato sul tipo `send()` (1271). Alzare il `--memory-limit` CI a ≥2G. |
| S1 | Sicurezza | P2 | `wayt-recesso-online.php:1854-1873` | **CSV formula injection**: `handle_export_csv()` scrive `consumer_name`/`reason`/`email` grezzi. Un valore che inizia con `= + - @` viene interpretato come formula all'apertura in Excel/Sheets. | Prefiggere con apice i campi che iniziano con `= + - @ \t \r` prima di `fputcsv`. |
| S2 | Sicurezza/FS | P2 | `wayt-recesso-online.php:2149-2177`; `uninstall.php:73-89` | Il PDF temporaneo è scritto in `uploads/wayt-recesso-tmp/` **senza** `index.html`/`.htaccess`; è web-accessibile nella finestra tra creazione ed eliminazione e persiste se il cleanup non gira (es. fatal durante l'invio). Mitigato dal suffisso random a 8 char. | Creare `index.html` vuoto + `.htaccess deny` (o `web.config`) alla `wp_mkdir_p`; cleanup in `finally`; opzionale cron di pulizia file orfani. |
| S3 | Sicurezza/Privacy | P2 | `wayt-recesso-online.php:1056,1134`; `:1225-1273` | L'avviso (con dichiarazione: ordine, nome, email) è inviato a `$data['email']`, **modificabile** dall'utente nello step dichiarazione. Dopo aver passato il lookup, l'endpoint può recapitare i dati dell'ordine a un indirizzo arbitrario (mail relay / leak) e l'avviso può non raggiungere il vero consumatore. | Vincolare l'avviso alla `billing_email` dell'ordine (o richiedere che il campo coincida), lasciando l'email digitata solo come riferimento. |
| S4 | Sicurezza | P2 | `wayt-recesso-online.php:697-719` | `handle_lookup()` (ospite) non ha **rate limiting**: enumerazione numero-ordine/email e innesco fraudolento del recesso da parte di chi conosce ordine+email (in linea col modello "traccia ordine" di WC, ma senza freni). | Transient di throttling per IP/email (es. N tentativi/ora); log dei tentativi falliti. |
| C2 | Correttezza | P2 | `wayt-recesso-online.php:255` | `wp_insert_post()` con `$wp_error=false` (default) ritorna `int`: il ramo `is_wp_error( $page_id )` è codice morto (confermato da PHPStan). | Passare `true` come secondo argomento e gestire il `WP_Error`, oppure rimuovere il check. |
| C3 | Correttezza | P2 | `wayt-recesso-online.php:2256` | `maybe_refund()` chiama `get_total()` su `WC_Order_Item` generico (ritorno di `get_item()`); il metodo esiste solo su `WC_Order_Item_Product`/`_Fee`/`_Shipping`. Errore PHPStan; a runtime regge solo perché gli item sono di prodotto. | `instanceof WC_Order_Item_Product` (o controllo `method_exists`) prima di `get_total()/get_taxes()`. |
| K1 | Conformità | P2 | `wayt-recesso-online.php:1134-1176` | Se `send_acknowledgement()` ritorna `false`, il flusso mostra comunque "avviso inviato a …": il consumatore è informato di un recapito su supporto durevole che **potrebbe non essere avvenuto** (`ack_sent_at` resta NULL, visibile solo all'admin). | In caso di fallimento: messaggio diverso, offrire il download immediato del PDF/dichiarazione come copia durevole, prevedere retry o alert admin. |
| P1f | Performance | P2 | `wayt-recesso-online.php:1979-2007,2015-2031,458-470` | `is_item_excluded()`/`order_fully_excluded()`/`already_withdrawn()` eseguono `get_post_meta`+`wc_get_product`+`has_term`+`COUNT(*)` per ordine, **senza cache**; su "I miei ordini" (≤20) e nelle email → N+1. | Memoizzazione per-request (static array per product_id e order_id); valutare un meta-cache di esclusione. |
| S5 | Coding standards | P3 | `wayt-recesso-online.php:464,1697,1700,1847` | `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` su `{$table}` (nome tabella da `prefix`+costante, **sicuro**). Il `phpcs:ignore` a `:1698` è mal posizionato (copre la riga sbagliata). | `phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` sulla riga corretta, con commento sul perché è sicuro. |
| S6 | Sicurezza | P3 | `wayt-recesso-online.php:2186` | `absint( $_GET['request'] )` senza `wp_unslash()` (cosmetico; `absint` neutralizza comunque). | Aggiungere `wp_unslash()` per coerenza con lo standard. |
| C4 | Correttezza | P3 | `wayt-recesso-online.php:983-989` | `wayt_reason_select` non è validato contro la lista motivi configurata: si può inviare un motivo arbitrario (solo integrità dato; output sempre escapato). | Validare `reason_select` ∈ `get_reasons_list()` ∪ `{'', '__other__'}`. |
| C5 | Correttezza | P3 | `wayt-recesso-online.php:2287-2301` | Il rimborso `items` fa `restock_items=true`, il rimborso `full` no: comportamento incoerente sul restock. | Uniformare la scelta di restock (o renderla opzione). |
| H1 | HPOS | P3 | `wayt-recesso-online.php:1722` | `get_edit_post_link()` come fallback (già preferito `get_edit_order_url()` quando l'ordine esiste). Accettabile; il fallback è ridondante in HPOS. | Usare solo `get_edit_order_url()` quando l'ordine è caricato. |
| P2f | Performance | P3 | `wayt-recesso-online.php:114,2075-2096` | `inject_blocks_notice` è agganciato a `render_block` (gira per **ogni** blocco del sito). I return anticipati sono economici, ma il callback è invocato moltissime volte. | Gating ulteriore (es. solo se `has_block('woocommerce/checkout')` o contesto checkout). |
| P3f | Performance | P3 | `wayt-recesso-online.php:35,296-302` | Opzioni in un'unica option autoloaded: ok; `opt()` già cache-a per-request. Nessuna azione necessaria, annotato per completezza. | — |
| T3 | Tooling | P3 | `wayt-recesso-online.php:1,22` | `InvalidClassFileName` e `MissingPackageTag` sul file principale del plugin (falsi positivi: è l'entry-point, non un file-classe). | Escludere il file principale da `Squiz.Commenting.FileComment` e `Files.FileName` nel ruleset, o aggiungere `@package`. |
| T4 | Tooling | P3 | `composer.json`, ambiente | `composer.lock` ora committato (build CI riproducibili). Ambiente di dev locale PHP 8.4; la matrice CI (8.0-8.3) e PHPCompatibility (`testVersion 8.0-`) non segnalano incompatibilità. | Nessuna azione; mantenere il lock aggiornato. |
| T5 | Tooling/Build | P3 | `.distignore` | `AUDIT_REPORT.md` e `ROADMAP.md` (e gli artefatti di audit) verrebbero inclusi nello ZIP distribuibile. | Aggiungere `AUDIT_REPORT.md`, `ROADMAP.md`, `CHANGELOG.md`? (valutare), `LICENSE`? (no, va incluso) a `.distignore`; almeno i due report di audit. |
| I1 | i18n | P3 | `wayt-recesso-online.php:1190-1207` (+ altri) | 6 `sprintf( __( '… %s …' ) )` privi del commento `/* translators: */` richiesto da WPCS e utile ai traduttori. | Aggiungere i commenti translators; rigenerare il `.pot`. |
| I2 | Accessibilità | P3 | `wayt-recesso-online.php:1331-1340,567` | Il colore accent è configurabile ma il pulsante usa testo bianco fisso: con un accent chiaro il contrasto è insufficiente (WCAG AA). | Calcolare la luminanza dell'accent e scegliere testo bianco/nero; o validare un contrasto minimo. |
| I3 | Accessibilità | P3 | `wayt-recesso-online.php:724-742,799-892` | Campi obbligatori segnalati solo visivamente (`*`); manca `aria-required`, gestione del focus sugli errori, `role`/`aria-live` per le notice. | Aggiungere `aria-required="true"`, associare gli errori, focus al primo campo invalido. |

---

## 3. Aree verificate — esito di dettaglio

### (a) Sicurezza WordPress — **buono**
- **Sanitizzazione input**: `absint`, `sanitize_text_field`, `sanitize_email`,
  `sanitize_textarea_field`, `sanitize_key`, `wp_unslash` usati correttamente
  (unica eccezione cosmetica S6).
- **Escape output**: `esc_html/attr/url` sistematici; le 4 eccezioni PHPCS
  (`:1343` CSS via `sanitize_css`, `:1593/1596` `wp_dropdown_pages`, `:1762`
  stringa costante) sono **sicure** ma andrebbero silenziate con motivazione.
- **Nonce + capability**: presenti su `handle_export_csv`, `handle_request_action`,
  `handle_pdf_download` (`check_admin_referer` + `manage_woocommerce`) e sul
  flusso form (lookup/declare/confirm). `product_exclusion_save` si appoggia al
  nonce di WooCommerce (documentato).
- **`handle_pdf_download` — niente IDOR/path traversal** ✔: capability + nonce
  per-id, PDF **rigenerato dal DB** (non letto da path utente), filename via
  `sanitize_file_name()`. L'admin è legittimato a vedere tutto il registro.
- **`generate_pdf_file`**: scrittura via `wp_upload_dir()` + `wp_mkdir_p`, suffisso
  random; manca hardening cartella e cleanup garantito (S2).
- **Generatore PDF su input ostile** ✔: `cp1252()` + `esc()` rimuovono control
  char e bilanciano le parentesi; `wrap()` spezza parole lunghe; `/Length`
  calcolata su `strlen` reale → struttura PDF valida anche con UTF-8 multibyte e
  stringhe lunghe (troncamento `(...)` a fine pagina).

### (b) Coding standards + compatibilità
- PHPCS **non pulito** (T1). PHPCompatibility (`testVersion 8.0-`): **nessuna**
  segnalazione → niente sintassi/API incompatibili con PHP 8.0-8.3.
- Nessuna API WP/WC deprecata rilevata; uso corretto del CRUD ordini e di
  `wc_create_refund`.

### (c) Correttezza
- **Scadenza/fusi orari** ✔: `get_deadline()` normalizza in UTC, sposta a
  `wp_timezone()`, `+N giorni`, fine giornata; `is_eligible()` confronta in tz
  sito. Conteggio a favore del consumatore (fine-giornata).
- **`is_eligible`/esclusioni art. 59** ✔: gestite variazioni (risale al padre) e
  categorie (`has_term` sul padre per le variazioni); difesa in profondità anche
  in `collect_posted_data`.
- **`maybe_refund`** ⚠: importi e tasse calcolati correttamente per `items`;
  `full` usa il residuo (`total - total_refunded`, che evita il doppio rimborso
  totale). **Idempotenza insufficiente** sui parziali (C1); `get_total()` su tipo
  generico (C3).
- **Anti-doppio-invio** ⚠: transient-lock legato a un token rigenerato → debole
  (C1).

### (d) HPOS + checkout a blocchi — **buono**
- Compatibilità HPOS dichiarata (`FeaturesUtil::declare_compatibility`); uso del
  CRUD ordini (niente accesso diretto a `postmeta`); `uninstall.php` pulisce anche
  `wc_orders_meta`. `inject_blocks_notice` inietta la nota lato server nel
  checkout a blocchi (`woocommerce/checkout-actions-block`).

### (e) Conformità art. 54-bis — **completa**
| Requisito | Stato | Riferimento |
|---|---|---|
| c.1-2 funzione sempre accessibile + etichetta | ✔ | account/ordine/email/pagina; `button_label` |
| c.3 dichiarazione (nome, identificativo ordine, mezzo elettronico) | ✔ | `render_declaration_step` |
| c.4 comando di conferma separato | ✔ | step `declare`→`confirm` |
| c.6 avviso su supporto durevole + data/ora | ✔ | `send_acknowledgement` + PDF (vedi K1) |
| c.7 validità entro la scadenza | ✔ | `is_eligible` ri-verificata in confirm |
| art. 49 lett. h informativa + modulo tipo | ✔ | `precontractual_text`, `modulo_tipo_url`, `[wayt_recesso_info]` |
| art. 59 eccezioni | ✔ | esclusioni prodotto/categoria/checkbox |

### (f) i18n + accessibilità
- Text domain `wayt-recesso` coerente; `.pot` presente (178 msgid) e allineato
  alle stringhe v0.2.0 verificate. Mancano 6 commenti translators (I1).
- Form con `label`/`for`, `fieldset`/`legend`; lacune ARIA/contrasto (I2, I3).

### (g) Performance
- N+1 sulle esclusioni/registro nelle liste (P1f); `inject_blocks_notice`
  globale (P2f); opzioni in un'unica option con cache per-request (ok, P3f).

---

## 4. Output sintetico degli strumenti

### `php -l` (sintassi) — **PULITO**
```
No syntax errors detected in ./wayt-recesso-online.php
No syntax errors detected in ./uninstall.php
No syntax errors detected in ./includes/class-wayt-recesso-pdf.php
```

### `composer run lint` (PHPCS — WordPress-Extra + PHPCompatibilityWP) — **FALLITO**
```
A TOTAL OF 178 ERRORS AND 83 WARNINGS WERE FOUND IN 3 FILES
PHPCBF CAN FIX 196 OF THESE SNIFF VIOLATIONS AUTOMATICALLY

Top sniff (source summary):
 107  Universal.Arrays.DisallowShortArraySyntax.Found        [auto-fix]
  34  Generic.Formatting.MultipleStatementAlignment...       [auto-fix]
  27  WordPress.Arrays.MultipleStatementAlignment...         [auto-fix]
  21  WordPress.Security.NonceVerification.Missing
  15  Squiz.Commenting.BlockComment.NoNewLine                [auto-fix]
  11  Squiz.Commenting.FunctionComment.SpacingAfterParamType [auto-fix]
   8  WordPress.Security.NonceVerification.Recommended
   6  WordPress.WP.I18n.MissingTranslatorsComment
   5  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
   5  WordPress.WP.Capabilities.Unknown
   4  WordPress.Security.EscapeOutput.OutputNotEscaped
   3  WordPress.NamingConventions.PrefixAllGlobals...
   3  WordPress.PHP.NoSilencedErrors.Discouraged
   …
File: uninstall.php  5E/1W · class-wayt-recesso-pdf.php 2E/3W · wayt-recesso-online.php 171E/79W
```
Nota: nel container l'installer plugin di PHPCS è disabilitato; gli standard
sono stati registrati a mano (`installed_paths`). In CI (plugin abilitati) la
registrazione è automatica.

### `composer run analyze` (PHPStan livello 5) — **FALLITO**
```
[ERROR] Found 9 errors

wayt-recesso-online.php:255   is_wp_error(int<1, max>) sempre false (check morto)
wayt-recesso-online.php:887   esc_attr() expects string, int given
wayt-recesso-online.php:942   esc_attr() expects string, int given
wayt-recesso-online.php:1268  Right side of && is always true (stub)
wayt-recesso-online.php:1271  WC_Emails::send() $headers expects string, array given
wayt-recesso-online.php:1271  WC_Emails::send() $attachments expects string, array given
wayt-recesso-online.php:2256  Call to undefined method WC_Order_Item::get_total()
wayt-recesso-online.php:2303  Negated boolean expression is always false (stub)
```
⚠️ Con `--memory-limit=1G` (valore in `composer.json`/CI) PHPStan va in **crash**
("reached configured PHP memory limit"). L'analisi sopra è stata ottenuta con
`--memory-limit=4G`. → alzare il limite in CI.

---

## 5. Conclusione e raccomandazione di scope per la Fase 3

Fix consigliati **P0+P1** (release-blocking):
- **C1** — idempotenza del recesso (re-check `already_withdrawn` + lock per-ordine).
- **T1** — PHPCS pulito (`phpcbf` + ignore mirati + ritocchi al ruleset).
- **T2** — PHPStan pulito (cast/instanceof/config) + `--memory-limit` CI a ≥2G.

I P2 (S1-S4, C2-C3, K1, P1f) sono irrobustimenti consigliati per la release ma
non bloccanti; i P3 vanno in backlog (vedi `ROADMAP.md`). Se preferisci, posso
includere in Fase 3 anche i P2 a basso rischio e alto valore (S1 CSV-injection,
S2 hardening cartella, C2/C3, P1f memoization) restando entro i vincoli (niente
dipendenze runtime, lint/stan puliti).
