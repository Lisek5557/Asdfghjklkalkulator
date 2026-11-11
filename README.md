# Osmo Pad

Aplikacja na Androida do sterowania gimbalem **DJI Osmo Mobile SE** (i innymi z serii Osmo Mobile)
za pomocą **pada Xbox**, z **drugiego telefonu**.

```
[pad Xbox] --Bluetooth--> [telefon sterujący: Osmo Pad] --BLE--> [gimbal Osmo Mobile SE + telefon nagrywający]
```

Telefon na gimbalu nagrywa zwykłą aplikacją aparatu. Steruje drugi telefon.

> ⚠️ DJI nie udostępnia SDK dla Osmo Mobile SE. Aplikacja używa nieoficjalnego,
> rozpracowanego protokołu (DUML przez BLE). Działa on na OM2, OM3, OM4 i OM7P;
> **na SE nie był jeszcze testowany**. Aktualizacja firmware gimbala może go zmienić.

## Instalacja

1. Wejdź w **Releases** tego repozytorium na telefonie sterującym i pobierz najnowszy `OsmoPad-N.apk`.
2. Otwórz plik i zezwól na instalację z nieznanych źródeł.

APK budowany jest automatycznie przez GitHub Actions przy każdym pushu (zakładka **Actions**).

## Użycie

1. **Odłącz gimbal od DJI Mimo** na telefonie nagrywającym: zamknij Mimo i najlepiej
   usuń gimbal z listy Bluetooth tego telefonu. Gimbal przyjmuje tylko jedno połączenie BLE naraz.
2. Sparuj pad Xbox z telefonem sterującym (przytrzymaj przycisk parowania na padzie
   i wybierz go w ustawieniach Bluetooth).
3. Włącz gimbal, otwórz **Osmo Pad**, naciśnij **Szukaj** i wybierz gimbal (nazwa zaczyna się zwykle od „OM”).
4. Steruj padem.

Na Androidzie 11 i starszym do skanowania BLE musi być włączona lokalizacja.

### Pad Xbox

| Element | Działanie |
|---|---|
| Lewa gałka | obrót / pochylenie (pełna prędkość) |
| Prawa gałka | obrót precyzyjny (30%) |
| Krzyżak | powolne przesuwanie |
| RT / LT | przyspieszenie do 2× / zwolnienie do 0,25× |
| LB / RB | zmniejsz / zwiększ prędkość maksymalną |
| A | wyśrodkuj |
| B | stop |
| X / Y | tryb Lock / Follow (eksperymentalne) |
| Widok (⧉) | odwróć pion |
| Menu (≡) | połącz z ostatnim gimbalem |

## Gdy gimbal nie reaguje albo rusza złą osią

1. Użyj przycisków **Test oś 1 / 2 / 3**. Każdy z nich rusza jedną osią przez sekundę.
   Zapisz, która oś obraca w lewo/prawo, która pochyla w górę/dół, a która przechyla telefon na bok.
2. Jeśli gałka w górę/dół **przechyla telefon na bok** zamiast go pochylać, zaznacz
   **Zamień pochylenie z przechyłem**.
3. Jeśli gimbal w ogóle nie reaguje, zaznacz **Tryb PWM** i spróbuj ponownie.
4. Wyłącz i włącz gimbal, upewnij się, że Mimo nie jest połączone.
5. Zrób zrzut ekranu z logiem na dole aplikacji i wklej go w issue lub w rozmowie.

## Budowanie lokalnie

```bash
./gradlew testDebugUnitTest assembleDebug
# APK: app/build/outputs/apk/debug/app-debug.apk
```

Wymaga JDK 17 i Android SDK (np. z Android Studio).

## Podziękowania

Protokół: [alkersan/om-research](https://github.com/alkersan/om-research) (MIT)
oraz [shaun-xenon/osmo-remote](https://github.com/shaun-xenon/osmo-remote) (MIT).
Kod tej aplikacji został napisany od nowa; nie zawiera oprogramowania DJI.
