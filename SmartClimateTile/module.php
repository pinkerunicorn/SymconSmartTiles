<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

/**
 * SmartClimateTile – Interaktives HTML-SDK Klimaregler-Widget fuer IP-Symcon 9.
 *
 * Zeigt einen SVG-Temperatur-Gauge mit Drag-to-Set, Modi-Leiste,
 * dynamischen Status-Indikatoren und vollem Animations-Paket.
 *
 * @author Florian Grassinger
 */
class SmartClimateTile extends IPSModuleStrict
{
    use SmartLog_Trait;

    // =======================================================================
    // Modul-Lifecycle
    // =======================================================================

    public function Create(): void
    {
        parent::Create();

        // HTML-SDK Tile Visualization aktivieren
        $this->SetVisualizationType(1);

        // --- Klimadaten ---
        $this->RegisterPropertyInteger('VarActualTemp', 0);
        $this->RegisterPropertyInteger('VarTargetTemp', 0);
        $this->RegisterPropertyInteger('VarHumidity', 0);
        $this->RegisterPropertyInteger('VarValvePosition', 0);
        $this->RegisterPropertyInteger('VarModeSelect', 0);

        // --- Modi & Indikatoren (als JSON-Strings) ---
        $this->RegisterPropertyString('AvailableModes', '[]');
        $this->RegisterPropertyString('StatusIndicators', '[]');

        // --- Visualisierung ---
        $this->RegisterPropertyInteger('ColorCold', 0x3B82F6);  // Blau
        $this->RegisterPropertyInteger('ColorWarm', 0xEF4444);  // Rot
        $this->RegisterPropertyFloat('TempMin', 5.0);
        $this->RegisterPropertyFloat('TempMax', 30.0);
        $this->RegisterPropertyFloat('TempStep', 0.5);
        $this->RegisterPropertyBoolean('ShowLabels', true);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Alle bisherigen Message-Registrierungen entfernen
        $messageList = $this->GetMessageList();
        foreach ($messageList as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Variable-Referenzen fuer MessageSink registrieren
        $varIDs = [
            $this->ReadPropertyInteger('VarActualTemp'),
            $this->ReadPropertyInteger('VarTargetTemp'),
            $this->ReadPropertyInteger('VarHumidity'),
            $this->ReadPropertyInteger('VarValvePosition'),
            $this->ReadPropertyInteger('VarModeSelect'),
        ];

        // Status-Indikatoren Variablen registrieren
        $indicators = json_decode($this->ReadPropertyString('StatusIndicators'), true);
        if (is_array($indicators)) {
            foreach ($indicators as $ind) {
                if (isset($ind['VariableID']) && $ind['VariableID'] > 0) {
                    $varIDs[] = $ind['VariableID'];
                }
            }
        }

        // Nur gueltige IDs registrieren
        foreach ($varIDs as $id) {
            if ($id > 0 && @IPS_ObjectExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Status setzen
        $actualTemp = $this->ReadPropertyInteger('VarActualTemp');
        $targetTemp = $this->ReadPropertyInteger('VarTargetTemp');

        if ($actualTemp == 0) {
            $this->SetStatus(104); // Ist-Temperatur nicht konfiguriert
        } elseif ($targetTemp == 0) {
            $this->SetStatus(200); // Soll-Temperatur nicht konfiguriert
        } else {
            $this->SetStatus(102); // Aktiv
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->PushUpdate();
        }
    }

    // =======================================================================
    // HTML-SDK Tile Visualization
    // =======================================================================

    public function GetVisualizationTile(): string
    {
        $htmlPath = __DIR__ . '/module.html';
        if (!file_exists($htmlPath)) {
            return '<div style="color:red;padding:20px;">module.html nicht gefunden</div>';
        }

        $html = file_get_contents($htmlPath);

        // Bewährtes Pattern (EnergyDistributionTile): htmlspecialchars + .replace() im JS
        $initialData = json_encode($this->GetTileData(), JSON_UNESCAPED_UNICODE);
        $html = str_replace('__INITIAL_DATA__', htmlspecialchars($initialData, ENT_QUOTES, 'UTF-8'), $html);

        return $html;
    }

    /**
     * Pusht aktuelle Daten an alle verbundenen Tile-Clients.
     */
    private function PushUpdate(): void
    {
        $data = $this->GetTileData();
        $this->UpdateVisualizationValue(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Sammelt alle aktuellen Werte fuer das Tile-Widget.
     */
    private function GetTileData(): array
    {
        $actualTempID = $this->ReadPropertyInteger('VarActualTemp');
        $targetTempID = $this->ReadPropertyInteger('VarTargetTemp');
        $humidityID   = $this->ReadPropertyInteger('VarHumidity');
        $valveID      = $this->ReadPropertyInteger('VarValvePosition');
        $modeID       = $this->ReadPropertyInteger('VarModeSelect');

        // Werte lesen
        $actualTemp = ($actualTempID > 0 && @IPS_ObjectExists($actualTempID))
            ? (float) GetValue($actualTempID) : 0.0;
        $targetTemp = ($targetTempID > 0 && @IPS_ObjectExists($targetTempID))
            ? (float) GetValue($targetTempID) : 0.0;

        $hasHumidity = ($humidityID > 0 && @IPS_ObjectExists($humidityID));
        $humidity = $hasHumidity ? (float) GetValue($humidityID) : 0.0;

        $hasValve = ($valveID > 0 && @IPS_ObjectExists($valveID));
        $valvePosition = 0.0;
        if ($hasValve) {
            $val = GetValue($valveID);
            // Boolean Support: false=0%, true=100%
            if (is_bool($val)) {
                $valvePosition = $val ? 100.0 : 0.0;
            } else {
                $valvePosition = (float) $val;
            }
        }

        // Aktiver Modus
        $activeMode = ($modeID > 0 && @IPS_ObjectExists($modeID))
            ? GetValue($modeID) : null;

        // Modi-Liste
        $modes = json_decode($this->ReadPropertyString('AvailableModes'), true) ?: [];

        // Status-Indikatoren evaluieren
        $indicatorDefs = json_decode($this->ReadPropertyString('StatusIndicators'), true) ?: [];
        $indicators = [];
        foreach ($indicatorDefs as $ind) {
            $varID = $ind['VariableID'] ?? 0;
            if ($varID <= 0 || !@IPS_ObjectExists($varID)) {
                continue;
            }

            $currentValue = GetValue($varID);
            $activeValue  = $ind['ActiveValue'] ?? '';

            // Vergleich: Typ-sensitiv (Bool, Int, String)
            $isActive = $this->CompareValues($currentValue, $activeValue);

            $indicators[] = [
                'active'        => $isActive,
                'icon'          => $isActive ? ($ind['ActiveIcon'] ?? '') : ($ind['InactiveIcon'] ?? ''),
                'color'         => $isActive ? ($ind['ActiveColor'] ?? 0x999999) : ($ind['InactiveColor'] ?? 0x555555),
                'label'         => $ind['Label'] ?? '',
            ];
        }

        // Konfigurations-Parameter
        $config = [
            'colorCold'  => $this->ReadPropertyInteger('ColorCold'),
            'colorWarm'  => $this->ReadPropertyInteger('ColorWarm'),
            'tempMin'    => $this->ReadPropertyFloat('TempMin'),
            'tempMax'    => $this->ReadPropertyFloat('TempMax'),
            'tempStep'   => $this->ReadPropertyFloat('TempStep'),
            'showLabels' => $this->ReadPropertyBoolean('ShowLabels'),
        ];

        return [
            'actualTemp'       => round($actualTemp, 1),
            'targetTemp'       => round($targetTemp, 1),
            'humidity'         => round($humidity, 1),
            'valvePosition'    => round($valvePosition, 0),
            'hasHumidity'      => $hasHumidity,
            'hasValve'         => $hasValve,
            'activeMode'       => $activeMode,
            'modes'            => $modes,
            'statusIndicators' => $indicators,
            'config'           => $config,
        ];
    }

    /**
     * Vergleicht einen Variable-Wert mit dem konfigurierten ActiveValue.
     */
    private function CompareValues(mixed $currentValue, string $activeValue): bool
    {
        if (is_bool($currentValue)) {
            return $currentValue === filter_var($activeValue, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($currentValue)) {
            return $currentValue === (int) $activeValue;
        }
        if (is_float($currentValue)) {
            return abs($currentValue - (float) $activeValue) < 0.001;
        }
        return (string) $currentValue === $activeValue;
    }

    // =======================================================================
    // RequestAction – Empfaengt Widget-Interaktionen
    // =======================================================================

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'SetTemperature':
                $targetTempID = $this->ReadPropertyInteger('VarTargetTemp');
                if ($targetTempID > 0 && @IPS_ObjectExists($targetTempID)) {
                    $temp = round((float) $Value, 1);
                    $this->SLogInfo("Soll-Temperatur geaendert: {$temp} °C");
                    RequestAction($targetTempID, $temp);
                }
                break;

            case 'SetMode':
                $modeID = $this->ReadPropertyInteger('VarModeSelect');
                if ($modeID > 0 && @IPS_ObjectExists($modeID)) {
                    $modeValue = $Value;
                    $this->SLogInfo("Modus geaendert: {$modeValue}");
                    RequestAction($modeID, $modeValue);
                }
                break;

            default:
                $this->SLogWarning("Unbekannter Ident: {$Ident}");
                break;
        }
    }

    // =======================================================================
    // Modus-Analyse (Form-Buttons)
    // =======================================================================

    /**
     * Analysiert die Modus-Variable und importiert Assoziationen.
     */
    public function AnalyzeModeVariable(): void
    {
        $modeVarID = $this->ReadPropertyInteger('VarModeSelect');
        if ($modeVarID <= 0 || !@IPS_ObjectExists($modeVarID)) {
            echo "Keine Modus-Variable konfiguriert!";
            return;
        }

        $variable = IPS_GetVariable($modeVarID);
        $modes = [];

        // 1. Versuch: CustomPresentation auslesen
        $customPresentation = $variable['VariableCustomPresentation'] ?? [];
        if (!empty($customPresentation)) {
            $modes = $this->ExtractModesFromPresentation($customPresentation);
        }

        // 2. Versuch: Native VariablePresentation (z.B. Homematic, Gardena etc.)
        if (empty($modes)) {
            $nativePresentation = $variable['VariablePresentation'] ?? [];
            if (!empty($nativePresentation)) {
                $modes = $this->ExtractModesFromPresentation($nativePresentation);
            }
        }

        // 3. Versuch: Profil auslesen
        if (empty($modes)) {
            $profileName = $variable['VariableCustomProfile'] ?: ($variable['VariableProfile'] ?? '');
            if ($profileName !== '' && @IPS_VariableProfileExists($profileName)) {
                $modes = $this->ExtractModesFromProfile($profileName);
            }
        }

        if (empty($modes)) {
            echo "Keine Assoziationen in Profil oder Darstellung gefunden.";
            return;
        }

        // Bestehende Anpassungen erhalten (Icon/Farbe)
        $existingModes = json_decode($this->ReadPropertyString('AvailableModes'), true) ?: [];
        $existingByValue = [];
        foreach ($existingModes as $m) {
            $existingByValue[$m['Value']] = $m;
        }

        foreach ($modes as &$mode) {
            if (isset($existingByValue[$mode['Value']])) {
                $existing = $existingByValue[$mode['Value']];
                // Behalte manuell gesetzte Icons/Farben
                if (!empty($existing['Icon'])) {
                    $mode['Icon'] = $existing['Icon'];
                }
                if (!empty($existing['Color']) && $existing['Color'] !== 0) {
                    $mode['Color'] = $existing['Color'];
                }
            }
        }
        unset($mode);

        IPS_SetProperty($this->InstanceID, 'AvailableModes', json_encode($modes, JSON_UNESCAPED_UNICODE));
        IPS_ApplyChanges($this->InstanceID);

        echo count($modes) . " Modi importiert.";
    }

    /**
     * Leert die Modi-Liste.
     */
    public function ClearModes(): void
    {
        IPS_SetProperty($this->InstanceID, 'AvailableModes', '[]');
        IPS_ApplyChanges($this->InstanceID);
        echo "Modi-Liste geleert.";
    }

    /**
     * Extrahiert Modi aus einer CustomPresentation (ENUMERATION oder VALUE_PRESENTATION).
     */
    private function ExtractModesFromPresentation(array $presentation): array
    {
        $modes = [];

        // ENUMERATION / native Presentation mit OPTIONS
        if (isset($presentation['OPTIONS'])) {
            $options = $presentation['OPTIONS'];
            if (is_string($options)) {
                $options = json_decode($options, true) ?: [];
            }
            foreach ($options as $opt) {
                // Value kann String (z.B. Homematic: "AUTOMATIC") oder Integer sein
                $value = $opt['Value'] ?? 0;
                $color = $opt['Color'] ?? -1;
                $modes[] = [
                    'Value'   => $value,
                    'Caption' => $opt['Caption'] ?? (string) $value,
                    'Icon'    => ($opt['IconActive'] ?? false) ? ($opt['IconValue'] ?? '') : '',
                    'Color'   => ($color === -1) ? 0 : $color,
                ];
            }
        }

        // VALUE_PRESENTATION mit INTERVALS
        if (empty($modes) && isset($presentation['INTERVALS'])) {
            $intervals = $presentation['INTERVALS'];
            if (is_string($intervals)) {
                $intervals = json_decode($intervals, true) ?: [];
            }
            foreach ($intervals as $intv) {
                if ($intv['ConstantActive'] ?? false) {
                    $colorVal = ($intv['ColorActive'] ?? false) ? ($intv['ColorValue'] ?? 0) : 0;
                    $modes[] = [
                        'Value'   => $intv['IntervalMinValue'] ?? 0,
                        'Caption' => $intv['ConstantValue'] ?? '',
                        'Icon'    => ($intv['IconActive'] ?? false) ? ($intv['IconValue'] ?? '') : '',
                        'Color'   => $colorVal,
                    ];
                }
            }
        }

        return $modes;
    }

    /**
     * Extrahiert Modi aus einem Variablen-Profil.
     */
    private function ExtractModesFromProfile(string $profileName): array
    {
        $modes = [];
        $profile = IPS_GetVariableProfile($profileName);

        if (isset($profile['Associations']) && is_array($profile['Associations'])) {
            foreach ($profile['Associations'] as $assoc) {
                $modes[] = [
                    'Value'   => $assoc['Value'] ?? 0,
                    'Caption' => $assoc['Name'] ?? '',
                    'Icon'    => $assoc['Icon'] ?? '',
                    'Color'   => $assoc['Color'] ?? 0,
                ];
            }
        }

        return $modes;
    }
}
