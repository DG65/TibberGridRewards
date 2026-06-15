# Changelog

## 1.1.0

- Kachel auf **HTML-SDK-Tile-Visualisierung** umgestellt (`GetVisualizationTile()` + `module.html`
  mit `handleMessage()`). Sie füllt die Kachel jetzt **randlos**, ohne zusätzlichen Innenrahmen
  (Vorbild: da8ter/TileVisu-Kacheln).
- Variable `Tile` (`~HTMLBox`) entfällt dadurch (wird beim Übernehmen automatisch entfernt); die
  Kachel kommt nun direkt als Visualisierungs-Tile der Instanz.
- Letzter Status wird gepuffert (`LastStatus`), damit die Kachel beim Öffnen und nach Farbänderung
  sofort den aktuellen Stand zeigt.

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
