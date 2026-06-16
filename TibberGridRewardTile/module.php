<?php

declare(strict_types=1);

/**
 * TibberGridRewardTile
 *
 * Eigenständige HTML-SDK-Kachel für die Tile-Visualisierung. Liest die Variablen einer
 * TibberGridReward-Instanz (Quelle) und stellt sie als randlose, frei gestaltbare Status-Kachel dar.
 *
 * Bewusst von der Datenlogik getrennt (Vorbild da8ter): Ein Problem in der Kachel kann die
 * WebSocket-/Datenverbindung der Quell-Instanz nicht beeinträchtigen.
 */
class TibberGridRewardTile extends IPSModule
{
    // GUID des Datenmoduls TibberGridReward (für die Quellen-Auswahl)
    private const SOURCE_MODULE = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';

    // Standardwerte (auch für „Zurücksetzen")
    private const DEF_ACTIVE      = 0x27D07F;
    private const DEF_AVAILABLE   = 0x2BB3C0;
    private const DEF_UNAVAILABLE = 0x7A8A99;
    // -1 = keine feste Farbe -> Kachel übernimmt das IPS-Theme (transparenter Hintergrund,
    //      Textfarbe automatisch hell/dunkel je nach Theme) – Verhalten wie bei da8ter.
    private const DEF_BACKGROUND  = -1;
    private const DEF_BOX         = -1;
    private const DEF_TEXT        = -1;
    private const DEF_TEXTMUTED   = -1;
    private const DEF_FONT        = 'system';
    private const DEF_SCALE       = 1.0;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);

        // Statusfarben
        $this->RegisterPropertyInteger('ColorActive', self::DEF_ACTIVE);
        $this->RegisterPropertyInteger('ColorAvailable', self::DEF_AVAILABLE);
        $this->RegisterPropertyInteger('ColorUnavailable', self::DEF_UNAVAILABLE);
        // Flächen-/Textfarben
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyInteger('ColorBox', self::DEF_BOX);
        $this->RegisterPropertyInteger('ColorText', self::DEF_TEXT);
        $this->RegisterPropertyInteger('ColorTextMuted', self::DEF_TEXTMUTED);
        // Schrift
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyFloat('FontScale', self::DEF_SCALE);

        // Als HTML-Kachel-Visualisierung anmelden
        $this->SetVisualizationType(1);
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

        $this->SetVisualizationType(1);

        // Bisherige VM_UPDATE-Registrierungen lösen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        // Auf Änderungen der Quell-Variablen lauschen, damit die Kachel sich aktualisiert
        $src = $this->ResolveSource();
        if ($src > 0 && IPS_InstanceExists($src)) {
            foreach (['Delivering', 'State', 'RewardCurrentMonth', 'RewardAllTime', 'Currency', 'FlexDevices'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $src);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }

    /**
     * Button-Aktion: alle Farben und Schrifteinstellungen auf Standard zurücksetzen.
     */
    public function ResetStyle(): void
    {
        $id = $this->InstanceID;
        IPS_SetProperty($id, 'ColorActive', self::DEF_ACTIVE);
        IPS_SetProperty($id, 'ColorAvailable', self::DEF_AVAILABLE);
        IPS_SetProperty($id, 'ColorUnavailable', self::DEF_UNAVAILABLE);
        IPS_SetProperty($id, 'ColorBackground', self::DEF_BACKGROUND);
        IPS_SetProperty($id, 'ColorBox', self::DEF_BOX);
        IPS_SetProperty($id, 'ColorText', self::DEF_TEXT);
        IPS_SetProperty($id, 'ColorTextMuted', self::DEF_TEXTMUTED);
        IPS_SetProperty($id, 'FontFamily', self::DEF_FONT);
        IPS_SetProperty($id, 'FontScale', self::DEF_SCALE);
        IPS_ApplyChanges($id);
        $this->ReloadForm();
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        // handleMessage() ist erst im HTML definiert -> initialen Aufruf ans Ende hängen.
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------
    // Datenaufbereitung
    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(): string
    {
        $cActive = $this->ColorHex($this->ReadPropertyInteger('ColorActive'), '#27d07f');
        $cAvail = $this->ColorHex($this->ReadPropertyInteger('ColorAvailable'), '#2bb3c0');
        $cUnavail = $this->ColorHex($this->ReadPropertyInteger('ColorUnavailable'), '#7a8a99');

        // Leerer String = nicht gesetzt -> die Kachel nutzt den Theme-Default aus dem CSS.
        $style = [
            'bg'        => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'box'       => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBox')),
            'text'      => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorText')),
            'textmuted' => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorTextMuted')),
            'font'      => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'scale'     => $this->FontScaleValue(),
        ];

        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, [
                'stateLabel' => $this->Translate('No source selected'),
                'cls'        => 'off',
                'accent'     => $cUnavail,
                'month'      => '–',
                'total'      => '–',
                'monthLabel' => $this->Translate('This month'),
                'totalLabel' => $this->Translate('Total'),
                'emptyLabel' => $this->Translate('No flex devices'),
                'devices'    => [],
            ]));
        }

        $delivering = (bool) $this->ReadSourceValue($src, 'Delivering', false);
        $stateText = (string) $this->ReadSourceValue($src, 'State', '');

        if ($delivering) {
            $cls = 'live';
            $accent = $cActive;
        } elseif ($stateText === $this->Translate('Available')) {
            $cls = 'avail';
            $accent = $cAvail;
        } else {
            $cls = 'off';
            $accent = $cUnavail;
        }

        $cur = $this->CurrencySymbol((string) $this->ReadSourceValue($src, 'Currency', ''));
        $month = $this->FormatMoney((float) $this->ReadSourceValue($src, 'RewardCurrentMonth', 0), $cur);
        $total = $this->FormatMoney((float) $this->ReadSourceValue($src, 'RewardAllTime', 0), $cur);

        // Wallbox-Gesamtleistung (nur wenn die Quelle die Variable hat)
        $wallbox = '';
        $wbVid = @IPS_GetObjectIDByIdent('WallboxPowerTotal', $src);
        if ($wbVid !== false && $wbVid > 0) {
            $wallbox = $this->FormatPower((float) GetValue($wbVid));
        }

        $devices = $this->ParseDevices((string) $this->ReadSourceValue($src, 'FlexDevices', ''), $cActive, $cAvail, $cUnavail);

        return json_encode(array_merge($style, [
            'stateLabel'   => $stateText !== '' ? $stateText : $this->Translate('No data yet'),
            'cls'          => $cls,
            'accent'       => $accent,
            'month'        => $month,
            'total'        => $total,
            'monthLabel'   => $this->Translate('This month'),
            'totalLabel'   => $this->Translate('Total'),
            'wallbox'      => $wallbox,
            'wallboxLabel' => $this->Translate('Wallboxes'),
            'emptyLabel'   => $this->Translate('No flex devices'),
            'devices'      => $devices,
        ]));
    }

    private function FormatPower(float $w): string
    {
        if (abs($w) >= 1000) {
            return number_format($w / 1000, 2, ',', '.') . ' kW';
        }
        return number_format($w, 0, ',', '.') . ' W';
    }

    /**
     * Zerlegt die FlexDevices-Textzeilen in Name, Meta (Typ + Zusatzinfos) und Farbpunkt.
     */
    private function ParseDevices(string $flexText, string $cActive, string $cAvail, string $cUnavail): array
    {
        $devices = [];
        $labelDelivering = $this->Translate('Delivering');
        $labelUnavailable = $this->Translate('Unavailable');
        $labelAvailable = $this->Translate('Available');

        foreach (explode("\n", $flexText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('·', $line));
            $head = array_shift($parts); // "Name (Typ)"

            $name = $head;
            $type = '';
            if (preg_match('/^(.*?)\s*\(([^)]*)\)/u', $head, $m)) {
                $name = trim($m[1]);
                $type = trim($m[2]);
            }

            // Farbe nach Gerätestatus (Unavailable vor Available prüfen – Teilstring!)
            if (mb_strpos($line, $labelDelivering) !== false) {
                $color = $cActive;
            } elseif (mb_strpos($line, $labelUnavailable) !== false) {
                $color = $cUnavail;
            } elseif ($labelAvailable !== '' && mb_strpos($line, $labelAvailable) !== false) {
                $color = $cAvail;
            } else {
                $color = $cUnavail;
            }

            // Zusatzinfos: alle Segmente außer dem Status (der steckt im Farbpunkt)
            $extras = [];
            foreach ($parts as $p) {
                if ($p === $labelDelivering || $p === $labelUnavailable || $p === $labelAvailable) {
                    continue;
                }
                $extras[] = $p;
            }
            $metaParts = [];
            if ($type !== '') {
                $metaParts[] = $type;
            }
            foreach ($extras as $e) {
                $metaParts[] = $e;
            }

            $devices[] = [
                'name'  => $name,
                'meta'  => implode(' · ', $metaParts),
                'color' => $color,
            ];
        }
        return $devices;
    }

    /**
     * Ermittelt die Quell-Instanz: bevorzugt die manuell gewählte, sonst – wenn es im System genau
     * eine TibberGridReward-Instanz gibt – automatisch diese.
     */
    private function ResolveSource(): int
    {
        $configured = $this->ReadPropertyInteger('SourceInstance');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }
        $list = IPS_GetInstanceListByModuleID(self::SOURCE_MODULE);
        $this->SendDebug(__FUNCTION__, 'SourceInstance=' . $configured . ' · gefundene TibberGridReward-Instanzen: ' . count($list) . ' [' . implode(', ', $list) . ']', 0);
        if (count($list) === 1) {
            return (int) $list[0];
        }
        return 0;
    }

    private function ReadSourceValue(int $instanceID, string $ident, $default)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) {
            return $default;
        }
        return GetValue($vid);
    }

    private function FontStack(string $key): string
    {
        switch ($key) {
            case 'arial':     return 'Arial, Helvetica, sans-serif';
            case 'verdana':   return 'Verdana, Geneva, sans-serif';
            case 'tahoma':    return 'Tahoma, Geneva, sans-serif';
            case 'trebuchet': return '"Trebuchet MS", Helvetica, sans-serif';
            case 'georgia':   return 'Georgia, "Times New Roman", serif';
            case 'courier':   return '"Courier New", Courier, monospace';
            case 'system':
            default:          return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
    }

    private function FontScaleValue(): float
    {
        $v = $this->ReadPropertyFloat('FontScale');
        if ($v < 0.5) {
            $v = 0.5;
        }
        if ($v > 2.5) {
            $v = 2.5;
        }
        return $v;
    }

    private function ColorHex(int $value, string $fallback): string
    {
        if ($value < 0) { // SelectColor: -1 = keine Farbe
            return $fallback;
        }
        return sprintf('#%06X', $value & 0xFFFFFF);
    }

    private function ColorOrEmpty(int $value): string
    {
        return $value < 0 ? '' : sprintf('#%06X', $value & 0xFFFFFF);
    }

    private function FormatMoney(float $value, string $currency): string
    {
        return number_format($value, 2, ',', '.') . ' ' . $currency;
    }

    private function CurrencySymbol(string $code): string
    {
        switch (strtoupper($code)) {
            case 'EUR': return '€';
            case 'SEK':
            case 'NOK':
            case 'DKK': return 'kr';
            case 'GBP': return '£';
            case 'USD': return '$';
            case '': return '€';
            default: return $code;
        }
    }
}
