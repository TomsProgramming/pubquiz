# PubQuiz Dashboard

Een minimalistisch en intuïtief dashboard voor het beheren van een PubQuiz met PHP en MySQL.

## ✨ Functies

### Voorkant
- **Home**: Startpagina met leaderboard
  - **Olympic Podium**: Top 3 teams weergegeven als olympische erepodium
  - **Alle Teams Tabel**: Volledige ranking van alle deelnemers
- **Interactieve Quiz**: Bekijk vragen één voor één met START scherm
  - START scherm voordat de quiz begint
  - Toggle tussen vraag- en antwoordweergave
  - Navigeer door alle vragen met vorige/volgende knoppen
  - Week selector
  - Afdrukfunctie

### Admin Gedeelte (beveiligd met login)
- **Admin Login**: Veilige toegang tot beheerpaneel (alleen bereikbaar via directe link)
- **Dashboard**: Statistieken en snelle overzicht
- **Vragen beheren**: 
  - Voeg vragen per week toe met categorieën
  - Bulk import (CSV-formaat)
  - Verwijder vragen
  - Filter per week
- **Teams beheren**: 
  - Voeg teams toe
  - **Wijzig teamnamen** ✨ (direct in tabel)
  - Update scores
  - Verwijder teams

## 🔐 Admin Login

**URL**: `http://localhost/pubquiz-dashboard/admin/login.php`

**Standaard credentials**:
- Gebruikersnaam: `admin`
- Wachtwoord: `pubquiz2026`

⚠️ **Let op**: Wijzig deze credentials in `admin/login.php` in de productie omgeving!

## 🎨 Design

- Minimalistisch Swiss Style design
- Zwart-wit kleurenschema
- Responsief voor alle apparaten
- Nette typografie en witruimte

## 🚀 Installatie

### 1. Database Setup

1. Open phpMyAdmin of je MySQL client
2. Importeer het bestand `pubquiz.sql`:
   - Selecteer "Importeren"
   - Kies het `pubquiz.sql` bestand
   - Klik op "OK"

Of via terminal:
```bash
mysql -u root -p < pubquiz.sql
```

### 2. Database Connectie Configureren

Open `database_connect.php` en pas de volgende waarden aan:

```php
define('DB_HOST', 'localhost');      // Je database host
define('DB_USER', 'root');           // Je database gebruikersnaam
define('DB_PASSWORD', '');           // Je database wachtwoord
define('DB_NAME', 'pubquiz');        // Database naam
```

### 3. Plaats bestanden op je webserver

Zet alle bestanden in je `htdocs` folder (XAMPP) of andere webserver directory.

### 4. Start de applicatie

Open in je browser:
```
http://localhost/pubquiz-dashboard/
```

## 📁 Mappenstructuur

```
pubquiz-dashboard/
├── index.php                 # Startpagina met statistieken
├── leaderboard.php           # Leaderboard (losse pagina)
├── quiz.php                  # Interactieve quiz
├── database_connect.php      # Database configuratie
├── style.css                 # Minimalistisch design
├── export_leaderboard.php    # CSV export
├── pubquiz.sql               # Database schema
│
└── admin/                    # Beveiligde beheersectie
    ├── login.php             # Login pagina
    ├── logout.php            # Uitloggen
    ├── index.php             # Dashboard
    ├── questions.php         # Vragen beheren
    └── teams.php             # Teams beheren
```

## 📝 Vragen toevoegen

### Individueel
1. Login in Admin
2. Ga naar "Vragen"
3. Vul het formulier in met:
   - Week nummer
   - Vraagnummer (1-25)
   - Vraag
   - Antwoord
   - Categorie (bijv. Geografie, Sport, etc.)

### Bulk Import
Format (1 vraag per regel):
```
1. Wat is de hoofdstad van Frankrijk? | Parijs | Geografie
2. Hoeveel zijden heeft een driehoek? | 3 | Wiskunde
```

## 🎯 Workflow PubQuiz Night

1. **Voorbereiding**: Ga naar `/admin/` en voeg alle vragen en teams toe
2. **Quiz starten**: Ga naar `/quiz/` en klik op START
3. **Vraag ronde**: Presenteer de vragen één voor één (vorige/volgende)
4. **Antwoord ronde**: Toggle naar antwoorden en toon de juiste antwoorden
5. **Scoring**: Update teamscores in Admin panel
6. **Einde**: Ga naar Home voor het eindstand op het podium

## ✨ Privacy Features

- **Quiz & Admin verborgen**: De links naar `/quiz/` en `/admin/` zijn niet zichtbaar
- Bereikbaar via directe URL of door ze in te typen in de adresbalk
- Voorkant toont alleen het leaderboard podium

## 📝 Gebruik

### Dashboard (Home)
- Overzicht van statistieken
- Top 5 teams
- Recente vragen van de huidige week

### Leaderboard
- Bekijk de ranglijst van alle teams
- Download als CSV

### Teams
- Voeg nieuwe teams toe
- Beheer team scores
- Verwijder teams

### Vragen
- Voeg vragen per week toe
- Bulk import vragen
- Bekijk vragen per week

## 📊 Database Schema

### teams tabel
- `id`: Team ID
- `name`: Teamnaam (uniek)
- `score`: Totale punten
- `created_at`: Aanmaakdatum

### questions tabel
- `id`: Vraag ID
- `week`: Weeknummer
- `question_number`: Vraagnummer (1-25)
- `question`: De vraag
- `answer`: Het antwoord
- `points`: Punten (default 1)
- `created_at`: Aanmaakdatum

### answers tabel
- `id`: Answer ID
- `team_id`: Team ID
- `question_id`: Vraag ID
- `answer`: Team's antwoord
- `is_correct`: Is het antwoord correct?
- `created_at`: Aanmaakdatum

## 💡 Tips

### Bulk vragen toevoegen
Je kunt vragen in bulk toevoegen met het volgende format:

```
1. Wat is de hoofdstad van Frankrijk? | Parijs
2. Hoeveel zijden heeft een driehoek? | 3
3. Welke kleur is de lucht? | Blauw
```

Of zonder nummers:
```
Wat is de hoofdstad van Nederland? | Amsterdam
Welke sport wordt in de Olympische Spelen beoefend? | Veel verschillende
Wie schilderde de Mona Lisa? | Leonardo da Vinci
```

### Scores automatisch berekenen
De applicatie berekent scores op basis van correcte antwoorden. Je kunt scores ook handmatig aanpassen in het Teams beheer scherm.

## 🔧 Troubleshooting

### "Connection failed" error
- Controleer je database instellingen in `database_connect.php`
- Zorg dat MySQL server draait
- Zorg dat je gebruikersnaam en wachtwoord correct zijn

### SQL import error
- Zorg dat je database charset UTF-8 is
- Probeer het .sql bestand handmatig in phpMyAdmin te importeren
- Zorg dat je voldoende rechten hebt

### Vragen worden niet weergegeven
- Zorg dat je in de juiste week bent
- Check dat er vragen zijn toegevoegd voor die week
- Controleer je database connectie

## 📄 Licentie

Dit project is vrij te gebruiken en aan te passen naar je wensen.

## 👤 Contact

Vragen? Controleer de code of contacteer je webhost voor database support.
