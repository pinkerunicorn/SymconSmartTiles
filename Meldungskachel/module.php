<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class Meldungskachel extends IPSModuleStrict
{
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('SmartNotifierID', 0);
        $this->RegisterPropertyInteger('RegistryID', 0);

        // Timer fuer regelmaessige Updates
        $this->RegisterTimer('UpdateTimer', 60000, 'MELDKACHEL_UpdateData($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterMessageForSources();
        $this->UpdateData();
    }

    private function RegisterMessageForSources(): void
    {
        // Alle alten Messages loeschen
        $messages = $this->GetMessageList();
        foreach ($messages as $sender => $msgs) {
            foreach ($msgs as $msg) {
                $this->UnregisterMessage($sender, $msg);
            }
        }

        // 1. SmartNotifier
        $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
        if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
            foreach (['DeviceProblems', 'ActiveAlarmCount', 'OpenContactCount'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $notifierId);
                if ($vid > 0) $this->RegisterMessage($vid, VM_UPDATE);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateData();
        }
    }

    public function UpdateData(): void
    {
        $payload = [
            'openWindows'        => [],
            'deviceProblemsCount'=> 0,
            'deviceProblems'     => [],
            'activeEventsCount'  => 0,
            'activeEventsList'   => [],
            'latestLogs'         => [],
        ];

        try {
            $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
            $inventoryId = $this->ReadPropertyInteger('RegistryID');

            $devProbs = 0;
            $alarmCount = 0;
            $openCount = 0;

            if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                $devId = @IPS_GetObjectIDByIdent('DeviceProblems', $notifierId);
                if ($devId) $devProbs = GetValue($devId);

                $alId = @IPS_GetObjectIDByIdent('ActiveAlarmCount', $notifierId);
                if ($alId) $alarmCount = GetValue($alId);
                
                $opId = @IPS_GetObjectIDByIdent('OpenContactCount', $notifierId);
                if ($opId) $openCount = GetValue($opId);
            }

            $payload['deviceProblemsCount'] = $devProbs;
            $payload['activeEventsCount'] = $alarmCount;

            // Offene Kontakte aus Inventory
            if ($inventoryId > 0 && @IPS_InstanceExists($inventoryId)) {
                if (!function_exists('SINV_GetByCategory')) {
                    IPS_LogMessage('Meldungskachel', 'Fehler: Funktion SINV_GetByCategory nicht gefunden!');
                    $payload['openWindows'][] = 'Modul-Fehler: SINV_GetByCategory fehlt';
                } else {
                    $winJson = @SINV_GetByCategory($inventoryId, 'contact');
                    $wins = is_string($winJson) ? json_decode($winJson, true) : [];
                    if (is_array($wins)) {
                        foreach ($wins as $w) {
                            $name = ($w['instanceName'] ?? '') . (($w['room'] ?? '') ? ' (' . $w['room'] . ')' : '');
                            if ($w['val'] ?? false) {
                                $payload['openWindows'][] = trim($name);
                            }
                        }
                    }
                }
            }

            // Geraete Probleme aus Notifier
            $problemsJson = '[]';
            if ($devProbs > 0 && $notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                if (function_exists('NOTIFY_GetProblems')) {
                    $problemsJson = @NOTIFY_GetProblems($notifierId);
                } else {
                    IPS_LogMessage('Meldungskachel', 'Fehler: Funktion NOTIFY_GetProblems nicht gefunden!');
                    $payload['deviceProblems'][] = ['name' => 'System', 'health' => 'alarm', 'detail' => 'Modul-Fehler: NOTIFY_GetProblems fehlt'];
                }
            }
            $problems = is_string($problemsJson) ? json_decode($problemsJson, true) : [];
            if (is_array($problems)) {
                foreach ($problems as $p) {
                    $name = ($p['instanceName'] ?? '') . (($p['room'] ?? '') ? ' (' . $p['room'] . ')' : '');
                    $payload['deviceProblems'][] = [
                        'name' => trim($name),
                        'health' => $p['alarmName'] ?? 'stale',
                        'detail' => $p['alarmName'] ?? ''
                    ];
                }
            }

            // Aktive Alarme aus Notifier
            $alarmsJson = '[]';
            if ($alarmCount > 0 && $notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                if (function_exists('NOTIFY_GetActiveAlarms')) {
                    $alarmsJson = @NOTIFY_GetActiveAlarms($notifierId);
                } else {
                    IPS_LogMessage('Meldungskachel', 'Fehler: Funktion NOTIFY_GetActiveAlarms nicht gefunden!');
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

            // System Logs
            $logIds = @IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
            if (count($logIds) > 0) {
                if (!function_exists('SLOG_GetLatestLogs')) {
                    IPS_LogMessage('Meldungskachel', 'Fehler: Funktion SLOG_GetLatestLogs nicht gefunden!');
                    $payload['latestLogs'][] = ['t' => time(), 'l' => 'ERROR', 's' => 'System', 'm' => 'Modul-Fehler: SLOG_GetLatestLogs fehlt'];
                } else {
                    $logsJson = @SLOG_GetLatestLogs($logIds[0], 3);
                    $logs = is_string($logsJson) ? json_decode($logsJson, true) : [];
                    if (is_array($logs)) {
                        $payload['latestLogs'] = $logs;
                    }
                }
            }

            $this->UpdateFormField('Visualization', 'payload', json_encode($payload));
            $this->SendDebug('UpdateData', json_encode($payload), 0);

        } catch (Throwable $e) {
            $this->SendDebug('UpdateDataError', $e->getMessage(), 0);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        // Keine Aktionen mehr benoetigt
    }
}