<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartIrrigationKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // UI / HTML-SDK
        $this->SetVisualizationType(1);

        // Properties für Meldung & Fortschritt
        $this->RegisterPropertyInteger('VarMessageTitle', 0);
        $this->RegisterPropertyInteger('VarProgress', 0);
        $this->RegisterPropertyInteger('VarRuntime', 0);
        $this->RegisterPropertyInteger('VarRemaining', 0);

        // Properties für SmartLawnAI Daten
        $this->RegisterPropertyInteger('VarFlowRate', 0);
        $this->RegisterPropertyInteger('VarRainToday', 0);
        $this->RegisterPropertyInteger('VarRainTomorrow', 0);
        $this->RegisterPropertyInteger('VarConsumptionToday', 0);
        $this->RegisterPropertyInteger('VarConsumptionWeek', 0);
        $this->RegisterPropertyInteger('VarConsumptionMonth', 0);
        $this->RegisterPropertyInteger('VarDeviceStatus', 0);

        // Feuchtigkeitssensoren (Liste als JSON String)
        $this->RegisterPropertyString('MoistureSensors', '[]');

        // Steuerung
        $this->RegisterPropertyInteger('CtrlIrrigationActive', 0);
        $this->RegisterPropertyInteger('CtrlAutoActive', 0);
        $this->RegisterPropertyInteger('CtrlBlockActive', 0);
        $this->RegisterPropertyInteger('CtrlManualStart', 0);
        $this->RegisterPropertyInteger('CtrlSoakPause', 0);
        $this->RegisterPropertyInteger('CtrlTriggerMoisture', 0);
        $this->RegisterPropertyInteger('CtrlTargetMoisture', 0);
        $this->RegisterPropertyInteger('CtrlMaxDuration', 0);

        // KI & Logs
        $this->RegisterPropertyInteger('VarAiResponse', 0);
        $this->RegisterPropertyInteger('VarLogInfo', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Alle alten Nachrichten-Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Neue Variablen registrieren
        $props = [
            'VarMessageTitle', 'VarProgress', 'VarRuntime', 'VarRemaining',
            'VarFlowRate', 'VarRainToday', 'VarRainTomorrow', 'VarConsumptionToday', 'VarConsumptionWeek', 'VarConsumptionMonth', 'VarDeviceStatus',
            'CtrlIrrigationActive', 'CtrlAutoActive', 'CtrlBlockActive', 'CtrlManualStart', 'CtrlSoakPause', 'CtrlTriggerMoisture', 'CtrlTargetMoisture', 'CtrlMaxDuration',
            'VarAiResponse', 'VarLogInfo'
        ];

        foreach ($props as $propName) {
            $vid = $this->ReadPropertyInteger($propName);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        // Moisture Sensors registrieren
        $moistureSensors = json_decode($this->ReadPropertyString('MoistureSensors'), true);
        if (is_array($moistureSensors)) {
            foreach ($moistureSensors as $sensor) {
                $vid = (int)($sensor['VariableID'] ?? 0);
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $this->RegisterMessage($vid, VM_UPDATE);
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

    public function GetConfigurationForm(): string
    {
        return file_get_contents(__DIR__ . '/form.json');
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

        // Weiterleiten von Frontend-Befehlen an die tatsächliche Variable
        $propName = '';
        if (str_starts_with($Ident, 'Action_')) {
            $propName = substr($Ident, 7); // e.g. Action_CtrlSoakPause -> CtrlSoakPause
        }

        if ($propName !== '') {
            $vid = $this->ReadPropertyInteger($propName);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                if (IPS_GetVariable($vid)['VariableAction'] > 0) {
                    RequestAction($vid, $Value);
                } else {
                    SetValue($vid, $Value); // Fallback falls kein Aktions-Skript hinterlegt ist
                }
            }
        }
    }

    private function getVarFormatted(string $propName, $default = '')
    {
        $vid = $this->ReadPropertyInteger($propName);
        if ($vid > 0 && IPS_VariableExists($vid)) {
            return GetValueFormatted($vid);
        }
        return $default;
    }

    private function getVarValue(string $propName, $default = null)
    {
        $vid = $this->ReadPropertyInteger($propName);
        if ($vid > 0 && IPS_VariableExists($vid)) {
            return GetValue($vid);
        }
        return $default;
    }

    private function UpdateData(): void
    {
        $payload = [];
        
        // Meldung & Fortschritt
        $payload['MessageTitle'] = $this->getVarFormatted('VarMessageTitle', '-');
        $payload['Progress'] = (int)$this->getVarValue('VarProgress', 0);
        $payload['Runtime'] = $this->getVarFormatted('VarRuntime', '0 Min');
        $payload['Remaining'] = $this->getVarFormatted('VarRemaining', '0 Min');

        // SmartLawnAI Daten
        $payload['FlowRate'] = $this->getVarFormatted('VarFlowRate', '0,00 l/min');
        $payload['RainToday'] = $this->getVarFormatted('VarRainToday', '0,00 mm');
        $payload['RainTomorrow'] = $this->getVarFormatted('VarRainTomorrow', '0,00 mm');
        $payload['ConsumptionToday'] = $this->getVarFormatted('VarConsumptionToday', '0 L');
        $payload['ConsumptionWeek'] = $this->getVarFormatted('VarConsumptionWeek', '0 L');
        $payload['ConsumptionMonth'] = $this->getVarFormatted('VarConsumptionMonth', '0 L');
        
        $deviceStatus = $this->getVarValue('VarDeviceStatus', false);
        $payload['DeviceStatusOk'] = is_bool($deviceStatus) ? $deviceStatus : ((int)$deviceStatus === 0);

        // Moisture Sensors
        $payload['MoistureSensors'] = [];
        $moistureSensors = json_decode($this->ReadPropertyString('MoistureSensors'), true);
        if (is_array($moistureSensors)) {
            foreach ($moistureSensors as $sensor) {
                $vid = (int)($sensor['VariableID'] ?? 0);
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $val = GetValueFormatted($vid);
                    $raw = (int)GetValue($vid);
                    $payload['MoistureSensors'][] = [
                        'Name' => $sensor['Name'],
                        'Value' => $val,
                        'Raw' => $raw
                    ];
                }
            }
        }

        // Steuerung
        $payload['CtrlIrrigationActive'] = (bool)$this->getVarValue('CtrlIrrigationActive', false);
        $payload['CtrlAutoActive'] = (bool)$this->getVarValue('CtrlAutoActive', false);
        $payload['CtrlBlockActive'] = (bool)$this->getVarValue('CtrlBlockActive', false);
        $payload['CtrlManualStart'] = (bool)$this->getVarValue('CtrlManualStart', false);
        
        $payload['CtrlSoakPause'] = (int)$this->getVarValue('CtrlSoakPause', 0);
        $payload['CtrlTriggerMoisture'] = (int)$this->getVarValue('CtrlTriggerMoisture', 0);
        $payload['CtrlTargetMoisture'] = (int)$this->getVarValue('CtrlTargetMoisture', 0);
        $payload['CtrlMaxDuration'] = (int)$this->getVarValue('CtrlMaxDuration', 0);

        // KI & Logs
        $payload['AiResponse'] = $this->getVarFormatted('VarAiResponse', '');
        
        $logInfo = $this->getVarValue('VarLogInfo', '');
        if (is_string($logInfo)) {
            // Check if it's JSON or HTML. Just pass as is. We'll handle in JS.
            $payload['LogInfo'] = $logInfo;
        } else {
            $payload['LogInfo'] = '';
        }

        $this->UpdateVisualizationValue(json_encode($payload));
        $this->DA_SetAvailable(true);
    }
}
