# SMARTFOX IP-Symcon Modul

Dieses Modul liest SMARTFOX Register via Modbus TCP aus und kann konfigurierbare RW-Register auch schreiben.

## Enthalten
- Host / Port / Unit-ID konfigurierbar
- Zyklisches Polling per Timer
- Frei definierbare Registerliste
- Datentypen: uint16, int16, uint32, int32, float32
- Word-Order AB oder BA
- Skalierungsfaktor
- Lesen und Schreiben von Holding Registers

## Installation
1. Ordner `smartfox-module` in ein Repository oder lokales Modulverzeichnis kopieren.
2. In IP-Symcon als Modul laden.
3. Instanz `SMARTFOX` anlegen.
4. IP-Adresse des SMARTFOX eintragen.
5. Registerliste anpassen.
6. "Jetzt aktualisieren" drücken.

## Hinweise
- Standardmäßig ist Modbus TCP auf Port 502 vorgesehen.
- Ob ein Register wirklich schreibbar ist, muss mit der SMARTFOX-Registerliste des konkret installierten Geräts/Firmwarestands abgeglichen werden.
- Bei 32-Bit Werten kann je nach Register ggf. `WordOrder = BA` nötig sein.
