# SMARTFOX IP-Symcon Modul

Dieses Modul liest und schreibt SMARTFOX Pro / Pro 2 Register per Modbus TCP.

## Wichtige Einstellung
Die SMARTFOX Excel-Tabelle verwendet Registeradressen wie `41012`, `41018`, `40400`.
In Modbus TCP muss häufig der Bereichsoffset `40000` abgezogen werden.

Darum ist im Modul standardmäßig eingestellt:

- **Adressbasis = 40000**

Beispiel:
- Dokumentation: `41012`
- Gesendete Modbus-Adresse: `1012`

## Standardregister
Vorkonfiguriert sind:
- 41012 - Day Energy from grid
- 41014 - Day Energy into grid
- 41018 - Power total
- 40400 - Control via Modbus
- 40403 - Control Relay 1 (deaktiviert)

## Hinweise
- `uint8[6]` wird als Hex-String angezeigt
- Schreibbar sind nur Register mit `RW`
- Bei skalierten Werten wird beim Lesen multipliziert und beim Schreiben dividiert


Hinweis: Bei SMARTFOX sollte die Adressbasis in dieser Version in der Regel auf 0 stehen, also die Dokumentationsadresse direkt verwendet werden (z. B. 40400, 41012).
