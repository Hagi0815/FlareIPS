<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FlareCurve.php';

/**
 * CircadianLight – FLARE-ähnliche Tageslichtsteuerung für eine Zone (Raum).
 *
 * - Vier Phasen (Morgen/Tag/Abend/Nacht), Abend folgt dem Sonnenuntergang (mit Grenzen)
 * - Weiche Übergänge vor jedem Phasenbeginn
 * - Abgleich aller Leuchten im Intervall, Totband gegen unnötige Befehle
 * - Override-Schutz: manuell geänderte Leuchten werden bis "Raum dunkel" nicht mehr angefasst
 * - Optional Bewegung/Präsenz: einschalten, Ausschaltverzögerung, Nachtlicht
 * - Wandschalter: Leuchte, die wieder erreichbar wird oder extern eingeschaltet wird,
 *   bekommt sofort die aktuellen Phasenwerte
 */
class CircadianLight extends IPSModule
{
    private const ECHO_WINDOW = 15;   // Sekunden, in denen Rückmeldungen eigener Befehle ignoriert werden
    private const BRI_TOLERANCE = 4.0; // % Abweichung, ab der eine Helligkeitsänderung als manuell gilt
    private const CT_TOLERANCE = 200;  // K Abweichung, ab der eine Farbtemperaturänderung als manuell gilt

    public function Create()
    {
        parent::Create();

        // Leuchten & Sensoren
        $this->RegisterPropertyString('Lights', '[]');
        $this->RegisterPropertyString('MotionSensors', '[]');
        $this->RegisterPropertyInteger('OffDelay', 300);
        $this->RegisterPropertyInteger('IdleBrightness', 0);
        $this->RegisterPropertyBoolean('IdleNightOnly', true);

        // Phasen: Helligkeit %, Farbtemperatur K, Übergang (Minuten vor Phasenbeginn), Szene-Skript
        $defaults = [
            'Morning' => [100, 5000, 30],
            'Day'     => [100, 4500, 60],
            'Evening' => [65, 2700, 90],
            'Night'   => [12, 2200, 60],
        ];
        foreach ($defaults as $phase => [$bri, $ct, $fade]) {
            $this->RegisterPropertyInteger($phase . 'Brightness', $bri);
            $this->RegisterPropertyInteger($phase . 'ColorTemp', $ct);
            $this->RegisterPropertyInteger($phase . 'Transition', $fade);
            $this->RegisterPropertyInteger($phase . 'Scene', 0);
        }
        $this->RegisterPropertyBoolean('DayDrift', true);
        $this->RegisterPropertyInteger('SunsetOffset', 0);

        // Standort
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyInteger('SunsetVariable', 0);

        // Erweitert
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('BrightnessDeadband', 2);
        $this->RegisterPropertyInteger('ColorTempDeadband', 100);
        $this->RegisterPropertyInteger('ColorTempUnit', 0); // 0 = automatisch, 1 = Kelvin, 2 = Mired
        $this->RegisterPropertyInteger('SendDelay', 100);

        $this->RegisterAttributeString('LightState', '{}');
        $this->RegisterAttributeBoolean('ManualOff', false);
        $this->RegisterAttributeBoolean('FirstRun', true);

        $this->RegisterTimer('UpdateTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Update\', 0);');
        $this->RegisterTimer('OffTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'RoomEmpty\', 0);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($senderID !== 0) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }

        $this->CreateProfiles();

        $this->MaintainVariable('Active', 'Automatik aktiv', VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->EnableAction('Active');
        $this->MaintainVariable('Occupied', 'Raum belegt', VARIABLETYPE_BOOLEAN, '~Presence', 2, true);
        $this->EnableAction('Occupied');
        $this->MaintainVariable('Phase', 'Phase', VARIABLETYPE_INTEGER, 'FLARE.Phase', 3, true);
        $this->MaintainVariable('TargetBrightness', 'Ziel-Helligkeit', VARIABLETYPE_INTEGER, '~Intensity.100', 4, true);
        $this->MaintainVariable('TargetColorTemp', 'Ziel-Farbtemperatur', VARIABLETYPE_INTEGER, 'FLARE.Kelvin', 5, true);
        $this->MaintainVariable('Overrides', 'Manuell übersteuert', VARIABLETYPE_INTEGER, 'FLARE.Count', 6, true);
        $this->MaintainVariable('MorningStart', 'Beginn Morgen', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 10, true);
        $this->MaintainVariable('DayStart', 'Beginn Tag', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 11, true);
        $this->MaintainVariable('EveningEarliest', 'Abend frühestens', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 12, true);
        $this->MaintainVariable('EveningLatest', 'Abend spätestens', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 13, true);
        $this->MaintainVariable('NightStart', 'Beginn Nacht', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 14, true);
        $this->MaintainVariable('EveningStart', 'Beginn Abend (heute)', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 15, true);
        foreach (['MorningStart', 'DayStart', 'EveningEarliest', 'EveningLatest', 'NightStart'] as $ident) {
            $this->EnableAction($ident);
        }

        // Standardzeiten nur beim ersten Mal setzen – danach gehören sie dir (WebFront, Ereignisse, Skripte)
        $defaults = ['MorningStart' => '06:30', 'DayStart' => '09:00', 'EveningEarliest' => '17:00', 'EveningLatest' => '21:00', 'NightStart' => '22:30'];
        foreach ($defaults as $ident => $time) {
            if ($this->GetValue($ident) === 0) {
                $this->SetValue($ident, strtotime('today ' . $time));
            }
        }
        if ($this->ReadAttributeBoolean('FirstRun')) {
            // Neue Instanz: Automatik standardmäßig an
            $this->SetValue('Active', true);
            $this->WriteAttributeBoolean('FirstRun', false);
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Nachrichten der Leuchten & Sensoren abonnieren
        foreach ($this->GetLights() as $light) {
            foreach (['Status', 'Brightness', 'ColorTemp', 'Reachable'] as $role) {
                if ($light[$role] > 0 && IPS_VariableExists($light[$role])) {
                    $this->RegisterMessage($light[$role], VM_UPDATE);
                    $this->RegisterReference($light[$role]);
                }
            }
        }
        foreach ($this->GetMotionSensors() as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
            $this->RegisterReference($id);
        }
        $sunsetVar = $this->ReadPropertyInteger('SunsetVariable');
        if ($sunsetVar > 0 && IPS_VariableExists($sunsetVar)) {
            $this->RegisterReference($sunsetVar);
        }

        // Status prüfen
        if (count($this->GetLights()) === 0) {
            $this->SetStatus(104);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }
        $this->SetStatus(102);

        // Zonen zeitversetzt starten, damit nicht alle gleichzeitig senden
        $this->SetTimerInterval('UpdateTimer', (3 + $this->InstanceID % 25) * 1000);
        $this->UpdateTargetVariables();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE || !$Data[1]) {
            return; // nur echte Wertänderungen
        }

        if (in_array($SenderID, $this->GetMotionSensors(), true)) {
            $this->HandleMotion();
            return;
        }

        foreach ($this->GetLights() as $light) {
            foreach (['Status', 'Brightness', 'ColorTemp', 'Reachable'] as $role) {
                if ($light[$role] === $SenderID) {
                    $this->HandleLightChange($light, $role, $Data[0], $Data[2]);
                    return;
                }
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Update':
                $this->Update();
                break;
            case 'RoomEmpty':
                $this->RoomEmpty();
                break;
            case 'Active':
                $this->SetValue('Active', (bool) $Value);
                if ($Value) {
                    $this->ClearOverrides();
                    $this->Update(true);
                }
                break;
            case 'Occupied':
                $this->SetOccupied((bool) $Value);
                break;
            case 'MorningStart':
            case 'DayStart':
            case 'EveningEarliest':
            case 'EveningLatest':
            case 'NightStart':
                $this->SetValue($Ident, (int) $Value);
                $this->Update();
                break;
            case 'Preview':
                $this->UpdateFormField('Preview', 'values', json_encode($this->BuildPreview()));
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $this->InjectValues($form['actions'], 'Preview', $this->BuildPreview());
        return json_encode($form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (FLARE_*)
    // ------------------------------------------------------------------

    /** Abgleich aller Leuchten der Zone mit der Kurve. */
    public function Update(bool $force = false): void
    {
        $this->SetTimerInterval('UpdateTimer', max(10, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        $target = $this->UpdateTargetVariables();

        if (!$this->GetValue('Active')) {
            return;
        }
        if (!$this->Lock()) {
            return;
        }
        try {
            $desired = $this->Desired($target);
            if ($desired === null) {
                return;
            }
            $state = $this->GetState();
            $first = true;
            foreach ($this->GetLights() as $light) {
                $key = (string) $light['Status'];
                if (!empty($state[$key]['override'])) {
                    continue;
                }
                if (!$first) {
                    IPS_Sleep($this->ReadPropertyInteger('SendDelay'));
                }
                if ($this->ApplyLight($light, $desired, $force, $state)) {
                    $first = false;
                }
            }
            $this->SetState($state);
        } finally {
            $this->Unlock();
        }
    }

    /** Raum als belegt / leer setzen (z.B. von einem Taster aus). */
    public function SetOccupied(bool $occupied): void
    {
        if ($occupied) {
            $this->SetTimerInterval('OffTimer', 0);
            if ($this->GetValue('Occupied')) {
                return;
            }
            $this->SetValue('Occupied', true);
            $this->WriteAttributeBoolean('ManualOff', false);
            if (!$this->GetValue('Active')) {
                return;
            }
            $phase = (int) $this->UpdateTargetVariables()['phase'];
            $scene = $this->ReadPropertyInteger(['Morning', 'Day', 'Evening', 'Night'][$phase] . 'Scene');
            if ($scene > 0 && IPS_ScriptExists($scene)) {
                // Szene übernimmt den Raum – Leuchten bis "Raum leer" nicht anfassen
                $this->SendDebug('Scene', 'Starte Szene-Skript ' . $scene, 0);
                $this->SetAllOverrides(true);
                IPS_RunScriptEx($scene, ['ZONE' => $this->InstanceID, 'PHASE' => $phase]);
                return;
            }
            $this->Update(true);
        } else {
            $this->RoomEmpty();
        }
    }

    /** Raum ist leer: ausschalten bzw. Nachtlicht, Overrides zurücksetzen. */
    public function RoomEmpty(): void
    {
        $this->SetTimerInterval('OffTimer', 0);
        $this->SetValue('Occupied', false);
        $this->WriteAttributeBoolean('ManualOff', false);
        $this->ClearOverrides();
        if (!$this->GetValue('Active') || count($this->GetMotionSensors()) === 0) {
            return;
        }
        $target = $this->UpdateTargetVariables();
        $idle = $this->IdleWanted($target);
        $desired = $idle ? ['on' => true, 'bri' => (float) $this->ReadPropertyInteger('IdleBrightness'), 'ct' => (float) $target['ct']]
                         : ['on' => false, 'bri' => 0.0, 'ct' => (float) $target['ct']];
        if (!$this->Lock()) {
            return;
        }
        try {
            $state = $this->GetState();
            foreach ($this->GetLights() as $light) {
                $this->ApplyLight($light, $desired, true, $state);
                IPS_Sleep($this->ReadPropertyInteger('SendDelay'));
            }
            $this->SetState($state);
        } finally {
            $this->Unlock();
        }
    }

    /** Alle manuellen Übersteuerungen zurücksetzen. */
    public function ClearOverrides(): void
    {
        $this->SetAllOverrides(false);
    }

    /** Aktuelle Zielwerte als JSON (Phase, Helligkeit, Farbtemperatur) – z.B. für eigene Skripte. */
    public function GetTarget(): string
    {
        $t = $this->CalculateTarget(time());
        $t['phaseName'] = FlareCurve::NAMES[$t['phase']];
        return json_encode($t);
    }

    // ------------------------------------------------------------------
    // Kernlogik
    // ------------------------------------------------------------------

    /**
     * Was die Leuchten jetzt tun sollen.
     * on = true/false: schalten; on = null: nur bereits eingeschaltete Leuchten nachführen.
     */
    private function Desired(array $target): ?array
    {
        $bri = (float) $target['bri'];
        $ct = (float) $target['ct'];

        if (count($this->GetMotionSensors()) === 0) {
            // Ohne Sensor: nur eingeschaltete Leuchten der Kurve nachführen
            return ['on' => null, 'bri' => $bri, 'ct' => $ct];
        }
        if ($this->ReadAttributeBoolean('ManualOff')) {
            return null; // bei Anwesenheit von Hand ausgeschaltet
        }
        if ($this->GetValue('Occupied')) {
            return ['on' => $bri >= 1, 'bri' => $bri, 'ct' => $ct];
        }
        if ($this->IdleWanted($target)) {
            return ['on' => true, 'bri' => (float) $this->ReadPropertyInteger('IdleBrightness'), 'ct' => $ct];
        }
        return ['on' => null, 'bri' => $bri, 'ct' => $ct];
    }

    private function IdleWanted(array $target): bool
    {
        return $this->ReadPropertyInteger('IdleBrightness') > 0
            && (!$this->ReadPropertyBoolean('IdleNightOnly') || (int) $target['phase'] === FlareCurve::NIGHT);
    }

    /**
     * Eine Leuchte auf die gewünschten Werte bringen. Gibt true zurück, wenn etwas gesendet wurde.
     * Die Sollwerte werden VOR dem Senden gespeichert, damit schnelle Rückmeldungen der Leuchte
     * nicht als manuelle Änderung erkannt werden.
     */
    private function ApplyLight(array $light, array $desired, bool $force, array &$state): bool
    {
        $statusID = $light['Status'];
        if (!IPS_VariableExists($statusID)) {
            return false;
        }
        $key = (string) $statusID;
        $st = $state[$key] ?? [];
        $isOn = $this->Truthy(GetValue($statusID));
        $sends = [];

        if ($desired['on'] === false) {
            if ($isOn) {
                $sends[] = [$statusID, $this->OnValue($statusID, false)];
                $st['sentOn'] = false;
            }
        } elseif ($desired['on'] === true || $isOn) {
            $bri = max(1.0, min(100.0, $desired['bri'] * $light['Factor'] / 100));
            $wasOff = !$isOn;
            if ($wasOff) {
                $sends[] = [$statusID, $this->OnValue($statusID, true)];
                $st['sentOn'] = true;
            }

            $briID = $light['Brightness'];
            if ($briID > 0 && IPS_VariableExists($briID)) {
                $current = $this->ReadPercent($briID);
                if ($wasOff || $force || abs($current - $bri) > $this->ReadPropertyInteger('BrightnessDeadband')) {
                    $sends[] = [$briID, $this->PercentToVar($briID, $bri)];
                    $st['sentBri'] = $bri;
                } elseif (!isset($st['sentBri'])) {
                    $st['sentBri'] = $current;
                }
            }

            $ctID = $light['ColorTemp'];
            if ($ctID > 0 && IPS_VariableExists($ctID)) {
                $current = $this->ReadKelvin($ctID);
                $wanted = $this->ClampKelvin($ctID, $desired['ct']);
                if ($wasOff || $force || abs($current - $wanted) > $this->ReadPropertyInteger('ColorTempDeadband')) {
                    $sends[] = [$ctID, $this->KelvinToVar($ctID, $wanted)];
                    $st['sentCt'] = $wanted;
                } elseif (!isset($st['sentCt'])) {
                    $st['sentCt'] = $current;
                }
            }
        }

        if (count($sends) === 0) {
            return false;
        }
        $st['sentAt'] = time();
        $state[$key] = $st;
        $this->SetState($state);
        foreach ($sends as [$id, $value]) {
            $this->Send($id, $value);
        }
        return true;
    }

    private function HandleMotion(): void
    {
        $occupied = false;
        foreach ($this->GetMotionSensors() as $id) {
            if ($this->Truthy(GetValue($id))) {
                $occupied = true;
                break;
            }
        }
        if ($occupied) {
            $this->SetOccupied(true);
            return;
        }
        $delay = $this->ReadPropertyInteger('OffDelay');
        if ($delay > 0) {
            $this->SetTimerInterval('OffTimer', $delay * 1000);
        } else {
            $this->RoomEmpty();
        }
    }

    private function HandleLightChange(array $light, string $role, $newValue, $oldValue): void
    {
        if (!$this->Lock()) {
            return;
        }
        try {
            $state = $this->GetState();
            $key = (string) $light['Status'];
            $st = $state[$key] ?? [];
            $recent = isset($st['sentAt']) && (time() - $st['sentAt']) <= self::ECHO_WINDOW;
            $active = (bool) $this->GetValue('Active');
            $statusOn = IPS_VariableExists($light['Status']) && $this->Truthy(GetValue($light['Status']));

            switch ($role) {
                case 'Status':
                    $on = $this->Truthy($newValue);
                    if ($recent && isset($st['sentOn']) && $st['sentOn'] === $on) {
                        break; // Rückmeldung eines eigenen Befehls
                    }
                    if ($on) {
                        // Extern eingeschaltet (App, Wandschalter, Strom wieder da): auf Kurve setzen
                        $this->SendDebug('Light', $key . ' extern eingeschaltet → Kurve', 0);
                        $st['override'] = false;
                        $state[$key] = $st;
                        $this->WriteAttributeBoolean('ManualOff', false);
                        if ($active) {
                            $this->ApplyExternalOn($light, $state);
                        }
                    } else {
                        if ($this->AllLightsOff()) {
                            // Raum dunkel → Overrides zurücksetzen
                            foreach ($state as &$s) {
                                $s['override'] = false;
                            }
                            unset($s);
                            if (count($this->GetMotionSensors()) > 0 && $this->GetValue('Occupied')) {
                                $this->WriteAttributeBoolean('ManualOff', true);
                                $this->SendDebug('Light', 'Alle Leuchten von Hand aus → bleibt aus bis Raum leer', 0);
                            }
                        } else {
                            $st['override'] = true;
                            $state[$key] = $st;
                            $this->SendDebug('Light', $key . ' einzeln von Hand aus → Override', 0);
                        }
                    }
                    break;

                case 'Brightness':
                    if (!$statusOn || !isset($st['sentBri'])) {
                        break;
                    }
                    $now = $this->ReadPercent($light['Brightness']);
                    if (abs($now - $st['sentBri']) > max(self::BRI_TOLERANCE, $this->ReadPropertyInteger('BrightnessDeadband') + 1)) {
                        $st['override'] = true;
                        $state[$key] = $st;
                        $this->SendDebug('Light', sprintf('%s Helligkeit von Hand %.0f%% (gesendet %.0f%%) → Override', $key, $now, $st['sentBri']), 0);
                    }
                    break;

                case 'ColorTemp':
                    if (!$statusOn || !isset($st['sentCt'])) {
                        break;
                    }
                    $now = $this->ReadKelvin($light['ColorTemp']);
                    if (abs($now - $st['sentCt']) > max(self::CT_TOLERANCE, $this->ReadPropertyInteger('ColorTempDeadband') + 50)) {
                        $st['override'] = true;
                        $state[$key] = $st;
                        $this->SendDebug('Light', sprintf('%s Farbtemperatur von Hand %dK (gesendet %dK) → Override', $key, $now, $st['sentCt']), 0);
                    }
                    break;

                case 'Reachable':
                    if ($this->Truthy($newValue) && !$this->Truthy($oldValue)) {
                        $this->SendDebug('Light', $key . ' wieder erreichbar → Kurve', 0);
                        $st['override'] = false;
                        $state[$key] = $st;
                        if ($active && $statusOn) {
                            $this->ApplyExternalOn($light, $state);
                        }
                    }
                    break;
            }
            $this->SetState($state);
        } finally {
            $this->Unlock();
        }
    }

    /** Leuchte, die von außen eingeschaltet wurde, sofort auf die passenden Werte setzen. */
    private function ApplyExternalOn(array $light, array &$state): void
    {
        $target = $this->CalculateTarget(time());
        $bri = (float) $target['bri'];
        if (count($this->GetMotionSensors()) > 0 && !$this->GetValue('Occupied') && $this->IdleWanted($target)) {
            $bri = (float) $this->ReadPropertyInteger('IdleBrightness');
        }
        $this->ApplyLight($light, ['on' => null, 'bri' => $bri, 'ct' => (float) $target['ct']], true, $state);
    }

    private function AllLightsOff(): bool
    {
        foreach ($this->GetLights() as $light) {
            if (IPS_VariableExists($light['Status']) && $this->Truthy(GetValue($light['Status']))) {
                return false;
            }
        }
        return true;
    }

    private function SetAllOverrides(bool $override): void
    {
        if (!$this->Lock()) {
            return;
        }
        try {
            $state = $this->GetState();
            foreach ($this->GetLights() as $light) {
                $state[(string) $light['Status']]['override'] = $override;
            }
            $this->SetState($state);
        } finally {
            $this->Unlock();
        }
    }

    // ------------------------------------------------------------------
    // Kurve & Zeiten
    // ------------------------------------------------------------------

    private function UpdateTargetVariables(): array
    {
        $t = $this->CalculateTarget(time());
        $this->SetValueIfChanged('Phase', (int) $t['phase']);
        $this->SetValueIfChanged('TargetBrightness', (int) round($t['bri']));
        $this->SetValueIfChanged('TargetColorTemp', (int) $t['ct']);
        $this->SetValueIfChanged('EveningStart', (int) $t['evening']);
        return $t;
    }

    private function CalculateTarget(int $now): array
    {
        return FlareCurve::target($this->CurveConfig(), fn (int $mid) => $this->Sunset($mid), $now);
    }

    private function CurveConfig(): array
    {
        $phase = function (string $name, string $ident = '') {
            return [
                'tod'  => $ident !== '' ? $this->TimeOfDay($ident) : 0,
                'bri'  => $this->ReadPropertyInteger($name . 'Brightness'),
                'ct'   => max(1000, $this->ReadPropertyInteger($name . 'ColorTemp')),
                'fade' => max(0, $this->ReadPropertyInteger($name . 'Transition')) * 60,
            ];
        };
        return [
            'phases' => [
                FlareCurve::MORNING => $phase('Morning', 'MorningStart'),
                FlareCurve::DAY     => $phase('Day', 'DayStart'),
                FlareCurve::EVENING => $phase('Evening'),
                FlareCurve::NIGHT   => $phase('Night', 'NightStart'),
            ],
            'evening' => [
                'earliest' => $this->TimeOfDay('EveningEarliest'),
                'latest'   => $this->TimeOfDay('EveningLatest'),
                'offset'   => $this->ReadPropertyInteger('SunsetOffset') * 60,
            ],
            'dayDrift' => $this->ReadPropertyBoolean('DayDrift'),
        ];
    }

    /** Uhrzeit (Sekunden seit Mitternacht) aus einer ~UnixTimestampTime-Variable. */
    private function TimeOfDay(string $ident): int
    {
        $ts = (int) $this->GetValue($ident);
        return (int) date('G', $ts) * 3600 + (int) date('i', $ts) * 60;
    }

    /** Sonnenuntergang für den Tag ab $midnight. */
    private function Sunset(int $midnight): int
    {
        [$lat, $lon] = $this->Coordinates();
        if ($lat != 0.0 || $lon != 0.0) {
            $info = date_sun_info($midnight + 43200, $lat, $lon);
            if (is_int($info['sunset'])) {
                return $info['sunset'];
            }
        }
        $var = $this->ReadPropertyInteger('SunsetVariable');
        if ($var > 0 && IPS_VariableExists($var)) {
            $ts = (int) GetValue($var);
            if ($ts > 0) {
                return mktime((int) date('G', $ts), (int) date('i', $ts), 0,
                    (int) date('n', $midnight), (int) date('j', $midnight), (int) date('Y', $midnight));
            }
        }
        return $midnight + 19 * 3600;
    }

    /** Koordinaten aus den Eigenschaften oder automatisch aus dem Location-Modul. */
    private function Coordinates(): array
    {
        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        if ($lat != 0.0 || $lon != 0.0) {
            return [$lat, $lon];
        }
        $cached = $this->GetBuffer('Coordinates');
        if ($cached !== '') {
            return json_decode($cached, true);
        }
        $result = [0.0, 0.0];
        foreach (@IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}') ?: [] as $id) {
            $loc = json_decode((string) @IPS_GetProperty($id, 'Location'), true);
            if (is_array($loc) && isset($loc['latitude'], $loc['longitude'])) {
                $result = [(float) $loc['latitude'], (float) $loc['longitude']];
                break;
            }
            $lat = @IPS_GetProperty($id, 'Latitude');
            $lon = @IPS_GetProperty($id, 'Longitude');
            if (is_numeric($lat) && is_numeric($lon)) {
                $result = [(float) $lat, (float) $lon];
                break;
            }
        }
        $this->SetBuffer('Coordinates', json_encode($result));
        return $result;
    }

    private function BuildPreview(): array
    {
        $mid = FlareCurve::midnight(time());
        $rows = [];
        for ($m = 0; $m < 1440; $m += 30) {
            $t = $this->CalculateTarget($mid + $m * 60);
            $rows[] = [
                'Time'       => date('H:i', $mid + $m * 60),
                'Phase'      => FlareCurve::NAMES[$t['phase']] . ($t['fading'] ? ' → ' . FlareCurve::NAMES[$t['next']] : ''),
                'Brightness' => (int) round($t['bri']) . ' %',
                'ColorTemp'  => (int) $t['ct'] . ' K',
            ];
        }
        return $rows;
    }

    private function InjectValues(array &$elements, string $name, array $values): void
    {
        foreach ($elements as &$el) {
            if (($el['name'] ?? '') === $name) {
                $el['values'] = $values;
            }
            if (isset($el['items']) && is_array($el['items'])) {
                $this->InjectValues($el['items'], $name, $values);
            }
        }
        unset($el);
    }

    // ------------------------------------------------------------------
    // Variablen der Leuchten lesen/schreiben
    // ------------------------------------------------------------------

    private function GetLights(): array
    {
        $list = json_decode($this->ReadPropertyString('Lights'), true) ?: [];
        $lights = [];
        foreach ($list as $row) {
            $status = (int) ($row['Status'] ?? 0);
            if ($status <= 0) {
                continue;
            }
            $lights[] = [
                'Status'     => $status,
                'Brightness' => (int) ($row['Brightness'] ?? 0),
                'ColorTemp'  => (int) ($row['ColorTemp'] ?? 0),
                'Reachable'  => (int) ($row['Reachable'] ?? 0),
                'Factor'     => max(1, min(200, (int) ($row['Factor'] ?? 100))),
            ];
        }
        return $lights;
    }

    private function GetMotionSensors(): array
    {
        $list = json_decode($this->ReadPropertyString('MotionSensors'), true) ?: [];
        $ids = [];
        foreach ($list as $row) {
            $id = (int) ($row['Variable'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function Send(int $varID, $value): bool
    {
        try {
            $ok = @RequestAction($varID, $value);
            $this->SendDebug('Send', sprintf('%d ← %s', $varID, var_export($value, true)), 0);
            return $ok !== false;
        } catch (Throwable $e) {
            $this->SendDebug('Send', sprintf('%d Fehler: %s', $varID, $e->getMessage()), 0);
            return false;
        }
    }

    private function Truthy($value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'connected', 'online', 'ja'], true);
        }
        return (bool) $value;
    }

    /** Schaltwert passend zum Variablentyp (bool, oder int/float z.B. 0/1). */
    private function OnValue(int $varID, bool $on)
    {
        switch (IPS_GetVariable($varID)['VariableType']) {
            case VARIABLETYPE_INTEGER:
                return $on ? 1 : 0;
            case VARIABLETYPE_FLOAT:
                return $on ? 1.0 : 0.0;
            default:
                return $on;
        }
    }

    /** Min/Max aus dem Variablenprofil (eigenes Profil hat Vorrang). */
    private function ProfileRange(int $varID, float $defMin, float $defMax): array
    {
        $v = IPS_GetVariable($varID);
        $profile = $v['VariableCustomProfile'] !== '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            if ($p['MaxValue'] > $p['MinValue']) {
                return [(float) $p['MinValue'], (float) $p['MaxValue']];
            }
        }
        return [$defMin, $defMax];
    }

    private function ReadPercent(int $varID): float
    {
        [$min, $max] = $this->ProfileRange($varID, 0, 100);
        return ((float) GetValue($varID) - $min) / ($max - $min) * 100;
    }

    private function PercentToVar(int $varID, float $pct)
    {
        [$min, $max] = $this->ProfileRange($varID, 0, 100);
        $value = $min + ($max - $min) * $pct / 100;
        return IPS_GetVariable($varID)['VariableType'] === VARIABLETYPE_FLOAT ? round($value, 2) : (int) round($value);
    }

    private function UsesMired(int $varID): bool
    {
        $unit = $this->ReadPropertyInteger('ColorTempUnit');
        if ($unit === 1) {
            return false;
        }
        if ($unit === 2) {
            return true;
        }
        [$min, $max] = $this->ProfileRange($varID, 0, 0);
        if ($max > 0) {
            return $max < 1000;
        }
        return (float) GetValue($varID) < 1000;
    }

    private function ReadKelvin(int $varID): float
    {
        $v = (float) GetValue($varID);
        if ($this->UsesMired($varID)) {
            return $v > 0 ? 1e6 / $v : 0;
        }
        return $v;
    }

    private function ClampKelvin(int $varID, float $kelvin): float
    {
        [$min, $max] = $this->ProfileRange($varID, 0, 0);
        if ($max <= 0) {
            return round($kelvin);
        }
        if ($this->UsesMired($varID)) {
            // Mired-Bereich in Kelvin umrechnen (Min-Mired = höchste Kelvin)
            [$min, $max] = [1e6 / $max, 1e6 / max(1, $min)];
        }
        return round(max($min, min($max, $kelvin)));
    }

    private function KelvinToVar(int $varID, float $kelvin)
    {
        $value = $this->UsesMired($varID) ? 1e6 / $kelvin : $kelvin;
        return IPS_GetVariable($varID)['VariableType'] === VARIABLETYPE_FLOAT ? round($value, 1) : (int) round($value);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function GetState(): array
    {
        return json_decode($this->ReadAttributeString('LightState'), true) ?: [];
    }

    private function SetState(array $state): void
    {
        $valid = array_map(fn ($l) => (string) $l['Status'], $this->GetLights());
        $state = array_intersect_key($state, array_flip($valid));
        $this->WriteAttributeString('LightState', json_encode($state));
        $count = count(array_filter($state, fn ($s) => !empty($s['override'])));
        $this->SetValueIfChanged('Overrides', $count);
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function Lock(): bool
    {
        if (IPS_SemaphoreEnter('FLARE_' . $this->InstanceID, 5000)) {
            return true;
        }
        $this->SendDebug('Lock', 'Semaphore Timeout', 0);
        return false;
    }

    private function Unlock(): void
    {
        IPS_SemaphoreLeave('FLARE_' . $this->InstanceID);
    }

    private function CreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('FLARE.Phase')) {
            IPS_CreateVariableProfile('FLARE.Phase', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('FLARE.Phase', 0, 'Morgen', 'Sun', 0xFFD54F);
            IPS_SetVariableProfileAssociation('FLARE.Phase', 1, 'Tag', 'Sun', 0xFFFFFF);
            IPS_SetVariableProfileAssociation('FLARE.Phase', 2, 'Abend', 'Moon', 0xFF9800);
            IPS_SetVariableProfileAssociation('FLARE.Phase', 3, 'Nacht', 'Moon', 0x5C6BC0);
        }
        if (!IPS_VariableProfileExists('FLARE.Kelvin')) {
            IPS_CreateVariableProfile('FLARE.Kelvin', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('FLARE.Kelvin', '', ' K');
            IPS_SetVariableProfileValues('FLARE.Kelvin', 2000, 6500, 100);
            IPS_SetVariableProfileIcon('FLARE.Kelvin', 'Bulb');
        }
        if (!IPS_VariableProfileExists('FLARE.Count')) {
            IPS_CreateVariableProfile('FLARE.Count', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('FLARE.Count', '', ' Leuchten');
            IPS_SetVariableProfileIcon('FLARE.Count', 'Hand');
        }
    }
}
