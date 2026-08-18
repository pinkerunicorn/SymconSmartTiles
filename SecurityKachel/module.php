<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_RegistryAware.php';

class SecurityKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    use RegistryAware_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1); // Enable HTML-SDK Kachel-Visualisierung
        $this->RegisterPropertyInteger('TileType', 1); // Not used directly, just for compatibility
        $this->RegisterPropertyInteger('RegistryID', 0);
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
        $shcId = $this->DR_GetControllerID();
        if ($shcId > 0 && IPS_InstanceExists($shcId)) {
            $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
            if ($presenceId) $this->RegisterMessage($presenceId, VM_UPDATE);
            
            $alarmId = @IPS_GetObjectIDByIdent('AlarmLevel', $shcId);
            if ($alarmId) $this->RegisterMessage($alarmId, VM_UPDATE);
        }

        // 2. DeviceRegistry Sensoren registrieren
        $drId = $this->DR_GetRegistryID();
        if ($drId > 0 && IPS_InstanceExists($drId)) {
            if (function_exists('SDR_GetDevicesByType')) {
                $contacts = SDR_GetDevicesByType($drId, 'DevicesContactSensor');
                $motions = SDR_GetDevicesByType($drId, 'DevicesMotionSensor');
                $lights = SDR_GetDevicesByType($drId, 'DevicesLight');
                $dimmers = SDR_GetDevicesByType($drId, 'DevicesLightDimmer');
                $colors = SDR_GetDevicesByType($drId, 'DevicesLightColor');
                
                $allDevices = array_merge($contacts, $motions, $lights, $dimmers, $colors);
                foreach ($allDevices as $device) {
                    if (!($device['enabled'] ?? true)) continue;
                    if (!empty($device['ExcludeFromAlarm'])) continue;
                    
                    $varId = 0;
                    if (!empty($device['OpenClose_VarID'])) $varId = (int)$device['OpenClose_VarID'];
                    else if (!empty($device['Status_VarID'])) $varId = (int)$device['Status_VarID'];
                    else if (!empty($device['OnOff_VarID'])) $varId = (int)$device['OnOff_VarID'];
                    else if (!empty($device['Brightness_VarID'])) $varId = (int)$device['Brightness_VarID'];
                    
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
        $shcId = $this->DR_GetControllerID();
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
        $drId = $this->DR_GetRegistryID();
        if ($drId > 0 && IPS_InstanceExists($drId) && function_exists('SDR_GetDevicesByType')) {
            $contacts = SDR_GetDevicesByType($drId, 'DevicesContactSensor');
            foreach ($contacts as $contact) {
                if (!($contact['enabled'] ?? true)) continue;
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
                if (!($motion['enabled'] ?? true)) continue;
                $varId = 0;
                if (!empty($motion['Status_VarID'])) $varId = (int)$motion['Status_VarID'];
                else if (!empty($motion['OnOff_VarID'])) $varId = (int)$motion['OnOff_VarID'];
                
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    if (GetValue($varId)) {
                        $payload['activeMotions'][] = $motion['name'] ?? 'Unbekannter Melder';
                    }
                }
            }

            $lights = SDR_GetDevicesByType($drId, 'DevicesLight');
            $dimmers = SDR_GetDevicesByType($drId, 'DevicesLightDimmer');
            $colors = SDR_GetDevicesByType($drId, 'DevicesLightColor');
            $allLights = array_merge($lights, $dimmers, $colors);
            
            $activeLights = [];
            foreach ($allLights as $light) {
                if (!($light['enabled'] ?? true)) continue;
                
                $vid = 0;
                $isDimmer = ($light['Type'] === 'DevicesLightDimmer');
                $vid = $isDimmer ? (int)($light['Brightness_VarID'] ?? 0) : (int)($light['OnOff_VarID'] ?? $light['Status_VarID'] ?? 0);
                
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $val = GetValue($vid);
                    if ($val > 0 || $val === true) {
                        $room = trim((string)($light['room'] ?? ''));
                        $name = trim((string)($light['name'] ?? 'Licht'));
                        $activeLights[] = [
                            'id' => $vid,
                            'name' => ($room !== '' ? $room . ' ' : '') . $name
                        ];
                    }
                }
            }
            $payload['activeLights'] = $activeLights;
        }

        // 3. Fetch from SmartMonitorDevice / DeviceRegistry (for clean lists)
        $smdIds = IPS_GetInstanceListByModuleID('{4574D58D-2DC0-4E16-92DC-16D9CD27D014}');
        $payload['lowBatteries'] = [];
        $payload['offlineDevices'] = [];
        $payload['deviceIssuesCount'] = 0;
        
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
            
            // To get clean lists, we parse the VisualizationValue of SmartMonitorDevice
            $smdPayload = @json_decode(GetValue(@IPS_GetObjectIDByIdent('MonitoredListHTML', $smdId) ?: 0), true);
            // Wait, MonitoredListHTML is HTML. SmartMonitorDevice pushes a JSON payload via UpdateVisualizationValue!
            // Let's just do a quick scan ourselves from the registry, or use deviceSummaryText to extract.
            // But since SecurityKachel has the DR ID, we can just do the check here!
            $threshold = 15;
            $thresholdVid = @IPS_GetProperty($smdId, 'LowBatteryThreshold');
            if ($thresholdVid !== false) $threshold = $thresholdVid;
            
            if ($drId > 0 && IPS_InstanceExists($drId) && function_exists('SDR_GetDevices')) {
                $allDevices = SDR_GetDevices($drId);
                if (is_array($allDevices)) {
                    foreach ($allDevices as $dev) {
                        if (!($dev['enabled'] ?? true)) continue;
                        $devName = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '?');
                        
                        // Battery check
                        $batVid = (int)($dev['Battery_VarID'] ?? 0);
                        if ($batVid > 0 && IPS_VariableExists($batVid)) {
                            $val = GetValue($batVid);
                            $isLow = false;
                            if (is_bool($val) && $val === true) {
                                $isLow = true;
                            } elseif (is_numeric($val)) {
                                if ((float)$val < $threshold) {
                                    $isLow = true;
                                }
                            }
                            if ($isLow) $payload['lowBatteries'][] = $devName;
                        }
                        
                        // Reachability check
                        $reachVid = (int)($dev['Reachable_VarID'] ?? 0);
                        if ($reachVid > 0 && IPS_VariableExists($reachVid)) {
                            $val = GetValue($reachVid);
                            $ident = strtoupper(IPS_GetObject($reachVid)['ObjectIdent']);
                            $name = strtoupper(IPS_GetName($reachVid));
                            $formatted = strtolower(GetValueFormatted($reachVid));
                            $isOffline = false;
                            
                            if (strpos($formatted, 'offline') !== false || strpos($formatted, 'nicht erreichbar') !== false || strpos($formatted, 'unreach') !== false || strpos($formatted, 'fehler') !== false) {
                                $isOffline = true;
                            } elseif (is_bool($val)) {
                                $isPositiveLogic = (strpos($ident, 'AVAILABLE') !== false || strpos($ident, 'ONLINE') !== false || strpos($ident, 'CONNECTED') !== false || strpos($name, 'STATUS') !== false || strpos($ident, 'STATE') !== false || strpos($ident, 'STATUS') !== false);
                                $isNegativeLogic = (strpos($ident, 'UNREACH') !== false || strpos($ident, 'OFFLINE') !== false || strpos($ident, 'ERROR') !== false || strpos($ident, 'FAILURE') !== false);
                                
                                if ($isPositiveLogic) {
                                    $isOffline = ($val === false);
                                } elseif ($isNegativeLogic) {
                                    $isOffline = ($val === true);
                                } else {
                                    $isOffline = ($val === false);
                                }
                            } elseif (is_string($val) && strtolower($val) === 'offline') {
                                $isOffline = true;
                            }
                            if ($isOffline) $payload['offlineDevices'][] = $devName;
                        }
                    }
                }
            }
        }

        // 4. Fetch from SmartMonitorEvent
        $smeIds = IPS_GetInstanceListByModuleID('{72F8B3A1-C994-4E60-A54D-B591D8E72C42}');
        if (count($smeIds) > 0) {
            $smeId = $smeIds[0];
            $eventsId = @IPS_GetObjectIDByIdent('ActiveEventsCount', $smeId);
            if ($eventsId) {
                $payload['activeEventsCount'] = GetValue($eventsId);
            }
            
            $activeEventsList = [];
            foreach (IPS_GetChildrenIDs($smeId) as $childId) {
                $obj = IPS_GetObject($childId);
                if (str_starts_with($obj['ObjectIdent'], 'Event_')) {
                    if (GetValue($childId) === true) {
                        $activeEventsList[] = str_replace('🔔 ', '', IPS_GetName($childId));
                    }
                }
            }
            $payload['activeEventsList'] = $activeEventsList;
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
            $shcId = $this->DR_GetControllerID();
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
        if ($Ident === 'ToggleLight') {
            $vid = (int)$Value;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $varInfo = IPS_GetVariable($vid);
                if ($varInfo['VariableType'] == 1) { // Integer (Dimmer)
                    $current = GetValue($vid);
                    RequestAction($vid, $current > 0 ? 0 : 100);
                } else if ($varInfo['VariableType'] == 0) { // Boolean
                    RequestAction($vid, !GetValue($vid));
                }
            }
            return;
        }

        if ($Ident === 'TurnOffAllLights') {
            $drId = $this->DR_GetRegistryID();
            if ($drId > 0 && IPS_InstanceExists($drId) && function_exists('SDR_GetDevicesByType')) {
                $lights = SDR_GetDevicesByType($drId, 'DevicesLight');
                $dimmers = SDR_GetDevicesByType($drId, 'DevicesLightDimmer');
                $colors = SDR_GetDevicesByType($drId, 'DevicesLightColor');
                $allLights = array_merge($lights, $dimmers, $colors);
                
                foreach ($allLights as $light) {
                    if (!($light['enabled'] ?? true)) continue;
                    
                    $isDimmer = ($light['Type'] === 'DevicesLightDimmer');
                    $vid = $isDimmer ? (int)($light['Brightness_VarID'] ?? 0) : (int)($light['OnOff_VarID'] ?? $light['Status_VarID'] ?? 0);
                    
                    if ($vid > 0 && IPS_VariableExists($vid)) {
                        $val = GetValue($vid);
                        if ($val > 0 || $val === true) {
                            $varInfo = IPS_GetVariable($vid);
                            if ($varInfo['VariableType'] == 1) {
                                RequestAction($vid, 0);
                            } else if ($varInfo['VariableType'] == 0) {
                                RequestAction($vid, false);
                            }
                        }
                    }
                }
            }
            return;
        }
    }

}
