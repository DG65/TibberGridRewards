<?php

declare(strict_types=1);

/**
 * TibberGridRewardTile
 *
 * Eigenständige HTML-SDK-Kachel für die Tile-Visualisierung. Liest die Variablen einer
 * TibberGridReward-Instanz (Quelle) und stellt sie als randlose Status-Kachel dar.
 *
 * Bewusst von der Datenlogik getrennt (Vorbild da8ter): Ein Problem in der Kachel kann die
 * WebSocket-/Datenverbindung der Quell-Instanz nicht beeinträchtigen.
 */
class TibberGridRewardTile extends IPSModule
{
    // GUID des Datenmoduls TibberGridReward (für die Quellen-Auswahl)
    private const SOURCE_MODULE = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);

        // Kachel-Farben (SelectColor liefert 0xRRGGBB)
        $this->RegisterPropertyInteger('ColorActive', 0x27D07F);
        $this->RegisterPropertyInteger('ColorAvailable', 0x2BB3C0);
        $this->RegisterPropertyInteger('ColorUnavailable', 0x7A8A99);

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
        $src = $this->ReadPropertyInteger('SourceInstance');
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

        $src = $this->ReadPropertyInteger('SourceInstance');
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode([
                'stateLabel' => $this->Translate('No source selected'),
                'cls'        => 'off',
                'accent'     => $cUnavail,
                'month'      => '–',
                'total'      => '–',
                'monthLabel' => $this->Translate('This month'),
                'totalLabel' => $this->Translate('Total'),
                'title'      => 'Tibber Grid Rewards',
                'emptyLabel' => $this->Translate('No flex devices'),
                'devices'    => [],
            ]);
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

        $devices = [];
        $flexText = (string) $this->ReadSourceValue($src, 'FlexDevices', '');
        foreach (explode("\n", $flexText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // "Name (Typ) · Status · ..." -> Name + Typ extrahieren
            $name = $line;
            $meta = '';
            if (preg_match('/^(.*?)\s*\(([^)]*)\)/u', $line, $m)) {
                $name = trim($m[1]);
                $meta = trim($m[2]);
            }
            // Farbpunkt nach Gerätestatus (Unavailable vor Available prüfen – Teilstring!)
            if (mb_strpos($line, $this->Translate('Delivering')) !== false) {
                $color = $cActive;
            } elseif (mb_strpos($line, $this->Translate('Unavailable')) !== false) {
                $color = $cUnavail;
            } elseif (mb_strpos($line, $this->Translate('Available')) !== false) {
                $color = $cAvail;
            } else {
                $color = $accent;
            }
            $devices[] = ['name' => $name, 'meta' => $meta, 'color' => $color];
        }

        return json_encode([
            'stateLabel' => $stateText !== '' ? $stateText : $this->Translate('No data yet'),
            'cls'        => $cls,
            'accent'     => $accent,
            'month'      => $month,
            'total'      => $total,
            'monthLabel' => $this->Translate('This month'),
            'totalLabel' => $this->Translate('Total'),
            'title'      => 'Tibber Grid Rewards',
            'emptyLabel' => $this->Translate('No flex devices'),
            'devices'    => $devices,
        ]);
    }

    /**
     * Liest eine Variable der Quell-Instanz anhand ihres Idents.
     */
    private function ReadSourceValue(int $instanceID, string $ident, $default)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) {
            return $default;
        }
        return GetValue($vid);
    }

    private function ColorHex(int $value, string $fallback): string
    {
        if ($value < 0) { // SelectColor: -1 = keine Farbe
            return $fallback;
        }
        return sprintf('#%06X', $value & 0xFFFFFF);
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
