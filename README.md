# Mat-å-Mage

*[English version](README.en.md)*

Logga vad du gör och hur det känns med ett knapptryck, och hitta sambanden mellan dem. Verktyget byggdes för mat och mage ("vad händer efter att jag druckit Oboj?"), men fungerar för vilka handlingar och konsekvenser som helst: träning och sömn, medicin och huvudvärk, kaffe och humör.

Det är gjort i PHP och MySQL/MariaDB, byggt för mobilen och kräver inga ramverk och ingen byggprocess.

## Funktioner

**Logga**
- Egna knappar för **handlingar** (t.ex. Äpple, Yoggi, Oboj) och **konsekvenser** (t.ex. Gasig, Magknip). Ett tryck loggar.
- **Logga i efterhand**: "Nu", "−30 min", "−1 h", "−2 h" eller valfri tid.
- **Styrka** på konsekvenser (lite, mycket, kraftigt) och en **kommentar** direkt efter trycket, båda frivilliga.
- **Ångra** ett felklick med ett tryck.
- **Loggen** under knapparna. Tryck på en rad för att ändra tid, styrka eller kommentar, eller ta bort den.
- **Fungerar utan nät**: loggningarna sparas i telefonen och skickas när nätet är tillbaka.
- **Ikon på hemskärmen**: sidan kan läggas till på hemskärmen och öppnas som en app.

**Knappar**
- Lägg till, byt namn, välj färg och ändra ordning.
- Knappar med historik **arkiveras** i stället för att raderas, så att analysen behåller datan. De kan återställas.
- **Egna namn på kategorierna**: standard är "Mat" och "Mage", men det kan till exempel bli "Träning" och "Sömn".

**Analys**
- Välj en **handling** och se vad som hänt de **följande 72 timmarna**, uppdelat i 12-timmarsintervall.
- Välj en **konsekvens** och se vilka handlingar som förekommit de **72 timmarna innan**.
- Varje ruta visar *"7/9, normalt 3,1"*: det hände efter 7 av 9 tillfällen, medan man av en slump skulle vänta sig ungefär 3.
- ▲ och ▲▲ markerar det som är **sannolikt inte slump** (binomialtest, under 5 % respektive 1 % risk). ▼ markerar det som händer klart *mer sällan* än normalt.
- **Dölj** knappar du inte är intresserad av. Valet sparas.
- Filtrera på **datum** och **styrka**, och välj **fönster** (24 h–7 dygn) och **intervall** (6, 12 eller 24 h).

**Export**
- **Exportera för AI**: en fil (.md) med instruktioner överst och data i CSV-format under. Ladda upp den i en AI-assistent och be den analysera.
- **Exportera CSV**: ren data för Excel, Numbers eller Google Kalkylark, semikolonseparerad med svenska tecken.

## Så räknas sambanden

1. **Tillfällen, inte loggningar.** Loggningar av samma knapp inom 3 timmar (går att ändra) räknas som ett tillfälle, så att två koppar kaffe samma morgon inte räknas dubbelt.
2. **Träff.** För varje tillfälle och tidsintervall kontrolleras om det andra loggades minst en gång i intervallet.
3. **Normalnivå med dygnsrytm.** För varje tillfälle kontrolleras samma tidsfönster, med samma klockslag, upp till 45 dagar före och efter. Andelen av de dagarna där det andra fanns i fönstret är normalnivån, och summan över alla tillfällen ger det förväntade antalet. Frukost blir därför ingen "orsak" bara för att besvär oftast loggas på dagen.
4. **Signifikans.** Ett binomialtest ger sannolikheten att få minst så många träffar av en slump. Under 5 % ger ▲ och under 1 % ger ▲▲. Minst 3 tillfällen krävs.
5. **Bara det som kunnat hända räknas.** Intervall som ligger i framtiden, eller före den första loggningen, räknas inte.

> Med många rutor uppstår enstaka ▲ av ren slump. ▲▲, och samband som syns i flera kolumner, är säkrare. Saker som ofta förekommer tillsammans (t.ex. Ostmacka och Kaffe till frukost) kan dela på ett samband. Få tillfällen ger osäkra resultat. Verktyget hittar samband, inte orsaker, och ersätter inte vården.

## Krav

- PHP 7.4 eller senare (8.x rekommenderas) med `mysqli` (mysqlnd) och `mbstring`
- MySQL 5.7+ eller MariaDB 10.3+ (InnoDB)
- HTTPS rekommenderas, och krävs för ikonen på hemskärmen på de flesta mobiler.

## Installation

1. **Skapa en databas** och kör `sql/schema.sql`, till exempel i phpMyAdmin under fliken SQL.
2. **Exempeldata (valfritt):** kör `sql/exempeldata.sql`. Den innehåller 60 dagars logg med två inbyggda samband: *Oboj → Gasig* efter 12–24 h och *Glass → Lös i magen* inom några timmar. Resten är slump, så att du ser hur analysen skiljer på riktiga samband och brus. Datan läggs på användar-ID 1.
3. **Kopiera** `config.example.php` till `config.php` och fyll i databasuppgifterna och `LOGIN_USER`.
4. **Ladda upp** mappen till webbservern.
5. **Öppna** `matamage.php`. Första gången visas en guide:
   1. Välj ett lösenord, så skapas en hash.
   2. Klistra in den i `config.php` som `LOGIN_PASSWORD_HASH` och ladda om sidan.
   3. Logga in.
6. **På mobilen:** välj "Dela → Lägg till på hemskärmen" (iPhone) eller "Installera app" (Android).

`config.php` finns i `.gitignore`, så dina lösenord hamnar aldrig i git.

## Inloggningslägen

| Läge | Beskrivning |
|---|---|
| `login` | Inbyggd inloggning med ett användarnamn och lösenord. Standardläget. |
| `demo` | Ingen inloggning. Varje besökare får en egen sandlåda med exempeldata som raderas efter `DEMO_TTL_HOURS` timmar. Kräver `sql/demo.sql`. |
| `ll2` | Delar session med ett befintligt inloggningssystem (`ll2.php`). Används i författarens egen installation och kan ignoreras. |

## Filer

| Fil | Innehåll |
|---|---|
| `matamage.php` | Hela verktyget: loggning, knappar, analys, export och appmanifest |
| `mam-ikon-*.png` | Ikoner för hemskärmen |
| `common.php` | Gemensamt nav: databas, inloggning, CSRF-skydd, meny och demoläge |
| `config.example.php` | Mall för inställningarna |
| `demo.php` | Kopierar exempeldata till en besökares sandlåda (bara demoläget) |
| `minsida.css` | Utseendet |
| `sql/` | `schema.sql`, `exempeldata.sql` och `demo.sql` |

## Licens

MIT – se [LICENSE](LICENSE).
