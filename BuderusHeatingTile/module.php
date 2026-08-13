<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class BuderusHeizungKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    // Variablen-Identifikatoren die wir aus der EMSESP-Instanz lesen
    private const WATCHED_IDENTS = [
        'curflowtemp',
        'rettemp',
        'outdoortemp',
        'seltemp',
        'daytemp',
        'nighttemp',
        'manualtemp',
        'heatingactive',
        'heatingpumpmod',
        'mode',
        'tapwateractive',
        'wwcharge',
    ];

    // Wie wir jeden Ident im Frontend darstellen
    private const IDENT_META = [
        'curflowtemp'    => ['label' => 'Vorlauf',    'unit' => 'Â°C', 'decimals' => 1],
        'rettemp'        => ['label' => 'Rücklauf',   'unit' => 'Â°C', 'decimals' => 1],
        'outdoortemp'    => ['label' => 'Außen',      'unit' => 'Â°C', 'decimals' => 1],
        'seltemp'        => ['label' => 'Soll',       'unit' => 'Â°C', 'decimals' => 1],
        'daytemp'        => ['label' => 'Tag-Soll',   'unit' => 'Â°C', 'decimals' => 1],
        'nighttemp'      => ['label' => 'Nacht-Soll', 'unit' => 'Â°C', 'decimals' => 1],
        'manualtemp'     => ['label' => 'Manuell',    'unit' => 'Â°C', 'decimals' => 1],
        'heatingactive'  => ['label' => 'Heizung',    'unit' => '',   'decimals' => 0],
        'heatingpumpmod' => ['label' => 'Pumpe',      'unit' => '%',  'decimals' => 0],
        'mode'           => ['label' => 'Modus',      'unit' => '',   'decimals' => 0],
        'tapwateractive' => ['label' => 'Warmwasser', 'unit' => '',   'decimals' => 0],
        'wwcharge'       => ['label' => 'WW-Ladung',  'unit' => '',   'decimals' => 0],
    ];

    // EMSESP Mode-Mapping (Integer â†’ String)
    private const MODE_MAP_REVERSE = [
        0 => 'auto',
        1 => 'manual',
        2 => 'off',
    ];

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900); // Alarm priority: 1 (Medium)

        // HTML-SDK Kachel-Visualisierung aktivieren
        $this->SetVisualizationType(1);

        // Eigenschaften
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyBoolean('ShowDHW', true);
        $this->RegisterPropertyBoolean('ShowPumpInfo', true);
        $this->RegisterPropertyString('DeviceName', 'Buderus Heizung');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Alle alten Message-Registrierungen entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $sourceID = $this->ReadPropertyInteger('SourceInstanceID');
        if ($sourceID <= 0 || !IPS_InstanceExists($sourceID)) {
            $this->SetStatus(104); // Konfiguration fehlt
            return;
        }

        // Auf alle Variablen der EMSESP-Instanz hören
        $this->RegisterVariableMessages($sourceID);

        $this->SetStatus(102); // Aktiv

    }

    private function RegisterVariableMessages(int $instanceID): void
    {
        // Alle Kind-Objekte der Quell-Instanz durchsuchen
        $children = IPS_GetChildrenIDs($instanceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) { // Nur Variablen
                $this->RegisterMessage($childID, VM_UPDATE);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            // Variable wurde aktualisiert â†’ Kachel updaten
            $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
        }
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');

        // Initiale Daten direkt ins HTML einbetten damit Kachel sofort befüllt ist
        $initialData = json_encode($this->CollectCurrentData(), JSON_UNESCAPED_UNICODE);
        $html = str_replace('__INITIAL_DATA__', htmlspecialchars($initialData, ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('__DEVICE_NAME__', htmlspecialchars($this->ReadPropertyString('DeviceName'), ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('__SHOW_DHW__', $this->ReadPropertyBoolean('ShowDHW') ? 'true' : 'false', $html);
        $html = str_replace('__SHOW_PUMP__', $this->ReadPropertyBoolean('ShowPumpInfo') ? 'true' : 'false', $html);

        return $html;
    }

    private function PushTileUpdate(): void
    {
        $data = $this->CollectCurrentData();
        $this->UpdateVisualizationValue(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Liest alle relevanten Variablen aus der EMSESP-Instanz aus
     * und gibt ein Array für das Frontend zurück.
     */
    private function CollectCurrentData(): array
    {
        $sourceID = $this->ReadPropertyInteger('SourceInstanceID');
        $data = [
            'curflowtemp'    => null,
            'rettemp'        => null,
            'outdoortemp'    => null,
            'seltemp'        => null,
            'daytemp'        => null,
            'nighttemp'      => null,
            'heatingactive'  => false,
            'heatingpumpmod' => 0,
            'mode'           => 0,
            'tapwateractive' => false,
            'wwcharge'       => false,
        ];

        if ($sourceID <= 0 || !IPS_InstanceExists($sourceID)) {
            return $data;
        }

        // Alle Kind-Variablen der EMSESP-Instanz auslesen
        $children = IPS_GetChildrenIDs($sourceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] !== 2) {
                continue; // Nur Variablen
            }

            $ident = strtolower($obj['ObjectIdent']);

            // Suche nach Idents die einen unserer Keys enthalten
            foreach (self::WATCHED_IDENTS as $watchedIdent) {
                if (str_contains($ident, $watchedIdent)) {
                    $value = GetValue($childID);
                    $data[$watchedIdent] = $value;
                    break;
                }
            }
        }

        return $data;
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $sourceID = $this->ReadPropertyInteger('SourceInstanceID');
        if ($sourceID <= 0 || !IPS_InstanceExists($sourceID)) {
            return;
        }

        switch ($Ident) {
            case 'SetMode':
                // Modus-Wechsel: Integer-Wert â†’ an EMSESP-Instanz weiterleiten
                $modeInt = (int) $Value;
                // EMSESP-Instanz hat eine eigene RequestAction für mode-Variablen
                // Wir suchen die mode-Variable in der Quell-Instanz
                $this->ForwardActionToEMSESP($sourceID, 'mode', $modeInt);
                // Kachel sofort aktualisieren
                $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
                break;

            case 'SetSelTemp':
                $this->ForwardActionToEMSESP($sourceID, 'seltemp', (float) $Value);
                $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
                break;

            case 'SetDayTemp':
                $this->ForwardActionToEMSESP($sourceID, 'daytemp', (float) $Value);
                $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
                break;

            case 'SetNightTemp':
                $this->ForwardActionToEMSESP($sourceID, 'nighttemp', (float) $Value);
                $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
                break;

            case 'DHWBoost':
                // Einmalige Warmwasser-Ladung triggern
                $this->ForwardActionToEMSESP($sourceID, 'wwcharge', true);
                $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
                break;
        }
    }

    /**
     * Leitet eine Aktion an die EMSESP-Instanz weiter, indem wir
     * die entsprechende Variable in der EMSESP-Instanz suchen und
     * deren RequestAction aufrufen.
     */
    private function ForwardActionToEMSESP(int $instanceID, string $targetIdent, mixed $value): void
    {
        $children = IPS_GetChildrenIDs($instanceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] !== 2) {
                continue;
            }

            $ident = strtolower($obj['ObjectIdent']);
            if (str_contains($ident, $targetIdent)) {
                // RequestAction der EMSESP-Instanz aufrufen
                IPS_RequestAction($instanceID, $obj['ObjectIdent'], $value);
                return;
            }
        }
    }

    /**
     * Manuelles Aktualisieren der Kachel (für Debugging)
     */
    public function UpdateTile(): void
    {
        $this->PushTileUpdate();
            $this->DA_SetAvailable(true);
    }
}
