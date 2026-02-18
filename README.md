# Tessie for IP-Symcon

Diese Bibliothek enthält:

- **TessieConfigurator** (Typ 4): Listet Fahrzeuge über Tessie REST API und legt Instanzen an (inkl. optionalem WS Client für Telemetrie).
- **TessieVehicle** (Typ 3): REST Fahrzeugdaten (Pull) + Telemetrie (Push via Parent-WS-Client) + **Remote Commands** (Lock/Climate/Charging ...).

## Installation
- Bibliothek als GitHub URL in *Module Control* hinzufügen oder lokal nach `modules/Tessie/` kopieren.
- IP-Symcon Dienst/Container neu starten oder Module Control → Neu laden.

## Konfigurator
- Instanz *Tessie Configurator* anlegen.
- `ApiToken` (REST) eintragen.
- Optional `TelemetryToken` eintragen und `CreateWSClient` aktiv lassen (Default). Dann wird beim Anlegen eines Fahrzeugs automatisch ein WS Client erzeugt und als Parent verbunden.

## Hinweise
- Tokens nicht veröffentlichen; bei Leaks rotieren.
