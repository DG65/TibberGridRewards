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

## Öffentlicher Vertrag: Preiskurve (`TIBBERGR_GetPriceCurve`, seit 2.1.0)

Zweite, von Grid Rewards komplett unabhängige Anbindung: die **offizielle** Tibber-API (Personal
Access Token, `https://api.tibber.com/v1-beta/gql`), NICHT die App-API. Grund für den eigenen Token
statt Weiterreichen aus TibberV2: bewusste Entkopplung von einem Fremdmodul, dessen Pflegezustand
wir nicht beeinflussen können (InverterHub hat dort eine 146 Tage alte Preisdatum-Variable gefunden —
Beleg genug).

```php
TIBBERGR_GetPriceCurve(int $id): array
// [[ 'start'=>int, 'end'=>int, 'price'=>float (ct/kWh brutto),
//    'basis'=>'endkunde', 'netzentgelt'=>'enthalten',
//    'level'=>'CHEAP'|'NORMAL'|'EXPENSIVE'|null ], …]
```

- **`basis`/`netzentgelt` sind konstant**, weil dieses Modul ausschließlich den vollständigen
  Tibber-Endkundenpreis liefert (inkl. evtl. zeitvariabler Netzentgelte wie §14a Modul 3) — nie einen
  reinen Spotpreis. Für Nicht-Tibber-Kunden ist das explizit NICHT unsere Baustelle; das wäre ein
  eigenes, netzbetreiberspezifisches Overlay-Modul (Werte dürfen nirgends fest im Code stehen, ~850
  Netzbetreiber in Deutschland).
- **`level`** ist Tibbers 5-stufiges Schema (`VERY_CHEAP…VERY_EXPENSIVE`), auf 3 Stufen abgebildet
  (`MapPriceLevel()`); `null`, wenn Tibber für einen Slot kein Level liefert.
- **`end`** wird aus dem Abstand zum nächsten Slot berechnet (Tibber liefert je nach Tarif Stunden-
  oder Viertelstunden-Werte, kein explizites Dauer-Feld); der letzte Slot übernimmt die Dauer des
  vorherigen.
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
