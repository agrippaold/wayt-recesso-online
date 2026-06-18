Cartella delle traduzioni (text domain: wayt-recesso).

Lingua sorgente del plugin: ITALIANO (le stringhe in codice sono in italiano).

Traduzioni incluse e ATTIVE (file .mo distribuiti):
    - en_US  (inglese)
    - de_DE  (tedesco)
    - fr_FR  (francese)
    - es_ES  (spagnolo)

Per ogni altra lingua, in assenza di un .mo WordPress usa automaticamente la
stringa sorgente italiana (fallback): nessuna stringa resta non tradotta a metà.

NOTA SULLE TRADUZIONI
Le traduzioni incluse usano la terminologia armonizzata UE del diritto di
recesso e sono fornite come ausilio operativo. NON costituiscono testo legale
vincolante: come indicato nella nota legale del plugin, le condizioni generali,
l'informativa precontrattuale e il modulo tipo restano responsabilita' del
professionista e del suo consulente legale. Si raccomanda una revisione
madrelingua/legale prima dell'uso in produzione, soprattutto per l'etichetta
del pulsante e i testi della dichiarazione/avviso.

Contributi: traduzioni per altre lingue UE sono benvenute via pull request.

Generare/aggiornare il template .pot:

    wp i18n make-pot . languages/wayt-recesso.pot --domain=wayt-recesso

Creare una traduzione: copiare wayt-recesso.pot in wayt-recesso-{locale}.po,
tradurre i msgstr e compilare con:

    msgfmt -c -o wayt-recesso-{locale}.mo wayt-recesso-{locale}.po
