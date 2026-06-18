# Changelog

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
