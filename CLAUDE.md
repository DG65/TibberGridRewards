# Hinweise für die Arbeit an diesem Repository

## Der Modul-Verbund

Dieses Repo gehört zu einer Gruppe eigenständiger IP-Symcon-Module, die zusammenwirken. An
ihnen wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet, die sich auf
gemeinsame Regeln und dokumentierte Schnittstellen geeinigt haben.

| Modul | Rolle | Repo / lokale Kopie | Vertrag zu uns |
|---|---|---|---|
| **Tibber Grid Rewards** (dieses Repo) | Erlös-/Vermarktungssignale | `DG65/TibberGridRewards` | — |
| **InverterHub** | Wechselrichter messen, darstellen, steuern | `DG65/InverterHub` · `../InverterHub` | keiner; konsumiert unsere Statusvariablen |
| **MeterHub** | Energiezähler (Modbus TCP) | `DG65/MeterHub` · `../MeterHub` | keiner |
| **Prognose** (EnergiePrognose) | PV- und Verbrauchsprognose | `DG65/Prognose` · `../Prognose` | keiner |
| **HeishaMon** | Panasonic-Wärmepumpe | `DG65/HeishaMon` | keiner |
| **StromGedacht** | Netzampel (TransnetBW) | `DG65/StromGedachtWidget` | Vorbild für unsere `DataActions`-Regel-Engine + Kachel-Editor |
| **Tessie** | Tesla-Fahrzeuge (Wallbox-SOC) | `DG65/Tessie` | keiner |
| **EMS** | Entscheidungslogik / Batteriefahrweise | EMS-Repo · `../EMS` | konsumiert unsere Statusvariablen (`Delivering`, `GridRewardMode`, `GridRewardWallboxRequest`) und `TIBBERGR_GetPriceCurve()` (Preiskurve, optional) |
| **ChargerHub** | Wallboxen (Modbus TCP) | `DG65/ChargerHub` | keiner (Stand: Gerüst v0.1.0, noch ohne Fachlogik) |
| **MigrationsHub** | Migration von Bestandsgeräten/Archivwerten | `DG65/MigrationsHub` | keiner (Stand: Gerüst v0.1.0, noch ohne Fachlogik) |

### Grundregel: jedes Modul bleibt eigenständig — und das wird geprüft

Kein Modul darf ein anderes voraussetzen. Kopplungen liegen hinter `function_exists(...)`
bzw. `IPS_ModuleExists(...)`; fehlt der Partner, entfallen nur Zusatzfunktionen — es darf
nichts brechen.

**Das ist kein Stilthema.** Der Aufruf einer nicht vorhandenen Funktion ist in PHP ein
**Fatal Error**. Das oft vorangestellte `@` unterdrückt ihn **nicht** — es unterdrückt nur
Warnungen. Fehlt der Wächter und ist das Partnermodul nicht installiert, bricht die Instanz
hart ab, statt die Zusatzfunktion wegzulassen.

Damit die Zusage jederzeit belegbar ist statt nur behauptet:

```
php .tools/check-standalone.php
```

(Ordner bewusst `.tools` mit führendem Punkt: Die Symcon-Store-Prüfung scannt jeden
Top-Level-Ordner als Modul-Kandidaten und meldet einen Fehler, wenn dort kein `module.json`
liegt — ein sichtbarer `tools/`-Ordner hat genau das ausgelöst. Versteckte Ordner werden vom
Scanner übersprungen.)

Der Prüfer durchsucht alle PHP-Dateien nach Aufrufen fremder Modulpräfixe (`MHUB_`, `PVF_`,
`HEISHA_`, `SGW_`, `TESSIE_`, `EMS_`, `GWET_`) und meldet jeden, der **in seiner aufrufenden
Funktion** keinen passenden `function_exists()`-Wächter hat. Kommentare und Zeichenketten
werden vorher entfernt, damit dokumentierte Beispielaufrufe keinen Fehlalarm auslösen.
Rückgabewert 0 = sauber, 1 = mindestens eine ungesicherte Stelle (für CI geeignet). Aktuell
(Stand 2.0.0) ruft dieses Modul keine fremden Modulfunktionen auf — die Prüfung läuft trotzdem
mit, damit das bei künftigen Kopplungen (z. B. `SGW_GetState()`) sofort auffällt, falls der
Wächter vergessen wird. Kommt ein Partnermodul dazu, dessen Präfix in `FOREIGN_PREFIXES`
ergänzen.

### Steuerhoheit: nur das EMS regelt die Batterie

Wichtigste Absprache im Verbund, weil sie sonst schwer auffindbare Fehler erzeugt:

1. **Das EMS ist die einzige Steuerhoheit auf der Batterie.** Es entscheidet.
2. **InverterHub ist reine Ausführungsschicht** — es setzt um, es entscheidet nicht.
3. **Signalmodule (wir eingeschlossen) steuern nicht direkt durch.**

Hintergrund: Dieses Modul hat eine generische „Wenn→Dann"-Regel-Engine (`DataActions`), mit
der sich **ohne eine Zeile Code** Regeln auf beliebige Zielvariablen legen ließen — auch auf
InverterHub- oder GoodweET-Variablen. Dann plant das EMS ein ECO-Fenster, während parallel
eine Tibber-Regel eine Ladevorgabe schreibt — zwei Regler auf derselben Batterie, beide
„korrekt". Deshalb: Regeln in diesem Modul zeigen auf Anzeige-/Automatisierungsziele, **nicht**
auf die Batteriesteuerung selbst; die Statusvariablen (`Delivering`, `GridRewardMode`,
`GridRewardWallboxRequest`) sind der vorgesehene Weg, über den das EMS die Signale konsumiert.

### Zusammenarbeit der Sitzungen

Die Sitzungen **teilen kein Gedächtnis**. Was einer gesagt wird, wissen die anderen nicht — der
Abgleich funktioniert ausschließlich über ausdrückliche Nachrichten. Es gibt **keine Hierarchie**
zwischen ihnen; die Zuständigkeiten oben sind Absprache, nicht Rangordnung. Auftraggeber ist
der Repo-Eigentümer.

Bei Anliegen, die mehrere Module betreffen, wird die zuständige Sitzung angesprochen und
gebeten, es weiterzureichen — nicht im fremden Repo selbst gearbeitet.

**Koordinationsmodell (Stand 2026-07-21, von der EMS-Sitzung mitgeteilt):** Dietmar ist der
zentrale Ansprechpartner für den gesamten Verbund; die übergreifende Koordination läuft über ihn.
Modul-Sitzungen werden von ihm nur bei modulspezifischen Aufgaben direkt angesprochen.

### Sprachregel: alles Nutzersichtbare auf Deutsch (Anweisung Dietmar, 2026-07-22)

Keine Anglizismen und keine englischen Ausdrücke/Sätze in dem, was der Nutzer zu sehen bekommt:
Formularbeschriftungen, Hinweis- und Warntexte, Fehler- und Statusmeldungen, Rückgabe-Texte,
Log-/Debug-Meldungen, Variablen- und Profilnamen, README. Ersetzungen z. B. Dry-Run → Probelauf,
Link → Verknüpfung, Event → Ereignis, Button → Schaltfläche, Scan → Suche.

**Ausgenommen (Umbenennen bräche Verträge):** Bezeichner im Code (Klassen, Methoden, Properties und
vor allem **Idents** — Idents sind API und werden nie umbenannt), feststehende IP-Symcon-/
Technikbegriffe sowie Feldnamen der Gegenstelle. Hier konkret unangetastet:

- die Vertragsfelder von `TIBBERGR_GetPriceCurve` (`start`/`end`/`price`/`basis`/`netzentgelt`/
  `level`/`level_tibber`) und ein künftiges `GetActiveControls` — Feldnamen englisch,
  Anzeigetexte deutsch;
- die Rohwerte der Tibber-API (`Delivering`, `excess`/`shortage`), insbesondere der **Inhalt der
  Variable `StateReason`**: Der ist ausdrücklich als Rohwert dokumentiert, `DetermineMode()` wertet
  ihn aus, und Nutzerregeln vergleichen darauf — eine Eindeutschung wäre ein stiller Bruch.

**Wo solche Zustände angezeigt werden, wird deutsch beschriftet.** Umgesetzt: `State` (über
`Translate()`), das Modus-Band der Kachel, und `CurrentPriceLevel` (`TranslatePriceLevel()`, 1:1 —
fünf Tibber-Stufen bleiben fünf, das Zusammenfassen/Bewerten bleibt Sache des EMS).

Zwei Praxishinweise (aus der Umstellung von InverterHub, gelten auch hier):

1. **Nach dem Ersetzen den Diff durchsehen, kein blindes `replace_all`.** Ändert sich das Genus,
   bricht der Satz: aus „keinen abhängigen Dropdown" wird „**keine abhängige** Auswahlliste", aus
   „eine Checkbox, **die** …" wird „ein Häkchen, **das** …".
2. **Fachbegriffe und Produktnamen nicht überdehnen.** Stehen bleiben: „Grid Rewards" (Produktname),
   WebSocket, API, Webfront, Tile, Modbus. Ersetzt wurden Button → Schaltfläche, Dropdown →
   Auswahlliste, Checkbox → Häkchen, Push → Meldung, Slot → Zeitabschnitt.
   **Sonderfall Token:** Im Verbund gilt Token → Zugangsschlüssel. Bei uns heißt das Ding auf
   Tibbers Website aber wörtlich „Personal Access Token" — wer dort etwas anderes sucht, findet es
   nicht. Deshalb steht im Formular „Zugangsschlüssel (bei Tibber ‚Personal Access Token')": deutsch
   zuerst, Originalbezeichnung als Suchhilfe dahinter. Diese Kombination bitte beibehalten.
3. Formularelementtypen (`"type": "Button"`) sind Code, keine Anzeigetexte — unangetastet lassen.

### Emojis: erwünscht, wo sie Nutzen stiften (Entscheidung Dietmar, 2026-07-23)

Kanonischer Wortlaut (Steward: MigrationsHub) — ersetzt jede frühere „keine Emojis"-Regel:

- **Erwünscht, wo sie Nutzen stiften:** (1) als **Panel-Icon** — ein Zeichen am Anfang einer
  ExpansionPanel-Überschrift (📖🔌📊), Ersatz fürs fehlende `icon`-Feld; (2) als
  **Status-/Aufmerksamkeitssymbol** (✅❌⚠️💡ℹ️) dort, wo etwas beim Lesen Aufmerksamkeit erfordert
  oder herausgestellt werden soll (Status, Warnungen, wichtige Hinweise) — sie bringen Fokus und
  Auflockerung.
- **Faktenlage:** Kein Symcon-Store-Review hat Emojis je beanstandet; die frühere „keine Emojis"-Regel
  war präventiv und ist aufgehoben.
- **Beobachtungsklausel:** Sollte je ein Stable-Review Emojis bemängeln, entscheidet der Verbund neu
  (Rückfall: gemeinsam emoji-frei).

Konsequenz hier: Unsere bestehenden Emoji-Captions (📖 Doku, 🎨 Statusfarben, 🧾 Tarif & Netzentgelt,
⚠️/ACHTUNG-Hinweise …) bleiben und sind ausdrücklich erwünscht — kein Ausbau.

## Öffentlicher Vertrag: Preiskurve (`TIBBERGR_GetPriceCurve`, seit 2.1.0)

Zweite, von Grid Rewards komplett unabhängige Anbindung: die **offizielle** Tibber-API (Personal
Access Token, `https://api.tibber.com/v1-beta/gql`), NICHT die App-API. Grund für den eigenen Token
statt Weiterreichen aus TibberV2: bewusste Entkopplung von einem Fremdmodul, dessen Pflegezustand
wir nicht beeinflussen können (InverterHub hat dort eine 146 Tage alte Preisdatum-Variable gefunden —
Beleg genug).

```php
TIBBERGR_GetPriceCurve(int $id): array
// Liste, aufsteigend nach 'start'. Lücken sind zulässig - keine lückenlose Abdeckung annehmen.
// [[ 'start'=>int (inklusiv), 'end'=>int (EXKLUSIV, Intervall [start,end)),
//    'price'=>float (ct/kWh brutto, NICHT EUR/kWh),
//    'basis'=>'endkunde', 'netzentgelt'=>'enthalten',
//    'level'=>null, 'level_tibber'=>string|null,
//    // nur wenn Formular "Tarif & Netzentgelt" aktiv (seit 2.4.0):
//    'components'=>['spot','beschaffung','netzentgelt','steuernAbgaben'] (ct/kWh NETTO),
//    'vat'=>float (%), 'tibberEnergy'=>float|null, 'tibberTax'=>float|null ], …]
```

- **`basis`/`netzentgelt` sind konstant**, weil dieses Modul ausschließlich den vollständigen
  Tibber-Endkundenpreis liefert (inkl. evtl. zeitvariabler Netzentgelte wie §14a Modul 3) — nie einen
  reinen Spotpreis. Für Nicht-Tibber-Kunden ist das explizit NICHT unsere Baustelle; das wäre ein
  eigenes, netzbetreiberspezifisches Overlay-Modul (Werte dürfen nirgends fest im Code stehen, ~850
  Netzbetreiber in Deutschland).
- **`level` ist bewusst IMMER `null`** (Korrektur nach MeterHub-Review, 2.1.1): `CHEAP`/`NORMAL`/
  `EXPENSIVE` wäre Tibbers eigenes, aus einem gleitenden Mittel berechnetes Vokabular — eine
  Spotpreis-Quelle hat das nicht und müsste es nachbilden. Zwei verschiedene Berechnungen im selben
  Feld hätten bei identischer Preislage je nach Quelle zu unterschiedlichen EMS-Entscheidungen führen
  können, ohne dass es auffällt. Die Einstufung ist deshalb Sache des EMS, einheitlich für alle
  Quellen (Steuerhoheits-Regel: Entscheidungen gehören dorthin, nicht in die Signalquelle). Tibbers
  **unveränderter** Rohwert (5-stufig, `VERY_CHEAP…VERY_EXPENSIVE`) bleibt separat in
  `level_tibber` erhalten — dort NICHT auf 3 Stufen abbilden oder sonst verändern, sonst entsteht
  wieder eine zweite Taxonomie.
- **`end` ist exklusiv**, berechnet aus dem Abstand zum nächsten Slot (Tibber liefert je nach Tarif
  Stunden- oder Viertelstunden-Werte, kein explizites Dauer-Feld): bei aneinandergrenzenden Slots ist
  `end` des einen exakt gleich `start` des nächsten. Der letzte Slot übernimmt die Dauer des
  vorherigen (Fallback 3600 s bei nur einem Slot). Abfrage beim Konsumenten also
  `now >= start && now < end`.
- **`components` (seit 2.4.0, Rechnungsprüfung, EMS-abgestimmt):** Zerlegung des Endkundenpreises in
  {spot, beschaffung, netzentgelt, steuernAbgaben} (ct/kWh NETTO), plus `vat` (%) und Tibbers rohe
  Zweiteilung `tibberEnergy`/`tibberTax` als **unabhängige Kreuzprobe-Anker**. Nur vorhanden, wenn im
  Formular „Tarif & Netzentgelt" aktiviert. Wie bei `level`: `components` ist eine **Rekonstruktion**
  aus der Config, `price` bleibt Tibbers maßgebliche Zahl — Abweichung = Prüfbefund, kein Bug. `spot`
  wird als Rest gebildet (Summe der components = price_netto per Konstruktion) und **kann negativ
  sein** (echte negative Börsenpreise) — nicht „reparieren". Netzgebiets-Werte stehen im Formular,
  bundesweite Sätze (Stromsteuer/Offshore/KWK/§19/MwSt) als Konstanten `TAX_*` im Modul (jährlich
  pflegen, `TAX_STAND`). FIXE Positionen (Netz-Grundpreis, §14a-Reduzierung, Tibber-Grundgebühr)
  gehören NICHT in `components` (nicht per kWh) — sie kommen über `TIBBERGR_GetTariffConfig()` (zweiter
  Vertrag, seit 2.4.0): fixe Positionen für die Monats-Endabrechnung, jeweils Jahresbetrag + /365-
  Tageswert, plus `campaigns` (befristete Rabatte, `amountMonth` signiert, `validFrom`/`validUntil` als
  Unix-Zeitstempel wie start/end, 0 = offen). Die Monats-Endabrechnung baut das **EMS** (multipliziert
  `components` × geeichte Slot-Energie aus dem Zähler, addiert die fixen Positionen, wendet die §14a-
  „nicht < 0"-Kappung an — die hängt vom Tages-Netzentgelt ab, das wir nicht kennen). Live gegen
  Dietmars Anlage verifiziert: `spot` deckt sich mit `tibberEnergy` auf ~0,01 ct/kWh über alle
  Tarifstufen; Modell trifft die Juni-Rechnung (90,58 € netto) exakt.
- Läuft unabhängig vom Grid-Rewards-Status: eigene Properties (`PriceApiToken`, `PriceHomeID`), eigene
  Attribute (`PriceHomes`, `PriceCache`), eigener Timer (`PriceRefresh`, alle 20 Minuten — häufig genug,
  um die Preise für den Folgetag zeitnah zu übernehmen, sobald Tibber sie veröffentlicht, üblicherweise
  zwischen 13 und 14 Uhr). Ein fehlender/ungültiger Token darf den Grid-Rewards-Teil nicht
  beeinträchtigen und umgekehrt — siehe `ApplyPriceChanges()`, getrennt von den Active/Email/Password-
  Prüfungen in `ApplyChanges()`.
- Signaturänderungen an `GetPriceCurve()` ankündigen (aktuell konsumiert von EMS); interne Umbauten
  (z. B. Cache-Format) sind frei, solange die Rückgabestruktur stabil bleibt.

## Branch-Modell: `beta` ist der aktive Entwicklungszweig

Alle DG65-Modulrepos bekommen einen einheitlichen `beta`-Zweig, damit sich neue Stände ohne
Store-Review schnell per GitHub-URL installieren lassen. **Entwicklung und Veröffentlichung
laufen künftig auf `beta`**, `main` bleibt der geprüfte, im Symcon Module Store gelistete Stand.

- „Kein Review nötig" gilt nur für Installationen per GitHub-URL (Repo-Eigentümer und sein
  Testerkreis, Modulverwaltung auf `beta` umgestellt). Fremde Nutzer über den Store beziehen
  weiterhin `main`.
- `main` wird **nicht** eigenmächtig aus `beta` nachgezogen — das entscheidet der
  Repo-Eigentümer (z. B. vor einer neuen Store-Review).
- Versionsbump/Changelog-Einträge auf `beta` normal weiterführen wie bisher.

## Regeln fürs Committen

- **Kein `git add -A`.** Nur die Dateien stagen, die man selbst geändert hat.
- **Vor dem Commit `git pull --rebase origin beta`.**
- **Vor dem Committen prüfen**, ob im Arbeitsbaum fremde Änderungen liegen (`git status`,
  `git diff`) — wenn ja, nicht mitcommitten.
- Öffentliche Funktionen (`TIBBERGR_*`) sind der Vertrag nach außen (aktuell konsumiert vom
  EMS). Signaturänderungen ankündigen; interne Umbauten sind frei, solange die Rückgabestruktur
  stabil bleibt.
