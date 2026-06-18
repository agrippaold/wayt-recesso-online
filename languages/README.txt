Cartella delle traduzioni (text domain: wayt-recesso).

Per generare il template .pot:

    wp i18n make-pot . languages/wayt-recesso.pot --domain=wayt-recesso

Poi crea le traduzioni (es. it_IT) con Poedit o:

    wp i18n make-json languages/   # se servono JSON per blocchi/JS (non necessari qui)

I file attesi: wayt-recesso-{locale}.po / .mo (es. wayt-recesso-it_IT.mo).
