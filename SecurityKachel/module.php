<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SecurityKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('TileType', 1); // Not used directly, just for compatibility
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
        $shcInstances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (count($shcInstances) > 0) {
            $shcId = $shcInstances[0];
            $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
            if ($presenceId) $this->RegisterMessage($presenceId, VM_UPDATE);
            
            $alarmId = @IPS_GetObjectIDByIdent('AlarmLevel', $shcId);
            if ($alarmId) $this->RegisterMessage($alarmId, VM_UPDATE);
        }

        // 2. DeviceRegistry Sensoren registrieren
        $drInstances = IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
        if (count($drInstances) > 0) {
            $drId = $drInstances[0];
            if (function_exists('SDR_GetDevicesByType')) {
                $contacts = SDR_GetDevicesByType($drId, 'DevicesContactSensor');
                $motions = SDR_GetDevicesByType($drId, 'DevicesMotionSensor');
                
                $allDevices = array_merge($contacts, $motions);
                foreach ($allDevices as $device) {
                    // Try different variable fields
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
            'presenceMode' => 0, // 0=Zuhause, 1=Kurz weg, 2=Urlaub
            'alarmLevel' => 0,   // 0=OK, 1=Warnung, 2=Alarm
            'openWindows' => [],
            'activeMotions' => []
        ];

        // 1. Fetch from SmartController
        $shcInstances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        $shcId = count($shcInstances) > 0 ? $shcInstances[0] : 0;
        if ($shcId > 0) {
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
        $drInstances = IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
        $drId = count($drInstances) > 0 ? $drInstances[0] : 0;
        
        if ($drId > 0 && function_exists('SDR_GetDevicesByType')) {
            $contacts = SDR_GetDevicesByType($drId, 'DevicesContactSensor');
            foreach ($contacts as $contact) {
                $varId = 0;
                if (!empty($contact['OpenClose_VarID'])) $varId = (int)$contact['OpenClose_VarID'];
                else if (!empty($contact['Status_VarID'])) $varId = (int)$contact['Status_VarID'];
                else if (!empty($contact['OnOff_VarID'])) $varId = (int)$contact['OnOff_VarID'];
                
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    $val = GetValue($varId);
                    // Wir nehmen an, dass true = Offen bedeutet. Falls es false ist, müssen wir es vielleicht invertieren, 
                    // aber Standard ist true = Alarm/Offen.
                    if ($val) {
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
            $shcInstances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
            if (count($shcInstances) > 0) {
                $shcId = $shcInstances[0];
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
