# Changelog

## 2.3.0

- **Neu: viertelstündliche Preise** (Panel „💶 Preiskurve“ → „Preisauflösung“, Standard „Automatisch“).
  Tibber rechnet in Deutschland seit dem 1.10.2025 viertelstündlich ab, liefert die 15-Minuten-Preise
  über die API aber nur auf ausdrückliche Anforderung (`priceInfo(resolution: QUARTER_HOURLY)`). Bisher
  fragte das Modul ohne diese Angabe ab und bekam dadurch **stündliche** Werte – für viertelstündlich
  abgerechnete Kunden eine spürbare Ungenauigkeit. An der Live-Anlage gemessen: der Stundenwert ist ein
  Mittel, der echte Viertelstundenpreis wich im Test um rund 1,3 ct/kWh ab – bei einer Rechnungsprüfung
  nicht vernachlässigbar.
  - „Automatisch“ fragt zuerst viertelstündlich ab und fällt auf Stundenwerte zurück, wenn der Tarif
    keine 15-Minuten-Preise liefert (ältere oder ausländische Tarife). Manuelles Erzwingen beider
    Auflösungen ist möglich.
  - Downstream unverändert: Der Vertrag `GetPriceCurve()` trug die Slot-Breite ohnehin über `end-start`,
    der slotgenaue Preiswechsel (PriceTick) und die Archivierung nehmen 15-Minuten-Slots automatisch mit.

## 2.2.5

- **Fix: `GetPriceCurve()` konnte bei dauerhaft scheiterndem Abruf die Visualisierung ausbremsen.**
  Der Zeitstempel im Zwischenspeicher wird nur bei **Erfolg** gesetzt – schlug der Abruf dauerhaft
  fehl (Netz weg, Zugangsschlüssel ungültig, Störung bei Tibber), löste **jeder** Aufruf der Funktion
  eine neue synchrone HTTP-Anfrage mit 30 s Zeitlimit aus. Da die Funktion inzwischen von mehreren
  Modulen aus der Visualisierung heraus aufgerufen wird, fällt das doppelt ins Gewicht. Jetzt wird
  der **Versuch** festgehalten, nicht nur der Erfolg: höchstens ein Nachladeversuch pro Minute.
  Der Normalbetrieb (gefüllter Zwischenspeicher) ist unverändert und kommt ohne HTTP-Anfrage aus.

## 2.2.4

- **Sprachregel fortgeführt:** Anglizismen in Formular- und Dokumentationstexten ersetzt –
  Button → Schaltfläche, Dropdown → Auswahlliste, Checkbox → Häkchen, Push → Meldung,
  Slot → Zeitabschnitt. Panel und Häkchen der Kachel heißen entsprechend „Simulations-Schaltflächen“;
  README und Formular wurden gemeinsam umbenannt, damit die Doku weiter zur Oberfläche passt.
- **Token → Zugangsschlüssel**, aber mit Originalbezeichnung als Suchhilfe: Das Feld heißt jetzt
  „Zugangsschlüssel (bei Tibber ‚Personal Access Token‘)“. Rein deutsch benannt würde man auf
  developer.tibber.com vergeblich nach dem Begriff suchen – dort heißt er wörtlich so.
- Beim Ersetzen wurde jeweils der Satzbau nachgezogen (Genuswechsel, z. B. „keinen abhängigen
  Dropdown“ → „keine abhängige Auswahlliste“).
- Unverändert bleiben Produkt- und Fachbegriffe (Grid Rewards, WebSocket, API, Webfront, Tile) sowie
  Formularelementtypen im Code.

## 2.2.3

- **Sprachregel umgesetzt (alles Nutzersichtbare auf Deutsch).** Die Variable „Aktuelles Preisniveau“
  zeigte bisher Tibbers englische Rohwerte (`VERY_CHEAP` … `VERY_EXPENSIVE`); sie ist jetzt deutsch
  beschriftet (sehr günstig … sehr teuer). Bewusst **1:1** – fünf Stufen bleiben fünf Stufen, das
  Zusammenfassen und Bewerten bleibt Sache des EMS. Unbekannte künftige Stufen werden unverändert
  durchgereicht, statt still zu verschwinden.
- Englische Rückfalltexte der Kachel-Schaltflächen und eine englische Debug-Meldung eingedeutscht.
- **Unverändert (bewusst):** Der Inhalt der Variable `StateReason` bleibt der Rohwert der Tibber-API
  (`excess`/`shortage`). Er ist als Rohwert dokumentiert, wird intern ausgewertet und in Nutzerregeln
  verglichen – eine Eindeutschung wäre ein stiller Bruch bestehender Automationen. Ebenso bleiben die
  Feldnamen des Vertrags `GetPriceCurve()` und alle Idents englisch (Idents sind API).

## 2.2.2

- **Neu: Statusanzeige der Archivierung** im Panel „💶 Preiskurve“ – zeigt beim Öffnen des Formulars,
  ob der Preisverlauf tatsächlich aufgezeichnet wird. Hintergrund: Die Archivierung lässt sich an
  mehreren Stellen einschalten (Häkchen hier, direkt an der Variable, oder per Knopf in einem
  Partnermodul wie dem InverterHub-Monitoring). Wurde sie anderswo aktiviert, blieb das Häkchen hier
  leer – man konnte also nicht erkennen, ob die Aufzeichnung läuft.
- Hinweistext ergänzt: Die Archivierung lässt sich alternativ **direkt an der Variable** einschalten
  (Objektbaum → Rechtsklick auf „Aktueller Preis“ → Archivierung). Bisher war nur vom Häkchen die
  Rede, was die Suche danach an der falschen Stelle nahelegte.

## 2.2.1

- Deutlicherer Hinweis am Häkchen „Preisverlauf archivieren“: Ohne Archivierung gibt es **keinen
  Preis-Rückblick** – `GetPriceCurve()` liefert nur heute und morgen, jede Auswertung der
  Vergangenheit speist sich aus dem Archiv. Das betrifft neben der Rechnungsprüfung auch die
  historische Preiskurve in Kacheln anderer Module (etwa dem InverterHub-Monitoring, das den
  Rückblick aus der archivierten Variable und die Vorschau aus der Funktion zeichnet). Ergänzt, weil
  ohne gesetztes Häkchen nicht ersichtlich war, warum dort der Verlauf fehlt.

## 2.2.0

- **Neu: Preisverlauf archivieren** (Häkchen im Panel „💶 Preiskurve“) – aktiviert die Archivierung
  der Variable „Aktueller Preis“ und legt damit die Grundlage für eine spätere **Rechnungsprüfung**.
  Das Häkchen schaltet die Archivierung nur **ein**; eine selbst gesetzte Archivierung wird beim
  Entfernen des Häkchens bewusst nicht wieder abgeschaltet, damit die laufende Aufzeichnung nicht
  stillschweigend abreißt.
- **Preis wechselt jetzt exakt zum Slot-Wechsel.** Bisher wurde „Aktueller Preis“ nur im
  20-Minuten-Raster der API-Abfrage aktualisiert – der archivierte Verlauf hätte den Preiswechsel
  also um bis zu 20 Minuten versetzt gezeigt, was eine Rechnungsprüfung wertlos macht. Ein eigener
  Timer schaltet den Preis nun sekundengenau zum Stunden- bzw. Viertelstundenwechsel um, rein aus dem
  Cache und ohne zusätzliche API-Aufrufe.
- **Genauerer Preis:** eigenes Profil `Tibber.PricePerKWh` mit **4 Nachkommastellen** statt der
  bisherigen 3 – eine Rundung auf 3 Stellen bedeutet bereits rund 0,3 ct/kWh Abweichung und wäre für
  eine Rechnungsprüfung nicht brauchbar.
- Intern: Variable und Vertrag `GetPriceCurve()` leiten den aktuellen Preis jetzt aus **derselben**
  Quelle (dem Slot-Cache) ab und können nicht mehr auseinanderlaufen; das separat abgefragte
  `current`-Feld der API entfällt dadurch.

## 2.1.2

- **Fix: Das Dropdown „Zuhause für die Preiskurve“ blieb dauerhaft leer.** Die Options wurden nur an
  Elemente der obersten Formularebene verteilt – `PriceHomeID` liegt aber im ExpansionPanel
  „💶 Preiskurve“ und wurde deshalb nie befüllt (auch nicht nach dem Schließen und Neuöffnen des
  Formulars). Die Zuweisung läuft jetzt rekursiv, wie bei den übrigen verschachtelten Feldern.
- **Kein Schließen/Neuöffnen mehr nötig.** Nach dem Übernehmen bzw. „Zuhause-Liste neu laden“ wird das
  **offene** Formular direkt aktualisiert (`UpdateFormField`) – bisher lief das Befüllen nur beim
  Öffnen des Formulars, sodass die frisch geladene Liste erst nach einem Neustart des Dialogs
  auftauchte. Gilt für **beide** Zuhause-Dropdowns (Grid Rewards und Preiskurve).
- **Gibt es nur ein Zuhause, wird es automatisch ausgewählt** – statt ein Dropdown ohne Alternative
  bedienen zu müssen.
- Wechselt der Personal Access Token, wird die Zuhause-Liste automatisch neu geladen (bisher blieb
  stillschweigend die Liste des alten Tokens stehen).

## 2.1.1

- **Fix (Modul-Verbund-Review durch MeterHub): `GetPriceCurve()` reicht `level` nicht mehr durch.**
  `CHEAP`/`NORMAL`/`EXPENSIVE` ist Tibbers eigenes, aus einem gleitenden Mittel berechnetes
  Vokabular – eine Spotpreis-Quelle hätte das nicht und müsste es nachbilden, wodurch dasselbe Feld
  zwei unterschiedliche Bedeutungen bekäme und ein EMS bei identischer Preislage je nach Quelle
  anders entscheiden könnte, ohne dass es auffällt. `level` ist deshalb jetzt **immer `null`**
  (Einstufung ist Sache des EMS, einheitlich für alle Quellen); Tibbers unveränderter Rohwert steht
  weiterhin zur Verfügung, aber klar getrennt in einem neuen Feld `level_tibber`.
- Vertrag zusätzlich präzisiert (Verhalten unverändert, nur dokumentiert/gegengetestet): `price` ist
  ct/kWh (nicht EUR/kWh), `end` ist exklusiv (Intervall `[start, end)`), Lücken in der zurückgegebenen
  Liste sind zulässig.

## 2.1.0

- **Neu: Endkunden-Preiskurve über die offizielle Tibber-API** (Panel „💶 Preiskurve“, komplett
  optional und unabhängig vom Grid-Rewards-Teil): mit einem Personal Access Token
  (developer.tibber.com/settings/access-token) liefert das Modul jetzt die öffentliche Funktion
  `TIBBERGR_GetPriceCurve()` – eine Liste von Zeit-Slots (`start`/`end`/`price`/`basis`/
  `netzentgelt`/`level`) mit dem vollständigen Tibber-Endkundenpreis, **inklusive** evtl.
  zeitvariabler Netzentgelte (z. B. §14a-Modul-3-Zeitfenster) – bewusst kein reiner Spotpreis,
  da genau diese Netzentgelt-Komponente für preisgetriebene Ladeentscheidungen den Unterschied
  macht. Gedacht als Preisquelle für preisgetriebene Automationen/EMS.
  - Eigener, separater Zugang (Personal Access Token statt des App-Logins für Grid Rewards) –
    Personal Access Tokens laufen nicht ab, es ist also kein Token-Refresh nötig.
  - Neue Komfort-Variablen `Aktueller Preis`/`Aktuelles Preisniveau` fürs schnelle Nachsehen.
  - Preiskurve wird alle 20 Minuten neu abgefragt, damit die Preise für den Folgetag zeitnah
    übernommen werden, sobald Tibber sie veröffentlicht (üblicherweise zwischen 13 und 14 Uhr).
  - Deckt weiterhin **nur** die Preiskurve ab – Verbrauch/Live-Messung bleibt bei „Tibber V.2“,
    um keine Doppelung zu erzeugen (siehe CLAUDE.md, Abschnitt Modul-Verbund).

## 2.0.0

- **Bedingungs-Regelwerk „Wenn → Dann" ersetzt die alten Automations-Zeilen** (Vorbild/Portierung:
  `StromGedachtWidget`). Jede Regel prüft beliebig viele **UND-verknüpfte Bedingungen** (`eq/ne/gt/ge/
  lt/le/wird EIN/wird AUS/ändert sich`) über die eigenen Datenpunkte dieser Instanz und setzt beim
  Eintreten **bis zu zwei Zielvariablen gleichzeitig** im Formular (Tibber-Erweiterung ggü. dem
  Vorbild: dort nur eine Aktion je Regel). Flankengesteuert wie bisher – eine Regel feuert beim
  Eintreten der Bedingung, nicht bei jeder Datenmeldung erneut.
- **Automatische einmalige Migration** der bestehenden „Automations"-Zeilen (1.16.0/1.17.0) in das
  neue Format – keine manuelle Nacharbeit nötig, bereits eingerichtete EMS-Steuerungen bleiben
  unverändert wirksam.
- **Neuer, interaktiver Regel-Editor direkt in der Kachel** (`TibberGridRewardTile`, Panel
  „🤖 Automationen anzeigen", standardmäßig **deaktiviert**, da darüber reale EMS-Aktoren
  konfigurierbar sind): beliebig viele UND-Bedingungen und – als Erweiterung ggü. dem Vorbild –
  beliebig viele Aktionen (bis 6) je Regel, jede mit echten, **voneinander unabhängigen** Dropdowns
  für Vergleichs- bzw. Zielwerte (per `requestAction()`/`RequestAction()`-Bridge dynamisch nachgeladen).
  Grund für den eigenen Kachel-Editor: klassische IP-Symcon-Formularlisten unterstützen keine
  pro-Zeile abhängigen Dropdowns, da jede Zeile eine andere Zielvariable mit anderem Profil haben
  kann.
- Regeln lassen sich zusätzlich direkt in der Kachel ein-/ausschalten und löschen, ohne die
  Instanzkonfiguration zu öffnen.
- Der Platzhalter `WALLBOX` als Aktionswert wird weiterhin laufend nachgeführt (nicht nur beim
  Auslösen der Regel), solange deren Bedingung erfüllt bleibt – wie bisher mit Änderungs-Schutz pro
  Zielvariable, damit der Aktor nicht gespammt wird.
- Intern: `ApplyAutomations`/`ApplyAutomationTarget` durch `evaluateDataActions`/
  `applyActionToVariable` ersetzt; `SetDataAction`/`DeleteDataAction`/`SetDataActionActive`/
  `GetDataActions`/`GetDataActionEditor`/`GetTargetValueOptions` neu für die Kachel-Anbindung.

## 1.17.0

- **Werte-Nachschau-Helfer** im Datenmodul (Panel „🎯 EMS-Steuerung"): Variable wählen, übernehmen,
  „Werte anzeigen" klicken – zeigt alle Profil-Werte der Variable als Text an. Ersatz dafür, dass
  IP-Symcon-Listen keinen pro-Zeile-abhängigen Dropdown unterstützen (jede Automationszeile kann eine
  andere Zielvariable mit anderem Profil haben).
- **Simulations-Buttons auf der Kachel** (`TibberGridRewardTile`, neues Panel „🧪 Simulations-Buttons
  auf der Kachel", standardmäßig **deaktiviert**): Verfügbar/Laden/Drosselung simulieren und „↩ Echter
  Status" direkt im Webfront, ohne die Instanzkonfiguration zu öffnen. Nutzt die neue
  `RequestAction()`-Weiterleitung der Kachel an die verbundene Datenquelle (`requestAction()` im
  HTML-SDK). Bewusst standardmäßig aus, da echte EMS-Automationen ausgelöst werden.

## 1.16.0

- **Generische EMS-Automationen** ersetzen die bisherigen Einzelfelder „EMS Leistungsmodus"/
  „EMS Leistungssollwert": neue Liste **„Automationen"** im Panel „🎯 EMS-Steuerung je
  Grid-Reward-Modus" – beliebig viele Zeilen je Modus, jede Zeile kann **bis zu zwei Zielvariablen
  gleichzeitig** setzen (typisch: Betriebsmodus + Leistungssollwert in einer Zeile). Werte werden
  automatisch in den Typ der Zielvariable umgewandelt; der Platzhalter **`WALLBOX`** steht für die
  live nachgeführte, aktuell benötigte Wallbox-Leistung.
- **Automatische einmalige Migration** einer bestehenden Konfiguration aus den alten Feldern
  (bis 1.15.x) in vier äquivalente Automationszeilen – keine manuelle Nacharbeit nötig.
- **Neues Panel „❓ Hilfe & Dokumentation"** direkt im Formular: kompakte Schritt-für-Schritt-Anleitung
  (Einrichtung, Wallboxen, Grid-Reward-Modus, Automationen, Simulation) plus Link zum ausführlichen
  README – ohne dafür GitHub öffnen zu müssen.
- Intern: `ApplyModeActions`/`ApplyPowerSetpoint` (je ein Sonderfall) durch die generischen Methoden
  `ApplyAutomations`/`ApplyAutomationTarget` ersetzt; Spam-Schutz jetzt pro Zielvariable statt global.

## 1.15.0

- **Fester Leistungswert je Modus (optional).** Neue Felder `EmsPowerFixed0..3` (Standard `-1` = weiter
  live nachregeln): Ist für den aktuellen Modus ein Wert ≥ 0 hinterlegt, setzt das Modul den
  Leistungssollwert auf diesen festen Wert, statt ihn laufend an die Wallbox-Last anzupassen. Gedacht
  für Modi, in denen der EMS-Leistungsmodus „Automatik" ist und der Sollwert ohnehin ignoriert wird.

## 1.14.2

- **„↩ Zurück zum echten Status" fordert jetzt aktiv einen frischen Push von Tibber an**, statt sich
  allein auf den zwischengespeicherten Stand zu verlassen: Die laufende WebSocket-Subscription wird
  ab- und sofort wieder angemeldet (nutzt die bereits offene Verbindung). Der frische Status kommt
  umgehend als reguläres Update herein und korrigiert die zuvor angezeigte Cache-Näherung automatisch,
  falls sich der echte Status inzwischen geändert hat.

## 1.14.1

- **Fix: Simulation ließ sich nicht sauber beenden.** Bisher hatte `Simulate()` keinen Weg zurück zum
  echten Status – die Flex-Geräte-Liste wurde dabei zusätzlich geleert und blieb es bis zum nächsten
  echten Tibber-Push (kann lange dauern).
- Der letzte **echte** (nicht simulierte) Status wird jetzt zwischengespeichert (`LastRealStatus`).
  `Simulate()` leert die Flex-Geräte-Liste nicht mehr, sondern übernimmt die echte Liste weiter.
- Neuer Button **„↩ Zurück zum echten Status"** (`ResetSimulation()`) stellt den zuletzt echten Status
  sofort wieder her, ohne auf den nächsten Push zu warten.

## 1.14.0

- **Neu: Simulation ohne echten Einsatz.** Panel „🧪 Simulation" mit drei Buttons (Verfügbar / Laden
  simulieren (excess) / Drosselung simulieren (shortage)). Ruft `Simulate($reason)` auf, die exakt
  denselben Code wie ein echtes Tibber-Ereignis durchläuft (Modusbestimmung, Energiezählung,
  Einsatz-Log **und** die konfigurierten EMS-Aktionen). Damit lässt sich die eigene EMS-Verdrahtung
  jederzeit testen, ohne auf einen echten – oft seltenen – Einsatz zu warten. Achtung: löst dabei
  wirklich die konfigurierten `RequestAction`-Befehle aus.

## 1.13.0

- **Neu: EMS-Leistungssollwert (optional)** im Panel „🎯 EMS-Steuerung je Grid-Reward-Modus": zweite
  Zielvariable, die fortlaufend auf `GridRewardWallboxRequest` gesetzt wird – exakt die Leistung, die
  das Auto gerade braucht. Damit weiß das Modul jetzt auch, **wohin** der Leistungswert geschrieben
  werden soll (bisher setzte es nur den festen Modus-Wert, keinen Leistungswert). Schreibt nur bei
  Änderung ≥ 1 W, um den Aktor nicht zu spammen.
- Panel umbenannt in „EMS-Steuerung je Grid-Reward-Modus" (deckt jetzt Modus + Leistung ab).

## 1.12.1

- **Vereinfacht:** „EMS-Aktionen je Modus" (generische Mehrfach-Liste) ersetzt durch **„EMS-Leistungsmodus
  je Grid-Reward-Modus"** – eine einzige Zielvariable (der Betriebsmodus-Schalter des EMS/Wechselrichters)
  plus vier Werte, einer je Grid-Reward-Modus (0–3). Entspricht dem eigentlichen Anwendungsfall besser.
- Hat die Zielvariable ein Variablenprofil mit Textwerten (z. B. „Automatik", „Batterie-Laden"),
  erscheinen die vier Werte-Felder automatisch als **Dropdown mit diesen Bezeichnungen**.

## 1.12.0

- **Neu: „🎯 EMS-Aktionen je Modus"** im Datenmodul. Konfigurierbare Liste (Modus, Zielvariable, Wert):
  Beim Wechsel des `GridRewardMode` (0–3) setzt das Modul automatisch alle aktiven, passenden Zeilen
  per `RequestAction` auf die Zielvariable – Wert wird passend zum Variablentyp umgewandelt (Zahl/
  Bool/Text). Damit kann jeder Nutzer selbst festlegen, woher der Strom je Modus kommt (eigener
  Wechselrichter/EMS), **ohne eigenes Skript**. Für dynamischere Logik bleibt ein eigenes
  Ereignis-Skript auf `GridRewardMode` weiterhin möglich und sinnvoll.

## 1.11.1

- Kachel: die vier Grid-Reward-Energie-Felder sind jetzt **zentriert** (wie die Reward-Boxen).

## 1.11.0

- Kachel: **dauerhaftes Modus-Band** (feste Kachelhöhe), das `GridRewardMode` spiegelt –
  ausgegraut „Kein Einsatz" (0), Teal „Auto lädt · aus Netz" (1), Grün „Laden aus Netz" (2),
  Bernstein „Drosselung" (3). Statusbadge färbt sich bei Drosselung bernstein und pulsiert bei
  aktivem Einsatz (2/3).
- Neuer Farbwähler **„Drosselung (shortage)"** (Standard Bernstein) + in „Zurücksetzen".
- Kachel lauscht nun auch auf `GridRewardMode`, `WallboxPowerTotal` und die Energie-Variablen
  (Live-Aktualisierung).

## 1.10.0

- **`GridRewardMode` als umfassender EMS-Modus (ein Wert, 4 Zustände):**
  - `0` Normal · `1` Auto lädt (kein Reward: Smart-Charge/Zwangsbeladen/Freigabe → Strom aus Netz)
    · `2` Grid Reward Laden (excess → aus Netz + Batterie aus Netz laden) · `3` Grid Reward
    Drosselung (shortage → Auto aus, Haus aus Batterie).
  - Gebildet aus Grid-Reward-Status **und** Ladezustand (`WallboxCharging`). Damit deckt **ein**
    Datenpunkt alle Steuersituationen ab; das EMS leitet die Aktion direkt daraus ab.
- `GridRewardWallboxRequest` ist jetzt aktiv, **wann immer das Auto lädt** (Modus 1 **oder** 2) – nicht
  nur bei Grid-Reward.
- Energiezählung/Einsatz-Log weiterhin an `excess` (Modus 2) gekoppelt.
- README: Abschnitt „Wirtschaftlich optimal steuern" (Strategie fürs EMS; Preis-Arbitrage via TibberV2).

## 1.9.2

- `GridRewardMode` nutzt jetzt **beide** Signale: „Grid Reward aktiv" (`Delivering`) als Einsatz-Flag
  **plus** Richtung aus dem Status-Detail (`excess`/`shortage`). Erkenntnis aus den Logs: `Delivering`
  ist bei `excess` **und** `shortage` aktiv; nur die Richtung unterscheidet sich. Ergebnis:
  0 = kein Einsatz, 1 = Einsatz + excess (Laden), 2 = Einsatz + shortage (Drosselung).

## 1.9.1

- **`GridRewardMode` jetzt aus dem echten API-Parameter** (Status-Detail `kind`/`reason`): `excess`
  → 1 (Laden aus Netz), `shortage` → 2 (Drosselung), sonst 0. Ersetzt die bisherige
  Stromfluss-Heuristik samt Entprellzeit – zuverlässiger und ohne Konfiguration.
- **„Aktiver Einsatz" = `excess`** (statt `Delivering`): Energiezählung, Sollwert
  `GridRewardWallboxRequest` und Einsatz-Log beziehen sich jetzt auf den Modus „Laden aus Netz".
  Hintergrund: Tibber meldet die Richtung über `excess`/`shortage`, während `Delivering` teils gar
  nicht auftritt.
- Robusteres Energie-Gating über `EventActive` (verbucht auch den letzten Abschnitt beim Einsatz-Ende).

## 1.9.0

- **Neuer Datenpunkt `GridRewardMode`** (Enum 0/1/2): Ein Grid-Reward-Einsatz kann „Laden" (Wallbox an)
  oder „Drosselung" (Wallbox aus) bedeuten – die API meldet das nicht. Das Modul bestimmt die Richtung
  aus dem **tatsächlichen Wallbox-Stromfluss** und stellt sie als EMS-Modus bereit (0 = kein Einsatz,
  1 = Laden aus Netz, 2 = Drosselung).
- **Entprellzeit** (`ModeSettleTime`, Standard 60 s) gegen Flackern beim Hochlaufen der Ladung;
  Profil `Tibber.GridRewardMode` mit farbigen Assoziationen.

## 1.8.1

- Review-Feedback (Symcon) umgesetzt:
  - `vendor` in beiden `module.json` von `DG65` auf **`Tibber`** geändert (Feld ist für den
    Hersteller des zugehörigen Systems gedacht, nicht für den Modulentwickler).
  - `ResetStyle` setzt die Werte jetzt per **`UpdateFormField`** nur im offenen Formular (statt
    `IPS_SetProperty` + `IPS_ApplyChanges`); der Benutzer prüft sie und bestätigt selbst mit
    „Änderungen übernehmen".

## 1.8.0

- **Energie aus Wallbox-Zählern (Ansatz C):** Je Wallbox kann zusätzlich der Energiezähler
  „abgegebene Energie im Ladezyklus" (kWh) gewählt werden. Die Grid-Reward-Energie wird dann **exakt
  aus diesem Zähler** als Delta berechnet – **reset-fest** (ein Zähler-Rücksprung = neuer Ladezyklus
  wird als Delta gewertet) und nur während `Delivering` gezählt. Ohne Energiezähler bleibt die
  bisherige Leistungs-Integration als Fallback.
- Hand-off ans EMS bleibt **ereignisbasiert über Variablen** (EMS abonniert per `VM_UPDATE`); das
  Modul schreibt bewusst nicht aktiv ins EMS (lose Kopplung).

## 1.7.1

- **Fix:** Profil `~UnixTimestampInterval` existiert in IPS nicht → `ApplyChanges` brach ab. Die
  Einsatz-Dauer (`LastEventDuration`) ist jetzt eine formatierte Textvariable (z. B. „1 h 23 min").
- Kachel zeigt zusätzlich die **vier Energiewerte** (Einsatz / heute / Monat / gesamt).

## 1.7.0

- **Wallbox-Aggregation & EMS-Übergabe** im Datenmodul:
  - Liste beliebig vieler Wallbox-Wirkleistungen (Variable + Faktor), laufende Summe
    `WallboxPowerTotal` (ereignisbasiert per `VM_UPDATE`).
  - Alters-/Plausibilitätsprüfung (`DataValid`, Schwelle „lädt" → `WallboxCharging`).
  - Fertiger EMS-Sollwert `GridRewardWallboxRequest` (= Summe während Grid-Reward, sonst 0).
- **Energie-Statistik:** während eines Einsatzes verschobene Energie (`GridRewardEnergyEvent/Today/
  Month/Total`) plus effektiver €/kWh-Wert (`GridRewardEffectiveRate`).
- **Einsatz-Log:** `LastEventStart/End/Duration/Energy`.
- **Kachel** zeigt zusätzlich die Wallbox-Gesamtleistung.

## 1.6.0

- Kachel-Modul erkennt die Datenquelle jetzt **automatisch**, wenn es genau eine
  `TibberGridReward`-Instanz gibt (`ResolveSource()` via `IPS_GetInstanceListByModuleID`). Das
  `SelectInstance`-Feld bleibt als optionale Übersteuerung (nur bei mehreren Dateninstanzen nötig).

## 1.5.0

- **Kachel-Defaults wie da8ter:** Hintergrund standardmäßig **transparent** (Kachel übernimmt das
  IPS-Theme), Textfarben passen sich automatisch hell/dunkel an (`prefers-color-scheme`). Eigene
  Farben überschreiben das weiterhin; „Zurücksetzen" stellt die Theme-Defaults wieder her.
- **Aufräumen Datenmodul:** Alle Kachel-/Darstellungs-Einstellungen liegen jetzt ausschließlich beim
  Modul `TibberGridRewardTile`. Das Datenmodul `TibberGridReward` hat keine Farb-Optionen mehr und
  erzeugt keine `Tile`-Variable (`~HTMLBox`) mehr (wird beim Übernehmen automatisch entfernt).

## 1.4.0

- Kachel-Modul `TibberGridRewardTile` voll anpassbar:
  - **Alle Farben** wählbar: Statusfarben (aktiv/verfügbar/nicht verfügbar) plus Hintergrund,
    Boxen, Text (Werte) und Text (Beschriftungen).
  - **Schriftart** (System/Arial/Verdana/Tahoma/Trebuchet/Georgia/Courier) und **Schriftgröße**
    (Faktor 0,5–2,5×) einstellbar.
  - Button **„Farben & Schrift auf Standard zurücksetzen"**.
- Gerätezeilen zeigen jetzt **mehr Infos** (Typ + Zusatzangaben wie „eingesteckt"/„Smart-Laden"),
  Status weiterhin als Farbpunkt.
- Eigener Kachel-Header endgültig entfernt, oben mehr Platz für den IPS-Titelbalken (kein
  überlappender, unlesbarer Text mehr).

## 1.3.0

- Neues, **eigenständiges Kachel-Modul `TibberGridRewardTile`** in derselben Bibliothek (analog zum
  Aufbau des Tessie-Moduls mit mehreren Instanzen). Es rendert die **randlose HTML-SDK-Kachel**
  (`SetVisualizationType(1)` + `GetVisualizationTile()` + `module.html`) und liest die Daten per
  Instanz-Auswahl aus einer `TibberGridReward`-Instanz.
- **Bewusst von der Datenlogik getrennt** (Vorbild da8ter): Die Kachel kann die WebSocket-/Daten-
  verbindung nicht mehr stören. Aktualisierung per `VM_UPDATE`-Nachrichten der Quell-Variablen.
- Eigene Farbwähler im Kachel-Modul. Das Datenmodul bleibt unverändert; seine `Tile`-Variable
  (~HTMLBox) existiert weiterhin als Alternative.
- Layout-Fix (Build 7): eigener Kachel-Header entfernt und oben Platz für den IPS-Titelbalken
  gelassen (keine Überlappung mehr); Gerätezeilen zeigen jetzt Name + Typ mit Farbpunkt je
  Gerätestatus statt der langen Rohzeile.

## 1.2.0

- **Rückbau der HTML-SDK-Kachel.** Die Umstellung aus 1.1.0 (`SetVisualizationType`/
  `GetVisualizationTile`/`module.html`) hat die Instanz instabil gemacht. Daher zurück auf die
  bewährte, robuste **`Tile`-Variable (`~HTMLBox`)** – die Kachel rendert wieder zuverlässig.
- `module.html` entfernt; keine Visualisierungs-SDK-Aufrufe mehr im Modul.
- Farbwähler (`SelectColor`) für die Statusfarben bleiben erhalten.

## 1.1.0

- Kachel auf **HTML-SDK-Tile-Visualisierung** umgestellt (`GetVisualizationTile()` + `module.html`
  mit `handleMessage()`). Sie füllt die Kachel jetzt **randlos**, ohne zusätzlichen Innenrahmen
  (Vorbild: da8ter/TileVisu-Kacheln).
- Variable `Tile` (`~HTMLBox`) entfällt dadurch (wird beim Übernehmen automatisch entfernt); die
  Kachel kommt nun direkt als Visualisierungs-Tile der Instanz.
- Letzter Status wird gepuffert (`LastStatus`), damit die Kachel beim Öffnen und nach Farbänderung
  sofort den aktuellen Stand zeigt.
- Fix: `SetVisualizationType(1)` in `Create()` ergänzt – ohne diese Anmeldung erschien die Kachel
  nicht in der Visualisierung.

## 1.0.0

- Repository/Bibliothek von `GridReward` zu **TibberGridRewards** umbenannt (`library.json`: name, url).
- MIT-Lizenz und ausführliche Dokumentation (README) ergänzt.
- Neues Modul **TibberGridReward** (Typ 3, Prefix `TIBBERGR`):
  - App-Login (E-Mail/Passwort → JWT) mit automatischem Token-Refresh vor Ablauf.
  - Persistente WebSocket-Subscription (`graphql-transport-ws`) auf `gridRewardStatus`
    über den IPS-WebSocket-Client als Parent.
  - Variablen: `Delivering` (Boolean-Signal), `State`, `StateReason`, `RewardCurrentMonth`,
    `RewardAllTime`, `Currency`, `FlexDeviceCount`, `FlexDevices`.
  - Zuhause-Auswahl per Dropdown (dynamisch aus `me { homes }`).
  - Watchdog + Relogin-Sequenz bei Datenausfall.
  - Webfront-Kachel: Variable `Tile` (`~HTMLBox`) mit Status-Karte (pulsierend bei aktivem
    Einsatz), Reward-Beträgen und Flex-Geräte-Liste (eine Zeile je Gerät, auch bei mehreren
    Fahrzeugen).
  - Kachel-Statusfarben (aktiv/verfügbar/nicht verfügbar) per Farbwähler im Formular einstellbar
    (`SelectColor`, Panel „Kachel-Farben").
- Alter Platzhalter `GridReward/` entfernt.
