<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

/**
 * SecurityKachel
 * WebFront-Kachel fuer Sicherheits- und Systemuebersicht.
 * Datenquellen: SmartController, SmartNotifier, SmartInventory, SmartLog.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SecurityKachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        // Konfiguration
        $this->RegisterPropertyInteger('SmartControllerID', 0);
        $this->RegisterPropertyInteger('SmartNotifierID', 0);
        $this->RegisterPropertyInteger('RegistryID', 0);

        // Legacy (Migration – wird nicht mehr aktiv genutzt)
        $this->RegisterPropertyInteger('TileType', 1);
        

        $this->DA_RegisterAvailability(900);

        $this->RegisterVariableString('DeviceProblemsList', 'Geraete-Probleme', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Warning'], 10);
        $this->RegisterVariableString('ActiveAlarmsList', 'Aktive Alarme', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Bell'], 11);
        $this->RegisterVariableString('OpenContactsList', 'Offene Kontakte', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Window'], 12);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Alle alten Subscriptions entfernen
        foreach ($this->GetMessageList() as $senderID => $msgIDs) {
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
            $this->UpdateData();
        }
    }

    private function RegisterMessageForSources(): void
    {
        // 1. SmartController
        $shcId = $this->ReadPropertyInteger('SmartControllerID');
        if ($shcId > 0 && @IPS_InstanceExists($shcId)) {
            foreach (['PresenceMode', 'AlarmLevel'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $shcId);
                if ($vid) $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        // 2. SmartNotifier Counter
        $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
        if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
            foreach (['DeviceProblems', 'ActiveAlarmCount', 'OpenContactCount', 'MotionCount'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $notifierId);
                if ($vid) $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        // 3. SmartLog
        $logIds = IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
        if (!empty($logIds)) {
            $vid = @IPS_GetObjectIDByIdent('LastEntry', $logIds[0]);
            if ($vid) $this->RegisterMessage($vid, VM_UPDATE);
        }
    }

    public function UpdateData(): void
    {
        $payload = [
            'presenceMode'       => 0,
            'alarmLevel'         => 0,
            'openWindows'        => [],
            'activeMotions'      => [],
            'deviceProblems'     => [],
            'deviceProblemsCount'=> 0,
            'activeEventsCount'  => 0,
            'activeEventsList'   => [],
            'latestLogs'         => [],
        ];

        // 1. SmartController
        $shcId = $this->ReadPropertyInteger('SmartControllerID');
        if ($shcId > 0 && @IPS_InstanceExists($shcId)) {
            $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
            if ($presenceId) $payload['presenceMode'] = GetValue($presenceId);

            $alarmId = @IPS_GetObjectIDByIdent('AlarmLevel', $shcId);
            if ($alarmId) $payload['alarmLevel'] = GetValue($alarmId);
        }

        // 2. SmartNotifier Counter
        $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
        if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
            $devProbVid = @IPS_GetObjectIDByIdent('DeviceProblems',   $notifierId);
            $alarmVid   = @IPS_GetObjectIDByIdent('ActiveAlarmCount', $notifierId);
            $contactVid = @IPS_GetObjectIDByIdent('OpenContactCount', $notifierId);


            $devProbs    = ($devProbVid && @IPS_VariableExists($devProbVid)) ? (int)GetValue($devProbVid) : 0;
            $alarmCount  = ($alarmVid   && @IPS_VariableExists($alarmVid))   ? (int)GetValue($alarmVid)   : 0;
            $contactCount= ($contactVid && @IPS_VariableExists($contactVid)) ? (int)GetValue($contactVid) : 0;


            $payload['deviceProblemsCount'] = $devProbs;
            $payload['activeEventsCount']   = $alarmCount;

            // Konkrete Listen aus SmartInventory holen wenn konfiguriert
            $inventoryId = $this->ReadPropertyInteger('RegistryID');
            if ($inventoryId == 0) {
                $invIds = @IPS_GetInstanceListByModuleID('{8F4A2B1C-D3E5-4F6A-B7C8-9D0E1F2A3B4C}');
                if (is_array($invIds) && count($invIds) > 0) {
                    $inventoryId = $invIds[0];
                }
            }
            if ($inventoryId > 0 && @IPS_InstanceExists($inventoryId)) {
                if (!function_exists('SINV_GetByCategory')) {
                    IPS_LogMessage('SecurityKachel', 'Fehler: Funktion SINV_GetByCategory nicht gefunden!');
                    $payload['deviceProblems'][] = ['name' => 'System', 'health' => 'alarm', 'detail' => 'Modul-Fehler: SINV_GetByCategory fehlt'];
                }
                // Kontakte offen
                if ($contactCount > 0 && function_exists('SINV_GetByCategory')) {
                    $contactJson = @SINV_GetByCategory($inventoryId, 'contact');
                    $contacts    = is_string($contactJson) ? json_decode($contactJson, true) : [];
                    if (is_array($contacts)) {
                        foreach ($contacts as $c) {
                            $varID = $c['varID'] ?? 0;
                            if (!$varID || !@IPS_VariableExists($varID)) continue;
                            $val         = GetValue($varID);
                            $normalState = $c['normalState'] ?? null;
                            if (is_array($normalState) && isset($normalState['value'])) {
                                $normalState = $normalState['value'];
                            }
                            
                            // Check if boolean
                            if (is_bool($val) && $normalState !== null) {
                                $normalBool = filter_var($normalState, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                                if ($normalBool !== null) $normalState = $normalBool;
                            }
                            
                            $isOpen = ($normalState !== null) ? ($val != $normalState) : (bool)$val;
                            if ($isOpen) {
                                $name = ($c['instanceName'] ?? '') . ($c['room'] ? ' (' . $c['room'] . ')' : '');
                                $payload['openWindows'][] = trim($name);
                            }
                        }
                    }
                }

                // Geraete-Probleme (dedupliziert mit Root-Cause)
                $problemsJson = '[]';
                $notifierIds = @IPS_GetInstanceListByModuleID('{2512A0CA-5F11-40F0-9F3F-BD7AD1ACBB80}');
                if ($devProbs > 0 && count($notifierIds) > 0) {
                    if (function_exists('NOTIFY_GetProblems')) {
                        $problemsJson = @NOTIFY_GetProblems($notifierIds[0]);
                    } else {
                        IPS_LogMessage('SecurityKachel', 'Fehler: Funktion NOTIFY_GetProblems nicht gefunden!');
                        $payload['deviceProblems'][] = ['name' => 'System', 'health' => 'alarm', 'detail' => 'Modul-Fehler: NOTIFY_GetProblems fehlt'];
                    }
                }
                IPS_LogMessage('SecurityKachelDEBUG', 'Raw Problems JSON: ' . print_r($problemsJson, true));
                $problems     = is_string($problemsJson) ? json_decode($problemsJson, true) : [];
                    if (is_array($problems)) {
                        foreach ($problems as $p) {
                            $payload['deviceProblems'][] = [
                                'name'   => ($p['instanceName'] ?? '') . (($p['room'] ?? '') ? ' (' . $p['room'] . ')' : ''),
                                'health' => $p['health'] ?? 'unknown',
                                'detail' => $p['detail'] ?? '',
                            ];
                        }
                    }

                // Aktive Alarme
                $alarmsJson = '[]';
                $notifierIds = @IPS_GetInstanceListByModuleID('{2512A0CA-5F11-40F0-9F3F-BD7AD1ACBB80}');
                if ($alarmCount > 0 && count($notifierIds) > 0) {
                    if (function_exists('NOTIFY_GetActiveAlarms')) {
                        $alarmsJson = @NOTIFY_GetActiveAlarms($notifierIds[0]);
                    } else {
                        IPS_LogMessage('SecurityKachel', 'Fehler: Funktion NOTIFY_GetActiveAlarms nicht gefunden!');
                        $payload['activeEventsList'][] = 'System-Fehler: NOTIFY_GetActiveAlarms fehlt';
                    }
                }
                $alarms = is_string($alarmsJson) ? json_decode($alarmsJson, true) : [];
                    if (is_array($alarms)) {
                        foreach ($alarms as $a) {
                            $name = ($a['instanceName'] ?? '') . (($a['room'] ?? '') ? ' (' . $a['room'] . ')' : '');
                            $tag = $a['tag'] ?? '';
                            if ($tag) {
                                $parts = explode(':', $tag);
                                $shortTag = end($parts);
                                $name .= " [" . $shortTag . "]";
                            }
                            $payload['activeEventsList'][] = trim($name);
                        }
                    }

                // Wenn wir Alarme haben, prüfen wir auch Bewegungsmelder, da diese bei Abwesenheit Alarme auslösen!
                if ($alarmCount > 0 && ($payload['presenceMode'] ?? 0) > 0 && function_exists('SINV_GetByCategory')) {
                    $motionJson = @SINV_GetByCategory($inventoryId, 'motion');
                    $motions    = is_string($motionJson) ? json_decode($motionJson, true) : [];
                    if (is_array($motions)) {
                        foreach ($motions as $m) {
                            $varID = $m['varID'] ?? 0;
                            if (!$varID || !@IPS_VariableExists($varID)) continue;
                            $val         = GetValue($varID);
                            $normalState = $m['normalState'] ?? null;
                            $isActive    = ($normalState !== null) ? ($val != $normalState) : (bool)$val;
                            if ($isActive) {
                                $name = ($m['instanceName'] ?? '') . ($m['room'] ? ' (' . $m['room'] . ')' : '') . ' [Bewegung]';
                                $payload['activeEventsList'][] = trim($name);
                            }
                        }
                    }
                }
            }
        }

        // 3. SmartLog
        $logIds = IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
        if (!empty($logIds)) {
            if (function_exists('SLOG_GetLatestLogs')) {
                $logsJson = @SLOG_GetLatestLogs($logIds[0], 3);
            } else {
                IPS_LogMessage('SecurityKachel', 'Fehler: Funktion SLOG_GetLatestLogs nicht gefunden!');
                $payload['latestLogs'][] = ['t' => time(), 'l' => 'ERROR', 's' => 'System', 'm' => 'Modul-Fehler: SLOG_GetLatestLogs fehlt'];
                $logsJson = false;
            }
            if ($logsJson) {
                $logs = json_decode($logsJson, true);
                if (is_array($logs)) {
                    $payload['latestLogs'] = $logs;
                }
            }
        }

        $deviceProblemsStr = implode("\n", array_map(fn($p) => $p['name'] . ' (' . $p['detail'] . ')', $payload['deviceProblems'] ?? []));
        $this->SetValue('DeviceProblemsList', $deviceProblemsStr ?: 'Keine Probleme');

        $alarmsStr = implode("\n", $payload['activeEventsList'] ?? []);
        $this->SetValue('ActiveAlarmsList', $alarmsStr ?: 'Keine aktiven Alarme');

        $contactsStr = implode("\n", $payload['openWindows'] ?? []);
        $this->SetValue('OpenContactsList', $contactsStr ?: 'Alle geschlossen');

        $this->UpdateVisualizationValue(json_encode($payload));
        $this->DA_SetAvailable(true);
    }

    public function GetVisualizationTile(): string
    {
        return file_get_contents(__DIR__ . '/module.html');
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Init') {
            $this->UpdateData();
            return;
        }

        if ($Ident === 'SetPresenceMode') {
            $shcId = $this->ReadPropertyInteger('SmartControllerID');
            if ($shcId > 0 && @IPS_InstanceExists($shcId)) {
                $presenceId = @IPS_GetObjectIDByIdent('PresenceMode', $shcId);
                if ($presenceId) {
                    RequestAction($presenceId, (int)$Value);
                }
            }
            return;
        }

        if ($Ident === 'ToggleLight') {
            $vid = (int)$Value;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $varInfo = IPS_GetVariable($vid);
                if ($varInfo['VariableType'] == 1) {
                    RequestAction($vid, GetValue($vid) > 0 ? 0 : 100);
                } elseif ($varInfo['VariableType'] == 0) {
                    RequestAction($vid, !GetValue($vid));
                }
            }
            return;
        }

        if ($Ident === 'TurnOffAllLights') {
            $invId = (int)$this->ReadPropertyInteger('RegistryID');
            if ($invId > 0 && @IPS_InstanceExists($invId) && function_exists('SINV_GetByCategory')) {
                $types = ['actor:switch', 'actor:dimmer', 'actor:color'];
                foreach ($types as $t) {
                    $devices = json_decode(@SINV_GetByCategory($invId, $t), true) ?: [];
                    foreach ($devices as $dev) {
                        $vid = (int)($dev['varID'] ?? 0);
                        if ($vid > 0 && @IPS_VariableExists($vid)) {
                            $val = GetValue($vid);
                            if ($val > 0 || $val === true) {
                                RequestAction($vid, ($t === 'actor:dimmer') ? 0 : false);
                            }
                        }
                    }
                }
            }
            return;
        }
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Datenquellen',
                    'expanded'=> true,
                    'items'   => [
                        ['type' => 'SelectInstance', 'name' => 'SmartControllerID', 'caption' => 'SmartController'],
                        ['type' => 'SelectInstance', 'name' => 'SmartNotifierID',   'caption' => 'SmartNotifier'],
                        ['type' => 'SelectInstance', 'name' => 'RegistryID',  'caption' => 'SmartInventory (Geraete-Status)'],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Daten jetzt aktualisieren', 'onClick' => 'SECKACHEL_UpdateData($id); echo "Aktualisiert.";'],
            ],
        ]);
    }
}
