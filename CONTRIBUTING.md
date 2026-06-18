# Come contribuire

Grazie per l'interesse verso **WAYT Recesso Online**. Il progetto è mantenuto da [WAYT](https://wayt.it) ed è aperto ai contributi della community.

## Segnalare un problema

Apri una [issue](../../issues) usando il template adatto (bug o richiesta funzionalità). Per i bug includi versione di WordPress, WooCommerce e PHP, i passaggi per riprodurre e l'eventuale messaggio di errore.

## Proporre una modifica

1. Forka il repository e crea un branch descrittivo (`feat/...`, `fix/...`, `docs/...`).
2. Mantieni lo stile del codice: **WordPress Coding Standards**. Prima di aprire la PR esegui:
   ```bash
   composer install
   composer run lint     # PHP_CodeSniffer (WordPress-Extra + WooCommerce)
   composer run analyze  # PHPStan
   ```
3. Tutte le stringhe rivolte all'utente vanno internazionalizzate con il text domain `wayt-recesso`. Se aggiungi stringhe, rigenera il `.pot`.
4. Usa commit convenzionali (`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`).
5. Apri la pull request verso `main` descrivendo cosa cambia e perché.

## Principi del progetto

- **Conformità prima di tutto**: ogni modifica al flusso di recesso deve restare allineata all'art. 54-bis del Codice del Consumo.
- **Sicurezza**: sanitizzazione degli input, escape degli output, nonce sulle azioni, controllo delle capability, query preparate.
- **Zero dipendenze runtime**: niente librerie esterne o chiamate di rete per le funzioni core.
- **Compatibilità**: WordPress e WooCommerce recenti, PHP 8.0+, HPOS.

## Licenza dei contributi

Inviando un contributo accetti che venga distribuito sotto licenza **GPL v2 o successiva**, come il resto del progetto.
