# Changelog

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
    Einsatz), Reward-Beträgen und Flex-Geräte-Liste.
- Alter Platzhalter `GridReward/` entfernt.
