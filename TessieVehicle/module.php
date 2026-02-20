<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    // -------- Variable Idents (Actions) --------
    private const ACT_LOCKED         = 'act_locked';
    private const ACT_CLIMATE        = 'act_climate';
    private const ACT_START_CHARGING = 'act_charging';
    private const ACT_CHARGE_LIMIT   = 'act_charge_limit';

    // Action variable = gewünschter Ladestrom (Sollwert) -> wird via set_charging_amps gesetzt
    private const ACT_CHARGING_AMPS_REQUEST = 'act_charging_amps';

    private const ACT_FLASH          = 'act_flash';
    private const ACT_HONK           = 'act_honk';

    // -------- Variable Idents (Status) --------
    // Statuswerte aus Telemetrie (nur Anzeige)
    private const STAT_CHARGING_AMPS_ACTUAL = 'stat_charge_amps_actual';  // ChargeAmps (Ist)
    private const STAT_CHARGING_AMPS_MAX    = 'stat_charge_amps_max';     // ChargeCurrentRequestMax
    private const STAT_AC_CHARGING_POWER    = 'stat_ac_charging_power';   // ACChargingPower

    // -------- Timers --------
    private const TIMER_UPDATE = 'UpdateTimer';

    // -------- Properties --------
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');

        $this->RegisterPropertyInteger('UpdateInterval', 300);     // seconds
        $this->RegisterPropertyBoolean('TelemetryEnabled', true);

        // If enabled: try to wake vehicle before sending commands
        $this->RegisterPropertyBoolean('WakeBeforeCommands', true);

        // Wait for completion query flag
        $this->RegisterPropertyBoolean('WaitForCompletion', true);

        // Debug HTTP Requests
        $this->RegisterPropertyBoolean('DebugHTTP', false);

        $this->RegisterTimer(self::TIMER_UPDATE, 0, 'TESSIE_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Timer interval
        $interval = (int)$this->ReadPropertyInteger('UpdateInterval');
        if ($interval < 0) {
            $interval = 0;
        }
        $this->SetTimerInterval(self::TIMER_UPDATE, $interval > 0 ? $interval * 1000 : 0);

        // Profiles must exist before MaintainVariable uses them
        $this->ensureProfiles();

        // Create variables + links
        try {
            $this->ensureVariables();
        } catch (Throwable $e) {
            $this->LogMessage('ensureVariables failed: ' . $e->getMessage(), KL_WARNING);
        }

        $this->SetStatus(102);
    }

    // -------- Public helper called by timer --------
    public function Update()
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));
        if ($token === '' || $vin === '') {
            return;
        }

        $status = $this->getVehicleStatus($vin, $token);
        if ($status !== '') {
            $this->SendDebug('Status', $status, 0);
        }
    }

    // -------- Dataflow: receive data from WebSocket I/O (Simple RX) --------
    public function ReceiveData($JSONString)
    {
        if (!(bool)$this->ReadPropertyBoolean('TelemetryEnabled')) {
            return;
        }

        $packet = json_decode($JSONString, true);
        if (!is_array($packet)) {
            return;
        }

        // Simple RX: Buffer contains the payload as string
        $buf = (string)($packet['Buffer'] ?? '');
        if ($buf === '') {
            return;
        }

        $payload = json_decode($buf, true);
        if (!is_array($payload)) {
            $this->SendDebug('Telemetry', 'Non-JSON buffer: ' . substr($buf, 0, 300), 0);
            return;
        }

        // Log raw (useful for debugging)
        $this->SendDebug('Telemetry', $buf, 0);

        // Handle errors packets and connection status packets
        if (isset($payload['errors'])) {
            $this->SendDebug('TelemetryErrors', json_encode($payload['errors']), 0);
            return;
        }
        if (isset($payload['status']) && isset($payload['connectionId'])) {
            $this->SendDebug('TelemetryConnection', json_encode($payload), 0);
            return;
        }

        // Data packets: { data: [ {key, value}, ... ], createdAt, vin, isResend }
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        $this->syncFromTelemetry($payload['data']);
    }

    // -------- Actions (IMPORTANT: IPSModule signature must be untyped) --------
    public function RequestAction($Ident, $Value)
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));

        if ($token === '' || $vin === '') {
            throw new Exception('ApiToken oder VIN fehlt.');
        }

        switch ((string)$Ident) {

            case self::ACT_LOCKED:
                $wantLocked = (bool)$Value;
                $this->sendCommand($vin, $token, $wantLocked ? 'lock' : 'unlock');
                $this->safeSetValue(self::ACT_LOCKED, $wantLocked);
                break;

            case self::ACT_CLIMATE:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_climate' : 'stop_climate');
                $this->safeSetValue(self::ACT_CLIMATE, $on);
                break;

            case self::ACT_START_CHARGING:
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_charging' : 'stop_charging');
                $this->safeSetValue(self::ACT_START_CHARGING, $on);
                break;

            case self::ACT_CHARGE_LIMIT:
                $percent = (int)$Value;
                if ($percent < 0) $percent = 0;
                if ($percent > 100) $percent = 100;

                // Tessie command: set_charge_limit expects percent
                $this->sendCommand($vin, $token, 'set_charge_limit', ['percent' => $percent]);
                $this->safeSetValue(self::ACT_CHARGE_LIMIT, $percent);
                break;

            case self::ACT_CHARGING_AMPS_REQUEST:
                // Tessie command: set_charging_amps expects amps
                $amps = (int)$Value;
                if ($amps < 0) $amps = 0;
                if ($amps > 48) $amps = 48;

                $this->sendCommand($vin, $token, 'set_charging_amps', ['amps' => $amps]);
                // Anzeige sofort setzen (Telemetrie gleicht nach)
                $this->safeSetValue(self::ACT_CHARGING_AMPS_REQUEST, $amps);
                break;

            case self::ACT_FLASH:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'flash');
                }
                $this->safeSetValue(self::ACT_FLASH, false);
                break;

            case self::ACT_HONK:
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'honk');
                }
                $this->safeSetValue(self::ACT_HONK, false);
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
        }
    }

    // -------- Internal: command sending --------
    private function sendCommand(string $vin, string $token, string $command, array $queryParams = []): void
    {
        if ((bool)$this->ReadPropertyBoolean('WakeBeforeCommands')) {
            $this->ensureAwake($vin, $token);
        }

        $wait = (bool)$this->ReadPropertyBoolean('WaitForCompletion');
        $params = $queryParams;
        if ($wait) {
            $params['wait_for_completion'] = 'true';
        }

        $path = '/' . rawurlencode($vin) . '/command/' . rawurlencode($command);
        if (count($params) > 0) {
            $path .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $resp = $this->apiRequest($token, 'POST', $path, null);

        $ok = (bool)($resp['result'] ?? ($resp['response']['result'] ?? false));
        if (!$ok) {
            $this->SendDebug('Command', 'Failed: ' . $command . ' ' . json_encode($resp), 0);
        } else {
            $this->SendDebug('Command', 'OK: ' . $command, 0);
        }
    }

    private function ensureAwake(string $vin, string $token): void
    {
        $status = $this->getVehicleStatus($vin, $token);
        if ($status === 'awake') {
            return;
        }

        $path = '/' . rawurlencode($vin) . '/wake';
        $resp = $this->apiRequest($token, 'POST', $path, null);
        $this->SendDebug('Wake', 'result=' . json_encode($resp), 0);
    }

    private function getVehicleStatus(string $vin, string $token): string
    {
        $path = '/' . rawurlencode($vin) . '/status';
        $resp = $this->apiRequest($token, 'GET', $path, null);
        $st = $resp['status'] ?? '';
        return is_string($st) ? $st : '';
    }

    // -------- Telemetry -> update variables --------
    private function syncFromTelemetry(array $dataItems): void
    {
        $locked = null;
        $limit  = null;

        $req = null;  // ChargeCurrentRequest (Soll)
        $act = null;  // ChargeAmps (Ist)
        $max = null;  // ChargeCurrentRequestMax
        $acp = null;  // ACChargingPower

        foreach ($dataItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string)($item['key'] ?? '');
            $val = $item['value'] ?? null;
            if (!is_array($val) || $key === '') {
                continue;
            }

            if ($key === 'Locked' && array_key_exists('booleanValue', $val)) {
                $locked = (bool)$val['booleanValue'];

            } elseif ($key === 'ChargeLimitSoc' && array_key_exists('intValue', $val)) {
                $limit = (int)$val['intValue'];

            } elseif ($key === 'ChargeCurrentRequest' && array_key_exists('intValue', $val)) {
                $req = (int)$val['intValue'];

            } elseif ($key === 'ChargeAmps' && array_key_exists('doubleValue', $val)) {
                $act = (float)$val['doubleValue'];

            } elseif ($key === 'ChargeCurrentRequestMax' && array_key_exists('intValue', $val)) {
                $max = (int)$val['intValue'];

            } elseif ($key === 'ACChargingPower' && array_key_exists('doubleValue', $val)) {
                $acp = (float)$val['doubleValue'];
            }
        }

        if ($locked !== null) {
            $this->safeSetValue(self::ACT_LOCKED, $locked);
        }
        if ($limit !== null) {
            $this->safeSetValue(self::ACT_CHARGE_LIMIT, $limit);
        }
        if ($req !== null) {
            // Action-Variable zeigt den SOLL-Wert
            $this->safeSetValue(self::ACT_CHARGING_AMPS_REQUEST, $req);
        }
        if ($act !== null) {
            $this->safeSetValue(self::STAT_CHARGING_AMPS_ACTUAL, $act);
        }
        if ($max !== null) {
            $this->safeSetValue(self::STAT_CHARGING_AMPS_MAX, $max);
        }
        if ($acp !== null) {
            $this->safeSetValue(self::STAT_AC_CHARGING_POWER, $acp);
        }
    }

    private function safeSetValue(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id <= 0) {
            return;
        }

        $type = IPS_GetVariable($id)['VariableType'] ?? null;
        if ($type === VARIABLETYPE_BOOLEAN) {
            @SetValueBoolean($id, (bool)$value);
        } elseif ($type === VARIABLETYPE_INTEGER) {
            @SetValueInteger($id, (int)$value);
        } elseif ($type === VARIABLETYPE_FLOAT) {
            @SetValueFloat($id, (float)$value);
        } else {
            @SetValueString($id, (string)$value);
        }
    }

    // -------- Variables, profiles, links --------
    private function ensureVariables(): void
    {
        $catActions = $this->ensureCategory('Aktionen');
        $catStatus  = $this->ensureCategory('Status');

        // Actions
        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', 0, true);
        $this->EnableAction(self::ACT_LOCKED);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_LOCKED), 'action.locked', 'Verriegelt');

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_CLIMATE);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_CLIMATE), 'action.climate', 'Klima');

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_START_CHARGING);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_START_CHARGING), 'action.charging', 'Laden');

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', 0, true);
        $this->EnableAction(self::ACT_CHARGE_LIMIT);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_CHARGE_LIMIT), 'action.charge_limit', 'Ladelimit (%)');

        // Action variable (Sollwert) – bewusst so benannt
        $this->MaintainVariable(self::ACT_CHARGING_AMPS_REQUEST, 'Ladestrom Soll (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $this->EnableAction(self::ACT_CHARGING_AMPS_REQUEST);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_CHARGING_AMPS_REQUEST), 'action.charging_amps_request', 'Ladestrom Soll (A)');

        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_FLASH);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_FLASH), 'action.flash', 'Licht blinken');

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_HONK);
        $this->ensureLink($catActions, $this->GetIDForIdent(self::ACT_HONK), 'action.honk', 'Hupe');

        // Status variables (no action)
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, 'Tessie.AmpsFloat', 0, true);
        $this->ensureLink($catStatus, $this->GetIDForIdent(self::STAT_CHARGING_AMPS_ACTUAL), 'status.charging_amps_actual', 'Ladestrom Ist (A)');

        $this->MaintainVariable(self::STAT_CHARGING_AMPS_MAX, 'Ladestrom Max (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $this->ensureLink($catStatus, $this->GetIDForIdent(self::STAT_CHARGING_AMPS_MAX), 'status.charging_amps_max', 'Ladestrom Max (A)');

        $this->MaintainVariable(self::STAT_AC_CHARGING_POWER, 'AC Ladeleistung (kW)', VARIABLETYPE_FLOAT, 'Tessie.kW', 0, true);
        $this->ensureLink($catStatus, $this->GetIDForIdent(self::STAT_AC_CHARGING_POWER), 'status.ac_charging_power', 'AC Ladeleistung (kW)');
    }

    private function ensureProfiles(): void
    {
        // Percent profile
        if (!IPS_VariableProfileExists('Tessie.PercentInt')) {
            IPS_CreateVariableProfile('Tessie.PercentInt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.PercentInt', '', ' %');
            IPS_SetVariableProfileValues('Tessie.PercentInt', 0, 100, 1);
            IPS_SetVariableProfileDigits('Tessie.PercentInt', 0);
            IPS_SetVariableProfileIcon('Tessie.PercentInt', 'Intensity');
        }

        // Amps integer profile
        if (!IPS_VariableProfileExists('Tessie.Amps')) {
            IPS_CreateVariableProfile('Tessie.Amps', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.Amps', '', ' A');
            IPS_SetVariableProfileValues('Tessie.Amps', 0, 48, 1);
            IPS_SetVariableProfileDigits('Tessie.Amps', 0);
            IPS_SetVariableProfileIcon('Tessie.Amps', 'Electricity');
        }

        // Amps float profile (Istwert)
        if (!IPS_VariableProfileExists('Tessie.AmpsFloat')) {
            IPS_CreateVariableProfile('Tessie.AmpsFloat', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.AmpsFloat', '', ' A');
            IPS_SetVariableProfileValues('Tessie.AmpsFloat', 0, 48, 0);
            IPS_SetVariableProfileDigits('Tessie.AmpsFloat', 1);
            IPS_SetVariableProfileIcon('Tessie.AmpsFloat', 'Electricity');
        }

        // kW float profile
        if (!IPS_VariableProfileExists('Tessie.kW')) {
            IPS_CreateVariableProfile('Tessie.kW', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.kW', '', ' kW');
            IPS_SetVariableProfileValues('Tessie.kW', 0, 30, 0);
            IPS_SetVariableProfileDigits('Tessie.kW', 2);
            IPS_SetVariableProfileIcon('Tessie.kW', 'Electricity');
        }
    }

    private function ensureCategory(string $name): int
    {
        $ident = 'CAT_' . $this->makeIdent($name);
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id <= 0) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
        }
        return $id;
    }

    private function ensureLink(int $parentId, int $targetId, string $fullKey, string $label): void
    {
        if ($targetId <= 0 || !IPS_ObjectExists($targetId)) {
            return;
        }

        $linkIdent = 'LNK_' . $this->makeIdent($fullKey);
        $linkId = @IPS_GetObjectIDByIdent($linkIdent, $parentId);
        if ($linkId <= 0) {
            $linkId = IPS_CreateLink();
            IPS_SetParent($linkId, $parentId);
            IPS_SetIdent($linkId, $linkIdent);
        }
        IPS_SetName($linkId, $label);
        IPS_SetLinkTargetID($linkId, $targetId);
    }

    private function makeIdent(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
        $s = preg_replace('/_+/', '_', (string)$s);
        $s = trim((string)$s, '_');
        if ($s === '') {
            $s = 'X';
        }
        return substr($s, 0, 64);
    }

    // -------- HTTP (Tessie API) --------
    private function apiRequest(string $token, string $method, string $path, $body): array
    {
        $base = rtrim(trim($this->ReadPropertyString('ApiBase')), '/');

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $url = $base . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $methodUpper = strtoupper($method);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodUpper);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

        $hasJsonBody = !($body === null || (is_array($body) && count($body) === 0));

        if ($hasJsonBody) {
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        } else {
            // POST ohne Payload: leeren Body + Content-Length 0 erzwingen
            if ($methodUpper === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
                $headers[] = 'Content-Length: 0';
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ((bool)$this->ReadPropertyBoolean('DebugHTTP')) {
            $this->SendDebug('ApiRequestURL', $methodUpper . ' ' . $url, 0);
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $this->SendDebug('ApiRequest', 'cURL error: ' . $err, 0);
            return [];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->SendDebug('ApiRequest', 'HTTP ' . $code . ' non-JSON: ' . substr($resp, 0, 500), 0);
            return [];
        }

        return $json;
    }
}
