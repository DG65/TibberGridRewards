<?php

declare(strict_types=1);

/**
 * TibberGridReward
 *
 * Abonniert den Tibber-Grid-Rewards-Status über die (inoffizielle) Tibber-App-API und stellt
 * ihn als IP-Symcon-Variablen bereit. Kernzweck: die Boolean-Variable "Delivering" als Trigger,
 * um bei einem aktiven Grid-Reward-Einsatz eigene Geräte zu steuern (z. B. GoodWe-Speicher auf
 * Entladesperre, Wallbox-Last aus dem Netz).
 *
 * Zusätzlich (seit 2.1.0, eigenständig von Active/Email/Password): liefert über die OFFIZIELLE
 * Tibber-API (Personal Access Token) die Endkunden-Preiskurve (GetPriceCurve()) für preisgetriebene
 * Automationen/EMS – als Endkundenpreis inkl. evtl. zeitvariabler Netzentgelte (z. B. §14a Modul 3),
 * NICHT nur ein reiner Spotpreis. Verbrauch/Live-Messung/Pulse deckt weiterhin das Modul "Tibber V.2"
 * (da8ter/TibberV2) ab – bewusst keine Doppelung dort.
 *
 * WS-Anbindung über den IPS-WebSocket-Client als Parent (Muster analog Tibber_Realtime aus TibberV2).
 */
class TibberGridReward extends IPSModule
{
    // IPS-WebSocket-Client (I/O), wird als Parent benötigt
    private const WS_CLIENT_MODULE = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    // DataID für SendDataToParent an den WebSocket-Client
    private const WS_DATA_ID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    // Tibber-App-API (inoffiziell – einziger Weg zu Grid Rewards)
    private const AUTH_URL = 'https://app.tibber.com/v1/login.credentials';
    private const GQL_URL  = 'https://app.tibber.com/v4/gql';
    private const WS_URL   = 'wss://app.tibber.com/v4/gql/ws';

    // Offizielle Tibber-API (Personal Access Token, developer.tibber.com) – nur für die Preiskurve
    private const PRICE_GQL_URL = 'https://api.tibber.com/v1-beta/gql';

    // IPS Archive Control – für die optionale Archivierung des Preisverlaufs
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RequireParent(self::WS_CLIENT_MODULE);

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Home_ID', '0');

        // Wallbox-Wirkleistung aggregieren
        $this->RegisterPropertyString('Wallboxes', '[]');
        $this->RegisterPropertyFloat('ChargingThreshold', 100.0);
        $this->RegisterPropertyInteger('MaxAge', 120);

        // Generisches Bedingungs-Regelwerk "Wenn -> Dann" (Vorbild/Portierung: StromGedachtWidget).
        // Jede Regel: beliebig viele UND-Bedingungen (Conditions) über die eigenen Variablen dieser
        // Instanz + beliebig viele Aktionen (Actions), die beim Erfüllen ausgeführt werden. Tibber-
        // Erweiterung ggü. StromGedacht: mehrere Actions pro Regel (dort nur eine) – ein Grid-Reward-
        // Übergang braucht typischerweise 2 Datenpunkte gleichzeitig (EMS-Modus + Leistung).
        $this->RegisterPropertyString('DataActions', '[]');
        $this->RegisterAttributeString('RuleState', '{}');
        $this->RegisterAttributeBoolean('DataActionsMigrated', false);
        // Werte-Nachschau-Helfer: zeigt die Profil-Werte einer Variable an, da IP-Symcon-Listen keine
        // pro-Zeile abhängigen Dropdowns unterstützen (jede Zeile könnte eine andere Zielvariable mit
        // anderem Profil haben). Die Kachel bietet dafür einen echten, interaktiven Regel-Editor.
        $this->RegisterPropertyInteger('LookupVariable', 0);

        // Legacy (bis 1.17.x) – nicht mehr im Formular, nur für die einmalige Migration nach
        // "DataActions" beim ersten Start dieser Version noch registriert.
        $this->RegisterPropertyString('Automations', '[]');
        $this->RegisterAttributeString('LastAppliedValues', '{}');
        $this->RegisterAttributeBoolean('EmsAutomationsMigrated', false);
        // Legacy (bis 1.15.x).
        $this->RegisterPropertyInteger('EmsModeVariable', 0);
        $this->RegisterPropertyInteger('EmsModeValue0', 0);
        $this->RegisterPropertyInteger('EmsModeValue1', 0);
        $this->RegisterPropertyInteger('EmsModeValue2', 0);
        $this->RegisterPropertyInteger('EmsModeValue3', 0);
        $this->RegisterPropertyInteger('EmsPowerVariable', 0);
        $this->RegisterPropertyInteger('EmsPowerFixed0', -1);
        $this->RegisterPropertyInteger('EmsPowerFixed1', -1);
        $this->RegisterPropertyInteger('EmsPowerFixed2', -1);
        $this->RegisterPropertyInteger('EmsPowerFixed3', -1);

        $this->RegisterAttributeString('JWT', '');
        $this->RegisterAttributeInteger('JWT_Exp', 0);
        $this->RegisterAttributeString('Homes', '');
        $this->RegisterAttributeInteger('Parent_IO', 0);
        $this->RegisterAttributeInteger('WTCounter', 0);
        $this->RegisterAttributeFloat('LastEnergyTs', 0.0);
        $this->RegisterAttributeFloat('LastPower', 0.0);
        $this->RegisterAttributeString('EnergyDayMarker', '');
        $this->RegisterAttributeString('EnergyMonthMarker', '');
        $this->RegisterAttributeString('EnergyLast', '{}');
        $this->RegisterAttributeBoolean('EventActive', false);
        // Letzter ECHTER (nicht simulierter) Status – ermöglicht ein sauberes Beenden der Simulation
        $this->RegisterAttributeString('LastRealStatus', '{}');

        // eindeutige Subscription-ID je Instanz
        $this->RegisterPropertyInteger('SubID', rand(1000, 9999));

        // Preiskurve über die offizielle Tibber-API (Personal Access Token) – bewusst unabhängig von
        // "Active"/Email/Password: läuft auch, wenn Grid Rewards gar nicht genutzt wird.
        $this->RegisterPropertyString('PriceApiToken', '');
        $this->RegisterPropertyString('PriceHomeID', '0');
        // Archivierung des Preisverlaufs (Grundlage für eine spätere Rechnungsprüfung).
        $this->RegisterPropertyBoolean('ArchivePrice', false);
        $this->RegisterAttributeString('PriceHomes', '');
        // Hash des Tokens, mit dem die Home-Liste geholt wurde – erkennt einen Token-Wechsel.
        $this->RegisterAttributeString('PriceHomesToken', '');
        $this->RegisterAttributeString('PriceCache', '{}');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // Timer
        $this->RegisterTimer('TokenRefresh', 0, 'TIBBERGR_TokenRefresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StartWatchdog', 0, 'TIBBERGR_StartWatchdog($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ReloginSequence', 0, 'TIBBERGR_ReloginSequence($_IPS[\'TARGET\']);');
        $this->RegisterTimer('EnergyTick', 0, 'TIBBERGR_EnergyTick($_IPS[\'TARGET\']);');
        // Alle 20 Minuten neu abfragen – häufig genug, um die morgigen Preise (erscheinen meist
        // zwischen 13 und 14 Uhr) zeitnah zu übernehmen, ohne die Tibber-API unnötig zu belasten.
        $this->RegisterTimer('PriceRefresh', 0, 'TIBBERGR_PriceRefresh($_IPS[\'TARGET\']);');
        // Schaltet den aktuellen Preis exakt zum Slot-Wechsel um (aus dem Cache, ohne API-Aufruf).
        // Eigener Timer, weil das Abfrage-Raster (20 min) sonst den Zeitpunkt des Preiswechsels
        // verschmieren würde – für eine Rechnungsprüfung ist die exakte Stunde entscheidend.
        $this->RegisterTimer('PriceTick', 0, 'TIBBERGR_PriceTick($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Standard-Darstellung erzwingen (räumt evtl. aus 1.1.0 verbliebenen HTML-SDK-Typ auf)
        $this->SetVisualizationType(0);

        // Einmalige Migrationen (lösen bei Bedarf selbst ein erneutes ApplyChanges aus – dann hier
        // abbrechen): zuerst die sehr alten EMS-Felder (bis 1.15.x) nach "Automations", danach das
        // v1.16/1.17-Format nach dem neuen Bedingungs-Regelwerk "DataActions".
        if ($this->MigrateLegacyEmsConfig()) {
            return;
        }
        if ($this->MigrateAutomationsToDataActions()) {
            return;
        }

        $this->RegisterProfiles();

        // Preiskurve (offizielle API) – bewusst UNABHÄNGIG vom Grid-Rewards-Teil unten: läuft auch,
        // wenn "Active" aus ist oder keine App-Zugangsdaten hinterlegt sind. Wählt ggf. das einzige
        // Zuhause automatisch aus und stößt dabei ein erneutes ApplyChanges an – dann hier abbrechen.
        if ($this->ApplyPriceChanges()) {
            return;
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('TokenRefresh', 0);
            $this->SetTimerInterval('StartWatchdog', 0);
            $this->SetTimerInterval('ReloginSequence', 0);
            $this->SetTimerInterval('EnergyTick', 0);
            $this->SetStatus(104); // inaktiv
            return;
        }

        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(201); // keine Zugangsdaten
            return;
        }

        // Login (nur falls Token fehlt/abgelaufen)
        if (!$this->EnsureToken()) {
            $this->SetStatus(210); // Login fehlgeschlagen
            return;
        }

        // Homes laden (für Dropdown im Formular)
        $this->GetHomesData();

        if ($this->ReadPropertyString('Home_ID') === '0' || $this->ReadPropertyString('Home_ID') === '') {
            $this->SetStatus(202); // kein Home gewählt
            return;
        }

        $this->SetStatus(102);
        $this->RegisterVariables();

        $this->RegisterMessageParent();
        $this->UpdateConfigurationForParent();
        $this->ScheduleTokenRefresh();

        // Wallbox-Aggregation einrichten
        $this->RegisterWallboxMessages();
        $this->AccumulateEnergyCounters(); // Zähler-Basis setzen (kein Delta über die Downtime)
        $this->WriteAttributeFloat('LastEnergyTs', microtime(true));
        // Baseline ohne Auslösen: verhindert Fehlauslösung einer Regel direkt nach dem Übernehmen,
        // falls ihre Bedingung zufällig schon erfüllt ist (Vorbild StromGedachtWidget).
        $this->SumWallboxes(false);
        $this->WriteAttributeFloat('LastPower', (float) $this->GetValueSafe('WallboxPowerTotal'));
        $this->UpdateKPI();
        $this->SetTimerInterval('EnergyTick', $this->ReadAttributeBoolean('EventActive') ? 30000 : 0);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Home-Dropdowns dynamisch füllen (Grid Rewards über die App-API, Preis-Zuhause über die
        // offizielle API - ein Personal Access Token kann andere/mehr Homes sehen als der App-Login).
        // WICHTIG: rekursiv patchen (ReplaceFormElements) - PriceHomeID liegt in einem
        // ExpansionPanel, eine Schleife nur über die oberste Ebene erreicht es nicht.
        $options = $this->BuildHomeOptions();
        $this->ReplaceFormElements($form['elements'], ['Home_ID'], function (array &$el) use ($options) {
            $el['options'] = $options;
        });
        $priceOptions = $this->BuildPriceHomeOptions();
        $this->ReplaceFormElements($form['elements'], ['PriceHomeID'], function (array &$el) use ($priceOptions) {
            $el['options'] = $priceOptions;
        });

        // Werte-Nachschau: Profil-Werte der gewählten Variable als Text anzeigen (IP-Symcon-Listen
        // können keine pro-Zeile abhängigen Dropdowns, da jede Zeile eine andere Zielvariable mit
        // anderem Profil haben kann). Die Kachel bietet dafür einen echten Dropdown im Regel-Editor.
        $lookupText = $this->GetLookupValuesText();
        $this->ReplaceFormElements($form['elements'], ['LookupResult'], function (array &$el) use ($lookupText) {
            $el['caption'] = $lookupText;
        });

        // "Wenn Datenpunkt"-Spalte der Automationen-Liste mit den verfügbaren Quellen befüllen
        // (verschachtelt in einem ExpansionPanel -> rekursiv patchen).
        $sourceOptions = $this->getAutomationSourceOptions();
        $patch = function (array &$elements) use (&$patch, $sourceOptions) {
            foreach ($elements as &$element) {
                if (!is_array($element)) {
                    continue;
                }
                if (($element['name'] ?? '') === 'DataActions' && isset($element['columns']) && is_array($element['columns'])) {
                    foreach ($element['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'Source') {
                            $col['edit']['options'] = $sourceOptions;
                        }
                    }
                    unset($col);
                }
                if (isset($element['items']) && is_array($element['items'])) {
                    $patch($element['items']);
                }
            }
            unset($element);
        };
        $patch($form['elements']);

        return json_encode($form);
    }

    /** Optionen des Grid-Rewards-Zuhause-Dropdowns aus der zwischengespeicherten App-API-Antwort. */
    private function BuildHomeOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select'), 'value' => '0']];
        $raw = $this->ReadAttributeString('Homes');
        if ($raw === '') {
            return $options;
        }
        $homes = json_decode($raw, true);
        foreach (($homes['data']['me']['homes'] ?? []) as $home) {
            $options[] = ['caption' => $home['title'] ?? $home['id'], 'value' => (string) $home['id']];
        }
        return $options;
    }

    /** Optionen des Preis-Zuhause-Dropdowns aus der zwischengespeicherten Antwort der offiziellen API. */
    private function BuildPriceHomeOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select'), 'value' => '0']];
        $raw = $this->ReadAttributeString('PriceHomes');
        if ($raw === '') {
            return $options;
        }
        $homes = json_decode($raw, true);
        foreach (($homes['data']['viewer']['homes'] ?? []) as $home) {
            $caption = $home['appNickname'] ?? ($home['address']['address1'] ?? $home['id']);
            $options[] = ['caption' => $caption, 'value' => (string) $home['id']];
        }
        return $options;
    }

    /**
     * Verfügbare "Wenn"-Datenpunkte für das Bedingungs-Regelwerk: eine feste, kuratierte Auswahl der
     * eigenen Variablen dieser Instanz (Ident als "value", damit Regeln unabhängig von der jeweiligen
     * Objekt-ID bleiben – wichtig, falls die Instanz einmal neu angelegt wird).
     */
    private function getAutomationSourceOptions(): array
    {
        return [
            ['caption' => 'Grid-Reward-Modus', 'value' => 'GridRewardMode'],
            ['caption' => 'Grid Reward aktiv', 'value' => 'Delivering'],
            ['caption' => 'Status-Detail', 'value' => 'StateReason'],
            ['caption' => 'Wallbox lädt', 'value' => 'WallboxCharging'],
            ['caption' => 'Wallbox-Daten gültig', 'value' => 'DataValid'],
            ['caption' => 'Wallbox-Leistung (gesamt)', 'value' => 'WallboxPowerTotal'],
        ];
    }

    /**
     * Button-Aktion: aktualisiert nur die Anzeige des Werte-Nachschau-Helfers (die Variable selbst ist
     * bereits als Property gespeichert, sobald sie ausgewählt und übernommen wurde).
     */
    public function ShowLookupValues(): void
    {
        $this->ReloadForm();
    }

    /**
     * Liefert die möglichen Werte der unter "Werte nachschlagen" gewählten Variable als lesbaren Text
     * (z. B. "0 = Gestoppt · 1 = Automatik · ..."), damit man sie in die Automationszeilen abtippen
     * kann, ohne die Werte an anderer Stelle nachsehen zu müssen.
     */
    private function GetLookupValuesText(): string
    {
        $targetID = $this->ReadPropertyInteger('LookupVariable');
        if ($targetID <= 0 || !IPS_VariableExists($targetID)) {
            return $this->Translate('Select a variable above, apply, then click "Show values".');
        }
        $options = json_decode($this->GetTargetValueOptions($targetID), true);
        if (!is_array($options) || count($options) === 0) {
            return $this->Translate('This variable has no fixed values - enter a number/text directly.');
        }
        $parts = [];
        foreach ($options as $o) {
            $parts[] = $o['v'] . ' = ' . $o['c'];
        }
        return implode('  ·  ', $parts);
    }

    /**
     * Mögliche Werte einer Variable als JSON [{v,c}] – zuerst über die IPS-9.0-Presentation
     * (Enumeration/Switch), dann über ein Legacy-Variablenprofil, zuletzt Boolean-Fallback (Ein/Aus).
     * Leeres Array = freie Eingabe. Wird sowohl für den Werte-Nachschau-Helfer im Formular als auch
     * für die Dropdowns im Regel-Editor der Kachel genutzt (dort per RequestAction abgefragt).
     */
    public function GetTargetValueOptions(int $VariableID): string
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return '[]';
        }
        $out = [];
        $var = IPS_GetVariable($VariableID);

        $pres = @IPS_GetVariablePresentation($VariableID);
        if (is_array($pres)) {
            $p = $pres['PRESENTATION'] ?? '';
            if ($p === VARIABLE_PRESENTATION_ENUMERATION) {
                $opts = json_decode((string) ($pres['OPTIONS'] ?? '[]'), true);
                if (is_array($opts)) {
                    foreach ($opts as $o) {
                        if (is_array($o) && isset($o['Value'])) {
                            $out[] = ['v' => $o['Value'], 'c' => (string) ($o['Caption'] ?? $o['Value'])];
                        }
                    }
                }
            } elseif ($p === VARIABLE_PRESENTATION_SWITCH) {
                $out[] = ['v' => 1, 'c' => (string) ($pres['CAPTION_ON'] ?? 'Ein')];
                $out[] = ['v' => 0, 'c' => (string) ($pres['CAPTION_OFF'] ?? 'Aus')];
            }
        }

        if (count($out) === 0) {
            $profile = ($var['VariableCustomProfile'] !== '') ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            if ($profile !== '' && IPS_VariableProfileExists($profile)) {
                foreach (IPS_GetVariableProfile($profile)['Associations'] as $a) {
                    $out[] = ['v' => $a['Value'], 'c' => (string) $a['Name']];
                }
            }
        }

        if (count($out) === 0 && (int) $var['VariableType'] === VARIABLETYPE_BOOLEAN) {
            $out = [['v' => 1, 'c' => 'Ein'], ['v' => 0, 'c' => 'Aus']];
        }
        return json_encode($out);
    }

    /**
     * Sucht Formularelemente per Name (auch verschachtelt in ExpansionPanel-"items") und wendet
     * darauf $callback an.
     */
    private function ReplaceFormElements(array &$elements, array $names, callable $callback): void
    {
        foreach ($elements as &$el) {
            if (isset($el['name']) && in_array($el['name'], $names, true)) {
                $callback($el);
            }
            if (isset($el['items']) && is_array($el['items'])) {
                $this->ReplaceFormElements($el['items'], $names, $callback);
            }
        }
        unset($el);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IM_CHANGESTATUS: // 10505 – Statuswechsel des Parent-I/O
                switch ($Data[0]) {
                    case IS_ACTIVE: // 102 – WebSocket verbunden
                        $this->SendDebug(__FUNCTION__, 'Parent aktiv – starte Autorisierung', 0);
                        $this->StartAuthorization();
                        break;
                    case IS_INACTIVE: // 104 – WebSocket getrennt
                        $this->SendDebug(__FUNCTION__, 'Parent inaktiv', 0);
                        $this->SetTimerInterval('StartWatchdog', 0);
                        $this->SetTimerInterval('ReloginSequence', 0);
                        break;
                }
                break;
            case KR_READY:
                $this->SendDebug(__FUNCTION__, 'Kernel bereit', 0);
                break;

            case VM_UPDATE: // Änderung einer Wallbox-Leistung oder -Energie
                $this->SumWallboxes();
                $this->EnergyStep();
                break;
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        $payload = json_decode($data['Buffer'] ?? '', true);
        if (!is_array($payload)) {
            return;
        }

        switch ($payload['type'] ?? '') {
            case 'connection_ack':
                $this->SendDebug(__FUNCTION__, 'connection_ack – sende Subscribe', 0);
                $this->SubscribeData();
                break;

            case 'ping': // graphql-transport-ws Keepalive
                $this->SendToWS(json_encode(['type' => 'pong']));
                break;

            case 'next':
                // Watchdog zurücksetzen (Verbindung lebt)
                $this->SetTimerInterval('StartWatchdog', 60000);
                $status = $payload['payload']['data']['gridRewardStatus'] ?? null;
                if (is_array($status)) {
                    $this->WriteAttributeString('LastRealStatus', json_encode($status));
                    $this->ProcessGridReward($status);
                } else {
                    $this->SendDebug(__FUNCTION__, 'next ohne gridRewardStatus: ' . json_encode($payload), 0);
                }
                break;

            case 'error':
                $this->SendDebug(__FUNCTION__, 'Error: ' . json_encode($payload), 0);
                break;

            case 'complete':
                // Subscription beendet – neu abonnieren
                $this->SendDebug(__FUNCTION__, 'complete – erneutes Subscribe', 0);
                $this->SubscribeData();
                break;
        }
    }

    /**
     * Button-Aktion: Zuhause-Liste neu laden (Login sicherstellen, Homes holen, Formular neu laden).
     */
    public function UpdateHomes(): void
    {
        $this->EnsureToken();
        $this->GetHomesData();
        $this->ReloadForm();
    }

    // ---------------------------------------------------------------------
    // Authentifizierung (App-Login → JWT)
    // ---------------------------------------------------------------------

    private function EnsureToken(): bool
    {
        $token = $this->ReadAttributeString('JWT');
        $exp = $this->ReadAttributeInteger('JWT_Exp');
        if ($token !== '' && $exp - time() > 60) {
            return true;
        }
        return $this->Login();
    }

    private function Login(): bool
    {
        $body = json_encode([
            'email'    => $this->ReadPropertyString('Email'),
            'password' => $this->ReadPropertyString('Password'),
        ]);

        $result = $this->HttpPost(self::AUTH_URL, $body, false);
        if ($result === null) {
            $this->SendDebug(__FUNCTION__, 'Login-Anfrage fehlgeschlagen', 0);
            return false;
        }

        $token = $result['token'] ?? '';
        if ($token === '') {
            $this->SendDebug(__FUNCTION__, 'Kein Token in Login-Antwort: ' . json_encode($result), 0);
            return false;
        }

        $this->WriteAttributeString('JWT', $token);
        $this->WriteAttributeInteger('JWT_Exp', $this->DecodeJwtExp($token));
        $this->SendDebug(__FUNCTION__, 'Login erfolgreich, Token gültig bis ' . date('d.m.Y H:i', $this->ReadAttributeInteger('JWT_Exp')), 0);
        return true;
    }

    private function DecodeJwtExp(string $jwt): int
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return time() + 3600;
        }
        $payload = json_decode($this->Base64UrlDecode($parts[1]), true);
        return (int) ($payload['exp'] ?? (time() + 3600));
    }

    private function Base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    public function TokenRefresh(): void
    {
        $this->SendDebug(__FUNCTION__, 'Token-Refresh', 0);
        if ($this->Login()) {
            $this->UpdateConfigurationForParent(); // neuer Authorization-Header → Reconnect
            $this->ScheduleTokenRefresh();
        }
    }

    private function ScheduleTokenRefresh(): void
    {
        $exp = $this->ReadAttributeInteger('JWT_Exp');
        // 5 Minuten vor Ablauf neu einloggen, mindestens in 60 s
        $seconds = max(60, $exp - time() - 300);
        $this->SetTimerInterval('TokenRefresh', $seconds * 1000);
    }

    // ---------------------------------------------------------------------
    // App-API: Homes
    // ---------------------------------------------------------------------

    private function GetHomesData(): void
    {
        $token = $this->ReadAttributeString('JWT');
        if ($token === '') {
            return;
        }
        $body = json_encode(['query' => '{ me { homes { id title } } }']);
        $result = $this->HttpPost(self::GQL_URL, $body, true);
        if ($result === null) {
            return;
        }
        $this->WriteAttributeString('Homes', json_encode($result));
        $this->SendDebug(__FUNCTION__, json_encode($result), 0);

        // Offenes Formular direkt nachziehen (siehe FetchPriceHomes) – ohne diesen Push bliebe das
        // Dropdown nach dem Übernehmen leer, bis man das Formular schließt und neu öffnet.
        $this->UpdateFormField('Home_ID', 'options', json_encode($this->BuildHomeOptions()));
    }

    // ---------------------------------------------------------------------
    // Offizielle Tibber-API: Endkunden-Preiskurve (Personal Access Token)
    //
    // Bewusst eine ZWEITE, unabhängige Anbindung neben der App-API oben: anderes Auth-Verfahren
    // (Bearer-Token statt E-Mail/Passwort-Login), andere URL, kein Token-Refresh nötig (Personal
    // Access Tokens laufen nicht ab). Deckt NUR die Preiskurve ab - Verbrauch/Live-Messung bleibt
    // bei "Tibber V.2", um keine Doppelung zu erzeugen.
    // ---------------------------------------------------------------------

    /**
     * Preis-Teil von ApplyChanges(): lädt bei Bedarf die Home-Liste (fürs Dropdown) und startet/stoppt
     * den Preis-Refresh-Timer. Läuft unabhängig vom Grid-Rewards-Status/-Fehlercode - ein falscher
     * oder fehlender PriceApiToken darf den Grid-Rewards-Teil nicht beeinflussen und umgekehrt.
     *
     * @return bool true, wenn ein Zuhause automatisch vorausgewählt und deshalb ein erneutes
     *              ApplyChanges angestoßen wurde (Aufrufer bricht dann ab).
     */
    private function ApplyPriceChanges(): bool
    {
        $this->MaintainVariable('CurrentPrice', $this->Translate('Current price'), VARIABLETYPE_FLOAT, 'Tibber.PricePerKWh', 100, true);
        $this->MaintainVariable('CurrentPriceLevel', $this->Translate('Current price level'), VARIABLETYPE_STRING, '', 101, true);
        $this->EnsurePriceArchiving();

        $token = $this->ReadPropertyString('PriceApiToken');
        if ($token === '') {
            $this->SetTimerInterval('PriceRefresh', 0);
            $this->SetTimerInterval('PriceTick', 0);
            return false;
        }

        // Home-Liste holen, wenn sie fehlt ODER der Token gewechselt hat (ein anderer Token kann
        // andere Zuhause sehen; sonst bliebe stumm die alte Liste stehen).
        $tokenHash = md5($token);
        if ($this->ReadAttributeString('PriceHomes') === '' || $this->ReadAttributeString('PriceHomesToken') !== $tokenHash) {
            $this->FetchPriceHomes();
            $this->WriteAttributeString('PriceHomesToken', $tokenHash);
        }

        // Genau ein Zuhause -> automatisch auswählen, statt den Nutzer ein Dropdown ohne Alternative
        // bedienen zu lassen. Einmalig, da PriceHomeID danach nicht mehr '0' ist (keine Schleife).
        if ($this->ReadPropertyString('PriceHomeID') === '0') {
            $homes = $this->BuildPriceHomeOptions(); // [0] ist der "Bitte wählen"-Platzhalter
            if (count($homes) === 2) {
                $this->SendDebug(__FUNCTION__, 'Einziges Preis-Zuhause automatisch gewählt: ' . $homes[1]['caption'], 0);
                IPS_SetProperty($this->InstanceID, 'PriceHomeID', $homes[1]['value']);
                IPS_ApplyChanges($this->InstanceID);
                return true;
            }
            $this->SetTimerInterval('PriceRefresh', 0);
            $this->SetTimerInterval('PriceTick', 0);
            return false;
        }

        $this->FetchAndCachePriceCurve();
        $this->SetTimerInterval('PriceRefresh', 20 * 60 * 1000);
        return false;
    }

    /** Button-Aktion: Preis-Zuhause-Liste neu laden (Formular danach neu laden). */
    public function UpdatePriceHomes(): void
    {
        $this->FetchPriceHomes();
        $this->ReloadForm();
    }

    private function FetchPriceHomes(): void
    {
        $token = $this->ReadPropertyString('PriceApiToken');
        if ($token === '') {
            return;
        }
        $body = json_encode(['query' => '{ viewer { homes { id appNickname address { address1 } } } }']);
        $result = $this->HttpPost(self::PRICE_GQL_URL, $body, true, $token);
        if ($result === null) {
            $this->SendDebug(__FUNCTION__, 'Abruf der Preis-Zuhause-Liste fehlgeschlagen', 0);
            return;
        }
        $this->WriteAttributeString('PriceHomes', json_encode($result));
        $this->SendDebug(__FUNCTION__, json_encode($result), 0);

        // Das bereits geöffnete Formular direkt nachziehen: GetConfigurationForm() läuft nur beim
        // Öffnen, ohne diesen Push bliebe das Dropdown bis zum Schließen/Neuöffnen leer.
        $this->UpdateFormField('PriceHomeID', 'options', json_encode($this->BuildPriceHomeOptions()));
    }

    /** Timer-Callback: Preiskurve neu abfragen (alle 20 Minuten, siehe Create()). */
    public function PriceRefresh(): void
    {
        if ($this->ReadPropertyString('PriceApiToken') === '' || $this->ReadPropertyString('PriceHomeID') === '0') {
            return;
        }
        $this->FetchAndCachePriceCurve();
    }

    /**
     * Timer-Callback: schaltet den aktuellen Preis exakt zum Slot-Wechsel um und plant sich auf den
     * nächsten Slot-Beginn. Arbeitet rein aus dem Cache (kein API-Aufruf) - der Preisverlauf für die
     * kommenden Stunden steht ja bereits fest.
     */
    public function PriceTick(): void
    {
        $this->ApplyCurrentPriceSlot();
        $this->ScheduleNextPriceTick();
    }

    /**
     * Schreibt den Preis des Slots, der den aktuellen Zeitpunkt abdeckt, in die Komfort-Variablen.
     * Zeitgenau, damit ein archivierter Verlauf den Preiswechsel auf die Sekunde am Slot-Beginn zeigt
     * (Voraussetzung für eine belastbare Rechnungsprüfung).
     */
    private function ApplyCurrentPriceSlot(): void
    {
        $now = time();
        foreach ($this->GetCachedPriceSlots() as $slot) {
            // Intervall [start, end) - end ist exklusiv, siehe Vertrag GetPriceCurve()
            if ($now < (int) $slot['start'] || $now >= (int) $slot['end']) {
                continue;
            }
            // Variable in €/kWh; der Cache hält ct/kWh. 4 Nachkommastellen, weil eine Rundung auf 3
            // bereits ~0,3 ct/kWh Fehler bedeuten kann - bei einer Rechnungsprüfung nicht egal.
            $this->SetValueIfExists('CurrentPrice', round(((float) $slot['price']) / 100, 4));
            $this->SetValueIfExists('CurrentPriceLevel', (string) ($slot['level_tibber'] ?? ''));
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Kein Preis-Slot deckt den aktuellen Zeitpunkt ab', 0);
    }

    /** Plant PriceTick auf den nächsten Slot-Beginn (Fallback: in 5 Minuten erneut prüfen). */
    private function ScheduleNextPriceTick(): void
    {
        $now = time();
        $next = 0;
        foreach ($this->GetCachedPriceSlots() as $slot) {
            if ((int) $slot['start'] > $now) {
                $next = (int) $slot['start'];
                break; // Slots sind aufsteigend sortiert
            }
        }
        // Ohne bekannten Folge-Slot regelmäßig nachsehen; nie länger als eine Stunde warten, damit
        // sich der Takt nach einem Neustart oder einer Datenlücke von selbst wieder einfängt.
        $seconds = ($next > $now) ? ($next - $now + 1) : 300;
        $this->SetTimerInterval('PriceTick', max(5, min($seconds, 3600)) * 1000);
    }

    /**
     * Aktiviert bei Bedarf die Archivierung des Preises. Schaltet bewusst NUR ein, nie aus: hat der
     * Nutzer die Archivierung selbst gesetzt, darf ein deaktiviertes Häkchen sie nicht stillschweigend
     * wieder entfernen (und damit die bisherige Historie vom weiteren Mitschreiben abschneiden).
     */
    private function EnsurePriceArchiving(): void
    {
        if (!$this->ReadPropertyBoolean('ArchivePrice')) {
            return;
        }
        $vid = @IPS_GetObjectIDByIdent('CurrentPrice', $this->InstanceID);
        if ($vid === false || $vid <= 0) {
            return;
        }
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
        if (count($archives) === 0) {
            $this->SendDebug(__FUNCTION__, 'Kein Archive Control gefunden - Archivierung nicht möglich', 0);
            return;
        }
        $archive = (int) $archives[0];
        if (AC_GetLoggingStatus($archive, $vid)) {
            return; // bereits aktiv
        }
        AC_SetLoggingStatus($archive, $vid, true);
        // 0 = Standard (Mittelwertbildung), NICHT Zähler: ein Preis ist ein Momentanwert, keine
        // aufsummierte Größe.
        AC_SetAggregationType($archive, $vid, 0);
        IPS_ApplyChanges($archive);
        $this->SendDebug(__FUNCTION__, 'Archivierung für "Aktueller Preis" aktiviert (Variable ' . $vid . ')', 0);
    }

    /**
     * Fragt die Preiskurve (heute + morgen, sobald verfügbar) für das gewählte Preis-Zuhause ab,
     * normalisiert sie auf das Verbund-Format und schreibt sie in PriceCache. Aktualisiert außerdem
     * die Komfort-Variablen CurrentPrice/CurrentPriceLevel aus dem "current"-Slot der Antwort.
     */
    private function FetchAndCachePriceCurve(): bool
    {
        $token = $this->ReadPropertyString('PriceApiToken');
        $homeId = $this->ReadPropertyString('PriceHomeID');
        if ($token === '' || $homeId === '0') {
            return false;
        }

        // Bewusst ohne das "current"-Feld: der aktuelle Preis wird aus den heutigen Slots abgeleitet
        // (PriceTick), damit Variable und Vertrag garantiert dieselbe Quelle haben und nicht
        // auseinanderlaufen können.
        $query = '{ viewer { home(id: "' . $homeId . '") { currentSubscription { priceInfo {'
            . ' today { total startsAt level }'
            . ' tomorrow { total startsAt level }'
            . ' } } } } }';
        $result = $this->HttpPost(self::PRICE_GQL_URL, json_encode(['query' => $query]), true, $token);
        if ($result === null) {
            $this->SendDebug(__FUNCTION__, 'Preisabfrage fehlgeschlagen', 0);
            return false;
        }

        $priceInfo = $result['data']['viewer']['home']['currentSubscription']['priceInfo'] ?? null;
        if (!is_array($priceInfo)) {
            $this->SendDebug(__FUNCTION__, 'Keine priceInfo in der Antwort: ' . json_encode($result), 0);
            return false;
        }

        $today = is_array($priceInfo['today'] ?? null) ? $priceInfo['today'] : [];
        $tomorrow = is_array($priceInfo['tomorrow'] ?? null) ? $priceInfo['tomorrow'] : [];
        $slots = $this->NormalizePriceSlots(array_merge($today, $tomorrow));

        $this->WriteAttributeString('PriceCache', json_encode(['fetchedAt' => time(), 'slots' => $slots]));
        $this->SendDebug(__FUNCTION__, count($slots) . ' Preis-Slots zwischengespeichert', 0);

        // Aktuellen Slot sofort übernehmen und den Takt auf den nächsten Slot-Wechsel setzen.
        $this->ApplyCurrentPriceSlot();
        $this->ScheduleNextPriceTick();
        return true;
    }

    /**
     * Wandelt die rohen Tibber-Preis-Slots (startsAt/total/level je Slot) in das Verbund-Format um:
     * [{start,end,price,basis,netzentgelt,level,level_tibber}]. "end" wird aus dem Abstand zum
     * jeweils nächsten Slot berechnet (Tibber liefert je nach Tarif Stunden- oder Viertelstunden-
     * Werte, exklusiv: end eines Slots = start des nächsten); der letzte Slot übernimmt die Dauer
     * des vorherigen (Fallback 3600 s, falls nur ein Slot vorliegt). Fehlende/ungültige Rohdaten
     * werden übersprungen statt erfunden - Lücken in der zurückgegebenen Liste sind zulässig.
     */
    private function NormalizePriceSlots(array $rawSlots): array
    {
        $starts = [];
        foreach ($rawSlots as $s) {
            if (!is_array($s) || !isset($s['startsAt'])) {
                continue;
            }
            $starts[] = strtotime((string) $s['startsAt']);
        }

        $out = [];
        $lastDuration = 3600;
        foreach ($rawSlots as $i => $s) {
            if (!is_array($s) || !isset($s['startsAt'], $s['total'])) {
                continue;
            }
            $start = strtotime((string) $s['startsAt']);
            $duration = isset($starts[$i + 1]) ? ($starts[$i + 1] - $start) : $lastDuration;
            if ($duration > 0) {
                $lastDuration = $duration;
            }
            $tibberLevel = (string) ($s['level'] ?? '');
            $out[] = [
                'start'        => $start,
                'end'          => $start + $duration,
                'price'        => round(((float) $s['total']) * 100, 2),
                'basis'        => 'endkunde',
                'netzentgelt'  => 'enthalten',
                // Einstufung ist Sache des EMS (Steuerhoheits-Regel: Entscheidungen gehören dorthin,
                // nicht in die Signalquelle) - hier bewusst IMMER null, um keine zweite, abweichende
                // Taxonomie neben der des EMS zu erzeugen. Tibbers eigenes (5-stufiges) Vokabular
                // bleibt separat in level_tibber erhalten, unverändert und unübersetzt.
                'level'        => null,
                'level_tibber' => $tibberLevel !== '' ? $tibberLevel : null,
            ];
        }
        return $out;
    }

    /**
     * Öffentlicher Vertrag für preisgetriebene Automationen/EMS: Endkunden-Preiskurve (heute + morgen,
     * sobald von Tibber veröffentlicht) als Liste von Zeit-Slots, aufsteigend nach 'start'. Konvention
     * wie MHUB_GetFunctions (Liste statt Einzelobjekt, auch bei leerem/einzelnem Ergebnis).
     *
     * Rückgabe je Slot: ['start'=>int Unix (inklusiv), 'end'=>int Unix (EXKLUSIV, Intervall
     * [start,end)), 'price'=>float ct/kWh brutto inkl. USt., 'basis'=>'endkunde',
     * 'netzentgelt'=>'enthalten', 'level'=>null, 'level_tibber'=>string|null].
     *
     * 'basis'/'netzentgelt' sind konstant, weil dieses Modul ausschließlich den vollständigen
     * Tibber-Endkundenpreis liefert (inkl. evtl. zeitvariabler Netzentgelte wie §14a Modul 3) -
     * nie einen reinen Spotpreis. 'level' ist bewusst IMMER null (Einstufung trifft das EMS
     * einheitlich für alle Quellen, siehe CLAUDE.md) - NICHT durchreichen, sonst entsteht dieselbe
     * Preislage mit zwei unterschiedlichen Einstufungen. Lücken in der Liste sind zulässig, Aufrufer
     * dürfen keine lückenlose Abdeckung annehmen. Ist der Cache leer (frisch angelegte Instanz, noch
     * kein Timer gelaufen), wird einmalig synchron nachgeladen, damit der erste Aufruf nicht leer
     * zurückkommt.
     */
    public function GetPriceCurve(): array
    {
        if ($this->ReadPropertyString('PriceApiToken') === '' || $this->ReadPropertyString('PriceHomeID') === '0') {
            return [];
        }
        $cache = json_decode($this->ReadAttributeString('PriceCache'), true);
        if (!is_array($cache) || (int) ($cache['fetchedAt'] ?? 0) === 0) {
            $this->FetchAndCachePriceCurve();
        }
        return $this->GetCachedPriceSlots();
    }

    /** Zwischengespeicherte Preis-Slots (ohne Nachladen) - gemeinsame Basis für Timer und Vertrag. */
    private function GetCachedPriceSlots(): array
    {
        $cache = json_decode($this->ReadAttributeString('PriceCache'), true);
        return is_array($cache['slots'] ?? null) ? $cache['slots'] : [];
    }

    // ---------------------------------------------------------------------
    // WebSocket-Parent-Konfiguration & Handshake
    // ---------------------------------------------------------------------

    public function GetConfigurationForParent()
    {
        $headers = [
            ['Name' => 'Authorization', 'Value' => 'Bearer ' . $this->ReadAttributeString('JWT')],
            ['Name' => 'Sec-WebSocket-Protocol', 'Value' => 'graphql-transport-ws'],
            ['Name' => 'User-Agent', 'Value' => 'Symcon-TibberGridReward/1.0'],
        ];
        $config = [
            'Active'            => $this->ReadPropertyBoolean('Active'),
            'URL'               => self::WS_URL,
            'VerifyCertificate' => true,
            'Headers'           => json_encode($headers),
        ];
        return json_encode($config);
    }

    private function UpdateConfigurationForParent(): void
    {
        $parentId = (int) @IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId <= 0) {
            $this->SendDebug(__FUNCTION__, 'Kein Parent-I/O vorhanden', 0);
            return;
        }
        $script = 'IPS_SetConfiguration(' . $parentId . ', \'' . $this->GetConfigurationForParent() . '\');' . PHP_EOL;
        $script .= 'IPS_ApplyChanges(' . $parentId . ');';
        IPS_RunScriptText($script); // triggert IM_CHANGESTATUS im MessageSink
    }

    private function RegisterMessageParent(): int
    {
        $ioId = (int) @IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $prev = $this->ReadAttributeInteger('Parent_IO');
        if ($ioId !== $prev) {
            if ($prev !== 0) {
                $this->UnregisterMessage($prev, IM_CHANGESTATUS);
            }
            $this->WriteAttributeInteger('Parent_IO', $ioId);
        }
        if ($ioId !== 0) {
            $this->RegisterMessage($ioId, IM_CHANGESTATUS);
        }
        return $ioId;
    }

    private function StartAuthorization(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        // Auth läuft über den Authorization-Header → connection_init ohne Payload
        $this->SendToWS(json_encode(['type' => 'connection_init']));
        $this->SendDebug(__FUNCTION__, 'connection_init gesendet', 0);
    }

    private function SubscribeData(): void
    {
        $query = 'subscription gridRewardsSubscription($homeId: String!) {'
            . ' gridRewardStatus(homeId: $homeId) {'
            . ' homeId'
            . ' state { __typename ... on GridRewardAvailable { kind } ... on GridRewardUnavailable { reasons } ... on GridRewardDelivering { reason } }'
            . ' rewardCurrency rewardCurrentMonth rewardAllTime'
            . ' flexDevices {'
            . ' __typename'
            . ' ... on GridRewardVehicle { kind vehicleId shortName make isPluggedIn isSmartChargingEnabled state { __typename ... on GridRewardAvailable { kind } ... on GridRewardUnavailable { reasons } ... on GridRewardDelivering { reason } } }'
            . ' ... on GridRewardBattery { kind batteryId shortName make isSmartModeEnabled state { __typename ... on GridRewardAvailable { kind } ... on GridRewardUnavailable { reasons } ... on GridRewardDelivering { reason } } }'
            . ' } } }';

        $frame = [
            'id'      => (string) $this->ReadPropertyInteger('SubID'),
            'type'    => 'subscribe',
            'payload' => [
                'operationName' => 'gridRewardsSubscription',
                'variables'     => ['homeId' => $this->ReadPropertyString('Home_ID')],
                'query'         => $query,
            ],
        ];
        $this->SendToWS(json_encode($frame));
        $this->SendDebug(__FUNCTION__, 'Subscribe gesendet für Home ' . $this->ReadPropertyString('Home_ID'), 0);
    }

    private function SendToWS(string $payload): void
    {
        $data = json_encode(['DataID' => self::WS_DATA_ID, 'Buffer' => $payload]);
        $this->SendDebug(__FUNCTION__, $payload, 0);
        @$this->SendDataToParent($data);
    }

    /**
     * Simuliert einen Grid-Reward-Status zu Testzwecken – durchläuft exakt denselben Code wie ein
     * echtes Tibber-Ereignis (Modusbestimmung, Energiezählung, Einsatz-Log und die konfigurierten
     * EMS-Aktionen). So kann jeder seine EMS-Verdrahtung testen, ohne auf einen echten, seltenen
     * Einsatz zu warten. ACHTUNG: löst dabei wirklich die im Formular hinterlegten RequestAction-
     * Befehle an die konfigurierten Zielvariablen aus.
     *
     * @param string $reason 'available' (kein Einsatz), 'excess' (Laden) oder 'shortage' (Drosselung)
     */
    public function Simulate(string $reason): void
    {
        $state = ($reason === 'excess' || $reason === 'shortage')
            ? ['__typename' => 'GridRewardDelivering', 'reason' => $reason]
            : ['__typename' => 'GridRewardAvailable', 'kind' => 'available'];

        // Echte Flex-Geräte-Liste aus dem letzten realen Status übernehmen, statt sie zu leeren.
        $cached = json_decode($this->ReadAttributeString('LastRealStatus'), true);
        $flexDevices = is_array($cached['flexDevices'] ?? null) ? $cached['flexDevices'] : [];

        $this->SendDebug(__FUNCTION__, 'Simuliere Status: ' . $reason, 0);
        $this->ProcessGridReward([
            'state'              => $state,
            'rewardCurrency'     => (string) $this->GetValueSafe('Currency'),
            'rewardCurrentMonth' => (float) $this->GetValueSafe('RewardCurrentMonth'),
            'rewardAllTime'      => (float) $this->GetValueSafe('RewardAllTime'),
            'flexDevices'        => $flexDevices,
        ]);
    }

    /**
     * Beendet die Simulation: zeigt sofort den zuletzt zwischengespeicherten echten Status als
     * Näherung (kein Warten), fordert aber zusätzlich einen FRISCHEN Status direkt von Tibber an
     * (erneutes Ab-/Anmelden der laufenden Subscription). Der frische Push kommt als normales
     * "next"-Paket herein und überschreibt die Näherung automatisch, falls sich der echte Status
     * inzwischen geändert haben sollte – zuverlässiger als sich allein auf den Cache zu verlassen.
     */
    public function ResetSimulation(): void
    {
        $cached = json_decode($this->ReadAttributeString('LastRealStatus'), true);
        if (is_array($cached)) {
            $this->SendDebug(__FUNCTION__, 'Zeige zwischengespeicherten Status, fordere frischen Push an', 0);
            $this->ProcessGridReward($cached);
        } else {
            $this->SendDebug(__FUNCTION__, 'Noch kein echter Status bekannt, fordere frischen Push an', 0);
        }

        // Laufende Subscription sauber beenden und neu abonnieren -> Tibber schickt umgehend den
        // aktuellen Status als frisches "next".
        $this->SendToWS(json_encode(['id' => (string) $this->ReadPropertyInteger('SubID'), 'type' => 'complete']));
        $this->SubscribeData();
    }

    // ---------------------------------------------------------------------
    // Verarbeitung des Grid-Reward-Status
    // ---------------------------------------------------------------------

    private function ProcessGridReward(array $status): void
    {
        $state = $status['state'] ?? [];
        [$stateName, $stateReason, $delivering] = $this->ParseState($state);

        $this->SetValueIfExists('Delivering', $delivering);
        $this->SetValueIfExists('State', $stateName);
        $this->SetValueIfExists('StateReason', $stateReason);
        $this->SetValueIfExists('Currency', (string) ($status['rewardCurrency'] ?? ''));
        $this->SetValueIfExists('RewardCurrentMonth', (float) ($status['rewardCurrentMonth'] ?? 0));
        $this->SetValueIfExists('RewardAllTime', (float) ($status['rewardAllTime'] ?? 0));

        $devices = $status['flexDevices'] ?? [];
        $this->SetValueIfExists('FlexDeviceCount', count($devices));
        $this->SetValueIfExists('FlexDevices', $this->FormatFlexDevices($devices));

        // Modus (nutzt Delivering/StateReason + Ladezustand), Event-Flanken, Sollwert + KPI
        $this->SumWallboxes();
        $this->UpdateKPI();

        $this->SendDebug(__FUNCTION__, 'State=' . $stateName . ' Delivering=' . ($delivering ? '1' : '0') . ' Reason=' . $stateReason, 0);
    }

    /**
     * @return array{0:string,1:string,2:bool} [Anzeige-Status, Begründung, Delivering-Flag]
     */
    private function ParseState(array $state): array
    {
        $typename = $state['__typename'] ?? '';
        switch ($typename) {
            case 'GridRewardDelivering':
                return [$this->Translate('Delivering'), (string) ($state['reason'] ?? ''), true];
            case 'GridRewardAvailable':
                return [$this->Translate('Available'), (string) ($state['kind'] ?? ''), false];
            case 'GridRewardUnavailable':
                $reasons = $state['reasons'] ?? [];
                return [$this->Translate('Unavailable'), is_array($reasons) ? implode(', ', $reasons) : (string) $reasons, false];
            default:
                return [$this->Translate('Unknown'), '', false];
        }
    }

    private function FormatFlexDevices(array $devices): string
    {
        $lines = [];
        foreach ($devices as $d) {
            $type = ($d['__typename'] ?? '') === 'GridRewardBattery' ? $this->Translate('Battery') : $this->Translate('Vehicle');
            $name = $d['shortName'] ?? ($d['make'] ?? '?');
            [$st, , ] = $this->ParseState($d['state'] ?? []);

            $extra = [];
            if (array_key_exists('isPluggedIn', $d)) {
                $extra[] = $this->Translate('plugged in') . ': ' . ($d['isPluggedIn'] ? $this->Translate('yes') : $this->Translate('no'));
            }
            if (array_key_exists('isSmartChargingEnabled', $d)) {
                $extra[] = $this->Translate('smart charging') . ': ' . ($d['isSmartChargingEnabled'] ? $this->Translate('yes') : $this->Translate('no'));
            }
            if (array_key_exists('isSmartModeEnabled', $d)) {
                $extra[] = $this->Translate('smart mode') . ': ' . ($d['isSmartModeEnabled'] ? $this->Translate('yes') : $this->Translate('no'));
            }
            $line = $name . ' (' . $type . ') · ' . $st;
            if ($extra) {
                $line .= ' · ' . implode(' · ', $extra);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------------
    // Variablen & Profile
    // ---------------------------------------------------------------------

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('Tibber.Reward')) {
            IPS_CreateVariableProfile('Tibber.Reward', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('Tibber.Reward', 2);
            IPS_SetVariableProfileIcon('Tibber.Reward', 'Euro');
            IPS_SetVariableProfileText('Tibber.Reward', '', ' €');
        }
        if (!IPS_VariableProfileExists('Tibber.RatePerKWh')) {
            IPS_CreateVariableProfile('Tibber.RatePerKWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('Tibber.RatePerKWh', 3);
            IPS_SetVariableProfileIcon('Tibber.RatePerKWh', 'Euro');
            IPS_SetVariableProfileText('Tibber.RatePerKWh', '', ' €/kWh');
        }
        if (!IPS_VariableProfileExists('Tibber.PricePerKWh')) {
            IPS_CreateVariableProfile('Tibber.PricePerKWh', VARIABLETYPE_FLOAT);
            // 4 Nachkommastellen: Tibber liefert den Preis so fein, und bei einer Rechnungsprüfung
            // schlägt eine Rundung auf 3 Stellen bereits mit rund 0,3 ct/kWh zu Buche.
            IPS_SetVariableProfileDigits('Tibber.PricePerKWh', 4);
            IPS_SetVariableProfileIcon('Tibber.PricePerKWh', 'Euro');
            IPS_SetVariableProfileText('Tibber.PricePerKWh', '', ' €/kWh');
        }
        if (!IPS_VariableProfileExists('Tibber.GridRewardMode')) {
            IPS_CreateVariableProfile('Tibber.GridRewardMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('Tibber.GridRewardMode', 'Energy');
        }
        // Assoziationen außerhalb der Existenzprüfung, damit Bestandsinstallationen aktualisiert werden
        IPS_SetVariableProfileAssociation('Tibber.GridRewardMode', 0, $this->Translate('Normal'), '', 0x9FB0C0);
        IPS_SetVariableProfileAssociation('Tibber.GridRewardMode', 1, $this->Translate('Car charging'), '', 0x2BB3C0);
        IPS_SetVariableProfileAssociation('Tibber.GridRewardMode', 2, $this->Translate('Grid reward: charge'), '', 0x27D07F);
        IPS_SetVariableProfileAssociation('Tibber.GridRewardMode', 3, $this->Translate('Grid reward: curtailment'), '', 0xE8A13A);
    }

    private function RegisterVariables(): void
    {
        // Alt-Variable Tile (~HTMLBox) entfernen – die Kachel ist jetzt das separate Modul TibberGridRewardTile.
        $this->MaintainVariable('Tile', '', VARIABLETYPE_STRING, '', 0, false);

        $pos = 0;
        $this->MaintainVariable('Delivering', $this->Translate('Grid Reward active'), VARIABLETYPE_BOOLEAN, '~Alert', $pos++, true);
        $this->MaintainVariable('State', $this->Translate('Status'), VARIABLETYPE_STRING, '', $pos++, true);
        $this->MaintainVariable('StateReason', $this->Translate('Status detail'), VARIABLETYPE_STRING, '', $pos++, true);
        $this->MaintainVariable('RewardCurrentMonth', $this->Translate('Reward current month'), VARIABLETYPE_FLOAT, 'Tibber.Reward', $pos++, true);
        $this->MaintainVariable('RewardAllTime', $this->Translate('Reward total'), VARIABLETYPE_FLOAT, 'Tibber.Reward', $pos++, true);
        $this->MaintainVariable('Currency', $this->Translate('Currency'), VARIABLETYPE_STRING, '', $pos++, true);
        $this->MaintainVariable('FlexDeviceCount', $this->Translate('Flex devices'), VARIABLETYPE_INTEGER, '', $pos++, true);
        $this->MaintainVariable('FlexDevices', $this->Translate('Flex device list'), VARIABLETYPE_STRING, '', $pos++, true);

        // Wallbox-Aggregation / EMS
        $this->MaintainVariable('WallboxPowerTotal', $this->Translate('Wallbox power (total)'), VARIABLETYPE_FLOAT, '~Watt', $pos++, true);
        $this->MaintainVariable('GridRewardWallboxRequest', $this->Translate('Grid import request'), VARIABLETYPE_FLOAT, '~Watt', $pos++, true);
        $this->MaintainVariable('GridRewardMode', $this->Translate('Grid reward mode'), VARIABLETYPE_INTEGER, 'Tibber.GridRewardMode', $pos++, true);
        $this->MaintainVariable('WallboxCharging', $this->Translate('Wallbox charging'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);
        $this->MaintainVariable('DataValid', $this->Translate('Wallbox data valid'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, true);

        // Energie-Statistik
        $this->MaintainVariable('GridRewardEnergyEvent', $this->Translate('Grid reward energy (event)'), VARIABLETYPE_FLOAT, '~Electricity', $pos++, true);
        $this->MaintainVariable('GridRewardEnergyToday', $this->Translate('Grid reward energy (today)'), VARIABLETYPE_FLOAT, '~Electricity', $pos++, true);
        $this->MaintainVariable('GridRewardEnergyMonth', $this->Translate('Grid reward energy (month)'), VARIABLETYPE_FLOAT, '~Electricity', $pos++, true);
        $this->MaintainVariable('GridRewardEnergyTotal', $this->Translate('Grid reward energy (total)'), VARIABLETYPE_FLOAT, '~Electricity', $pos++, true);
        $this->MaintainVariable('GridRewardEffectiveRate', $this->Translate('Effective reward rate'), VARIABLETYPE_FLOAT, 'Tibber.RatePerKWh', $pos++, true);

        // Einsatz-Log
        $this->MaintainVariable('LastEventStart', $this->Translate('Last event start'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $pos++, true);
        $this->MaintainVariable('LastEventEnd', $this->Translate('Last event end'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $pos++, true);
        $this->MaintainVariable('LastEventDuration', $this->Translate('Last event duration'), VARIABLETYPE_STRING, '', $pos++, true);
        $this->MaintainVariable('LastEventEnergy', $this->Translate('Last event energy'), VARIABLETYPE_FLOAT, '~Electricity', $pos++, true);
    }

    private function SetValueIfExists(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id !== false && $id > 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function GetValueSafe(string $ident)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return ($id !== false && $id > 0) ? GetValue($id) : 0;
    }

    // ---------------------------------------------------------------------
    // Wallbox-Aggregation, Energie-Statistik, Einsatz-Log
    // ---------------------------------------------------------------------

    private function RegisterWallboxMessages(): void
    {
        // alte VM_UPDATE-Registrierungen und Referenzen lösen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }

        foreach ($this->GetWallboxRows() as $r) {
            foreach (['PowerID', 'EnergyID'] as $col) {
                $vid = (int) ($r[$col] ?? 0);
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
        }
    }

    private function GetWallboxRows(): array
    {
        $rows = json_decode($this->ReadPropertyString('Wallboxes'), true);
        if (!is_array($rows)) {
            return [];
        }
        // nur aktive Zeilen
        return array_values(array_filter($rows, static function ($r) {
            return !empty($r['Active']);
        }));
    }

    private function SumWallboxes(bool $fire = true): void
    {
        $maxAge = $this->ReadPropertyInteger('MaxAge');
        $total = 0.0;
        $allValid = true;
        $anyActive = false;

        foreach ($this->GetWallboxRows() as $r) {
            $vid = (int) ($r['PowerID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                $allValid = false;
                continue;
            }
            $anyActive = true;
            $factor = (float) ($r['Factor'] ?? 1);
            if ($factor == 0.0) {
                $factor = 1.0;
            }
            if ($maxAge > 0) {
                $info = IPS_GetVariable($vid);
                if ((time() - (int) $info['VariableUpdated']) > $maxAge) {
                    $allValid = false; // veralteter Messwert -> nicht mitzählen
                    continue;
                }
            }
            $total += (float) GetValue($vid) * $factor;
        }

        $charging = $total > $this->ReadPropertyFloat('ChargingThreshold');
        $this->SetValueIfExists('WallboxPowerTotal', $total);
        $this->SetValueIfExists('WallboxCharging', $charging);
        $this->SetValueIfExists('DataValid', $anyActive ? $allValid : true);

        $this->UpdateMode($charging);
        $mode = (int) $this->GetValueSafe('GridRewardMode');
        // „Wallbox aus Netz" gilt, wenn das Auto lädt – normal (1) oder bei Grid-Reward-Laden (2).
        $request = ($mode === 1 || $mode === 2) ? $total : 0.0;
        $this->SetValueIfExists('GridRewardWallboxRequest', $request);

        try {
            $this->evaluateDataActions($fire);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'Automation-Fehler: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Setzt den EMS-Modus und steuert die Energie-/Log-Flanken (Grid-Reward-Laden = excess = Modus 2).
     */
    private function UpdateMode(bool $charging): void
    {
        $delivering = (bool) $this->GetValueSafe('Delivering');
        $reason = (string) $this->GetValueSafe('StateReason');
        $newMode = $this->DetermineMode($delivering, $reason, $charging);
        $oldMode = (int) $this->GetValueSafe('GridRewardMode');

        if ($newMode === 2 && $oldMode !== 2) {
            $this->StartGridRewardEvent();
        } elseif ($newMode !== 2 && $oldMode === 2) {
            $this->EndGridRewardEvent();
        }
        $this->SetValueIfExists('GridRewardMode', $newMode);
    }

    // ---------------------------------------------------------------------
    // Bedingungs-Regelwerk "Wenn -> Dann" (Portierung von StromGedachtWidget, um Actions[] je Regel
    // erweitert – ein Grid-Reward-Übergang braucht typischerweise mehrere Datenpunkte gleichzeitig).
    // ---------------------------------------------------------------------

    /**
     * Liest die Bedingungsliste einer Regel, egal ob im neuen Mehrfach-Format
     * ({Conditions:[{Source,Op,Compare},...]}) oder im alten flachen Format
     * (Source/Op/Compare direkt in der Regel) gespeichert. Alle Bedingungen
     * werden mit UND verknüpft ausgewertet.
     */
    private function normalizeRuleConditions(array $rule): array
    {
        if (isset($rule['Conditions']) && is_array($rule['Conditions'])) {
            $out = [];
            foreach ($rule['Conditions'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $src = (string) ($c['Source'] ?? '');
                if ($src === '') {
                    continue;
                }
                $out[] = ['Source' => $src, 'Op' => (string) ($c['Op'] ?? 'true'), 'Compare' => (string) ($c['Compare'] ?? '')];
            }
            return $out;
        }
        $src = (string) ($rule['Source'] ?? '');
        if ($src === '') {
            return [];
        }
        return [['Source' => $src, 'Op' => (string) ($rule['Op'] ?? 'true'), 'Compare' => (string) ($rule['Compare'] ?? '')]];
    }

    /**
     * Liest die Aktionsliste einer Regel. Drei mögliche Formate, geprüft in dieser Reihenfolge:
     *  1) {Actions:[{Target,Action,Value},...]} – reiches Format des Kachel-Editors, beliebig viele.
     *  2) Target1/Action1/Value1/Target2/Action2/Value2 – flache Spalten der klassischen Formular-
     *     Liste (Tibber-Erweiterung ggü. StromGedacht: 2 Datenpunkte je Zeile, da ein Grid-Reward-
     *     Übergang typischerweise EMS-Betriebsmodus + Leistungssollwert gleichzeitig braucht).
     *  3) Target/Action/Value – einzelnes Ziel (Fallback, z. B. sehr alte Fremd-Daten).
     */
    private function normalizeRuleActions(array $rule): array
    {
        if (isset($rule['Actions']) && is_array($rule['Actions'])) {
            $out = [];
            foreach ($rule['Actions'] as $a) {
                if (!is_array($a) || (int) ($a['Target'] ?? 0) <= 0) {
                    continue;
                }
                $out[] = [
                    'Target' => (int) $a['Target'],
                    'Action' => (string) ($a['Action'] ?? 'on'),
                    'Value'  => (string) ($a['Value'] ?? ''),
                ];
            }
            return $out;
        }

        if (isset($rule['Target1']) || isset($rule['Target2'])) {
            $out = [];
            foreach ([1, 2] as $n) {
                $t = (int) ($rule['Target' . $n] ?? 0);
                if ($t <= 0) {
                    continue;
                }
                $out[] = [
                    'Target' => $t,
                    'Action' => (string) ($rule['Action' . $n] ?? 'on'),
                    'Value'  => (string) ($rule['Value' . $n] ?? ''),
                ];
            }
            return $out;
        }

        $t = (int) ($rule['Target'] ?? 0);
        if ($t <= 0) {
            return [];
        }
        return [['Target' => $t, 'Action' => (string) ($rule['Action'] ?? 'on'), 'Value' => (string) ($rule['Value'] ?? '')]];
    }

    /**
     * Setzt Zielvariable per Aktion (wenn vorhanden) oder direkt per SetValue. Der Platzhalter
     * „WALLBOX" (beliebige Groß-/Kleinschreibung) im Wert wird durch die aktuell benötigte
     * Wallbox-Leistung ersetzt (Tibber-Erweiterung ggü. StromGedacht).
     */
    private function applyActionToVariable(int $vid, string $action, string $rawValue, string $context): bool
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            $this->SendDebug('Aktion', sprintf('%s: Zielvariable #%d existiert nicht', $context, $vid), 0);
            return false;
        }

        $var = IPS_GetVariable($vid);
        switch ($action) {
            case 'off':
                $value = false;
                break;
            case 'toggle':
                $value = !(bool) GetValue($vid);
                break;
            case 'value':
                $resolved = (strtoupper(trim($rawValue)) === 'WALLBOX')
                    ? (string) $this->GetValueSafe('GridRewardWallboxRequest')
                    : $rawValue;
                $value = $this->castToVariableType($resolved, (int) $var['VariableType']);
                break;
            case 'on':
            default:
                $value = true;
                break;
        }
        // Bool-Aktionen auf Nicht-Bool-Variablen sinnvoll abbilden (0/1)
        if (is_bool($value) && (int) $var['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            $value = $this->castToVariableType($value ? '1' : '0', (int) $var['VariableType']);
        }

        $hasAction = ((int) $var['VariableAction'] > 0 || (int) $var['VariableCustomAction'] > 0);
        $ok = $hasAction ? @RequestAction($vid, $value) : @SetValue($vid, $value);

        $this->SendDebug('Aktion', sprintf(
            '%s -> %s #%d = %s (%s)',
            $context,
            $hasAction ? 'RequestAction' : 'SetValue',
            $vid,
            json_encode($value),
            ($ok === false) ? 'FEHLER' : 'ok'
        ), 0);
        return $ok !== false;
    }

    /** Wandelt den Regel-Wert (Text) in den Typ der Zielvariable um. */
    private function castToVariableType(string $raw, int $type)
    {
        $raw = trim($raw);
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                return in_array(strtolower($raw), ['1', 'true', 'wahr', 'ja', 'yes', 'on', 'ein', 'an'], true);
            case VARIABLETYPE_INTEGER:
                return (int) $raw;
            case VARIABLETYPE_FLOAT:
                return (float) str_replace(',', '.', $raw);
            default:
                return $raw;
        }
    }

    /**
     * Wertet alle Wenn->Dann-Regeln aus. Flankengesteuert: eine Regel feuert nur, wenn ihre Bedingung
     * von unerfüllt auf erfüllt wechselt (bzw. bei 'change', wenn sich der Wert ändert) – nicht bei
     * jeder Datenmeldung erneut. Ausnahme: Aktionen mit dem Wert-Platzhalter „WALLBOX" werden
     * fortlaufend nachgeführt, solange die Bedingung erfüllt bleibt (mit Änderungs-Schutz pro
     * Zielvariable), damit der Leistungssollwert der tatsächlichen Wallbox-Last folgt.
     * $fire=false aktualisiert nur den Zustand ohne auszulösen (Baseline nach Übernehmen, verhindert
     * Fehlauslösungen durch alte Flanken).
     */
    private function evaluateDataActions(bool $fire = true): void
    {
        $rules = json_decode($this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        $state = json_decode($this->ReadAttributeString('RuleState'), true);
        if (!is_array($state)) {
            $state = [];
        }
        $stateChanged = false;
        $lastVals = json_decode($this->ReadAttributeString('LastAppliedValues'), true);
        if (!is_array($lastVals)) {
            $lastVals = [];
        }
        $lastValsChanged = false;

        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $key = (string) $i;

            $conditions = $this->normalizeRuleConditions($rule);
            if (count($conditions) === 0) {
                continue;
            }

            $prevState = $state[$key] ?? null;
            if (is_array($prevState) && !array_key_exists('overall', $prevState)) {
                $prevState = null;
            }
            $prevVals = is_array($prevState['vals'] ?? null) ? $prevState['vals'] : [];

            $newVals = [];
            $allSatisfied = true;
            $hasMomentary = false;
            $sourcesValid = true;

            foreach ($conditions as $ci => $cond) {
                $vid = @IPS_GetObjectIDByIdent($cond['Source'], $this->InstanceID);
                if ($vid === false || $vid <= 0) {
                    $sourcesValid = false;
                    break;
                }
                $cur = GetValue($vid);
                $serial = json_encode($cur);
                $newVals[$ci] = $serial;

                if ($cond['Op'] === 'change') {
                    $hasMomentary = true;
                    $changed = array_key_exists($ci, $prevVals) && ($prevVals[$ci] !== $serial);
                    if (!$changed) {
                        $allSatisfied = false;
                    }
                } elseif (!$this->evalRuleCondition($cur, $cond['Op'], $cond['Compare'])) {
                    $allSatisfied = false;
                }
            }

            if (!$sourcesValid) {
                continue;
            }

            if ($hasMomentary) {
                $fireNow = $allSatisfied;
            } else {
                $prevOverall = (bool) ($prevState['overall'] ?? false);
                $fireNow = ($prevState !== null) && !$prevOverall && $allSatisfied;
            }

            $newState = ['overall' => $allSatisfied, 'vals' => $newVals];
            if ($prevState === null || ($prevState['overall'] ?? null) !== $allSatisfied || ($prevState['vals'] ?? null) !== $newVals) {
                $state[$key] = $newState;
                $stateChanged = true;
            }

            if (!$fire || !(bool) ($rule['Active'] ?? true)) {
                continue;
            }

            foreach ($this->normalizeRuleActions($rule) as $ai => $act) {
                $isLive = ($act['Action'] === 'value') && (strtoupper(trim($act['Value'])) === 'WALLBOX');
                $context = 'Automation ' . $this->describeDataAction($rule) . ' [' . $ai . ']';

                if ($isLive) {
                    if (!$allSatisfied) {
                        continue; // nur solange die Bedingung erfüllt bleibt live nachführen
                    }
                    $wallboxRequest = (float) $this->GetValueSafe('GridRewardWallboxRequest');
                    $dedupKey = (string) $act['Target'];
                    $prevVal = $lastVals[$dedupKey] ?? null;
                    if ($prevVal !== null && is_numeric($prevVal) && abs((float) $prevVal - $wallboxRequest) < 1.0) {
                        continue; // keine relevante Änderung -> nicht spammen
                    }
                    if ($this->applyActionToVariable($act['Target'], 'value', (string) $wallboxRequest, $context)) {
                        $lastVals[$dedupKey] = $wallboxRequest;
                        $lastValsChanged = true;
                    }
                } elseif ($fireNow) {
                    $this->applyActionToVariable($act['Target'], $act['Action'], $act['Value'], $context);
                }
            }
        }

        foreach (array_keys($state) as $k) {
            if (!isset($rules[(int) $k])) {
                unset($state[$k]);
                $stateChanged = true;
            }
        }
        if ($stateChanged) {
            $this->WriteAttributeString('RuleState', json_encode($state));
        }
        if ($lastValsChanged) {
            $this->WriteAttributeString('LastAppliedValues', json_encode($lastVals));
        }
    }

    /** Prüft, ob der aktuelle Wert die Bedingung erfüllt. */
    private function evalRuleCondition($cur, string $op, string $cmp): bool
    {
        switch ($op) {
            case 'true':
                return (bool) $cur === true;
            case 'false':
                return (bool) $cur === false;
            case 'change':
                return false; // Sonderfall, wird über den Wertvergleich behandelt
        }

        $cmp = trim($cmp);
        if (is_bool($cur)) {
            $cur = $cur ? 1 : 0;
        }
        $numeric = is_numeric($cur) && is_numeric(str_replace(',', '.', $cmp));
        if ($numeric) {
            $a = (float) $cur;
            $b = (float) str_replace(',', '.', $cmp);
        } else {
            $a = (string) $cur;
            $b = $cmp;
        }

        switch ($op) {
            case 'eq':
                return $numeric ? (abs($a - $b) < 1e-9) : (strcasecmp($a, $b) === 0);
            case 'ne':
                return $numeric ? (abs($a - $b) >= 1e-9) : (strcasecmp($a, $b) !== 0);
            case 'gt':
                return $numeric && $a > $b;
            case 'ge':
                return $numeric && $a >= $b;
            case 'lt':
                return $numeric && $a < $b;
            case 'le':
                return $numeric && $a <= $b;
        }
        return false;
    }

    /** Menschenlesbare Beschreibung einer einzelnen Bedingung, z. B. „Grid-Reward-Modus = 2". */
    private function describeCondition(array $cond): string
    {
        $opText = [
            'true' => 'wird EIN', 'false' => 'wird AUS', 'change' => 'ändert sich',
            'eq' => '=', 'ne' => '≠', 'gt' => '>', 'ge' => '≥', 'lt' => '<', 'le' => '≤',
        ];

        $srcIdent = (string) ($cond['Source'] ?? '');
        $srcName = $srcIdent;
        foreach ($this->getAutomationSourceOptions() as $o) {
            if ($o['value'] === $srcIdent) {
                $srcName = $o['caption'];
                break;
            }
        }

        $op = (string) ($cond['Op'] ?? 'true');
        $condText = $opText[$op] ?? $op;
        if (!in_array($op, ['true', 'false', 'change'], true)) {
            $condText .= ' ' . (string) ($cond['Compare'] ?? '');
        }

        return $srcName . ' ' . $condText;
    }

    /** Menschenlesbare Beschreibung einer Regel (Bedingungen mit UND, Aktionen mit "+"). */
    private function describeDataAction(array $rule): string
    {
        $conditions = $this->normalizeRuleConditions($rule);
        $condParts = array_map([$this, 'describeCondition'], $conditions);
        $condText = (count($condParts) > 0) ? implode(' UND ', $condParts) : '?';

        $actionParts = [];
        foreach ($this->normalizeRuleActions($rule) as $act) {
            $tVid = (int) $act['Target'];
            $tName = ($tVid > 0 && IPS_VariableExists($tVid)) ? IPS_GetName($tVid) : ('#' . $tVid);
            switch ($act['Action']) {
                case 'off':
                    $actionParts[] = $tName . ' ausschalten';
                    break;
                case 'toggle':
                    $actionParts[] = $tName . ' umschalten';
                    break;
                case 'value':
                    $actionParts[] = $tName . ' = ' . $act['Value'];
                    break;
                default:
                    $actionParts[] = $tName . ' einschalten';
                    break;
            }
        }
        $doText = (count($actionParts) > 0) ? implode(' + ', $actionParts) : '?';

        return sprintf('Wenn %s → %s', $condText, $doText);
    }

    /**
     * Daten für den Regel-Editor der Kachel: Datenpunkte (Quellen) und schaltbare Zielvariablen mit
     * Objektbaum-Pfad. JSON: {sources:[{v,c}], targets:[{v,c,p}]}
     */
    public function GetDataActionEditor(): string
    {
        $sources = [];
        foreach ($this->getAutomationSourceOptions() as $o) {
            $sources[] = ['v' => $o['value'], 'c' => $o['caption']];
        }

        $targets = [];
        foreach (IPS_GetVariableList() as $vid) {
            $var = IPS_GetVariable($vid);
            if ((int) $var['VariableAction'] <= 0 && (int) $var['VariableCustomAction'] <= 0) {
                continue;
            }
            $targets[] = ['v' => $vid, 'c' => IPS_GetName($vid), 'p' => IPS_GetLocation($vid)];
            if (count($targets) >= 1000) {
                break;
            }
        }
        usort($targets, function ($a, $b) {
            return strcasecmp($a['p'], $b['p']);
        });

        return json_encode(['sources' => $sources, 'targets' => $targets]);
    }

    /** Regeln als JSON für die Kachel: [{i, text, active, rule}] */
    public function GetDataActions(): string
    {
        $rules = json_decode($this->ReadPropertyString('DataActions'), true);
        $out = [];
        if (is_array($rules)) {
            foreach ($rules as $i => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $out[] = [
                    'i'      => $i,
                    'text'   => $this->describeDataAction($rule),
                    'active' => (bool) ($rule['Active'] ?? true),
                    'rule'   => [
                        'Conditions' => $this->normalizeRuleConditions($rule),
                        'Actions'    => $this->normalizeRuleActions($rule),
                    ],
                ];
            }
        }
        return json_encode($out);
    }

    /**
     * Legt eine Regel an oder überschreibt sie ($Index < 0 = anhängen).
     * $RuleJSON: {Active, Conditions:[{Source,Op,Compare},...] (UND), Actions:[{Target,Action,Value},...]}
     */
    public function SetDataAction(int $Index, string $RuleJSON): void
    {
        $in = json_decode($RuleJSON, true);
        if (!is_array($in)) {
            return;
        }
        $ops = ['true', 'false', 'eq', 'ne', 'gt', 'ge', 'lt', 'le', 'change'];
        $acts = ['on', 'off', 'toggle', 'value'];

        $conditions = [];
        foreach ((array) ($in['Conditions'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $src = trim((string) ($c['Source'] ?? ''));
            if ($src === '') {
                continue;
            }
            $conditions[] = [
                'Source'  => $src,
                'Op'      => in_array(($c['Op'] ?? ''), $ops, true) ? (string) $c['Op'] : 'true',
                'Compare' => (string) ($c['Compare'] ?? ''),
            ];
        }
        $conditions = array_slice($conditions, 0, 5);
        if (count($conditions) === 0) {
            return;
        }

        $actions = [];
        foreach ((array) ($in['Actions'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $target = (int) ($a['Target'] ?? 0);
            if ($target <= 0) {
                continue;
            }
            $actions[] = [
                'Target' => $target,
                'Action' => in_array(($a['Action'] ?? ''), $acts, true) ? (string) $a['Action'] : 'on',
                'Value'  => (string) ($a['Value'] ?? ''),
            ];
        }
        $actions = array_slice($actions, 0, 6);
        if (count($actions) === 0) {
            return;
        }

        $rule = [
            'Active'     => (bool) ($in['Active'] ?? true),
            'Conditions' => $conditions,
            // Erste Bedingung zusätzlich flach spiegeln, für die klassische Formular-Liste
            'Source'     => $conditions[0]['Source'],
            'Op'         => $conditions[0]['Op'],
            'Compare'    => $conditions[0]['Compare'],
            'Actions'    => $actions,
        ];

        $rules = json_decode($this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        if ($Index >= 0 && isset($rules[$Index])) {
            $rules[$Index] = $rule;
        } else {
            $rules[] = $rule;
        }
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode(array_values($rules)));
        IPS_ApplyChanges($this->InstanceID);
    }

    public function DeleteDataAction(int $Index): void
    {
        $rules = json_decode($this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules) || !isset($rules[$Index])) {
            return;
        }
        unset($rules[$Index]);
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode(array_values($rules)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Aktiviert/deaktiviert eine Regel (z. B. aus der Kachel). */
    public function SetDataActionActive(int $Index, bool $Active): void
    {
        $rules = json_decode($this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules) || !isset($rules[$Index]) || !is_array($rules[$Index])) {
            return;
        }
        if ((bool) ($rules[$Index]['Active'] ?? true) === $Active) {
            return;
        }
        $rules[$Index]['Active'] = $Active;
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode($rules));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Einmalige Migration der alten EMS-Felder (bis 1.15.x: EmsModeVariable/-Value0..3,
     * EmsPowerVariable/-Fixed0..3) in das neue generische "Automations"-Format – rekonstruiert
     * bestehende Konfigurationen 1:1 als vier Automationszeilen (eine je Modus, je Zeile Zielvariable
     * 1 = alter EMS-Modus-Schalter, Zielvariable 2 = alte Leistungsvariable). Läuft nur einmal (Attribut
     * EmsAutomationsMigrated) und nur, wenn noch keine eigenen Automationen konfiguriert sind.
     *
     * @return bool true, wenn migriert wurde (Aufrufer sollte danach return; da ApplyChanges erneut
     *              angestoßen wird)
     */
    private function MigrateLegacyEmsConfig(): bool
    {
        if ($this->ReadAttributeBoolean('EmsAutomationsMigrated')) {
            return false;
        }
        $this->WriteAttributeBoolean('EmsAutomationsMigrated', true);

        $current = json_decode($this->ReadPropertyString('Automations'), true);
        if (!empty($current)) {
            return false; // schon eigene Automationen konfiguriert -> nichts überschreiben
        }
        $modeVar = $this->ReadPropertyInteger('EmsModeVariable');
        if ($modeVar <= 0) {
            return false; // nichts zu migrieren
        }
        $powerVar = $this->ReadPropertyInteger('EmsPowerVariable');

        $rows = [];
        for ($m = 0; $m <= 3; $m++) {
            $fixed = $this->ReadPropertyInteger('EmsPowerFixed' . $m);
            $rows[] = [
                'Active'  => true,
                'Mode'    => $m,
                'Target1' => $modeVar,
                'Value1'  => (string) $this->ReadPropertyInteger('EmsModeValue' . $m),
                'Target2' => $powerVar,
                'Value2'  => $powerVar > 0 ? ($fixed >= 0 ? (string) $fixed : 'WALLBOX') : '',
            ];
        }

        $this->SendDebug(__FUNCTION__, 'Migriere alte EMS-Konfiguration nach Automations: ' . json_encode($rows), 0);
        IPS_SetProperty($this->InstanceID, 'Automations', json_encode($rows));
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    /**
     * Einmalige Migration der alten "Automations"-Zeilen (bis 1.17.x: Active/Mode/Target1/Value1/
     * Target2/Value2, gültig für genau einen Grid-Reward-Modus) in das neue generische
     * "DataActions"-Regelwerk: jede Zeile wird zu einer Regel mit genau einer Bedingung
     * (GridRewardMode = Mode) und bis zu zwei Aktionen. So bleibt Dietmars bereits verifizierte
     * Konfiguration (Modus 0/1/3 -> Automatik+Fixwert, Modus 2 -> Stromeinkauf+WALLBOX) ohne manuelle
     * Neueingabe erhalten. Läuft nur einmal (Attribut DataActionsMigrated) und nur, wenn noch keine
     * eigenen DataActions konfiguriert sind.
     *
     * @return bool true, wenn migriert wurde (Aufrufer sollte danach return; da ApplyChanges erneut
     *              angestoßen wird)
     */
    private function MigrateAutomationsToDataActions(): bool
    {
        if ($this->ReadAttributeBoolean('DataActionsMigrated')) {
            return false;
        }
        $this->WriteAttributeBoolean('DataActionsMigrated', true);

        $currentRules = json_decode($this->ReadPropertyString('DataActions'), true);
        if (!empty($currentRules)) {
            return false; // schon eigene DataActions konfiguriert -> nichts überschreiben
        }
        $oldRows = json_decode($this->ReadPropertyString('Automations'), true);
        if (!is_array($oldRows) || count($oldRows) === 0) {
            return false; // nichts zu migrieren
        }

        $newRules = [];
        foreach ($oldRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $actions = [];
            foreach (['Target1' => 'Value1', 'Target2' => 'Value2'] as $tKey => $vKey) {
                $target = (int) ($row[$tKey] ?? 0);
                if ($target <= 0) {
                    continue;
                }
                $value = (string) ($row[$vKey] ?? '');
                if ($value === '') {
                    continue;
                }
                $actions[] = ['Target' => $target, 'Action' => 'value', 'Value' => $value];
            }
            if (count($actions) === 0) {
                continue;
            }
            $mode = (string) (int) ($row['Mode'] ?? 0);
            $newRules[] = [
                'Active'     => (bool) ($row['Active'] ?? true),
                'Conditions' => [['Source' => 'GridRewardMode', 'Op' => 'eq', 'Compare' => $mode]],
                'Source'     => 'GridRewardMode',
                'Op'         => 'eq',
                'Compare'    => $mode,
                'Actions'    => $actions,
            ];
        }
        if (count($newRules) === 0) {
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'Migriere alte Automations nach DataActions: ' . json_encode($newRules), 0);
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode($newRules));
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    /**
     * Umfassender EMS-Modus aus Grid-Reward-Status + Ladezustand:
     *   0 = Normal (kein Einsatz, Auto lädt nicht)
     *   1 = Auto lädt, kein Reward (Smart-Charge / Zwangsbeladen / Freigabe) -> Strom aus Netz
     *   2 = Grid Reward Laden (excess) -> aus Netz, zusätzlich Batterie aus Netz laden, nie entladen
     *   3 = Grid Reward Drosselung (shortage) -> Auto aus, Haus aus Batterie/PV, Netzbezug minimieren
     */
    private function DetermineMode(bool $delivering, string $reason, bool $charging): int
    {
        if ($delivering) {
            return strpos(strtolower($reason), 'shortage') !== false ? 3 : 2;
        }
        return $charging ? 1 : 0;
    }

    public function EnergyTick(): void
    {
        $this->EnergyStep();
    }

    /**
     * Ein Energie-Schritt: bei vorhandenen Zählern reset-fest aus diesen, sonst per Integration.
     * Gezählt wird nur bei aktivem Einsatz (EventActive); die Basis wird immer fortgeschrieben.
     */
    private function EnergyStep(): void
    {
        if ($this->HasEnergyCounters()) {
            $delta = $this->AccumulateEnergyCounters();
            if ($delta > 0 && $this->ReadAttributeBoolean('EventActive')) {
                $this->AddEnergy($delta);
            }
        } else {
            $this->IntegrateEnergy();
        }
        $this->UpdateKPI();
    }

    private function HasEnergyCounters(): bool
    {
        foreach ($this->GetWallboxRows() as $r) {
            $eid = (int) ($r['EnergyID'] ?? 0);
            if ($eid > 0 && IPS_VariableExists($eid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Liefert die Energie-Zunahme (kWh) über alle Wallbox-Energiezähler seit dem letzten Aufruf.
     * Reset-fest: springt ein Zähler zurück (neuer Ladezyklus), wird der neue Wert als Delta gewertet.
     * Aktualisiert die gespeicherten Zählerstände immer (auch außerhalb eines Einsatzes).
     */
    private function AccumulateEnergyCounters(): float
    {
        $old = json_decode($this->ReadAttributeString('EnergyLast'), true);
        if (!is_array($old)) {
            $old = [];
        }
        $new = [];
        $total = 0.0;

        foreach ($this->GetWallboxRows() as $r) {
            $eid = (int) ($r['EnergyID'] ?? 0);
            if ($eid <= 0 || !IPS_VariableExists($eid)) {
                continue;
            }
            $key = (string) $eid;
            $cur = (float) GetValue($eid);
            if (array_key_exists($key, $old)) {
                $last = (float) $old[$key];
                $total += ($cur >= $last) ? ($cur - $last) : $cur; // Rücksprung = neuer Zyklus
            }
            $new[$key] = $cur; // nur aktuelle Zähler behalten (entfernt automatisch alte)
        }

        $this->WriteAttributeString('EnergyLast', json_encode($new));
        return $total;
    }

    private function IntegrateEnergy(): void
    {
        $now = microtime(true);
        $lastTs = $this->ReadAttributeFloat('LastEnergyTs');
        $lastPower = $this->ReadAttributeFloat('LastPower');

        if ($this->ReadAttributeBoolean('EventActive') && $lastTs > 0 && $lastPower > 0) {
            $dt = $now - $lastTs;
            if ($dt > 0 && $dt < 7200) { // Plausibilität: keine Riesensprünge (z.B. nach Downtime)
                $kwh = $lastPower * $dt / 3600.0 / 1000.0; // W·s -> kWh
                if ($kwh > 0) {
                    $this->AddEnergy($kwh);
                }
            }
        }

        $this->WriteAttributeFloat('LastEnergyTs', $now);
        $this->WriteAttributeFloat('LastPower', (float) $this->GetValueSafe('WallboxPowerTotal'));
    }

    private function AddEnergy(float $kwh): void
    {
        $day = date('Y-m-d');
        $month = date('Y-m');
        if ($this->ReadAttributeString('EnergyDayMarker') !== $day) {
            $this->SetValueIfExists('GridRewardEnergyToday', 0.0);
            $this->WriteAttributeString('EnergyDayMarker', $day);
        }
        if ($this->ReadAttributeString('EnergyMonthMarker') !== $month) {
            $this->SetValueIfExists('GridRewardEnergyMonth', 0.0);
            $this->WriteAttributeString('EnergyMonthMarker', $month);
        }

        $this->SetValueIfExists('GridRewardEnergyEvent', (float) $this->GetValueSafe('GridRewardEnergyEvent') + $kwh);
        $this->SetValueIfExists('GridRewardEnergyToday', (float) $this->GetValueSafe('GridRewardEnergyToday') + $kwh);
        $this->SetValueIfExists('GridRewardEnergyMonth', (float) $this->GetValueSafe('GridRewardEnergyMonth') + $kwh);
        $this->SetValueIfExists('GridRewardEnergyTotal', (float) $this->GetValueSafe('GridRewardEnergyTotal') + $kwh);
    }

    private function FormatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d h %02d min', $h, $m);
        }
        if ($m > 0) {
            return sprintf('%d min %02d s', $m, $s);
        }
        return $s . ' s';
    }

    private function UpdateKPI(): void
    {
        $energyMonth = (float) $this->GetValueSafe('GridRewardEnergyMonth');
        $reward = (float) $this->GetValueSafe('RewardCurrentMonth');
        $rate = $energyMonth > 0.01 ? $reward / $energyMonth : 0.0;
        $this->SetValueIfExists('GridRewardEffectiveRate', $rate);
    }

    private function StartGridRewardEvent(): void
    {
        $this->WriteAttributeBoolean('EventActive', true);
        $this->SetValueIfExists('GridRewardEnergyEvent', 0.0);
        $this->SetValueIfExists('LastEventStart', time());
        // Basis neu setzen, damit nichts vor dem Einsatz mitgezählt wird
        if ($this->HasEnergyCounters()) {
            $this->AccumulateEnergyCounters(); // Zählerstände als Basis merken (nicht zählen)
        } else {
            $this->WriteAttributeFloat('LastEnergyTs', microtime(true));
            $this->WriteAttributeFloat('LastPower', (float) $this->GetValueSafe('WallboxPowerTotal'));
        }
        $this->SetTimerInterval('EnergyTick', 30000);
        $this->SendDebug(__FUNCTION__, 'Grid-Reward-Einsatz (Laden aus Netz) gestartet', 0);
    }

    private function EndGridRewardEvent(): void
    {
        // letzten Abschnitt verbuchen (EventActive noch true), danach Einsatz schließen
        if ($this->HasEnergyCounters()) {
            $delta = $this->AccumulateEnergyCounters();
            if ($delta > 0) {
                $this->AddEnergy($delta);
            }
        } else {
            $this->IntegrateEnergy();
        }
        $this->WriteAttributeBoolean('EventActive', false);
        $this->SetTimerInterval('EnergyTick', 0);
        $end = time();
        $start = (int) $this->GetValueSafe('LastEventStart');
        $this->SetValueIfExists('LastEventEnd', $end);
        $this->SetValueIfExists('LastEventDuration', $this->FormatDuration($start > 0 ? max(0, $end - $start) : 0));
        $this->SetValueIfExists('LastEventEnergy', (float) $this->GetValueSafe('GridRewardEnergyEvent'));
        $this->SendDebug(__FUNCTION__, 'Grid-Reward-Einsatz beendet', 0);
    }

    // ---------------------------------------------------------------------
    // Watchdog / Relogin (analog TibberV2)
    // ---------------------------------------------------------------------

    public function StartWatchdog(): void
    {
        $this->SendDebug(__FUNCTION__, 'Keine Daten empfangen – starte Relogin-Sequenz', 0);
        $this->ReloginSequence();
    }

    public function ReloginSequence(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        if ($this->GetTimerInterval('ReloginSequence') > 0) {
            // zweiter Aufruf → tatsächlich neu verbinden
            $this->SetTimerInterval('ReloginSequence', 0);
            $this->ReloginRetriesReached(true);
            $this->EnsureToken();
            $this->UpdateConfigurationForParent();
        } else {
            $this->SetTimerInterval('StartWatchdog', 0);
            $random = rand(60, 120);
            $this->SetTimerInterval('ReloginSequence', $random * 1000);
            $this->SendDebug(__FUNCTION__, 'Relogin in ' . $random . ' s', 0);
            if ($this->ReloginRetriesReached()) {
                $this->SendDebug(__FUNCTION__, 'Maximale Relogin-Versuche erreicht – Abbruch', 0);
                $this->SetTimerInterval('ReloginSequence', 0);
                $this->SetStatus(104);
            }
        }
    }

    private function ReloginRetriesReached(bool $reset = false): bool
    {
        $counter = $this->ReadAttributeInteger('WTCounter');
        if ($counter > 4 || $reset) {
            $this->WriteAttributeInteger('WTCounter', $reset ? 0 : 1);
            return !$reset;
        }
        $this->WriteAttributeInteger('WTCounter', $counter + 1);
        return false;
    }

    // ---------------------------------------------------------------------
    // HTTP-Helfer
    // ---------------------------------------------------------------------

    /**
     * @return array|null dekodierte JSON-Antwort oder null bei Fehler
     */
    private function HttpPost(string $url, string $body, bool $auth, ?string $token = null): ?array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . ($token ?? $this->ReadAttributeString('JWT'));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->SendDebug(__FUNCTION__, 'cURL-Fehler: ' . $err, 0);
            return null;
        }
        if ($code >= 400) {
            $this->SendDebug(__FUNCTION__, 'HTTP ' . $code . ': ' . substr((string) $resp, 0, 500), 0);
            return null;
        }

        $json = json_decode((string) $resp, true);
        return is_array($json) ? $json : null;
    }
}
