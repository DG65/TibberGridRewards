# Changelog

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
