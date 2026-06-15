# TibberGridRewards für IP-Symcon

IP-Symcon-Bibliothek, die den **Tibber-Grid-Rewards-Status** über die Tibber-App-API abonniert und
als Variablen bereitstellt. Kern ist ein handlungsfähiges Signal (`Grid Reward aktiv`), mit dem sich
eigene Automationen auslösen lassen.

> **Abgrenzung:** Die offizielle Tibber-API (Preise, Verbrauch/Produktion, Live-Messung/Pulse) wird
> hier **nicht** abgebildet – dafür gibt es das ausgereifte Modul
> [Tibber V.2](https://github.com/da8ter/TibberV2). Diese Bibliothek **ergänzt** es ausschließlich um
> Grid Rewards.

---

## Inhaltsverzeichnis

- [Was sind Tibber Grid Rewards?](#was-sind-tibber-grid-rewards)
- [Funktionsweise des Moduls](#funktionsweise-des-moduls)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Variablen](#variablen)
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

1. In IP-Symcon **Modulverwaltung** öffnen (Objektbaum → Kerninstanzen → *Modules* bzw. über die
   Verwaltungskonsole).
2. **Modul hinzufügen** und die URL eintragen:
   ```
   https://github.com/DG65/TibberGridRewards
   ```
3. Über **Instanz hinzufügen** eine Instanz vom Typ **TibberGridReward** anlegen. Der benötigte
   WebSocket-Client (I/O) als Parent wird dabei **automatisch** erzeugt.

> Module nie manuell in den `modules/`-Ordner kopieren – immer über die Modulverwaltung hinzufügen.

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
| `Tile` | String (~HTMLBox) | Fertige **Kachel** (Status, Reward-Beträge, Flex-Geräte) für die Visualisierung |
| `Delivering` | Boolean | **Primäres Signal** – `true`, solange ein Grid-Reward-Einsatz läuft |
| `State` | String | `Verfügbar` / `Nicht verfügbar` / `Einsatz aktiv` |
| `StateReason` | String | Roh-Begründung (`reason` / `kind` / `reasons`) – z. B. zur Richtungsbestimmung |
| `RewardCurrentMonth` | Float | Vergütung im aktuellen Monat |
| `RewardAllTime` | Float | Vergütung gesamt |
| `Currency` | String | Währung |
| `FlexDeviceCount` | Integer | Anzahl der Flex-Geräte |
| `FlexDevices` | String | Lesbare Liste der Flex-Geräte inkl. Einzelstatus |

## Kachel für die Visualisierung

Das Modul erzeugt die Variable `Tile` (Profil `~HTMLBox`) mit einer fertigen, dunkel gestalteten
Status-Karte: Statusanzeige (bei aktivem Einsatz pulsierend), Vergütung des aktuellen Monats und
gesamt sowie die Flex-Geräte inkl. Einzelstatus. Sind mehrere Flex-Geräte (z. B. zwei Fahrzeuge)
registriert, erscheint pro Gerät eine eigene Zeile.

Zur Anzeige in der **Kachel-Visualisierung** einfach die Variable `Tile` der Instanz auf eine
Visualisierungsseite ziehen – sie aktualisiert sich automatisch mit jedem Status-Update.

Die Statusfarben (aktiv / verfügbar / nicht verfügbar) lassen sich im Instanzformular unter
**🎨 Kachel-Farben** per Farbwähler anpassen – ohne Code-Änderung und update-fest.

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

**Warum nicht der offizielle API-Token?**
Der Grid-Rewards-Status ist über die offizielle Tibber-API (Personal Access Token) nicht verfügbar,
sondern nur über die App-API, die einen App-Login (E-Mail/Passwort) verlangt.

**Steuert das Modul meinen Wechselrichter/Speicher direkt?**
Nein. Es liefert nur den Status. Die Gerätesteuerung baust du in IP-Symcon selbst, ausgelöst durch die
`Delivering`-Variable.

**`State` bleibt dauerhaft „Nicht verfügbar" – warum?**
Dann nimmt aktuell kein unterstütztes Gerät an Grid Rewards teil bzw. ist keines registriert. Ohne
registriertes Flex-Gerät entsteht kein `Delivering`-Signal.

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
