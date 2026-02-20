<?php
declare(strict_types=1);

class TessieVehicle extends IPSModule
{
    // -------------------- Variable Idents (Actions) --------------------
    private const ACT_LOCKED                = 'act_locked';
    private const ACT_CLIMATE               = 'act_climate';
    private const ACT_START_CHARGING        = 'act_charging';
    private const ACT_CHARGE_LIMIT          = 'act_charge_limit';

    // Action variable = gewünschter Ladestrom (Sollwert)
    private const ACT_CHARGING_AMPS_REQUEST = 'act_charging_amps';

    private const ACT_FLASH                 = 'act_flash';
    private const ACT_HONK                  = 'act_honk';

    // -------------------- Variable Idents (Status) --------------------
    private const STAT_CHARGING_AMPS_ACTUAL = 'stat_charge_amps_actual';   // ChargeAmps (Ist)
    private const STAT_CHARGING_AMPS_MAX    = 'stat_charge_amps_max';      // ChargeCurrentRequestMax
    private const STAT_AC_CHARGING_POWER    = 'stat_ac_charging_power';    // ACChargingPower

    // -------------------- Timers --------------------
    private const TIMER_UPDATE = 'UpdateTimer';

    // -------------------- Purpose categories (Links) --------------------
    private const PURPOSE_ACTIONS  = 'Aktionen';
    private const PURPOSE_STATUS   = 'Status';
    private const PURPOSE_CHARGING = 'Laden';
    private const PURPOSE_CLIMATE  = 'Klima';
    private const PURPOSE_SECURITY = 'Sicherheit';

    // -------------------- Attributes --------------------
    private const ATTR_VEHICLE_NAME        = 'VehicleName';
    private const ATTR_LAST_LINKS_LOCATION = 'LastLinksLocation';

    // -------------------- Ident prefixes for managed link tree --------------------
    private const IDENT_ROOT_PREFIX  = 'TESSIE_LINKROOT_';
    private const IDENT_PURP_PREFIX  = 'PURP_';
    private const IDENT_LINK_PREFIX  = 'LNK_';

    public function Create()
    {
        parent::Create();

        // API
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ApiBase', 'https://api.tessie.com');

        // Behavior
        $this->RegisterPropertyInteger('UpdateInterval', 300);     // seconds
        $this->RegisterPropertyBoolean('TelemetryEnabled', true);
        $this->RegisterPropertyBoolean('WakeBeforeCommands', true);
        $this->RegisterPropertyBoolean('WaitForCompletion', true);
        $this->RegisterPropertyBoolean('DebugHTTP', false);

        // Object tree placement
        // Where the instance should be placed (Parent category). 0 => do not move.
        $this->RegisterPropertyInteger('InstanceLocation', 0);

        // Links: Root parent category for link tree. 0 => do not create links.
        $this->RegisterPropertyInteger('LinksLocation', 0);
        $this->RegisterPropertyBoolean('CreateLinks', true);

        // Cleanup: remove obsolete auto-created links (and old root if LinksLocation changed)
        $this->RegisterPropertyBoolean('CleanupLinks', true);

        // Debug/Simulation for cleanup
        $this->RegisterPropertyBoolean('DebugCleanup', false);
        $this->RegisterPropertyBoolean('DryRunCleanup', false);

        // Internal
        $this->RegisterTimer(self::TIMER_UPDATE, 0, 'TESSIE_Update($_IPS["TARGET"]);');
        $this->RegisterAttributeString(self::ATTR_VEHICLE_NAME, '');
        $this->RegisterAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, 0);
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

        // Move instance if configured
        $instanceParent = (int)$this->ReadPropertyInteger('InstanceLocation');
        if ($instanceParent > 0 && IPS_ObjectExists($instanceParent)) {
            $currentParent = IPS_GetParent($this->InstanceID);
            if ($currentParent !== $instanceParent) {
                IPS_SetParent($this->InstanceID, $instanceParent);
            }
        }

        // Profiles
        $this->ensureProfiles();

        // Variables (remain under instance)
        try {
            $this->ensureVariables();
        } catch (Throwable $e) {
            $this->LogMessage('ensureVariables failed: ' . $e->getMessage(), KL_WARNING);
        }

        // Links
        try {
            $this->ensureLinkTree();
        } catch (Throwable $e) {
            $this->LogMessage('ensureLinkTree failed: ' . $e->getMessage(), KL_WARNING);
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

        $this->SendDebug('Telemetry', $buf, 0);

        if (isset($payload['errors'])) {
            $this->SendDebug('TelemetryErrors', json_encode($payload['errors']), 0);
            return;
        }
        if (isset($payload['status']) && isset($payload['connectionId'])) {
            $this->SendDebug('TelemetryConnection', json_encode($payload), 0);
            return;
        }

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

                $this->sendCommand($vin, $token, 'set_charge_limit', ['percent' => $percent]);
                $this->safeSetValue(self::ACT_CHARGE_LIMIT, $percent);
                break;

            case self::ACT_CHARGING_AMPS_REQUEST:
                $amps = (int)$Value;
                if ($amps < 0) $amps = 0;
                if ($amps > 48) $amps = 48;

                $this->sendCommand($vin, $token, 'set_charging_amps', ['amps' => $amps]);
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
        $vehicleName = null; // VehicleName

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

            } elseif ($key === 'VehicleName' && array_key_exists('stringValue', $val)) {
                $vehicleName = (string)$val['stringValue'];
            }
        }

        if ($locked !== null) {
            $this->safeSetValue(self::ACT_LOCKED, $locked);
        }
        if ($limit !== null) {
            $this->safeSetValue(self::ACT_CHARGE_LIMIT, $limit);
        }
        if ($req !== null) {
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

        // Store vehicle name for link root caption
        if ($vehicleName !== null && trim($vehicleName) !== '') {
            $old = $this->ReadAttributeString(self::ATTR_VEHICLE_NAME);
            if ($old !== $vehicleName) {
                $this->WriteAttributeString(self::ATTR_VEHICLE_NAME, $vehicleName);
                // Rename link root category now (if enabled)
                $this->ensureLinkTree(true);
            }
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

    // -------- Variables & profiles --------
    private function ensureVariables(): void
    {
        // Actions
        $this->MaintainVariable(self::ACT_LOCKED, 'Verriegelt', VARIABLETYPE_BOOLEAN, '~Lock', 0, true);
        $this->EnableAction(self::ACT_LOCKED);

        $this->MaintainVariable(self::ACT_CLIMATE, 'Klima', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_CLIMATE);

        $this->MaintainVariable(self::ACT_START_CHARGING, 'Laden', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_START_CHARGING);

        $this->MaintainVariable(self::ACT_CHARGE_LIMIT, 'Ladelimit (%)', VARIABLETYPE_INTEGER, 'Tessie.PercentInt', 0, true);
        $this->EnableAction(self::ACT_CHARGE_LIMIT);

        $this->MaintainVariable(self::ACT_CHARGING_AMPS_REQUEST, 'Ladestrom Soll (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $this->EnableAction(self::ACT_CHARGING_AMPS_REQUEST);

        $this->MaintainVariable(self::ACT_FLASH, 'Licht blinken', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_FLASH);

        $this->MaintainVariable(self::ACT_HONK, 'Hupe', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction(self::ACT_HONK);

        // Status
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_ACTUAL, 'Ladestrom Ist (A)', VARIABLETYPE_FLOAT, 'Tessie.AmpsFloat', 0, true);
        $this->MaintainVariable(self::STAT_CHARGING_AMPS_MAX, 'Ladestrom Max (A)', VARIABLETYPE_INTEGER, 'Tessie.Amps', 0, true);
        $this->MaintainVariable(self::STAT_AC_CHARGING_POWER, 'AC Ladeleistung (kW)', VARIABLETYPE_FLOAT, 'Tessie.kW', 0, true);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('Tessie.PercentInt')) {
            IPS_CreateVariableProfile('Tessie.PercentInt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Tessie.PercentInt', '', ' %');
            IPS_SetVariableProfileValues('Tessie.PercentInt', 0, 100, 1);
            IPS_SetVariableProfileDigits('Tessie.PercentInt', 0);
            IPS_SetVariableProfileIcon('Tessie.PercentInt', 'Intensity');
        }

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

        if (!IPS_VariableProfileExists('Tessie.kW')) {
            IPS_CreateVariableProfile('Tessie.kW', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('Tessie.kW', '', ' kW');
            IPS_SetVariableProfileValues('Tessie.kW', 0, 30, 0);
            IPS_SetVariableProfileDigits('Tessie.kW', 2);
            IPS_SetVariableProfileIcon('Tessie.kW', 'Electricity');
        }
    }

    // -------- Link tree (overview) --------
    private function ensureLinkTree(bool $forceRename = false): void
    {
        if (!(bool)$this->ReadPropertyBoolean('CreateLinks')) {
            return;
        }

        $linksParent = (int)$this->ReadPropertyInteger('LinksLocation');
        if ($linksParent <= 0 || !IPS_ObjectExists($linksParent)) {
            return;
        }

        // Cleanup old root if LinksLocation has changed
        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            $this->cleanupOldRootIfNeeded($linksParent);
        }

        // Root category name = vehicle name (preferred) else instance name
        $vehicleName = trim($this->ReadAttributeString(self::ATTR_VEHICLE_NAME));
        $rootName = $vehicleName !== '' ? $vehicleName : IPS_GetName($this->InstanceID);

        // Create/find root category by stable ident (unique per instance)
        $rootIdent = self::IDENT_ROOT_PREFIX . $this->InstanceID;
        $rootId = @IPS_GetObjectIDByIdent($rootIdent, $linksParent);
        if ($rootId <= 0) {
            $rootId = IPS_CreateCategory();
            IPS_SetParent($rootId, $linksParent);
            IPS_SetIdent($rootId, $rootIdent);
        }
        if ($forceRename || IPS_GetName($rootId) !== $rootName) {
            IPS_SetName($rootId, $rootName);
        }

        // Purpose sub categories under root
        $purposes = [
            self::PURPOSE_ACTIONS,
            self::PURPOSE_STATUS,
            self::PURPOSE_CHARGING,
            self::PURPOSE_CLIMATE,
            self::PURPOSE_SECURITY
        ];

        $purposeIds = [];
        foreach ($purposes as $p) {
            $pid = $this->ensureCategoryUnder($rootId, $p, self::IDENT_PURP_PREFIX . $this->makeIdent($p));
            $purposeIds[$p] = $pid;
        }

        // Desired link idents for cleanup
        $desired = [];

        // Build link sets
        $actionVars = [
            self::ACT_LOCKED,
            self::ACT_CLIMATE,
            self::ACT_START_CHARGING,
            self::ACT_CHARGE_LIMIT,
            self::ACT_CHARGING_AMPS_REQUEST,
            self::ACT_FLASH,
            self::ACT_HONK
        ];

        $statusVars = [
            self::STAT_CHARGING_AMPS_ACTUAL,
            self::STAT_CHARGING_AMPS_MAX,
            self::STAT_AC_CHARGING_POWER
        ];

        // Domain mappings (variables -> purpose)
        $domainMap = [
            // Laden
            self::ACT_START_CHARGING        => self::PURPOSE_CHARGING,
            self::ACT_CHARGE_LIMIT          => self::PURPOSE_CHARGING,
            self::ACT_CHARGING_AMPS_REQUEST => self::PURPOSE_CHARGING,
            self::STAT_CHARGING_AMPS_ACTUAL => self::PURPOSE_CHARGING,
            self::STAT_CHARGING_AMPS_MAX    => self::PURPOSE_CHARGING,
            self::STAT_AC_CHARGING_POWER    => self::PURPOSE_CHARGING,

            // Klima
            self::ACT_CLIMATE               => self::PURPOSE_CLIMATE,

            // Sicherheit
            self::ACT_LOCKED                => self::PURPOSE_SECURITY,
            self::ACT_FLASH                 => self::PURPOSE_SECURITY,
            self::ACT_HONK                  => self::PURPOSE_SECURITY
        ];

        // Create links in Actions / Status overview categories
        foreach ($actionVars as $ident) {
            $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varId > 0) {
                $linkIdent = self::IDENT_LINK_PREFIX . 'ACT_' . $ident;
                $this->ensureLinkUnder($purposeIds[self::PURPOSE_ACTIONS], $varId, $linkIdent, IPS_GetName($varId));
                $desired[$purposeIds[self::PURPOSE_ACTIONS]][] = $linkIdent;
            }
        }
        foreach ($statusVars as $ident) {
            $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varId > 0) {
                $linkIdent = self::IDENT_LINK_PREFIX . 'STAT_' . $ident;
                $this->ensureLinkUnder($purposeIds[self::PURPOSE_STATUS], $varId, $linkIdent, IPS_GetName($varId));
                $desired[$purposeIds[self::PURPOSE_STATUS]][] = $linkIdent;
            }
        }

        // Create links in domain categories
        foreach ($domainMap as $ident => $purposeName) {
            $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varId > 0) {
                $linkIdent = self::IDENT_LINK_PREFIX . 'DOM_' . $this->makeIdent($purposeName) . '_' . $ident;
                $this->ensureLinkUnder($purposeIds[$purposeName], $varId, $linkIdent, IPS_GetName($varId));
                $desired[$purposeIds[$purposeName]][] = $linkIdent;
            }
        }

        // Cleanup obsolete links inside our managed purpose categories
        if ((bool)$this->ReadPropertyBoolean('CleanupLinks')) {
            foreach ($purposeIds as $pid) {
                $keep = $desired[$pid] ?? [];
                $this->cleanupLinksUnder($pid, $keep);
            }
        }

        // Remember current LinksLocation
        $this->WriteAttributeInteger(self::ATTR_LAST_LINKS_LOCATION, $linksParent);
    }

    private function cleanupOldRootIfNeeded(int $currentLinksParent): void
    {
        $last = (int)$this->ReadAttributeInteger(self::ATTR_LAST_LINKS_LOCATION);
        if ($last <= 0 || $last === $currentLinksParent) {
            return;
        }
        if (!IPS_ObjectExists($last)) {
            return;
        }

        $rootIdent = self::IDENT_ROOT_PREFIX . $this->InstanceID;
        $oldRootId = @IPS_GetObjectIDByIdent($rootIdent, $last);
        if ($oldRootId > 0) {
            $dry = (bool)$this->ReadPropertyBoolean('DryRunCleanup');
            if ($dry) {
                // Dry-Run: niemals löschen und auch nicht loggen
                return;
            }

            // Nur loggen, wenn wirklich gelöscht wird
            if ((bool)$this->ReadPropertyBoolean('DebugCleanup')) {
                $this->SendDebug('Cleanup', 'Lösche alten Link-Root (LinksLocation geändert): ID=' . $oldRootId . ' Name="' . IPS_GetName($oldRootId) . '" Parent=' . $last, 0);
            }

            $obj = IPS_GetObject($oldRootId);
            if (($obj['ObjectType'] ?? 0) === OBJECTTYPE_CATEGORY) {
                IPS_Delete($oldRootId);
            }
        }
    }

    private function cleanupLinksUnder(int $parentId, array $keepIdents): void
    {
        $keep = array_flip($keepIdents);
        $children = IPS_GetChildrenIDs($parentId);

        $dry = (bool)$this->ReadPropertyBoolean('DryRunCleanup');
        if ($dry) {
            // Dry-Run: niemals löschen und auch nicht loggen
            return;
        }

        $toDelete = [];
        foreach ($children as $cid) {
            $obj = IPS_GetObject($cid);
            if (($obj['ObjectType'] ?? 0) !== OBJECTTYPE_LINK) {
                continue;
            }
            $ident = IPS_GetIdent($cid);
            // Nur Links anfassen, die dieses Modul verwaltet (prefix LNK_)
            if (strpos($ident, self::IDENT_LINK_PREFIX) !== 0) {
                continue;
            }
            if (!isset($keep[$ident])) {
                $toDelete[] = $cid;
            }
        }

        if (count($toDelete) === 0) {
            return; // nichts zu löschen -> auch nichts loggen
        }

        // Nur loggen, wenn wirklich gelöscht wird
        if ((bool)$this->ReadPropertyBoolean('DebugCleanup')) {
            $this->SendDebug('Cleanup', 'Lösche ' . count($toDelete) . ' Link(s) in Kategorie "' . IPS_GetName($parentId) . '" (' . $parentId . ')', 0);
        }

        foreach ($toDelete as $cid) {
            if ((bool)$this->ReadPropertyBoolean('DebugCleanup')) {
                $target = IPS_GetLink($cid)['TargetID'] ?? 0;
                $this->SendDebug('Cleanup', '  - lösche: LinkID=' . $cid . ' Ident=' . IPS_GetIdent($cid) . ' Name="' . IPS_GetName($cid) . '" Target=' . $target, 0);
            }
            IPS_Delete($cid);
        }
    }

    private function ensureCategoryUnder(int $parentId, string $name, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id <= 0) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }
        if (IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
        return $id;
    }

    private function ensureLinkUnder(int $parentId, int $targetId, string $ident, string $name): void
    {
        if ($targetId <= 0 || !IPS_ObjectExists($targetId)) {
            return;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id <= 0) {
            $id = IPS_CreateLink();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetLinkTargetID($id, $targetId);
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
            // POST without payload: force Content-Length: 0
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
