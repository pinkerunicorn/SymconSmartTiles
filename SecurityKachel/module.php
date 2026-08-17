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
                    if (!empty($device['ExcludeFromAlarm'])) continue;
                    
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

        // 3. SmartMonitorDevice registrieren
        $smdIds = IPS_GetInstanceListByModuleID('{4574D58D-2DC0-4E16-92DC-16D9CD27D014}');
        if (count($smdIds) > 0) {
            $smdId = $smdIds[0];
            foreach (['LowBatteryCount', 'OfflineDeviceCount', 'OrphanedVarCount'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $smdId);
                if ($vid) $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        // 4. SmartMonitorEvent registrieren
        $smeIds = IPS_GetInstanceListByModuleID('{72F8B3A1-C994-4E60-A54D-B591D8E72C42}');
        if (count($smeIds) > 0) {
            $smeId = $smeIds[0];
            $vid = @IPS_GetObjectIDByIdent('ActiveEventsCount', $smeId);
            if ($vid) $this->RegisterMessage($vid, VM_UPDATE);
        }

        // 5. SmartLog registrieren
        $logIds = IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
        if (count($logIds) > 0) {
            $logId = $logIds[0];
            $vid = @IPS_GetObjectIDByIdent('LastEntry', $logId);
            if ($vid) $this->RegisterMessage($vid, VM_UPDATE);
        }
    }

    public function UpdateData(): void
    {
        $payload = [
            'presenceMode' => 0, 
            'alarmLevel' => 0,   
            'openWindows' => [],
            'activeMotions' => [],
            'deviceIssuesCount' => 0,
            'activeEventsCount' => 0,
            'latestLogs' => []
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
                if (!empty($contact['ExcludeFromAlarm'])) continue;

                $varId = 0;
                if (!empty($contact['OpenClose_VarID'])) $varId = (int)$contact['OpenClose_VarID'];
                else if (!empty($contact['Status_VarID'])) $varId = (int)$contact['Status_VarID'];
                else if (!empty($contact['OnOff_VarID'])) $varId = (int)$contact['OnOff_VarID'];
                
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    $val = GetValue($varId);
                    $closedDef = strtolower(trim((string)($contact['ClosedValue'] ?? 'false')));
                    
                    $isClosed = false;
                    if (is_bool($val)) {
                        $target = ($closedDef === 'true' || $closedDef === '1' || $closedDef === 'wahr');
                        $isClosed = ($val === $target);
                    } elseif (is_int($val)) {
                        $isClosed = ($val === (int)$closedDef);
                    } elseif (is_float($val)) {
                        $isClosed = ($val === (float)$closedDef);
                    } elseif (is_string($val)) {
                        $isClosed = (strtolower(trim($val)) === $closedDef);
                    } else {
                        $isClosed = ((string)$val === $closedDef);
                    }

                    if (!$isClosed) {
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

        // 3. Fetch from SmartMonitorDevice
        $smdIds = IPS_GetInstanceListByModuleID('{4574D58D-2DC0-4E16-92DC-16D9CD27D014}');
        if (count($smdIds) > 0) {
            $smdId = $smdIds[0];
            $lowBatId = @IPS_GetObjectIDByIdent('LowBatteryCount', $smdId);
            $offlineId = @IPS_GetObjectIDByIdent('OfflineDeviceCount', $smdId);
            $orphanId = @IPS_GetObjectIDByIdent('OrphanedVarCount', $smdId);

            $issues = 0;
            if ($lowBatId) $issues += GetValue($lowBatId);
            if ($offlineId) $issues += GetValue($offlineId);
            if ($orphanId) $issues += GetValue($orphanId);
            $payload['deviceIssuesCount'] = $issues;
        }

        // 4. Fetch from SmartMonitorEvent
        $smeIds = IPS_GetInstanceListByModuleID('{72F8B3A1-C994-4E60-A54D-B591D8E72C42}');
        if (count($smeIds) > 0) {
            $smeId = $smeIds[0];
            $eventsId = @IPS_GetObjectIDByIdent('ActiveEventsCount', $smeId);
            if ($eventsId) {
                $payload['activeEventsCount'] = GetValue($eventsId);
            }
        }

        // 5. Fetch from SmartLog
        $logIds = IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
        if (count($logIds) > 0) {
            $logId = $logIds[0];
            if (function_exists('SLOG_GetLatestLogs')) {
                $logsJson = @SLOG_GetLatestLogs($logId, 3);
                if ($logsJson) {
                    $logs = json_decode($logsJson, true);
                    if (is_array($logs)) {
                        $payload['latestLogs'] = $logs;
                    }
                }
            }
        }

        $this->UpdateVisualizationValue(json_encode($payload));
        $this->DA_SetAvailable(true);
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

    public function GetConfigurationForm(): string
    {
        $jsonForm = file_get_contents(__DIR__ . '/form.json');
        $form = json_decode($jsonForm, true);

        // Populate DeviceRegistry options
        $drInstances = IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
        $drOptions = [['caption' => '(Bitte auswählen)', 'value' => 0]];
        foreach ($drInstances as $id) {
            $drOptions[] = ['caption' => IPS_GetName($id), 'value' => $id];
        }
        
        // Populate SmartController options
        $shcInstances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        $shcOptions = [['caption' => '(Bitte auswählen)', 'value' => 0]];
        foreach ($shcInstances as $id) {
            $shcOptions[] = ['caption' => IPS_GetName($id), 'value' => $id];
        }

        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$element) {
                if ($element['name'] === 'DeviceRegistryID') {
                    $element['options'] = $drOptions;
                }
                if ($element['name'] === 'SmartControllerID') {
                    $element['options'] = $shcOptions;
                }
            }
        }

        return json_encode($form);
    }
}
