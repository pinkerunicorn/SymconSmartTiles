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
        $this->RegisterPropertyInteger('SmartInventoryID', 0);

        // Legacy (Migration – wird nicht mehr aktiv genutzt)
        $this->RegisterPropertyInteger('TileType', 1);
        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->RegisterPropertyInteger('DeviceRegistryID', 0);

        $this->DA_RegisterAvailability(900);
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
            $motionVid  = @IPS_GetObjectIDByIdent('MotionCount',      $notifierId);

            $devProbs    = ($devProbVid && @IPS_VariableExists($devProbVid)) ? (int)GetValue($devProbVid) : 0;
            $alarmCount  = ($alarmVid   && @IPS_VariableExists($alarmVid))   ? (int)GetValue($alarmVid)   : 0;
            $contactCount= ($contactVid && @IPS_VariableExists($contactVid)) ? (int)GetValue($contactVid) : 0;
            $motionCount = ($motionVid  && @IPS_VariableExists($motionVid))  ? (int)GetValue($motionVid)  : 0;

            $payload['deviceProblemsCount'] = $devProbs;
            $payload['activeEventsCount']   = $alarmCount;

            // Konkrete Listen aus SmartInventory holen wenn konfiguriert
            $inventoryId = $this->ReadPropertyInteger('SmartInventoryID');
            if ($inventoryId > 0 && @IPS_InstanceExists($inventoryId) && function_exists('SINV_GetByCategory')) {
                // Kontakte offen
                if ($contactCount > 0) {
                    $contactJson = @SINV_GetByCategory($inventoryId, 'contact');
                    $contacts    = is_string($contactJson) ? json_decode($contactJson, true) : [];
                    if (is_array($contacts)) {
                        foreach ($contacts as $c) {
                            $varID = $c['varID'] ?? 0;
                            if (!$varID || !@IPS_VariableExists($varID)) continue;
                            $val         = GetValue($varID);
                            $normalState = $c['normalState'] ?? null;
                            $isOpen      = ($normalState !== null) ? ($val != $normalState) : (bool)$val;
                            if ($isOpen) {
                                $name = ($c['instanceName'] ?? '') . ($c['room'] ? ' (' . $c['room'] . ')' : '');
                                $payload['openWindows'][] = trim($name);
                            }
                        }
                    }
                }

                // Geraete-Probleme (dedupliziert mit Root-Cause)
                if ($devProbs > 0 && function_exists('SINV_GetProblems')) {
                    $problemsJson = @SINV_GetProblems($inventoryId);
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
                }

                // Aktive Bewegungsmelder
                if ($motionCount > 0) {
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
                                $name = ($m['instanceName'] ?? '') . ($m['room'] ? ' (' . $m['room'] . ')' : '');
                                $payload['activeMotions'][] = trim($name);
                            }
                        }
                    }
                }
            }
        }

        // 3. SmartLog
        $logIds = IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
        if (!empty($logIds) && function_exists('SLOG_GetLatestLogs')) {
            $logsJson = @SLOG_GetLatestLogs($logIds[0], 3);
            if ($logsJson) {
                $logs = json_decode($logsJson, true);
                if (is_array($logs)) {
                    $payload['latestLogs'] = $logs;
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
            // Lichter sind nicht in SmartInventory – DeviceRegistry als Fallback
            $drId = (int)$this->ReadPropertyInteger('DeviceRegistryID');
            if ($drId > 0 && @IPS_InstanceExists($drId) && function_exists('SDR_GetDevicesByType')) {
                $allLights = array_merge(
                    (array)@SDR_GetDevicesByType($drId, 'DevicesLight'),
                    (array)@SDR_GetDevicesByType($drId, 'DevicesLightDimmer'),
                    (array)@SDR_GetDevicesByType($drId, 'DevicesLightColor')
                );
                foreach ($allLights as $light) {
                    if (!($light['enabled'] ?? true)) continue;
                    $isDimmer = ($light['Type'] === 'DevicesLightDimmer');
                    $vid = $isDimmer
                        ? (int)($light['Brightness_VarID'] ?? 0)
                        : (int)($light['OnOff_VarID'] ?? $light['Status_VarID'] ?? 0);
                    if ($vid > 0 && IPS_VariableExists($vid)) {
                        $val = GetValue($vid);
                        if ($val > 0 || $val === true) {
                            $varInfo = IPS_GetVariable($vid);
                            if ($varInfo['VariableType'] == 1) {
                                RequestAction($vid, 0);
                            } elseif ($varInfo['VariableType'] == 0) {
                                RequestAction($vid, false);
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
                        ['type' => 'SelectInstance', 'name' => 'SmartControllerID', 'caption' => 'SmartController Instanz'],
                        ['type' => 'SelectInstance', 'name' => 'SmartNotifierID',   'caption' => 'SmartNotifier Instanz (Monitoring-Daten)'],
                        ['type' => 'SelectInstance', 'name' => 'SmartInventoryID',  'caption' => 'SmartInventory Instanz (Geraete-Listen)'],
                        ['type' => 'Label', 'caption' => 'Optional: DeviceRegistry fuer "Alle Lichter aus"'],
                        ['type' => 'SelectInstance', 'name' => 'DeviceRegistryID',  'caption' => 'DeviceRegistry (Lichter)'],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Daten jetzt aktualisieren', 'onClick' => 'SECKACHEL_UpdateData($id); echo "Aktualisiert.";'],
            ],
        ]);
    }
}
