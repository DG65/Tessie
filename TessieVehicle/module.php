<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    // -------- Variable Idents (Actions) --------
        private const ACT_LOCKED             = 'act_locked';
    private const ACT_CLIMATE                = 'act_climate';
    private const ACT_START_CHARGING         = 'act_charging';
    private const ACT_CHARGE_LIMIT           = 'act_charge_limit';
    private const ACT_CHARGING_AMPS          = 'act_charging_amps';
    private const STAT_CHARGE_AMPS_ACTUAL    = 'stat_charge_amps_actual';
    private const STAT_CHARGE_AMPS_MAX       = 'stat_charge_amps_max';
    private const ACT_FLASH                  = 'act_flash';
    private const ACT_HONK                   = 'act_honk';

    // -------- Timers --------
    private const TIMER_UPDATE = 'UpdateTimer';

    // -------- Properties --------
    // ApiBase: Tessie API base, default https://api.tessie.com (Tessie Fleet API mirror is also available there) [15](https://developer.tessie.com/reference/access-tesla-fleet-api)[16](https://developer.tessie.com/reference/authentication)
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

        // Wait for completion query flag (Tessie supports wait_for_completion on commands) [3](https://developer.tessie.com/reference/lock)[5](https://developer.tessie.com/reference/start-climate)[7](https://developer.tessie.com/reference/start-charging)[9](https://developer.tessie.com/reference/flash-lights)[10](https://developer.tessie.com/reference/honk)
        $this->RegisterPropertyBoolean('WaitForCompletion', true);

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

        // Create action variables + links
        try {
            $this->ensureActionVariables();
        } catch (Throwable $e) {
            $this->LogMessage('ensureActionVariables failed: ' . $e->getMessage(), KL_WARNING);
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

        // Optional lightweight status check (asleep/awake). [13](https://developer.tessie.com/reference/get-status)
        $status = $this->getVehicleStatus($vin, $token);
        if ($status !== '') {
            $this->SendDebug('Status', $status, 0);
        }

        // If telemetry is enabled, updates will mostly come via WS. Still keep this for fallback.
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

        // Simple RX: Buffer contains the payload as string [17](https://teslemetry.com/blog/vehicle-data-caching)[14](https://developer.tessie.com/reference/access-tesla-fleet-telemetry)
        $buf = (string)($packet['Buffer'] ?? '');
        if ($buf === '') {
            return;
        }

        // In Symcon the Buffer may be UTF-8 encoded already; do not utf8_decode blindly.
        $payload = json_decode($buf, true);
        if (!is_array($payload)) {
            $this->SendDebug('Telemetry', 'Non-JSON buffer: ' . substr($buf, 0, 300), 0);
            return;
        }

        // Log raw (useful for debugging)
        $this->SendDebug('Telemetry', $buf, 0);

        // Handle errors packets and connection status packets as seen in your dump [2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)[14](https://developer.tessie.com/reference/access-tesla-fleet-telemetry)
        if (isset($payload['errors'])) {
            $this->SendDebug('TelemetryErrors', json_encode($payload['errors']), 0);
            return;
        }
        if (isset($payload['status']) && isset($payload['connectionId'])) {
            $this->SendDebug('TelemetryConnection', json_encode($payload), 0);
            return;
        }

        // Data packets: { data: [ {key, value}, ... ], createdAt, vin, isResend } [14](https://developer.tessie.com/reference/access-tesla-fleet-telemetry)[2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        $this->syncActionVarsFromTelemetry($payload['data']);
    }

    // -------- Actions (IMPORTANT: IPSModule signature must be untyped) --------
    // Symcon docs explicitly show IPSModule variant without type hints [1](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/requestaction/)
    public function RequestAction($Ident, $Value)
    {
        $token = trim($this->ReadPropertyString('ApiToken'));
        $vin   = trim($this->ReadPropertyString('VIN'));

        if ($token === '' || $vin === '') {
            throw new Exception('ApiToken oder VIN fehlt.');
        }

        switch ((string)$Ident) {

            case self::ACT_LOCKED:
                // Tessie: lock/unlock [3](https://developer.tessie.com/reference/lock)[4](https://developer.tessie.com/reference/unlock)
                $wantLocked = (bool)$Value;
                $this->sendCommand($vin, $token, $wantLocked ? 'lock' : 'unlock');
                $this->safeSetValue(self::ACT_LOCKED, $wantLocked);
                break;

            case self::ACT_CLIMATE:
                // Tessie: start_climate / stop_climate [5](https://developer.tessie.com/reference/start-climate)[6](https://developer.tessie.com/reference/stop-climate)
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_climate' : 'stop_climate');
                $this->safeSetValue(self::ACT_CLIMATE, $on);
                break;

            case self::ACT_START_CHARGING:
                // Tessie: start_charging / stop_charging [7](https://developer.tessie.com/reference/start-charging)[8](https://developer.tessie.com/reference/stop-charging)
                $on = (bool)$Value;
                $this->sendCommand($vin, $token, $on ? 'start_charging' : 'stop_charging');
                $this->safeSetValue(self::ACT_START_CHARGING, $on);
                break;

            case self::ACT_CHARGE_LIMIT:
                // Tessie supports "Set Charge Limit" (command name set_charge_limit) [18](https://developer.tessie.com/reference/quick-start)[2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)
                $percent = (int)$Value;
                if ($percent < 0) $percent = 0;
                if ($percent > 100) $percent = 100;

                // IMPORTANT: do NOT send empty JSON bodies; pass query param instead
                $this->sendCommand($vin, $token, 'set_charge_limit', ['percent' => $percent]);
                $this->safeSetValue(self::ACT_CHARGE_LIMIT, $percent);
                break;

            case self::ACT_CHARGING_AMPS:
                // Tessie: set_charging_amps requires amps parameter [11](https://developer.tessie.com/reference/set-charging-amps)
                $amps = (int)$Value;
                if ($amps < 0) $amps = 0;
                if ($amps > 48) $amps = 48;

                $this->sendCommand($vin, $token, 'set_charging_amps', ['amps' => $amps]);
                $this->safeSetValue(self::ACT_CHARGING_AMPS, $amps);
                break;

            case self::ACT_FLASH:
                // Tessie: flash [9](https://developer.tessie.com/reference/flash-lights)
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'flash');
                }
                // button reset
                $this->safeSetValue(self::ACT_FLASH, false);
                break;

            case self::ACT_HONK:
                // Tessie: honk [10](https://developer.tessie.com/reference/honk)
                if ((bool)$Value) {
                    $this->sendCommand($vin, $token, 'honk');
                }
                // button reset
                $this->safeSetValue(self::ACT_HONK, false);
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
        }
    }

    // -------- Internal: command sending --------

    /**
     * Sends a Tessie command using:
     * POST https://api.tessie.com/{vin}/command/{command}?wait_for_completion=true
     * with optional query params (e.g. amps, percent). [3](https://developer.tessie.com/reference/lock)[5](https://developer.tessie.com/reference/start-climate)[7](https://developer.tessie.com/reference/start-charging)[9](https://developer.tessie.com/reference/flash-lights)[10](https://developer.tessie.com/reference/honk)[11](https://developer.tessie.com/reference/set-charging-amps)
     */
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

        // DO NOT send an empty JSON body. This caused HTTP 400 "parsing request parameters" in your logs. [2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)
        $resp = $this->apiRequest($token, 'POST', $path, null);

        $ok = (bool)($resp['result'] ?? ($resp['response']['result'] ?? false));
        if (!$ok) {
            $this->SendDebug('Command', 'Failed: ' . $command . ' ' . json_encode($resp), 0);
        } else {
            $this->SendDebug('Command', 'OK: ' . $command, 0);
        }
    }

    /**
     * Ensure vehicle is awake. Uses Tessie status and wake endpoints. [13](https://developer.tessie.com/reference/get-status)[12](https://developer.tessie.com/reference/wake)
     */
    private function ensureAwake(string $vin, string $token): void
    {
        $status = $this->getVehicleStatus($vin, $token);
        if ($status === 'awake') {
            return;
        }

        // Wake endpoint: POST /{vin}/wake returns result true when awake or false after timeout [12](https://developer.tessie.com/reference/wake)
        $path = '/' . rawurlencode($vin) . '/wake';
        $resp = $this->apiRequest($token, 'POST', $path, null);

        $ok = (bool)($resp['result'] ?? false);
        $this->SendDebug('Wake', 'result=' . json_encode($resp), 0);

        // If wake failed, still try sending command; Tessie may handle retries server-side,
        // but the user will see a meaningful error anyway.
        (void)$ok;
    }

    private function getVehicleStatus(string $vin, string $token): string
    {
        // Tessie: GET /{vin}/status -> { "status": "asleep|waiting_for_sleep|awake" } [13](https://developer.tessie.com/reference/get-status)
        $path = '/' . rawurlencode($vin) . '/status';
        $resp = $this->apiRequest($token, 'GET', $path, null);
        $st = $resp['status'] ?? '';
        return is_string($st) ? $st : '';
    }

    // -------- Telemetry -> update action variables --------

    private function syncActionVarsFromTelemetry(array $dataItems): void
    {
        // Telemetry data format is documented by Tessie: array of {key, value} [14](https://developer.tessie.com/reference/access-tesla-fleet-telemetry)[2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)
        $locked = null;
        $limit  = null;
        $amps   = null;       
        $req    = null;
        $act    = null;
        $max    = null;


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
                $amps = (int)$val['intValue'];
            } elseif ($key === 'ChargeCurrentRequest' && array_key_exists('intValue', $val)) {
                $req = (int)$val['intValue'];
            } elseif ($key === 'ChargeAmps' && array_key_exists('doubleValue', $val)) {
                $act = (float)$val['doubleValue'];
            } elseif ($key === 'ChargeCurrentRequestMax' && array_key_exists('intValue', $val)) {
                $max = (int)$val['intValue'];
            }

        }

        if ($locked !== null) {
            $this->safeSetValue(self::ACT_LOCKED, $locked);
        }
        if ($limit !== null) {
            $this->safeSetValue(self::ACT_CHARGE_LIMIT, $limit);
        }
        if ($amps !== null) {
            $this->safeSetValue(self::ACT_CHARGING_AMPS, $amps);
        }
        if ($req !== null) {
            $this->safeSetValue(self::ACT_CHARGING_AMPS, $req);          // Sollwert, Action-Variable
        }
        if ($act !== null) {
            $this->safeSetValue(self::STAT_CHARGE_AMPS_ACTUAL, $act);   // Istwert
        }
        if ($max !== null) {$this->safeSetValue(self::STAT_CHARGE_AMPS_MAX, $max);      // Max
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

    // -------- Action variables + links --------

    private function ensureActionVariables(): void
    {
        $catId = $this->ensureCategory('Aktionen');

        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', 0, true);
        $this->EnableAction(self::ACT_LOCKED);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_LOCKED), 'action.locked', 'Verriegelt');

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_CLIMATE);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_CLIMATE), 'action.climate', 'Klima');

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_START_CHARGING);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_START_CHARGING), 'action.charging', 'Laden');

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', 0, true);
        $this->EnableAction(self::ACT_CHARGE_LIMIT);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_CHARGE_LIMIT), 'action.charge_limit', 'Ladelimit (%)');

        $this->MaintainVariable(self::ACT_CHARGING_AMPS, 'Ladestrom (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $this->EnableAction(self::ACT_CHARGING_AMPS);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_CHARGING_AMPS), 'action.charging_amps', 'Ladestrom (A)');

        $this->MaintainVariable(self::STAT_CHARGE_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, 'Tessie.AmpsFloat', 0, true);
        $this->MaintainVariable(self::STAT_CHARGE_AMPS_MAX,    'Ladestrom Max (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);

        // Button: flash (reset after press)
        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_FLASH);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_FLASH), 'action.flash', 'Licht blinken');

        // Button: honk (reset after press)
        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_HONK);
        $this->ensureLink($catId, $this->GetIDForIdent(self::ACT_HONK), 'action.honk', 'Hupe');
    }

    // -------- Profiles --------

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

        // Amps profile
        if (!IPS_VariableProfileExists('Tessie.Amps')) {
            IPS_CreateVariableProfile('Tessie.Amps', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.Amps', '', ' A');
            IPS_SetVariableProfileValues('Tessie.Amps', 0, 48, 1);
            IPS_SetVariableProfileDigits('Tessie.Amps', 0);
            IPS_SetVariableProfileIcon('Tessie.Amps', 'Electricity');
        }

        if (!IPS_VariableProfileExists('Tessie.AmpsFloat')) {
            IPS_CreateVariableProfile('Tessie.AmpsFloat', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.AmpsFloat', '', ' A');
            IPS_SetVariableProfileValues('Tessie.AmpsFloat', 0, 48, 0);
            IPS_SetVariableProfileDigits('Tessie.AmpsFloat', 1);
            IPS_SetVariableProfileIcon('Tessie.AmpsFloat', 'Electricity');
        }
    }

    // -------- Category/Link helpers --------

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

    private function ensureLink(int $catId, $targetId, string $fullKey, string $label): void
    {
        // Guard against invalid ids
        if (!is_int($targetId) || $targetId <= 0 || !IPS_ObjectExists($targetId)) {
            return;
        }

        $linkIdent = 'LNK_' . $this->makeIdent($fullKey);
        $linkId = @IPS_GetObjectIDByIdent($linkIdent, $catId);
        if ($linkId <= 0) {
            $linkId = IPS_CreateLink();
            IPS_SetParent($linkId, $catId);
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

    /**
     * Generic request against ApiBase (default https://api.tessie.com). [16](https://developer.tessie.com/reference/authentication)[15](https://developer.tessie.com/reference/access-tesla-fleet-api)
     * IMPORTANT: if $body is null or empty, do NOT send a JSON body.
     * Sending [] caused HTTP 400 parsing errors in your logs. [2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)
     */
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
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

        // Only attach a body if it's non-empty (avoid [] which broke commands) [2](https://adsoba-my.sharepoint.com/personal/d_gureth_adsoba_de/Documents/Microsoft%20Copilot%20Chat-Dateien/dump.txt)
        $methodUpper = strtoupper($method);

        $hasJsonBody = !($body === null || (is_array($body) && count($body) === 0));

        if ($hasJsonBody) {
            // Normaler JSON-Body
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        } else {
            // WICHTIG: Für POST ohne Payload trotzdem einen leeren Body senden,
            // damit cURL Content-Length: 0 setzt und kein HTTP 411 entsteht.
            if ($methodUpper === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
                // Content-Length wird i.d.R. automatisch gesetzt, aber explizit schadet nicht:
                $headers[] = 'Content-Length: 0';
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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
