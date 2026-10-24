# FLARE für IP-Symcon – CircadianLight

Tageslicht-Automatik für Hue-Leuchten (und alle anderen Leuchten mit Statusvariablen) nach dem Vorbild von
[FLARE für Home Assistant](https://danrspencer.github.io/flare/). Eine Instanz = eine Zone (Raum).

## Funktionen

- **Vier Phasen** – Morgen, Tag, Abend, Nacht mit eigener Helligkeit und Farbtemperatur.
  Morgen, Tag und Nacht beginnen zu festen Uhrzeiten, der Abend folgt dem Sonnenuntergang
  (begrenzt durch „Abend frühestens“ und „Abend spätestens“).
- **Weiche Übergänge** – je Phase einstellbar, wie viele Minuten *vor* dem Beginn überblendet wird
  (Farbtemperatur wird in Mired interpoliert). 0 = harter Wechsel.
- **Tag wird wärmer** – optional wandert die Farbtemperatur über den Nachmittag Richtung Abend.
- **Abgleich** – im Intervall (Standard 60 s) werden alle Leuchten geprüft; Änderungen unterhalb des
  Totbands werden nicht gesendet. Mehrere Zonen starten zeitversetzt.
- **Override-Schutz** – ändert jemand Helligkeit oder Farbtemperatur (App, Taster, anderes Skript),
  fasst die Zone diese Leuchte nicht mehr an, bis der Raum dunkel bzw. leer ist.
- **Wandschalter** – wird eine Leuchte wieder *erreichbar* oder von außen eingeschaltet, bekommt sie
  sofort die aktuellen Phasenwerte.
- **Anwesenheit (optional)** – Bewegungs-/Präsenzvariablen schalten ein, nach der Verzögerung aus.
  Alle Leuchten bei Anwesenheit von Hand aus → bleibt aus, bis der Raum leer war.
- **Nachtlicht** – leerer Raum dimmt auf x % statt auszuschalten (optional nur nachts).
- **Szene statt Kurve** – je Phase optional ein Skript, das beim Betreten ausgeführt wird
  (z. B. Hue-Szene abrufen). Die Leuchten gelten dann bis „Raum leer“ als übersteuert.
  Im Skript stehen `$_IPS['ZONE']` und `$_IPS['PHASE']` (0–3) zur Verfügung.
- **Vorschau** – im Konfigurationsformular siehst du den kompletten Tagesverlauf in 30-min-Schritten.

## Installation

1. Ordner `FlareIPS` nach `<Symcon>/modules/` kopieren (oder per Git in der Modulverwaltung einbinden).
2. Instanz „CircadianLight“ (Alias „FLARE Lichtautomatik“) pro Raum anlegen.
3. Leuchten eintragen: je Leuchte die Variablen **Status**, **Helligkeit**, **Farbtemperatur** und,
   falls vorhanden, eine Variable für **Erreichbarkeit** der Hue-Instanz auswählen.
   Kelvin oder Mired wird automatisch am Variablenprofil erkannt (unter „Erweitert“ erzwingbar).
4. Standort: Breite/Länge leer lassen → wird aus dem Location-Modul gelesen.
   Klappt das nicht, Koordinaten eintragen oder eine Sonnenuntergangs-Variable wählen.

## Variablen der Instanz

| Variable | Zweck |
|---|---|
| Automatik aktiv | Zone ein/aus. Beim Einschalten werden Overrides gelöscht. |
| Raum belegt | Belegt-Status, auch manuell schaltbar (z. B. per Taster). |
| Phase, Ziel-Helligkeit, Ziel-Farbtemperatur | Aktuelle Sollwerte. |
| Manuell übersteuert | Anzahl Leuchten mit Override. |
| Beginn Morgen / Tag / Nacht, Abend frühestens / spätestens | Zeiten – per WebFront, Ereignis oder Skript änderbar. |
| Beginn Abend (heute) | Berechneter Abendbeginn. |

Beispiel Wochenende ausschlafen (Ereignis Fr+Sa 22:00):

```php
RequestAction(IPS_GetObjectIDByIdent('MorningStart', $zone), strtotime('today 08:30'));
```
… und So+Do 22:00 wieder auf 06:30.

## Funktionen für Skripte

```php
FLARE_Update(int $InstanzID, bool $force);   // sofort abgleichen
FLARE_SetOccupied(int $InstanzID, bool $belegt);
FLARE_RoomEmpty(int $InstanzID);
FLARE_ClearOverrides(int $InstanzID);
FLARE_GetTarget(int $InstanzID);              // JSON: phase, bri, ct, fading, ...
```

## Hinweise

- „Helligkeit %“ in der Leuchtenliste skaliert die Phasenhelligkeit pro Leuchte (z. B. 50 % für eine Stehlampe).
- Ohne Bewegungsmelder schaltet die Zone nie selbst ein – sie führt nur eingeschaltete Leuchten nach.
- Debug-Ausgaben (gesendete Befehle, erkannte Overrides) stehen im Debug-Fenster der Instanz.
