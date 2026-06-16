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
 * Die offizielle Tibber-API (Preise, Verbrauch, Live-Messung/Pulse) wird hiervon NICHT abgedeckt –
 * dafür gibt es das Modul "Tibber V.2" (da8ter/TibberV2).
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

        $this->RegisterAttributeString('JWT', '');
        $this->RegisterAttributeInteger('JWT_Exp', 0);
        $this->RegisterAttributeString('Homes', '');
        $this->RegisterAttributeInteger('Parent_IO', 0);
        $this->RegisterAttributeInteger('WTCounter', 0);
        $this->RegisterAttributeFloat('LastEnergyTs', 0.0);
        $this->RegisterAttributeFloat('LastPower', 0.0);
        $this->RegisterAttributeString('EnergyDayMarker', '');
        $this->RegisterAttributeString('EnergyMonthMarker', '');

        // eindeutige Subscription-ID je Instanz
        $this->RegisterPropertyInteger('SubID', rand(1000, 9999));

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // Timer
        $this->RegisterTimer('TokenRefresh', 0, 'TIBBERGR_TokenRefresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StartWatchdog', 0, 'TIBBERGR_StartWatchdog($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ReloginSequence', 0, 'TIBBERGR_ReloginSequence($_IPS[\'TARGET\']);');
        $this->RegisterTimer('EnergyTick', 0, 'TIBBERGR_EnergyTick($_IPS[\'TARGET\']);');
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

        $this->RegisterProfiles();

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
        $this->WriteAttributeFloat('LastEnergyTs', microtime(true));
        $this->SumWallboxes();
        $this->WriteAttributeFloat('LastPower', (float) $this->GetValueSafe('WallboxPowerTotal'));
        $this->UpdateKPI();
        $this->SetTimerInterval('EnergyTick', ((bool) $this->GetValueSafe('Delivering')) ? 30000 : 0);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Home-Dropdown dynamisch füllen
        $options = [['caption' => $this->Translate('Please select'), 'value' => '0']];
        $homesRaw = $this->ReadAttributeString('Homes');
        if ($homesRaw !== '') {
            $homes = json_decode($homesRaw, true);
            $list = $homes['data']['me']['homes'] ?? [];
            foreach ($list as $home) {
                $caption = $home['title'] ?? $home['id'];
                $options[] = ['caption' => $caption, 'value' => (string) $home['id']];
            }
        }

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'Home_ID') {
                $element['options'] = $options;
            }
        }
        unset($element);

        return json_encode($form);
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

            case VM_UPDATE: // Änderung einer Wallbox-Wirkleistung
                $this->SumWallboxes();
                $this->IntegrateEnergy();
                $this->UpdateKPI();
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

    // ---------------------------------------------------------------------
    // Verarbeitung des Grid-Reward-Status
    // ---------------------------------------------------------------------

    private function ProcessGridReward(array $status): void
    {
        $state = $status['state'] ?? [];
        [$stateName, $stateReason, $delivering] = $this->ParseState($state);

        $wasDelivering = (bool) $this->GetValueSafe('Delivering');

        $this->SetValueIfExists('Delivering', $delivering);
        $this->SetValueIfExists('State', $stateName);
        $this->SetValueIfExists('StateReason', $stateReason);
        $this->SetValueIfExists('Currency', (string) ($status['rewardCurrency'] ?? ''));
        $this->SetValueIfExists('RewardCurrentMonth', (float) ($status['rewardCurrentMonth'] ?? 0));
        $this->SetValueIfExists('RewardAllTime', (float) ($status['rewardAllTime'] ?? 0));

        $devices = $status['flexDevices'] ?? [];
        $this->SetValueIfExists('FlexDeviceCount', count($devices));
        $this->SetValueIfExists('FlexDevices', $this->FormatFlexDevices($devices));

        // Einsatz-Flanken (Event-Log + Energiezählung)
        if ($delivering && !$wasDelivering) {
            $this->StartGridRewardEvent();
        } elseif (!$delivering && $wasDelivering) {
            $this->EndGridRewardEvent();
        }

        // Netzbezug-Sollwert + KPI neu berechnen
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
        $this->MaintainVariable('LastEventDuration', $this->Translate('Last event duration'), VARIABLETYPE_INTEGER, '~UnixTimestampInterval', $pos++, true);
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
            $vid = (int) ($r['PowerID'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterReference($vid);
                $this->RegisterMessage($vid, VM_UPDATE);
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

    private function SumWallboxes(): void
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

        $this->SetValueIfExists('WallboxPowerTotal', $total);
        $this->SetValueIfExists('WallboxCharging', $total > $this->ReadPropertyFloat('ChargingThreshold'));
        $this->SetValueIfExists('DataValid', $anyActive ? $allValid : true);

        $delivering = (bool) $this->GetValueSafe('Delivering');
        $this->SetValueIfExists('GridRewardWallboxRequest', $delivering ? $total : 0.0);
    }

    public function EnergyTick(): void
    {
        $this->IntegrateEnergy();
        $this->UpdateKPI();
    }

    private function IntegrateEnergy(): void
    {
        $now = microtime(true);
        $lastTs = $this->ReadAttributeFloat('LastEnergyTs');
        $lastPower = $this->ReadAttributeFloat('LastPower');

        if ((bool) $this->GetValueSafe('Delivering') && $lastTs > 0 && $lastPower > 0) {
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

    private function UpdateKPI(): void
    {
        $energyMonth = (float) $this->GetValueSafe('GridRewardEnergyMonth');
        $reward = (float) $this->GetValueSafe('RewardCurrentMonth');
        $rate = $energyMonth > 0.01 ? $reward / $energyMonth : 0.0;
        $this->SetValueIfExists('GridRewardEffectiveRate', $rate);
    }

    private function StartGridRewardEvent(): void
    {
        $this->SetValueIfExists('GridRewardEnergyEvent', 0.0);
        $this->SetValueIfExists('LastEventStart', time());
        // Integrations-Basis neu setzen, damit keine Downtime mitgezählt wird
        $this->WriteAttributeFloat('LastEnergyTs', microtime(true));
        $this->WriteAttributeFloat('LastPower', (float) $this->GetValueSafe('WallboxPowerTotal'));
        $this->SetTimerInterval('EnergyTick', 30000);
        $this->SendDebug(__FUNCTION__, 'Grid-Reward-Einsatz gestartet', 0);
    }

    private function EndGridRewardEvent(): void
    {
        $this->IntegrateEnergy(); // letzten Abschnitt verbuchen
        $this->SetTimerInterval('EnergyTick', 0);
        $end = time();
        $start = (int) $this->GetValueSafe('LastEventStart');
        $this->SetValueIfExists('LastEventEnd', $end);
        $this->SetValueIfExists('LastEventDuration', $start > 0 ? max(0, $end - $start) : 0);
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
    private function HttpPost(string $url, string $body, bool $auth): ?array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->ReadAttributeString('JWT');
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
