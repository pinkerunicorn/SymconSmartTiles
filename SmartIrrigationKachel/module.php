<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartIrrigationKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    // All SmartLawnAI variable idents we want to monitor
    private const SLAI_IDENTS = [
        'SummaryStatus', 'WateringActive', 'CurrentFlowRate',
        'WaterToday', 'WaterThisWeek', 'WaterThisMonth',
        'ForecastRainToday', 'ForecastRainTomorrow',
        'LastGeminiResponse', 'IrrigationLog',
        'DefaultZielFeuchte', 'DefaultStartSchwellwert',
        'SickerpauseMinuten', 'GlobalMaxDuration',
        'AutomaticActive', 'ForceStart', 'SperrzeitActive',
        'DeviceAvailable'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);
        $this->SetVisualizationType(1);
        $this->RegisterPropertyInteger('SmartLawnAIID', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Unregister all old messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $slaiId = $this->ReadPropertyInteger('SmartLawnAIID');
        if ($slaiId < 1 || !@IPS_InstanceExists($slaiId)) {
            return;
        }

        // Register messages for all known SLAI variables
        foreach (self::SLAI_IDENTS as $ident) {
            $vid = @IPS_GetObjectIDByIdent($ident, $slaiId);
            if ($vid !== false && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        // Also register messages for zone moisture sensors
        $zonesJson = @IPS_GetProperty($slaiId, 'Zones');
        if ($zonesJson !== false && $zonesJson !== '') {
            $zones = json_decode($zonesJson, true);
            if (is_array($zones)) {
                foreach ($zones as $zone) {
                    $sensorId = (int)($zone['SensorID'] ?? 0);
                    if ($sensorId > 0) {
                        $resolved = @SLAI_ResolveSensorObject($slaiId, $sensorId);
                        if (is_array($resolved) && isset($resolved['MoistureID']) && $resolved['MoistureID'] > 0) {
                            $this->RegisterMessage($resolved['MoistureID'], VM_UPDATE);
                        }
                    }
                }
            }
        }

        $this->UpdateData();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateData();
        }
    }

    public function GetVisualizationTile(): string
    {
        return file_get_contents(__DIR__ . '/module.html');
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'Init') {
            $this->UpdateData();
            return;
        }

        // Forward actions to SmartLawnAI variables
        // Ident format: "Action_<SLAI_Ident>"
        if (str_starts_with($Ident, 'Action_')) {
            $slaiIdent = substr($Ident, 7);
            $slaiId = $this->ReadPropertyInteger('SmartLawnAIID');
            if ($slaiId < 1 || !@IPS_InstanceExists($slaiId)) {
                return;
            }

            $vid = @IPS_GetObjectIDByIdent($slaiIdent, $slaiId);
            if ($vid !== false && IPS_VariableExists($vid)) {
                $varInfo = IPS_GetVariable($vid);
                // Cast value to correct type
                switch ($varInfo['VariableType']) {
                    case VARIABLETYPE_BOOLEAN:
                        $Value = (bool)$Value;
                        break;
                    case VARIABLETYPE_INTEGER:
                        $Value = (int)$Value;
                        break;
                    case VARIABLETYPE_FLOAT:
                        $Value = (float)$Value;
                        break;
                    case VARIABLETYPE_STRING:
                        $Value = (string)$Value;
                        break;
                }

                if ($varInfo['VariableAction'] > 0) {
                    RequestAction($vid, $Value);
                } else {
                    SetValue($vid, $Value);
                }
            }
        }
    }

    /**
     * Read a formatted value from a SmartLawnAI variable by ident
     */
    private function readSlaiFormatted(int $slaiId, string $ident, string $default = ''): string
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $slaiId);
        if ($vid !== false && IPS_VariableExists($vid)) {
            return GetValueFormatted($vid);
        }
        return $default;
    }

    /**
     * Read a raw value from a SmartLawnAI variable by ident
     */
    private function readSlaiValue(int $slaiId, string $ident, mixed $default = null): mixed
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $slaiId);
        if ($vid !== false && IPS_VariableExists($vid)) {
            return GetValue($vid);
        }
        return $default;
    }

    private function UpdateData(): void
    {
        $slaiId = $this->ReadPropertyInteger('SmartLawnAIID');
        if ($slaiId < 1 || !@IPS_InstanceExists($slaiId)) {
            $this->UpdateVisualizationValue(json_encode(['error' => 'Keine SmartLawnAI Instanz konfiguriert']));
            return;
        }

        $payload = [];

        // Status & Progress (SummaryStatus contains HTML progress bar when watering)
        $payload['SummaryStatus'] = (string)$this->readSlaiValue($slaiId, 'SummaryStatus', '');
        $payload['WateringActive'] = (bool)$this->readSlaiValue($slaiId, 'WateringActive', false);

        // Sensor & Weather data
        $payload['FlowRate'] = $this->readSlaiFormatted($slaiId, 'CurrentFlowRate', '0,00 l/min');
        $payload['FlowRateRaw'] = (float)$this->readSlaiValue($slaiId, 'CurrentFlowRate', 0.0);
        $payload['RainToday'] = $this->readSlaiFormatted($slaiId, 'ForecastRainToday', '0,00 mm');
        $payload['RainTomorrow'] = $this->readSlaiFormatted($slaiId, 'ForecastRainTomorrow', '0,00 mm');

        // Consumption
        $payload['ConsumptionToday'] = $this->readSlaiFormatted($slaiId, 'WaterToday', '0 L');
        $payload['ConsumptionWeek'] = $this->readSlaiFormatted($slaiId, 'WaterThisWeek', '0 L');
        $payload['ConsumptionMonth'] = $this->readSlaiFormatted($slaiId, 'WaterThisMonth', '0 L');

        // Device status
        $deviceStatus = (int)$this->readSlaiValue($slaiId, 'DeviceAvailable', 0);
        $payload['DeviceStatusOk'] = ($deviceStatus >= 1);

        // Moisture sensors from zones
        $payload['MoistureSensors'] = [];
        $zonesJson = @IPS_GetProperty($slaiId, 'Zones');
        if ($zonesJson !== false && $zonesJson !== '') {
            $zones = json_decode($zonesJson, true);
            if (is_array($zones)) {
                foreach ($zones as $zone) {
                    $sensorId = (int)($zone['SensorID'] ?? 0);
                    $zoneName = $zone['GroupName'] ?? 'Zone';
                    if ($sensorId > 0) {
                        $resolved = @SLAI_ResolveSensorObject($slaiId, $sensorId);
                        if (is_array($resolved) && isset($resolved['MoistureID']) && $resolved['MoistureID'] > 0) {
                            $moistureVal = GetValue($resolved['MoistureID']);
                            $payload['MoistureSensors'][] = [
                                'Name' => 'Feuchte ' . $zoneName,
                                'Value' => round((float)$moistureVal, 1) . ' %',
                                'Raw' => (int)round((float)$moistureVal)
                            ];
                        }
                    }
                }
            }
        }

        // Controls (Sliders & Switches)
        $payload['AutomaticActive'] = (bool)$this->readSlaiValue($slaiId, 'AutomaticActive', false);
        $payload['SperrzeitActive'] = (bool)$this->readSlaiValue($slaiId, 'SperrzeitActive', false);
        $payload['ForceStart'] = (bool)$this->readSlaiValue($slaiId, 'ForceStart', false);
        $payload['TriggerFeuchte'] = (float)$this->readSlaiValue($slaiId, 'DefaultStartSchwellwert', 0);
        $payload['ZielFeuchte'] = (float)$this->readSlaiValue($slaiId, 'DefaultZielFeuchte', 0);
        $payload['MaxDuration'] = (int)$this->readSlaiValue($slaiId, 'GlobalMaxDuration', 0);

        // AI & Logs
        $payload['AiResponse'] = (string)$this->readSlaiValue($slaiId, 'LastGeminiResponse', '');
        $payload['LogInfo'] = (string)$this->readSlaiValue($slaiId, 'IrrigationLog', '');

        $this->UpdateVisualizationValue(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->DA_SetAvailable(true);
    }
}
