# TibberGridRewards für IP-Symcon

IP-Symcon-Bibliothek, die den **Tibber-Grid-Rewards-Status** über die Tibber-App-API abonniert und
als Variablen bereitstellt. Kern ist ein handlungsfähiges Signal (`Grid Reward aktiv`), mit dem sich
eigene Automationen auslösen lassen.

> **Abgrenzung:** Verbrauch/Produktion und Live-Messung (Pulse) über die offizielle Tibber-API werden
> hier **nicht** abgebildet – dafür gibt es das ausgereifte Modul
> [Tibber V.2](https://github.com/da8ter/TibberV2). Diese Bibliothek ergänzt es um Grid Rewards
> **und** – optional, über einen eigenen Personal Access Token – die Endkunden-Preiskurve
> (`TIBBERGR_GetPriceCurve()`, siehe [Preiskurve für preisgetriebene Automationen](#preiskurve-für-preisgetriebene-automationen)).

---

## Inhaltsverzeichnis

- [Was sind Tibber Grid Rewards?](#was-sind-tibber-grid-rewards)
- [Funktionsweise des Moduls](#funktionsweise-des-moduls)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Variablen](#variablen)
- [Kachel für die Visualisierung](#kachel-für-die-visualisierung)
- [Wallbox-Leistung & EMS-Übergabe](#wallbox-leistung--ems-übergabe)
- [Preiskurve für preisgetriebene Automationen](#preiskurve-für-preisgetriebene-automationen)
- [Anwendungsbeispiel: Speicher & Wallbox steuern](#anwendungsbeispiel-speicher--wallbox-steuern)
- [FAQ](#faq)
- [Fehlersuche](#fehlersuche)
- [Technische Details](#technische-details)
- [Haftungsausschluss](#haftungsausschluss)
- [Lizenz](#lizenz)

---

## Was sind Tibber Grid Rewards?

Mit **Grid Rewards** vergütet Tibber das Bereitstellen von Flexibilität für die Netzstabilität: Ein
angemeldetes Flex-Gerät (z. B. ein Elektroauto oder ein unterstützter Heimspeicher) wird von Tibber
zeitweise so gesteuert, dass es das Netz entlastet – z. B. Laden bei Überschuss. Dafür gibt es eine
Vergütung.

Während eines solchen Einsatzes meldet die Tibber-App den Status **„Delivering"**. Genau dieses Signal
macht das Modul in IP-Symcon nutzbar.

## Funktionsweise des Moduls

Das Modul **TibberGridReward** meldet sich mit deinen Tibber-App-Zugangsdaten an, öffnet eine
dauerhafte WebSocket-Verbindung zur Tibber-App-API und abonniert den Grid-Rewards-Status deines
Zuhauses. Jede Statusänderung schreibt es sofort in IP-Symcon-Variablen.

**Wichtig zum Verständnis:** Tibber steuert nur das **bei Tibber registrierte** Flex-Gerät selbst
(z. B. dein Elektroauto). Andere Geräte – etwa einen GoodWe-Hausspeicher – steuert Tibber **nicht**.
Stattdessen reagierst du in IP-Symcon selbst auf das `Delivering`-Signal und regelst deine Geräte über
deine eigene Logik (siehe [Anwendungsbeispiel](#anwendungsbeispiel-speicher--wallbox-steuern)).

Das Signal ist an **deine** Teilnahme gekoppelt – es ist kein allgemeines, regionsweites Netzsignal.
Ohne registriertes Flex-Gerät bleibt der Status dauerhaft `Unavailable`.

## Voraussetzungen

- IP-Symcon **9.0** oder neuer.
- Aktiver Tibber-Vertrag **und Teilnahme an Grid Rewards mit einem unterstützten Gerät**
  (z. B. einem Elektroauto).
- Zugangsdaten der **Tibber-App** (E-Mail + Passwort). Grid Rewards ist nur über die App-API
  erreichbar, **nicht** über den offiziellen Personal Access Token.

## Installation

**Variante A – Module Store (empfohlen):** In der Verwaltungskonsole den **Module Store** öffnen und
nach **„Tibber Grid Rewards"** suchen → installieren.

**Variante B – manuell über GitHub:**

1. In IP-Symcon **Modulverwaltung** öffnen (Objektbaum → Kerninstanzen → *Modules* bzw. über die
   Verwaltungskonsole).
2. **Modul hinzufügen** und die URL eintragen:
   ```
   https://github.com/DG65/TibberGridRewards
   ```

Danach über **Instanz hinzufügen** eine Instanz vom Typ **TibberGridReward** anlegen (der benötigte
WebSocket-Client als Parent wird automatisch erzeugt). Für die Kachel zusätzlich eine Instanz
**TibberGridRewardTile** anlegen (siehe [Kachel](#kachel-für-die-visualisierung)).

> Module nie manuell in den `modules/`-Ordner kopieren – immer über Module Store/Modulverwaltung.

## Konfiguration

| Feld | Bedeutung |
|---|---|
| **Aktiv** | Schaltet die Verbindung ein/aus |
| **Tibber App – E-Mail** | E-Mail-Adresse deines Tibber-Kontos |
| **Tibber App – Passwort** | Passwort deines Tibber-Kontos |
| **Zuhause (Home)** | Auswahl des Zuhauses (Dropdown) |

**Erstkonfiguration:**

1. E-Mail und Passwort eintragen, **Aktiv** anhaken, **Übernehmen**.
2. Auf **Zuhause-Liste neu laden** klicken – das Dropdown füllt sich mit deinen Tibber-Zuhausen.
3. Das gewünschte Zuhause auswählen und erneut **Übernehmen**.

Danach steht der Status auf „Aktiv" und die Variablen werden live aktualisiert.

## Variablen

| Ident | Typ | Bedeutung |
|---|---|---|
| `Delivering` | Boolean | **Primäres Signal** – `true`, solange ein Grid-Reward-Einsatz läuft |
| `State` | String | `Verfügbar` / `Nicht verfügbar` / `Einsatz aktiv` |
| `StateReason` | String | Roh-Begründung (`kind` / `reason` / `reasons`) – u. a. `excess` (Überschuss) / `shortage` (Knappheit) → Richtung |
| `RewardCurrentMonth` | Float | Vergütung im aktuellen Monat |
| `RewardAllTime` | Float | Vergütung gesamt |
| `Currency` | String | Währung |
| `FlexDeviceCount` | Integer | Anzahl der Flex-Geräte |
| `FlexDevices` | String | Lesbare Liste der Flex-Geräte inkl. Einzelstatus |
| `WallboxPowerTotal` | Float (W) | Summe der Wirkleistungen aller gewählten Wallboxen |
| `GridRewardWallboxRequest` | Float (W) | **EMS-Sollwert**: Wallbox-Leistung, wenn das Auto lädt (Modus 1 oder 2), sonst `0` |
| `GridRewardMode` | Integer | **EMS-Modus** (ein Wert): 0 Normal · 1 Auto lädt · 2 Grid Reward Laden · 3 Drosselung |
| `WallboxCharging` | Boolean | `true`, wenn die Summe über der Schwelle „lädt" liegt |
| `DataValid` | Boolean | `false`, wenn ein Wallbox-Messwert fehlt oder veraltet ist |
| `GridRewardEnergyEvent/Today/Month/Total` | Float (kWh) | Während Grid-Reward verschobene Energie je Zeitraum |
| `GridRewardEffectiveRate` | Float (€/kWh) | KPI: Reward (Monat) ÷ verschobene Energie (Monat) |
| `LastEventStart/End/Duration/Energy` | — | Einsatz-Log des letzten Grid-Reward-Einsatzes |

## Kachel für die Visualisierung

Die Bibliothek enthält dafür ein **zweites Modul** `TibberGridRewardTile` – eine eigenständige
HTML-SDK-Kachel, die die Tile **randlos** füllt (kein zusätzlicher Innenrahmen) und bewusst von der
Datenlogik getrennt ist (ein Kachel-Problem kann die Datenverbindung nicht stören).

1. Instanz **TibberGridRewardTile** anlegen.
2. Die **Datenquelle** wird automatisch erkannt, wenn es genau eine `TibberGridReward`-Instanz gibt;
   nur bei mehreren musst du sie im Formular manuell wählen.
3. Die Instanz-Kachel in der Kachel-Visualisierung auf eine Seite legen.

Standardmäßig ist der **Hintergrund transparent** und der Text passt sich automatisch dem hellen/dunklen
IPS-Theme an – die Kachel fügt sich also nahtlos ein. Bei Bedarf lassen sich im Instanzformular **alle
Farben** (Status, Hintergrund, Boxen, Text), die **Schriftart** und die **Schriftgröße** anpassen; der
Button **„Farben & Schrift auf Standard zurücksetzen"** stellt alles wieder her. Die Kachel
aktualisiert sich automatisch bei jeder Variablenänderung der Quelle.

Ein dauerhaftes **Modus-Band** (feste Kachelhöhe) zeigt den `GridRewardMode`: ausgegraut „Kein Einsatz",
Teal „Auto lädt · aus Netz", Grün „Laden aus Netz" und Bernstein „Drosselung". Die Status- und
Drosselungs-Farbe sind eigene Farbwähler.

> Alle Kachel-Einstellungen liegen ausschließlich beim Modul `TibberGridRewardTile`. Das Datenmodul
> `TibberGridReward` enthält keine Darstellungs-Optionen mehr.

### Simulations-Buttons auf der Kachel (optional, standardmäßig aus)

Im Kachel-Formular lässt sich unter **🧪 Simulations-Buttons auf der Kachel** eine Checkbox aktivieren,
die direkt auf der Kachel im Webfront vier Buttons einblendet: **Verfügbar**, **Laden simulieren**,
**Drosselung simulieren** und **↩ Echter Status**. Sie rufen dieselben `Simulate()`/`ResetSimulation()`-
Funktionen der verbundenen Datenquelle auf wie die Buttons im Konfigurationsformular – praktisch, um
die EMS-Verdrahtung zu testen, ohne die Instanzkonfiguration zu öffnen.

> **Achtung:** Diese Buttons lösen echte `RequestAction`-Befehle an die in der Datenquelle
> konfigurierten Automationen aus. Standardmäßig **deaktiviert**, da jeder mit Zugriff auf das
> Webfront (z. B. Familie, Gäste über eine geteilte Ansicht) sie antippen könnte.

### Regel-Editor auf der Kachel (optional, standardmäßig aus)

Im Kachel-Formular lässt sich unter **🤖 Automationen anzeigen** eine Checkbox aktivieren, die auf der
Kachel selbst die konfigurierten Wenn→Dann-Regeln anzeigt – mit Ein-/Ausschalten und Löschen je Regel
sowie einem vollständigen Regel-Editor (beliebig viele Bedingungen und Aktionen, mit abhängigen
Dropdowns für Vergleichs- und Zielwerte).

> **Achtung:** Über den Editor lassen sich hier direkt die Automationen der Datenquelle anlegen,
> ändern und löschen – inklusive echter Aktoren wie Speicher-Entladesperre und Wallbox-Steuerung.
> Standardmäßig **deaktiviert**, aus demselben Grund wie bei den Simulations-Buttons.

## Wallbox-Leistung & EMS-Übergabe

Im Datenmodul lassen sich unter **🔌 Wallboxen** die Wirkleistungs-Datenpunkte beliebig vieler
Wallboxen auswählen (Liste, je Zeile eine Variable + Faktor, z. B. `1000` falls eine Quelle in kW
liefert). Das Modul:

- **summiert** die aktiven Wirkleistungen laufend (ereignisbasiert per `VM_UPDATE`) →
  `WallboxPowerTotal`,
- prüft die Messwerte auf **Alter/Plausibilität** (`DataValid`, Schwelle einstellbar) – wichtig, weil
  das EMS auf diesen Wert steuert,
- stellt den **fertigen EMS-Sollwert** `GridRewardWallboxRequest` bereit (= Wallbox-Summe nur im Modus
  „Laden aus Netz"/excess, sonst `0`) sowie die Richtung als `GridRewardMode` (siehe unten),
- erfasst die während eines Einsatzes verschobene **Energie** (Einsatz/heute/Monat/gesamt) und rechnet
  daraus den **effektiven €/kWh-Wert** (`GridRewardEffectiveRate`). Wird je Wallbox optional ein
  **Energiezähler** („abgegebene Energie im Ladezyklus", kWh) angegeben, kommt die Energie **exakt aus
  diesem Zähler** (reset-fest gegen Zyklus-Rücksprünge); ohne Zähler wird sie aus der Leistung
  integriert,
- führt ein **Einsatz-Log** (Start/Ende/Dauer/Energie des letzten Einsatzes).

Das EMS muss nur die Variablen mit stabilen Idents lesen (v. a. `Delivering` und
`GridRewardWallboxRequest`). Die eigentliche Wechselrichter-/Speichersteuerung bleibt bewusst im EMS –
dieses Modul liefert ausschließlich saubere Eingangswerte. Die Wallbox-Gesamtleistung wird zusätzlich
in der Kachel angezeigt.

### EMS-Modus (`GridRewardMode`) – ein Wert für die Steuerung

`GridRewardMode` fasst alle relevanten Situationen in **einem** Wert zusammen, aus dem das EMS direkt
die Aktion ableitet. Er wird aus dem Grid-Reward-Status (`Delivering` + `StateReason`) **und** dem
Ladezustand der Wallboxen gebildet:

| Wert | Situation | Typische EMS-Reaktion |
|---|---|---|
| `0` | **Normal** – kein Einsatz, Auto lädt nicht | Eigenverbrauch normal; Batterie nach Preis bewirtschaften |
| `1` | **Auto lädt, kein Reward** (Smart-Charge / Zwangsbeladen / Freigabe) | Wallbox-Last **aus dem Netz** (Batterie-Entladung um Wallbox-Leistung kürzen) |
| `2` | **Grid Reward: Laden** (`excess`) | wie 1, **plus** Batterie aus Netz laden (belohnt); Batterie **nie** entladen |
| `3` | **Grid Reward: Drosselung** (`shortage`) | Auto aus, Haus aus Batterie/PV → Netzbezug minimieren |

Die Fälle „Preis ok / Freigabe" und „Zwangsbeladen" fallen bewusst in **Wert 1** – die EMS-Aktion
(Strom aus dem Netz) ist dort identisch. `GridRewardWallboxRequest` ist die zugehörige Leistung
(aktiv bei Wert 1 **und** 2). Energiezählung und Einsatz-Log beziehen sich auf **`excess` (Wert 2)** –
den Zeitraum, in dem für die Vergütung tatsächlich aus dem Netz geladen wird.

### Automationen „Wenn → Dann" – ohne eigenes Skript konfigurierbar

Damit **jeder Nutzer** frei festlegen kann, wie sein EMS/Wechselrichter reagiert, gibt es im
Datenmodul unter **🎯 Automationen (Wenn → Dann)** eine Liste von **Regeln**. Jede Regel besteht aus:

- einer **Bedingung** über einen Datenpunkt dieser Instanz (z. B. „Grid-Reward-Modus = 2", „Wallbox
  lädt wird EIN", „Status-Detail ändert sich"),
- **Zielvariable 1 + Aktion + Wert 1** (Pflicht),
- **Zielvariable 2 + Aktion + Wert 2** (optional) – für den typischen Fall, dass ein Grid-Reward-
  Übergang **zwei Datenpunkte gleichzeitig** braucht (z. B. den Betriebsmodus-Schalter deines
  Wechselrichters **und** einen Leistungssollwert) – beides in **einer** Regel, ohne zwei getrennte
  Konfigurationen.

Sobald die Bedingung eintritt, setzt das Modul beide Zielvariablen automatisch per `RequestAction`
(**flankengesteuert** – eine Regel feuert beim Eintreten, nicht bei jeder Datenmeldung erneut). Als
Aktion stehen Einschalten/Ausschalten/Umschalten/Wert setzen zur Wahl; ein eingegebener Wert wird
selbsttätig in den Typ der Zielvariable umgewandelt (Zahl, `true`/`false` oder Text). Für den Wert
gibt es einen besonderen Platzhalter:

- **`WALLBOX`** (Groß-/Kleinschreibung egal) wird durch die aktuell benötigte Wallbox-Leistung ersetzt
  und **laufend live nachgeführt**, solange die Bedingung erfüllt bleibt – so kauft das EMS immer nur
  so viel Energie aus dem Netz ein, wie fürs Laden tatsächlich gebraucht wird. Geschrieben wird nur
  bei relevanter Änderung, um den Aktor nicht zu spammen.
- Jeder andere Text/Zahl wird als **fester Wert** verwendet (z. B. sinnvoll für Modi, in denen der
  Leistungssollwert ohnehin ignoriert wird, weil der Betriebsmodus „Automatik" ist).

Es sind **beliebig viele Regeln** erlaubt – braucht dein EMS mehr als zwei Datenpunkte für einen
Übergang, legst du einfach eine weitere Regel mit derselben Bedingung an.

**Werte nachschlagen:** Da jede Regel eine andere Zielvariable mit anderem Profil haben kann,
unterstützt IP-Symcon hier keinen Dropdown, der sich abhängig von der in derselben Zeile gewählten
Zielvariable anpasst (Listenspalten sind für alle Zeilen einheitlich). Als Ersatz gibt es darunter
**„🔍 Werte nachschlagen"**: Variable wählen, übernehmen, **„Werte anzeigen"** klicken – darunter
erscheinen alle möglichen Werte der Zielvariable als Text (z. B. „0 = Gestoppt · 1 = Automatik · …")
zum Ablesen und Abtippen in Vergleichswert/Wert 1/2.

**Komfortabler Regel-Editor in der Kachel:** Die Kachel `TibberGridRewardTile` bietet unter
**🤖 Automationen anzeigen** (standardmäßig **deaktiviert**, siehe Warnhinweis dort) einen
interaktiven Editor direkt im Webfront – mit **beliebig vielen UND-verknüpften Bedingungen** und
**beliebig vielen Aktionen** (bis 6) je Regel, jeweils mit echten, voneinander unabhängigen Dropdowns
für Vergleichs- und Zielwerte (dort, wo die jeweilige Variable feste Werte hat). Regeln lassen sich
dort auch direkt ein-/ausschalten und löschen, ohne die Instanzkonfiguration zu öffnen. Das deckt auch
komplexere, dynamische Logik ab, für die im klassischen Formular mehrere Zeilen mit unterschiedlichen
Zielvariablen und Profilen nötig wären.

Für sehr dynamische Logik (z. B. „nur laden, wenn die Batterie beim Einsatz-Start bereits lud, und die
Leistung aus der vorherigen Einstellung + Wallbox-Leistung berechnen") bleibt ein eigenes
Ereignis-Skript auf `GridRewardMode` weiterhin die passendere Wahl – das Modul liefert dafür weiterhin
alle nötigen Werte (`GridRewardMode`, `GridRewardWallboxRequest`, `WallboxPowerTotal`).

> **Update von einer älteren Version:** Eine bestehende Konfiguration aus den Feldern
> „EMS Leistungsmodus/-Sollwert" (bis Version 1.15.x) bzw. aus den „Automations"-Zeilen (1.16.0/
> 1.17.0) wird beim ersten Start automatisch und einmalig in das neue Regelformat übernommen – keine
> manuelle Nacharbeit nötig.

### Wirtschaftlich optimal steuern (Strategie fürs EMS)

Grundregel: **Jede kWh aus der billigsten Quelle.** Grenzkosten: PV (~0) < Batterie-**Wert** (= teuerste
Stunde, für die du sie aufhebst) vs. Netzpreis − Grid-Reward.

- **Auto laden ⇒ immer aus dem Netz** (es lädt nur in günstigen/belohnten Zeiten; die Batterie ist für
  den teuren Abend wertvoller). → Modus 1 und 2.
- **`excess` (Modus 2):** Netzbezug ist belohnt → Auto **und** Batterie aus dem Netz laden, Batterie nie
  entladen.
- **`shortage` (Modus 3):** Netzbezug wird „bestraft" (Reward fürs Reduzieren) → Auto aus, Haus aus
  Batterie/PV.
- **Batterie-Arbitrage** (wann aus Netz laden / entladen) macht das **EMS anhand der Tibber-Preise**:
  laden wenn `Preis + Verluste < Batteriewert`, entladen wenn `Preis − Reward > Batteriewert`. Dieses
  Modul liefert dafür die Grid-Reward-Schicht sowie – optional, siehe unten – die Preiskurve selbst
  (`TIBBERGR_GetPriceCurve()`); alternativ liefert TibberV2 den Preis-Forecast.

## Preiskurve für preisgetriebene Automationen

Optional und **komplett unabhängig** vom Grid-Rewards-Teil oben: Mit einem eigenen
[Personal Access Token](https://developer.tibber.com/settings/access-token) liefert das Modul über
die **offizielle** Tibber-API die Endkunden-Preiskurve als öffentliche Funktion:

```php
TIBBERGR_GetPriceCurve(int $InstanzID): array
// [[ 'start' => int,        // Unix-Zeitstempel, Slot-Beginn
//    'end'   => int,        // Unix-Zeitstempel, Slot-Ende
//    'price' => float,      // Bruttopreis in ct/kWh
//    'basis' => 'endkunde', // dieses Modul liefert immer den vollständigen Endkundenpreis
//    'netzentgelt' => 'enthalten', // zeitvariable Netzentgelte sind im Preis bereits enthalten
//    'level' => 'CHEAP'|'NORMAL'|'EXPENSIVE'|null, // optional, null wenn Tibber kein Level liefert
// ], …]
```

Liste statt Einzelobjekt (auch bei leerem Ergebnis), damit spätere Erweiterungen die Signatur nicht
brechen – Konvention wie bei den anderen Modul-Verbund-Verträgen (z. B. `MHUB_GetFunctions`).

**Warum ein eigener Token statt Weiterreichen aus TibberV2:** Ein Personal Access Token
(`developer.tibber.com`) ist von Haus aus für die Preis-/Verbrauchsdaten der offiziellen API gedacht,
läuft nicht ab und macht dieses Modul dafür **eigenständig** – ohne stillschweigende Abhängigkeit von
einer fremden Instanz, deren Wartungszustand man nicht beeinflussen kann.

**Warum der volle Endkundenpreis und kein reiner Spotpreis:** Bei zeitvariablen Netzentgelten
(z. B. §14a EnWG Modul 3) kann der Unterschied zwischen Netzentgelt-Hoch- und -Niedrigtarif mehrere
Cent/kWh betragen – für eine Ladeentscheidung oft relevanter als die Spotpreis-Schwankung selbst. Ein
EMS, das nur den Spotpreis sieht, trifft in solchen Zeitfenstern die falsche Entscheidung (lädt nachts
zu wenig, meidet die Abendspitze nicht). Deshalb liefert `price` immer den kompletten Preis, den
Tibber dem Kunden tatsächlich berechnet.

**Einrichtung:** Im Panel „💶 Preiskurve“ den Personal Access Token eintragen, übernehmen, dann
„Preis-Zuhause-Liste neu laden“ und das Zuhause auswählen, erneut übernehmen. Die Kurve wird danach
alle 20 Minuten neu abgefragt – häufig genug, um die Preise für den Folgetag zeitnah zu übernehmen,
sobald Tibber sie veröffentlicht (üblicherweise zwischen 13 und 14 Uhr). Die Komfort-Variablen
„Aktueller Preis“ (€/kWh) und „Aktuelles Preisniveau“ zeigen den jeweils aktuellen Slot fürs schnelle
Nachsehen, ohne die Funktion aufrufen zu müssen.

## Anwendungsbeispiel: Speicher & Wallbox steuern

Typischer Anwendungsfall mit großem Hausspeicher und Wallbox: Während eines Grid-Reward-Einsatzes soll
der **Speicher nicht entladen** und die **Wallbox-Last aus dem Netz** bezogen werden – damit das vom
Netz angeforderte Verhalten nicht durch Batterieentladung unterlaufen wird.

Dazu ein **ausgelöstes Ereignis** auf die Variable `Delivering` legen (Auslöser: „bei Änderung") mit
folgendem Skript:

```php
<?php
// $_IPS['VALUE'] = neuer Wert von "Delivering"
$delivering = $_IPS['VALUE'];

if ($delivering) {
    // Grid-Reward-Einsatz startet:
    // Hausspeicher auf Entladesperre setzen und Wallbox-Last aus dem Netz beziehen.
    // -> hier deine GoodWe-/EMS-Steuerbefehle einsetzen, z. B.:
    // GOODWE_SetEntladesperre($speicherInstanzID, true);
} else {
    // Einsatz beendet: Normalbetrieb wiederherstellen.
    // GOODWE_SetEntladesperre($speicherInstanzID, false);
}
```

Die konkreten Steuerbefehle hängen von deinem Speicher-/EMS-Modul ab. Das Modul liefert nur das
Signal – die Geräteansteuerung bleibt in deiner Hand.

> **Richtung prüfen:** Ob ein Einsatz „aus dem Netz laden" (Überschuss) oder „ins Netz entladen"
> (Knappheit) bedeutet, steht im Detail in `StateReason`. Beobachte diese Variable bei den ersten
> echten Einsätzen, bevor du die Steuerlogik endgültig festlegst.

## FAQ

**Brauche ich ein Tibber Pulse / Watty?**
Nein. Grid Rewards ist unabhängig von der Echtzeit-Verbrauchsmessung.

**Warum nicht der offizielle API-Token für Grid Rewards?**
Der Grid-Rewards-Status ist über die offizielle Tibber-API (Personal Access Token) nicht verfügbar,
sondern nur über die App-API, die einen App-Login (E-Mail/Passwort) verlangt. Der Personal Access
Token kommt trotzdem zum Einsatz – aber nur für die optionale Preiskurve (siehe
[Preiskurve für preisgetriebene Automationen](#preiskurve-für-preisgetriebene-automationen)), das
ist ein komplett anderer, unabhängiger Teil des Moduls.

**Steuert das Modul meinen Wechselrichter/Speicher direkt?**
Nein. Es liefert nur den Status. Die Gerätesteuerung baust du in IP-Symcon selbst, ausgelöst durch die
`Delivering`-Variable.

**Warum brauche ich überhaupt ein Steuersignal fürs EMS?**
Während eines Grid-Reward-Einsatzes wird das Auto bewusst aus dem Netz geladen. Ohne Gegenmaßnahme
würde ein Hausspeicher diese Last aber aus der **Batterie** decken – das Ergebnis: die Batterie ist
leer **und** die aus dem Netz „belohnte" Energie ist gar nicht aus dem Netz gekommen. Mit dem
`Delivering`-Signal (und `GridRewardWallboxRequest`) sorgt dein EMS dafür, dass der Speicher in diesem
Zeitraum **nicht entlädt** und die Wallbox-Last aus dem Netz kommt – nur so trägt der Einsatz wirklich.

**`State` bleibt dauerhaft „Nicht verfügbar" – warum?**
Dann nimmt aktuell kein unterstütztes Gerät an Grid Rewards teil bzw. ist keines registriert. Ohne
registriertes Flex-Gerät entsteht kein `Delivering`-Signal.

## Simulation ohne echten Einsatz

Echte Grid-Reward-Einsätze sind oft selten und unvorhersehbar – zum Testen der eigenen EMS-Verdrahtung
gibt es im Formular unter **🧪 Simulation** drei Buttons: **Verfügbar**, **Laden simulieren (excess)**
und **Drosselung simulieren (shortage)**. Sie durchlaufen exakt denselben Code wie ein echtes
Tibber-Ereignis – Modusbestimmung, Energiezählung, Einsatz-Log **und** die konfigurierten Automationen
(„🎯 Automationen (Wenn → Dann)"). So lässt sich die komplette Kette prüfen, ohne auf einen
echten Einsatz zu warten.

> **Achtung:** Die Simulation löst dabei wirklich die konfigurierten `RequestAction`-Befehle an deine
> Ziel­variablen aus (z. B. den Leistungsmodus deines Wechselrichters). Das ist beabsichtigt – ein
> echter Funktionstest –, aber kein rein passives „Anschauen".

Die Simulation bleibt so lange bestehen, bis entweder der nächste echte Tibber-Push eintrifft oder du
auf **„↩ Zurück zum echten Status"** klickst. Dieser Button zeigt sofort den zuletzt bekannten echten
(nicht simulierten) Status als Näherung – und fordert zusätzlich **aktiv einen frischen Status direkt
von Tibber an** (die laufende Subscription wird ab- und sofort wieder angemeldet). Der frische Push
kommt umgehend als normales Update herein und überschreibt die Näherung automatisch, falls sich der
echte Status inzwischen geändert haben sollte – zuverlässiger, als sich nur auf einen zwischengespeicherten
Stand zu verlassen.

## Fehlersuche

- **Status „Login fehlgeschlagen":** E-Mail/Passwort der **Tibber-App** prüfen (nicht der API-Token).
- **Status „Kein Zuhause gewählt":** Auf *Zuhause-Liste neu laden* klicken und ein Zuhause auswählen.
- **Keine Werte:** Den **Debug** der Instanz öffnen (Rechtsklick → Debug). Dort sollten nacheinander
  `Login erfolgreich`, `connection_ack` und `next`-Pakete erscheinen. Ein WebSocket-Client als Parent
  muss vorhanden und aktiv sein.
- **Verbindung bricht ab:** Das Modul loggt sich bei Datenausfall automatisch neu ein
  (Watchdog/Relogin-Sequenz).

## Technische Details

- **Login:** `POST https://app.tibber.com/v1/login.credentials` → JWT; automatischer Token-Refresh
  kurz vor Ablauf.
- **Zuhause:** `POST https://app.tibber.com/v4/gql` (`me { homes { id title } }`).
- **Status:** WebSocket `wss://app.tibber.com/v4/gql/ws` mit Subprotokoll `graphql-transport-ws`;
  Subscription `gridRewardStatus(homeId)`.
- **Parent:** IP-Symcon WebSocket-Client (I/O), wird automatisch als Parent angelegt.

## Haftungsausschluss

Dieses Projekt nutzt eine **inoffizielle** Tibber-App-Schnittstelle, die sich jederzeit ohne
Vorankündigung ändern kann. Es steht in keiner Verbindung zu Tibber. Nutzung auf eigene Verantwortung.

## Lizenz

[MIT](LICENSE) © 2026 Dietmar Gureth
