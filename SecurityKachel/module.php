<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SecurityKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1); // Enable HTML-SDK Kachel-Visualisierung
        $this->RegisterPropertyInteger('TileType', 1); // Not used directly, just for compatibility
        $this->RegisterPropertyInteger('DeviceRegistryID', 0);
        $this->RegisterPropertyInteger('SmartControllerID', 0);
        $this->DA_RegisterAvailability(900);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // We unregister all previous messages first to avoid duplicates
        $msgList = $this->GetMessageList();
        foreach ($msgList as $senderID => $msgIDs) {
            foreach ($msgIDs as $msgID) {
                $this->UnregisterMessage($senderID, $msgID);
            }
        }
        
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->RegisterMessageForSources();
        $this->UpdateData();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->RegisterMessageForSources();
            $this->UpdateData();
            return;
        }

        if ($Message == VM_UPDATE) {
            // Unabhängig davon, welche Variable sich ändert, pushen wir einfach die frischen Daten ans Frontend
            $this->UpdateData();
        }
    }

    private function RegisterMessageForSources(): void
    {
        // 1. SmartController Variablen registrieren
        $shcId = $this->ReadPropertyInteger('SmartControllerID');
        if ($shcId > 0 && IPS_InstanceExists($shcId)) {
            $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
            if ($presenceId) $this->RegisterMessage($presenceId, VM_UPDATE);
            
            $alarmId = @IPS_GetObjectIDByIdent('AlarmLevel', $shcId);
            if ($alarmId) $this->RegisterMessage($alarmId, VM_UPDATE);
        }

        // 2. DeviceRegistry Sensoren registrieren
        $drId = $this->ReadPropertyInteger('DeviceRegistryID');
        if ($drId > 0 && IPS_InstanceExists($drId)) {
            if (function_exists('SDR_GetDevicesByType')) {
                $contacts = SDR_GetDevicesByType($drId, 'DevicesContactSensor');
                $motions = SDR_GetDevicesByType($drId, 'DevicesMotionSensor');
                
                $allDevices = array_merge($contacts, $motions);
                foreach ($allDevices as $device) {
                    $varId = 0;
                    if (!empty($device['OpenClose_VarID'])) $varId = (int)$device['OpenClose_VarID'];
                    else if (!empty($device['Status_VarID'])) $varId = (int)$device['Status_VarID'];
                    else if (!empty($device['OnOff_VarID'])) $varId = (int)$device['OnOff_VarID'];
                    
                    if ($varId > 0 && IPS_VariableExists($varId)) {
                        $this->RegisterMessage($varId, VM_UPDATE);
                    }
                }
            }
        }
    }

    private function UpdateData(): void
    {
        $payload = [
            'presenceMode' => 0, 
            'alarmLevel' => 0,   
            'openWindows' => [],
            'activeMotions' => []
        ];

        // 1. Fetch from SmartController
        $shcId = $this->ReadPropertyInteger('SmartControllerID');
        if ($shcId > 0 && IPS_InstanceExists($shcId)) {
            if (function_exists('SHC_GetPresenceMode')) {
                $payload['presenceMode'] = SHC_GetPresenceMode($shcId);
            } else {
                $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
                if ($presenceId) $payload['presenceMode'] = GetValue($presenceId);
            }

            if (function_exists('SHC_GetAlarmLevel')) {
                $payload['alarmLevel'] = SHC_GetAlarmLevel($shcId);
            } else {
                $alarmId = @IPS_GetObjectIDByIdent('AlarmLevel', $shcId);
                if ($alarmId) $payload['alarmLevel'] = GetValue($alarmId);
            }
        }

        // 2. Fetch from DeviceRegistry
        $drId = $this->ReadPropertyInteger('DeviceRegistryID');
        if ($drId > 0 && IPS_InstanceExists($drId) && function_exists('SDR_GetDevicesByType')) {
            $contacts = SDR_GetDevicesByType($drId, 'DevicesContactSensor');
            foreach ($contacts as $contact) {
                $varId = 0;
                if (!empty($contact['OpenClose_VarID'])) $varId = (int)$contact['OpenClose_VarID'];
                else if (!empty($contact['Status_VarID'])) $varId = (int)$contact['Status_VarID'];
                else if (!empty($contact['OnOff_VarID'])) $varId = (int)$contact['OnOff_VarID'];
                
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    if (GetValue($varId)) {
                        $payload['openWindows'][] = $contact['name'] ?? 'Unbekanntes Fenster';
                    }
                }
            }

            $motions = SDR_GetDevicesByType($drId, 'DevicesMotionSensor');
            foreach ($motions as $motion) {
                $varId = 0;
                if (!empty($motion['Status_VarID'])) $varId = (int)$motion['Status_VarID'];
                else if (!empty($motion['OnOff_VarID'])) $varId = (int)$motion['OnOff_VarID'];
                
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    if (GetValue($varId)) {
                        $payload['activeMotions'][] = $motion['name'] ?? 'Unbekannter Melder';
                    }
                }
            }
        }

        $this->UpdateFormField('SecurityData', 'value', json_encode($payload));
        $this->DA_SetAvailable(true);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'SetPresenceMode') {
            $shcId = $this->ReadPropertyInteger('SmartControllerID');
            if ($shcId > 0 && IPS_InstanceExists($shcId)) {
                if (function_exists('SHC_SetPresenceMode')) {
                    SHC_SetPresenceMode($shcId, (int)$Value);
                } else {
                    $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
                    if ($presenceId) {
                        RequestAction($presenceId, (int)$Value);
                    }
                }
            }
        }
    }
}
